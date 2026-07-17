<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Community board ("HUB" / whiteboard) — user-submitted activities.
 *
 * A separate CPT (`ke_board_event`) with NO ticketing/checkout/fees.
 * Interaction model: likes (custom table with a UNIQUE index) and
 * native WordPress comments (logged-in only). Submissions land as
 * `pending` and only go public after an admin approves them.
 *
 * Shortcodes: [kiwi_board] (public carousel), [kiwi_create_board]
 * (submission form, login required).
 *
 * Canonical date storage (enforced on BOTH save paths, unlike the
 * mixed formats in ke_event):
 *   _ke_board_date      Y-m-d
 *   _ke_board_time      H:i
 *   _ke_board_datetime  Y-m-d H:i:s   (combined; used for ordering)
 */
class KE_Board {

    const POST_TYPE = 'ke_board_event';
    const OPTION    = 'ke_board_settings';

    /** Upload allowlist — SVG deliberately excluded (stored-XSS vector). */
    const ALLOWED_MIMES = array(
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png'          => 'image/png',
        'webp'         => 'image/webp',
    );

    public function init() {
        add_action( 'init', array( $this, 'register_post_type' ) );
        // Rewrite flushing is centralized: kiwi_events_maybe_flush_rewrites()
        // (init 99, gated on KE_REWRITE_VERSION in kiwi-events.php).
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );

        add_shortcode( 'kiwi_board', array( $this, 'render_board' ) );
        add_shortcode( 'kiwi_create_board', array( $this, 'render_create_form' ) );

