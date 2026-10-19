<?php

require_login();
$user = current_admin();
$errors = array();
$stage = (string)query_param('stage');

function account_row($aid)
{
    ensure_admin_web_row($aid);
    return db_row(
        'SELECT a.`password`, w.`totp_secret`, w.`totp_enabled`, w.`totp_last_step` FROM ' . table('admins') . ' a
         LEFT JOIN `vd_admin_web` w ON w.`aid` = a.`aid` WHERE a.`aid` = ?',
        array((int)$aid)
    );
}

if (is_post()) {
    $action = (string)post_param('do');
    try {
        check_csrf_form();
        $row = account_row($user['id']);
        $password = (string)post_param('password');

        if ($action === 'password') {
            $stage = '';
            $next = (string)post_param('new_password');
            if ($next !== (string)post_param('confirm')) {
                fail('password_mismatch');
            }
            check_new_password($next);
            if (!password_matches((string)post_param('current'), $row['password'])) {
                fail('invalid_credentials');
            }
            db_execute('UPDATE ' . table('admins') . ' SET `password` = ? WHERE `aid` = ?', array(make_password_hash($next), $user['id']));
            bump_session_version($user['id']);
            write_log('password_change');
            flash_toast(t('security.password_changed'));
            redirect_to(page_url('account'));
        }

        if ($action === 'totp_start') {
            $stage = 'password';
            if ((int)$row['totp_enabled'] === 1) {
                fail('totp_already_enabled');
            }
            if (!password_matches($password, $row['password'])) {
                fail('invalid_credentials');
            }
            db_execute(
                'UPDATE `vd_admin_web` SET `totp_secret` = ?, `totp_enabled` = 0 WHERE `aid` = ?',
                array(protect_secret(new_totp_secret()), $user['id'])
            );
            $_SESSION['totp_setup'] = true;
            redirect_to(page_url('account', array('stage' => 'scan')));
        }

        if ($action === 'totp_enable') {
            $stage = 'scan';
            if ((int)$row['totp_enabled'] === 1) {
                fail('totp_already_enabled');
            }
            $secret = reveal_secret($row['totp_secret']);
            if (!$secret) {
                fail('totp_not_started');
            }
            $step = check_totp_code($secret, post_param('code'));
            if ($step === null) {
                fail('invalid_totp');
            }
            db_execute('UPDATE `vd_admin_web` SET `totp_enabled` = 1, `totp_last_step` = ? WHERE `aid` = ?', array($step, $user['id']));
            unset($_SESSION['totp_setup']);
            bump_session_version($user['id']);
            write_log('2fa_enable');
            flash_toast(t('security.enabled_toast'));
            redirect_to(page_url('account'));
        }

        if ($action === 'totp_disable') {
            $stage = 'disable';
            if ((int)$row['totp_enabled'] !== 1) {
                fail('totp_not_enabled');
            }
            if (!password_matches($password, $row['password'])) {
                fail('invalid_credentials');
            }
            if (check_totp_code(reveal_secret($row['totp_secret']), post_param('code'), $row['totp_last_step']) === null) {
                fail('invalid_totp');
            }
            db_execute('UPDATE `vd_admin_web` SET `totp_enabled` = 0, `totp_secret` = NULL, `totp_last_step` = 0 WHERE `aid` = ?', array($user['id']));
            bump_session_version($user['id']);
            write_log('2fa_disable');
            flash_toast(t('security.disabled_toast'));
            redirect_to(page_url('account'));
        }

        if ($action === 'logout_all') {
            bump_session_version($user['id']);
            write_log('logout_all');
            flash_toast(t('security.logout_all_done'));
            redirect_to(page_url('account'));
        }
    } catch (PanelError $error) {
        $errors[$action] = $error->code_name;
    }
}

$user = current_admin(true);
$row = account_row($user['id']);
$setup = null;
if ($stage === 'scan' && !$user['totp_enabled'] && !empty($_SESSION['totp_setup'])) {
    $secret = reveal_secret($row['totp_secret']);
    if ($secret) {
        $setup = totp_setup_data($secret, $user['username']);
    }
}
if ($stage === 'scan' && !$setup) {
    $stage = '';
}

function account_error($errors, $action)
{
    return isset($errors[$action]) ? error_line($errors[$action]) : '';
}

function code_input()
{
    return field_block(t('security.code'), '<input class="code-input" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" maxlength="6" pattern="\d{6}" required data-digits>');
}

