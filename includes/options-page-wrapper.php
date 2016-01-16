<?php
    $list_table = new Elit_List_Table();
    $list_table->prepare_items();
    ?>

    <div class="wrap">
        <div id="icon-users" class="icon32"></div>
        <h2>Facebookkeeping</h2>
        <?php $list_table->display(); ?>
    </div>
    
