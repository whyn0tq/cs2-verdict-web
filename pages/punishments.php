<?php

$kind = $page;
$is_comms = $kind === 'comms';
$user = current_admin();

$search = text_value(query_param('search'), 64);
$status = (string)query_param('status');
$type = $is_comms ? (string)query_param('type') : '';
$list = list_punishments($kind, $search, $status, $type, int_value(query_param('p', 1), 1));

$can_add = can($kind . '.add');
$can_edit = can($kind . '.edit');
$can_delete = can($kind . '.delete');
$has_actions = $can_add || $can_edit || $can_delete;
$columns = 6 + ($is_comms ? 1 : 0) + ($has_actions ? 1 : 0);
$filtered = $search !== '' || $status !== '' || $type !== '';

$server_options = array();
if ($can_add) {
    foreach (load_servers() as $row) {
        $server_options[] = array('id' => (int)$row['sid'], 'name' => server_name($row));
    }
}

function punishment_details($row, $kind)
{
    $entries = array();
    if ($row['steamid']) {
        $entries[] = array(t('punish.steamid'), e($row['steam2'] . ' · ' . $row['steamid']));
    }
    if (!empty($row['ip'])) {
        $entries[] = array(t('punish.ip'), e($row['ip']));
    }
    $entries[] = array(t('punish.server'), e($row['server_name'] !== null ? $row['server_name'] : t('punish.any_server')));
    $entries[] = array(t('punish.date'), time_tag($row['created_at']));
    $entries[] = array(t('punish.expires'), $row['expires_at'] ? time_tag($row['expires_at']) : e(t('duration.permanent')));
    if ($row['unbanned_at']) {
        $entries[] = array(t('punish.removed_by'), e($row['unban_admin_name']) . ' · ' . time_tag($row['unbanned_at']));
        $entries[] = array(t('punish.remove_reason'), e($row['unban_reason'] !== '' ? $row['unban_reason'] : '—'));
    }
    $html = '<dl class="details">';
    foreach ($entries as $entry) {
        $html .= '<div><dt>' . e($entry[0]) . '</dt><dd>' . $entry[1] . '</dd></div>';
    }
    return $html . '</dl>';
}

$add_button = '';
if ($can_add) {
    $add_button = '<button type="button" class="btn btn-primary" data-punish-add="' . e($kind) . '">' . icon('plus', 17) .
        e(t($is_comms ? 'punish.add_comm' : 'punish.add_ban')) . '</button>';
}

