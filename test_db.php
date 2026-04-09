<?php
require_once('../../../Local Sites/kiwi-test/app/public/wp-load.php');
global $wpdb;
$table = $wpdb->prefix . 'ke_ticket_types';
$tickets = $wpdb->get_results("SELECT * FROM {$table}");
print_r($tickets);
