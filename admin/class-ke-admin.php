<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin menu, page routing, and asset enqueueing
 */
class KE_Admin {

    public function init() {
        // Streamed exports (CSV/PDF) must be intercepted before any admin
        // HTML is emitted, otherwise the download headers are ignored and the
        // file ends up appended to the middle of the rendered admin page.
        // render() runs far too late for that — hook admin_init instead.
        add_action( 'admin_init', array( $this, 'maybe_export_early' ) );
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_menu', array( $this, 'add_scanner_link' ), 99 );
        add_action( 'admin_footer', array( $this, 'scanner_link_new_tab' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'current_screen', array( $this, 'redirect_to_custom_builder' ) );
        add_action( 'admin_post_ke_toggle_event_status', array( $this, 'handle_toggle_event_status' ) );
        add_action( 'admin_post_ke_add_category', array( $this, 'handle_add_category' ) );
        add_action( 'admin_post_ke_delete_category', array( $this, 'handle_delete_category' ) );
        add_action( 'admin_post_ke_add_organizer', array( $this, 'handle_add_organizer' ) );
        add_action( 'admin_post_ke_delete_organizer', array( $this, 'handle_delete_organizer' ) );
        add_action( 'admin_post_ke_set_organizer_password', array( $this, 'handle_set_organizer_password' ) );
        add_action( 'admin_post_ke_save_organizer_profile', array( $this, 'handle_save_organizer_profile' ) );
        add_action( 'wp_ajax_ke_update_organizer_logo', array( $this, 'handle_ajax_update_organizer_logo' ) );

        // Term-edit screen: render a Profile Photo section + persist on save.
        add_action( 'ke_organizer_edit_form_fields',     array( $this, 'render_organizer_photo_field' ), 5, 2 );
        add_action( 'edited_ke_organizer',               array( $this, 'save_organizer_photo_field' ), 10, 2 );

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
            // Toggle between the canonical statuses. Deactivating sets 'draft'
            // (not the old non-canonical 'paused'): 'draft' is in the plugin's
            // canonical status vocabulary AND in the shortcode's hide set, so a
            // deactivated event actually disappears from public listings, which
            // is the point of the toggle. 'paused' was in neither list, so a
            // "deactivated" event kept showing.
            $current_status = get_post_meta( $event_id, '_ke_event_status', true ) ?: 'active';
            $new_status = ( $current_status === 'active' ) ? 'draft' : 'active';
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
        $pwd     = isset( $_POST['organizer_scanner_password'] ) ? trim( (string) $_POST['organizer_scanner_password'] ) : '';

        if ( $name ) {
            $term = wp_insert_term( $name, 'ke_organizer' );
            if ( ! is_wp_error( $term ) ) {
                if ( $logo_id ) {
                    update_term_meta( $term['term_id'], 'ke_organizer_logo', $logo_id );
                }
                if ( $pwd !== '' ) {
                    KE_Scanner_Password::set_organizer_password( $term['term_id'], $pwd );
                }
            }
        }

        wp_redirect( admin_url( 'admin.php?page=ke-organizers' ) );
        exit;
    }

    public function handle_set_organizer_password() {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ke_set_organizer_password_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized access.' );
        }

        $term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
        $term    = $term_id ? get_term( $term_id, 'ke_organizer' ) : null;
        if ( ! $term || is_wp_error( $term ) ) {
            wp_die( 'Invalid organizer.' );
        }

        $clear = ! empty( $_POST['organizer_scanner_password_clear'] );
        $pwd   = isset( $_POST['organizer_scanner_password'] ) ? trim( (string) $_POST['organizer_scanner_password'] ) : '';

        if ( $clear ) {
            KE_Scanner_Password::set_organizer_password( $term_id, '' );
        } elseif ( $pwd !== '' ) {
            KE_Scanner_Password::set_organizer_password( $term_id, $pwd );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=ke-organizers' ) );
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
            delete_term_meta( $term_id, 'ke_organizer_hero_image' );
            delete_term_meta( $term_id, 'ke_organizer_gallery_photo_ids' );
            delete_term_meta( $term_id, 'ke_organizer_category' );
            wp_delete_term( $term_id, 'ke_organizer' );
        }

        wp_redirect( admin_url( 'admin.php?page=ke-organizers' ) );
        exit;
    }

    /**
     * Save the public-profile fields for an organizer term: hero image,
     * category text, and gallery photo IDs. Called from the Profile modal
     * on the custom organizers list page.
     */
    public function handle_save_organizer_profile() {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ke_save_organizer_profile_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_kiwi_events' ) ) {
            wp_die( 'Unauthorized access.' );
        }

