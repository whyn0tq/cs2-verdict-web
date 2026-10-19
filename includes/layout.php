<?php

define('PANEL_AUTHOR', 'teesa (whyn0tq)');
define('PANEL_GITHUB', 'https://github.com/whyn0tq');

function render_head($title)
{
    $favicon = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23e5383b'/%3E%3Cpath d='M8 9l8 15 8-15' fill='none' stroke='white' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E";
    echo '<!doctype html>' . "\n";
    echo '<html lang="' . e(current_language()) . '" data-theme="dark">' . "\n";
    echo '<head>' . "\n";
    echo '<meta charset="UTF-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
    echo '<link rel="icon" href="' . $favicon . '">' . "\n";
    echo '<title>' . e($title !== '' ? $title . ' · Verdict' : 'Verdict') . '</title>' . "\n";
    echo '<script src="' . e(asset_url('theme.js')) . '"></script>' . "\n";
    echo '<link rel="stylesheet" href="' . e(asset_url('style.css')) . '">' . "\n";
    echo '</head>' . "\n";
    echo '<body>' . "\n";
}

function render_scripts($data = array())
{
    $user = is_installed() ? current_admin() : null;
    $base = array(
        'lang' => current_language(),
        'strings' => translations(current_language()),
        'fallback' => current_language() === 'en' ? null : translations('en'),
        'icons' => icon_paths(),
        'csrf' => csrf_token(),
        'api' => base_path() . 'api.php',
        'base' => base_path(),
        'user' => $user,
        'toasts' => take_toasts()
    );
    $data = array_merge($base, $data);
    $json = json_encode($data);
    $json = str_replace(array('<', '>'), array('<', '>'), $json);
    echo '<div class="toasts" role="status" aria-live="polite"></div>' . "\n";
    echo '<script type="application/json" id="verdict-data">' . $json . '</script>' . "\n";
    foreach (array('core.js', 'fields.js', 'modals.js', 'live.js', 'app.js') as $script) {
        echo '<script src="' . e(asset_url('js/' . $script)) . '"></script>' . "\n";
    }
    echo '</body>' . "\n" . '</html>' . "\n";
}

function render_prefs($class = '')
{
    $language = current_language();
    $theme_label = t('theme.to_light');
    $html = '<div class="prefs ' . e($class) . '"><div class="lang-switch" role="group">';
    foreach (array('ru', 'en') as $code) {
        $html .= '<button type="button" data-lang="' . $code . '" class="' . ($language === $code ? 'active' : '') . '">' . strtoupper($code) . '</button>';
    }
    $html .= '</div>';
    $html .= '<button type="button" class="theme-toggle is-dark" data-theme-toggle aria-label="' . e($theme_label) . '" title="' . e($theme_label) . '">' .
        icon('sun', 16, 'theme-icon theme-sun') . icon('moon', 16, 'theme-icon theme-moon') . '</button>';
    $html .= '</div>';
    return $html;
}

function render_footer()
{
    return '<footer class="footer"><span class="footer-author"><b>Verdict</b> by ' . e(PANEL_AUTHOR) . ' · © ' . date('Y') . '</span>' .
        '<a class="footer-link" href="' . e(PANEL_GITHUB) . '" target="_blank" rel="noreferrer noopener">' . icon('github', 16) . 'GitHub</a></footer>';
}

function navigation_sections()
{
    $sections = array(
        array('title' => null, 'links' => array(
            array('home', 'nav.home', 'home'),
            array('bans', 'nav.bans', 'ban'),
            array('comms', 'nav.comms', 'mic-off'),
            array('servers', 'nav.servers', 'server')
        ))
    );
    $manage = array();
    if (can('admins.manage')) {
        $manage[] = array('admins', 'nav.admins', 'shield-check');
    }
    if (can('groups.manage')) {
        $manage[] = array('groups', 'nav.groups', 'layers');
    }
    if (can('logs.view')) {
        $manage[] = array('logs', 'nav.logs', 'scroll-text');
    }
    if (count($manage) > 0) {
        $sections[] = array('title' => 'nav.manage', 'links' => $manage);
    }
    return $sections;
}

