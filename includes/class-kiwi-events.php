<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Main orchestrator class for KiwiEvents
 */
class Kiwi_Events {

    /** @var KE_Post_Types */
    private $post_types;

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

        // Admin
        if ( is_admin() ) {
            $this->admin = new KE_Admin();
            $this->admin->init();
        }

        // Public
        $this->public_facing = new KE_Public();
        $this->public_facing->init();

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
