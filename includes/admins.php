<?php

function admin_server_bindings()
{
    $bindings = array();
    $rows = db_rows('SELECT `admin_id`, `server_id` FROM ' . table('admins_servers_groups'));
    foreach ($rows as $row) {
        $aid = (int)$row['admin_id'];
        if (!isset($bindings[$aid])) {
            $bindings[$aid] = array();
        }
        if ((int)$row['server_id'] > 0) {
            $bindings[$aid][] = (int)$row['server_id'];
        }
    }
    return $bindings;
}

function list_admins($user)
{
    $rows = db_rows(admin_select_sql() . ' ORDER BY GREATEST(a.`immunity`, IFNULL(g.`immunity`, 0)) DESC, a.`user`, a.`aid`');
    $bindings = admin_server_bindings();
    $admins = array();
    $seen = array();
    foreach ($rows as $row) {
        $aid = (int)$row['aid'];
        if (isset($seen[$aid])) {
            continue;
        }
        $seen[$aid] = true;
        $rights = admin_rights($row);
        $panel = $row['password'] !== '' && count($rights['permissions']) > 0;
        $admins[] = array(
            'id' => $aid,
            'steamid' => authid_to_steam64($row['authid']),
            'username' => $row['user'],
            'panel' => $panel,
            'group_id' => $row['group_id'] !== null ? (int)$row['group_id'] : null,
            'group_name' => $row['srv_group'] !== null && $row['srv_group'] !== '' ? $row['srv_group'] : null,
            'flags' => clean_flags($row['srv_flags']),
            'immunity' => (int)$row['own_immunity'],
            'effective_immunity' => $rights['immunity'],
            'web_permissions' => parse_permissions($row['permissions']),
            'owner' => $rights['owner'],
            'expires_at' => (int)$row['expired'] > 0 ? (int)$row['expired'] : null,
            'totp_enabled' => (int)$row['totp_enabled'] === 1,
            'servers' => isset($bindings[$aid]) ? $bindings[$aid] : array(),
            'manageable' => $aid === $user['id'] ? $user['owner'] : can_manage_admin($user, $row)
        );
    }
    return $admins;
}

function find_group_by_id($id)
{
    if ($id <= 0) {
        return null;
    }
    $group = db_row(
        'SELECT g.*, w.`permissions` AS web_permissions FROM ' . table('srvgroups') . ' g
         LEFT JOIN `vd_group_web` w ON w.`group_id` = g.`id` WHERE g.`id` = ?',
        array($id)
    );
    if (!$group) {
        fail('invalid_reference');
    }
    return $group;
}

function admin_input($input, $existing)
{
    $raw_steamid = text_value(isset($input['steamid']) ? $input['steamid'] : '', 128);
    $steam64 = $raw_steamid !== '' ? resolve_steam64($raw_steamid) : null;
    if ($raw_steamid !== '' && !$steam64) {
        fail('invalid_steamid');
    }

    $panel = !empty($input['panel']);
    $username = text_value(isset($input['username']) ? $input['username'] : '', 64);
    $keeps_name = $existing && $username === $existing['user'];
    if ($panel && !$keeps_name && !preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
        fail('invalid_username');
    }

    $password = isset($input['password']) ? (string)$input['password'] : '';
    if ($panel && $password !== '') {
        check_new_password($password);
    }
    if ($panel && $password === '' && (!$existing || $existing['password'] === '')) {
        fail('password_required');
    }
    if (!$panel && !$steam64) {
        fail('steamid_or_login_required');
    }

    $expires = 0;
    if (!empty($input['expires_at'])) {
        $expires = int_value($input['expires_at']);
        if ($expires <= 0) {
            fail('invalid_date');
        }
    }

    $servers = array();
    if (isset($input['servers']) && is_array($input['servers'])) {
        foreach (array_slice($input['servers'], 0, 500) as $sid) {
            $sid = int_value($sid);
            if ($sid > 0 && !in_array($sid, $servers)) {
                $servers[] = $sid;
            }
        }
    }

    return array(
        'steamid' => $steam64,
        'panel' => $panel,
        'username' => $username,
        'nickname' => text_value(isset($input['name']) ? $input['name'] : '', 64),
        'password' => $password,
        'permissions' => $panel ? parse_permissions(isset($input['web_permissions']) ? $input['web_permissions'] : array()) : array(),
        'flags' => clean_flags(isset($input['flags']) ? $input['flags'] : ''),
        'immunity' => clamp(int_value(isset($input['immunity']) ? $input['immunity'] : 0), 0, 100),
        'group' => find_group_by_id(int_value(isset($input['group_id']) ? $input['group_id'] : 0)),
        'expires' => $expires,
        'servers' => $servers,
        'reset_2fa' => !empty($input['reset_2fa'])
    );
}

