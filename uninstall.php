<?php
/**
 * Uninstall KiwiEvents — remove all data
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop custom tables
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ke_tickets" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ke_orders" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ke_ticket_types" );

// Delete all ke_event posts and their meta
$events = get_posts( array(
    'post_type'   => 'ke_event',
    'numberposts' => -1,
    'post_status' => 'any',
    'fields'      => 'ids',
) );
foreach ( $events as $event_id ) {
    wp_delete_post( $event_id, true );
}

// Delete plugin options
$options = array(
    'ke_db_version',
    'ke_currency',
    'ke_email_from_name',
    'ke_email_from_address',
    'ke_default_ticket_limit',
    'ke_scanner_page_id',
);
foreach ( $options as $option ) {
    delete_option( $option );
}

// Remove capabilities
$admin_role = get_role( 'administrator' );
if ( $admin_role ) {
    $admin_role->remove_cap( 'manage_kiwi_events' );
    $admin_role->remove_cap( 'edit_ke_events' );
    $admin_role->remove_cap( 'scan_ke_tickets' );
}

// Clean up uploads directory
$upload_dir = wp_upload_dir();
$ke_dir = $upload_dir['basedir'] . '/kiwi-events';
if ( is_dir( $ke_dir ) ) {
    // Recursively delete
    $it = new RecursiveDirectoryIterator( $ke_dir, RecursiveDirectoryIterator::SKIP_DOTS );
    $files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $files as $file ) {
        if ( $file->isDir() ) {
            rmdir( $file->getRealPath() );
        } else {
            unlink( $file->getRealPath() );
        }
    }
    rmdir( $ke_dir );
}
