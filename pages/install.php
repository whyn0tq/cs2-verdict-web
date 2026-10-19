<?php

$steps = array('code', 'database', 'account', 'server', 'done');

function install_state()
{
    if (!isset($_SESSION['install']) || !is_array($_SESSION['install'])) {
        $_SESSION['install'] = array('step' => 0, 'reached' => 0, 'db' => null, 'db_info' => null, 'owner' => null, 'result' => null);
    }
    return $_SESSION['install'];
}

function save_install_state($state)
{
    $state['reached'] = max($state['reached'], $state['step']);
    $_SESSION['install'] = $state;
}

function install_code()
{
    if (is_file(INSTALL_CODE_FILE)) {
        $lines = explode("\n", trim(file_get_contents(INSTALL_CODE_FILE)));
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', end($lines)));
        if (strlen($code) >= 8) {
            return $code;
        }
    }
    if (!data_dir_writable()) {
        return null;
    }
    $code = strtoupper(random_hex(10));
    $text = "<?php exit; ?>\n" . substr($code, 0, 5) . '-' . substr($code, 5) . "\n";
    @file_put_contents(INSTALL_CODE_FILE, $text);
    return $code;
}

function check_install_code($given)
{
    $key = 'install-code';
    if (!isset($_SESSION['install_failures'])) {
        $_SESSION['install_failures'] = 0;
    }
    if ($_SESSION['install_failures'] >= 10) {
        fail('too_many_attempts', 429);
    }
    $expected = install_code();
    $given = strtoupper(preg_replace('/[\s-]/', '', (string)$given));
    if ($expected === null || !same_text($expected, $given)) {
        $_SESSION['install_failures']++;
        fail('invalid_install_code', 403);
    }
    unset($_SESSION['install_failures']);
}

function install_db_input()
{
    $db = array(
        'host' => text_value(post_param('host'), 255),
        'port' => int_value(post_param('port'), 3306),
        'user' => text_value(post_param('user'), 64),
        'password' => (string)post_param('password'),
        'database' => text_value(post_param('database'), 64),
        'prefix' => text_value(post_param('prefix'), 16)
    );
    if ($db['host'] === '') {
        $db['host'] = '127.0.0.1';
    }
    if ($db['database'] === '') {
        $db['database'] = 'verdict';
    }
    if ($db['user'] === '') {
        fail('db_user_required');
    }
    if ($db['port'] < 1 || $db['port'] > 65535) {
        fail('invalid_port');
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $db['database'])) {
        fail('invalid_db_name');
    }
    if (!preg_match('/^[A-Za-z0-9_]*$/', $db['prefix'])) {
        fail('invalid_prefix');
    }
    return $db;
}

function install_db_error($error)
{
    if ($error instanceof PanelError) {
        return $error;
    }
    $code = (int)$error->getCode();
    if (isset($error->errorInfo[1])) {
        $code = (int)$error->errorInfo[1];
    }
    if (in_array($code, array(1044, 1045, 1142))) {
        return new PanelError('db_access_denied', 400, $error->getMessage());
    }
    if (in_array($code, array(2002, 2003, 2005, 2006, 1130))) {
        return new PanelError('db_unreachable', 400, $error->getMessage());
    }
    return new PanelError('db_error', 400, $error->getMessage());
}

function inspect_database($db)
{
    $pdo = open_database($db, false);
    $version = $pdo->query('SELECT VERSION()')->fetchColumn();
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $statement->execute(array($db['database']));
    $exists = (int)$statement->fetchColumn() > 0;

    $prefix = $db['prefix'] !== '' && substr($db['prefix'], -1) !== '_' ? $db['prefix'] . '_' : $db['prefix'];
    $statement = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
    $statement->execute(array($db['database']));
    $ours = array('admins', 'admins_servers_groups', 'banlog', 'bans', 'comms', 'servers', 'servers_groups', 'srvgroups', 'settings', 'groups');
    $existing = false;
    $others = 0;
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $name = strtolower($name);
        $mine = strpos($name, 'vd_') === 0;
        foreach ($ours as $table) {
            if ($name === strtolower($prefix . $table)) {
                $mine = true;
                if ($table === 'bans' || $table === 'admins') {
                    $existing = true;
                }
            }
        }
        if (!$mine) {
            $others++;
        }
    }
    return array('version' => $version, 'exists' => $exists, 'existing' => $existing, 'others' => $others);
}

