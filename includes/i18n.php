<?php

function current_language()
{
    static $language = null;
    if ($language !== null) {
        return $language;
    }
    $saved = isset($_COOKIE['lang']) ? $_COOKIE['lang'] : '';
    if ($saved === 'ru' || $saved === 'en') {
        $language = $saved;
        return $language;
    }
    $accept = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE']) : '';
    $language = strpos($accept, 'ru') === 0 ? 'ru' : 'en';
    return $language;
}

function translations($language)
{
    static $loaded = array();
    if (!isset($loaded[$language])) {
        $loaded[$language] = include INCLUDE_DIR . '/lang/' . $language . '.php';
    }
    return $loaded[$language];
}

function find_translation($dictionary, $key)
{
    $node = $dictionary;
    foreach (explode('.', $key) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return null;
        }
        $node = $node[$part];
    }
    return is_string($node) ? $node : null;
}

function t($key, $vars = array())
{
    $text = find_translation(translations(current_language()), $key);
    if ($text === null) {
        $text = find_translation(translations('en'), $key);
    }
    if ($text === null) {
        $text = $key;
    }
    foreach ($vars as $name => $value) {
        $text = str_replace('{' . $name . '}', (string)$value, $text);
    }
    return $text;
}

function error_text($code)
{
    return t('errors.' . $code);
}

function format_duration($minutes)
{
    $total = (int)$minutes;
    if ($total <= 0) {
        return t('duration.permanent');
    }
    $days = (int)floor($total / 1440);
    $hours = (int)floor(($total % 1440) / 60);
    $rest = $total % 60;
    $parts = array();
    if ($days > 0) {
        $parts[] = t('duration.d', array('n' => $days));
    }
    if ($hours > 0) {
        $parts[] = t('duration.h', array('n' => $hours));
    }
    if ($rest > 0) {
        $parts[] = t('duration.m', array('n' => $rest));
    }
    return implode(' ', $parts);
}

function time_tag($timestamp, $format = 'datetime', $class = '')
{
    if (!$timestamp) {
        return '—';
    }
    $fallback = gmdate($format === 'time' ? 'H:i' : 'd.m.Y H:i', (int)$timestamp);
    $class_attribute = $class !== '' ? ' class="' . e($class) . '"' : '';
    return '<time' . $class_attribute . ' data-ts="' . (int)$timestamp . '" data-format="' . e($format) . '" datetime="' .
        gmdate('Y-m-d\TH:i:s\Z', (int)$timestamp) . '">' . $fallback . '</time>';
}
