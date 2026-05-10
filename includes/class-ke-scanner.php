<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Scanner page — mobile-friendly QR ticket validation.
 *
 * Public route at /kiwi-scanner/. Security is enforced inside the page by the
 * organizer-password gate, which exchanges a password for a short-lived
 * session token; the validate endpoint authenticates that token.
 */
class KE_Scanner {

    public function init() {
        add_action( 'init', array( $this, 'add_rewrite_rules' ) );
        add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'handle_scanner_page' ) );
        add_action( 'template_redirect', array( $this, 'handle_verify_redirect' ) );
    }

    /**
     * Add rewrite rules for /kiwi-scanner/ and /ke-verify/{code}.
     */
    public function add_rewrite_rules() {
        add_rewrite_rule( '^kiwi-scanner/?$', 'index.php?ke_scanner=1', 'top' );
        add_rewrite_rule( '^ke-verify/([a-f0-9]+)/?$', 'index.php?ke_verify_code=$matches[1]', 'top' );

        // One-time flush whenever this rule set changes. Bump the version below
        // when add/remove a rule so existing installs pick it up automatically.
        if ( get_option( 'ke_scanner_rewrite_version', '' ) !== '1.4.0' ) {
            flush_rewrite_rules( false );
            update_option( 'ke_scanner_rewrite_version', '1.4.0' );
        }
    }

    /**
     * Register custom query vars.
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'ke_scanner';
        $vars[] = 'ke_verify_code';
        return $vars;
    }

    /**
     * Handle the /kiwi-scanner page request. Public access — the organizer
     * password gate inside the page is the access control.
     */
    public function handle_scanner_page() {
        if ( ! get_query_var( 'ke_scanner' ) ) {
            return;
        }

        show_admin_bar( false );
        include KE_PLUGIN_DIR . 'public/views/scanner.php';
        exit;
    }

    /**
     * Handle /ke-verify/{code} — show a basic ticket info page.
     */
    public function handle_verify_redirect() {
        $code = get_query_var( 'ke_verify_code' );
        if ( ! $code ) {
            return;
        }

        $tickets = new KE_Tickets();
        $ticket  = $tickets->get_by_code( $code );

        include KE_PLUGIN_DIR . 'public/views/ticket-info.php';
        exit;
    }
}
