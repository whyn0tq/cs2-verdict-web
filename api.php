<?php

require dirname(__FILE__) . '/includes/bootstrap.php';

function map_image_file($name)
{
    $dir = DATA_DIR . '/maps';
    foreach (array('png', 'jpg', 'webp') as $extension) {
        if (is_file($dir . '/' . $name . '.' . $extension)) {
            return $dir . '/' . $name . '.' . $extension;
        }
    }
    $miss = $dir . '/' . $name . '.miss';
    if (is_file($miss) && filemtime($miss) > time() - 6 * 3600) {
        return null;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return null;
    }

    $response = http_get('https://raw.githubusercontent.com/ghostcap-gaming/cs2-map-images/main/cs2/' . $name . '.png', 10);
    if (!$response || $response['status'] != 200 || strlen($response['body']) > 5 * 1024 * 1024) {
        if ($response && $response['status'] == 404) {
            @touch($miss);
        }
        return null;
    }
    $body = $response['body'];
    $extension = null;
    if (substr($body, 0, 4) === "\x89PNG") {
        $extension = 'png';
    } elseif (substr($body, 0, 3) === "\xFF\xD8\xFF") {
        $extension = 'jpg';
    } elseif (substr($body, 0, 4) === 'RIFF' && substr($body, 8, 4) === 'WEBP') {
        $extension = 'webp';
    }
    if ($extension === null) {
        return null;
    }
    $file = $dir . '/' . $name . '.' . $extension;
    @file_put_contents($file, $body);
    return is_file($file) ? $file : null;
}

