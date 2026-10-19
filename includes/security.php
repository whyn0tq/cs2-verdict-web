<?php

if (!defined('OPENSSL_RAW_DATA')) {
    define('OPENSSL_RAW_DATA', 1);
}

function start_session()
{
    if (session_id() !== '') {
        return;
    }
    session_name('verdict_session');
    session_set_cookie_params(0, base_path(), '', is_https(), true);
    session_start();
}

function send_security_headers()
{
    if (headers_sent()) {
        return;
    }
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; " .
        "img-src 'self' data: https://*.steamstatic.com; connect-src 'self'; font-src 'self'; object-src 'none'; " .
        "base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_hex(40);
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function check_csrf_form()
{
    $given = isset($_POST['_token']) ? (string)$_POST['_token'] : '';
    if (!same_text(csrf_token(), $given)) {
        fail('csrf', 403);
    }
}

function check_csrf_header()
{
    $given = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string)$_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    if (!same_text(csrf_token(), $given)) {
        fail('csrf', 403);
    }
}

function attempt_row($name)
{
    return db_row('SELECT `hits`, `started` FROM `vd_attempts` WHERE `name` = ?', array($name));
}

function attempts_exceeded($name, $max, $window)
{
    $row = attempt_row($name);
    if (!$row || (int)$row['started'] < time() - $window) {
        return false;
    }
    return (int)$row['hits'] >= $max;
}

function add_attempt($name, $window)
{
    $row = attempt_row($name);
    $now = time();
    if (!$row || (int)$row['started'] < $now - $window) {
        db_execute('REPLACE INTO `vd_attempts` (`name`, `hits`, `started`) VALUES (?, 1, ?)', array($name, $now));
        return 1;
    }
    db_execute('UPDATE `vd_attempts` SET `hits` = `hits` + 1 WHERE `name` = ?', array($name));
    return (int)$row['hits'] + 1;
}

function clear_attempts($name)
{
    db_execute('DELETE FROM `vd_attempts` WHERE `name` = ?', array($name));
}

function limit_rate($name, $max, $window)
{
    if (attempts_exceeded($name, $max, $window)) {
        fail('too_many_requests', 429);
    }
    add_attempt($name, $window);
}

function sweep_attempts()
{
    if (mt_rand(1, 50) === 1) {
        db_execute('DELETE FROM `vd_attempts` WHERE `started` < ?', array(time() - 86400));
    }
}

function protect_secret($secret)
{
    $key = hash('sha256', 'verdict-totp:' . config_value('secret'), true);
    if (!function_exists('openssl_encrypt')) {
        return 'plain:' . $secret;
    }
    $iv = random_bytes_string(16);
    $data = openssl_encrypt($secret, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    $mac = hash_hmac('sha256', $iv . $data, $key, true);
    return 'v1:' . base64_encode($iv . $mac . $data);
}

function reveal_secret($stored)
{
    $stored = (string)$stored;
    if (strpos($stored, 'plain:') === 0) {
        return substr($stored, 6);
    }
    if (strpos($stored, 'v1:') !== 0 || !function_exists('openssl_decrypt')) {
        return null;
    }
    $raw = base64_decode(substr($stored, 3));
    if (strlen($raw) < 49) {
        return null;
    }
    $key = hash('sha256', 'verdict-totp:' . config_value('secret'), true);
    $iv = substr($raw, 0, 16);
    $mac = substr($raw, 16, 32);
    $data = substr($raw, 48);
    if (!same_text(hash_hmac('sha256', $iv . $data, $key, true), $mac)) {
        return null;
    }
    $secret = openssl_decrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $secret === false ? null : $secret;
}