        add_filter( 'single_template', array( $this, 'override_single_template' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_single_assets' ) );

        // Admin metabox so admins can review/fix a submission before approving.
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
        add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_admin_meta' ), 10, 2 );

        // Server-side comment gate: a logged-out POST must be rejected, not
        // just hidden by the UI.
        add_filter( 'preprocess_comment', array( $this, 'require_login_to_comment' ) );
    }

    /* ─────────────────────────────────────────────
     *  Settings
     * ───────────────────────────────────────────── */

    public static function get_settings() {
        $saved = get_option( self::OPTION, array() );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), array(
            'enabled'              => true,
            'max_daily'            => 3,
            'max_gallery'          => 5,
            'max_file_mb'          => 5,
            'trending_threshold'   => 20,
            'notify_admin_new'     => true,
            'notify_user_received' => true,
            'notify_user_decision' => true,
        ) );
    }

    public static function is_enabled() {
        $s = self::get_settings();
        return ! empty( $s['enabled'] );
    }

    /* ─────────────────────────────────────────────
     *  Post type
     * ───────────────────────────────────────────── */

    public function register_post_type() {
        register_post_type( self::POST_TYPE, array(
            'labels' => array(
                'name'          => 'Board',
                'singular_name' => 'Actividad del board',
                'edit_item'     => 'Editar actividad del board',
                'search_items'  => 'Buscar actividades',
                'not_found'     => 'No hay actividades',
            ),
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => false, // surfaced under the Kiwi Events menu
            'show_in_rest'       => true,
            'query_var'          => true,
            'rewrite'            => array( 'slug' => 'board' ),
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'supports'           => array( 'title', 'editor', 'thumbnail', 'author', 'comments' ),
        ) );
    }

    /* ─────────────────────────────────────────────
     *  Dates — canonical format, enforced on save
     * ───────────────────────────────────────────── */

    /**
     * Validate and normalize a date (Y-m-d) + time (H:i) pair.
     *
     * @return array|WP_Error array( date, time, datetime ) on success.
     */
    public static function normalize_datetime( $date_raw, $time_raw ) {
        $tz   = wp_timezone();
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d', trim( (string) $date_raw ), $tz );
        if ( ! $date instanceof DateTimeImmutable || $date->format( 'Y-m-d' ) !== trim( (string) $date_raw ) ) {
            return new WP_Error( 'ke_board_bad_date', 'La fecha no es válida (formato AAAA-MM-DD).' );
        }

        $time_raw = trim( (string) $time_raw );
        // <input type="time"> may send seconds on some platforms; drop them.
        if ( preg_match( '/^(\d{2}:\d{2}):\d{2}$/', $time_raw, $m ) ) {
            $time_raw = $m[1];
        }
        $time = DateTimeImmutable::createFromFormat( '!H:i', $time_raw, $tz );
        if ( ! $time instanceof DateTimeImmutable || $time->format( 'H:i' ) !== $time_raw ) {
            return new WP_Error( 'ke_board_bad_time', 'La hora no es válida (formato HH:MM).' );
        }

        return array(
            'date'     => $date->format( 'Y-m-d' ),
            'time'     => $time_raw,
            'datetime' => $date->format( 'Y-m-d' ) . ' ' . $time_raw . ':00',
        );
    }

    /**
     * A board activity is "over" after the END of its date (midnight, site
     * timezone) — an 8 PM activity stays visible through the evening and
     * drops off the next day. Fail-open: missing/unparseable dates are NOT
     * over (hiding valid content on a parse edge case is worse).
     */
    public static function is_over( $post_id ) {
        $date_raw = get_post_meta( (int) $post_id, '_ke_board_date', true );
        if ( ! is_string( $date_raw ) || '' === trim( $date_raw ) ) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d', trim( $date_raw ), wp_timezone() );
        if ( ! $date instanceof DateTimeImmutable ) {
            return false;
        }
        $today = ( function_exists( 'current_datetime' ) ? current_datetime() : new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
        return $date->format( 'Y-m-d' ) < $today;
    }

    /* ─────────────────────────────────────────────
     *  Likes
     * ───────────────────────────────────────────── */

    private static function likes_table() {
        global $wpdb;
        return $wpdb->prefix . 'ke_board_likes';
    }

    /** Recompute the cached count from the table (the table wins). */
    public static function refresh_like_count( $post_id ) {
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::likes_table() . ' WHERE post_id = %d',
            $post_id
        ) );
        update_post_meta( $post_id, '_ke_board_like_count', $count );
        return $count;
    }

    public static function get_like_count( $post_id ) {
        return (int) get_post_meta( (int) $post_id, '_ke_board_like_count', true );
    }

    public static function user_has_liked( $post_id, $user_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM ' . self::likes_table() . ' WHERE post_id = %d AND user_id = %d',
            $post_id, $user_id
        ) );
    }

    /* ─────────────────────────────────────────────
     *  Queries
     * ───────────────────────────────────────────── */

    /**
     * All visible (published, not-over) board events, soonest first.
     * IMPORTANT: fetches everything, filters in PHP, and only then may the
     * caller array_slice() to a display limit — never limit before filtering.
     */
    public static function get_visible_events() {
        // No meta_key in the query: that would INNER JOIN on the meta and
        // silently drop any published post missing _ke_board_datetime
        // (Quick Edit / programmatic inserts). Sort in PHP instead;
        // missing datetimes sort last.
        $query = new WP_Query( array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ) );

        $visible = array();
        foreach ( $query->posts as $post ) {
            if ( ! self::is_over( $post->ID ) ) {
                $visible[] = $post;
            }
        }

        usort( $visible, static function ( $a, $b ) {
            $da = (string) get_post_meta( $a->ID, '_ke_board_datetime', true );
            $db = (string) get_post_meta( $b->ID, '_ke_board_datetime', true );
            if ( '' === $da ) { $da = '9999-12-31 23:59:59'; }
            if ( '' === $db ) { $db = '9999-12-31 23:59:59'; }
            return strcmp( $da, $db );
        } );

        return $visible;
    }

    public static function pending_count() {
        $counts = wp_count_posts( self::POST_TYPE );
        return isset( $counts->pending ) ? (int) $counts->pending : 0;
    }

    /* ─────────────────────────────────────────────
     *  Shortcodes
     * ───────────────────────────────────────────── */

    private function enqueue_assets() {
        $ver = defined( 'KE_BOARD_ASSETS_VER' ) ? KE_BOARD_ASSETS_VER : KE_VERSION;
        wp_enqueue_style( 'ke-board-css', KE_PLUGIN_URL . 'public/css/ke-board.css', array( 'ke-public-css' ), $ver );
        wp_enqueue_script( 'ke-board-js', KE_PLUGIN_URL . 'public/js/ke-board.js', array(), $ver, true );
        wp_localize_script( 'ke-board-js', 'keBoard', array(
            'restUrl'  => esc_url_raw( rest_url( 'ke/v1/' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'loggedIn' => is_user_logged_in(),
            'loginUrl' => $this->login_url(),
        ) );
    }

    public function enqueue_single_assets() {
        if ( is_singular( self::POST_TYPE ) ) {
            $this->enqueue_assets();
        }
    }

    /** Login URL from Access Control settings, with redirect back. */
    private function login_url( $back_url = '' ) {
        $access = get_option( 'ke_access_settings', array() );
        if ( '' === $back_url ) {
            $back_url = is_singular() ? get_permalink() : home_url( add_query_arg( array() ) );
        }
        return ! empty( $access['login_url'] )
            ? add_query_arg( 'redirect_to', urlencode( $back_url ), $access['login_url'] )
            : wp_login_url( $back_url );
    }

    public function render_board( $atts = array() ) {
        if ( ! self::is_enabled() ) {
            return '';
        }
        $this->enqueue_assets();

        $atts  = shortcode_atts( array( 'limit' => 12, 'create_url' => '' ), $atts, 'kiwi_board' );
        $limit = max( 1, (int) $atts['limit'] );

        // Fetch → filter (inside get_visible_events) → THEN slice.
        $events    = array_slice( self::get_visible_events(), 0, $limit );
        $threshold = (int) self::get_settings()['trending_threshold'];

        ob_start();
        include KE_PLUGIN_DIR . 'public/views/board-carousel.php';
        return ob_get_clean();
    }

    public function render_create_form( $atts = array() ) {
        if ( ! self::is_enabled() ) {
            return '';
        }
        $this->enqueue_assets();

        $settings = self::get_settings();
        $gate     = null;
        if ( ! is_user_logged_in() ) {
            $access = get_option( 'ke_access_settings', array() );
            $gate   = array(
                'login_url'    => $this->login_url( get_permalink() ),
                'register_url' => ! empty( $access['register_url'] )
                    ? add_query_arg( 'redirect_to', urlencode( get_permalink() ), $access['register_url'] )
                    : '',
                'message'      => ! empty( $access['login_required_message'] )
                    ? $access['login_required_message']
                    : 'Inicia sesión para publicar una actividad en el board.',
            );
        }

        ob_start();
        include KE_PLUGIN_DIR . 'public/views/board-form.php';
        return ob_get_clean();
    }

    public function override_single_template( $template ) {
        global $post;
        if ( $post && self::POST_TYPE === $post->post_type ) {
            return KE_PLUGIN_DIR . 'public/views/single-board-event.php';
        }
        return $template;
    }

    /* ─────────────────────────────────────────────
     *  Comments — logged-in only, enforced server-side
     * ───────────────────────────────────────────── */

    public function require_login_to_comment( $commentdata ) {
        // Testimonials etc. use custom comment_type — only gate real comments
        // on board posts.
        $post = get_post( isset( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : 0 );
        if ( $post && self::POST_TYPE === $post->post_type && ! is_user_logged_in() ) {
            wp_die( 'Debes iniciar sesión para comentar.', 403 );
        }
        return $commentdata;
    }

    /* ─────────────────────────────────────────────
     *  REST — submit, like toggle, settings save
     * ───────────────────────────────────────────── */

    public function register_routes() {
        // NOTE: never bare PHP internals as callbacks — closures only (PHP 8
        // fatals with ArgumentCountError on the 3-arg invocation).
        register_rest_route( 'ke/v1', '/board/submit', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'handle_submit' ),
            'permission_callback' => 'is_user_logged_in',
        ) );

        register_rest_route( 'ke/v1', '/board/like/(?P<id>\d+)', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'handle_like_toggle' ),
            'permission_callback' => 'is_user_logged_in',
            'args'                => array(
                'id' => array(
                    'required'          => true,
                    'validate_callback' => static function ( $value ) {
                        return is_numeric( $value );
                    },
                    'sanitize_callback' => static function ( $value ) {
                        return absint( $value );
                    },
                ),
            ),
        ) );

        register_rest_route( 'ke/v1', '/settings/board', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'save_settings' ),
            'permission_callback' => static function () {
                return current_user_can( 'manage_kiwi_events' );
            },
        ) );
    }

    public function save_settings( WP_REST_Request $request ) {
        $params  = $request->get_json_params() ?: array();
        $current = self::get_settings();

        $new = array(
            'enabled'              => isset( $params['enabled'] ) ? rest_sanitize_boolean( $params['enabled'] ) : $current['enabled'],
            'max_daily'            => isset( $params['max_daily'] ) ? max( 1, absint( $params['max_daily'] ) ) : $current['max_daily'],
            'max_gallery'          => isset( $params['max_gallery'] ) ? max( 0, absint( $params['max_gallery'] ) ) : $current['max_gallery'],
            'max_file_mb'          => isset( $params['max_file_mb'] ) ? max( 1, absint( $params['max_file_mb'] ) ) : $current['max_file_mb'],
            'trending_threshold'   => isset( $params['trending_threshold'] ) ? max( 1, absint( $params['trending_threshold'] ) ) : $current['trending_threshold'],
            'notify_admin_new'     => isset( $params['notify_admin_new'] ) ? rest_sanitize_boolean( $params['notify_admin_new'] ) : $current['notify_admin_new'],
            'notify_user_received' => isset( $params['notify_user_received'] ) ? rest_sanitize_boolean( $params['notify_user_received'] ) : $current['notify_user_received'],
            'notify_user_decision' => isset( $params['notify_user_decision'] ) ? rest_sanitize_boolean( $params['notify_user_decision'] ) : $current['notify_user_decision'],
        );
        update_option( self::OPTION, $new );

        return rest_ensure_response( array( 'success' => true, 'board' => $new ) );
    }

    public function handle_like_toggle( WP_REST_Request $request ) {
        global $wpdb;

        if ( ! self::is_enabled() ) {
            return new WP_Error( 'ke_board_disabled', 'El board está deshabilitado.', array( 'status' => 403 ) );
        }

        $post_id = (int) $request['id'];
        $user_id = get_current_user_id();
        $post    = get_post( $post_id );

        if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
            return new WP_Error( 'ke_board_not_found', 'Actividad no encontrada.', array( 'status' => 404 ) );
        }

        $table = self::likes_table();
        if ( self::user_has_liked( $post_id, $user_id ) ) {
            $wpdb->delete( $table, array( 'post_id' => $post_id, 'user_id' => $user_id ), array( '%d', '%d' ) );
            $liked = false;
        } else {
            // The UNIQUE index rejects a racing duplicate insert; a failed
            // insert here just means "already liked" — never a fatal.
            $inserted = $wpdb->insert( $table, array(
                'post_id'    => $post_id,
                'user_id'    => $user_id,
                'created_at' => current_time( 'mysql' ),
            ), array( '%d', '%d', '%s' ) );
            $liked = true;
            if ( false === $inserted && ! self::user_has_liked( $post_id, $user_id ) ) {
                return new WP_Error( 'ke_board_like_failed', 'No se pudo registrar el like.', array( 'status' => 500 ) );
            }
        }

        $count = self::refresh_like_count( $post_id );

        return rest_ensure_response( array(
            'success' => true,
            'liked'   => $liked,
            'count'   => $count,
        ) );
    }

    /* ─────────────────────────────────────────────
     *  Submission
     * ───────────────────────────────────────────── */

    public function handle_submit( WP_REST_Request $request ) {
        if ( ! self::is_enabled() ) {
            return new WP_Error( 'ke_board_disabled', 'El board está deshabilitado.', array( 'status' => 403 ) );
        }

        $settings = self::get_settings();
        $user_id  = get_current_user_id();

        // ── Serialize this user's submissions (MySQL named lock) so N
        // parallel POSTs can't all pass the rate-limit check before any
        // post exists (TOCTOU). Released on every return path below.
        global $wpdb;
        $lock_name = 'ke_board_submit_' . $user_id;
        $got_lock  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
        if ( '1' !== (string) $got_lock ) {
            return new WP_Error( 'ke_board_busy', 'Ya hay un envío en curso. Intenta de nuevo en unos segundos.', array( 'status' => 429 ) );
        }
        try {
            return $this->process_submission( $request, $settings, $user_id );
        } finally {
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }
    }

    /**
     * The submission pipeline — runs while holding the per-user lock.
     *
     * @return WP_REST_Response|WP_Error
     */
    private function process_submission( WP_REST_Request $request, $settings, $user_id ) {
        // ── Rate limit: max N submissions per user per 24h ──
        $recent = new WP_Query( array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'any',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'date_query'     => array( array( 'after' => '24 hours ago' ) ),
        ) );
        if ( count( $recent->posts ) >= (int) $settings['max_daily'] ) {
            return new WP_Error(
                'ke_board_rate_limited',
                sprintf( 'Has alcanzado el límite de %d publicaciones por día. Intenta mañana.', (int) $settings['max_daily'] ),
                array( 'status' => 429 )
            );
        }

        // ── Required text fields ──
        $title       = sanitize_text_field( $request->get_param( 'title' ) ?? '' );
        $description = sanitize_textarea_field( $request->get_param( 'description' ) ?? '' );
        $location    = sanitize_text_field( $request->get_param( 'location' ) ?? '' );
        $university  = sanitize_text_field( $request->get_param( 'university' ) ?? '' );
        $organizer   = sanitize_text_field( $request->get_param( 'organizer' ) ?? '' );
        $contact     = sanitize_text_field( $request->get_param( 'contact' ) ?? '' );

        $required = array(
            'title'       => array( $title, 'El título es obligatorio.' ),
            'description' => array( $description, 'La descripción es obligatoria.' ),
            'location'    => array( $location, 'La ubicación es obligatoria.' ),
            'university'  => array( $university, 'La universidad es obligatoria.' ),
            'organizer'   => array( $organizer, 'El nombre de quien organiza es obligatorio.' ),
            'contact'     => array( $contact, 'El contacto es obligatorio.' ),
        );
        foreach ( $required as $field ) {
            if ( '' === $field[0] ) {
                return new WP_Error( 'ke_board_missing_field', $field[1], array( 'status' => 400 ) );
            }
        }

        $dt = self::normalize_datetime( $request->get_param( 'date' ), $request->get_param( 'time' ) );
        if ( is_wp_error( $dt ) ) {
            $dt->add_data( array( 'status' => 400 ) );
            return $dt;
        }

        // ── File validation (ALL files validated BEFORE creating the post) ──
        $files = $request->get_file_params();
        if ( empty( $files['main_image'] ) || empty( $files['main_image']['tmp_name'] ) ) {
            return new WP_Error( 'ke_board_missing_image', 'La imagen principal es obligatoria.', array( 'status' => 400 ) );
        }

        $gallery_keys = array();
        foreach ( array_keys( $files ) as $key ) {
            if ( 0 === strpos( $key, 'gallery_' ) && ! empty( $files[ $key ]['tmp_name'] ) ) {
                $gallery_keys[] = $key;
            }
        }
        if ( count( $gallery_keys ) > (int) $settings['max_gallery'] ) {
            return new WP_Error(
                'ke_board_too_many_images',
                sprintf( 'Máximo %d imágenes adicionales.', (int) $settings['max_gallery'] ),
                array( 'status' => 400 )
            );
        }

        $max_bytes = (int) $settings['max_file_mb'] * 1024 * 1024;
        foreach ( array_merge( array( 'main_image' ), $gallery_keys ) as $key ) {
            $check = $this->validate_image_file( $files[ $key ], $max_bytes );
            if ( is_wp_error( $check ) ) {
                return $check;
            }
        }

        // ── Create the post (pending — nothing goes public without approval) ──
        $post_id = wp_insert_post( array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'pending',
            'post_title'     => $title,
            'post_content'   => $description,
            'post_author'    => $user_id,
            'comment_status' => 'open',
        ), true );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( $post_id, '_ke_board_location', $location );
        update_post_meta( $post_id, '_ke_board_date', $dt['date'] );
        update_post_meta( $post_id, '_ke_board_time', $dt['time'] );
        update_post_meta( $post_id, '_ke_board_datetime', $dt['datetime'] );
        update_post_meta( $post_id, '_ke_board_university', $university );
        update_post_meta( $post_id, '_ke_board_organizer', $organizer );
        update_post_meta( $post_id, '_ke_board_contact', $contact );
        update_post_meta( $post_id, '_ke_board_like_count', 0 );

        // ── Uploads — via media_handle_upload so WordPress runs its own MIME
        // and filename checks; attached to the post for traceability. This
        // runs server-side under a trusted context after our validation
        // above, so submitters don't need the upload_files capability.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        add_filter( 'upload_mimes', array( $this, 'restrict_upload_mimes' ) );

        $main_id = media_handle_upload( 'main_image', $post_id );
        if ( is_wp_error( $main_id ) ) {
            remove_filter( 'upload_mimes', array( $this, 'restrict_upload_mimes' ) );
            wp_delete_post( $post_id, true );
            return new WP_Error( 'ke_board_upload_failed', 'No se pudo subir la imagen principal: ' . $main_id->get_error_message(), array( 'status' => 400 ) );
        }
        set_post_thumbnail( $post_id, $main_id );

        $gallery_ids    = array();
        $gallery_failed = 0;
        foreach ( $gallery_keys as $key ) {
            $gid = media_handle_upload( $key, $post_id );
            if ( is_wp_error( $gid ) ) {
                $gallery_failed++;
                error_log( 'KiwiEvents board: gallery upload failed for post ' . $post_id . ' — ' . $gid->get_error_message() );
            } else {
                $gallery_ids[] = (int) $gid;
            }
        }
        remove_filter( 'upload_mimes', array( $this, 'restrict_upload_mimes' ) );

        if ( ! empty( $gallery_ids ) ) {
            update_post_meta( $post_id, '_ke_board_gallery', $gallery_ids );
        }

        $message = 'Tu actividad fue enviada y está pendiente de revisión. Aparecerá en el board cuando sea aprobada.';
        if ( $gallery_failed > 0 ) {
            $message .= sprintf( ' (%d imagen(es) adicional(es) no se pudieron procesar.)', $gallery_failed );
        }

        // Notifications go through the queue — a mail failure must never
        // fail the submission that already succeeded.
        $this->queue_submission_notifications( $post_id, $settings );

        return rest_ensure_response( array(
            'success' => true,
            'message' => $message,
        ) );
    }

    /**
     * Admin notification recipient. The plugin has no global "notification
     * address" setting (the ticket-purchase admin email resolves per-event
     * organizer term meta, which doesn't apply to board posts), so this
     * falls back the same way that path does: get_option('admin_email').
     */
    public static function admin_recipient() {
        return get_option( 'admin_email' );
    }

    /** Queue "new submission" emails to admin and submitter (per settings). */
    private function queue_submission_notifications( $post_id, $settings ) {
        if ( ! class_exists( 'KE_Email_Queue' ) || ! class_exists( 'KE_Email_Templates' ) ) {
            return;
        }
        $post   = get_post( $post_id );
        $author = $post ? get_userdata( (int) $post->post_author ) : null;

        $ctx = array(
            'post_title'      => $post ? $post->post_title : '',
            'submitter_name'  => $author ? $author->display_name : '',
            'submitter_email' => $author ? $author->user_email : '',
            'date_display'    => self::format_datetime( $post_id ),
            'location'        => get_post_meta( $post_id, '_ke_board_location', true ),
            'university'      => get_post_meta( $post_id, '_ke_board_university', true ),
            'organizer'       => get_post_meta( $post_id, '_ke_board_organizer', true ),
            'contact'         => get_post_meta( $post_id, '_ke_board_contact', true ),
            'excerpt'         => wp_trim_words( $post ? $post->post_content : '', 40 ),
            'moderation_url'  => admin_url( 'admin.php?page=ke-board#board-post-' . $post_id ),
        );

        try {
            if ( ! empty( $settings['notify_admin_new'] ) && is_email( self::admin_recipient() ) ) {
                KE_Email_Queue::enqueue( 'board_submitted_admin', self::admin_recipient(), $ctx );
            }
            if ( ! empty( $settings['notify_user_received'] ) && $author && is_email( $author->user_email ) ) {
                KE_Email_Queue::enqueue( 'board_submitted_user', $author->user_email, $ctx );
            }
        } catch ( \Throwable $e ) {
            error_log( 'KiwiEvents board: could not queue submission notifications — ' . $e->getMessage() );
        }
    }

    /** While a board upload runs, only the image allowlist is accepted. */
    public function restrict_upload_mimes( $mimes ) {
        return self::ALLOWED_MIMES;
    }

    /**
     * Validate one uploaded file: real content check (finfo via
     * wp_check_filetype_and_ext + getimagesize), allowlisted MIME only
     * (never SVG), and the size cap. Extension alone is never trusted.
     */
    private function validate_image_file( $file, $max_bytes ) {
        if ( ! empty( $file['error'] ) ) {
            return new WP_Error( 'ke_board_upload_error', 'Error al subir un archivo. Intenta de nuevo.', array( 'status' => 400 ) );
        }
        if ( (int) $file['size'] > $max_bytes ) {
            return new WP_Error(
                'ke_board_file_too_big',
                sprintf( 'Cada imagen debe pesar máximo %d MB.', (int) ( $max_bytes / ( 1024 * 1024 ) ) ),
                array( 'status' => 400 )
            );
        }

        $check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::ALLOWED_MIMES );
        if ( empty( $check['type'] ) || ! in_array( $check['type'], array_values( self::ALLOWED_MIMES ), true ) ) {
            return new WP_Error( 'ke_board_bad_file_type', 'Solo se permiten imágenes JPG, PNG o WEBP.', array( 'status' => 400 ) );
        }

        // Belt-and-suspenders: it must decode as a real image (catches a PHP
        // file renamed to .jpg even if headers were spoofed).
        $dims = @getimagesize( $file['tmp_name'] );
        if ( false === $dims ) {
            return new WP_Error( 'ke_board_not_an_image', 'El archivo no es una imagen válida.', array( 'status' => 400 ) );
        }

        // Dimension cap: the byte cap alone doesn't stop decompression
        // bombs (a 20000×20000 PNG compresses under 5MB but needs ~1.6GB
        // decoded when WordPress regenerates thumbnail sizes).
        $w = isset( $dims[0] ) ? (int) $dims[0] : 0;
        $h = isset( $dims[1] ) ? (int) $dims[1] : 0;
        if ( $w > 8000 || $h > 8000 || ( $w * $h ) > 25000000 ) {
            return new WP_Error( 'ke_board_image_too_large', 'La imagen es demasiado grande (máximo 8000px por lado).', array( 'status' => 400 ) );
        }

        return true;
    }

    /* ─────────────────────────────────────────────
     *  Admin metabox (admins may fix a submission before approving)
     * ───────────────────────────────────────────── */

    public function add_meta_box() {
        add_meta_box(
            'ke_board_details',
            'Detalles de la actividad',
            array( $this, 'render_meta_box' ),
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'ke_board_details_nonce', 'ke_board_nonce' );
        $date       = get_post_meta( $post->ID, '_ke_board_date', true );
        $time       = get_post_meta( $post->ID, '_ke_board_time', true );
        $location   = get_post_meta( $post->ID, '_ke_board_location', true );
        $university = get_post_meta( $post->ID, '_ke_board_university', true );
        $organizer  = get_post_meta( $post->ID, '_ke_board_organizer', true );
        $contact    = get_post_meta( $post->ID, '_ke_board_contact', true );
        ?>
        <table class="form-table">
            <tr><th><label for="ke_board_date">Fecha</label></th>
                <td><input type="date" id="ke_board_date" name="ke_board_date" value="<?php echo esc_attr( $date ); ?>" required></td></tr>
            <tr><th><label for="ke_board_time">Hora</label></th>
                <td><input type="time" id="ke_board_time" name="ke_board_time" value="<?php echo esc_attr( $time ); ?>" required></td></tr>
            <tr><th><label for="ke_board_location">Ubicación</label></th>
                <td><input type="text" class="regular-text" id="ke_board_location" name="ke_board_location" value="<?php echo esc_attr( $location ); ?>"></td></tr>
            <tr><th><label for="ke_board_university">Universidad</label></th>
                <td><input type="text" class="regular-text" id="ke_board_university" name="ke_board_university" value="<?php echo esc_attr( $university ); ?>"></td></tr>
            <tr><th><label for="ke_board_organizer">Organiza (grupo/persona)</label></th>
                <td><input type="text" class="regular-text" id="ke_board_organizer" name="ke_board_organizer" value="<?php echo esc_attr( $organizer ); ?>"></td></tr>
            <tr><th><label for="ke_board_contact">Contacto (público)</label></th>
                <td><input type="text" class="regular-text" id="ke_board_contact" name="ke_board_contact" value="<?php echo esc_attr( $contact ); ?>"></td></tr>
        </table>
        <?php
    }

    public function save_admin_meta( $post_id, $post ) {
        if ( ! isset( $_POST['ke_board_nonce'] ) || ! wp_verify_nonce( $_POST['ke_board_nonce'], 'ke_board_details_nonce' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        foreach ( array(
            'ke_board_location'   => '_ke_board_location',
            'ke_board_university' => '_ke_board_university',
            'ke_board_organizer'  => '_ke_board_organizer',
            'ke_board_contact'    => '_ke_board_contact',
        ) as $post_key => $meta_key ) {
            if ( isset( $_POST[ $post_key ] ) ) {
                update_post_meta( $post_id, $meta_key, sanitize_text_field( $_POST[ $post_key ] ) );
            }
        }

        // Dates keep the canonical format even on admin edits.
        if ( isset( $_POST['ke_board_date'], $_POST['ke_board_time'] ) ) {
            $dt = self::normalize_datetime( $_POST['ke_board_date'], $_POST['ke_board_time'] );
            if ( ! is_wp_error( $dt ) ) {
                update_post_meta( $post_id, '_ke_board_date', $dt['date'] );
                update_post_meta( $post_id, '_ke_board_time', $dt['time'] );
                update_post_meta( $post_id, '_ke_board_datetime', $dt['datetime'] );
            }
        }
    }

    /* ─────────────────────────────────────────────
     *  Display helpers (used by the views)
     * ───────────────────────────────────────────── */

    public static function format_datetime( $post_id ) {
        $raw = get_post_meta( (int) $post_id, '_ke_board_datetime', true );
        $dt  = is_string( $raw ) && '' !== $raw
            ? DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $raw, wp_timezone() )
            : false;
        if ( ! $dt instanceof DateTimeImmutable ) {
            return 'Fecha por confirmar';
        }
        return wp_date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ), $dt->getTimestamp() );
    }

    public static function is_trending( $post_id ) {
        $threshold = (int) self::get_settings()['trending_threshold'];
        return $threshold > 0 && self::get_like_count( $post_id ) >= $threshold;
    }
}