        $term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
        $term    = $term_id ? get_term( $term_id, 'ke_organizer' ) : null;
        if ( ! $term || is_wp_error( $term ) ) {
            wp_die( 'Invalid organizer.' );
        }

        // Hero image — single attachment ID (0 clears).
        $hero_id = isset( $_POST['hero_image_id'] ) ? absint( $_POST['hero_image_id'] ) : 0;
        if ( $hero_id ) {
            update_term_meta( $term_id, 'ke_organizer_hero_image', $hero_id );
        } else {
            delete_term_meta( $term_id, 'ke_organizer_hero_image' );
        }

        // Category — short free-text label, e.g. "Productora de eventos".
        $category = isset( $_POST['organizer_category'] ) ? sanitize_text_field( wp_unslash( $_POST['organizer_category'] ) ) : '';
        $category = mb_substr( $category, 0, 80 );
        if ( $category !== '' ) {
            update_term_meta( $term_id, 'ke_organizer_category', $category );
        } else {
            delete_term_meta( $term_id, 'ke_organizer_category' );
        }

        // Gallery — comma-separated attachment IDs in display order.
        $gallery_raw = isset( $_POST['gallery_photo_ids'] ) ? (string) $_POST['gallery_photo_ids'] : '';
        $ids = array_values( array_filter( array_map( 'absint', explode( ',', $gallery_raw ) ) ) );
        // Cap at 60 to avoid runaway term meta from a misclick in the
        // media library — generous enough for any real organizer.
        $ids = array_slice( $ids, 0, 60 );
        if ( ! empty( $ids ) ) {
            update_term_meta( $term_id, 'ke_organizer_gallery_photo_ids', $ids );
        } else {
            delete_term_meta( $term_id, 'ke_organizer_gallery_photo_ids' );
        }

