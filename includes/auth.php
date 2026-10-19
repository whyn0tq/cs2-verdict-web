<?php

function admin_select_sql()
{
    return 'SELECT a.`aid`, a.`user`, a.`authid`, a.`password`, a.`srv_flags`, a.`immunity` AS own_immunity, a.`srv_group`,
            a.`expired`, a.`comment`, g.`id` AS group_id, g.`flags` AS group_flags, g.`immunity` AS group_immunity,
            w.`permissions`, w.`session_version`, w.`totp_secret`, w.`totp_enabled`, w.`totp_last_step`,
            gw.`permissions` AS group_permissions
        FROM ' . table('admins') . ' a
        LEFT JOIN ' . table('srvgroups') . ' g ON g.`name` = a.`srv_group`
        LEFT JOIN `vd_admin_web` w ON w.`aid` = a.`aid`
        LEFT JOIN `vd_group_web` gw ON gw.`group_id` = g.`id`';
}

function find_admin($aid)
{
    return db_row(admin_select_sql() . ' WHERE a.`aid` = ? LIMIT 1', array((int)$aid));
}

function admin_is_active($row)
{
    $expired = (int)$row['expired'];
    return $expired <= 0 || $expired > time();
}

function admin_as_user($row)
{
    $rights = admin_rights($row);
    return array(
        'id' => (int)$row['aid'],
        'name' => $row['user'],
        'username' => $row['user'],
        'steamid' => authid_to_steam64($row['authid']),
        'group' => $row['srv_group'] !== '' ? $row['srv_group'] : null,
        'immunity' => $rights['immunity'],
        'permissions' => $rights['permissions'],
        'owner' => $rights['owner'],
        'totp_enabled' => (int)$row['totp_enabled'] === 1
    );
}

function current_admin($reload = false)
{
    static $user = false;
    if ($user !== false && !$reload) {
        return $user;
    }
    $user = null;
    if (!is_installed() || empty($_SESSION['admin_id'])) {
        return null;
    }

    $row = find_admin($_SESSION['admin_id']);
    $valid = $row && $row['password'] !== '' && admin_is_active($row) &&
        (int)$row['session_version'] === (int)$_SESSION['session_version'];
    if ($valid) {
        $candidate = admin_as_user($row);
        if (count($candidate['permissions']) > 0) {
            $user = $candidate;
            return $user;
        }
    }
    sign_out();
    return null;
}

function sign_in($row)
{
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$row['aid'];
    $_SESSION['session_version'] = (int)$row['session_version'];
    current_admin(true);
}

function sign_out()
{
    unset($_SESSION['admin_id'], $_SESSION['session_version'], $_SESSION['totp_setup'], $_SESSION['login_pending']);
}

function ensure_admin_web_row($aid)
{
    db_execute('INSERT IGNORE INTO `vd_admin_web` (`aid`, `permissions`) VALUES (?, ?)', array((int)$aid, ''));
}

function bump_session_version($aid, $keep_current = true)
{
    ensure_admin_web_row($aid);
    db_execute('UPDATE `vd_admin_web` SET `session_version` = `session_version` + 1 WHERE `aid` = ?', array((int)$aid));
    if ($keep_current && isset($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] === (int)$aid) {
        $_SESSION['session_version'] = (int)db_value('SELECT `session_version` FROM `vd_admin_web` WHERE `aid` = ?', array((int)$aid));
        current_admin(true);
    }
}

function blowfish_available()
{
    return defined('CRYPT_BLOWFISH') && CRYPT_BLOWFISH == 1 && version_compare(PHP_VERSION, '5.3.7', '>=');
}

function make_password_hash($password)
{
    if (blowfish_available()) {
        $salt = substr(str_replace('+', '.', base64_encode(random_bytes_string(16))), 0, 22);
        return crypt($password, '$2y$10$' . $salt);
    }
    $salt = random_hex(16);
    $hash = $salt . $password;
    for ($i = 0; $i < 20000; $i++) {
        $hash = hash('sha256', $hash . $password);
    }
    return 'vd1$' . $salt . '$' . $hash;
}

function password_matches($password, $hash)
{
    $hash = (string)$hash;
    if ($hash === '') {
        return false;
    }
    if (strpos($hash, '$2') === 0) {
        return same_text($hash, crypt($password, $hash));
    }
    if (strpos($hash, 'vd1$') === 0) {
        $parts = explode('$', $hash);
        if (count($parts) !== 3) {
            return false;
        }
        $check = $parts[1] . $password;
        for ($i = 0; $i < 20000; $i++) {
            $check = hash('sha256', $check . $password);
        }
        return same_text($parts[2], $check);
    }
    if (preg_match('/^[0-9a-f]{40}$/i', $hash)) {
        return same_text(strtolower($hash), sha1(sha1('SourceBans' . $password)));
    }
    return false;
}

function check_new_password($password)
{
    $password = (string)$password;
    if (strlen($password) < 8) {
        fail('password_too_short');
    }
    if (strlen($password) > 72) {
        fail('password_too_long');
    }
    return $password;
}

function require_login()
{
    if (!current_admin()) {
        redirect_to(page_url('login'));
    }
}

function require_page_permission($permission)
{
    require_login();
    if (!can($permission)) {
        redirect_to(page_url());
    }
}

function require_api_login()
{
    if (!current_admin()) {
        fail('unauthorized', 401);
    }
    return current_admin();
}

function require_api_permission($permissions)
{
    $user = require_api_login();
    $permissions = is_array($permissions) ? $permissions : array($permissions);
    if (!can_any($permissions)) {
        fail('forbidden', 403);
    }
    return $user;
}

function require_owner()
{
    $user = require_api_login();
    if (!$user['owner']) {
        fail('forbidden', 403);
    }
    return $user;
}
