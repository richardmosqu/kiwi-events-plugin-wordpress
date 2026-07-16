<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Customer ticket wallet — [kiwi_tickets_purchase]
 *
 * Shows the logged-in user their purchased tickets grouped by event,
 * split into Upcoming / Past / Cancelled tabs. Tickets are matched by
 * the KE order's user_id ONLY (guest orders are never shown).
 *
 * Also registers the gated per-ticket PDF download endpoint:
 *   GET /ke/v1/my-tickets/{id}/pdf   (cookie auth + wp_rest nonce;
 *   ownership is enforced server-side against ke_orders.user_id).
 */
class KE_Tickets_Wallet {

    /** Ticket-row statuses that always land in the Cancelled tab. */
    const CANCELLED_TICKET_STATUSES = array( 'cancelled' );

    /** KE order payment_status values that cancel the whole order's tickets. */
    const CANCELLED_KE_ORDER_STATUSES = array( 'cancelled', 'refunded' );

    /**
     * WooCommerce order statuses that cancel tickets. 'failed' is included
     * deliberately: a failed payment must never surface usable tickets, so
     * if ticket rows exist for a failed WC order they render as cancelled.
     */
    const CANCELLED_WC_STATUSES = array( 'cancelled', 'refunded', 'failed' );

    public function init() {
        add_shortcode( 'kiwi_tickets_purchase', array( $this, 'render_wallet' ) );
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /* ─────────────────────────────────────────────
     *  Shortcode
     * ───────────────────────────────────────────── */

    public function render_wallet( $atts = array() ) {
        $ver = defined( 'KE_WALLET_ASSETS_VER' ) ? KE_WALLET_ASSETS_VER : KE_VERSION;

        wp_enqueue_style(
            'ke-tickets-wallet-css',
            KE_PLUGIN_URL . 'public/css/ke-tickets-wallet.css',
            array( 'ke-public-css' ),
            $ver
        );

        if ( ! is_user_logged_in() ) {
            ob_start();
            $gate = $this->get_gate_data();
            include KE_PLUGIN_DIR . 'public/views/tickets-wallet-gate.php';
            return ob_get_clean();
        }

        wp_enqueue_script(
            'ke-tickets-wallet-js',
            KE_PLUGIN_URL . 'public/js/ke-tickets-wallet.js',
            array(),
            $ver,
            true
        );

        $wallet = $this->build_wallet_data( get_current_user_id() );

        ob_start();
        include KE_PLUGIN_DIR . 'public/views/tickets-wallet.php';
        return ob_get_clean();
    }

    /**
     * Login-gate data from the Access Control settings
     * (option `ke_access_settings`: login_url / register_url /
     * login_required_message), falling back to core URLs.
     */
    private function get_gate_data() {
        $access   = get_option( 'ke_access_settings', array() );
        $back_url = get_permalink();

        $login_url = ! empty( $access['login_url'] )
            ? add_query_arg( 'redirect_to', urlencode( $back_url ), $access['login_url'] )
            : wp_login_url( $back_url );

        $register_url = ! empty( $access['register_url'] )
            ? add_query_arg( 'redirect_to', urlencode( $back_url ), $access['register_url'] )
            : '';

        $message = ! empty( $access['login_required_message'] )
            ? $access['login_required_message']
            : 'Inicia sesión para ver tus boletos.';

        return array(
            'login_url'    => $login_url,
            'register_url' => $register_url,
            'message'      => $message,
        );
    }

    /* ─────────────────────────────────────────────
     *  Data
     * ───────────────────────────────────────────── */

    /**
     * Fetch, categorize, and group ALL of the user's tickets.
     * No LIMIT anywhere before categorization — a limit here previously
     * caused a production bug in [kiwi_events].
     */
    private function build_wallet_data( $user_id ) {
        $tickets = $this->fetch_user_tickets( $user_id );

        $wallet = array(
            'total' => count( $tickets ),
            'tabs'  => array(
                'upcoming'  => array( 'label' => 'Próximos',   'events' => array(), 'ticket_count' => 0 ),
                'past'      => array( 'label' => 'Pasados',    'events' => array(), 'ticket_count' => 0 ),
                'cancelled' => array( 'label' => 'Cancelados', 'events' => array(), 'ticket_count' => 0 ),
            ),
        );

        if ( empty( $tickets ) ) {
            return $wallet;
        }

        $this->prime_event_caches( $tickets );
        $wc_statuses  = $this->fetch_wc_statuses( $tickets );
        $qr_generator = new KE_QR_Generator();

        // Per-request cache of resolved event display data.
        $event_cache = array();

        foreach ( $tickets as $t ) {
            $event_id = (int) $t->event_id;

            if ( ! isset( $event_cache[ $event_id ] ) ) {
                $event_cache[ $event_id ] = $this->resolve_event( $event_id, $t->event_name );
            }
            $event = $event_cache[ $event_id ];

            $tab = $this->categorize_ticket( $t, $event, $wc_statuses );

            if ( ! isset( $wallet['tabs'][ $tab ]['events'][ $event_id ] ) ) {
                $wallet['tabs'][ $tab ]['events'][ $event_id ] = array_merge( $event, array(
                    'tickets' => array(),
                ) );
            }

            $wallet['tabs'][ $tab ]['events'][ $event_id ]['tickets'][] = array(
                'id'         => (int) $t->id,
                'type_name'  => $t->ticket_type_name ?: 'General',
                'code_short' => strtoupper( substr( (string) $t->ticket_code, 0, 8 ) ),
                'qr_url'     => $qr_generator->get_url( $t->ticket_code ),
                'status'     => $t->status,
            );
            $wallet['tabs'][ $tab ]['ticket_count']++;
        }

        // Ordering: Upcoming soonest-first; Past/Cancelled most-recent-first.
        foreach ( $wallet['tabs'] as $key => &$tab_data ) {
            $asc = ( 'upcoming' === $key );
            uasort( $tab_data['events'], function ( $a, $b ) use ( $asc ) {
                return $asc ? $a['start_ts'] <=> $b['start_ts'] : $b['start_ts'] <=> $a['start_ts'];
            } );
        }
        unset( $tab_data );

        return $wallet;
    }

    /**
     * One query for every ticket the user owns. Ownership = the linked KE
     * order's user_id (guest orders have user_id = 0 and can never match
     * because we bail on empty $user_id).
     */
    private function fetch_user_tickets( $user_id ) {
        global $wpdb;

        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return array();
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT t.id, t.ticket_code, t.event_id, t.status, t.attendee_number,
                    COALESCE(tt.name, t.ticket_type_snapshot) as ticket_type_name,
                    o.payment_status as ke_payment_status,
                    o.wc_order_id,
                    p.post_title as event_name
             FROM {$wpdb->prefix}ke_tickets t
             INNER JOIN {$wpdb->prefix}ke_orders o ON t.order_id = o.id
             LEFT JOIN {$wpdb->prefix}ke_ticket_types tt ON t.ticket_type_id = tt.id
             LEFT JOIN {$wpdb->posts} p ON t.event_id = p.ID
             WHERE o.user_id = %d
             ORDER BY t.created_at DESC",
            $user_id
        ) );
    }

    /**
     * Warm the post + postmeta caches for every referenced event (and its
     * featured image) in two bulk queries, so the per-ticket get_post_meta /
     * thumbnail calls below never hit the database individually.
     */
    private function prime_event_caches( $tickets ) {
        $event_ids = array_unique( array_map( 'intval', wp_list_pluck( $tickets, 'event_id' ) ) );
        $event_ids = array_filter( $event_ids );
        if ( empty( $event_ids ) ) {
            return;
        }

        _prime_post_caches( $event_ids, false, true );

        $thumb_ids = array();
        foreach ( $event_ids as $eid ) {
            $tid = (int) get_post_thumbnail_id( $eid );
            if ( $tid ) {
                $thumb_ids[] = $tid;
            }
        }
        if ( ! empty( $thumb_ids ) ) {
            _prime_post_caches( $thumb_ids, false, true );
        }
    }

    /**
     * Batch-resolve WooCommerce order statuses for the distinct wc_order_ids
     * (bounded by the user's distinct orders, not their ticket count).
     * Returns map: wc_order_id => status slug ('cancelled', 'refunded', …).
     */
    private function fetch_wc_statuses( $tickets ) {
        $statuses = array();

        if ( ! function_exists( 'wc_get_order' ) ) {
            return $statuses;
        }

        $wc_ids = array_unique( array_filter( array_map( 'intval', wp_list_pluck( $tickets, 'wc_order_id' ) ) ) );
        foreach ( $wc_ids as $wc_id ) {
            $order = wc_get_order( $wc_id );
            if ( $order ) {
                $statuses[ $wc_id ] = $order->get_status();
            }
        }

        return $statuses;
    }

    /**
     * Display + categorization data for one event. Meta reads are served
     * from the primed cache.
     */
    private function resolve_event( $event_id, $fallback_title ) {
        $start_raw     = get_post_meta( $event_id, '_ke_event_date_start', true );
        $venue         = get_post_meta( $event_id, '_ke_event_venue', true );
        $address       = get_post_meta( $event_id, '_ke_event_address', true );
        $location_type = get_post_meta( $event_id, '_ke_event_location_type', true );

        $location = $venue ?: $address;
        if ( ! $location && 'online' === $location_type ) {
            $location = 'Evento en línea';
        }

        $start_ts = $this->parse_display_datetime( $start_raw ) ?: 0;

        return array(
            'id'           => $event_id,
            'title'        => $fallback_title ?: ( get_the_title( $event_id ) ?: 'Evento no disponible' ),
            'thumb'        => get_the_post_thumbnail_url( $event_id, 'medium' ),
            'location'     => $location,
            'start_ts'     => $start_ts,
            'date_display' => $start_ts
                ? wp_date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ), $start_ts )
                : 'Fecha por confirmar',
            'is_cancelled' => ( 'cancelled' === get_post_meta( $event_id, '_ke_event_status', true ) ),
            'is_expired'   => KE_Shortcodes::event_is_expired( $event_id ),
        );
    }

    /**
     * Mutually exclusive categorization, evaluated in order:
     * cancelled → past → upcoming. Expiry reuses the fail-open
     * KE_Shortcodes::event_is_expired() (no new date comparison).
     */
    private function categorize_ticket( $ticket, $event, $wc_statuses ) {
        $wc_id     = (int) $ticket->wc_order_id;
        $wc_status = ( $wc_id && isset( $wc_statuses[ $wc_id ] ) ) ? $wc_statuses[ $wc_id ] : '';

        $is_cancelled =
            in_array( $ticket->status, self::CANCELLED_TICKET_STATUSES, true ) ||
            in_array( $ticket->ke_payment_status, self::CANCELLED_KE_ORDER_STATUSES, true ) ||
            ( $wc_status && in_array( $wc_status, self::CANCELLED_WC_STATUSES, true ) ) ||
            $event['is_cancelled'];

        if ( $is_cancelled ) {
            return 'cancelled';
        }

        return $event['is_expired'] ? 'past' : 'upcoming';
    }

    /**
     * DISPLAY-ONLY parse of the mixed start-date formats stored in
     * _ke_event_date_start ('Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i',
     * 'Y-m-d'), interpreted in the site timezone. Never used for expiry —
     * that's event_is_expired()'s job.
     *
     * @return int|false Unix timestamp, or false when unparseable.
     */
    private function parse_display_datetime( $raw ) {
        if ( ! is_scalar( $raw ) ) {
            return false;
        }
        $raw = trim( (string) $raw );
        if ( '' === $raw || '0' === $raw || 0 === strpos( $raw, '0000-00-00' ) ) {
            return false;
        }

        $tz = wp_timezone();
        foreach ( array( 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d' ) as $format ) {
            $dt = DateTimeImmutable::createFromFormat( $format, $raw, $tz );
            if ( $dt instanceof DateTimeImmutable ) {
                if ( 'Y-m-d' === $format ) {
                    $dt = $dt->setTime( 0, 0, 0 );
                }
                return $dt->getTimestamp();
            }
        }

        return false;
    }

    /* ─────────────────────────────────────────────
     *  PDF download endpoint
     * ───────────────────────────────────────────── */

    public function register_routes() {
        register_rest_route( 'ke/v1', '/my-tickets/(?P<id>\d+)/pdf', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'download_ticket_pdf' ),
            'permission_callback' => 'is_user_logged_in',
            'args'                => array(
                'id' => array(
                    'required'          => true,
                    // Closures, not bare internal functions: WP calls these
                    // with ($value, $request, $param) and PHP 8 fatals with
                    // ArgumentCountError on internals like is_numeric().
                    'validate_callback' => static function ( $value ) {
                        return is_numeric( $value );
                    },
                    'sanitize_callback' => static function ( $value ) {
                        return absint( $value );
                    },
                ),
            ),
        ) );
    }

    /**
     * Stream one ticket PDF to its owner. The real gate is the server-side
     * ownership check: the ticket's KE order user_id must equal the current
     * user (the wp_rest nonce only establishes the cookie-authed identity).
     */
    public function download_ticket_pdf( WP_REST_Request $request ) {
        global $wpdb;

        $ticket_id = (int) $request['id'];

        $ticket = $wpdb->get_row( $wpdb->prepare(
            "SELECT t.*,
                    COALESCE(tt.name, t.ticket_type_snapshot) as ticket_type_name,
                    tt.price as ticket_price,
                    p.post_title as event_name,
                    o.user_id as owner_user_id
             FROM {$wpdb->prefix}ke_tickets t
             INNER JOIN {$wpdb->prefix}ke_orders o ON t.order_id = o.id
             LEFT JOIN {$wpdb->prefix}ke_ticket_types tt ON t.ticket_type_id = tt.id
             LEFT JOIN {$wpdb->posts} p ON t.event_id = p.ID
             WHERE t.id = %d",
            $ticket_id
        ) );

        if ( ! $ticket ) {
            return new WP_Error( 'ke_ticket_not_found', 'Boleto no encontrado.', array( 'status' => 404 ) );
        }

        $owner = (int) $ticket->owner_user_id;
        if ( $owner <= 0 || $owner !== get_current_user_id() ) {
            return new WP_Error( 'ke_ticket_forbidden', 'No tienes permiso para descargar este boleto.', array( 'status' => 403 ) );
        }

        $filepath = $ticket->pdf_path;
        if ( empty( $filepath ) || ! file_exists( $filepath ) ) {
            $generator = new KE_PDF_Generator();
            $filepath  = $generator->generate( $ticket );
            if ( is_wp_error( $filepath ) ) {
                return $filepath;
            }
        }

        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="boleto-' . strtoupper( substr( $ticket->ticket_code, 0, 8 ) ) . '.pdf"' );
        header( 'Content-Length: ' . filesize( $filepath ) );
        readfile( $filepath );
        exit;
    }
}