        wp_safe_redirect( add_query_arg( 'profile_saved', '1', admin_url( 'admin.php?page=ke-organizers' ) ) );
        exit;
    }

    /**
     * AJAX endpoint for the in-card pencil-overlay logo editor on the
     * organizers list page. Accepts term_id + attachment_id, persists to
     * `ke_organizer_logo`, returns the new thumbnail URL so the front-end
     * can swap the <img> src without a full reload.
     *
     * Uses admin-ajax (not REST) so the existing wp_ajax_* pattern fits
     * with the rest of the admin handlers and the page's wp_create_nonce
     * works directly.
     */
    public function handle_ajax_update_organizer_logo() {
        check_ajax_referer( 'ke_update_organizer_logo', '_wpnonce' );
        if ( ! current_user_can( 'manage_kiwi_events' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
        }

        $term_id       = isset( $_POST['term_id'] )       ? absint( $_POST['term_id'] )       : 0;
        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

        $term = $term_id ? get_term( $term_id, 'ke_organizer' ) : null;
        if ( ! $term || is_wp_error( $term ) ) {
            wp_send_json_error( array( 'message' => 'Invalid organizer.' ), 400 );
        }

        if ( $attachment_id ) {
            update_term_meta( $term_id, 'ke_organizer_logo', $attachment_id );
        } else {
            delete_term_meta( $term_id, 'ke_organizer_logo' );
        }

        wp_send_json_success( array(
            'attachment_id' => $attachment_id,
            'thumb_url'     => $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'medium' ) : '',
        ) );
    }

    /**
     * Profile Photo section for the standalone term-edit screen
     * (edit-tags.php?taxonomy=ke_organizer&action=edit). Mirrors the same
     * meta key (`ke_organizer_logo`) the rest of the admin uses so the
     * card grid, public profile, and dashboard all reflect the change.
     */
    public function render_organizer_photo_field( $term, $taxonomy ) {
        $logo_id  = (int) get_term_meta( $term->term_id, 'ke_organizer_logo', true );
        $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
        wp_enqueue_media();
        wp_nonce_field( 'ke_save_organizer_photo_' . $term->term_id, '_ke_org_photo_nonce' );
        ?>
        <tr class="form-field ke-org-photo-row">
            <th scope="row"><label><?php esc_html_e( 'Profile Photo', 'kiwi-events' ); ?></label></th>
            <td>
                <div id="ke-org-photo-preview" style="width:120px;height:120px;border-radius:14px;overflow:hidden;background:#f1f5f9 center/cover no-repeat;border:1px solid #e2e8f0;margin-bottom:10px;<?php echo $logo_url ? 'background-image:url(' . esc_url( $logo_url ) . ');' : ''; ?>"></div>
                <input type="hidden" name="ke_organizer_logo_id" id="ke-org-photo-id" value="<?php echo esc_attr( $logo_id ); ?>">
                <button type="button" class="button" id="ke-org-photo-pick"><?php esc_html_e( 'Change photo', 'kiwi-events' ); ?></button>
                <button type="button" class="button" id="ke-org-photo-clear" <?php echo $logo_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove photo', 'kiwi-events' ); ?></button>
                <p class="description"><?php esc_html_e( 'This photo appears on the public organizer page (/organizers/{slug}) and in the dashboard.', 'kiwi-events' ); ?></p>
                <script>
                (function() {
                    var frame, btn = document.getElementById('ke-org-photo-pick'),
                        clr = document.getElementById('ke-org-photo-clear'),
                        prev = document.getElementById('ke-org-photo-preview'),
                        idIn = document.getElementById('ke-org-photo-id');
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        if (!frame) {
                            frame = wp.media({ title: '<?php echo esc_js( __( 'Choose profile photo', 'kiwi-events' ) ); ?>', button: { text: '<?php echo esc_js( __( 'Use this photo', 'kiwi-events' ) ); ?>' }, library: { type: 'image' }, multiple: false });
                            frame.on('select', function() {
                                var att = frame.state().get('selection').first().toJSON();
                                var url = (att.sizes && att.sizes.medium && att.sizes.medium.url) || att.url;
                                idIn.value = att.id;
                                prev.style.backgroundImage = 'url("' + url.replace(/"/g, '\\"') + '")';
                                clr.style.display = '';
                            });
                        }
                        frame.open();
                    });
                    clr.addEventListener('click', function(e) {
                        e.preventDefault();
                        idIn.value = '';
                        prev.style.backgroundImage = '';
                        clr.style.display = 'none';
                    });
                })();
                </script>
            </td>
        </tr>
        <?php
    }

    public function save_organizer_photo_field( $term_id, $tt_id ) {
        if ( ! isset( $_POST['_ke_org_photo_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['_ke_org_photo_nonce'], 'ke_save_organizer_photo_' . $term_id ) ) return;
        if ( ! current_user_can( 'manage_kiwi_events' ) ) return;

        $logo_id = isset( $_POST['ke_organizer_logo_id'] ) ? absint( $_POST['ke_organizer_logo_id'] ) : 0;
        if ( $logo_id ) {
            update_term_meta( $term_id, 'ke_organizer_logo', $logo_id );
        } else {
            delete_term_meta( $term_id, 'ke_organizer_logo' );
        }
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
        // Custom kiwi-slice silhouette icon. Base64-encoded SVG so WP applies
        // its admin-color tint via CSS mask (single-color shape required).
        $kiwi_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="black"><path d="M10 1.5C5.3 1.5 1.5 5.3 1.5 10s3.8 8.5 8.5 8.5 8.5-3.8 8.5-8.5S14.7 1.5 10 1.5zm0 1.5c3.9 0 7 3.1 7 7s-3.1 7-7 7-7-3.1-7-7 3.1-7 7-7z"/><ellipse cx="10" cy="10" rx="0.75" ry="0.75"/><ellipse cx="10" cy="5.2" rx="0.35" ry="0.6"/><ellipse cx="10" cy="14.8" rx="0.35" ry="0.6"/><ellipse cx="5.2" cy="10" rx="0.6" ry="0.35"/><ellipse cx="14.8" cy="10" rx="0.6" ry="0.35"/><circle cx="6.6" cy="6.6" r="0.45"/><circle cx="13.4" cy="6.6" r="0.45"/><circle cx="6.6" cy="13.4" r="0.45"/><circle cx="13.4" cy="13.4" r="0.45"/></svg>';
        $kiwi_icon = 'data:image/svg+xml;base64,' . base64_encode( $kiwi_svg );

        // Main menu
        add_menu_page(
            'KiwiEvents',
            'KiwiEvents',
            'manage_kiwi_events',
            'kiwi-events',
            array( $this, 'render_dashboard_page' ),
            $kiwi_icon,
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

        // Promoters
        add_submenu_page(
            'kiwi-events',
            'Promoters',
            'Promoters',
            'manage_kiwi_events',
            'ke-promoters',
            array( $this, 'render_promoters_page' )
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

        // Reservations
        add_submenu_page(
            'kiwi-events',
            'Reservations',
            'Reservations',
            'manage_kiwi_events',
            'kiwi-events-reservations',
            array( $this, 'render_reservations_page' )
        );

        // Community board moderation queue — hidden entirely when the board
        // system is disabled in Settings → Board. The pending-count bubble
        // reuses core's awaiting-mod classes so it looks native.
        if ( class_exists( 'KE_Board' ) && KE_Board::is_enabled() ) {
            $board_pending = KE_Board::pending_count();
            $board_title   = 'Board';
            if ( $board_pending > 0 ) {
                $board_title .= ' <span class="awaiting-mod count-' . esc_attr( $board_pending ) . '"><span class="pending-count">'
                    . esc_html( number_format_i18n( $board_pending ) ) . '</span></span>';
            }
            add_submenu_page(
                'kiwi-events',
                'Board',
                $board_title,
                'manage_kiwi_events',
                'ke-board',
                array( $this, 'render_board_page' )
            );
        }

        // System Settings
        add_submenu_page(
            'kiwi-events',
            'Settings',
            'Settings',
            'manage_kiwi_events',
            'ke-settings',
            array( $this, 'render_settings_page' )
        );

    }

    /**
     * Add a "Scanner" submenu entry that opens the public /kiwi-scanner/
     * route in a new tab. We push directly into the $submenu global so the
     * URL is the public URL rather than admin.php?page=...; the new-tab
     * behavior is layered on by `scanner_link_new_tab()` in admin_footer.
     */
    public function add_scanner_link() {
        global $submenu;
        if ( ! current_user_can( 'manage_kiwi_events' ) ) return;
        $submenu['kiwi-events'][] = array(
            __( 'Scanner', 'kiwi-events' ),
            'manage_kiwi_events',
            esc_url( home_url( '/kiwi-scanner/' ) ),
        );
    }

    /**
     * Force the Scanner submenu link to open in a new tab. Runs at the end
     * of admin pages, so the DOM is in place when the script executes.
     */
    public function scanner_link_new_tab() {
        $url = esc_js( home_url( '/kiwi-scanner/' ) );
        ?>
        <script>
        (function(){
            var url = <?php echo wp_json_encode( home_url( '/kiwi-scanner/' ) ); ?>;
            document.querySelectorAll('#adminmenu a[href="' + url + '"]').forEach(function(a){
                a.setAttribute('target', '_blank');
                a.setAttribute('rel', 'noopener');
            });
        })();
        </script>
        <?php
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets( $hook ) {
        // Only load on our plugin pages
        $plugin_pages = array(
            'toplevel_page_kiwi-events',
            'kiwievents_page_kiwi-events-attendees',
            'kiwievents_page_kiwi-events-reservations',
            'admin_page_ke-events-list',        // null-parent → admin_page_ prefix
            'kiwievents_page_ke-event-builder', // event builder was missing entirely
            'kiwievents_page_ke-categories',
            'kiwievents_page_ke-organizers',
            'kiwievents_page_ke-promoters',
            'kiwievents_page_ke-board',
            'kiwievents_page_ke-settings',
        );

        $is_ke_taxonomy = isset( $_GET['taxonomy'] ) && in_array(
            $_GET['taxonomy'],
            array( 'ke_event_category', 'ke_event_tag', 'ke_organizer' ),
            true
        );

        $is_ke_page = in_array( $hook, $plugin_pages )
                      || ( isset( $_GET['post_type'] ) && $_GET['post_type'] === 'ke_event' )
                      || ( get_post_type() === 'ke_event' )
                      || $is_ke_taxonomy;

        if ( ! $is_ke_page ) {
            return;
        }

        // Kiwi brand design tokens — must load BEFORE every other KE admin
        // stylesheet so they can reference --kiwi-* custom properties.
        $ke_tokens_ver = defined( 'KE_TOKENS_ASSETS_VER' ) ? KE_TOKENS_ASSETS_VER : KE_VERSION;
        wp_enqueue_style(
            'ke-admin-tokens',
            KE_PLUGIN_URL . 'admin/css/ke-admin-tokens.css',
            array(),
            $ke_tokens_ver
        );

        // Dedicated cache-bust for general admin stylesheets so the Kiwi-brand
        // rollout can churn CSS without bumping the whole plugin version.
        $ke_admin_css_ver = defined( 'KE_ADMIN_CSS_VER' ) ? KE_ADMIN_CSS_VER : KE_VERSION;

        // Admin CSS
        wp_enqueue_style(
            'ke-admin-css',
            KE_PLUGIN_URL . 'admin/css/ke-admin.css',
            array( 'ke-admin-tokens' ),
            $ke_admin_css_ver
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
                KE_ADMIN_JS_VER,
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

        // Attendees-specific assets (list page with inline dropdown, modals, bulk)
        if ( $hook === 'kiwievents_page_kiwi-events-attendees' ) {
            wp_enqueue_style(
                'ke-attendees-css',
                KE_PLUGIN_URL . 'admin/css/ke-attendees.css',
                array( 'ke-admin-css' ),
                $ke_admin_css_ver
            );
            wp_enqueue_script(
                'ke-attendees-js',
                KE_PLUGIN_URL . 'admin/js/ke-attendees.js',
                array(),
                KE_VERSION,
                true
            );
            wp_localize_script( 'ke-attendees-js', 'keAttendeesData', array(
                'restUrl' => esc_url_raw( rest_url( 'ke/v1/' ) ),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
            ) );
        }

        // Reservations admin page — reuses ke-attendees.css for table/modal
        // chrome and layers a thin reservations stylesheet on top for the
        // status pills and contact subtext.
        if ( $hook === 'kiwievents_page_kiwi-events-reservations' ) {
            wp_enqueue_style(
                'ke-attendees-css',
                KE_PLUGIN_URL . 'admin/css/ke-attendees.css',
                array( 'ke-admin-css' ),
                $ke_admin_css_ver
            );
            wp_enqueue_style(
                'ke-admin-reservations-css',
                KE_PLUGIN_URL . 'admin/css/ke-admin-reservations.css',
                array( 'ke-attendees-css' ),
                $ke_admin_css_ver
            );
            wp_enqueue_script(
                'ke-admin-reservations-js',
                KE_PLUGIN_URL . 'admin/js/ke-admin-reservations.js',
                array(),
                KE_VERSION,
                true
            );
            wp_localize_script( 'ke-admin-reservations-js', 'keAdminResvData', array(
                'restUrl' => esc_url_raw( rest_url( 'ke/v1/' ) ),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
            ) );
        }

        // Board moderation page — tokens-only stylesheet (dark-mode safe)
        // plus the modal/toggle behaviors.
        if ( $hook === 'kiwievents_page_ke-board' ) {
            wp_enqueue_style(
                'ke-admin-board-css',
                KE_PLUGIN_URL . 'admin/css/ke-admin-board.css',
                array( 'ke-admin-css' ),
                $ke_admin_css_ver
            );
            $ke_admin_js_ver = defined( 'KE_ADMIN_JS_VER' ) ? KE_ADMIN_JS_VER : KE_VERSION;
            wp_enqueue_script(
                'ke-admin-board-js',
                KE_PLUGIN_URL . 'admin/js/ke-admin-board.js',
                array(),
                $ke_admin_js_ver,
                true
            );
        }
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        $dashboard = new KE_Admin_Dashboard();
        $dashboard->render();
    }

    /**
     * Intercept streamed exports on admin_init, before any admin HTML output.
     *
     * The Attendees and Reservations pages both offer CSV/PDF downloads that
     * send their own Content-Disposition headers and stream the file. Those
     * headers only work if nothing has been printed yet, so we can't wait for
     * the page's render() callback (which fires after the admin <head>, menu,
     * and notices are already on the wire). This runs early, matches on the
     * page + export flag, and streams + exits before WordPress renders the
     * chrome.
     */
    public function maybe_export_early() {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

        if ( $page === 'kiwi-events-attendees'
            && isset( $_GET['ke_export_csv'] ) && $_GET['ke_export_csv'] === '1' ) {
            ( new KE_Admin_Attendees() )->export_csv(); // streams + exits
            return;
        }

        if ( $page === 'kiwi-events-reservations' ) {
            if ( isset( $_GET['ke_export_csv'] ) && $_GET['ke_export_csv'] === '1' ) {
                ( new KE_Admin_Reservations() )->export_csv(); // streams + exits
                return;
            }
            if ( isset( $_GET['ke_export_pdf'] ) && $_GET['ke_export_pdf'] === '1' ) {
                ( new KE_Admin_Reservations() )->export_pdf(); // streams + exits
                return;
            }
        }
    }

    /**
     * Render attendees page
     */
    public function render_attendees_page() {
        $attendees = new KE_Admin_Attendees();
        $attendees->render();
    }

    /**
     * Render reservations page
     */
    public function render_reservations_page() {
        $reservations = new KE_Admin_Reservations();
        $reservations->render();
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
        wp_enqueue_media();
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
     * Render the Promoters page. The module owns its own routing
     * (list vs edit form) based on the `action` query param.
     */
    public function render_promoters_page() {
        $module = new KE_Admin_Promoters();
        $module->render();
    }

    /**
     * Render Settings page
     */
    public function render_settings_page() {
        require_once KE_PLUGIN_DIR . 'admin/views/settings.php';
    }

    /**
     * Render the Board moderation queue.
     */
    public function render_board_page() {
        $module = new KE_Admin_Board();
        $module->render();
    }
}
