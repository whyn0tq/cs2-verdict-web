<?php

function punishment_kinds()
{
    return array('bans', 'comms');
}

function comm_types()
{
    return array(1 => 'mute', 2 => 'gag', 3 => 'silence');
}

function comm_type_number($name)
{
    $number = array_search($name, comm_types());
    return $number === false ? 0 : (int)$number;
}

function punishment_status_sql()
{
    return "CASE
        WHEN p.`RemoveType` IS NOT NULL AND p.`RemoveType` <> 'E' THEN 'unbanned'
        WHEN p.`RemoveType` = 'E' OR (p.`length` > 0 AND p.`ends` <= UNIX_TIMESTAMP()) THEN 'expired'
        ELSE 'active' END";
}

function punishment_from_sql($kind)
{
    return ' FROM ' . table($kind) . ' p
        LEFT JOIN ' . table('admins') . ' a ON a.`aid` = p.`aid`
        LEFT JOIN ' . table('admins') . ' r ON r.`aid` = p.`RemovedBy`
        LEFT JOIN ' . table('servers') . ' s ON s.`sid` = p.`sid`
        LEFT JOIN `vd_server_info` i ON i.`sid` = p.`sid`';
}

function punishment_select_sql($kind)
{
    return 'SELECT p.*, ' . punishment_status_sql() . ' AS status, a.`user` AS admin_user, r.`user` AS remover_user,
        s.`ip` AS server_ip, s.`port` AS server_port, i.`hostname` AS server_hostname' . punishment_from_sql($kind);
}

