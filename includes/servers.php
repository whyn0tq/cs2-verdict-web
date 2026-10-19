<?php

define('SERVER_REFRESH_SECONDS', 15);
define('PLUGIN_STALE_SECONDS', 20);

function server_select_sql()
{
    return 'SELECT s.`sid`, s.`ip`, s.`port`, s.`rcon`, s.`enabled`, i.`hostname`, i.`map`, i.`players`, i.`max_players`,
            i.`bots`, i.`online`, i.`checked`, i.`last_seen`, l.`version` AS plugin_version, l.`score_t`, l.`score_ct`,
            l.`updated` AS plugin_seen
        FROM ' . table('servers') . ' s
        LEFT JOIN `vd_server_info` i ON i.`sid` = s.`sid`
        LEFT JOIN `vd_live_servers` l ON l.`sid` = s.`sid`';
}

function load_servers()
{
    return refresh_servers(db_rows(server_select_sql() . ' ORDER BY s.`sid`'));
}

function load_server($sid)
{
    $row = db_row(server_select_sql() . ' WHERE s.`sid` = ?', array((int)$sid));
    if (!$row) {
        return null;
    }
    $rows = refresh_servers(array($row));
    return $rows[0];
}

function find_server_or_fail($sid)
{
    $server = db_row('SELECT * FROM ' . table('servers') . ' WHERE `sid` = ?', array((int)$sid));
    if (!$server) {
        fail('not_found', 404);
    }
    return $server;
}

function save_server_info($sid, $info)
{
    $now = time();
    if ($info) {
        db_execute(
            'REPLACE INTO `vd_server_info` (`sid`, `hostname`, `map`, `players`, `max_players`, `bots`, `online`, `checked`, `last_seen`)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
            array((int)$sid, $info['name'] !== '' ? $info['name'] : null, $info['map'] !== '' ? $info['map'] : null,
                max(0, $info['players'] - $info['bots']), $info['max_players'], $info['bots'], $now, $now)
        );
        return;
    }
    db_execute(
        'INSERT INTO `vd_server_info` (`sid`, `online`, `checked`) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE `online` = 0, `checked` = ?',
        array((int)$sid, $now, $now)
    );
}

function refresh_servers($rows)
{
    $now = time();
    $targets = array();
    foreach ($rows as $index => $row) {
        if ((int)$row['checked'] < $now - SERVER_REFRESH_SECONDS) {
            $targets[$index] = array($row['ip'], (int)$row['port']);
            db_execute(
                'INSERT INTO `vd_server_info` (`sid`, `checked`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `checked` = ?',
                array((int)$row['sid'], $now, $now)
            );
        }
    }
    if (count($targets) === 0) {
        return $rows;
    }

    $results = query_servers_info($targets, 1.5);
    foreach ($results as $index => $info) {
        save_server_info($rows[$index]['sid'], $info);
        $rows[$index]['checked'] = $now;
        $rows[$index]['online'] = $info ? 1 : 0;
        if ($info) {
            $rows[$index]['hostname'] = $info['name'];
            $rows[$index]['map'] = $info['map'];
            $rows[$index]['players'] = max(0, $info['players'] - $info['bots']);
            $rows[$index]['max_players'] = $info['max_players'];
            $rows[$index]['last_seen'] = $now;
        }
    }
    return $rows;
}

function plugin_connected($row)
{
    return (int)$row['plugin_seen'] > time() - PLUGIN_STALE_SECONDS;
}

function server_name($row)
{
    return $row['hostname'] !== null && $row['hostname'] !== '' ? $row['hostname'] : $row['ip'] . ':' . $row['port'];
}

function server_is_online($row)
{
    return (int)$row['online'] === 1 || plugin_connected($row);
}

function present_server($row)
{
    $manage = can('servers.manage') || can('servers.rcon');
    $server = array(
        'id' => (int)$row['sid'],
        'ip' => $row['ip'],
        'port' => (int)$row['port'],
        'name' => server_name($row),
        'hostname' => $row['hostname'],
        'map' => $row['map'],
        'players' => (int)$row['players'],
        'max_players' => (int)$row['max_players'],
        'online' => server_is_online($row),
        'last_seen' => (int)$row['last_seen'] > 0 ? (int)$row['last_seen'] : null
    );
    if ($manage) {
        $server['has_rcon'] = $row['rcon'] !== '';
    }
    if (can('players.view')) {
        $connected = plugin_connected($row);
        $server['plugin'] = array(
            'connected' => $connected,
            'version' => $connected ? $row['plugin_version'] : null,
            'teams' => $connected ? array('t' => (int)$row['score_t'], 'ct' => (int)$row['score_ct']) : null
        );
    }
    return $server;
}