function install_owner_input()
{
    $owner = array(
        'username' => text_value(post_param('username'), 32),
        'password' => (string)post_param('password'),
        'confirm' => (string)post_param('confirm'),
        'steamid' => text_value(post_param('steamid'), 128)
    );
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $owner['username'])) {
        fail('invalid_username');
    }
    check_new_password($owner['password']);
    if ($owner['password'] !== $owner['confirm']) {
        fail('password_mismatch');
    }
    if ($owner['steamid'] !== '' && !steam_to_64($owner['steamid'])) {
        fail('invalid_steamid');
    }
    return $owner;
}

function install_save_owner($owner)
{
    $steam64 = $owner['steamid'] !== '' ? steam_to_64($owner['steamid']) : null;
    $hash = make_password_hash($owner['password']);
    $existing = db_row('SELECT `aid`, `srv_flags` FROM ' . table('admins') . ' WHERE `user` = ?', array($owner['username']));
    if ($existing) {
        $aid = (int)$existing['aid'];
        $flags = clean_flags($existing['srv_flags'] . 'z');
        db_execute(
            'UPDATE ' . table('admins') . ' SET `password` = ?, `srv_flags` = ?, `immunity` = 100, `expired` = 0' . ($steam64 ? ', `authid` = ?' : '') . ' WHERE `aid` = ?',
            $steam64 ? array($hash, $flags, steam64_to_steam2($steam64), $aid) : array($hash, $flags, $aid)
        );
    } else {
        db_execute(
            'INSERT INTO ' . table('admins') . " (`user`, `authid`, `password`, `gid`, `email`, `extraflags`, `immunity`, `srv_group`, `srv_flags`, `expired`, `comment`)
             VALUES (?, ?, ?, -1, '', 0, 100, NULL, 'z', 0, '')",
            array($owner['username'], $steam64 ? steam64_to_steam2($steam64) : '', $hash)
        );
        $aid = db_insert_id();
        db_execute('INSERT INTO ' . table('admins_servers_groups') . ' (`admin_id`, `group_id`, `srv_group_id`, `server_id`) VALUES (?, 0, -1, -1)', array($aid));
    }
    db_execute(
        "INSERT INTO `vd_admin_web` (`aid`, `permissions`, `session_version`) VALUES (?, 'owner', 0)
         ON DUPLICATE KEY UPDATE `permissions` = 'owner', `totp_enabled` = 0, `totp_secret` = NULL, `session_version` = `session_version` + 1",
        array($aid)
    );
    return $aid;
}

function install_save_server($server)
{
    $sid = (int)db_value('SELECT `sid` FROM ' . table('servers') . ' WHERE `ip` = ? AND `port` = ?', array($server['ip'], $server['port']));
    if ($sid === 0) {
        db_execute(
            'INSERT INTO ' . table('servers') . ' (`ip`, `port`, `rcon`, `modid`, `enabled`) VALUES (?, ?, ?, 0, 1)',
            array($server['ip'], $server['port'], $server['rcon'])
        );
        $sid = db_insert_id();
    } elseif ($server['rcon'] !== '') {
        db_execute('UPDATE ' . table('servers') . ' SET `rcon` = ? WHERE `sid` = ?', array($server['rcon'], $sid));
    }
    save_server_info($sid, query_server_info($server['ip'], $server['port']));
    return $sid;
}

