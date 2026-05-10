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
        self::schedule_cron_jobs();
        flush_rewrite_rules();
    }

    /**
     * Schedule recurring cron jobs. Each module's cron class is responsible
     * for its own hook + interval — KE_Activator just nudges them on
     * activation so the user doesn't have to wait for `init` to fire.
     */
    private static function schedule_cron_jobs() {
        if ( class_exists( 'KE_Reservations_Cron' ) ) {
            KE_Reservations_Cron::activate();
        }
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
            is_archived tinyint(1) NOT NULL DEFAULT 0,
            custom_fields text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY event_id (event_id),
            KEY is_archived (is_archived)
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
            KEY payment_status (payment_status),
            KEY ke_event_pay_created (event_id, payment_status, created_at)
        ) $charset_collate;";

        // Tickets table
        $table_tickets = $wpdb->prefix . 'ke_tickets';
        $sql_tickets = "CREATE TABLE $table_tickets (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_code varchar(64) NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            ticket_type_id bigint(20) unsigned NOT NULL,
            ticket_type_snapshot varchar(255) DEFAULT NULL,
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
            extra_fields_data text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY ticket_code (ticket_code),
            KEY order_id (order_id),
            KEY ticket_type_id (ticket_type_id),
            KEY event_id (event_id),
            KEY attendee_email (attendee_email),
            KEY status (status),
            KEY ke_event_status_created (event_id, status, created_at)
        ) $charset_collate;";

        // Reservations table — group/capacity bookings (parallel to ticket
        // sales). One row per reservation made by a single contact, holding
        // a `party_size` of seats. Unlike tickets there is no order or QR
        // code by default; reservations are confirmed in two modes (auto
        // or venue-approved) and capacity is tracked by summing party_size
        // across rows whose status counts as "holding" (pending|confirmed).
        //
        // Status is stored as varchar (not ENUM) to match the rest of the
        // schema and to keep dbDelta happy on cross-MySQL version installs.
        $table_reservations = $wpdb->prefix . 'ke_reservations';
        $sql_reservations = "CREATE TABLE $table_reservations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            reservation_code varchar(32) NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'pending',
            customer_name varchar(255) NOT NULL DEFAULT '',
            customer_email varchar(255) NOT NULL DEFAULT '',
            customer_phone varchar(50) NOT NULL DEFAULT '',
            party_size int(11) NOT NULL DEFAULT 1,
            arrival_time datetime NOT NULL,
            area varchar(100) DEFAULT NULL,
            notes text DEFAULT NULL,
            extra_fields_data text DEFAULT NULL,
            decline_reason text DEFAULT NULL,
            checked_in_at datetime DEFAULT NULL,
            checked_in_by bigint(20) unsigned DEFAULT NULL,
            no_show_processed tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY reservation_code (reservation_code),
            KEY event_id (event_id),
            KEY status (status),
            KEY customer_email (customer_email),
            KEY arrival_time (arrival_time),
            KEY ke_event_status_arrival (event_id, status, arrival_time)
        ) $charset_collate;";

        dbDelta( $sql_ticket_types );
        dbDelta( $sql_orders );
        dbDelta( $sql_tickets );
        dbDelta( $sql_reservations );

        // Belt-and-suspenders migration: dbDelta can miss column additions
        // in some edge cases, so add is_archived explicitly if missing.
        $has_archived = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'is_archived'",
            DB_NAME, $table_ticket_types
        ) );
        if ( ! $has_archived ) {
            $wpdb->query( "ALTER TABLE {$table_ticket_types} ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER status" );
            $wpdb->query( "ALTER TABLE {$table_ticket_types} ADD KEY is_archived (is_archived)" );
        }

        // ticket_type_snapshot on ke_tickets: captures the type name at sale time
        // so attendees lists survive ticket-type deletion or archival.
        $has_snapshot = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'ticket_type_snapshot'",
            DB_NAME, $table_tickets
        ) );
        if ( ! $has_snapshot ) {
            $wpdb->query( "ALTER TABLE {$table_tickets} ADD COLUMN ticket_type_snapshot VARCHAR(255) DEFAULT NULL AFTER ticket_type_id" );
        }

        // Backfill any missing snapshots from the live ticket type row.
        $wpdb->query(
            "UPDATE {$table_tickets} t
             LEFT JOIN {$table_ticket_types} tt ON t.ticket_type_id = tt.id
             SET t.ticket_type_snapshot = tt.name
             WHERE t.ticket_type_snapshot IS NULL AND tt.name IS NOT NULL"
        );

        // Composite indexes for the dashboard's time-window queries. dbDelta is
        // unreliable for adding indexes to existing tables, so add explicitly
        // when missing.
        $has_tickets_idx = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'ke_event_status_created'",
            DB_NAME, $table_tickets
        ) );
        if ( ! $has_tickets_idx ) {
            $wpdb->query( "ALTER TABLE {$table_tickets} ADD KEY ke_event_status_created (event_id, status, created_at)" );
        }

        $has_orders_idx = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'ke_event_pay_created'",
            DB_NAME, $table_orders
        ) );
        if ( ! $has_orders_idx ) {
            $wpdb->query( "ALTER TABLE {$table_orders} ADD KEY ke_event_pay_created (event_id, payment_status, created_at)" );
        }

        // Per-event extra fields: stores attendee responses to admin-defined
        // questions (university, dietary, shirt size, etc.). Holds JSON keyed
        // by stable field id so renaming a label later doesn't lose answers.
        $has_extras = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'extra_fields_data'",
            DB_NAME, $table_tickets
        ) );
        if ( ! $has_extras ) {
            $wpdb->query( "ALTER TABLE {$table_tickets} ADD COLUMN extra_fields_data TEXT NULL AFTER attendee_email" );
        }

        // Belt-and-suspenders for the reservations table: dbDelta can miss
        // index additions on existing tables across plugin upgrades, so add
        // any missing column/index explicitly. Mirrors the same pattern used
        // for ke_tickets / ke_orders above.
        $has_no_show = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'no_show_processed'",
            DB_NAME, $table_reservations
        ) );
        if ( ! $has_no_show ) {
            $wpdb->query( "ALTER TABLE {$table_reservations} ADD COLUMN no_show_processed TINYINT(1) NOT NULL DEFAULT 0 AFTER checked_in_by" );
        }
        $has_resv_idx = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'ke_event_status_arrival'",
            DB_NAME, $table_reservations
        ) );
        if ( ! $has_resv_idx ) {
            $wpdb->query( "ALTER TABLE {$table_reservations} ADD KEY ke_event_status_arrival (event_id, status, arrival_time)" );
        }

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
