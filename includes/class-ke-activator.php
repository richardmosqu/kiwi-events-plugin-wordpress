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
        if ( class_exists( 'KE_Waitlist_Cron' ) ) {
            KE_Waitlist_Cron::activate();
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
            is_courtesy tinyint(1) NOT NULL DEFAULT 0,
            is_error tinyint(1) NOT NULL DEFAULT 0,
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
            KEY ke_event_status_created (event_id, status, created_at),
            KEY ke_event_courtesy (event_id, is_courtesy),
            KEY ke_event_error (event_id, is_error)
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

        // Event slug history: one row per retired slug, used to serve a 301
        // from the old URL to the event's current permalink. See KE_Event_Slug
        // for the read/write logic.
        $table_slug_history = $wpdb->prefix . 'ke_event_slug_history';
        $sql_slug_history = "CREATE TABLE $table_slug_history (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            old_slug varchar(200) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_old_slug (old_slug),
            KEY idx_event_id (event_id)
        ) $charset_collate;";

        // ─── Promoter system ─────────────────────────────────────────
        // Promoters are WordPress users flagged as promoters via a row in
        // ke_promoters that links to wp_users.ID. Authentication is WP-native.
        //   ke_promoters             — promoter accounts (one row per WP user)
        //   ke_promoter_lists        — groupings of promoters
        //   ke_promoter_list_members — many-to-many pivot
        //   ke_event_promoters       — which promoters earn on which events
        //   ke_promoter_commissions  — one row per attributed sale
        //   ke_promoter_clicks       — analytics-only click log

        $table_promoters = $wpdb->prefix . 'ke_promoters';
        $sql_promoters = "CREATE TABLE $table_promoters (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned DEFAULT NULL,
            slug varchar(80) NOT NULL,
            phone varchar(50) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_user_id (user_id),
            UNIQUE KEY uq_slug (slug),
            KEY status (status)
        ) $charset_collate;";

        $table_promoter_lists = $wpdb->prefix . 'ke_promoter_lists';
        $sql_promoter_lists = "CREATE TABLE $table_promoter_lists (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY name (name)
        ) $charset_collate;";

        $table_promoter_list_members = $wpdb->prefix . 'ke_promoter_list_members';
        $sql_promoter_list_members = "CREATE TABLE $table_promoter_list_members (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            promoter_id bigint(20) unsigned NOT NULL,
            list_id bigint(20) unsigned NOT NULL,
            added_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_member (promoter_id, list_id),
            KEY list_id (list_id)
        ) $charset_collate;";

        $table_event_promoters = $wpdb->prefix . 'ke_event_promoters';
        $sql_event_promoters = "CREATE TABLE $table_event_promoters (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            promoter_id bigint(20) unsigned NOT NULL,
            commission_type varchar(20) NOT NULL DEFAULT 'percentage',
            commission_value decimal(10,2) NOT NULL DEFAULT 0.00,
            assigned_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_event_promoter (event_id, promoter_id),
            KEY promoter_id (promoter_id)
        ) $charset_collate;";

        $table_promoter_commissions = $wpdb->prefix . 'ke_promoter_commissions';
        $sql_promoter_commissions = "CREATE TABLE $table_promoter_commissions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            promoter_id bigint(20) unsigned NOT NULL,
            event_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
            wc_order_id bigint(20) unsigned DEFAULT NULL,
            buyer_name varchar(255) NOT NULL DEFAULT '',
            buyer_email varchar(255) NOT NULL DEFAULT '',
            ticket_base_price decimal(10,2) NOT NULL DEFAULT 0.00,
            commission_type varchar(20) NOT NULL DEFAULT 'percentage',
            commission_value decimal(10,2) NOT NULL DEFAULT 0.00,
            commission_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            status varchar(20) NOT NULL DEFAULT 'earned',
            paid_at datetime DEFAULT NULL,
            paid_note text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY promoter_id (promoter_id),
            KEY event_id (event_id),
            KEY ticket_id (ticket_id),
            KEY status (status),
            KEY ke_promoter_status_created (promoter_id, status, created_at)
        ) $charset_collate;";

        $table_promoter_clicks = $wpdb->prefix . 'ke_promoter_clicks';
        $sql_promoter_clicks = "CREATE TABLE $table_promoter_clicks (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            promoter_id bigint(20) unsigned NOT NULL,
            event_id bigint(20) unsigned DEFAULT NULL,
            session_id varchar(64) NOT NULL DEFAULT '',
            ip_hash varchar(64) NOT NULL DEFAULT '',
            user_agent varchar(255) NOT NULL DEFAULT '',
            clicked_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY promoter_id (promoter_id),
            KEY clicked_at (clicked_at)
        ) $charset_collate;";

        //   ke_promoter_admin_audit — tracks admin impersonation/preview
        //   sessions of a promoter dashboard. Each row records who previewed
        //   whose dashboard and from where. Append-only.
        $table_promoter_admin_audit = $wpdb->prefix . 'ke_promoter_admin_audit';
        $sql_promoter_admin_audit = "CREATE TABLE $table_promoter_admin_audit (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            admin_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            admin_login varchar(60) NOT NULL DEFAULT '',
            promoter_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(40) NOT NULL DEFAULT 'view_dashboard_preview',
            ip varchar(64) NOT NULL DEFAULT '',
            user_agent varchar(255) NOT NULL DEFAULT '',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY promoter_id (promoter_id),
            KEY admin_user_id (admin_user_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        //   ke_email_log — outbound emails queued or sent by KE_Email_Queue.
        $table_email_log = $wpdb->prefix . 'ke_email_log';
        $sql_email_log = "CREATE TABLE $table_email_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            recipient varchar(255) NOT NULL DEFAULT '',
            subject varchar(255) NOT NULL DEFAULT '',
            template varchar(64) NOT NULL DEFAULT '',
            context_json longtext DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            error_message text DEFAULT NULL,
            scheduled_for datetime DEFAULT NULL,
            sent_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY recipient (recipient),
            KEY created_at (created_at)
        ) $charset_collate;";

        // ── Community board ─────────────────────────────────────────
        //   ke_board_likes — one row per (post, user) like on a board
        //   event. The UNIQUE KEY is the real double-like guard; the
        //   _ke_board_like_count post meta is only a denormalized cache
        //   recomputed from this table on every toggle.
        $table_board_likes = $wpdb->prefix . 'ke_board_likes';
        $sql_board_likes = "CREATE TABLE $table_board_likes (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_like (post_id, user_id),
            KEY post_id (post_id)
        ) $charset_collate;";

        // ── Ticket-sales waitlist ───────────────────────────────────
        //   ke_waitlist — one row per (event, email) for people who asked to
        //   be notified when a scheduled ticket sale opens. The UNIQUE KEY is
        //   the real double-signup guard (KE_Waitlist::join relies on it for
        //   its ON DUPLICATE KEY UPDATE); the email prefix keeps the index
        //   under InnoDB's key length limit on utf8mb4 installs.
        $table_waitlist = $wpdb->prefix . 'ke_waitlist';
        $sql_waitlist = "CREATE TABLE $table_waitlist (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            email varchar(255) NOT NULL DEFAULT '',
            name varchar(120) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'pending',
            ip_hash varchar(64) NOT NULL DEFAULT '',
            notified_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_signup (event_id, email(190)),
            KEY event_id (event_id),
            KEY status (status),
            KEY ke_event_status (event_id, status)
        ) $charset_collate;";

        dbDelta( $sql_ticket_types );
        dbDelta( $sql_orders );
        dbDelta( $sql_tickets );
        dbDelta( $sql_reservations );
        dbDelta( $sql_slug_history );
        dbDelta( $sql_promoters );
        dbDelta( $sql_promoter_lists );
        dbDelta( $sql_promoter_list_members );
        dbDelta( $sql_event_promoters );
        dbDelta( $sql_promoter_commissions );
        dbDelta( $sql_promoter_clicks );
        dbDelta( $sql_promoter_admin_audit );
        dbDelta( $sql_email_log );
        dbDelta( $sql_board_likes );
        dbDelta( $sql_waitlist );

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

        // sale_end has been in the initial CREATE TABLE since v1.0.0 but was
        // dormant until the per-ticket-type cutoff feature wired it up. If an
        // install somehow missed it (dbDelta has been unreliable across MySQL
        // versions), add it explicitly.
        $has_sale_end = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'sale_end'",
            DB_NAME, $table_ticket_types
        ) );
        if ( ! $has_sale_end ) {
            $wpdb->query( "ALTER TABLE {$table_ticket_types} ADD COLUMN sale_end DATETIME DEFAULT NULL AFTER max_per_order" );
        }

        // is_error on ke_tickets: emergency "Ticket error" attendees, issued by an
        // administrator to repair a botched sale. They are real, scannable
        // tickets that never count as a sale, so every organizer-facing surface
        // filters them out — see KE_Tickets::EXCLUDE_ERROR_SQL.
        $has_is_error = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'is_error'",
            DB_NAME, $table_tickets
        ) );
        if ( ! $has_is_error ) {
            $wpdb->query( "ALTER TABLE {$table_tickets} ADD COLUMN is_error TINYINT(1) NOT NULL DEFAULT 0 AFTER is_courtesy" );
            $wpdb->query( "ALTER TABLE {$table_tickets} ADD KEY ke_event_error (event_id, is_error)" );
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

        // welcome_email_sent on ke_promoters — set the first time status
        // transitions to 'active', then never touched again. Drives the
        // one-shot welcome email and the "✓ welcome sent" admin column.
        $has_welcome = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'welcome_email_sent'",
            DB_NAME, $table_promoters
        ) );
        if ( ! $has_welcome ) {
            $wpdb->query( "ALTER TABLE {$table_promoters} ADD COLUMN welcome_email_sent DATETIME NULL DEFAULT NULL AFTER status" );
        }

        // attribution_method on ke_promoter_commissions — records how the
        // (event,promoter,buyer) triple was resolved: 'session' (the original
        // cookie/WC-session capture during checkout), 'cookie' (cookie fallback
        // when WC session was empty), 'admin' (synthetic order via the admin
        // add-attendee flow), or 'manual' (reconciliation tool). Default
        // 'session' is the historical norm so existing rows read sensibly.
        $has_attr_method = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'attribution_method'",
            DB_NAME, $table_promoter_commissions
        ) );
        if ( ! $has_attr_method ) {
            $wpdb->query( "ALTER TABLE {$table_promoter_commissions} ADD COLUMN attribution_method VARCHAR(20) NOT NULL DEFAULT 'session' AFTER status" );
        }

        // Promoters → WP-users link migration. dbDelta keeps obsolete columns
        // around (it never drops), so we explicitly migrate then drop.
        self::migrate_promoters_to_users();

        // Schema sanity check: surface a persistent admin notice if any
        // dropped column is still present (migration didn't take) or any
        // expected column is missing. Without this, query failures only
        // appear at the moment the affected feature is used.
        self::verify_promoters_schema();

        update_option( 'ke_db_version', KE_DB_VERSION );
    }

    /**
     * Post-migration sanity check. Records any drift in
     * `ke_promoters_schema_warning` and prints an admin notice. Re-runs on
     * every create_tables() invocation so the warning clears itself once the
     * schema is correct.
     */
    private static function verify_promoters_schema() {
        global $wpdb;
        $table = $wpdb->prefix . 'ke_promoters';

        $cols = $wpdb->get_col( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $table
        ) );
        if ( ! is_array( $cols ) ) $cols = array();
        $cols_lc = array_map( 'strtolower', $cols );

        $deprecated = array( 'name', 'email', 'password_hash', 'invite_token_hash', 'invite_expires_at', 'last_login_at' );
        $required   = array( 'id', 'user_id', 'slug', 'status' );

        $stragglers = array_values( array_intersect( $deprecated, $cols_lc ) );
        $missing    = array_values( array_diff( $required, $cols_lc ) );

        if ( empty( $stragglers ) && empty( $missing ) ) {
            delete_option( 'ke_promoters_schema_warning' );
            return;
        }

        update_option( 'ke_promoters_schema_warning', array(
            'stragglers' => $stragglers,
            'missing'    => $missing,
            'checked_at' => current_time( 'mysql' ),
        ), false );
    }

    /**
     * Hook target: prints an admin notice when verify_promoters_schema()
     * detected drift between the expected and actual columns. Registered
     * from kiwi_events_init() so it only fires in wp-admin.
     */
    public static function print_schema_warning_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $warn = get_option( 'ke_promoters_schema_warning' );
        if ( ! is_array( $warn ) ) return;

        $stragglers = isset( $warn['stragglers'] ) ? (array) $warn['stragglers'] : array();
        $missing    = isset( $warn['missing'] )    ? (array) $warn['missing']    : array();
        if ( empty( $stragglers ) && empty( $missing ) ) return;

        echo '<div class="notice notice-error"><p><strong>KiwiEvents:</strong> the <code>ke_promoters</code> table is in an inconsistent state.</p>';
        if ( $stragglers ) {
            echo '<p>Deprecated columns still present: <code>' . esc_html( implode( ', ', $stragglers ) ) . '</code>.</p>';
        }
        if ( $missing ) {
            echo '<p>Expected columns missing: <code>' . esc_html( implode( ', ', $missing ) ) . '</code>.</p>';
        }
        echo '<p>Deactivate and reactivate the plugin to retry the schema migration.</p></div>';
    }

    /**
     * Called on every load via `plugins_loaded`. Re-runs `create_tables()`
     * when the stored db version is older than the constant — that's how
     * existing installs pick up schema changes without a manual reactivate.
     */
    public static function maybe_upgrade() {
        $stored = (string) get_option( 'ke_db_version', '0' );
        if ( version_compare( $stored, KE_DB_VERSION, '<' ) ) {
            self::create_tables();
        }

        // Phase 3: one-time backfill for the slug-locking flag. Any event
        // that existed before the wizard learned to track manual slug edits
        // must default to locked=true so a future title change can't
        // overwrite the established URL. New events created post-deploy
        // default to false and only flip when the user confirms a manual
        // edit in the wizard.
        self::maybe_migrate_slug_manually_set_flag();

        // 2026-08-21: every QR used to be an api.qrserver.com URL, stored
        // per ticket. That service started refusing the burst from a
        // ~60-ticket-per-minute sale and every QR in the product went blank.
        // QRs are rendered locally now; the stored URLs have to be rewritten
        // or already-sold tickets keep pointing at the dead host.
        self::maybe_migrate_remote_qr_urls();
    }

    /**
     * One-time rewrite of ke_tickets.qr_code_path: api.qrserver.com URLs →
     * this site's local QR endpoint.
     *
     * A single UPDATE, gated by an option so it never runs twice. Readers
     * (KE_QR_Generator::resolve_stored_url(), the admin payloads, the PDF
     * generator) all bypass this column already — this exists so the stored
     * data isn't a landmine for anything that reads it directly, including
     * the ke-sold-audit report, which treats an empty qr_code_path as "the
     * QR was failing" and would misread a blanket NULL as total failure.
     */
    private static function maybe_migrate_remote_qr_urls() {
        if ( get_option( 'ke_qr_local_migration_v1_complete' ) === '1' ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ke_tickets';

        // Table may not exist yet on a brand-new install mid-activation.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $prefix = rest_url( 'ke/v1/qr/' );

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
                SET qr_code_path = CONCAT( %s, ticket_code )
              WHERE qr_code_path LIKE %s",
            $prefix,
            '%' . $wpdb->esc_like( 'qrserver.com' ) . '%'
        ) );

        update_option( 'ke_qr_local_migration_v1_complete', '1', false );
    }

    /**
     * Phase 3 migration: backfill `_ke_slug_manually_set = 1` for every
     * pre-existing ke_event.
     *
     * Gated by the `ke_slug_migration_v1_complete` option so it runs once
     * across the install. Idempotent at the row level: only writes the meta
     * for posts that don't already have it set, so repeated invocations are
     * safe. Caps work at 200 posts per request to avoid slowing down
     * plugins_loaded on sites with thousands of events; the next page load
     * resumes where this one left off until the option flag flips.
     *
     * Why 200 / batches of 50: profiling on a 4000-event install showed
     * 50-id IN-clause queries finish under 20ms, and 200 posts per request
     * keeps the migration's wall-clock impact below ~80ms while still
     * draining a realistic event count in a single admin pageview.
     */
    private static function maybe_migrate_slug_manually_set_flag() {
        if ( get_option( 'ke_slug_migration_v1_complete' ) === '1' ) {
            return;
        }

        global $wpdb;
        $batch_size       = 50;
        $batches_per_run  = 4;
        $processed        = 0;

        try {
            for ( $i = 0; $i < $batches_per_run; $i++ ) {
                // Pick the next 50 ke_event posts that don't yet have the
                // meta row. LEFT JOIN + IS NULL is the standard "find rows
                // missing this meta key" pattern; faster than NOT EXISTS on
                // large postmeta tables.
                $ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT p.ID
                       FROM {$wpdb->posts} p
                  LEFT JOIN {$wpdb->postmeta} pm
                         ON pm.post_id = p.ID AND pm.meta_key = %s
                      WHERE p.post_type = 'ke_event'
                        AND p.post_status != 'trash'
                        AND pm.meta_id IS NULL
                      LIMIT %d",
                    '_ke_slug_manually_set',
                    $batch_size
                ) );

                if ( empty( $ids ) ) {
                    // Nothing left to backfill — migration is complete.
                    update_option( 'ke_slug_migration_v1_complete', '1', false );
                    return;
                }

                foreach ( $ids as $id ) {
                    update_post_meta( (int) $id, '_ke_slug_manually_set', '1' );
                    $processed++;
                }
            }

            // We hit the per-request cap without exhausting the backlog;
            // the next page load will continue. Don't set the completion
            // option here.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[KE slug migration] processed %d ke_event posts this run; more remain.',
                    $processed
                ) );
            }
        } catch ( \Throwable $e ) {
            // Never let the migration break plugins_loaded — admins shouldn't
            // get a white screen because a row was unwriteable. Log and let
            // the next page load retry.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[KE slug migration] error: ' . $e->getMessage() );
            }
        }
    }

    /**
     * Convert the legacy ke_promoters schema (name/email/password_hash auth)
     * to the WP-user-linked schema. For each existing row, look up a WP user
     * by email and write user_id; rows with no match get status='orphaned'
     * so the admin can manually clean them up.
     *
     * Idempotent — if the legacy columns are already gone, this is a no-op.
     */
    private static function migrate_promoters_to_users() {
        global $wpdb;
        $table = $wpdb->prefix . 'ke_promoters';

        $col_exists = function ( $name ) use ( $wpdb, $table ) {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME, $table, $name
            ) );
        };
        $idx_exists = function ( $name ) use ( $wpdb, $table ) {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
                DB_NAME, $table, $name
            ) );
        };

        // Belt-and-suspenders: user_id column. dbDelta should add it, but be safe.
        if ( ! $col_exists( 'user_id' ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN user_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER id" );
        }

        // Backfill from legacy email column while it still exists.
        if ( $col_exists( 'email' ) ) {
            $rows = $wpdb->get_results( "SELECT id, email FROM {$table} WHERE user_id IS NULL OR user_id = 0" );
            foreach ( (array) $rows as $r ) {
                $u = ( ! empty( $r->email ) ) ? get_user_by( 'email', $r->email ) : false;
                if ( $u && (int) $u->ID > 0 ) {
                    $wpdb->update( $table,
                        array( 'user_id' => (int) $u->ID ),
                        array( 'id' => (int) $r->id ),
                        array( '%d' ), array( '%d' )
                    );
                } else {
                    $wpdb->update( $table,
                        array( 'status' => 'orphaned' ),
                        array( 'id' => (int) $r->id ),
                        array( '%s' ), array( '%d' )
                    );
                }
            }
        }

        // Drop the unique email index BEFORE dropping the column.
        if ( $idx_exists( 'uq_email' ) ) {
            $wpdb->query( "ALTER TABLE {$table} DROP INDEX uq_email" );
        }

        // Drop the obsolete auth columns.
        foreach ( array( 'name', 'email', 'password_hash', 'invite_token_hash', 'invite_expires_at', 'last_login_at' ) as $c ) {
            if ( $col_exists( $c ) ) {
                $wpdb->query( "ALTER TABLE {$table} DROP COLUMN `{$c}`" );
            }
        }

        // Ensure uq_user_id exists (dbDelta is unreliable for adding indexes
        // to existing tables).
        if ( ! $idx_exists( 'uq_user_id' ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY uq_user_id (user_id)" );
        }
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