function map_label($map)
{
    $parts = explode('/', (string)$map);
    return end($parts);
}

function clean_map_name($value)
{
    $parts = explode('/', strtolower((string)$value));
    $name = preg_replace('/\.png$/', '', end($parts));
    return preg_match('/^[a-z0-9_]{1,64}$/', $name) ? $name : null;
}

function server_address_input($ip, $port)
{
    $ip = text_value($ip, 64);
    $port = int_value($port, 27015);
    if (!preg_match('/^[A-Za-z0-9.:-]{1,64}$/', $ip)) {
        fail('invalid_ip');
    }
    if ($port < 1 || $port > 65535) {
        fail('invalid_port');
    }
    return array($ip, $port);
}

function team_name($number)
{
    $teams = array(0 => 'none', 1 => 'spec', 2 => 't', 3 => 'ct');
    return isset($teams[(int)$number]) ? $teams[(int)$number] : 'none';
}

function live_players($sid)
{
    $rows = db_rows('SELECT * FROM `vd_live_players` WHERE `sid` = ? ORDER BY `slot`', array((int)$sid));
    $ids = array();
    foreach ($rows as $row) {
        if (is_steam64($row['steamid'])) {
            $ids[] = $row['steamid'];
        }
    }
    $marks = player_marks($ids);
    $show_ip = can('players.ip');
    $players = array();
    foreach ($rows as $row) {
        $steamid = is_steam64($row['steamid']) ? $row['steamid'] : null;
        $mark = $steamid && isset($marks[$steamid]) ? $marks[$steamid] : array('muted' => false, 'gagged' => false, 'is_admin' => false);
        $players[] = array(
            'source' => 'plugin',
            'slot' => (int)$row['slot'],
            'steamid' => $steamid,
            'steam2' => $steamid ? steam64_to_steam2($steamid) : null,
            'name' => $row['name'],
            'bot' => (int)$row['bot'] === 1 || !$steamid,
            'team' => team_name($row['team']),
            'alive' => (int)$row['alive'] === 1,
            'health' => (int)$row['health'],
            'score' => (int)$row['score'],
            'kills' => (int)$row['kills'],
            'deaths' => (int)$row['deaths'],
            'assists' => (int)$row['assists'],
            'ping' => (int)$row['ping'],
            'ip' => $show_ip ? $row['ip'] : null,
            'joined_at' => (int)$row['connected'] > 0 ? (int)$row['connected'] : null,
            'muted' => $mark['muted'],
            'gagged' => $mark['gagged'],
            'is_admin' => $mark['is_admin']
        );
    }
    return $players;
}

function player_marks($ids)
{
    $marks = array();
    if (count($ids) === 0) {
        return $marks;
    }
    $now = time();
    $patterns = array();
    foreach ($ids as $id) {
        $marks[$id] = array('muted' => false, 'gagged' => false, 'is_admin' => false);
        $patterns[steam_auth_tail($id)] = $id;
    }

    $tails = array_keys($patterns);
    $rows = db_rows(
        'SELECT SUBSTRING(`authid`, 9) AS tail, `type` FROM ' . table('comms') . '
         WHERE SUBSTRING(`authid`, 9) IN (' . placeholders(count($tails)) . ') AND `RemoveType` IS NULL
         AND (`length` = 0 OR `ends` > ?)',
        array_merge($tails, array($now))
    );
    foreach ($rows as $row) {
        if (!isset($patterns[$row['tail']])) {
            continue;
        }
        $id = $patterns[$row['tail']];
        $type = (int)$row['type'];
        if ($type === 1 || $type === 3) {
            $marks[$id]['muted'] = true;
        }
        if ($type === 2 || $type === 3) {
            $marks[$id]['gagged'] = true;
        }
    }

    $rows = db_rows(
        'SELECT SUBSTRING(`authid`, 9) AS tail FROM ' . table('admins') . ' WHERE SUBSTRING(`authid`, 9) IN (' . placeholders(count($tails)) . ')',
        $tails
    );
    foreach ($rows as $row) {
        if (isset($patterns[$row['tail']])) {
            $marks[$patterns[$row['tail']]]['is_admin'] = true;
        }
    }
    return $marks;
}

function queue_command($sid, $command, $args)
{
    db_execute(
        'INSERT INTO `vd_queue` (`sid`, `command`, `args`, `status`, `created`) VALUES (?, ?, ?, 0, ?)',
        array((int)$sid, $command, json_encode($args), time())
    );
    return db_insert_id();
}

