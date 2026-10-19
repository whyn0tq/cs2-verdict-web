<?php

if (current_admin()) {
    redirect_to(page_url());
}

$error = null;
$username = '';
$pending = isset($_SESSION['login_pending']) ? $_SESSION['login_pending'] : null;
if ($pending && $pending['until'] < time()) {
    $pending = null;
    unset($_SESSION['login_pending']);
}
if (query_param('back') === '1') {
    unset($_SESSION['login_pending']);
    redirect_to(page_url('login'));
}

function find_login_admin($username)
{
    $row = db_row(admin_select_sql() . " WHERE a.`user` = ? AND a.`password` <> '' LIMIT 1", array($username));
    if (!$row || !admin_is_active($row)) {
        return null;
    }
    $rights = admin_rights($row);
    return count($rights['permissions']) > 0 ? $row : null;
}

if (is_post()) {
    try {
        check_csrf_form();
        $ip_key = 'login-ip:' . client_ip();
        if (attempts_exceeded($ip_key, 30, 900)) {
            fail('too_many_attempts', 429);
        }

        if ($pending) {
            $user_key = 'login-user:' . strtolower($pending['user']) . ':' . client_ip();
            if (attempts_exceeded($user_key, 10, 900)) {
                fail('too_many_attempts', 429);
            }
            $row = find_admin($pending['aid']);
            $secret = $row ? reveal_secret($row['totp_secret']) : null;
            $step = $secret ? check_totp_code($secret, post_param('code'), $row['totp_last_step']) : null;
            if ($step === null) {
                add_attempt($ip_key, 900);
                add_attempt($user_key, 900);
                write_log('login_failed', $pending['user'] . ' (2fa)', false);
                fail('invalid_totp', 401);
            }
            db_execute('UPDATE `vd_admin_web` SET `totp_last_step` = ? WHERE `aid` = ?', array($step, (int)$row['aid']));
            unset($_SESSION['login_pending']);
            clear_attempts($user_key);
            sign_in($row);
            write_log('login');
            redirect_to(page_url());
        }

        $username = text_value(post_param('username'), 64);
        $password = cut_text((string)post_param('password'), 200);
        $user_key = 'login-user:' . strtolower($username) . ':' . client_ip();
        if (attempts_exceeded($user_key, 10, 900)) {
            fail('too_many_attempts', 429);
        }

        $row = find_login_admin($username);
        $valid = $row && password_matches($password, $row['password']);
        if (!$valid) {
            add_attempt($ip_key, 900);
            add_attempt($user_key, 900);
            write_log('login_failed', $username, false);
            fail('invalid_credentials', 401);
        }

        if ((int)$row['totp_enabled'] === 1) {
            $_SESSION['login_pending'] = array('aid' => (int)$row['aid'], 'user' => $username, 'until' => time() + 300);
            redirect_to(page_url('login'));
        }

        clear_attempts($user_key);
        ensure_admin_web_row($row['aid']);
        $row = find_admin($row['aid']);
        sign_in($row);
        write_log('login');
        redirect_to(page_url());
    } catch (PanelError $failure) {
        $error = $failure->code_name;
    }
}

$need_code = $pending !== null;

render_head(t('login.title'));
?>
<div id="root">
    <div class="auth">
        <?php echo render_prefs('auth-prefs'); ?>
        <div class="auth-brand"><?php echo brand_mark(48); ?><span>Verdict</span></div>
        <div class="glass auth-box">
            <header class="install-head">
                <h1><?php echo e($need_code ? t('security.title') : t('login.title')); ?></h1>
                <p><?php echo e($need_code ? t('security.code_hint') : t('login.subtitle')); ?></p>
            </header>
            <form class="form" method="post" action="<?php echo e(page_url('login')); ?>">
                <?php echo csrf_field(); ?>
                <?php if (!$need_code) { ?>
                    <?php echo field_block(t('login.username'), '<input name="username" value="' . e($username) . '" autocomplete="username" required autofocus>'); ?>
                    <?php echo field_block(t('login.password'), '<input type="password" name="password" autocomplete="current-password" required>'); ?>
                <?php } else { ?>
                    <?php echo field_block(t('security.code'), '<input class="code-input" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" maxlength="6" pattern="\d{6}" required autofocus data-digits>'); ?>
                <?php } ?>
                <?php echo error_line($error); ?>
                <div class="install-actions">
                    <button type="submit" class="btn btn-primary btn-block"><?php echo icon($need_code ? 'key-round' : 'log-in', 17) . e(t('login.submit')); ?></button>
                    <?php if ($need_code) { ?>
                        <a class="btn btn-ghost btn-block" href="<?php echo e(page_url('login', array('back' => '1'))); ?>"><?php echo e(t('install.back')); ?></a>
                    <?php } ?>
                </div>
            </form>
        </div>
        <?php echo render_footer(); ?>
    </div>
</div>
<?php
render_scripts();
