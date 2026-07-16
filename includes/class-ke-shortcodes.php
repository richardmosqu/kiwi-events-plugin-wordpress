<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Category-filtered event shortcodes:
 *   [kiwi_events_carousel  category="slug"] — horizontal carousel
 *   [kiwi_events_list      category="slug"] — vertical stack
 *   [kiwi_events_calendar  category="slug"] — month-view calendar
 *   [kiwi_organizers]                        — horizontal organizer catalog
 *
 * Carousel + list share a single query helper (upcoming-by-default, sorted
 * by start date ascending) plus a per-request memo cache so multiple
 * instances on one page hit the DB once. Calendar uses its own range-based
 * query because it intentionally shows past months too. The organizer
 * catalog enumerates ke_organizer taxonomy terms and counts each term's
 * non-expired events via the same expiration rule the event shortcodes use.
 */
class KE_Shortcodes {

    /** Per-request memo: hash(atts) → WP_Query */
    private static $query_cache = array();

    /** Per-page instance counter so each calendar shortcode gets a unique id. */
    private static $calendar_instance = 0;

    public function init() {
        add_shortcode( 'kiwi_events_carousel',   array( $this, 'render_carousel' ) );
        add_shortcode( 'kiwi_events_list',       array( $this, 'render_list' ) );
        add_shortcode( 'kiwi_events_calendar',   array( $this, 'render_calendar' ) );
        add_shortcode( 'kiwi_organizers',        array( $this, 'render_organizers' ) );
        add_shortcode( 'kiwi-promoter-dashboard', array( $this, 'render_promoter_mini_dashboard' ) );
    }

    /* ─── Expiration helpers ─────────────────────────────────────────── */

    /**
     * True when an event's END datetime is in the past relative to the site
     * timezone. Events with no end date never expire.
     *
     * FAIL-OPEN by design: any unparseable / unexpected / unscalar value
     * resolves to "not expired" so a single bad meta row cannot silently
     * hide a valid event from every public listing. Hiding a valid event
     * costs real ticket sales; surfacing a stale one is cosmetic.
     *
     * Stored _ke_event_date_end formats this plugin produces or has
     * produced (all interpreted in the site timezone — there's never a
     * timezone suffix in the stored value):
     *   - "Y-m-d\TH:i"    — current admin builder JS output ("2026-06-25T14:30")
     *   - "Y-m-d\TH:i:s"  — variant with seconds
     *   - "Y-m-d H:i:s"   — legacy MySQL-style from the old metabox save path
     *   - "Y-m-d H:i"     — legacy without seconds
     *   - "Y-m-d"         — date-only legacy; treated as 23:59:59 site-local
     *                       so an event ending "today" stays visible all day
     *   - ""              — never set → never expires
     *   - "0000-00-00*"   — legacy zero sentinel → never expires
     */
    public static function event_is_expired( $post_id ) {
        $raw = get_post_meta( (int) $post_id, '_ke_event_date_end', true );

        if ( ! is_scalar( $raw ) ) {
            return false;
        }
        $end_raw = trim( (string) $raw );

        if ( $end_raw === '' || $end_raw === '0' || strpos( $end_raw, '0000-00-00' ) === 0 ) {
            return false;
        }

        $tz  = wp_timezone();
        $end = self::parse_event_datetime( $end_raw, $tz );

        if ( ! $end instanceof DateTimeImmutable ) {
            self::debug_expired_log( $post_id, $end_raw, null, null, false, 'PARSE_FAIL' );
            return false;
        }

        $now = function_exists( 'current_datetime' )
            ? current_datetime()
            : new DateTimeImmutable( 'now', $tz );

        $is_expired = $end < $now;
        self::debug_expired_log( $post_id, $end_raw, $end, $now, $is_expired, 'OK' );

        return $is_expired;
    }

    /**
     * Parse a stored event datetime against the known storage formats and
     * return a DateTimeImmutable in $tz, or null on failure.
     *
     * Strict createFromFormat is tried first so values like "2026-06-25T14:99"
     * cannot silently roll over into the next field — only when every strict
     * format fails do we fall back to the lenient constructor, and a failure
     * there is treated as "couldn't parse, show the event" by the caller.
     */
    private static function parse_event_datetime( $raw, DateTimeZone $tz ) {
        $raw = trim( (string) $raw );
        if ( $raw === '' ) {
            return null;
        }

        $datetime_formats = array(
            'Y-m-d\TH:i',
            'Y-m-d\TH:i:s',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
        );
        foreach ( $datetime_formats as $fmt ) {
            $parsed = DateTimeImmutable::createFromFormat( $fmt, $raw, $tz );
            if ( $parsed !== false && self::last_parse_was_clean() ) {
                return $parsed;
            }
        }

        // Date-only fallback. The leading "!" resets every unspecified field
        // (incl. time) so we don't inherit "now" — then push to end-of-day
        // so a same-day end keeps the event visible all day.
        $date_only = DateTimeImmutable::createFromFormat( '!Y-m-d', $raw, $tz );
        if ( $date_only !== false && self::last_parse_was_clean() ) {
            return $date_only->setTime( 23, 59, 59 );
        }

        try {
            return new DateTimeImmutable( $raw, $tz );
        } catch ( Exception $e ) {
            return null;
        }
    }

