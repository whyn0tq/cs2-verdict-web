<?php

define('BASE32_ALPHABET', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567');
define('TOTP_PERIOD', 30);

function base32_encode_bytes($bytes)
{
    $bits = '';
    for ($i = 0; $i < strlen($bytes); $i++) {
        $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
    }
    $output = '';
    for ($i = 0; $i < strlen($bits); $i += 5) {
        $chunk = str_pad(substr($bits, $i, 5), 5, '0', STR_PAD_RIGHT);
        $output .= substr(BASE32_ALPHABET, bindec($chunk), 1);
    }
    return $output;
}

function base32_decode_text($text)
{
    $text = strtoupper(preg_replace('/[\s=]/', '', $text));
    $bits = '';
    for ($i = 0; $i < strlen($text); $i++) {
        $index = strpos(BASE32_ALPHABET, $text[$i]);
        if ($index === false) {
            continue;
        }
        $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $bytes .= chr(bindec(substr($bits, $i, 8)));
    }
    return $bytes;
}

function new_totp_secret()
{
    return base32_encode_bytes(random_bytes_string(20));
}

function totp_code_at($secret, $step)
{
    $counter = pack('N*', 0, $step);
    $hash = hash_hmac('sha1', $counter, base32_decode_text($secret), true);
    $offset = ord($hash[19]) & 15;
    $value = ((ord($hash[$offset]) & 127) << 24) | (ord($hash[$offset + 1]) << 16) |
        (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function check_totp_code($secret, $code, $last_step = 0)
{
    $code = preg_replace('/\s/', '', (string)$code);
    if (!preg_match('/^\d{6}$/', $code) || !$secret) {
        return null;
    }
    $now = (int)floor(time() / TOTP_PERIOD);
    for ($step = $now - 1; $step <= $now + 1; $step++) {
        if ($step <= (int)$last_step) {
            continue;
        }
        if (same_text(totp_code_at($secret, $step), $code)) {
            return $step;
        }
    }
    return null;
}

function totp_setup_data($secret, $account)
{
    $issuer = 'Verdict';
    $uri = 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret .
        '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=' . TOTP_PERIOD;
    return array(
        'secret' => $secret,
        'uri' => $uri,
        'qr' => 'data:image/svg+xml;base64,' . base64_encode(qr_code_svg($uri))
    );
}
