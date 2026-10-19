<?php

require dirname(__FILE__) . '/includes/bootstrap.php';

function render_error_page($code)
{
    render_head(t('errors.server_error'));
    echo '<div id="root"><div class="auth"><div class="auth-brand">' . brand_mark(48) . '<span>Verdict</span></div>';
    echo '<div class="glass auth-box"><header class="install-head"><h1>' . e(error_text($code)) . '</h1></header>';
    echo '<div class="install-actions"><a class="btn btn-primary btn-block" href="' . e(page_url()) . '">' . e(t('nav.home')) . '</a></div></div>';
    echo render_footer() . '</div></div>';
    render_scripts();
}

$page = (string)query_param('page', 'home');

try {
    $install_finished = is_installed() && isset($_SESSION['install_done']) && query_param('done') === '1';
    if (!is_installed() || $install_finished) {
        require ROOT_DIR . '/pages/install.php';
        unset($_SESSION['install_done']);
        exit;
    }

    upgrade_schema_if_needed();
    sweep_attempts();
    sweep_live_tables();

    $routes = array(
        'home' => 'home.php',
        'bans' => 'punishments.php',
        'comms' => 'punishments.php',
        'servers' => 'servers.php',
        'server' => 'server.php',
        'admins' => 'admins.php',
        'groups' => 'groups.php',
        'logs' => 'logs.php',
        'account' => 'account.php',
        'login' => 'login.php',
        'logout' => 'logout.php'
    );
    if (!isset($routes[$page])) {
        redirect_to(page_url());
    }
    require ROOT_DIR . '/pages/' . $routes[$page];
} catch (PanelError $error) {
    render_error_page($error->code_name);
} catch (Exception $error) {
    error_log('Verdict: ' . $error->getMessage());
    render_error_page($error instanceof PDOException ? 'db_error' : 'server_error');
}
