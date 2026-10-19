<?php

function write_log($action, $details = '', $admin = null)
{
    if ($admin === null) {
        $admin = current_admin();
    }
    db_execute(
        'INSERT INTO `vd_panel_log` (`admin_id`, `admin_name`, `action`, `details`, `ip`, `created`) VALUES (?, ?, ?, ?, ?, ?)',
        array(
            $admin ? $admin['id'] : null,
            $admin ? $admin['name'] : 'Guest',
            $action,
            text_value($details, 512),
            client_ip(),
            time()
        )
    );
}

function log_categories()
{
    return array(
        'punishments' => "`action` REGEXP '^(bans|comms)_' OR `action` IN ('kick', 'slay', 'team_move')",
        'admins' => "`action` REGEXP '^(admin|group)_'",
        'servers' => "`action` REGEXP '^(server|plugin)_' OR `action` IN ('rcon', 'install', 'say')",
        'security' => "`action` REGEXP '^(login|logout|password|2fa)'"
    );
}

function list_logs($search, $category, $page)
{
    $where = array();
    $params = array();
    if ($search !== '') {
        $where[] = '(`admin_name` LIKE ? OR `action` LIKE ? OR `details` LIKE ? OR `ip` LIKE ?)';
        $like = '%' . $search . '%';
        $params = array($like, $like, $like, $like);
    }
    $categories = log_categories();
    if (isset($categories[$category])) {
        $where[] = '(' . $categories[$category] . ')';
    }
    $sql_where = count($where) > 0 ? ' WHERE ' . implode(' AND ', $where) : '';

    $total = (int)db_value('SELECT COUNT(*) FROM `vd_panel_log`' . $sql_where, $params);
    $pages = paginate($total, $page, 50);
    $pages['items'] = db_rows(
        'SELECT * FROM `vd_panel_log`' . $sql_where . ' ORDER BY `created` DESC, `id` DESC LIMIT ? OFFSET ?',
        array_merge($params, array($pages['limit'], $pages['offset']))
    );
    return $pages;
}