    /** True only when the last createFromFormat call had no warnings/errors. */
    private static function last_parse_was_clean() {
        $errors = DateTimeImmutable::getLastErrors();
        if ( ! is_array( $errors ) ) {
            return true; // PHP 8.2+ returns false when there's nothing to report.
        }
        $warnings = isset( $errors['warning_count'] ) ? (int) $errors['warning_count'] : 0;
        $hard     = isset( $errors['error_count'] )   ? (int) $errors['error_count']   : 0;
        return ( $warnings + $hard ) === 0;
    }

    /**
     * Gated by `define( 'KE_EVENTS_DEBUG', true )` in wp-config.php. Emits a
     * line per event so the actual stored value, the parsed wall-clock, and
     * the current-time comparison can be diffed when an event is wrongly
     * (in)visible. No-op in production.
     */
    private static function debug_expired_log( $post_id, $end_raw, $parsed_end, $now, $is_expired, $status ) {
        if ( ! defined( 'KE_EVENTS_DEBUG' ) || ! KE_EVENTS_DEBUG ) {
            return;
        }
        error_log( sprintf(
            '[KE-EVENTS] Event %d: end_raw="%s", parsed=%s, now=%s, expired=%s, status=%s',
            (int) $post_id,
            $end_raw,
            $parsed_end ? $parsed_end->format( 'Y-m-d H:i:s P' ) : 'PARSE_FAIL',
            $now        ? $now->format( 'Y-m-d H:i:s P' )         : 'n/a',
            $is_expired ? 'YES' : 'NO',
            $status
        ) );
    }

    /**
     * Removes expired events from a WP_Post array. Used by every public
     * listing shortcode so the rule is centralized — change the logic above
     * and every surface inherits it.
     */
    public static function filter_expired_posts( array $posts ) {
        $kept = array();
        foreach ( $posts as $p ) {
            if ( ! self::event_is_expired( $p->ID ) ) {
                $kept[] = $p;
            }
        }
        return $kept;
    }

    /* ─── Promoter mini dashboard ─────────────────────────────────────── */