function check_admin_grant($user, $data)
{
    $group = $data['group'];
    $group_permissions = $group ? parse_permissions($group['web_permissions']) : array();
    check_can_grant(
        $user,
        array_values(array_unique(array_merge($data['permissions'], $group_permissions))),
        $data['flags'] . ($group ? $group['flags'] : ''),
        max($data['immunity'], $group ? (int)$group['immunity'] : 0)
    );
}

function unique_admin_login($wanted, $steam64, $skip_aid)
{
    $login = $wanted !== '' ? $wanted : ($steam64 ? steam64_to_steam2($steam64) : 'admin');
    $taken = db_value('SELECT COUNT(*) FROM ' . table('admins') . ' WHERE `user` = ? AND `aid` <> ?', array($login, (int)$skip_aid));
    if ((int)$taken === 0) {
        return $login;
    }
    return cut_text($login, 40) . ' (' . ($steam64 ? steam64_to_steam2($steam64) : random_hex(6)) . ')';
}

function check_steamid_free($steam64, $skip_aid)
{
    if (!$steam64) {
        return;
    }
    $taken = db_value(
        'SELECT COUNT(*) FROM ' . table('admins') . ' WHERE `authid` REGEXP ? AND `aid` <> ?',
        array(authid_pattern($steam64), (int)$skip_aid)
    );
    if ((int)$taken > 0) {
        fail('duplicate_admin', 409);
    }
}

function save_admin_bindings($aid, $data)
{
    db_execute('DELETE FROM ' . table('admins_servers_groups') . ' WHERE `admin_id` = ?', array($aid));
    $group_id = $data['group'] ? (int)$data['group']['id'] : 0;
    $servers = count($data['servers']) > 0 ? $data['servers'] : array(-1);
    foreach ($servers as $sid) {
        db_execute(
            'INSERT INTO ' . table('admins_servers_groups') . ' (`admin_id`, `group_id`, `srv_group_id`, `server_id`) VALUES (?, ?, -1, ?)',
            array($aid, $group_id, $sid)
        );
    }
}

