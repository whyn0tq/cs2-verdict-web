<?php

define('A2S_HEADER', "\xFF\xFF\xFF\xFF");

function a2s_info_request($challenge = '')
{
    return A2S_HEADER . "TSource Engine Query\0" . $challenge;
}

function a2s_player_request($challenge = '')
{
    return A2S_HEADER . "\x55" . ($challenge !== '' ? $challenge : A2S_HEADER);
}

function udp_address($host, $port)
{
    if (strpos($host, ':') !== false) {
        return 'udp://[' . $host . ']:' . (int)$port;
    }
    return 'udp://' . $host . ':' . (int)$port;
}

function read_c_string($data, &$offset)
{
    $end = strpos($data, "\0", $offset);
    if ($end === false) {
        $offset = strlen($data);
        return null;
    }
    $value = substr($data, $offset, $end - $offset);
    $offset = $end + 1;
    return $value;
}

function parse_a2s_info($data)
{
    $offset = 6;
    $name = read_c_string($data, $offset);
    $map = read_c_string($data, $offset);
    read_c_string($data, $offset);
    read_c_string($data, $offset);
    if ($name === null || $map === null || strlen($data) < $offset + 9) {
        return null;
    }
    $offset += 2;
    return array(
        'name' => cut_text(trim($name), 128),
        'map' => cut_text($map, 64),
        'players' => ord($data[$offset]),
        'max_players' => ord($data[$offset + 1]),
        'bots' => ord($data[$offset + 2]),
        'vac' => ord($data[$offset + 6]) === 1
    );
}

function parse_a2s_players($data)
{
    $offset = 5;
    $count = ord($data[$offset]);
    $offset++;
    $players = array();
    for ($i = 0; $i < $count && $offset < strlen($data); $i++) {
        $offset++;
        $name = read_c_string($data, $offset);
        if ($name === null || $offset + 8 > strlen($data)) {
            break;
        }
        $score = unpack('l', substr($data, $offset, 4));
        $seconds = unpack('f', substr($data, $offset + 4, 4));
        $offset += 8;
        if ($name !== '') {
            $players[] = array('name' => $name, 'score' => $score[1], 'seconds' => max(0, (int)round($seconds[1])));
        }
    }
    return $players;
}

function a2s_exchange($targets, $kind, $timeout)
{
    $sockets = array();
    $results = array();
    foreach ($targets as $key => $target) {
        $results[$key] = null;
        $socket = @stream_socket_client(udp_address($target[0], $target[1]), $errno, $errstr, 2);
        if (!$socket) {
            continue;
        }
        stream_set_blocking($socket, false);
        fwrite($socket, $kind === 'players' ? a2s_player_request() : a2s_info_request());
        $sockets[$key] = $socket;
    }

    $deadline = microtime(true) + $timeout;
    while (count($sockets) > 0) {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            break;
        }
        $read = array_values($sockets);
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 0, (int)($left * 1000000));
        if (!$ready) {
            break;
        }
        foreach ($read as $socket) {
            $key = array_search($socket, $sockets, true);
            $data = fread($socket, 4096);
            if ($data === false || strlen($data) < 5 || substr($data, 0, 4) !== A2S_HEADER) {
                continue;
            }
            $type = $data[4];
            if ($type === "\x41" && strlen($data) >= 9) {
                $challenge = substr($data, 5, 4);
                fwrite($socket, $kind === 'players' ? a2s_player_request($challenge) : a2s_info_request($challenge));
                continue;
            }
            if ($kind === 'info' && $type === "\x49") {
                $results[$key] = parse_a2s_info($data);
            } elseif ($kind === 'players' && $type === "\x44") {
                $results[$key] = parse_a2s_players($data);
            }
            fclose($socket);
            unset($sockets[$key]);
        }
    }

    foreach ($sockets as $socket) {
        fclose($socket);
    }
    return $results;
}

function query_servers_info($targets, $timeout = 1.5)
{
    return a2s_exchange($targets, 'info', $timeout);
}

function query_server_info($host, $port)
{
    $result = query_servers_info(array('server' => array($host, $port)), 2);
    if ($result['server'] === null) {
        $result = query_servers_info(array('server' => array($host, $port)), 2);
    }
    return $result['server'];
}

function query_server_players($host, $port)
{
    $result = a2s_exchange(array('server' => array($host, $port)), 'players', 2);
    return $result['server'] === null ? array() : $result['server'];
}

function rcon_packet($id, $type, $body)
{
    return pack('VVV', strlen($body) + 10, $id, $type) . $body . "\0\0";
}

function rcon_read_packets($socket, &$buffer, $wait)
{
    $packets = array();
    $read = array($socket);
    $write = null;
    $except = null;
    $seconds = (int)floor($wait);
    $micro = (int)(($wait - $seconds) * 1000000);
    if (!@stream_select($read, $write, $except, $seconds, $micro)) {
        return $packets;
    }
    $chunk = fread($socket, 8192);
    if ($chunk === false || $chunk === '') {
        return null;
    }
    $buffer .= $chunk;
    while (strlen($buffer) >= 4) {
        $size = unpack('V', substr($buffer, 0, 4));
        $size = $size[1];
        if (strlen($buffer) < $size + 4) {
            break;
        }
        $header = unpack('Vid/Vtype', substr($buffer, 4, 8));
        $id = $header['id'] > 2147483647 ? $header['id'] - 4294967296 : $header['id'];
        $body = substr($buffer, 12, max(0, $size - 10));
        $packets[] = array('id' => $id, 'type' => $header['type'], 'body' => $body);
        $buffer = substr($buffer, $size + 4);
    }
    return $packets;
}

function rcon_command($host, $port, $password, $command)
{
    $address = strpos($host, ':') !== false ? '[' . $host . ']' : $host;
    $socket = @fsockopen($address, (int)$port, $errno, $errstr, 3);
    if (!$socket) {
        fail('rcon_unreachable', 502);
    }
    stream_set_blocking($socket, false);
    $buffer = '';

    fwrite($socket, rcon_packet(1, 3, $password));
    $authorized = false;
    $deadline = microtime(true) + 4;
    while (!$authorized && microtime(true) < $deadline) {
        $packets = rcon_read_packets($socket, $buffer, 0.5);
        if ($packets === null) {
            fclose($socket);
            fail('rcon_auth_failed', 502);
        }
        foreach ($packets as $packet) {
            if ($packet['type'] != 2) {
                continue;
            }
            if ($packet['id'] == -1) {
                fclose($socket);
                fail('rcon_auth_failed', 502);
            }
            $authorized = true;
        }
    }
    if (!$authorized) {
        fclose($socket);
        fail('rcon_timeout', 502);
    }

    fwrite($socket, rcon_packet(2, 2, $command));
    $output = '';
    $wait = 2.0;
    $deadline = microtime(true) + 6;
    while (microtime(true) < $deadline) {
        $packets = rcon_read_packets($socket, $buffer, $wait);
        if ($packets === null || count($packets) === 0) {
            break;
        }
        foreach ($packets as $packet) {
            if ($packet['id'] == 2 && $packet['type'] == 0) {
                $output .= $packet['body'];
            }
        }
        $wait = 0.3;
    }
    fclose($socket);
    return trim($output);
}
