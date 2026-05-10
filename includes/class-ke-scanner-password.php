<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Scanner password management for organizers.
 *
 * Stores a per-organizer password (hashed via wp_hash_password) under term meta
 * `_ke_organizer_scanner_password`. Wires up the password field on both the
 * WordPress native taxonomy term Add/Edit screens and exposes helpers used by
 * the plugin's custom organizer admin and the scanner REST endpoints.
 */
class KE_Scanner_Password {

    const META_KEY              = '_ke_organizer_scanner_password';
    const META_KEY_LENGTH       = '_ke_organizer_scanner_password_length';
    const ATTEMPT_WINDOW        = 60;   // seconds
    const ATTEMPT_LIMIT         = 3;    // failed attempts per IP+event in the window
    const ATTEMPT_PREFIX        = 'ke_scanner_attempts_';

    // Session token for the public scanner page. Issued after a successful
    // password gate and presented as X-KE-Scanner-Token on validate calls.
    const SESSION_TTL           = 4 * HOUR_IN_SECONDS;
    const SESSION_PREFIX        = 'ke_scanner_session_';
    // Per-token scan rate limit (anti-flooding by a leaked token).
    const SCAN_RATE_PREFIX      = 'ke_scanner_scan_rate_';
    const SCAN_RATE_WINDOW      = 60;   // seconds
    const SCAN_RATE_LIMIT       = 60;   // scans per token per window
    // Index of live tokens per organizer, used for bulk invalidation.
    const SESSION_INDEX_PREFIX  = 'ke_scanner_session_index_';

    public function init() {
        // WordPress native taxonomy term screens
        add_action( 'ke_organizer_add_form_fields',  array( $this, 'render_add_field' ) );
        add_action( 'ke_organizer_edit_form_fields', array( $this, 'render_edit_field' ), 10, 2 );

        // Save handlers (cover both create and edit)
        add_action( 'created_ke_organizer', array( $this, 'save_term_password' ), 10, 2 );
        add_action( 'edited_ke_organizer',  array( $this, 'save_term_password' ), 10, 2 );

        // When a term is deleted, scrub session transients tied to its events
        add_action( 'pre_delete_term', array( $this, 'on_pre_delete_term' ), 10, 2 );
    }

    /* ──────────────────────────────────────────────────────────────
     *  Public helpers (used by REST + custom admin)
     * ────────────────────────────────────────────────────────────── */

    public static function organizer_has_password( $term_id ) {
        $hash = get_term_meta( (int) $term_id, self::META_KEY, true );
        return is_string( $hash ) && $hash !== '';
    }

    /**
     * Length of the organizer's stored password, or 0 when none is set.
     * Used by the admin UI to render bullet-dot placeholders. Never returns
     * the password itself.
     *
     * Passwords set before the length-tracking meta was introduced have no
     * recorded length — for those we return a fixed fallback (8 dots) so
     * the UI still shows "a password is set" rather than misreading them
     * as unset.
     */
    public static function organizer_password_length( $term_id ) {
        if ( ! self::organizer_has_password( $term_id ) ) return 0;
        $len = (int) get_term_meta( (int) $term_id, self::META_KEY_LENGTH, true );
        if ( $len > 0 ) return $len;
        return 8;
    }

    public static function verify_organizer_password( $term_id, $password ) {
        $hash = get_term_meta( (int) $term_id, self::META_KEY, true );
        if ( ! is_string( $hash ) || $hash === '' ) {
            return false;
        }
        return (bool) wp_check_password( (string) $password, $hash );
    }

    /**
     * Set or clear an organizer's scanner password.
     * Pass empty string / null to clear.
     */
    public static function set_organizer_password( $term_id, $plain ) {
        $term_id = (int) $term_id;
        if ( $term_id <= 0 ) return false;

        if ( $plain === null || $plain === '' ) {
            delete_term_meta( $term_id, self::META_KEY );
            delete_term_meta( $term_id, self::META_KEY_LENGTH );
        } else {
            $hash = wp_hash_password( (string) $plain );
            update_term_meta( $term_id, self::META_KEY, $hash );
            // Store length separately (the hash is irreversible) so the admin
            // UI can render the right number of bullet dots without ever
            // shipping the plaintext or the hash to the browser.
            update_term_meta( $term_id, self::META_KEY_LENGTH, mb_strlen( (string) $plain ) );
        }

        // Invalidate organizer-dashboard cookie sessions so a stale cookie
        // can't outlive a password change.
        if ( class_exists( 'KE_Organizer_Dashboard' ) ) {
            KE_Organizer_Dashboard::purge_organizer_sessions( $term_id );
        }
        // Also kill any live scanner-page session tokens issued under the
        // old password.
        self::purge_organizer_sessions( $term_id );
        return true;
    }

    /* ──────────────────────────────────────────────────────────────
     *  Event → organizer lookup
     * ────────────────────────────────────────────────────────────── */

    public static function get_organizer_id_for_event( $event_id ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) return 0;

