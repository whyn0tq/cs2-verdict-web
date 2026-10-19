<?php

require_page_permission('admins.manage');
$user = current_admin();
$admins = list_admins($user);

$groups = array();
foreach (list_groups() as $group) {
    $groups[] = array('id' => $group['id'], 'name' => $group['name'], 'immunity' => $group['immunity']);
}
$servers = array();
foreach (db_rows('SELECT s.`sid`, s.`ip`, s.`port`, i.`hostname` FROM ' . table('servers') . ' s LEFT JOIN `vd_server_info` i ON i.`sid` = s.`sid` ORDER BY s.`sid`') as $row) {
    $servers[] = array('id' => (int)$row['sid'], 'name' => server_name($row));
}

$add_button = '<button type="button" class="btn btn-primary" data-admin-add>' . icon('plus', 17) . e(t('admins.add')) . '</button>';

render_page_start(t('admins.title'), 'admins');
?>
<section>
    <?php echo page_header('shield-check', t('admins.title'), null, $add_button); ?>

    <div class="card table-wrap" data-prefer-steam-name>
        <table class="table">
            <thead>
                <tr>
                    <th><?php echo e(t('admins.name')); ?></th>
                    <th><?php echo e(t('admins.group')); ?></th>
                    <th><?php echo e(t('admins.access')); ?></th>
                    <th><?php echo e(t('admins.flags')); ?></th>
                    <th><?php echo e(t('admins.immunity')); ?></th>
                    <th><?php echo e(t('admins.expires')); ?></th>
                    <th class="right"><?php echo e(t('common.actions')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($admins as $row) {
                    $extra = $row['steamid'] && $row['username'] ? '<span class="muted small">@' . e($row['username']) . '</span>' : '';
                    ?>
                    <tr data-record="<?php echo e(json_encode($row)); ?>">
                        <td><?php echo player_block($row['username'], $row['steamid'], null, '', true, false, $extra); ?></td>
                        <td><?php echo e($row['group_name'] !== null ? $row['group_name'] : '—'); ?></td>
                        <td class="nowrap">
                            <?php if ($row['owner']) { ?>
                                <span class="badge badge-role-owner"><?php echo e(t('admins.owner')); ?></span>
                            <?php } elseif ($row['panel']) { ?>
                                <span class="badge badge-role"><?php echo e(t('admins.panel_access')); ?></span>
                            <?php } else { ?>
                                <span class="badge"><?php echo e(t('admins.game_only')); ?></span>
                            <?php } ?>
                            <?php if ($row['panel']) { ?>
                                <span class="badge <?php echo $row['totp_enabled'] ? 'badge-unbanned' : 'badge-expired'; ?> badge-2fa">2FA</span>
                            <?php } ?>
                        </td>
                        <td class="flags-cell"><?php echo e($row['flags'] !== '' ? implode(', ', str_split($row['flags'])) : '—'); ?></td>
                        <td><?php echo (int)$row['effective_immunity']; ?></td>
                        <td class="nowrap muted"><?php echo $row['expires_at'] ? time_tag($row['expires_at']) : e(t('admins.never')); ?></td>
                        <td class="right nowrap">
                            <div class="row-actions">
                                <?php if ($row['manageable']) { ?>
                                    <button type="button" class="btn btn-ghost btn-icon" data-admin-edit title="<?php echo e(t('common.edit')); ?>" aria-label="<?php echo e(t('common.edit')); ?>"><?php echo icon('pencil', 15); ?></button>
                                <?php } ?>
                                <?php if ($row['manageable'] && $row['id'] !== $user['id']) { ?>
                                    <button type="button" class="btn btn-ghost btn-icon text-danger" data-admin-delete title="<?php echo e(t('common.delete')); ?>" aria-label="<?php echo e(t('common.delete')); ?>"><?php echo icon('trash-2', 15); ?></button>
                                <?php } ?>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php if (count($admins) === 0) {
            echo empty_block('inbox', t('common.empty'));
        } ?>
    </div>
</section>
<?php
render_page_end(array('groups' => $groups, 'servers' => $servers));
