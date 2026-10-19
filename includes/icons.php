<?php

function icon_paths()
{
    static $paths = null;
    if ($paths === null) {
        $paths = include INCLUDE_DIR . '/icon_paths.php';
    }
    return $paths;
}

function icon($name, $size = 18, $class = '', $stroke = 2)
{
    $paths = icon_paths();
    if (!isset($paths[$name])) {
        return '';
    }
    $classes = trim('lucide lucide-' . $name . ' ' . $class);
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int)$size . '" height="' . (int)$size .
        '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' . $stroke .
        '" stroke-linecap="round" stroke-linejoin="round" class="' . e($classes) . '" aria-hidden="true">' . $paths[$name] . '</svg>';
}

function brand_mark($size = 28)
{
    return '<svg class="brand-mark" width="' . (int)$size . '" height="' . (int)$size .
        '" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="9"></rect><path d="M8 9l8 15 8-15"></path></svg>';
}
