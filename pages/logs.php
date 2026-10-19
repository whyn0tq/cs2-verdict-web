<?php

require_page_permission('logs.view');

$search = text_value(query_param('search'), 64);
$category = (string)query_param('category');
$current_page = int_value(query_param('p', 1), 1);
$list = list_logs($search, $category, $current_page);
$filtered = $search !== '' || $category !== '';

function log_action_styles()
{
    return array(
        'bans_add' => array('ban', 'red'),
        'bans_edit' => array('pencil', 'neutral'),
        'bans_remove' => array('undo-2', 'green'),
        'bans_delete' => array('trash-2', 'red'),
        'comms_add' => array('mic-off', 'amber'),
        'comms_edit' => array('pencil', 'neutral'),
        'comms_remove' => array('undo-2', 'green'),
        'comms_delete' => array('trash-2', 'red'),
        'kick' => array('user-x', 'amber'),
        'slay' => array('skull', 'red'),
        'team_move' => array('arrow-left-right', 'neutral'),
        'say' => array('message-square', 'neutral'),
        'rcon' => array('terminal', 'neutral'),
        'server_add' => array('server', 'green'),
        'server_edit' => array('server-cog', 'neutral'),
        'server_delete' => array('trash-2', 'red'),
        'admin_add' => array('user-plus', 'green'),
        'admin_edit' => array('user-cog', 'neutral'),
        'admin_delete' => array('user-minus', 'red'),
        'group_add' => array('users', 'green'),
        'group_edit' => array('users', 'neutral'),
        'group_delete' => array('trash-2', 'red'),
        'login' => array('log-in', 'green'),
        'login_failed' => array('shield-alert', 'red'),
        'logout_all' => array('log-out', 'amber'),
        'password_change' => array('key-round', 'neutral'),
        '2fa_enable' => array('shield-check', 'green'),
        '2fa_disable' => array('shield-off', 'amber'),
        'plugin_config_view' => array('file-code', 'neutral'),
        'install' => array('rocket', 'green')
    );
}

function log_details_html($action, $text)
{
    $parts = preg_split('/(\s+)/', trim((string)$text), -1, PREG_SPLIT_DELIM_CAPTURE);
    $timed = preg_match('/^(bans|comms)_(add|edit)$/', $action);
    $tokens = array('password', '2fa-reset', '(2fa)');
    $html = '';
    foreach ($parts as $index => $part) {
        if (preg_match('/^#\d+$/', $part)) {
            $html .= '<span class="log-ref">' . e($part) . '</span>';
        } elseif (preg_match('/^7656\d{13}$/', $part)) {
            $html .= '<a class="log-steam" href="' . e(profile_url($part)) . '" target="_blank" rel="noreferrer noopener">' . e($part) . '</a>';
        } elseif ($timed && preg_match('/^\d+m$/', $part)) {
            $html .= '<span class="log-duration">' . e(format_duration((int)$part)) . '</span>';
        } elseif ($index === 0 && $action === 'comms_add' && in_array($part, comm_types())) {
            $html .= type_badge($part);
        } elseif (in_array($part, $tokens)) {
            $html .= '<span class="tag">' . e(t('logs.tokens.' . $part)) . '</span>';
        } else {
            $html .= e($part);
        }
    }
    return '<span class="log-details">' . $html . '</span>';
}

$styles = log_action_styles();
$subtitle = e(t('logs.total', array('n' => number_format($list['total'], 0, '', ' '))));
$live = $current_page === 1 ? '<span class="status-pill on"><span class="dot log-live-dot"></span>' . e(t('logs.live')) . '</span>' : '';
$categories = array('' => 'layers', 'punishments' => 'gavel', 'admins' => 'users', 'servers' => 'server', 'security' => 'shield-check');