function serve_map()
{
    $name = clean_map_name(query_param('name'));
    if ($name === null || !is_installed()) {
        fail('not_found', 404);
    }
    if (!current_admin()) {
        $known = false;
        foreach (db_rows('SELECT `map` FROM `vd_server_info` WHERE `map` IS NOT NULL') as $row) {
            if (clean_map_name($row['map']) === $name) {
                $known = true;
            }
        }
        if (!$known) {
            fail('not_found', 404);
        }
    }
    $file = map_image_file($name);
    if (!$file) {
        fail('not_found', 404);
    }
    $types = array('png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp');
    $extension = substr($file, strrpos($file, '.') + 1);
    session_write_close();
    header('Content-Type: ' . $types[$extension]);
    header('Cache-Control: public, max-age=604800');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

function known_steam_ids($ids)
{
    if (count($ids) === 0) {
        return array();
    }
    $tails = array();
    foreach ($ids as $id) {
        $tails[steam_auth_tail($id)] = $id;
    }
    $marks = placeholders(count($tails));
    $values = array_keys($tails);
    $rows = db_rows(
        'SELECT SUBSTRING(`authid`, 9) AS tail FROM ' . table('bans') . ' WHERE SUBSTRING(`authid`, 9) IN (' . $marks . ')
         UNION SELECT SUBSTRING(`authid`, 9) FROM ' . table('comms') . ' WHERE SUBSTRING(`authid`, 9) IN (' . $marks . ')
         UNION SELECT SUBSTRING(`authid`, 9) FROM ' . table('admins') . ' WHERE SUBSTRING(`authid`, 9) IN (' . $marks . ')',
        array_merge($values, $values, $values)
    );
    $known = array();
    foreach ($rows as $row) {
        if (isset($tails[$row['tail']])) {
            $known[] = $tails[$row['tail']];
        }
    }
    $live = db_rows('SELECT DISTINCT `steamid` FROM `vd_live_players` WHERE `steamid` IN (' . placeholders(count($ids)) . ')', $ids);
    foreach ($live as $row) {
        $known[] = $row['steamid'];
    }
    return array_values(array_unique($known));
}

function action_avatars()
{
    $ids = array();
    foreach (explode(',', text_value(query_param('ids'), 2000)) as $id) {
        if (is_steam64($id) && !in_array($id, $ids)) {
            $ids[] = $id;
        }
    }
    $ids = array_slice($ids, 0, 50);
    session_write_close();
    $cards = steam_cards(known_steam_ids($ids));
    send_json(count($cards) > 0 ? $cards : new stdClass());
}

function action_steam_lookup()
{
    require_api_login();
    session_write_close();
    $query = text_value(query_param('q'), 128);
    $profile = steam_profile($query);
    if (!$profile) {
        fail('not_found', 404);
    }
    if (!$profile['name']) {
        $name = name_seen_online($profile['steamid']);
        if (!$name) {
            $name = db_value(
                'SELECT `name` FROM ' . table('bans') . " WHERE `authid` REGEXP ? AND `name` <> 'unnamed' ORDER BY `bid` DESC LIMIT 1",
                array(authid_pattern($profile['steamid']))
            );
        }
        $profile['name'] = $name ? $name : null;
    }
    send_json($profile);
}

function server_probe($ip, $port)
{
    $address = server_address_input($ip, $port);
    $info = query_server_info($address[0], $address[1]);
    if (!$info) {
        fail('server_unreachable', 404);
    }
    send_json($info);
}

function action_server_query()
{
    $user = require_api_permission('servers.manage');
    limit_rate('a2s:' . $user['id'], 40, 60);
    session_write_close();
    server_probe(query_param('ip'), query_param('port'));
}

function action_players()
{
    require_api_permission('players.view');
    $sid = int_value(query_param('id'));
    $row = load_server($sid);
    if (!$row) {
        fail('not_found', 404);
    }
    session_write_close();
    if (plugin_connected($row)) {
        send_json(array('source' => 'plugin', 'players' => live_players($sid), 'server' => present_server($row)));
    }
    $players = array();
    if (server_is_online($row)) {
        foreach (query_server_players($row['ip'], (int)$row['port']) as $player) {
            $players[] = array('source' => 'a2s', 'steamid' => null, 'name' => $player['name'], 'score' => $player['score'], 'seconds' => $player['seconds']);
        }
    }
    send_json(array('source' => 'a2s', 'players' => $players, 'server' => present_server($row)));
}

function action_feed()
{
    require_api_permission('players.view');
    send_json(feed_events(int_value(query_param('id')), int_value(query_param('after'))));
}

function action_plugin_config()
{
    require_owner();
    $server = find_server_or_fail(int_value(query_param('id')));
    write_log('plugin_config_view', '#' . (int)$server['sid']);
    $db = config_value('db');
    $config = plugin_config_text($server['sid']);
    $config['local'] = in_array($db['host'], array('127.0.0.1', 'localhost'));
    send_json($config);
}

function punishment_kind()
{
    $kind = (string)body_value('kind', '');
    if (!in_array($kind, punishment_kinds())) {
        fail('bad_request');
    }
    return $kind;
}

function action_punish_add()
{
    $kind = punishment_kind();
    $user = require_api_permission($kind . '.add');
    send_json(array('id' => add_punishment($kind, $user, request_body())));
}

function action_punish_edit()
{
    $kind = punishment_kind();
    $user = require_api_permission(array($kind . '.add', $kind . '.edit'));
    edit_punishment($kind, $user, int_value(body_value('id')), request_body());
    send_json(array('ok' => true));
}

function action_punish_remove()
{
    $kind = punishment_kind();
    $user = require_api_permission(array($kind . '.add', $kind . '.edit'));
    remove_punishment($kind, $user, int_value(body_value('id')), body_value('reason', ''));
    send_json(array('ok' => true));
}

function action_punish_delete()
{
    $kind = punishment_kind();
    require_api_permission($kind . '.delete');
    delete_punishment($kind, int_value(body_value('id')));
    send_json(array('ok' => true));
}

function action_admin_save()
{
    $user = require_api_permission('admins.manage');
    $id = int_value(body_value('id'));
    if ($id > 0) {
        update_admin($user, $id, request_body());
        send_json(array('ok' => true));
    }
    send_json(array('id' => create_admin($user, request_body())));
}

function action_admin_delete()
{
    $user = require_api_permission('admins.manage');
    delete_admin($user, int_value(body_value('id')));
    send_json(array('ok' => true));
}

function action_group_save()
{
    $user = require_api_permission('groups.manage');
    $id = int_value(body_value('id'));
    if ($id > 0) {
        update_group($user, $id, request_body());
        send_json(array('ok' => true));
    }
    send_json(array('id' => create_group($user, request_body())));
}

function action_group_delete()
{
    $user = require_api_permission('groups.manage');
    delete_group($user, int_value(body_value('id')));
    send_json(array('ok' => true));
}

function action_server_save()
{
    require_api_permission('servers.manage');
    $id = int_value(body_value('id'));
    $address = server_address_input(body_value('ip'), body_value('port'));
    $rcon = text_value(body_value('rcon_password', ''), 128);
    $taken = (int)db_value(
        'SELECT COUNT(*) FROM ' . table('servers') . ' WHERE `ip` = ? AND `port` = ? AND `sid` <> ?',
        array($address[0], $address[1], $id)
    );
    if ($taken > 0) {
        fail('duplicate_server', 409);
    }

    if ($id > 0) {
        $existing = find_server_or_fail($id);
        $moved = $address[0] !== $existing['ip'] || $address[1] !== (int)$existing['port'];
        $password = body_value('clear_rcon') === true ? '' : ($rcon !== '' ? $rcon : $existing['rcon']);
        db_execute('UPDATE ' . table('servers') . ' SET `ip` = ?, `port` = ?, `rcon` = ? WHERE `sid` = ?', array($address[0], $address[1], $password, $id));
        if ($moved) {
            db_execute('DELETE FROM `vd_server_info` WHERE `sid` = ?', array($id));
            save_server_info($id, query_server_info($address[0], $address[1]));
        }
        write_log('server_edit', '#' . $id . ' ' . $address[0] . ':' . $address[1]);
        send_json(array('ok' => true));
    }

    db_execute(
        'INSERT INTO ' . table('servers') . ' (`ip`, `port`, `rcon`, `modid`, `enabled`) VALUES (?, ?, ?, 0, 1)',
        array($address[0], $address[1], $rcon)
    );
    $id = db_insert_id();
    $info = query_server_info($address[0], $address[1]);
    save_server_info($id, $info);
    write_log('server_add', '#' . $id . ' ' . ($info ? $info['name'] . ' ' : '') . $address[0] . ':' . $address[1]);
    send_json(array('id' => $id));
}

function action_server_delete()
{
    require_api_permission('servers.manage');
    $id = int_value(body_value('id'));
    $row = load_server($id);
    if (!$row) {
        fail('not_found', 404);
    }
    db_execute('DELETE FROM ' . table('servers') . ' WHERE `sid` = ?', array($id));
    foreach (array('vd_server_info', 'vd_live_servers', 'vd_live_players', 'vd_feed', 'vd_queue') as $name) {
        db_execute('DELETE FROM `' . $name . '` WHERE `sid` = ?', array($id));
    }
    write_log('server_delete', '#' . $id . ' ' . server_name($row));
    send_json(array('ok' => true));
}

function target_steamid()
{
    $steam64 = steam_to_64(body_value('steamid', ''));
    if (!$steam64) {
        fail('invalid_steamid');
    }
    return $steam64;
}

function server_label($sid)
{
    $row = load_server($sid);
    return $row ? server_name($row) : '#' . $sid;
}

function action_kick()
{
    $user = require_api_permission('players.kick');
    limit_rate('rcon:' . $user['id'], 30, 60);
    $sid = int_value(body_value('id'));
    $steam64 = target_steamid();
    check_targetable($user, $steam64);
    $reason = str_replace(array('"', ';'), '', text_value(body_value('reason', ''), 128));
    $link = server_link_row($sid);
    if (!$link) {
        fail('not_found', 404);
    }
    if (plugin_connected($link)) {
        run_plugin_command($sid, 'kick', array('steamid' => $steam64, 'reason' => $reason, 'admin' => $user['name']));
    } elseif ($link['rcon'] !== '') {
        rcon_command($link['ip'], (int)$link['port'], $link['rcon'], 'vd_kick ' . $steam64 . ' ' . $reason);
    } else {
        fail('server_no_channel', 502);
    }
    write_log('kick', $steam64 . ' @' . server_label($sid) . ' ' . $reason);
    send_json(array('ok' => true));
}

function action_slay()
{
    $user = require_api_permission('players.control');
    limit_rate('rcon:' . $user['id'], 30, 60);
    $sid = int_value(body_value('id'));
    $steam64 = target_steamid();
    check_targetable($user, $steam64);
    require_plugin_link($sid);
    run_plugin_command($sid, 'slay', array('steamid' => $steam64, 'admin' => $user['name']));
    write_log('slay', $steam64 . ' @' . server_label($sid));
    send_json(array('ok' => true));
}

function action_team()
{
    $user = require_api_permission('players.control');
    limit_rate('rcon:' . $user['id'], 30, 60);
    $sid = int_value(body_value('id'));
    $team = (string)body_value('team', '');
    if (!in_array($team, array('t', 'ct', 'spec'))) {
        fail('invalid_team');
    }
    $steam64 = target_steamid();
    check_targetable($user, $steam64);
    require_plugin_link($sid);
    run_plugin_command($sid, 'team', array('steamid' => $steam64, 'team' => $team, 'admin' => $user['name']));
    write_log('team_move', $steam64 . ' → ' . $team . ' @' . server_label($sid));
    send_json(array('ok' => true));
}

function action_say()
{
    $user = require_api_permission('players.chat');
    limit_rate('chat:' . $user['id'], 20, 60);
    $sid = int_value(body_value('id'));
    $text = text_value(body_value('text', ''), 200);
    if ($text === '') {
        fail('message_empty');
    }
    $steam64 = '';
    if (body_value('steamid')) {
        $steam64 = target_steamid();
    }
    require_plugin_link($sid);
    run_plugin_command($sid, 'say', array('text' => $text, 'steamid' => $steam64, 'admin' => $user['name']));
    write_log('say', '@' . server_label($sid) . ($steam64 !== '' ? ' → ' . $steam64 : '') . ' ' . $text);
    send_json(array('ok' => true));
}

function action_rcon()
{
    $user = require_api_permission('servers.rcon');
    limit_rate('rcon:' . $user['id'], 30, 60);
    $sid = int_value(body_value('id'));
    $server = find_server_or_fail($sid);
    $command = text_value(body_value('command', ''), 512);
    if ($command === '') {
        fail('command_required');
    }
    if ($server['rcon'] === '') {
        fail('rcon_not_configured');
    }
    session_write_close();
    $output = rcon_command($server['ip'], (int)$server['port'], $server['rcon'], $command);
    write_log('rcon', '@' . server_label($sid) . ' ' . $command);
    send_json(array('output' => $output));
}

function action_install_query()
{
    if (is_installed() || empty($_SESSION['install_code_ok'])) {
        fail('forbidden', 403);
    }
    session_write_close();
    server_probe(query_param('ip'), query_param('port'));
}

try {
    $action = (string)query_param('action');
    if ($action === 'map') {
        serve_map();
    }
    if ($action === 'install_query') {
        action_install_query();
    }
    if (!is_installed()) {
        fail('not_installed', 503);
    }
    upgrade_schema_if_needed();

    $reading = array('avatars', 'steam_lookup', 'server_query', 'players', 'feed', 'plugin_config');
    $writing = array(
        'punish_add', 'punish_edit', 'punish_remove', 'punish_delete',
        'admin_save', 'admin_delete', 'group_save', 'group_delete',
        'server_save', 'server_delete', 'kick', 'slay', 'team', 'say', 'rcon'
    );
    if (in_array($action, $writing)) {
        if (!is_post()) {
            fail('bad_request', 405);
        }
        check_csrf_header();
    } elseif (!in_array($action, $reading)) {
        fail('not_found', 404);
    }

    $handler = 'action_' . $action;
    $handler();
} catch (PanelError $error) {
    $body = array('error' => $error->code_name);
    if ($error->detail) {
        $body['detail'] = $error->detail;
    }
    send_json($body, $error->status);
} catch (Exception $error) {
    error_log('Verdict API: ' . $error->getMessage());
    send_json(array('error' => $error instanceof PDOException ? 'db_error' : 'server_error'), 500);
}