    /**
     * [kiwi-promoter-dashboard]
     *
     * Renders a compact card listing the current promoter's active events
     * (tickets sold, commission earned), with a click-through to the full
     * portal. Renders nothing if:
     *   - the visitor isn't logged in
     *   - the logged-in user isn't linked to an active promoter row
     *
     * Silent-fail by design — this is meant to be embedded on author/profile
     * pages where it's a no-op for everyone except the promoter themselves.
     */
    public function render_promoter_mini_dashboard( $atts = array() ) {
        if ( ! is_user_logged_in() ) return '';
        if ( ! class_exists( 'KE_Promoter_Attribution' ) ) return '';

        $promoter = KE_Promoter_Attribution::find_by_user_id( get_current_user_id() );
        if ( ! $promoter || ( isset( $promoter->status ) && $promoter->status !== 'active' ) ) {
            return '';
        }

        $assignments = class_exists( 'KE_Event_Promoters' )
            ? KE_Event_Promoters::list_events_for_promoter( (int) $promoter->id )
            : array();

        $currency = (string) get_option( 'ke_promoter_currency_label', '$' );
        $portal   = KE_Promoter_Portal::portal_url( $promoter->slug );

        // Per-event aggregates from the commission ledger.
        global $wpdb;
        $per_event = array();
        if ( $assignments ) {
            $ct  = $wpdb->prefix . 'ke_promoter_commissions';
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT event_id, COUNT(*) AS tickets, COALESCE(SUM(commission_amount),0) AS total
                   FROM $ct WHERE promoter_id = %d GROUP BY event_id",
                (int) $promoter->id
            ) );
            foreach ( $rows as $r ) {
                $per_event[ (int) $r->event_id ] = array(
                    'tickets' => (int) $r->tickets,
                    'total'   => (float) $r->total,
                );
            }
        }

        ob_start(); ?>
        <div class="ke-promo-mini" style="--kep-accent-1:#6366f1;">
            <style>
                .ke-promo-mini { max-width:600px; margin:18px auto; background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:16px 18px; font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif; color:#0f172a; box-shadow:0 4px 12px rgba(15,23,42,0.04); }
                .ke-promo-mini__head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; }
                .ke-promo-mini__title { font-size:14px; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:var(--kep-accent-1); margin:0; }
                .ke-promo-mini__view { font-size:12px; font-weight:600; color:var(--kep-accent-1); text-decoration:none; }
                .ke-promo-mini__view:hover { text-decoration:underline; }
                .ke-promo-mini__row { display:grid; grid-template-columns:1fr auto auto; gap:12px; align-items:center; padding:10px 0; border-top:1px solid #f1f5f9; font-size:13px; }
                .ke-promo-mini__row:first-of-type { border-top:none; }
                .ke-promo-mini__event { font-weight:600; color:#0f172a; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
                .ke-promo-mini__metric { font-variant-numeric:tabular-nums; color:#475569; font-size:12px; }
                .ke-promo-mini__metric strong { color:#0f172a; font-weight:700; font-size:13px; }
                .ke-promo-mini__empty { text-align:center; color:#64748b; font-size:13px; padding:14px 4px; }
                @media (max-width:480px) {
                    .ke-promo-mini__row { grid-template-columns:1fr; gap:4px; padding:12px 0; }
                    .ke-promo-mini__metric { font-size:12px; }
                }
            </style>
            <div class="ke-promo-mini__head">
                <h3 class="ke-promo-mini__title"><?php esc_html_e( 'Promoter Mini Dashboard', 'kiwi-events' ); ?></h3>
                <a class="ke-promo-mini__view" href="<?php echo esc_url( $portal ); ?>"><?php esc_html_e( 'Open dashboard →', 'kiwi-events' ); ?></a>
            </div>
            <?php if ( empty( $assignments ) ) : ?>
                <div class="ke-promo-mini__empty">
                    <?php esc_html_e( 'No active events assigned yet.', 'kiwi-events' ); ?>
                </div>
            <?php else : foreach ( $assignments as $a ) :
                $stats = $per_event[ (int) $a->event_id ] ?? array( 'tickets' => 0, 'total' => 0.0 );
            ?>
                <a class="ke-promo-mini__row" href="<?php echo esc_url( $portal ); ?>" style="text-decoration:none; color:inherit;">
                    <span class="ke-promo-mini__event"><?php echo esc_html( $a->title ); ?></span>
                    <span class="ke-promo-mini__metric"><strong><?php echo (int) $stats['tickets']; ?></strong> <?php esc_html_e( 'sold', 'kiwi-events' ); ?></span>
                    <span class="ke-promo-mini__metric"><strong><?php echo esc_html( $currency . number_format( (float) $stats['total'], 2 ) ); ?></strong></span>
                </a>
            <?php endforeach; endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ─── Attribute normalization ────────────────────────────────────── */

    private function normalize_atts( $atts, $tag ) {
        $atts = shortcode_atts( array(
            'category'  => '',
            'limit'     => '',
            'show_past' => 'false',
            // Carousel-only display knobs (ignored by the list view but
            // accepted so existing carousel template variables resolve).
            'autoplay'       => 'true',
            'interval'       => 5000,
            'columns'        => 1,
            'show_category'  => 'true',
            'show_organizer' => 'true',
        ), $atts, $tag );

        // limit: empty = no cap, otherwise positive integer.
        if ( $atts['limit'] === '' ) {
            $atts['limit'] = -1;
        } else {
            $atts['limit'] = max( 1, intval( $atts['limit'] ) );
        }

        $atts['show_past'] = filter_var( $atts['show_past'], FILTER_VALIDATE_BOOLEAN );
        $atts['category']  = sanitize_title( (string) $atts['category'] );

        return $atts;
    }

    /* ─── Query (cached per request) ─────────────────────────────────── */

    private function run_query( $atts ) {
        $key = md5( wp_json_encode( array(
            'category'  => $atts['category'],
            'limit'     => $atts['limit'],
            'show_past' => $atts['show_past'],
        ) ) );

        if ( isset( self::$query_cache[ $key ] ) ) {
            return self::$query_cache[ $key ];
        }

        $args = array(
            'post_type'      => 'ke_event',
            'post_status'    => 'publish',
            'posts_per_page' => $atts['limit'],
            'orderby'        => 'meta_value',
            'meta_key'       => '_ke_event_date_start',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        );

        $args['meta_query'] = array(
            array(
                'key'     => '_ke_event_status',
                'value'   => 'active',
                'compare' => '=',
            ),
        );

        if ( $atts['category'] !== '' ) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'ke_event_category',
                    'field'    => 'slug',
                    'terms'    => array( $atts['category'] ),
                ),
            );
        }

        $query = new WP_Query( $args );

        // Expiration filter (default). show_past=true opts back into the full
        // result set including events whose end datetime has already passed.
        // Filtering in PHP (vs meta_query) keeps the "no end date = never
        // expires" rule clean — meta_query date compares treat missing values
        // as 0 and would wrongly drop those events.
        if ( ! $atts['show_past'] ) {
            $query->posts      = self::filter_expired_posts( $query->posts );
            $query->post_count = count( $query->posts );
        }

        self::$query_cache[ $key ] = $query;
        return $query;
    }

    /* ─── Carousel ───────────────────────────────────────────────────── */

    public function render_carousel( $atts ) {
        $atts  = $this->normalize_atts( $atts, 'kiwi_events_carousel' );
        $query = $this->run_query( $atts );

        wp_enqueue_style(
            'ke-events-carousel-css',
            KE_PLUGIN_URL . 'public/css/ke-events-carousel.css',
            array( 'ke-public-css' ),
            KE_VERSION
        );
        wp_enqueue_script(
            'ke-events-carousel-js',
            KE_PLUGIN_URL . 'public/js/ke-events-carousel.js',
            array(),
            KE_VERSION,
            true
        );

        ob_start();
        include KE_PLUGIN_DIR . 'public/views/events-carousel.php';
        return ob_get_clean();
    }

    /* ─── List ───────────────────────────────────────────────────────── */

    public function render_list( $atts ) {
        $atts  = $this->normalize_atts( $atts, 'kiwi_events_list' );
        $query = $this->run_query( $atts );

        wp_enqueue_style(
            'ke-events-list-css',
            KE_PLUGIN_URL . 'public/css/ke-events-list.css',
            array( 'ke-public-css' ),
            KE_VERSION
        );

        ob_start();
        include KE_PLUGIN_DIR . 'public/views/events-list.php';
        return ob_get_clean();
    }

    /* ─── Calendar ───────────────────────────────────────────────────── */

    public function render_calendar( $atts ) {
        $raw = shortcode_atts( array(
            'category' => '',
            'month'    => '',
        ), $atts, 'kiwi_events_calendar' );

        $category = sanitize_title( (string) $raw['category'] );

        // month attribute: "YYYY-MM". Falls back to the current month when
        // missing or unparseable so the shortcode never renders blank.
        $month_ts = false;
        if ( preg_match( '/^(\d{4})-(\d{1,2})$/', trim( (string) $raw['month'] ), $m ) ) {
            $month_ts = strtotime( sprintf( '%04d-%02d-01', (int) $m[1], (int) $m[2] ) );
        }
        if ( ! $month_ts ) {
            $month_ts = strtotime( date( 'Y-m-01' ) );
        }

        $year  = (int) date( 'Y', $month_ts );
        $month = (int) date( 'n', $month_ts );

        // Server-side preload for the initial month — avoids a render-then-fetch
        // flash. JS picks it up from data-preload on first paint, then fetches
        // subsequent months from the REST endpoint.
        $from_str = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
        $to_str   = date( 'Y-m-t 23:59:59', $month_ts );

        $args = array(
            'post_type'      => 'ke_event',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'meta_value',
            'meta_key'       => '_ke_event_date_start',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => '_ke_event_status',
                    'value'   => 'active',
                    'compare' => '=',
                ),
                array(
                    'key'     => '_ke_event_date_start',
                    'value'   => array( $from_str, $to_str ),
                    'compare' => 'BETWEEN',
                    'type'    => 'DATETIME',
                ),
            ),
        );

        if ( $category !== '' ) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'ke_event_category',
                    'field'    => 'slug',
                    'terms'    => array( $category ),
                ),
            );
        }

        $query = new WP_Query( $args );

        // Drop events whose end datetime has already passed so navigating
        // back to a past month renders an empty grid instead of dead links.
        $query->posts      = self::filter_expired_posts( $query->posts );
        $query->post_count = count( $query->posts );

        $preload = array();
        foreach ( $query->posts as $post ) {
            $thumb_id = get_post_thumbnail_id( $post->ID );
            $preload[] = array(
                'id'             => $post->ID,
                'title'          => get_the_title( $post ),
                'slug'           => $post->post_name,
                'permalink'      => get_permalink( $post->ID ),
                'banner_url'     => $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '',
                'start_datetime' => (string) get_post_meta( $post->ID, '_ke_event_date_start', true ),
                'end_datetime'   => (string) get_post_meta( $post->ID, '_ke_event_date_end', true ),
                'venue_name'     => (string) get_post_meta( $post->ID, '_ke_event_venue', true ),
            );
        }

        wp_enqueue_style(
            'ke-events-calendar-css',
            KE_PLUGIN_URL . 'public/css/ke-events-calendar.css',
            array( 'ke-public-css' ),
            KE_VERSION
        );
        wp_enqueue_script(
            'ke-events-calendar-js',
            KE_PLUGIN_URL . 'public/js/ke-events-calendar.js',
            array(),
            KE_VERSION,
            true
        );

        self::$calendar_instance++;
        $instance_id = 'ke-cal-' . self::$calendar_instance . '-' . wp_generate_password( 6, false, false );

        // Expose vars to the view.
        $view = array(
            'instance_id'    => $instance_id,
            'category'       => $category,
            'year'           => $year,
            'month'          => $month,
            'preload_events' => $preload,
            'rest_url'       => esc_url_raw( rest_url( 'ke/v1/calendar-events' ) ),
            'week_start'     => (int) get_option( 'start_of_week', 0 ),
        );

        ob_start();
        include KE_PLUGIN_DIR . 'public/views/events-calendar.php';
        return ob_get_clean();
    }

    /* ─── Organizers catalog ─────────────────────────────────────────── */

    /**
     * [kiwi_organizers]
     *
     * Horizontally-scrollable catalog of every ke_organizer taxonomy term,
     * alphabetical by name, each card showing logo + name + count of active
     * (non-expired) events. ALL terms render, including those with zero
     * active events — the catalog is a discovery surface, not a top-N list.
     */
    public function render_organizers( $atts = array() ) {
        $terms = get_terms( array(
            'taxonomy'   => 'ke_organizer',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return '';
        }

        $organizers = array();
        foreach ( $terms as $term ) {
            $logo_id  = (int) get_term_meta( $term->term_id, 'ke_organizer_logo', true );
            $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
            $count    = $this->count_active_events_for_organizer( (int) $term->term_id );

            $organizers[] = array(
                'name'         => $term->name,
                'slug'         => $term->slug,
                'url'          => home_url( '/organizers/' . $term->slug . '/' ),
                'logo_url'     => $logo_url ?: '',
                'initials'     => $this->organizer_initials( $term->name ),
                'active_count' => $count,
                /* translators: %d is the number of active events for an organizer card */
                'count_label'  => sprintf(
                    _n( '%d event', '%d events', $count, 'kiwi-events' ),
                    $count
                ),
            );
        }

        wp_enqueue_style(
            'ke-organizers-catalog-css',
            KE_PLUGIN_URL . 'public/css/ke-organizers-catalog.css',
            array( 'ke-public-css' ),
            KE_VERSION
        );

        ob_start();
        include KE_PLUGIN_DIR . 'public/views/organizers-catalog.php';
        return ob_get_clean();
    }

    /**
     * Counts an organizer's published events that are status='active' and
     * NOT expired (per KE_Shortcodes::event_is_expired). Mirrors the rule
     * the public event shortcodes use, so a card's number matches what a
     * visitor would see if they opened the organizer's profile.
     */
    private function count_active_events_for_organizer( $term_id ) {
        $q = new WP_Query( array(
            'post_type'      => 'ke_event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array(
                    'key'     => '_ke_event_status',
                    'value'   => 'active',
                    'compare' => '=',
                ),
            ),
            'tax_query'      => array(
                array(
                    'taxonomy' => 'ke_organizer',
                    'field'    => 'term_id',
                    'terms'    => array( (int) $term_id ),
                ),
            ),
        ) );

        $count = 0;
        foreach ( $q->posts as $event_id ) {
            if ( ! self::event_is_expired( (int) $event_id ) ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Up-to-two-letter uppercase initials for the no-logo placeholder.
     * Multibyte-safe via mb_substr / mb_strtoupper so accented organizer
     * names (e.g. "Ángela & Co.") render correctly.
     */
    private function organizer_initials( $name ) {
        $name = trim( wp_strip_all_tags( (string) $name ) );
        if ( $name === '' ) return '?';
        $parts = preg_split( '/\s+/u', $name );
        if ( count( $parts ) >= 2 ) {
            return mb_strtoupper( mb_substr( $parts[0], 0, 1 ) . mb_substr( $parts[1], 0, 1 ) );
        }
        return mb_strtoupper( mb_substr( $name, 0, 2 ) );
    }
}
