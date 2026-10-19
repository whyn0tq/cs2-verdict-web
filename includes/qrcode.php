<?php

function qr_block_layout($version)
{
    $layouts = array(
        1 => array(10, array(array(1, 16))),
        2 => array(16, array(array(1, 28))),
        3 => array(26, array(array(1, 44))),
        4 => array(18, array(array(2, 32))),
        5 => array(24, array(array(2, 43))),
        6 => array(16, array(array(4, 27))),
        7 => array(18, array(array(4, 31))),
        8 => array(22, array(array(2, 38), array(2, 39))),
        9 => array(22, array(array(3, 36), array(2, 37))),
        10 => array(26, array(array(4, 43), array(1, 44)))
    );
    return $layouts[$version];
}

function qr_alignment_positions($version)
{
    $positions = array(
        1 => array(),
        2 => array(6, 18),
        3 => array(6, 22),
        4 => array(6, 26),
        5 => array(6, 30),
        6 => array(6, 34),
        7 => array(6, 22, 38),
        8 => array(6, 24, 42),
        9 => array(6, 26, 46),
        10 => array(6, 28, 50)
    );
    return $positions[$version];
}

function qr_data_capacity($version)
{
    $layout = qr_block_layout($version);
    $total = 0;
    foreach ($layout[1] as $group) {
        $total += $group[0] * $group[1];
    }
    return $total;
}

function qr_galois_tables()
{
    static $tables = null;
    if ($tables !== null) {
        return $tables;
    }
    $exp = array();
    $log = array_fill(0, 256, 0);
    $value = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $value;
        $log[$value] = $i;
        $value <<= 1;
        if ($value & 256) {
            $value ^= 0x11D;
        }
    }
    for ($i = 255; $i < 512; $i++) {
        $exp[$i] = $exp[$i - 255];
    }
    $tables = array($exp, $log);
    return $tables;
}

function qr_multiply($a, $b)
{
    if ($a == 0 || $b == 0) {
        return 0;
    }
    $tables = qr_galois_tables();
    return $tables[0][$tables[1][$a] + $tables[1][$b]];
}

function qr_generator($degree)
{
    $tables = qr_galois_tables();
    $poly = array(1);
    for ($i = 0; $i < $degree; $i++) {
        $next = array_fill(0, count($poly) + 1, 0);
        for ($j = 0; $j < count($poly); $j++) {
            $next[$j] ^= $poly[$j];
            $next[$j + 1] ^= qr_multiply($poly[$j], $tables[0][$i]);
        }
        $poly = $next;
    }
    return $poly;
}

function qr_error_codewords($data, $degree)
{
    $generator = qr_generator($degree);
    $result = array_fill(0, $degree, 0);
    foreach ($data as $byte) {
        $factor = $byte ^ $result[0];
        array_shift($result);
        $result[] = 0;
        if ($factor == 0) {
            continue;
        }
        for ($i = 0; $i < $degree; $i++) {
            $result[$i] ^= qr_multiply($generator[$i + 1], $factor);
        }
    }
    return $result;
}