function create_admin($user, $input)
{
    $data = admin_input($input, null);
    check_admin_grant($user, $data);
    check_steamid_free($data['steamid'], 0);

    $login = $data['panel'] ? $data['username'] : unique_admin_login($data['nickname'], $data['steamid'], 0);
    try {
        db_execute(
            'INSERT INTO ' . table('admins') . ' (`user`, `authid`, `password`, `gid`, `email`, `extraflags`, `immunity`, `srv_group`, `srv_flags`, `expired`, `comment`)
             VALUES (?, ?, ?, -1, \'\', 0, ?, ?, ?, ?, \'\')',
            array(
                $login,
                $data['steamid'] ? steam64_to_steam2($data['steamid']) : '',
                $data['panel'] ? make_password_hash($data['password']) : '',
                $data['immunity'],
                $data['group'] ? $data['group']['name'] : null,
                $data['flags'],
                $data['expires']
            )
        );
    } catch (PDOException $error) {
        if (duplicate_key_error($error)) {
            fail('duplicate_admin', 409);
        }
        throw $error;
    }
    $aid = db_insert_id();

    db_execute(
        'REPLACE INTO `vd_admin_web` (`aid`, `permissions`, `session_version`) VALUES (?, ?, 0)',
        array($aid, implode(',', $data['permissions']))
    );
    save_admin_bindings($aid, $data);

    write_log('admin_add', '#' . $aid . ' ' . $login . ' ' . (string)$data['steamid']);
    reload_admins_everywhere();
    return $aid;
}

function update_admin($user, $aid, $input)
{
    $existing = find_admin($aid);
    if (!$existing) {
        fail('not_found', 404);
    }
    $self = (int)$existing['aid'] === (int)$user['id'];
    if ($self && !$user['owner']) {
        fail('use_account_page');
    }
    if (!$self && !can_manage_admin($user, $existing)) {
        fail('forbidden', 403);
    }

    $data = admin_input($input, $existing);
    if ($self && ($data['password'] !== '' || $data['reset_2fa'] || !$data['panel'] || $data['username'] !== $existing['user'])) {
        fail('use_account_page');
    }
    if ($self && !in_array('owner', $data['permissions'])) {
        fail('cannot_remove_own_owner');
    }
    check_admin_grant($user, $data);
    check_steamid_free($data['steamid'], $aid);

    $login = $data['panel'] ? $data['username'] : $existing['user'];
    $password = $existing['password'];
    if (!$data['panel']) {
        $password = '';
    } elseif ($data['password'] !== '') {
        $password = make_password_hash($data['password']);
    }

    $before = admin_rights($existing);
    $after_row = $existing;
    $after_row['permissions'] = implode(',', $data['permissions']);
    $after_row['group_permissions'] = $data['group'] ? $data['group']['web_permissions'] : '';
    $after = admin_rights($after_row);
    $revoke = !$self && ($data['password'] !== '' || $data['reset_2fa'] || !$data['panel'] || $login !== $existing['user'] ||
        implode(',', $before['permissions']) !== implode(',', $after['permissions']));

    try {
        db_execute(
            'UPDATE ' . table('admins') . ' SET `user` = ?, `authid` = ?, `password` = ?, `immunity` = ?, `srv_group` = ?, `srv_flags` = ?, `expired` = ?
             WHERE `aid` = ?',
            array(
                $login,
                $data['steamid'] ? steam64_to_steam2($data['steamid']) : '',
                $password,
                $data['immunity'],
                $data['group'] ? $data['group']['name'] : null,
                $data['flags'],
                $data['expires'],
                $aid
            )
        );
    } catch (PDOException $error) {
        if (duplicate_key_error($error)) {
            fail('duplicate_admin', 409);
        }
        throw $error;
    }

    ensure_admin_web_row($aid);
    db_execute('UPDATE `vd_admin_web` SET `permissions` = ? WHERE `aid` = ?', array(implode(',', $data['permissions']), $aid));
    if ($data['reset_2fa']) {
        db_execute('UPDATE `vd_admin_web` SET `totp_enabled` = 0, `totp_secret` = NULL, `totp_last_step` = 0 WHERE `aid` = ?', array($aid));
    }
    if ($revoke) {
        bump_session_version($aid, false);
    }
    save_admin_bindings($aid, $data);

    $details = '#' . $aid . ' ' . $login . ($data['password'] !== '' ? ' password' : '') . ($data['reset_2fa'] ? ' 2fa-reset' : '');
    write_log('admin_edit', $details);
    reload_admins_everywhere();
}

function delete_admin($user, $aid)
{
    if ((int)$aid === (int)$user['id']) {
        fail('cannot_delete_self');
    }
    $existing = find_admin($aid);
    if (!$existing) {
        fail('not_found', 404);
    }
    if (!can_manage_admin($user, $existing)) {
        fail('forbidden', 403);
    }
    db_execute('DELETE FROM ' . table('admins_servers_groups') . ' WHERE `admin_id` = ?', array((int)$aid));
    db_execute('DELETE FROM `vd_admin_web` WHERE `aid` = ?', array((int)$aid));
    db_execute('DELETE FROM ' . table('admins') . ' WHERE `aid` = ?', array((int)$aid));
    write_log('admin_delete', '#' . (int)$aid . ' ' . $existing['user']);
    reload_admins_everywhere();
}