        $terms = wp_get_post_terms( $event_id, 'ke_organizer', array( 'fields' => 'ids' ) );
        if ( is_wp_error( $terms ) || empty( $terms ) ) return 0;
        return (int) $terms[0];
    }

    public static function event_requires_password( $event_id ) {
        $org_id = self::get_organizer_id_for_event( $event_id );
        if ( $org_id <= 0 ) return false;
        return self::organizer_has_password( $org_id );
    }

    /* ──────────────────────────────────────────────────────────────
     *  Rate limiting (per IP + event) for failed gate attempts
     * ────────────────────────────────────────────────────────────── */

    public static function get_request_ip() {
        $ip = '';
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $parts = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
            $ip    = trim( $parts[0] );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        $ip = filter_var( $ip, FILTER_VALIDATE_IP ) ?: '0.0.0.0';
        return $ip;
    }

    private static function attempt_key( $ip, $event_id ) {
        return self::ATTEMPT_PREFIX . md5( $ip ) . '_' . (int) $event_id;
    }

    public static function attempts_used( $ip, $event_id ) {
        $val = get_transient( self::attempt_key( $ip, $event_id ) );
        return is_numeric( $val ) ? (int) $val : 0;
    }

    public static function attempts_remaining( $ip, $event_id ) {
        return max( 0, self::ATTEMPT_LIMIT - self::attempts_used( $ip, $event_id ) );
    }

    public static function record_failed_attempt( $ip, $event_id ) {
        $key = self::attempt_key( $ip, $event_id );
        $val = (int) get_transient( $key );
        $val++;
        set_transient( $key, $val, self::ATTEMPT_WINDOW );
        return $val;
    }

    public static function clear_attempts( $ip, $event_id ) {
        delete_transient( self::attempt_key( $ip, $event_id ) );
    }

    /* ──────────────────────────────────────────────────────────────
     *  WP native taxonomy edit screens
     * ────────────────────────────────────────────────────────────── */

    public function render_add_field( $taxonomy ) {
        wp_nonce_field( 'ke_organizer_password_save', 'ke_organizer_password_nonce' );
        ?>
        <div class="form-field">
            <label for="ke_organizer_scanner_password">
                <?php esc_html_e( 'Organizer Password', 'kiwi-events' ); ?>
            </label>
            <input type="password"
                   id="ke_organizer_scanner_password"
                   name="ke_organizer_scanner_password"
                   value=""
                   autocomplete="new-password" />
            <p class="description">
                <?php esc_html_e( 'This password protects two things: (1) Kiwi Scanner access for staff to scan tickets at events, and (2) the Organizer Dashboard at /organizer/[slug] where you can view sales, attendees, and download reports for all your events.', 'kiwi-events' ); ?>
            </p>
        </div>
        <?php
    }

    public function render_edit_field( $term, $taxonomy ) {
        $has = self::organizer_has_password( $term->term_id );
        wp_nonce_field( 'ke_organizer_password_save', 'ke_organizer_password_nonce' );
        if ( $has ) :
            $dashboard_url = home_url( '/organizer/' . $term->slug . '/' );
            ?>
            <tr class="form-field">
                <th scope="row"><?php esc_html_e( 'Organizer Dashboard', 'kiwi-events' ); ?></th>
                <td>
                    <a href="<?php echo esc_url( $dashboard_url ); ?>"
                       target="_blank"
                       rel="noopener"
                       class="button button-primary"
                       style="display:inline-flex;align-items:center;gap:6px;">
                        📊 <?php esc_html_e( 'View Dashboard', 'kiwi-events' ); ?>
                    </a>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: dashboard URL */
                            esc_html__( 'Opens the organizer dashboard in a new tab: %s', 'kiwi-events' ),
                            '<code>' . esc_html( $dashboard_url ) . '</code>'
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <?php
        endif;
        ?>
        <tr class="form-field">
            <th scope="row">
                <label for="ke_organizer_scanner_password">
                    <?php esc_html_e( 'Organizer Password', 'kiwi-events' ); ?>
                </label>
            </th>
            <td>
                <input type="password"
                       id="ke_organizer_scanner_password"
                       name="ke_organizer_scanner_password"
                       value=""
                       autocomplete="new-password" />
                <p class="description">
                    <?php if ( $has ) : ?>
                        <strong><?php esc_html_e( 'A password is currently set.', 'kiwi-events' ); ?></strong>
                        <?php esc_html_e( 'Enter a new password to change it, or check the box below to remove the password entirely.', 'kiwi-events' ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'This password protects two things: (1) Kiwi Scanner access for staff to scan tickets at events, and (2) the Organizer Dashboard at /organizer/[slug] where you can view sales, attendees, and download reports for all your events.', 'kiwi-events' ); ?>
                    <?php endif; ?>
                </p>
                <?php if ( $has ) : ?>
                    <p>
                        <label>
                            <input type="checkbox" name="ke_organizer_scanner_password_clear" value="1" />
                            <?php esc_html_e( 'Remove the existing organizer password', 'kiwi-events' ); ?>
                        </label>
                    </p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Save handler for both create and edit actions on ke_organizer terms.
     */
    public function save_term_password( $term_id, $tt_id ) {
        if ( ! current_user_can( 'manage_categories' ) && ! current_user_can( 'manage_kiwi_events' ) ) {
            return;
        }
        if ( empty( $_POST['ke_organizer_password_nonce'] )
             || ! wp_verify_nonce( $_POST['ke_organizer_password_nonce'], 'ke_organizer_password_save' ) ) {
            return;
        }

        $clear   = ! empty( $_POST['ke_organizer_scanner_password_clear'] );
        $raw     = isset( $_POST['ke_organizer_scanner_password'] ) ? (string) $_POST['ke_organizer_scanner_password'] : '';
        $plain   = trim( $raw );

        if ( $clear ) {
            self::set_organizer_password( $term_id, '' );
            return;
        }
        if ( $plain === '' ) {
            // No change requested — leave existing hash intact.
            return;
        }
        self::set_organizer_password( $term_id, $plain );
    }

    public function on_pre_delete_term( $term_id, $taxonomy ) {
        if ( $taxonomy !== 'ke_organizer' ) return;
        self::purge_organizer_sessions( (int) $term_id );
        delete_term_meta( $term_id, self::META_KEY );
        delete_term_meta( $term_id, self::META_KEY_LENGTH );
    }

    /* ──────────────────────────────────────────────────────────────
     *  Session tokens for the public scanner page
     * ────────────────────────────────────────────────────────────── */

    private static function session_index_key( $organizer_id ) {
        return self::SESSION_INDEX_PREFIX . (int) $organizer_id;
    }

    private static function add_token_to_index( $organizer_id, $token ) {
        $key = self::session_index_key( $organizer_id );
        $idx = get_transient( $key );
        if ( ! is_array( $idx ) ) $idx = array();
        $idx[ $token ] = time() + self::SESSION_TTL;
        // Drop expired entries opportunistically so the index stays bounded.
        $now = time();
        foreach ( $idx as $t => $exp ) {
            if ( $exp < $now ) unset( $idx[ $t ] );
        }
        set_transient( $key, $idx, self::SESSION_TTL );
    }

    private static function remove_token_from_index( $organizer_id, $token ) {
        $key = self::session_index_key( $organizer_id );
        $idx = get_transient( $key );
        if ( ! is_array( $idx ) ) return;
        unset( $idx[ $token ] );
        if ( empty( $idx ) ) {
            delete_transient( $key );
        } else {
            set_transient( $key, $idx, self::SESSION_TTL );
        }
    }

    /**
     * Issue a session token for the given event. Token is a 64-hex random
     * string; the transient stores the event_id and organizer_id so the
     * validate endpoint can confirm the token's scope.
     *
     * @return array { token, expires_at }
     */
    public static function issue_session_token( $event_id ) {
        $event_id     = (int) $event_id;
        $organizer_id = self::get_organizer_id_for_event( $event_id );
        $token        = bin2hex( random_bytes( 32 ) );
        $expires_at   = time() + self::SESSION_TTL;

        set_transient( self::SESSION_PREFIX . $token, array(
            'event_id'     => $event_id,
            'organizer_id' => $organizer_id,
            'issued_at'    => time(),
            'expires_at'   => $expires_at,
        ), self::SESSION_TTL );

        if ( $organizer_id > 0 ) {
            self::add_token_to_index( $organizer_id, $token );
        }

        return array(
            'token'      => $token,
            'expires_at' => $expires_at,
        );
    }

    /**
     * Verify a session token. Returns the stored payload (event_id /
     * organizer_id / expires_at) or null when missing, expired, or malformed.
     */
    public static function verify_session_token( $token ) {
        if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) return null;
        $data = get_transient( self::SESSION_PREFIX . $token );
        if ( ! is_array( $data ) ) return null;
        return $data;
    }

    public static function revoke_session_token( $token ) {
        if ( ! is_string( $token ) || $token === '' ) return;
        $data = get_transient( self::SESSION_PREFIX . $token );
        delete_transient( self::SESSION_PREFIX . $token );
        if ( is_array( $data ) && ! empty( $data['organizer_id'] ) ) {
            self::remove_token_from_index( (int) $data['organizer_id'], $token );
        }
    }

    /**
     * Invalidate every live scanner session for an organizer. Called when
     * the organizer password changes or the term is deleted.
     */
    public static function purge_organizer_sessions( $organizer_id ) {
        $organizer_id = (int) $organizer_id;
        if ( $organizer_id <= 0 ) return;
        $key = self::session_index_key( $organizer_id );
        $idx = get_transient( $key );
        if ( is_array( $idx ) ) {
            foreach ( array_keys( $idx ) as $tok ) {
                delete_transient( self::SESSION_PREFIX . $tok );
            }
        }
        delete_transient( $key );
    }

    /**
     * Per-token rate limit on validate. Anti-flooding guard if a token leaks.
     * Returns true when the call is allowed, false when over the limit.
     */
    public static function check_scan_rate( $token ) {
        if ( ! is_string( $token ) || $token === '' ) return false;
        $key = self::SCAN_RATE_PREFIX . $token;
        $val = (int) get_transient( $key );
        if ( $val >= self::SCAN_RATE_LIMIT ) return false;
        set_transient( $key, $val + 1, self::SCAN_RATE_WINDOW );
        return true;
    }
}
