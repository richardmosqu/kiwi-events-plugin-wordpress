<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Plugin activation: create database tables, capabilities, and options
 */
class KE_Activator {

    public static function activate() {
        self::create_tables();
        self::add_capabilities();
        self::set_default_options();
        flush_rewrite_rules();
    }

    /**
     * Create custom database tables using dbDelta
     */
    private static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Ticket Types table
        $table_ticket_types = $wpdb->prefix . 'ke_ticket_types';
        $sql_ticket_types = "CREATE TABLE $table_ticket_types (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            ticket_type varchar(20) NOT NULL DEFAULT 'free',
            price decimal(10,2) NOT NULL DEFAULT 0.00,
            capacity_type varchar(20) NOT NULL DEFAULT 'limited',
            quantity_total int(11) NOT NULL DEFAULT 0,
            quantity_sold int(11) NOT NULL DEFAULT 0,
            min_per_order int(11) NOT NULL DEFAULT 1,
            max_per_order int(11) NOT NULL DEFAULT 10,
            sale_start datetime DEFAULT NULL,
            sale_end datetime DEFAULT NULL,
            show_remaining varchar(3) NOT NULL DEFAULT 'yes',
            status varchar(20) NOT NULL DEFAULT 'active',
            custom_fields text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY event_id (event_id)
        ) $charset_collate;";

        // Orders table
        $table_orders = $wpdb->prefix . 'ke_orders';
        $sql_orders = "CREATE TABLE $table_orders (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_number varchar(50) NOT NULL,
            event_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            buyer_name varchar(255) NOT NULL DEFAULT '',
            buyer_email varchar(255) NOT NULL DEFAULT '',
            total_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            ticket_quantity int(11) NOT NULL DEFAULT 0,
            payment_method varchar(50) NOT NULL DEFAULT 'free',
            payment_status varchar(20) NOT NULL DEFAULT 'pending',
            wc_order_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY order_number (order_number),
            KEY event_id (event_id),
            KEY user_id (user_id),
            KEY buyer_email (buyer_email),
            KEY payment_status (payment_status)
        ) $charset_collate;";

        // Tickets table
        $table_tickets = $wpdb->prefix . 'ke_tickets';
        $sql_tickets = "CREATE TABLE $table_tickets (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_code varchar(64) NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            ticket_type_id bigint(20) unsigned NOT NULL,
            event_id bigint(20) unsigned NOT NULL,
            attendee_name varchar(255) NOT NULL DEFAULT '',
            attendee_email varchar(255) NOT NULL DEFAULT '',
            attendee_number int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'valid',
            qr_code_path varchar(500) DEFAULT NULL,
            pdf_path varchar(500) DEFAULT NULL,
            checked_in_at datetime DEFAULT NULL,
            checked_in_by bigint(20) unsigned DEFAULT NULL,
            custom_fields_data text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY ticket_code (ticket_code),
            KEY order_id (order_id),
            KEY ticket_type_id (ticket_type_id),
            KEY event_id (event_id),
            KEY attendee_email (attendee_email),
            KEY status (status)
        ) $charset_collate;";

        dbDelta( $sql_ticket_types );
        dbDelta( $sql_orders );
        dbDelta( $sql_tickets );

        update_option( 'ke_db_version', KE_DB_VERSION );
    }

    /**
     * Add custom capabilities to admin role
     */
    private static function add_capabilities() {
        $admin_role = get_role( 'administrator' );
        if ( $admin_role ) {
            $admin_role->add_cap( 'manage_kiwi_events' );
            $admin_role->add_cap( 'edit_ke_events' );
            $admin_role->add_cap( 'scan_ke_tickets' );
        }
    }

    /**
     * Set default plugin options
     */
    private static function set_default_options() {
        $defaults = array(
            'ke_currency'              => 'USD',
            'ke_email_from_name'       => get_bloginfo( 'name' ),
            'ke_email_from_address'    => get_bloginfo( 'admin_email' ),
            'ke_default_ticket_limit'  => 10,
            'ke_scanner_page_id'       => 0,
        );

        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value );
            }
        }
    }
}