function render_page_start($title, $active)
{
    render_head($title);
    $user = current_admin();
    echo '<div id="root"><div class="shell">';

    echo '<header class="mobilebar"><a href="' . e(page_url()) . '" class="brand">' . brand_mark() . '<span>Verdict</span></a>' .
        '<button type="button" class="icon-btn" data-sidebar-open aria-label="' . e(t('nav.menu')) . '">' . icon('menu', 22) . '</button></header>';
    echo '<div class="sidebar-backdrop" data-sidebar-close></div>';

    echo '<aside class="sidebar glass">';
    echo '<div class="sidebar-top"><a href="' . e(page_url()) . '" class="brand">' . brand_mark(32) . '<span>Verdict</span></a>' .
        '<button type="button" class="icon-btn sidebar-close" data-sidebar-close aria-label="' . e(t('common.close')) . '">' . icon('x', 20) . '</button></div>';

    echo '<nav class="sidebar-nav"><span class="nav-indicator" aria-hidden="true"></span>';
    foreach (navigation_sections() as $section) {
        echo '<div class="nav-section">';
        if ($section['title']) {
            echo '<div class="nav-section-title">' . e(t($section['title'])) . '</div>';
        }
        foreach ($section['links'] as $link) {
            $is_active = $active === $link[0];
            echo '<a href="' . e(page_url($link[0])) . '" class="side-link' . ($is_active ? ' active' : '') . '"' . ($is_active ? ' aria-current="page"' : '') . '>' .
                icon($link[2], 18) . '<span>' . e(t($link[1])) . '</span></a>';
        }
        echo '</div>';
    }
    echo '</nav>';

    echo '<div class="sidebar-bottom">' . render_prefs();
    if ($user) {
        $role = $user['owner'] ? t('admins.owner') : ($user['group'] !== null ? $user['group'] : t('nav.account'));
        echo '<div class="user-card"><a href="' . e(page_url('account')) . '" class="user-link' . ($active === 'account' ? ' active' : '') . '">' .
            '<span class="avatar">' . e(first_letter($user['name'])) . '</span>' .
            '<span class="user-meta"><span class="user-name">' . e($user['name']) . '</span><span class="user-role">' . e($role) . '</span></span></a>' .
            '<form method="post" action="' . e(page_url('logout')) . '" class="inline-form">' . csrf_field() .
            '<button type="submit" class="icon-btn" aria-label="' . e(t('nav.logout')) . '" title="' . e(t('nav.logout')) . '">' . icon('log-out', 18) . '</button></form></div>';
    } else {
        echo '<a href="' . e(page_url('login')) . '" class="btn btn-primary btn-block">' . icon('log-in', 17) . e(t('nav.login')) . '</a>';
    }
    echo '</div></aside>';

    echo '<main class="main">';
    if ($user && !is_https() && !is_local_host()) {
        echo '<div class="https-warning">' . e(t('security.no_https')) . '</div>';
    }
    echo '<div class="container">';
}

function render_page_end($data = array())
{
    echo '</div><div class="container footer-wrap">' . render_footer() . '</div></main></div></div>';
    render_scripts($data);
}

function page_header($icon_name, $title, $subtitle = null, $actions = '')
{
    $html = '<div class="page-header"><div class="page-header-main">';
    if ($icon_name) {
        $html .= '<span class="page-icon">' . icon($icon_name, 22) . '</span>';
    }
    $html .= '<div><h1>' . e($title) . '</h1>';
    if ($subtitle !== null && $subtitle !== '') {
        $html .= '<p class="page-sub">' . $subtitle . '</p>';
    }
    $html .= '</div></div>';
    if ($actions !== '') {
        $html .= '<div class="page-header-actions">' . $actions . '</div>';
    }
    return $html . '</div>';
}

function empty_block($icon_name, $title, $text = null, $extra = '')
{
    $html = '<div class="empty"><span class="empty-icon">' . icon($icon_name ? $icon_name : 'inbox', 26) . '</span>';
    if ($title) {
        $html .= '<div class="empty-title">' . e($title) . '</div>';
    }
    if ($text) {
        $html .= '<div class="empty-text">' . e($text) . '</div>';
    }
    return $html . $extra . '</div>';
}

function status_badge($status)
{
    return '<span class="badge badge-' . e($status) . '">' . e(t('status.' . $status)) . '</span>';
}

function type_badge($type)
{
    return '<span class="badge badge-type-' . e($type) . '">' . e(t('type.' . $type)) . '</span>';
}

function player_block($name, $steamid, $steam2, $fallback = '', $with_avatar = false, $by_ip = false, $extra = '')
{
    $label = $name !== null && $name !== '' ? $name : ($fallback !== '' ? $fallback : '—');
    $info = '<div class="player"><span class="player-name"' . ($steamid ? ' data-steam-name="' . e($steamid) . '"' : '') . '>' . e($label) . '</span>';
    if ($steamid) {
        $info .= '<a class="player-id" href="' . e(profile_url($steamid)) . '" target="_blank" rel="noreferrer noopener" data-stop>' .
            e($steam2 ? $steam2 : $steamid) . '</a>';
    }
    $info .= $extra . '</div>';
    if (!$with_avatar) {
        return $info;
    }
    $avatar = '<span class="row-avatar placeholder"' . ($steamid ? ' data-avatar-for="' . e($steamid) . '"' : '') . '>' .
        icon($by_ip ? 'globe' : 'user-round', 16) . '</span>';
    return '<div class="player-with-avatar">' . $avatar . $info . '</div>';
}