function run_plugin_command($sid, $command, $args)
{
    $id = queue_command($sid, $command, $args);
    $deadline = microtime(true) + 6;
    while (microtime(true) < $deadline) {
        usleep(250000);
        $row = db_row('SELECT `status`, `result` FROM `vd_queue` WHERE `id` = ?', array($id));
        if (!$row) {
            break;
        }
        if ((int)$row['status'] === 1) {
            return $row['result'];
        }
        if ((int)$row['status'] === 2) {
            $code = preg_match('/^[a-z_]{1,40}$/', (string)$row['result']) ? $row['result'] : 'plugin_error';
            $status = in_array($code, array('player_not_found', 'player_dead', 'already_in_team', 'invalid_team', 'message_empty')) ? 409 : 502;
            fail($code, $status);
        }
    }
    db_execute('UPDATE `vd_queue` SET `status` = 3 WHERE `id` = ? AND `status` = 0', array($id));
    fail('agent_timeout', 502);
}

function server_link_row($sid)
{
    return db_row(
        'SELECT s.`sid`, s.`ip`, s.`port`, s.`rcon`, l.`updated` AS plugin_seen FROM ' . table('servers') . ' s
         LEFT JOIN `vd_live_servers` l ON l.`sid` = s.`sid` WHERE s.`sid` = ?',
        array((int)$sid)
    );
}

function require_plugin_link($sid)
{
    $row = server_link_row($sid);
    if (!$row) {
        fail('not_found', 404);
    }
    if (!plugin_connected($row)) {
        fail('agent_offline', 409);
    }
    return $row;
}

function sync_player_everywhere($steam64, $ip)
{
    $conditions = array();
    $params = array(time() - PLUGIN_STALE_SECONDS);
    if ($steam64) {
        $conditions[] = 'p.`steamid` = ?';
        $params[] = $steam64;
    }
    if ($ip) {
        $conditions[] = 'p.`ip` = ?';
        $params[] = $ip;
    }
    if (count($conditions) === 0) {
        return;
    }
    $rows = db_rows(
        'SELECT DISTINCT p.`sid`, p.`steamid` FROM `vd_live_players` p JOIN `vd_live_servers` l ON l.`sid` = p.`sid`
         WHERE l.`updated` > ? AND (' . implode(' OR ', $conditions) . ')',
        $params
    );
    foreach ($rows as $row) {
        if (is_steam64($row['steamid'])) {
            queue_command($row['sid'], 'sync', array('steamid' => $row['steamid']));
        }
    }
}

function reload_admins_everywhere()
{
    $rows = db_rows('SELECT `sid` FROM `vd_live_servers` WHERE `updated` > ?', array(time() - PLUGIN_STALE_SECONDS));
    foreach ($rows as $row) {
        queue_command($row['sid'], 'reload_admins', array());
    }
}

function feed_events($sid, $after)
{
    if ($after > 0) {
        $rows = db_rows('SELECT * FROM `vd_feed` WHERE `sid` = ? AND `id` > ? ORDER BY `id` LIMIT 150', array((int)$sid, (int)$after));
    } else {
        $rows = array_reverse(db_rows('SELECT * FROM `vd_feed` WHERE `sid` = ? ORDER BY `id` DESC LIMIT 150', array((int)$sid)));
    }
    $events = array();
    foreach ($rows as $row) {
        $event = array(
            'id' => (int)$row['id'],
            'kind' => $row['kind'],
            'steamid' => is_steam64($row['steamid']) ? $row['steamid'] : null,
            'name' => $row['name'],
            'text' => $row['text'],
            'at' => (int)$row['created']
        );
        $meta = $row['meta'] ? json_decode($row['meta'], true) : null;
        if (is_array($meta)) {
            foreach (array('team', 'teamOnly', 'dead', 'blocked', 'from', 'admin') as $key) {
                if (isset($meta[$key])) {
                    $event[$key] = $meta[$key];
                }
            }
        }
        $events[] = $event;
    }
    return $events;
}

function sweep_live_tables()
{
    if (mt_rand(1, 100) !== 1) {
        return;
    }
    db_execute('DELETE FROM `vd_feed` WHERE `created` < ?', array(time() - 14 * 86400));
    db_execute('DELETE FROM `vd_queue` WHERE `created` < ?', array(time() - 3600));
}

function plugin_config_text($sid)
{
    $db = config_value('db');
    $lines = array(
        '"Databases"',
        '{',
        '    "verdict"',
        '    {',
        '        "host"      "' . $db['host'] . '"',
        '        "user"      "' . $db['user'] . '"',
        '        "pass"      "' . $db['password'] . '"',
        '        "database"  "' . $db['database'] . '"',
        '        "port"      "' . $db['port'] . '"',
        '    }',
        '}'
    );
    $core = array(
        '"database_prefix"   "' . rtrim(table_prefix(), '_') . '"',
        '"server_id"         "' . (int)$sid . '"'
    );
    return array('databases' => implode("\n", $lines), 'core' => implode("\n", $core));
}