render_page_start(t('account.title'), 'account');
?>
<section class="narrow">
    <?php echo page_header('user-round', t('account.title')); ?>

    <div class="card pad">
        <dl class="details">
            <div><dt><?php echo e(t('login.username')); ?></dt><dd><?php echo e($user['username']); ?></dd></div>
            <div><dt><?php echo e(t('admins.group')); ?></dt><dd><?php echo e($user['owner'] ? t('admins.owner') : ($user['group'] !== null ? $user['group'] : '—')); ?></dd></div>
            <div><dt>SteamID</dt><dd><?php echo e($user['steamid'] ? $user['steamid'] : '—'); ?></dd></div>
        </dl>
    </div>

    <div class="card pad">
        <div class="block-head">
            <h2><?php echo e(t('security.title')); ?></h2>
            <span class="badge <?php echo $user['totp_enabled'] ? 'badge-unbanned' : 'badge-active'; ?>"><?php echo e(t($user['totp_enabled'] ? 'security.on' : 'security.off')); ?></span>
        </div>

        <?php if (!$user['totp_enabled'] && $stage === '') { ?>
            <div class="form">
                <p class="muted"><?php echo e(t('security.description')); ?></p>
                <div><a class="btn btn-primary" href="<?php echo e(page_url('account', array('stage' => 'password'))); ?>"><?php echo e(t('security.enable')); ?></a></div>
            </div>
        <?php } ?>

        <?php if (!$user['totp_enabled'] && $stage === 'password') { ?>
            <form class="form" method="post" action="<?php echo e(page_url('account')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="do" value="totp_start">
                <?php echo field_block(t('account.current'), '<input type="password" name="password" autocomplete="current-password" required autofocus>'); ?>
                <?php echo account_error($errors, 'totp_start'); ?>
                <div class="actions">
                    <a class="btn btn-ghost" href="<?php echo e(page_url('account')); ?>"><?php echo e(t('common.cancel')); ?></a>
                    <button type="submit" class="btn btn-primary"><?php echo e(t('install.next')); ?> →</button>
                </div>
            </form>
        <?php } ?>

        <?php if (!$user['totp_enabled'] && $stage === 'scan' && $setup) { ?>
            <form class="form" method="post" action="<?php echo e(page_url('account', array('stage' => 'scan'))); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="do" value="totp_enable">
                <p class="muted"><?php echo e(t('security.scan')); ?></p>
                <div class="qr-box">
                    <img src="<?php echo e($setup['qr']); ?>" alt="QR" width="200" height="200">
                    <div class="qr-secret">
                        <span class="field-label"><?php echo e(t('security.manual')); ?></span>
                        <code><?php echo e(implode(' ', str_split($setup['secret'], 4))); ?></code>
                    </div>
                </div>
                <?php echo code_input(); ?>
                <?php echo account_error($errors, 'totp_enable'); ?>
                <div class="actions">
                    <a class="btn btn-ghost" href="<?php echo e(page_url('account')); ?>"><?php echo e(t('common.cancel')); ?></a>
                    <button type="submit" class="btn btn-primary"><?php echo e(t('security.confirm')); ?></button>
                </div>
            </form>
        <?php } ?>

        <?php if ($user['totp_enabled'] && $stage !== 'disable') { ?>
            <div class="form">
                <p class="muted"><?php echo e(t('security.enabled_text')); ?></p>
                <div><a class="btn btn-ghost text-danger" href="<?php echo e(page_url('account', array('stage' => 'disable'))); ?>"><?php echo e(t('security.disable')); ?></a></div>
            </div>
        <?php } ?>

        <?php if ($user['totp_enabled'] && $stage === 'disable') { ?>
            <form class="form" method="post" action="<?php echo e(page_url('account', array('stage' => 'disable'))); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="do" value="totp_disable">
                <?php echo field_block(t('account.current'), '<input type="password" name="password" autocomplete="current-password" required autofocus>'); ?>
                <?php echo code_input(); ?>
                <?php echo account_error($errors, 'totp_disable'); ?>
                <div class="actions">
                    <a class="btn btn-ghost" href="<?php echo e(page_url('account')); ?>"><?php echo e(t('common.cancel')); ?></a>
                    <button type="submit" class="btn btn-danger"><?php echo e(t('security.disable')); ?></button>
                </div>
            </form>
        <?php } ?>
    </div>

    <div class="card pad">
        <h2><?php echo e(t('account.change_password')); ?></h2>
        <form class="form" method="post" action="<?php echo e(page_url('account')); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="do" value="password">
            <?php echo field_block(t('account.current'), '<input type="password" name="current" autocomplete="current-password" required>'); ?>
            <?php echo field_block(t('account.new'), '<input type="password" name="new_password" autocomplete="new-password" required>', t('security.password_rules')); ?>
            <?php echo field_block(t('account.confirm'), '<input type="password" name="confirm" autocomplete="new-password" required>'); ?>
            <?php echo account_error($errors, 'password'); ?>
            <div><button type="submit" class="btn btn-primary"><?php echo e(t('common.save')); ?></button></div>
        </form>
    </div>

    <div class="card pad">
        <h2><?php echo e(t('security.sessions')); ?></h2>
        <form class="form" method="post" action="<?php echo e(page_url('account')); ?>"
            data-confirm-tone="warning"
            data-confirm-title="<?php echo e(t('security.logout_all_confirm')); ?>"
            data-confirm-text="<?php echo e(t('security.logout_all_text')); ?>"
            data-confirm-label="<?php echo e(t('confirm.sign_out')); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="do" value="logout_all">
            <p class="muted"><?php echo e(t('security.sessions_text')); ?></p>
            <div><button type="submit" class="btn btn-ghost"><?php echo e(t('security.logout_all')); ?></button></div>
        </form>
    </div>
</section>
<?php
render_page_end();
