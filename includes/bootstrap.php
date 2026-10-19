<?php

error_reporting(E_ALL);
ini_set('display_errors', '0');

define('VERDICT_VERSION', '2.0.0');
define('ROOT_DIR', dirname(dirname(__FILE__)));
define('INCLUDE_DIR', ROOT_DIR . '/includes');
define('DATA_DIR', ROOT_DIR . '/data');
define('CONFIG_FILE', DATA_DIR . '/config.php');
define('INSTALL_CODE_FILE', DATA_DIR . '/install-code.php');

date_default_timezone_set('UTC');

require_once INCLUDE_DIR . '/helpers.php';
require_once INCLUDE_DIR . '/database.php';
require_once INCLUDE_DIR . '/schema.php';
require_once INCLUDE_DIR . '/i18n.php';
require_once INCLUDE_DIR . '/icons.php';
require_once INCLUDE_DIR . '/steam.php';
require_once INCLUDE_DIR . '/security.php';
require_once INCLUDE_DIR . '/totp.php';
require_once INCLUDE_DIR . '/qrcode.php';
require_once INCLUDE_DIR . '/permissions.php';
require_once INCLUDE_DIR . '/auth.php';
require_once INCLUDE_DIR . '/game_server.php';
require_once INCLUDE_DIR . '/servers.php';
require_once INCLUDE_DIR . '/punishments.php';
require_once INCLUDE_DIR . '/admins.php';
require_once INCLUDE_DIR . '/groups.php';
require_once INCLUDE_DIR . '/logs.php';
require_once INCLUDE_DIR . '/layout.php';

undo_magic_quotes();
load_config();
start_session();
send_security_headers();
