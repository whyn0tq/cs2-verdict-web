<?php

$row = load_server(int_value(query_param('id'), 0));
if (!$row) {
    redirect_to(page_url('servers'));
}
$server = present_server($row);
$plugin = isset($server['plugin']) ? $server['plugin'] : null;

$details = e($server['ip'] . ':' . $server['port']);
if ($server['online']) {
    $details .= ' · ' . e(map_label($server['map'])) . ' · ' . $server['players'] . '/' . $server['max_players'];
}

render_page_start($server['name'], 'servers');
?>
<section>
    <a href="<?php echo e(page_url('servers')); ?>" class="back-link"><?php echo icon('arrow-left', 16) . e(t('servers.back')); ?></a>

    <div class="page-header" id="server-head" data-refresh="10000">
        <div class="page-header-main">
            <span class="page-icon"><?php echo icon('server', 22); ?></span>
            <div>
                <h1>
                    <?php echo e($server['name']); ?>
                    <span class="status-pill <?php echo $server['online'] ? 'on' : 'off'; ?>"><span class="dot"></span><?php echo e(t($server['online'] ? 'servers.online' : 'servers.offline')); ?></span>
                    <?php if ($plugin) { ?>
                        <span class="status-pill <?php echo $plugin['connected'] ? 'on' : 'off'; ?>"><?php echo icon('plug', 13) . e($plugin['connected'] ? t('servers.plugin_on', array('version' => $plugin['version'] ? $plugin['version'] : '?')) : t('servers.plugin_off')); ?></span>
                    <?php } ?>
                </h1>
                <p class="page-sub"><?php echo $details; ?></p>
            </div>
        </div>
        <div class="page-header-actions">
            <a class="btn btn-primary" href="steam://connect/<?php echo e($server['ip'] . ':' . $server['port']); ?>"><?php echo icon('play', 16) . e(t('servers.connect')); ?></a>
            <?php if (can('servers.manage')) { ?>
                <?php if (can('owner')) { ?>
                    <button type="button" class="btn btn-ghost" data-plugin-config><?php echo icon('key-round', 16) . e(t('security.plugin_config')); ?></button>
                <?php } ?>
                <button type="button" class="btn btn-ghost" data-server-edit><?php echo icon('pencil', 16) . e(t('common.edit')); ?></button>
                <button type="button" class="btn btn-ghost btn-icon-lg text-danger" data-server-delete title="<?php echo e(t('common.delete')); ?>" aria-label="<?php echo e(t('common.delete')); ?>"><?php echo icon('trash-2', 16); ?></button>
            <?php } ?>
        </div>
    </div>

    <?php if (can('servers.manage')) { ?>
        <div class="notice">
            <?php echo e(t('servers.server_id_hint', array('id' => $server['id']))); ?>
            · <?php echo e(t('servers.last_seen')); ?>: <?php echo $server['last_seen'] ? time_tag($server['last_seen']) : e(t('servers.never')); ?>
            <?php if (!$plugin || !$plugin['connected']) { ?>
                <div><?php echo e(t('servers.plugin_hint')); ?></div>
                <?php if (empty($server['has_rcon'])) { ?>
                    <div class="text-warn"><?php echo e(t('servers.no_channel')); ?></div>
                <?php } ?>
            <?php } ?>
        </div>
    <?php } ?>

    <?php if (!can('players.view')) { ?>
        <div class="card"><?php echo empty_block('users', t('servers.login_to_see')); ?></div>
    <?php } else { ?>
        <div class="card panel-block">
            <div class="block-head">
                <h2 class="with-icon"><?php echo icon('users', 18) . e(t('servers.players_online')); ?></h2>
                <span class="badge" data-players-count>0</span>
            </div>
            <div data-players><div class="state"><?php echo e(t('common.loading')); ?></div></div>
        </div>
        <div data-feed></div>
    <?php } ?>

    <?php if (can('servers.rcon') && !empty($server['has_rcon'])) { ?>
        <div data-console></div>
    <?php } ?>
</section>
<?php
render_page_end(array('server' => $server));
