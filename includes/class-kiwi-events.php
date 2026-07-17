<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Main orchestrator class for KiwiEvents
 */
class Kiwi_Events {

    /** @var KE_Post_Types */
    private $post_types;

    /** @var KE_Event_Slug */
    private $event_slug;

    /** @var KE_Shortcodes */
    private $shortcodes;

    /** @var KE_Admin */
    private $admin;

    /** @var KE_Public */
    private $public_facing;

    /** @var KE_Rest_API */
    private $rest_api;

    /** @var KE_WooCommerce */
    private $woocommerce;

    /** @var KE_Scanner */
    private $scanner;

    /** @var KE_Scanner_Password */
    private $scanner_password;

    /** @var KE_Organizer_Dashboard */
    private $organizer_dashboard;

    /** @var KE_Organizer_Public */
    private $organizer_public;

    /** @var KE_Promoter_Attribution */
    private $promoter_attribution;

    /** @var KE_Promoter_Portal */
    private $promoter_portal;

    /** @var KE_Admin_Promoters */
    private $admin_promoters;

    /** @var KE_Tickets_Wallet */
    private $tickets_wallet;

    /** @var KE_Board */
    private $board;

    /** @var KE_Admin_Board */
    private $admin_board;

    /**
     * Initialize all components
     */
    public function run() {
        // Run DB migrations if needed
        if ( get_option( 'ke_db_version' ) !== KE_DB_VERSION ) {
            KE_Activator::activate();
        }

        // Core
        $this->post_types = new KE_Post_Types();
        $this->post_types->init();

        // Editable event slug + slug-history 301 redirects
        $this->event_slug = new KE_Event_Slug();
        $this->event_slug->init();

        // Category-filtered event shortcodes
        $this->shortcodes = new KE_Shortcodes();
        $this->shortcodes->init();

        // Promoter attribution: capture ?ke_promo on inbound traffic.
        // Runs on every front-end request (skips REST/admin/cron internally).
        $this->promoter_attribution = new KE_Promoter_Attribution();
        $this->promoter_attribution->init();

        // Promoter portal at /promoter/{slug} — rewrite rules + auth handlers.
        // Always boot: the admin-post handlers must register on every request,
        // and the rewrite-rule registration is idempotent.
        $this->promoter_portal = new KE_Promoter_Portal();
        $this->promoter_portal->init();

        // Admin
        if ( is_admin() ) {
            $this->admin = new KE_Admin();
            $this->admin->init();

            // Promoters admin module — only register the admin-post handlers
            // when we're in wp-admin.
            $this->admin_promoters = new KE_Admin_Promoters();
            $this->admin_promoters->init();
        }

        // Public
        $this->public_facing = new KE_Public();
        $this->public_facing->init();

        // Customer ticket wallet — [kiwi_tickets_purchase] + gated PDF endpoint
        $this->tickets_wallet = new KE_Tickets_Wallet();
        $this->tickets_wallet->init();

        // Community board — [kiwi_board] / [kiwi_create_board] + moderation.
        // The admin module always boots: its admin_post approve/reject
        // handlers must register on every admin-context request.
        $this->board = new KE_Board();
        $this->board->init();
        $this->admin_board = new KE_Admin_Board();
        $this->admin_board->init();

        // REST API
        $this->rest_api = new KE_Rest_API();
        add_action( 'rest_api_init', array( $this->rest_api, 'register_routes' ) );

        // Async email dispatch (scheduled by process_checkout for free tickets)
        add_action( 'ke_send_ticket_email', function( $order_id ) {
            $email = new KE_Email();
            $email->send_ticket_email( $order_id );
        } );

        // WooCommerce integration (only if WC is active)
        if ( class_exists( 'WooCommerce' ) ) {
            $this->woocommerce = new KE_WooCommerce();
            $this->woocommerce->init();
        }

        // Scanner
        $this->scanner = new KE_Scanner();
        $this->scanner->init();

        // Scanner password (organizer-level)
        $this->scanner_password = new KE_Scanner_Password();
        $this->scanner_password->init();

        // Organizer self-service dashboard at /organizer/{slug}
        $this->organizer_dashboard = new KE_Organizer_Dashboard();
        $this->organizer_dashboard->init();

        // Public organizer profile page at /organizers/{slug} (plural).
        $this->organizer_public = new KE_Organizer_Public();
        $this->organizer_public->init();
    }
}
