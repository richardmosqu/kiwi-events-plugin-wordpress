<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Scanner page — mobile-friendly QR ticket validation
 */
class KE_Scanner {

    public function init() {
        add_action( 'init', array( $this, 'add_rewrite_rules' ) );
        add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'handle_scanner_page' ) );
        add_action( 'template_redirect', array( $this, 'handle_verify_redirect' ) );
    }

    /**
     * Add rewrite rules for /kiwi-scanner/ and /ke-verify/{code}
     */
    public function add_rewrite_rules() {
        add_rewrite_rule( '^kiwi-scanner/?$', 'index.php?ke_scanner=1', 'top' );
        add_rewrite_rule( '^ke-verify/([a-f0-9]+)/?$', 'index.php?ke_verify_code=$matches[1]', 'top' );
    }

    /**
     * Register custom query vars
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'ke_scanner';
        $vars[] = 'ke_verify_code';
        return $vars;
    }

    /**
     * Handle the scanner page request
     */
    public function handle_scanner_page() {
        if ( ! get_query_var( 'ke_scanner' ) ) {
            return;
        }

        // Check permissions
        if ( ! is_user_logged_in() || ! current_user_can( 'scan_ke_tickets' ) ) {
            wp_redirect( wp_login_url( home_url( '/kiwi-scanner/' ) ) );
            exit;
        }

        // Load scanner template
        include KE_PLUGIN_DIR . 'public/views/scanner.php';
        exit;
    }

    /**
     * Handle /ke-verify/{code} — redirect to scanner with code
     */
    public function handle_verify_redirect() {
        $code = get_query_var( 'ke_verify_code' );
        if ( ! $code ) {
            return;
        }

        // If user has scanner permissions, go to scanner page with auto-validate
        if ( is_user_logged_in() && current_user_can( 'scan_ke_tickets' ) ) {
            wp_redirect( home_url( '/kiwi-scanner/?validate=' . urlencode( $code ) ) );
            exit;
        }

        // Otherwise, show a basic ticket info page
        $tickets = new KE_Tickets();
        $ticket  = $tickets->get_by_code( $code );

        include KE_PLUGIN_DIR . 'public/views/ticket-info.php';
        exit;
    }
}
