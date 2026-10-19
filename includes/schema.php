<?php

function material_admin_tables()
{
    return array(
        "CREATE TABLE IF NOT EXISTS `{p}admins` (
            `aid` int(6) NOT NULL AUTO_INCREMENT,
            `user` varchar(64) NOT NULL,
            `authid` varchar(64) NOT NULL DEFAULT '',
            `password` varchar(128) NOT NULL DEFAULT '',
            `gid` int(6) NOT NULL DEFAULT -1,
            `email` varchar(128) NOT NULL DEFAULT '',
            `validate` varchar(128) NULL DEFAULT NULL,
            `extraflags` int(10) NOT NULL DEFAULT 0,
            `immunity` int(10) NOT NULL DEFAULT 0,
            `srv_group` varchar(128) DEFAULT NULL,
            `srv_flags` varchar(64) DEFAULT NULL,
            `srv_password` varchar(128) DEFAULT NULL,
            `lastvisit` int(11) NULL,
            `expired` int(11) NULL DEFAULT 0,
            `skype` varchar(128) NULL,
            `comment` varchar(128) NULL,
            `vk` varchar(128) NULL,
            `support` int(6) NULL DEFAULT 0,
            PRIMARY KEY (`aid`),
            UNIQUE KEY `user` (`user`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{p}admins_servers_groups` (
            `admin_id` int(10) NOT NULL,
            `group_id` int(10) NOT NULL,
            `srv_group_id` int(10) NOT NULL,
            `server_id` int(10) NOT NULL,
            KEY `admin_id` (`admin_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{p}banlog` (
            `sid` int(6) NOT NULL,
            `time` int(11) NOT NULL,
            `name` varchar(128) NOT NULL,
            `bid` int(6) NOT NULL,
            PRIMARY KEY (`sid`, `time`, `bid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{p}bans` (
            `bid` int(6) NOT NULL AUTO_INCREMENT,
            `ip` varchar(32) DEFAULT NULL,
            `authid` varchar(64) NOT NULL DEFAULT '',
            `name` varchar(128) NOT NULL DEFAULT 'unnamed',
            `created` int(11) NOT NULL DEFAULT 0,
            `ends` int(11) NOT NULL DEFAULT 0,
            `length` int(10) NOT NULL DEFAULT 0,
            `reason` text NOT NULL,
            `aid` int(6) NOT NULL DEFAULT 0,
            `adminIp` varchar(32) NOT NULL DEFAULT '',
            `sid` int(6) NOT NULL DEFAULT 0,
            `country` varchar(4) DEFAULT NULL,
            `RemovedBy` int(8) NULL,
            `RemoveType` varchar(3) NULL,
            `RemovedOn` int(10) NULL,
            `type` tinyint NOT NULL DEFAULT 0,
            `ureason` text,
            PRIMARY KEY (`bid`),
            KEY `sid` (`sid`),
            KEY `created` (`created`),
            KEY `steam_lookup` (`authid`, `type`),
            KEY `ip_lookup` (`ip`, `type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{p}comms` (
            `bid` int(6) NOT NULL AUTO_INCREMENT,
            `authid` varchar(64) NOT NULL,
            `name` varchar(128) NOT NULL DEFAULT 'unnamed',
            `created` int(11) NOT NULL DEFAULT 0,
            `ends` int(11) NOT NULL DEFAULT 0,
            `length` int(10) NOT NULL DEFAULT 0,
            `reason` text NOT NULL,
            `aid` int(6) NOT NULL DEFAULT 0,
            `adminIp` varchar(32) NOT NULL DEFAULT '',
            `sid` int(6) NOT NULL DEFAULT 0,
            `RemovedBy` int(8) DEFAULT NULL,
            `RemoveType` varchar(3) DEFAULT NULL,
            `RemovedOn` int(11) DEFAULT NULL,
            `type` tinyint(4) NOT NULL DEFAULT 0,
            `ureason` text,
            PRIMARY KEY (`bid`),
            KEY `sid` (`sid`),
            KEY `type` (`type`),
            KEY `RemoveType` (`RemoveType`),
            KEY `authid` (`authid`),
            KEY `created` (`created`),
            KEY `aid` (`aid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{p}servers` (
            `sid` int(6) NOT NULL AUTO_INCREMENT,
            `priority` int(11) NOT NULL DEFAULT 0,
            `ip` varchar(64) NOT NULL,
            `port` int(5) NOT NULL,
            `rcon` varchar(256) NOT NULL DEFAULT '',
            `modid` int(10) NOT NULL DEFAULT 0,
            `enabled` tinyint NOT NULL DEFAULT 1,
            PRIMARY KEY (`sid`),
            UNIQUE KEY `ip` (`ip`, `port`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{p}servers_groups` (
            `server_id` int(10) NOT NULL,
            `group_id` int(10) NOT NULL,
            PRIMARY KEY (`server_id`, `group_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{p}srvgroups` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `flags` varchar(30) NOT NULL,
            `immunity` int(10) unsigned NOT NULL DEFAULT 0,
            `name` varchar(120) NOT NULL,
            `groups_immune` varchar(255) NOT NULL DEFAULT '',
            `maxbantime` int NOT NULL DEFAULT -1,
            `maxmutetime` int NOT NULL DEFAULT -1,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{p}settings` (
            `setting` varchar(128) NOT NULL,
            `value` text NOT NULL,
            UNIQUE KEY `setting` (`setting`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function verdict_tables()
{
    return array(
        "CREATE TABLE IF NOT EXISTS `vd_admin_web` (
            `aid` int NOT NULL,
            `permissions` text NULL,
            `session_version` int NOT NULL DEFAULT 0,
            `totp_secret` varchar(255) NULL,
            `totp_enabled` tinyint NOT NULL DEFAULT 0,
            `totp_last_step` bigint NOT NULL DEFAULT 0,
            PRIMARY KEY (`aid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `vd_group_web` (
            `group_id` int NOT NULL,
            `permissions` text NULL,
            PRIMARY KEY (`group_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `vd_server_info` (
            `sid` int NOT NULL,
            `hostname` varchar(128) NULL,
            `map` varchar(64) NULL,
            `players` smallint NOT NULL DEFAULT 0,
            `max_players` smallint NOT NULL DEFAULT 0,
            `bots` smallint NOT NULL DEFAULT 0,
            `online` tinyint NOT NULL DEFAULT 0,
            `checked` int NOT NULL DEFAULT 0,
            `last_seen` int NOT NULL DEFAULT 0,
            PRIMARY KEY (`sid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `vd_live_servers` (
            `sid` int NOT NULL,
            `version` varchar(32) NULL,
            `score_t` int NOT NULL DEFAULT 0,
            `score_ct` int NOT NULL DEFAULT 0,
            `updated` int NOT NULL DEFAULT 0,
            PRIMARY KEY (`sid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `vd_live_players` (
            `sid` int NOT NULL,
            `slot` int NOT NULL,
            `steamid` varchar(20) NULL,
            `name` varchar(128) NOT NULL DEFAULT '',
            `bot` tinyint NOT NULL DEFAULT 0,
            `team` tinyint NOT NULL DEFAULT 0,
            `alive` tinyint NOT NULL DEFAULT 0,
            `health` int NOT NULL DEFAULT 0,
            `kills` int NOT NULL DEFAULT 0,
            `deaths` int NOT NULL DEFAULT 0,
            `assists` int NOT NULL DEFAULT 0,
            `score` int NOT NULL DEFAULT 0,
            `ping` int NOT NULL DEFAULT 0,
            `ip` varchar(45) NULL,
            `connected` int NOT NULL DEFAULT 0,
            PRIMARY KEY (`sid`, `slot`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `vd_feed` (
            `id` bigint NOT NULL AUTO_INCREMENT,
            `sid` int NOT NULL,
            `kind` varchar(16) NOT NULL,
            `steamid` varchar(20) NULL,
            `name` varchar(128) NULL,
            `text` varchar(512) NULL,
            `meta` varchar(255) NULL,
            `created` int NOT NULL,
            PRIMARY KEY (`id`),
            KEY `server_feed` (`sid`, `id`),
            KEY `created` (`created`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `vd_queue` (
            `id` int NOT NULL AUTO_INCREMENT,
            `sid` int NOT NULL,
            `command` varchar(32) NOT NULL,
            `args` text NOT NULL,
            `status` tinyint NOT NULL DEFAULT 0,
            `result` varchar(255) NULL,
            `created` int NOT NULL,
            PRIMARY KEY (`id`),
            KEY `pending` (`sid`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `vd_panel_log` (
            `id` int NOT NULL AUTO_INCREMENT,
            `admin_id` int NULL,
            `admin_name` varchar(64) NOT NULL,
            `action` varchar(32) NOT NULL,
            `details` varchar(512) NOT NULL DEFAULT '',
            `ip` varchar(45) NULL,
            `created` int NOT NULL,
            PRIMARY KEY (`id`),
            KEY `created` (`created`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `vd_attempts` (
            `name` varchar(120) NOT NULL,
            `hits` int NOT NULL DEFAULT 0,
            `started` int NOT NULL DEFAULT 0,
            PRIMARY KEY (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `vd_steam_cache` (
            `steamid` varchar(20) NOT NULL,
            `name` varchar(64) NULL,
            `avatar` varchar(255) NULL,
            `checked` int NOT NULL DEFAULT 0,
            PRIMARY KEY (`steamid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function column_patches()
{
    return array(
        array('admins', 'expired', 'int(11) NULL DEFAULT 0'),
        array('admins', 'comment', 'varchar(128) NULL'),
        array('srvgroups', 'maxbantime', 'int NOT NULL DEFAULT -1'),
        array('srvgroups', 'maxmutetime', 'int NOT NULL DEFAULT -1')
    );
}

function table_exists($name)
{
    $count = db_value(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        array($name)
    );
    return (int)$count > 0;
}

function column_exists($table, $column)
{
    $count = db_value(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        array($table, $column)
    );
    return (int)$count > 0;
}

function create_tables()
{
    foreach (material_admin_tables() as $statement) {
        db()->exec(str_replace('{p}', table_prefix(), $statement));
    }
    foreach (verdict_tables() as $statement) {
        db()->exec($statement);
    }
    foreach (column_patches() as $patch) {
        if (!column_exists(table_prefix() . $patch[0], $patch[1])) {
            db()->exec('ALTER TABLE ' . table($patch[0]) . ' ADD `' . $patch[1] . '` ' . $patch[2]);
        }
    }
}

function upgrade_schema_if_needed()
{
    if (config_value('schema') === VERDICT_VERSION) {
        return;
    }
    create_tables();
    $config = $GLOBALS['config'];
    $config['schema'] = VERDICT_VERSION;
    save_config($config);
}

function material_web_permissions($bits)
{
    $bits = (int)$bits;
    if ($bits & (1 << 24)) {
        return array('owner');
    }

    $permissions = array();
    if ($bits & (1 << 8)) {
        $permissions = array_merge($permissions, array('bans.add', 'comms.add', 'players.view', 'players.kick'));
    }
    if ($bits & ((1 << 11) | (1 << 12) | (1 << 26))) {
        $permissions = array_merge($permissions, array('bans.edit', 'comms.edit'));
    }
    if ($bits & (1 << 25)) {
        $permissions = array_merge($permissions, array('bans.delete', 'comms.delete'));
    }
    if ($bits & ((1 << 1) | (1 << 2) | (1 << 3))) {
        $permissions[] = 'admins.manage';
    }
    if ($bits & ((1 << 5) | (1 << 6) | (1 << 7))) {
        $permissions[] = 'servers.manage';
    }
    if ($bits & ((1 << 16) | (1 << 17) | (1 << 18))) {
        $permissions[] = 'groups.manage';
    }
    if ($bits & (1 << 19)) {
        $permissions[] = 'logs.view';
    }
    return array_values(array_unique($permissions));
}

function import_material_admin_rights()
{
    $has_web_groups = table_exists(table_prefix() . 'groups') && column_exists(table_prefix() . 'groups', 'flags');
    $sql = 'SELECT a.`aid`, a.`extraflags`' . ($has_web_groups ? ', g.`flags` AS group_bits' : ', 0 AS group_bits') .
        ' FROM ' . table('admins') . ' a' .
        ($has_web_groups ? ' LEFT JOIN ' . table('groups') . ' g ON g.`gid` = a.`gid`' : '') .
        ' WHERE a.`aid` NOT IN (SELECT `aid` FROM `vd_admin_web`)';

    foreach (db_rows($sql) as $row) {
        $permissions = material_web_permissions((int)$row['extraflags'] | (int)$row['group_bits']);
        if (count($permissions) === 0) {
            continue;
        }
        db_execute(
            'INSERT INTO `vd_admin_web` (`aid`, `permissions`) VALUES (?, ?)',
            array((int)$row['aid'], implode(',', $permissions))
        );
    }
}
