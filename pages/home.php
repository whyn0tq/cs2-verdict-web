<?php

$stats = punishment_stats();
$servers = array();
$players_online = 0;
foreach (load_servers() as $row) {
    $server = present_server($row);
    $servers[] = $server;
    if ($server['online']) {
        $players_online += $server['players'];
    }
}
$latest = list_punishments('bans', '', '', '', 1, 8);

$tiles = array(
    array('home.active_bans', $stats['bans_active'], 'ban', 'accent'),
    array('home.total_bans', $stats['bans_total'], 'gavel', ''),
    array('home.active_comms', $stats['comms_active'], 'mic-off', ''),
    array('home.players_online', $players_online, 'users', '')
);

render_page_start(t('nav.home'), 'home');
?>
<section id="home-live" data-refresh="10000">
    <div class="hero"><h1><?php echo e(t('home.title')); ?></h1></div>

    <div class="tiles">
        <?php foreach ($tiles as $tile) { ?>
            <div class="card tile <?php echo e($tile[3]); ?>">
                <span class="tile-icon"><?php echo icon($tile[2], 20); ?></span>
                <div class="tile-value"><?php echo (int)$tile[1]; ?></div>
                <div class="tile-label"><?php echo e(t($tile[0])); ?></div>
            </div>
        <?php } ?>
    </div>

    <div class="split">
        <div class="card panel-block">
            <div class="block-head">
                <h2><?php echo e(t('home.latest_bans')); ?></h2>
                <a href="<?php echo e(page_url('bans')); ?>" class="btn btn-ghost btn-sm"><?php echo e(t('home.all_bans')) . icon('arrow-right', 15); ?></a>
            </div>
            <?php if (count($latest['items']) > 0) { ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><?php echo e(t('punish.player')); ?></th>
                                <th><?php echo e(t('punish.reason')); ?></th>
                                <th><?php echo e(t('punish.duration')); ?></th>
                                <th><?php echo e(t('punish.status')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($latest['items'] as $row) { ?>
                                <tr>
                                    <td>
                                        <?php echo player_block($row['name'], $row['steamid'], $row['steam2'], $row['steamid'] ? t('punish.no_name') : t('punish.ip_ban'), true, !$row['steamid']); ?>
                                        <div class="muted small"><?php echo time_tag($row['created_at']); ?></div>
                                    </td>
                                    <td class="reason"><?php echo e($row['reason'] !== '' ? $row['reason'] : '—'); ?></td>
                                    <td class="nowrap"><?php echo e(format_duration($row['duration'])); ?></td>
                                    <td><?php echo status_badge($row['status']); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } else { ?>
                <?php echo empty_block('ban', t('home.no_bans'), t('home.no_bans_text')); ?>
            <?php } ?>
        </div>

        <div class="card panel-block">
            <div class="block-head">
                <h2><?php echo e(t('home.servers')); ?></h2>
                <a href="<?php echo e(page_url('servers')); ?>" class="btn btn-ghost btn-sm"><?php echo e(t('home.all_servers')) . icon('arrow-right', 15); ?></a>
            </div>
            <div class="stack">
                <?php foreach ($servers as $server) {
                    echo server_card($server, true);
                } ?>
            </div>
            <?php if (count($servers) === 0) {
                echo empty_block('server', t('servers.empty'), t('servers.empty_text'));
            } ?>
        </div>
    </div>
</section>
<?php
render_page_end();
