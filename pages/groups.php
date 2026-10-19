<?php

require_page_permission('groups.manage');
$groups = list_groups();

$add_button = '<button type="button" class="btn btn-primary" data-group-add>' . icon('plus', 17) . e(t('groups.add')) . '</button>';

render_page_start(t('groups.title'), 'groups');
?>
<section>
    <?php echo page_header('layers', t('groups.title'), null, $add_button); ?>

    <div class="card table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th><?php echo e(t('groups.name')); ?></th>
                    <th><?php echo e(t('groups.panel')); ?></th>
                    <th><?php echo e(t('admins.flags')); ?></th>
                    <th><?php echo e(t('admins.immunity')); ?></th>
                    <th><?php echo e(t('groups.members')); ?></th>
                    <th class="right"><?php echo e(t('common.actions')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $row) {
                    if (in_array('owner', $row['web_permissions'])) {
                        $rights = t('permissions.owner');
                    } elseif (count($row['web_permissions']) > 0) {
                        $rights = t('groups.permissions_count', array('n' => count($row['web_permissions'])));
                    } else {
                        $rights = '—';
                    }
                    ?>
                    <tr data-record="<?php echo e(json_encode($row)); ?>">
                        <td><b><?php echo e($row['name']); ?></b></td>
                        <td class="flags-cell"><?php echo e($rights); ?></td>
                        <td class="flags-cell"><?php echo e($row['flags'] !== '' ? implode(', ', str_split($row['flags'])) : '—'); ?></td>
                        <td><?php echo (int)$row['immunity']; ?></td>
                        <td><?php echo (int)$row['members']; ?></td>
                        <td class="right nowrap">
                            <div class="row-actions">
                                <button type="button" class="btn btn-ghost btn-icon" data-group-edit title="<?php echo e(t('common.edit')); ?>" aria-label="<?php echo e(t('common.edit')); ?>"><?php echo icon('pencil', 15); ?></button>
                                <button type="button" class="btn btn-ghost btn-icon text-danger" data-group-delete title="<?php echo e(t('common.delete')); ?>" aria-label="<?php echo e(t('common.delete')); ?>"><?php echo icon('trash-2', 15); ?></button>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php if (count($groups) === 0) {
            echo empty_block('layers', t('groups.empty_title'), t('groups.empty'));
        } ?>
    </div>
</section>
<?php
render_page_end();
