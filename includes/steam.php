<?php

define('STEAM_HIGH_PART', 7656119);
define('STEAM_LOW_PART', 7960265728);

function steam64_from_account($account)
{
    $low = STEAM_LOW_PART + (float)$account;
    $high = STEAM_HIGH_PART + floor($low / 10000000000);
    $low = fmod($low, 10000000000);
    return sprintf('%d%010.0f', $high, $low);
}

function account_from_steam64($steam64)
{
    $high = (float)substr($steam64, 0, -10);
    $low = (float)substr($steam64, -10);
    return ($high - STEAM_HIGH_PART) * 10000000000 + $low - STEAM_LOW_PART;
}

function is_steam64($text)
{
    return is_string($text) && preg_match('/^7656\d{13}$/', $text) && account_from_steam64($text) > 0;
}

function steam_to_64($input)
{
    $text = trim((string)$input);
    if ($text === '') {
        return null;
    }
    if (preg_match('/^\d{17}$/', $text)) {
        return is_steam64($text) ? $text : null;
    }
    if (preg_match('/^STEAM_[0-5]:([01]):(\d+)$/i', $text, $match)) {
        return steam64_from_account((float)$match[2] * 2 + (int)$match[1]);
    }
    if (preg_match('/^\[?U:1:(\d+)\]?$/i', $text, $match)) {
        return steam64_from_account((float)$match[1]);
    }
    if (preg_match('/steamcommunity\.com\/profiles\/(\d{17})/i', $text, $match)) {
        return is_steam64($match[1]) ? $match[1] : null;
    }
    return null;
}

function steam_auth_tail($steam64)
{
    $account = account_from_steam64($steam64);
    return sprintf('%d:%.0f', fmod($account, 2), floor($account / 2));
}

function steam64_to_steam2($steam64)
{
    if (!is_steam64($steam64)) {
        return null;
    }
    return 'STEAM_1:' . steam_auth_tail($steam64);
}

function authid_pattern($steam64)
{
    return '^STEAM_[0-9]:' . steam_auth_tail($steam64) . '$';
}

function authid_to_steam64($authid)
{
    if (!preg_match('/^STEAM_[0-5]:([01]):(\d+)$/i', trim((string)$authid), $match)) {
        return null;
    }
    return steam64_from_account((float)$match[2] * 2 + (int)$match[1]);
}

function profile_url($steam64)
{
    return 'https://steamcommunity.com/profiles/' . $steam64;
}

function vanity_name($input)
{
    if (preg_match('/steamcommunity\.com\/id\/([A-Za-z0-9_-]{2,32})/i', (string)$input, $match)) {
        return $match[1];
    }
    return null;
}

function xml_field($xml, $tag)
{
    if (preg_match('/<' . $tag . '><!\[CDATA\[(.*?)\]\]><\/' . $tag . '>/s', $xml, $match)) {
        return trim($match[1]);
    }
    if (preg_match('/<' . $tag . '>([^<]*)<\/' . $tag . '>/', $xml, $match)) {
        return trim(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'));
    }
    return null;
}

function clean_avatar_url($url)
{
    if ($url && preg_match('/^https:\/\/avatars\.(?:[a-z]+\.)?steamstatic\.com\/[\w.\/-]+$/', $url)) {
        return $url;
    }
    return null;
}

function steam_level($steam64)
{
    $page = http_get('https://steamcommunity.com/profiles/' . $steam64 . '/', 5);
    if (!$page || $page['status'] != 200) {
        return null;
    }
    if (preg_match('/class="friendPlayerLevelNum">(\d+)</', $page['body'], $match)) {
        return (int)$match[1];
    }
    return null;
}

function steam_profile($input)
{
    $steam64 = steam_to_64($input);
    $vanity = $steam64 ? null : vanity_name($input);
    if (!$steam64 && !$vanity) {
        return null;
    }

    $url = $steam64
        ? 'https://steamcommunity.com/profiles/' . $steam64 . '/?xml=1'
        : 'https://steamcommunity.com/id/' . rawurlencode($vanity) . '/?xml=1';
    $response = http_get($url, 8);
    if (!$response || $response['status'] != 200) {
        if ($steam64) {
            return array('steamid' => $steam64, 'name' => null, 'avatar' => null, 'level' => null, 'offline' => true);
        }
        return null;
    }

    if (!preg_match('/<steamID64>(\d{17})<\/steamID64>/', $response['body'], $match)) {
        return null;
    }

    $xml = $response['body'];
    $public = xml_field($xml, 'privacyState') === 'public';
    $avatar = xml_field($xml, 'avatarFull');
    if (!$avatar) {
        $avatar = xml_field($xml, 'avatarMedium');
    }
    $name = xml_field($xml, 'steamID');

    return array(
        'steamid' => $match[1],
        'name' => $name !== null && $name !== '' ? cut_text($name, 64) : null,
        'avatar' => clean_avatar_url($avatar),
        'level' => $public ? steam_level($match[1]) : null,
        'private' => !$public,
        'vac' => xml_field($xml, 'vacBanned') === '1'
    );
}

function resolve_steam64($input)
{
    $steam64 = steam_to_64($input);
    if ($steam64 || !vanity_name($input)) {
        return $steam64;
    }
    $profile = steam_profile($input);
    return $profile ? $profile['steamid'] : null;
}

function steam_cards($ids)
{
    $ids = array_values(array_unique(array_filter($ids, 'is_steam64')));
    if (count($ids) === 0) {
        return array();
    }

    $cards = array();
    $missing = array();
    $now = time();
    $rows = db_rows('SELECT * FROM `vd_steam_cache` WHERE `steamid` IN (' . placeholders(count($ids)) . ')', $ids);
    $cached = array();
    foreach ($rows as $row) {
        $cached[$row['steamid']] = $row;
    }

    foreach ($ids as $id) {
        if (isset($cached[$id])) {
            $row = $cached[$id];
            $found = $row['avatar'] !== null || $row['name'] !== null;
            $lifetime = $found ? 86400 : 600;
            if ((int)$row['checked'] > $now - $lifetime) {
                $cards[$id] = $found ? array('avatar' => $row['avatar'], 'name' => $row['name']) : null;
                continue;
            }
        }
        $missing[] = $id;
    }

    $missing = array_slice($missing, 0, 12);
    $urls = array();
    foreach ($missing as $id) {
        $urls[$id] = 'https://steamcommunity.com/profiles/' . $id . '/?xml=1';
    }

    foreach (http_get_many($urls, 8) as $id => $response) {
        $card = null;
        if ($response && $response['status'] == 200) {
            $avatar = clean_avatar_url(xml_field($response['body'], 'avatarMedium'));
            $name = xml_field($response['body'], 'steamID');
            $name = $name !== null && $name !== '' ? cut_text($name, 64) : null;
            if ($avatar || $name) {
                $card = array('avatar' => $avatar, 'name' => $name);
            }
        }
        db_execute(
            'REPLACE INTO `vd_steam_cache` (`steamid`, `name`, `avatar`, `checked`) VALUES (?, ?, ?, ?)',
            array($id, $card ? $card['name'] : null, $card ? $card['avatar'] : null, $now)
        );
        $cards[$id] = $card;
    }

    return $cards;
}