function finish_install($state, $with_server)
{
    $db = $state['db'];
    $owner = $state['owner'];
    $server = null;
    if ($with_server) {
        $address = server_address_input(post_param('ip'), post_param('port'));
        $server = array('ip' => $address[0], 'port' => $address[1], 'rcon' => text_value(post_param('rcon_password'), 128));
    }
    if (!data_dir_writable()) {
        fail('config_not_writable', 500);
    }

    $config = array(
        'installed' => true,
        'db' => array('host' => $db['host'], 'port' => $db['port'], 'user' => $db['user'], 'password' => $db['password'], 'database' => $db['database']),
        'prefix' => $db['prefix'],
        'secret' => random_hex(64),
        'installed_at' => gmdate('Y-m-d H:i:s'),
        'schema' => VERDICT_VERSION
    );

    try {
        $pdo = open_database($db, false);
        try {
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $db['database'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (PDOException $ignored) {
        }
        $pdo->exec('USE `' . $db['database'] . '`');
        db($pdo);
        $GLOBALS['config'] = $config;

        create_tables();
        import_material_admin_rights();
        $aid = install_save_owner($owner);
        $sid = $server ? install_save_server($server) : null;
    } catch (Exception $error) {
        $GLOBALS['config'] = null;
        throw install_db_error($error);
    }

    if (!save_config($config)) {
        $GLOBALS['config'] = null;
        fail('config_not_writable', 500);
    }
    @unlink(INSTALL_CODE_FILE);

    $row = find_admin($aid);
    sign_in($row);
    write_log('install', 'Verdict installed, database ' . $db['database']);

    return array('server_id' => $sid, 'has_rcon' => $server && $server['rcon'] !== '');
}

$state = install_state();
$error = null;
$error_detail = null;
$code_ok = !empty($_SESSION['install_code_ok']);
$requirements = array(
    'php' => version_compare(PHP_VERSION, '5.2.0', '>='),
    'pdo' => class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers()),
    'writable' => data_dir_writable()
);
$blocked = !$requirements['php'] || !$requirements['pdo'] || !$requirements['writable'];
if (!is_installed()) {
    install_code();
}

$jump = query_param('step');
if ($jump !== '' && $code_ok && (int)$jump <= $state['reached'] && (int)$jump < 4 && $state['result'] === null) {
    $state['step'] = (int)$jump;
    save_install_state($state);
}

if (is_post()) {
    $action = (string)post_param('do');
    try {
        check_csrf_form();
        if ($action === 'verify') {
            check_install_code(post_param('code'));
            $_SESSION['install_code_ok'] = true;
            $code_ok = true;
            $state['step'] = 1;
        } elseif (!$code_ok) {
            $state['step'] = 0;
        } elseif ($action === 'test_db' || $action === 'db_next') {
            $state['db'] = install_db_input();
            $state['db_info'] = null;
            try {
                $state['db_info'] = inspect_database($state['db']);
            } catch (Exception $failure) {
                throw install_db_error($failure);
            }
            if ($action === 'db_next') {
                $state['step'] = 2;
            }
        } elseif ($action === 'account') {
            $state['owner'] = install_owner_input();
            $state['step'] = 3;
        } elseif ($action === 'install' || $action === 'skip') {
            $state['result'] = finish_install($state, $action === 'install');
            $state['step'] = 4;
        }
    } catch (PanelError $failure) {
        $error = $failure->code_name;
        $error_detail = $failure->detail;
    }
    save_install_state($state);
    if ($error === null && $state['step'] === 4) {
        unset($_SESSION['install_code_ok']);
        $_SESSION['install_done'] = $state['result'];
        unset($_SESSION['install']);
        redirect_to(page_url('', array('done' => '1')));
    }
}

$done = isset($_SESSION['install_done']) && is_installed() ? $_SESSION['install_done'] : null;
$step = $done ? 4 : $state['step'];
$db = $state['db'] ? $state['db'] : array('host' => '127.0.0.1', 'port' => 3306, 'user' => '', 'password' => '', 'database' => 'verdict', 'prefix' => 'sb');
$owner = $state['owner'] ? $state['owner'] : array('username' => '', 'steamid' => '');
$info = $state['db_info'];

function install_field($label, $name, $value, $type = 'text', $hint = null, $extra = '')
{
    $input = '<input type="' . $type . '" name="' . $name . '" value="' . e($value) . '"' . $extra . '>';
    return field_block($label, $input, $hint);
}

render_head(t('install.welcome'));
?>
<div id="root">
<div class="install">
    <div class="install-top">
        <div class="brand"><?php echo brand_mark(34); ?><span>Verdict</span></div>
        <?php echo render_prefs(); ?>
    </div>

    <div class="progress" aria-hidden="true"><div class="progress-fill" style="width: <?php echo round($step / (count($steps) - 1) * 100); ?>%"></div></div>
    <ol class="steps">
        <?php foreach ($steps as $index => $name) {
            $class = $index === $step ? 'current' : ($index < $step ? 'passed' : '');
            ?>
            <li class="<?php echo $class; ?>"><span class="step-num"><?php echo $index < $step ? '✓' : $index + 1; ?></span><span class="step-label"><?php echo e(t('install.steps.' . $name)); ?></span></li>
        <?php } ?>
    </ol>

    <div class="glass install-card">
        <?php if ($step === 0) { ?>
            <form class="form" method="post" action="<?php echo e(page_url()); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="do" value="verify">
                <header class="install-head">
                    <h1><?php echo e(t('install.welcome')); ?></h1>
                    <p><?php echo e(t('install.welcome_text')); ?></p>
                </header>
                <ul class="checklist">
                    <li class="<?php echo $requirements['php'] ? 'ok' : 'bad'; ?>"><?php echo e(t('install.php_version', array('version' => PHP_VERSION))); ?><?php echo $requirements['php'] ? '' : ' — ' . e(t('install.php_old')); ?></li>
                    <li class="<?php echo $requirements['pdo'] ? 'ok' : 'bad'; ?>"><?php echo e(t($requirements['pdo'] ? 'install.pdo' : 'install.pdo_missing')); ?></li>
                    <li class="<?php echo $requirements['writable'] ? 'ok' : 'bad'; ?>"><?php echo e(t($requirements['writable'] ? 'install.writable' : 'install.not_writable')); ?></li>
                </ul>
                <?php echo field_block(t('install.code'), '<input class="code-input" name="code" placeholder="XXXXX-XXXXX" autofocus autocomplete="off" spellcheck="false" required>', t('install.code_hint')); ?>
                <?php echo error_line($error, $error_detail); ?>
                <div class="install-actions">
                    <button type="submit" class="btn btn-primary btn-block"<?php echo $blocked ? ' disabled' : ''; ?>><?php echo e(t('install.next')); ?></button>
                </div>
            </form>
        <?php } ?>

        <?php if ($step === 1) { ?>
            <form class="form" method="post" action="<?php echo e(page_url()); ?>">
                <?php echo csrf_field(); ?>
                <header class="install-head">
                    <h1><?php echo e(t('install.db_title')); ?></h1>
                    <p><?php echo e(t('install.db_text')); ?></p>
                </header>
                <?php echo install_field(t('install.db_host'), 'host', $db['host'], 'text', null, ' autofocus'); ?>
                <?php echo install_field(t('install.db_port'), 'port', $db['port'], 'number'); ?>
                <?php echo install_field(t('install.db_user'), 'user', $db['user'], 'text', null, ' autocomplete="off"'); ?>
                <?php echo install_field(t('install.db_password'), 'password', $db['password'], 'password', null, ' autocomplete="new-password"'); ?>
                <?php echo install_field(t('install.db_name'), 'database', $db['database'], 'text', t('install.db_name_hint')); ?>
                <?php echo install_field(t('install.db_prefix'), 'prefix', $db['prefix'], 'text', t('install.db_prefix_hint')); ?>

                <?php if ($info) {
                    if ($info['existing']) {
                        $state_text = t('install.db_existing');
                    } elseif (!$info['exists']) {
                        $state_text = t('install.db_will_create');
                    } elseif ($info['others'] > 0) {
                        $state_text = t('install.db_shared', array('n' => $info['others']));
                    } else {
                        $state_text = t('install.db_empty');
                    }
                    ?>
                    <div class="success-box">
                        <?php echo e(t('install.db_ok', array('version' => $info['version']))); ?>
                        <div class="small"><?php echo e($state_text); ?></div>
                    </div>
                <?php } ?>
                <?php echo error_line($error, $error_detail); ?>

                <div class="install-actions">
                    <?php if ($info) { ?>
                        <button type="submit" name="do" value="db_next" class="btn btn-primary btn-block"><?php echo e(t('install.next')); ?></button>
                    <?php } else { ?>
                        <button type="submit" name="do" value="test_db" class="btn btn-primary btn-block"><?php echo e(t('install.db_test')); ?></button>
                    <?php } ?>
                    <a class="btn btn-ghost btn-block" href="<?php echo e(page_url('', array('step' => 0))); ?>"><?php echo e(t('install.back')); ?></a>
                </div>
            </form>
        <?php } ?>

        <?php if ($step === 2) { ?>
            <form class="form" method="post" action="<?php echo e(page_url()); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="do" value="account">
                <header class="install-head">
                    <h1><?php echo e(t('install.account_title')); ?></h1>
                    <p><?php echo e(t('install.account_text')); ?></p>
                </header>
                <?php echo install_field(t('login.username'), 'username', $owner['username'], 'text', t('install.username_hint'), ' autocomplete="username" autofocus required'); ?>
                <?php echo install_field(t('login.password'), 'password', '', 'password', t('security.password_rules'), ' autocomplete="new-password" required'); ?>
                <?php echo install_field(t('account.confirm'), 'confirm', '', 'password', null, ' autocomplete="new-password" required'); ?>
                <?php echo install_field('SteamID (' . t('common.optional') . ')', 'steamid', $owner['steamid'], 'text', t('install.steamid_hint'), ' placeholder="STEAM_1:0:12345678"'); ?>
                <?php echo error_line($error, $error_detail); ?>
                <div class="install-actions">
                    <button type="submit" class="btn btn-primary btn-block"><?php echo e(t('install.next')); ?></button>
                    <a class="btn btn-ghost btn-block" href="<?php echo e(page_url('', array('step' => 1))); ?>"><?php echo e(t('install.back')); ?></a>
                </div>
            </form>
        <?php } ?>

        <?php if ($step === 3) { ?>
            <form class="form" method="post" action="<?php echo e(page_url()); ?>" data-install-server>
                <?php echo csrf_field(); ?>
                <header class="install-head">
                    <h1><?php echo e(t('install.server_title')); ?></h1>
                    <p><?php echo e(t('install.server_text')); ?></p>
                </header>
                <div data-server-address data-probe="install_query" data-ip="<?php echo e((string)post_param('ip')); ?>" data-port="<?php echo e((string)post_param('port', '27015')); ?>"></div>
                <?php echo field_block(t('servers.rcon_password') . ' (' . t('common.optional') . ')', '<input type="password" name="rcon_password" autocomplete="new-password">', t('install.rcon_hint')); ?>
                <?php echo error_line($error, $error_detail); ?>
                <div class="install-actions">
                    <button type="submit" name="do" value="install" class="btn btn-primary btn-block" data-busy-text="<?php echo e(t('install.installing')); ?>"><?php echo e(t('install.install')); ?></button>
                    <button type="submit" name="do" value="skip" class="btn btn-ghost btn-block" formnovalidate><?php echo e(t('install.skip_install')); ?></button>
                    <a class="btn btn-link" href="<?php echo e(page_url('', array('step' => 2))); ?>"><?php echo e(t('install.back')); ?></a>
                </div>
            </form>
        <?php } ?>

        <?php if ($step === 4 && $done) {
            $plugin = plugin_config_text($done['server_id'] ? $done['server_id'] : 0);
            $db_config = config_value('db');
            ?>
            <div class="form">
                <header class="install-head done-head">
                    <div class="done-icon">✓</div>
                    <h1><?php echo e(t('install.done_title')); ?></h1>
                    <p><?php echo e(t('install.done_text')); ?></p>
                </header>
                <?php echo plugin_config_html($plugin, $db_config['host']); ?>
                <div>
                    <h2><?php echo e(t('install.next_steps')); ?></h2>
                    <ol class="next-steps">
                        <li><?php echo e(t('install.step_plugin')); ?></li>
                        <li><?php echo e(t('install.step_config')); ?></li>
                        <?php if (!$done['server_id']) { ?><li><?php echo e(t('install.step_server')); ?></li><?php } ?>
                        <?php if ($done['has_rcon']) { ?><li><?php echo e(t('install.step_rcon')); ?></li><?php } ?>
                        <li><?php echo e(t('install.step_restart')); ?></li>
                        <li><?php echo e(t('install.step_2fa')); ?></li>
                    </ol>
                </div>
                <div class="install-actions">
                    <a href="<?php echo e(page_url()); ?>" class="btn btn-primary btn-block"><?php echo e(t('install.open_panel')); ?></a>
                </div>
            </div>
        <?php } ?>
    </div>

    <?php echo render_footer(); ?>
</div>
</div>
<?php
render_scripts();