render_page_start(t('logs.title'), 'logs');
?>
<section>
    <?php echo page_header('scroll-text', t('logs.title'), $subtitle, $live); ?>

    <form class="toolbar log-toolbar" method="get" action="<?php echo e(base_path()); ?>" data-live-form="log-results">
        <input type="hidden" name="page" value="logs">
        <input type="hidden" name="category" value="<?php echo e($category); ?>">
        <div class="searchbox<?php echo $search !== '' ? ' open' : ''; ?>">
            <button type="button" class="searchbox-icon" aria-label="<?php echo e(t('common.search')); ?>" title="<?php echo e(t('common.search')); ?>"><?php echo icon('search', 18); ?></button>
            <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="<?php echo e(t('logs.search_placeholder')); ?>" aria-label="<?php echo e(t('common.search')); ?>" autocomplete="off">
            <button type="button" class="searchbox-clear" aria-label="clear"<?php echo $search === '' ? ' hidden' : ''; ?>><?php echo icon('x', 15); ?></button>
        </div>
        <div class="log-filters" role="group" aria-label="<?php echo e(t('logs.title')); ?>">
            <?php foreach ($categories as $key => $icon_name) { ?>
                <button type="button" class="log-filter<?php echo $category === $key ? ' active' : ''; ?>" aria-pressed="<?php echo $category === $key ? 'true' : 'false'; ?>" data-log-category="<?php echo e($key); ?>">
                    <?php echo icon($icon_name, 15) . e(t('logs.categories.' . ($key !== '' ? $key : 'all'))); ?>
                </button>
            <?php } ?>
        </div>
    </form>

    <div id="log-results"<?php echo $current_page === 1 ? ' data-refresh="15000"' : ''; ?>>
        <div class="card log-card">
            <?php if (count($list['items']) > 0) { ?>
                <ul class="log-list" data-log-days>
                    <?php foreach ($list['items'] as $row) {
                        $style = isset($styles[$row['action']]) ? $styles[$row['action']] : array('activity', 'neutral');
                        $label = isset($styles[$row['action']]) ? t('logs.actions.' . $row['action']) : $row['action'];
                        $guest = !$row['admin_id'] && $row['admin_name'] === 'Guest';
                        $name = $guest ? t('logs.guest') : $row['admin_name'];
                        ?>
                        <li class="log-item tone-<?php echo e($style[1]); ?>" data-ts="<?php echo (int)$row['created']; ?>">
                            <span class="log-icon"><?php echo icon($style[0], 17); ?></span>
                            <div class="log-body">
                                <div class="log-head">
                                    <span class="log-action"><?php echo e($label); ?></span>
                                    <?php if ($guest) { ?>
                                        <span class="log-admin guest"><?php echo e($name); ?></span>
                                    <?php } else { ?>
                                        <button type="button" class="log-admin" data-log-search="<?php echo e($row['admin_name']); ?>" title="<?php echo e(t('logs.filter_admin', array('name' => $name))); ?>">
                                            <span class="log-admin-initial"><?php echo e(first_letter($name)); ?></span><?php echo e($name); ?>
                                        </button>
                                    <?php } ?>
                                </div>
                                <?php if ($row['details'] !== '') {
                                    echo log_details_html($row['action'], $row['details']);
                                } ?>
                            </div>
                            <div class="log-side">
                                <?php echo time_tag($row['created'], 'time', 'log-time'); ?>
                                <?php if ($row['ip']) { ?>
                                    <button type="button" class="log-ip" data-log-search="<?php echo e($row['ip']); ?>" title="<?php echo e(t('logs.filter_ip')); ?>"><?php echo e($row['ip']); ?></button>
                                <?php } ?>
                            </div>
                        </li>
                    <?php } ?>
                </ul>
            <?php } elseif ($filtered) {
                echo empty_block('search-x', t('logs.nothing_found'), t('logs.nothing_found_text'),
                    '<button type="button" class="btn btn-ghost btn-sm log-reset" data-log-reset>' . e(t('logs.reset')) . '</button>');
            } else {
                echo empty_block('scroll-text', t('logs.empty'), t('logs.empty_text'));
            } ?>
        </div>
        <?php echo pagination_links($list, 'logs', array('search' => $search, 'category' => $category)); ?>
    </div>
</section>
<?php
render_page_end();