function qr_codewords($text, $version)
{
    $capacity = qr_data_capacity($version);
    $bits = '0100' . str_pad(decbin(strlen($text)), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    for ($i = 0; $i < strlen($text); $i++) {
        $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
    }
    $bits .= str_repeat('0', min(4, $capacity * 8 - strlen($bits)));
    if (strlen($bits) % 8 != 0) {
        $bits .= str_repeat('0', 8 - strlen($bits) % 8);
    }

    $data = array();
    for ($i = 0; $i < strlen($bits); $i += 8) {
        $data[] = bindec(substr($bits, $i, 8));
    }
    $pad = array(0xEC, 0x11);
    for ($i = 0; count($data) < $capacity; $i++) {
        $data[] = $pad[$i % 2];
    }

    $layout = qr_block_layout($version);
    $blocks = array();
    $errors = array();
    $offset = 0;
    foreach ($layout[1] as $group) {
        for ($b = 0; $b < $group[0]; $b++) {
            $block = array_slice($data, $offset, $group[1]);
            $offset += $group[1];
            $blocks[] = $block;
            $errors[] = qr_error_codewords($block, $layout[0]);
        }
    }

    $result = array();
    $longest = 0;
    foreach ($blocks as $block) {
        $longest = max($longest, count($block));
    }
    for ($i = 0; $i < $longest; $i++) {
        foreach ($blocks as $block) {
            if ($i < count($block)) {
                $result[] = $block[$i];
            }
        }
    }
    for ($i = 0; $i < $layout[0]; $i++) {
        foreach ($errors as $error) {
            $result[] = $error[$i];
        }
    }
    return $result;
}

class QrMatrix
{
    var $size;
    var $modules = array();
    var $reserved = array();

    function __construct($version)
    {
        $this->size = 17 + 4 * $version;
        for ($y = 0; $y < $this->size; $y++) {
            $this->modules[$y] = array_fill(0, $this->size, false);
            $this->reserved[$y] = array_fill(0, $this->size, false);
        }
    }

    function set($x, $y, $dark)
    {
        if ($x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size) {
            return;
        }
        $this->modules[$y][$x] = $dark;
        $this->reserved[$y][$x] = true;
    }
}

function qr_draw_function_patterns($matrix, $version)
{
    $size = $matrix->size;
    for ($i = 0; $i < $size; $i++) {
        $matrix->set(6, $i, $i % 2 == 0);
        $matrix->set($i, 6, $i % 2 == 0);
    }

    $corners = array(array(3, 3), array($size - 4, 3), array(3, $size - 4));
    foreach ($corners as $corner) {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $distance = max(abs($dx), abs($dy));
                $matrix->set($corner[0] + $dx, $corner[1] + $dy, $distance != 2 && $distance != 4);
            }
        }
    }

    $positions = qr_alignment_positions($version);
    $count = count($positions);
    for ($i = 0; $i < $count; $i++) {
        for ($j = 0; $j < $count; $j++) {
            if (($i == 0 && $j == 0) || ($i == 0 && $j == $count - 1) || ($i == $count - 1 && $j == 0)) {
                continue;
            }
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $matrix->set($positions[$i] + $dx, $positions[$j] + $dy, max(abs($dx), abs($dy)) != 1);
                }
            }
        }
    }

    qr_draw_format_bits($matrix, 0);

    if ($version >= 7) {
        $remainder = $version;
        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
        }
        $bits = ($version << 12) | $remainder;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) == 1;
            $a = $size - 11 + $i % 3;
            $b = (int)floor($i / 3);
            $matrix->set($a, $b, $dark);
            $matrix->set($b, $a, $dark);
        }
    }
}

function qr_draw_format_bits($matrix, $mask)
{
    $data = $mask;
    $remainder = $data;
    for ($i = 0; $i < 10; $i++) {
        $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
    }
    $bits = (($data << 10) | $remainder) ^ 0x5412;
    $size = $matrix->size;

    for ($i = 0; $i <= 5; $i++) {
        $matrix->set(8, $i, (($bits >> $i) & 1) == 1);
    }
    $matrix->set(8, 7, (($bits >> 6) & 1) == 1);
    $matrix->set(8, 8, (($bits >> 7) & 1) == 1);
    $matrix->set(7, 8, (($bits >> 8) & 1) == 1);
    for ($i = 9; $i < 15; $i++) {
        $matrix->set(14 - $i, 8, (($bits >> $i) & 1) == 1);
    }
    for ($i = 0; $i < 8; $i++) {
        $matrix->set($size - 1 - $i, 8, (($bits >> $i) & 1) == 1);
    }
    for ($i = 8; $i < 15; $i++) {
        $matrix->set(8, $size - 15 + $i, (($bits >> $i) & 1) == 1);
    }
    $matrix->set(8, $size - 8, true);
}

function qr_place_data($matrix, $codewords)
{
    $bits = array();
    foreach ($codewords as $byte) {
        for ($i = 7; $i >= 0; $i--) {
            $bits[] = (($byte >> $i) & 1) == 1;
        }
    }
    $size = $matrix->size;
    $index = 0;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right == 6) {
            $right = 5;
        }
        for ($vertical = 0; $vertical < $size; $vertical++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $upward = (($right + 1) & 2) == 0;
                $y = $upward ? $size - 1 - $vertical : $vertical;
                if ($matrix->reserved[$y][$x]) {
                    continue;
                }
                $matrix->modules[$y][$x] = $index < count($bits) ? $bits[$index] : false;
                $index++;
            }
        }
    }
}

