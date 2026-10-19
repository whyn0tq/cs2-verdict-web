<?php

$servers = array();
foreach (load_servers() as $row) {
    $servers[] = present_server($row);
}

$add_button = '';
if (can('servers.manage')) {
    $add_button = '<button type="button" class="btn btn-primary" data-server-add>' . icon('plus', 17) . e(t('servers.add')) . '</button>';
}

render_page_start(t('servers.title'), 'servers');
?>
<section>
    <?php echo page_header('server', t('servers.title'), null, $add_button); ?>

    <div id="servers-live" data-refresh="10000">
        <?php if (count($servers) > 0) { ?>
            <div class="grid-cards">
                <?php foreach ($servers as $server) {
                    echo server_card($server);
                } ?>
            </div>
        <?php } else { ?>
            <div class="card"><?php echo empty_block('server', t('servers.empty'), t('servers.empty_text')); ?></div>
        <?php } ?>
    </div>
</section>
<?php
render_page_end();