render_page_start(t($is_comms ? 'punish.comms_title' : 'punish.bans_title'), $kind);
?>
<section>
    <?php echo page_header($is_comms ? 'mic-off' : 'ban', t($is_comms ? 'punish.comms_title' : 'punish.bans_title'), null, $add_button); ?>

    <form class="toolbar" method="get" action="<?php echo e(base_path()); ?>" data-live-form="punish-results">
        <input type="hidden" name="page" value="<?php echo e($kind); ?>">
        <div class="searchbox<?php echo $search !== '' ? ' open' : ''; ?>">
            <button type="button" class="searchbox-icon" aria-label="<?php echo e(t('common.search')); ?>" title="<?php echo e(t('common.search')); ?>"><?php echo icon('search', 18); ?></button>
            <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="<?php echo e(t('punish.search_placeholder')); ?>" aria-label="<?php echo e(t('common.search')); ?>" autocomplete="off">
            <button type="button" class="searchbox-clear" aria-label="clear"<?php echo $search === '' ? ' hidden' : ''; ?>><?php echo icon('x', 15); ?></button>
        </div>
        <select name="status" class="toolbar-select" data-select aria-label="<?php echo e(t('punish.status')); ?>">
            <option value=""><?php echo e(t('punish.status') . ': ' . t('common.all')); ?></option>
            <?php foreach (array('active', 'expired', 'unbanned') as $option) { ?>
                <option value="<?php echo $option; ?>"<?php echo $status === $option ? ' selected' : ''; ?>><?php echo e(t('status.' . $option)); ?></option>
            <?php } ?>
        </select>
        <?php if ($is_comms) { ?>
            <select name="type" class="toolbar-select" data-select aria-label="<?php echo e(t('punish.type')); ?>">
                <option value=""><?php echo e(t('punish.type') . ': ' . t('common.all')); ?></option>
                <?php foreach (comm_types() as $option) { ?>
                    <option value="<?php echo $option; ?>"<?php echo $type === $option ? ' selected' : ''; ?>><?php echo e(t('type.' . $option)); ?></option>
                <?php } ?>
            </select>
        <?php } ?>
        <noscript><button type="submit" class="btn btn-ghost"><?php echo e(t('common.search')); ?></button></noscript>
    </form>

    <div id="punish-results">
        <div class="card table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><?php echo e(t('punish.date')); ?></th>
                        <th><?php echo e(t('punish.player')); ?></th>
                        <?php if ($is_comms) { ?><th><?php echo e(t('punish.type')); ?></th><?php } ?>
                        <th><?php echo e(t('punish.reason')); ?></th>
                        <th><?php echo e(t('punish.duration')); ?></th>
                        <th><?php echo e(t('punish.admin')); ?></th>
                        <th><?php echo e(t('punish.status')); ?></th>
                        <?php if ($has_actions) { ?><th class="right"><?php echo e(t('common.actions')); ?></th><?php } ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($list['items'] as $row) {
                        $may_modify = $can_edit || ($can_add && $row['admin_id'] === $user['id'] && $row['admin_id'] > 0);
                        $subject = $row['name'] ? $row['name'] : ($row['steam2'] ? $row['steam2'] : (string)(isset($row['ip']) ? $row['ip'] : ''));
                        ?>
                        <tr class="clickable" data-expand="<?php echo $row['id']; ?>" data-record="<?php echo e(json_encode($row)); ?>">
                            <td class="nowrap muted"><?php echo time_tag($row['created_at']); ?></td>
                            <td><?php echo player_block($row['name'], $row['steamid'], $row['steam2'], $row['steamid'] ? t('punish.no_name') : t('punish.ip_ban'), true, !$row['steamid']); ?></td>
                            <?php if ($is_comms) { ?><td><?php echo type_badge($row['type']); ?></td><?php } ?>
                            <td class="reason"><?php echo e($row['reason'] !== '' ? $row['reason'] : '—'); ?></td>
                            <td class="nowrap"><?php echo e(format_duration($row['duration'])); ?></td>
                            <td><?php echo e($row['admin_name']); ?></td>
                            <td><?php echo status_badge($row['status']); ?></td>
                            <?php if ($has_actions) { ?>
                                <td class="right nowrap" data-stop>
                                    <div class="row-actions">
                                        <?php if ($may_modify && $row['status'] === 'active') { ?>
                                            <button type="button" class="btn btn-ghost btn-sm" data-punish-remove="<?php echo e($kind); ?>"><?php echo icon('undo-2', 15) . e(t($is_comms ? 'punish.remove' : 'punish.unban')); ?></button>
                                        <?php } ?>
                                        <?php if ($may_modify) { ?>
                                            <button type="button" class="btn btn-ghost btn-icon" data-punish-edit="<?php echo e($kind); ?>" title="<?php echo e(t('common.edit')); ?>" aria-label="<?php echo e(t('common.edit')); ?>"><?php echo icon('pencil', 15); ?></button>
                                        <?php } ?>
                                        <?php if ($can_delete) { ?>
                                            <button type="button" class="btn btn-ghost btn-icon text-danger" data-punish-delete="<?php echo e($kind); ?>" data-subject="<?php echo e($subject); ?>" title="<?php echo e(t('common.delete')); ?>" aria-label="<?php echo e(t('common.delete')); ?>"><?php echo icon('trash-2', 15); ?></button>
                                        <?php } ?>
                                    </div>
                                </td>
                            <?php } ?>
                        </tr>
                        <tr class="details-row" data-details="<?php echo $row['id']; ?>" hidden>
                            <td colspan="<?php echo $columns; ?>"><?php echo punishment_details($row, $kind); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            <?php if (count($list['items']) === 0) {
                if ($filtered) {
                    echo empty_block($is_comms ? 'mic-off' : 'ban', t('common.empty'));
                } else {
                    echo empty_block($is_comms ? 'mic-off' : 'ban', t($is_comms ? 'punish.no_comms' : 'punish.no_bans'), t('punish.empty_text'));
                }
            } ?>
        </div>
        <?php echo pagination_links($list, $kind, array('search' => $search, 'status' => $status, 'type' => $type)); ?>
    </div>
</section>
<?php
render_page_end(array('servers' => $server_options));