function qr_mask_hit($mask, $x, $y)
{
    switch ($mask) {
        case 0: return ($x + $y) % 2 == 0;
        case 1: return $y % 2 == 0;
        case 2: return $x % 3 == 0;
        case 3: return ($x + $y) % 3 == 0;
        case 4: return ((int)floor($x / 3) + (int)floor($y / 2)) % 2 == 0;
        case 5: return ($x * $y) % 2 + ($x * $y) % 3 == 0;
        case 6: return (($x * $y) % 2 + ($x * $y) % 3) % 2 == 0;
    }
    return (($x + $y) % 2 + ($x * $y) % 3) % 2 == 0;
}

function qr_apply_mask($matrix, $mask)
{
    for ($y = 0; $y < $matrix->size; $y++) {
        for ($x = 0; $x < $matrix->size; $x++) {
            if (!$matrix->reserved[$y][$x] && qr_mask_hit($mask, $x, $y)) {
                $matrix->modules[$y][$x] = !$matrix->modules[$y][$x];
            }
        }
    }
}

function qr_penalty($matrix)
{
    $size = $matrix->size;
    $lines = array();
    $dark = 0;
    for ($y = 0; $y < $size; $y++) {
        $row = '';
        $column = '';
        for ($x = 0; $x < $size; $x++) {
            $row .= $matrix->modules[$y][$x] ? '1' : '0';
            $column .= $matrix->modules[$x][$y] ? '1' : '0';
            if ($matrix->modules[$y][$x]) {
                $dark++;
            }
        }
        $lines[] = $row;
        $lines[] = $column;
    }

    $penalty = 0;
    foreach ($lines as $line) {
        if (preg_match_all('/0{5,}|1{5,}/', $line, $runs)) {
            foreach ($runs[0] as $run) {
                $penalty += 3 + strlen($run) - 5;
            }
        }
        $penalty += 40 * (substr_count($line, '10111010000') + substr_count($line, '00001011101'));
    }

    for ($y = 0; $y < $size - 1; $y++) {
        for ($x = 0; $x < $size - 1; $x++) {
            $color = $matrix->modules[$y][$x];
            if ($color == $matrix->modules[$y][$x + 1] && $color == $matrix->modules[$y + 1][$x] && $color == $matrix->modules[$y + 1][$x + 1]) {
                $penalty += 3;
            }
        }
    }

    $total = $size * $size;
    $penalty += 10 * max(0, (int)ceil(abs($dark * 20 - $total * 10) / $total) - 1);
    return $penalty;
}

function qr_build($text)
{
    $version = 0;
    for ($candidate = 1; $candidate <= 10; $candidate++) {
        $needed = 4 + ($candidate < 10 ? 8 : 16) + strlen($text) * 8;
        if ($needed <= qr_data_capacity($candidate) * 8) {
            $version = $candidate;
            break;
        }
    }
    if ($version == 0) {
        return null;
    }

    $codewords = qr_codewords($text, $version);
    $best = null;
    $best_penalty = 0;
    for ($mask = 0; $mask < 8; $mask++) {
        $matrix = new QrMatrix($version);
        qr_draw_function_patterns($matrix, $version);
        qr_place_data($matrix, $codewords);
        qr_apply_mask($matrix, $mask);
        qr_draw_format_bits($matrix, $mask);
        $penalty = qr_penalty($matrix);
        if ($best === null || $penalty < $best_penalty) {
            $best = $matrix;
            $best_penalty = $penalty;
        }
    }
    return $best;
}

function qr_code_svg($text)
{
    $matrix = qr_build($text);
    if ($matrix === null) {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"></svg>';
    }
    $border = 2;
    $full = $matrix->size + $border * 2;
    $path = '';
    for ($y = 0; $y < $matrix->size; $y++) {
        for ($x = 0; $x < $matrix->size; $x++) {
            if ($matrix->modules[$y][$x]) {
                $path .= 'M' . ($x + $border) . ',' . ($y + $border) . 'h1v1h-1z';
            }
        }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $full . ' ' . $full . '" shape-rendering="crispEdges">' .
        '<rect width="100%" height="100%" fill="#ffffff"/><path d="' . $path . '" fill="#000000"/></svg>';
}
