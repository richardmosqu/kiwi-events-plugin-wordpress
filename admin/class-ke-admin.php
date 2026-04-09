<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin menu, page routing, and asset enqueueing
 */
class KE_Admin {

    public function init() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'current_screen', array( $this, 'redirect_to_custom_builder' ) );
        add_action( 'admin_post_ke_toggle_event_status', array( $this, 'handle_toggle_event_status' ) );
        add_action( 'admin_post_ke_add_category', array( $this, 'handle_add_category' ) );
        add_action( 'admin_post_ke_delete_category', array( $this, 'handle_delete_category' ) );
        add_action( 'admin_post_ke_add_organizer', array( $this, 'handle_add_organizer' ) );
        add_action( 'admin_post_ke_delete_organizer', array( $this, 'handle_delete_organizer' ) );
    }

    public function handle_toggle_event_status() {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ke_toggle_status_nonce' ) ) {
            wp_die('Security check failed.');
        }

        if ( ! current_user_can( 'manage_kiwi_events' ) ) {
            wp_die('Unauthorized access.');
        }

        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        if ( $event_id ) {
            $current_status = get_post_meta( $event_id, '_ke_event_status', true ) ?: 'active';
            $new_status = ( $current_status === 'active' ) ? 'paused' : 'active';
            update_post_meta( $event_id, '_ke_event_status', $new_status );
        }

        wp_redirect( admin_url('admin.php?page=ke-events-list') );
        exit;
    }

    public function handle_add_organizer() {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ke_add_organizer_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_kiwi_events' ) ) {
            wp_die( 'Unauthorized access.' );
        }

        $name    = isset( $_POST['organizer_name'] ) ? sanitize_text_field( $_POST['organizer_name'] ) : '';
        $logo_id = isset( $_POST['organizer_logo_id'] ) ? absint( $_POST['organizer_logo_id'] ) : 0;

        if ( $name ) {
            $term = wp_insert_term( $name, 'ke_organizer' );
            if ( ! is_wp_error( $term ) && $logo_id ) {
                update_term_meta( $term['term_id'], 'ke_organizer_logo', $logo_id );
            }
        }

        wp_redirect( admin_url( 'admin.php?page=ke-organizers' ) );
        exit;
    }

    public function handle_delete_organizer() {
        $term_id = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0;

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ke_delete_org_' . $term_id ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_kiwi_events' ) ) {
            wp_die( 'Unauthorized access.' );
        }

        if ( $term_id ) {
            delete_term_meta( $term_id, 'ke_organizer_logo' );
            wp_delete_term( $term_id, 'ke_organizer' );
        }

        wp_redirect( admin_url( 'admin.php?page=ke-organizers' ) );
        exit;
    }

    public function handle_add_category() {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ke_add_category_nonce' ) ) {
            wp_die('Security check failed.');
        }
        if ( ! current_user_can( 'manage_kiwi_events' ) ) {
            wp_die('Unauthorized access.');
        }

        $name = isset( $_POST['cat_name'] ) ? sanitize_text_field( $_POST['cat_name'] ) : '';
        if ( $name ) {
            wp_insert_term( $name, 'ke_event_category' );
        }
        
        wp_redirect( admin_url('admin.php?page=ke-categories') );
        exit;
    }

    public function handle_delete_category() {
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ke_delete_cat_' . $_GET['term_id'] ) ) {
            wp_die('Security check failed.');
        }
        if ( ! current_user_can( 'manage_kiwi_events' ) ) {
            wp_die('Unauthorized access.');
        }

        $term_id = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0;
        if ( $term_id ) {
            wp_delete_term( $term_id, 'ke_event_category' );
        }

        wp_redirect( admin_url('admin.php?page=ke-categories') );
        exit;
    }

    /**
     * Register admin menu pages
     */
    public function register_menu() {
        // Main menu
        add_menu_page(
            'KiwiEvents',
            'KiwiEvents',
            'manage_kiwi_events',
            'kiwi-events',
            array( $this, 'render_dashboard_page' ),
            'dashicons-calendar-alt',
            26
        );

        // Dashboard submenu
        add_submenu_page(
            'kiwi-events',
            'Dashboard',
            'Dashboard',
            'manage_kiwi_events',
            'kiwi-events',
            array( $this, 'render_dashboard_page' )
        );

        // Events (CPT) — link to the CPT admin page
        add_submenu_page(
            'kiwi-events',
            'Events',
            'Events',
            'manage_kiwi_events',
            'edit.php?post_type=ke_event'
        );

        // Add New Event
        add_submenu_page(
            'kiwi-events',
            'Add New Event',
            'Add New Event',
            'manage_kiwi_events',
            'ke-event-builder',
            array( $this, 'render_event_builder' )
        );

        // Custom Event List (Hidden from menu, or replace the default one)
        add_submenu_page(
            null, // Hide from menu since we intercept 'edit.php?post_type=ke_event'
            'All Events',
            'All Events',
            'manage_kiwi_events',
            'ke-events-list',
            array( $this, 'render_events_list' )
        );

        // Event Categories
        add_submenu_page(
            'kiwi-events',
            'Categories',
            'Categories',
            'manage_kiwi_events',
            'ke-categories',
            array( $this, 'render_categories_list' )
        );

        // Organizers (custom page)
        add_submenu_page(
            'kiwi-events',
            'Organizers',
            'Organizers',
            'manage_kiwi_events',
            'ke-organizers',
            array( $this, 'render_organizers_page' )
        );

        // Tags
        add_submenu_page(
            'kiwi-events',
            'Tags',
            'Tags',
            'manage_kiwi_events',
            'edit-tags.php?taxonomy=ke_event_tag&post_type=ke_event'
        );

        // Attendees
        add_submenu_page(
            'kiwi-events',
            'Attendees',
            'Attendees',
            'manage_kiwi_events',
            'kiwi-events-attendees',
            array( $this, 'render_attendees_page' )
        );

        // System Settings
        add_submenu_page(
            'kiwi-events',
            'Settings',
            'Settings',
            'manage_kiwi_events',
            'ke-settings',
            array( $this, 'render_settings_page' )
        );

        // Scanner shortcut
        add_submenu_page(
            'kiwi-events',
            'Ticket Scanner',
            'Scanner',
            'scan_ke_tickets',
            'kiwi-events-scanner',
            array( $this, 'render_scanner_link' )
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets( $hook ) {
        // Only load on our plugin pages
        $plugin_pages = array(
            'toplevel_page_kiwi-events',
            'kiwievents_page_kiwi-events-attendees',
            'kiwievents_page_kiwi-events-scanner',
            'admin_page_ke-events-list',        // null-parent → admin_page_ prefix
            'kiwievents_page_ke-event-builder', // event builder was missing entirely
            'kiwievents_page_ke-categories',
            'kiwievents_page_ke-organizers',
            'kiwievents_page_ke-settings',
        );

        $is_ke_page = in_array( $hook, $plugin_pages )
                      || ( isset( $_GET['post_type'] ) && $_GET['post_type'] === 'ke_event' )
                      || ( get_post_type() === 'ke_event' );

        if ( ! $is_ke_page ) {
            return;
        }

        // Admin CSS
        wp_enqueue_style(
            'ke-admin-css',
            KE_PLUGIN_URL . 'admin/css/ke-admin.css',
            array(),
            KE_VERSION
        );

        // Dashboard-specific assets
        if ( $hook === 'toplevel_page_kiwi-events' ) {
            // Chart.js
            wp_enqueue_script(
                'chartjs',
                'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
                array(),
                '4.4.0',
                true
            );

            wp_enqueue_script(
                'ke-admin-dashboard',
                KE_PLUGIN_URL . 'admin/js/ke-admin-dashboard.js',
                array( 'jquery', 'chartjs' ),
                KE_VERSION,
                true
            );

            wp_localize_script( 'ke-admin-dashboard', 'keAdmin', array(
                'restUrl' => esc_url_raw( rest_url( 'ke/v1/' ) ),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
            ) );
        }

        // General admin JS
        wp_enqueue_script(
            'ke-admin-js',
            KE_PLUGIN_URL . 'admin/js/ke-admin.js',
            array( 'jquery' ),
            KE_VERSION,
            true
        );

        wp_localize_script( 'ke-admin-js', 'keAdminData', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'restUrl' => esc_url_raw( rest_url( 'ke/v1/' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ) );
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        $dashboard = new KE_Admin_Dashboard();
        $dashboard->render();
    }

    /**
     * Render attendees page
     */
    public function render_attendees_page() {
        $attendees = new KE_Admin_Attendees();
        $attendees->render();
    }

    /**
     * Render scanner link page
     */
    public function render_scanner_link() {
        $scanner_url = home_url( '/kiwi-scanner/' );
        ?>
        <div class="wrap ke-wrap">
            <div class="ke-page-header">
                <div class="ke-page-header-left">
                    <h1>Ticket Scanner</h1>
                    <p>Open the scanner on any mobile device to check in attendees</p>
                </div>
            </div>
            <div class="ke-card ke-scanner-card">
                <p>Point your phone's camera at a ticket QR code to validate it instantly at the venue entrance.</p>
                <a href="<?php echo esc_url( $scanner_url ); ?>" target="_blank" class="ke-btn ke-btn-primary" style="font-size:15px; padding:14px 32px;">
                    Open Scanner
                </a>
                <p class="ke-muted" style="margin-top:16px;">
                    Scanner URL: <code style="font-size:11px; background:rgba(241,245,249,0.8); padding:3px 8px; border-radius:6px;"><?php echo esc_url( $scanner_url ); ?></code>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Redirect standard WP editor and list to our custom interfaces
     */
    public function redirect_to_custom_builder() {
        $screen = get_current_screen();
        if ( ! $screen ) return;

        // Redirect post edit
        if ( $screen->id === 'ke_event' && $screen->base === 'post' ) {
            $post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
            if ( $post_id ) {
                $url = admin_url( 'admin.php?page=ke-event-builder&event_id=' . $post_id );
                wp_redirect( $url );
                exit;
            }
        }

        // Redirect post list
        if ( $screen->id === 'edit-ke_event' && $screen->base === 'edit' ) {
            $url = admin_url( 'admin.php?page=ke-events-list' );
            wp_redirect( $url );
            exit;
        }

        // Redirect taxonomy tags
        if ( $screen->id === 'edit-ke_event_category' && $screen->base === 'edit-tags' ) {
            $url = admin_url( 'admin.php?page=ke-categories' );
            wp_redirect( $url );
            exit;
        }

        // Redirect organizer taxonomy to custom page
        if ( $screen->id === 'edit-ke_organizer' && $screen->base === 'edit-tags' ) {
            $url = admin_url( 'admin.php?page=ke-organizers' );
            wp_redirect( $url );
            exit;
        }
    }

    /**
     * Render the Event Builder View
     */
    public function render_event_builder() {
        require_once KE_PLUGIN_DIR . 'admin/views/event-builder.php';
    }

    /**
     * Render the Custom Event List View
     */
    public function render_events_list() {
        require_once KE_PLUGIN_DIR . 'admin/views/events-list.php';
    }

    /**
     * Render the Categories View
     */
    public function render_categories_list() {
        require_once KE_PLUGIN_DIR . 'admin/views/categories-list.php';
    }

    /**
     * Render the Organizers View
     */
    public function render_organizers_page() {
        wp_enqueue_media();
        require_once KE_PLUGIN_DIR . 'admin/views/organizers-list.php';
    }

    /**
     * Render Settings page
     */
    public function render_settings_page() {
        require_once KE_PLUGIN_DIR . 'admin/views/settings.php';
    }
}