function pagination_links($pages, $page_name, $params)
{
    if ($pages['pages'] <= 1) {
        return '';
    }
    $html = '<div class="pagination">';
    $prev = array_merge($params, array('p' => $pages['page'] - 1));
    $next = array_merge($params, array('p' => $pages['page'] + 1));
    if ($pages['page'] > 1) {
        $html .= '<a class="btn btn-ghost btn-sm" href="' . e(page_url($page_name, $prev)) . '">' . icon('chevron-left', 16) . e(t('common.prev')) . '</a>';
    } else {
        $html .= '<button type="button" class="btn btn-ghost btn-sm" disabled>' . icon('chevron-left', 16) . e(t('common.prev')) . '</button>';
    }
    $html .= '<span class="muted small">' . e(t('common.page', array('page' => $pages['page'], 'pages' => $pages['pages']))) . '</span>';
    if ($pages['page'] < $pages['pages']) {
        $html .= '<a class="btn btn-ghost btn-sm" href="' . e(page_url($page_name, $next)) . '">' . e(t('common.next')) . icon('chevron-right', 16) . '</a>';
    } else {
        $html .= '<button type="button" class="btn btn-ghost btn-sm" disabled>' . e(t('common.next')) . icon('chevron-right', 16) . '</button>';
    }
    return $html . '</div>';
}

function map_backdrop($map)
{
    $name = clean_map_name($map);
    if ($name === null) {
        return '';
    }
    return '<img class="map-backdrop" src="' . e(api_url('map', array('name' => $name))) . '" alt="" aria-hidden="true" loading="lazy" data-map-image>';
}

function server_card($server, $compact = false)
{
    $fill = $server['max_players'] > 0 ? min(100, $server['players'] / $server['max_players'] * 100) : 0;
    $online = $server['online'];
    $html = '<a href="' . e(page_url('server', array('id' => $server['id']))) . '" class="card server-card' . ($compact ? ' compact' : '') . '" data-server-card="' . $server['id'] . '">';
    if ($online) {
        $html .= map_backdrop($server['map']);
    }
    $html .= '<div class="server-top"><span class="server-icon">' . icon('server', 18) . '</span>' .
        '<div class="server-title"><span class="server-name">' . e($server['name']) . '</span>' .
        '<span class="muted small ellipsis">' . e($server['ip'] . ':' . $server['port']) . '</span></div>' .
        '<span class="status-pill ' . ($online ? 'on' : 'off') . '"><span class="dot"></span>' . e(t($online ? 'servers.online' : 'servers.offline')) . '</span></div>';
    $html .= '<div class="server-meta"><span>' . icon('map', 14) . e($online && map_label($server['map']) !== '' ? map_label($server['map']) : '—') . '</span>' .
        '<span>' . icon('users', 14) . e($online ? $server['players'] . '/' . $server['max_players'] : '—') . '</span></div>';
    $html .= '<div class="bar"><div class="bar-fill" style="width: ' . ($online ? round($fill, 1) : 0) . '%"></div></div>';
    return $html . '</a>';
}

function error_line($code, $detail = null)
{
    if (!$code) {
        return '';
    }
    $html = '<div class="form-error">' . e(error_text($code));
    if ($detail) {
        $html .= '<div class="form-error-detail">' . e($detail) . '</div>';
    }
    return $html . '</div>';
}

function code_block_html($path, $text, $file_name)
{
    return '<div><div class="code-head"><span class="field-label">' . e($path) . '</span><div class="actions">' .
        '<button type="button" class="btn btn-ghost btn-sm" data-copy data-copied-text="' . e(t('install.copied')) . '">' . icon('copy', 15) . e(t('install.copy')) . '</button>' .
        '<button type="button" class="btn btn-ghost btn-sm" data-download="' . e($file_name) . '">' . icon('download', 15) . e(t('install.download')) . '</button>' .
        '</div></div><pre class="code-block">' . e($text) . '</pre></div>';
}

function plugin_config_html($plugin, $host)
{
    $html = code_block_html('addons/configs/databases.cfg', $plugin['databases'], 'databases.cfg');
    $html .= code_block_html('addons/configs/verdict/core.ini', $plugin['core'], 'core.ini');
    $html .= '<div class="notice">' . e(t('security.plugin_db_note')) . '</div>';
    if (in_array($host, array('127.0.0.1', 'localhost'))) {
        $html .= '<div class="notice">' . e(t('install.host_note')) . '</div>';
    }
    return $html;
}

function field_block($label, $control, $hint = null, $as_label = true)
{
    $tag = $as_label ? 'label' : 'div';
    $html = '<' . $tag . ' class="field"><span class="field-label">' . e($label) . '</span>' . $control;
    if ($hint) {
        $html .= '<span class="field-hint">' . e($hint) . '</span>';
    }
    return $html . '</' . $tag . '>';
}
