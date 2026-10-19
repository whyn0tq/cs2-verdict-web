<?php

function all_permissions()
{
    return array(
        'bans.add', 'bans.edit', 'bans.delete',
        'comms.add', 'comms.edit', 'comms.delete',
        'players.view', 'players.kick', 'players.control', 'players.chat', 'players.ip',
        'servers.manage', 'servers.rcon',
        'admins.manage', 'groups.manage', 'logs.view'
    );
}

function permission_sections()
{
    return array(
        'bans' => array('bans.add', 'bans.edit', 'bans.delete'),
        'comms' => array('comms.add', 'comms.edit', 'comms.delete'),
        'players' => array('players.view', 'players.kick', 'players.control', 'players.chat', 'players.ip'),
        'servers' => array('servers.manage', 'servers.rcon'),
        'panel' => array('admins.manage', 'groups.manage', 'logs.view')
    );
}

function known_flags()
{
    return array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'z');
}

function protected_flags()
{
    return array('z', 'm');
}

function parse_permissions($value)
{
    $list = is_array($value) ? $value : explode(',', (string)$value);
    $known = array_merge(array('owner'), all_permissions());
    $result = array();
    foreach ($list as $item) {
        $item = trim((string)$item);
        if (in_array($item, $known) && !in_array($item, $result)) {
            $result[] = $item;
        }
    }
    return $result;
}

function clean_flags($value)
{
    $letters = preg_replace('/[^a-z]/', '', strtolower((string)$value));
    $result = '';
    for ($i = 0; $i < strlen($letters); $i++) {
        if (strpos($result, $letters[$i]) === false) {
            $result .= $letters[$i];
        }
    }
    return substr($result, 0, 30);
}

function has_protected_flags($flags)
{
    foreach (protected_flags() as $flag) {
        if (strpos((string)$flags, $flag) !== false) {
            return true;
        }
    }
    return false;
}

function admin_rights($row)
{
    $own = parse_permissions(isset($row['permissions']) ? $row['permissions'] : '');
    $from_group = parse_permissions(isset($row['group_permissions']) ? $row['group_permissions'] : '');
    $permissions = array_values(array_unique(array_merge($own, $from_group)));
    $owner = in_array('owner', $permissions);
    if ($owner) {
        $permissions = array_merge(array('owner'), all_permissions());
    }
    $immunity = max((int)$row['own_immunity'], (int)$row['group_immunity']);
    return array(
        'permissions' => $permissions,
        'owner' => $owner,
        'immunity' => $immunity,
        'flags' => clean_flags($row['srv_flags'] . $row['group_flags'])
    );
}

function can($permission)
{
    $user = current_admin();
    if (!$user) {
        return false;
    }
    return $user['owner'] || in_array($permission, $user['permissions']);
}

function can_any($permissions)
{
    foreach ($permissions as $permission) {
        if (can($permission)) {
            return true;
        }
    }
    return false;
}

function can_manage_admin($user, $row)
{
    if ($user['owner']) {
        return true;
    }
    $target = admin_rights($row);
    if ($target['owner'] || $target['immunity'] >= $user['immunity']) {
        return false;
    }
    foreach ($target['permissions'] as $permission) {
        if (!in_array($permission, $user['permissions'])) {
            return false;
        }
    }
    return true;
}

function check_can_grant($user, $permissions, $flags, $immunity)
{
    if ($user['owner']) {
        return;
    }
    foreach ($permissions as $permission) {
        if ($permission === 'owner' || !in_array($permission, $user['permissions'])) {
            fail('permission_not_owned', 403);
        }
    }
    if (has_protected_flags($flags)) {
        fail('protected_flags', 403);
    }
    if ($immunity >= $user['immunity']) {
        fail('immunity_too_high', 403);
    }
}

function check_targetable($user, $steam64)
{
    if (!$steam64 || $user['owner']) {
        return;
    }
    $row = db_row(admin_select_sql() . ' WHERE a.`authid` REGEXP ? LIMIT 1', array(authid_pattern($steam64)));
    if (!$row) {
        return;
    }
    $target = admin_rights($row);
    if ($target['owner'] || $target['immunity'] >= $user['immunity']) {
        fail('target_immune', 403);
    }
}