function punishment_filters($kind, $search, $status, $type)
{
    $where = array();
    $params = array();

    if ($search !== '') {
        $steam64 = steam_to_64($search);
        if ($steam64) {
            $where[] = 'p.`authid` REGEXP ?';
            $params[] = authid_pattern($steam64);
        } else {
            $like = '%' . $search . '%';
            $parts = array('p.`name` LIKE ?', 'p.`reason` LIKE ?', 'a.`user` LIKE ?');
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            if ($kind === 'bans' && can('players.ip')) {
                $parts[] = 'p.`ip` = ?';
                $params[] = $search;
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
    }

    if (in_array($status, array('active', 'expired', 'unbanned'))) {
        $where[] = punishment_status_sql() . ' = ?';
        $params[] = $status;
    }

    if ($kind === 'comms' && comm_type_number($type) > 0) {
        $where[] = 'p.`type` = ?';
        $params[] = comm_type_number($type);
    }

    return array(count($where) > 0 ? ' WHERE ' . implode(' AND ', $where) : '', $params);
}

function list_punishments($kind, $search, $status, $type, $page, $per_page = 25)
{
    $filters = punishment_filters($kind, $search, $status, $type);
    $total = (int)db_value('SELECT COUNT(*)' . punishment_from_sql($kind) . $filters[0], $filters[1]);
    $pages = paginate($total, $page, $per_page);
    $rows = db_rows(
        punishment_select_sql($kind) . $filters[0] . ' ORDER BY p.`bid` DESC LIMIT ? OFFSET ?',
        array_merge($filters[1], array($pages['limit'], $pages['offset']))
    );
    $items = array();
    foreach ($rows as $row) {
        $items[] = present_punishment($kind, $row);
    }
    $pages['items'] = $items;
    return $pages;
}

function console_name()
{
    return 'Console';
}

function present_punishment($kind, $row)
{
    $steamid = authid_to_steam64($row['authid']);
    $name = trim((string)$row['name']);
    if ($name === 'unnamed') {
        $name = '';
    }
    $length = (int)$row['length'];
    $server_name = null;
    if ((int)$row['sid'] > 0 && $row['server_ip'] !== null) {
        $server_name = $row['server_hostname'] ? $row['server_hostname'] : $row['server_ip'] . ':' . $row['server_port'];
    }
    $removed = $row['RemoveType'] !== null && $row['RemoveType'] !== 'E';

    $item = array(
        'id' => (int)$row['bid'],
        'steamid' => $steamid,
        'steam2' => $steamid ? steam64_to_steam2($steamid) : null,
        'name' => $name !== '' ? $name : null,
        'reason' => (string)$row['reason'],
        'duration' => (int)floor($length / 60),
        'created_at' => (int)$row['created'],
        'expires_at' => $length > 0 ? (int)$row['ends'] : null,
        'admin_id' => (int)$row['aid'],
        'admin_name' => (int)$row['aid'] > 0 && $row['admin_user'] !== null ? $row['admin_user'] : console_name(),
        'server_id' => (int)$row['sid'] > 0 ? (int)$row['sid'] : null,
        'server_name' => $server_name,
        'status' => $row['status'],
        'unbanned_at' => $removed ? (int)$row['RemovedOn'] : null,
        'unban_admin_name' => $removed ? ((int)$row['RemovedBy'] > 0 && $row['remover_user'] !== null ? $row['remover_user'] : console_name()) : null,
        'unban_reason' => $removed ? (string)$row['ureason'] : null
    );
    if ($kind === 'bans') {
        $item['ip'] = can('players.ip') && $row['ip'] !== '' ? $row['ip'] : null;
    } else {
        $types = comm_types();
        $item['type'] = isset($types[(int)$row['type']]) ? $types[(int)$row['type']] : 'mute';
    }
    return $item;
}

function load_punishment($kind, $id)
{
    $row = db_row('SELECT * FROM ' . table($kind) . ' WHERE `bid` = ?', array((int)$id));
    if (!$row) {
        fail('not_found', 404);
    }
    return $row;
}

function check_can_modify($kind, $row, $user)
{
    if (can($kind . '.edit')) {
        return;
    }
    if (!can($kind . '.add') || (int)$row['aid'] !== (int)$user['id']) {
        fail('forbidden', 403);
    }
}

function duration_minutes($value)
{
    $minutes = int_value($value, -1);
    if ($minutes < 0 || $minutes > 5256000) {
        fail('invalid_duration');
    }
    return $minutes;
}

function name_seen_online($steam64)
{
    $name = db_value('SELECT `name` FROM `vd_live_players` WHERE `steamid` = ? LIMIT 1', array($steam64));
    return $name !== null && $name !== '' ? $name : null;
}

function add_punishment($kind, $user, $input)
{
    $raw_steamid = text_value(isset($input['steamid']) ? $input['steamid'] : '', 128);
    $steam64 = $raw_steamid !== '' ? resolve_steam64($raw_steamid) : null;
    if ($raw_steamid !== '' && !$steam64) {
        fail('invalid_steamid');
    }

    $ip = '';
    if ($kind === 'bans') {
        $ip = text_value(isset($input['ip']) ? $input['ip'] : '', 45);
        if ($ip !== '' && !valid_ip($ip)) {
            fail('invalid_ip');
        }
        if (!$steam64 && $ip === '') {
            fail('steamid_or_ip_required');
        }
    } elseif (!$steam64) {
        fail('invalid_steamid');
    }

    $type = 0;
    if ($kind === 'comms') {
        $type = comm_type_number(isset($input['type']) ? $input['type'] : '');
        if ($type === 0) {
            fail('invalid_type');
        }
    }

    check_targetable($user, $steam64);

    $minutes = duration_minutes(isset($input['duration']) ? $input['duration'] : -1);
    $seconds = $minutes * 60;
    $reason = text_value(isset($input['reason']) ? $input['reason'] : '', 255);
    $server_id = int_value(isset($input['server_id']) ? $input['server_id'] : 0);
    $name = text_value(isset($input['name']) ? $input['name'] : '', 64);
    if ($name === '' && $steam64) {
        $name = (string)name_seen_online($steam64);
    }
    if ($name === '') {
        $name = 'unnamed';
    }
    $now = time();
    $authid = $steam64 ? steam64_to_steam2($steam64) : '';

    if ($kind === 'bans') {
        db_execute(
            'INSERT INTO ' . table('bans') . ' (`type`, `ip`, `authid`, `name`, `created`, `ends`, `length`, `reason`, `aid`, `adminIp`, `sid`, `country`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array($steam64 ? 0 : 1, $ip, $authid, $name, $now, $now + $seconds, $seconds, $reason, $user['id'], client_ip(), max(0, $server_id), ' ')
        );
    } else {
        db_execute(
            'INSERT INTO ' . table('comms') . ' (`authid`, `name`, `created`, `ends`, `length`, `reason`, `aid`, `adminIp`, `sid`, `type`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array($authid, $name, $now, $now + $seconds, $seconds, $reason, $user['id'], client_ip(), max(0, $server_id), $type)
        );
    }
    $id = db_insert_id();

    $types = comm_types();
    $label = $kind === 'comms' ? $types[$type] . ' ' : '';
    write_log($kind . '_add', $label . ($steam64 ? $steam64 : $ip) . ' ' . ($name !== 'unnamed' ? $name : '') . ' ' . $minutes . 'm ' . $reason);
    sync_player_everywhere($steam64, $kind === 'bans' ? $ip : '');
    return $id;
}

function edit_punishment($kind, $user, $id, $input)
{
    $row = load_punishment($kind, $id);
    check_can_modify($kind, $row, $user);

    $seconds = array_key_exists('duration', $input) ? duration_minutes($input['duration']) * 60 : (int)$row['length'];
    $reason = array_key_exists('reason', $input) ? text_value($input['reason'], 255) : $row['reason'];
    $name = array_key_exists('name', $input) ? text_value($input['name'], 64) : $row['name'];
    if ($name === '') {
        $name = 'unnamed';
    }

    db_execute(
        'UPDATE ' . table($kind) . ' SET `reason` = ?, `name` = ?, `length` = ?, `ends` = `created` + ? WHERE `bid` = ?',
        array($reason, $name, $seconds, $seconds, (int)$id)
    );
    write_log($kind . '_edit', '#' . (int)$id . ' ' . (int)floor($seconds / 60) . 'm ' . $reason);
    sync_player_everywhere(authid_to_steam64($row['authid']), $kind === 'bans' ? $row['ip'] : '');
}

function remove_punishment($kind, $user, $id, $reason)
{
    $row = load_punishment($kind, $id);
    check_can_modify($kind, $row, $user);
    if ($row['RemoveType'] !== null && $row['RemoveType'] !== 'E') {
        fail('already_removed');
    }
    $reason = text_value($reason, 255);
    db_execute(
        'UPDATE ' . table($kind) . " SET `RemovedBy` = ?, `RemoveType` = 'U', `RemovedOn` = ?, `ureason` = ? WHERE `bid` = ?",
        array($user['id'], time(), $reason, (int)$id)
    );
    $steam64 = authid_to_steam64($row['authid']);
    write_log($kind . '_remove', '#' . (int)$id . ' ' . ($steam64 ? $steam64 : (string)$row['ip']) . ' ' . $reason);
    sync_player_everywhere($steam64, $kind === 'bans' ? $row['ip'] : '');
}

function delete_punishment($kind, $id)
{
    $row = load_punishment($kind, $id);
    db_execute('DELETE FROM ' . table($kind) . ' WHERE `bid` = ?', array((int)$id));
    $steam64 = authid_to_steam64($row['authid']);
    write_log($kind . '_delete', '#' . (int)$id . ' ' . ($steam64 ? $steam64 : (string)$row['ip']));
    sync_player_everywhere($steam64, $kind === 'bans' ? $row['ip'] : '');
}

function punishment_stats()
{
    $active = "`RemoveType` IS NULL AND (`length` = 0 OR `ends` > UNIX_TIMESTAMP())";
    $row = db_row(
        'SELECT
            (SELECT COUNT(*) FROM ' . table('bans') . ') AS bans_total,
            (SELECT COUNT(*) FROM ' . table('bans') . ' WHERE ' . $active . ') AS bans_active,
            (SELECT COUNT(*) FROM ' . table('comms') . ' WHERE ' . $active . ') AS comms_active'
    );
    return array(
        'bans_total' => (int)$row['bans_total'],
        'bans_active' => (int)$row['bans_active'],
        'comms_active' => (int)$row['comms_active']
    );
}
