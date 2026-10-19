<?php

function list_groups()
{
    $rows = db_rows(
        'SELECT g.*, w.`permissions` AS web_permissions,
            (SELECT COUNT(*) FROM ' . table('admins') . ' a WHERE a.`srv_group` = g.`name`) AS members
         FROM ' . table('srvgroups') . ' g
         LEFT JOIN `vd_group_web` w ON w.`group_id` = g.`id`
         ORDER BY g.`immunity` DESC, g.`name`'
    );
    $groups = array();
    foreach ($rows as $row) {
        $groups[] = array(
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'flags' => clean_flags($row['flags']),
            'immunity' => (int)$row['immunity'],
            'web_permissions' => parse_permissions($row['web_permissions']),
            'members' => (int)$row['members'],
            'max_ban' => max(0, (int)$row['maxbantime']),
            'max_mute' => max(0, (int)$row['maxmutetime'])
        );
    }
    return $groups;
}

function group_input($input)
{
    $name = text_value(isset($input['name']) ? $input['name'] : '', 64);
    if ($name === '') {
        fail('name_required');
    }
    $max_ban = max(0, int_value(isset($input['max_ban']) ? $input['max_ban'] : 0));
    $max_mute = max(0, int_value(isset($input['max_mute']) ? $input['max_mute'] : 0));
    return array(
        'name' => $name,
        'flags' => clean_flags(isset($input['flags']) ? $input['flags'] : ''),
        'permissions' => parse_permissions(isset($input['web_permissions']) ? $input['web_permissions'] : array()),
        'immunity' => clamp(int_value(isset($input['immunity']) ? $input['immunity'] : 0), 0, 100),
        'max_ban' => $max_ban > 0 ? $max_ban : -1,
        'max_mute' => $max_mute > 0 ? $max_mute : -1
    );
}

function check_group_grant($user, $group)
{
    check_can_grant($user, $group['permissions'], $group['flags'], $group['immunity']);
}

function stored_group($id)
{
    $row = db_row(
        'SELECT g.*, w.`permissions` AS web_permissions FROM ' . table('srvgroups') . ' g
         LEFT JOIN `vd_group_web` w ON w.`group_id` = g.`id` WHERE g.`id` = ?',
        array((int)$id)
    );
    if (!$row) {
        fail('not_found', 404);
    }
    return $row;
}

function check_group_name_free($name, $skip_id)
{
    $taken = db_value('SELECT COUNT(*) FROM ' . table('srvgroups') . ' WHERE `name` = ? AND `id` <> ?', array($name, (int)$skip_id));
    if ((int)$taken > 0) {
        fail('duplicate_group', 409);
    }
}

function create_group($user, $input)
{
    $group = group_input($input);
    check_group_grant($user, $group);
    check_group_name_free($group['name'], 0);
    db_execute(
        'INSERT INTO ' . table('srvgroups') . ' (`flags`, `immunity`, `name`, `groups_immune`, `maxbantime`, `maxmutetime`) VALUES (?, ?, ?, \'\', ?, ?)',
        array($group['flags'], $group['immunity'], $group['name'], $group['max_ban'], $group['max_mute'])
    );
    $id = db_insert_id();
    db_execute('REPLACE INTO `vd_group_web` (`group_id`, `permissions`) VALUES (?, ?)', array($id, implode(',', $group['permissions'])));
    write_log('group_add', '#' . $id . ' ' . $group['name']);
    return $id;
}

function update_group($user, $id, $input)
{
    $current = stored_group($id);
    check_can_grant($user, parse_permissions($current['web_permissions']), $current['flags'], (int)$current['immunity']);
    $group = group_input($input);
    check_group_grant($user, $group);
    check_group_name_free($group['name'], $id);

    db_execute(
        'UPDATE ' . table('srvgroups') . ' SET `flags` = ?, `immunity` = ?, `name` = ?, `maxbantime` = ?, `maxmutetime` = ? WHERE `id` = ?',
        array($group['flags'], $group['immunity'], $group['name'], $group['max_ban'], $group['max_mute'], (int)$id)
    );
    if ($group['name'] !== $current['name']) {
        db_execute('UPDATE ' . table('admins') . ' SET `srv_group` = ? WHERE `srv_group` = ?', array($group['name'], $current['name']));
    }
    db_execute('REPLACE INTO `vd_group_web` (`group_id`, `permissions`) VALUES (?, ?)', array((int)$id, implode(',', $group['permissions'])));
    write_log('group_edit', '#' . (int)$id . ' ' . $group['name']);
    reload_admins_everywhere();
}

function delete_group($user, $id)
{
    $current = stored_group($id);
    check_can_grant($user, parse_permissions($current['web_permissions']), $current['flags'], (int)$current['immunity']);
    db_execute('DELETE FROM ' . table('srvgroups') . ' WHERE `id` = ?', array((int)$id));
    db_execute('DELETE FROM `vd_group_web` WHERE `group_id` = ?', array((int)$id));
    db_execute('UPDATE ' . table('admins') . ' SET `srv_group` = NULL WHERE `srv_group` = ?', array($current['name']));
    write_log('group_delete', '#' . (int)$id . ' ' . $current['name']);
    reload_admins_everywhere();
}
