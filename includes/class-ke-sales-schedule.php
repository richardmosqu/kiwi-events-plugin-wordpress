<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Scheduled ticket sales — "la venta de boletos abre el día X a la hora Y".
 *
 * Event-level (not per ticket type): while the scheduled moment is still in
 * the future the whole purchase area on the public event page is replaced by
 * a big "Boletos disponibles a partir de …" notice plus an optional waitlist
 * form. When the moment arrives the notice disappears on its own (the gate is
 * computed at render time, never by cron) and KE_Waitlist_Cron mails everyone
 * who signed up.
 *
 * Configuration lives in post meta `_ke_event_sales_schedule` — see
 * self::default_config() for the shape. Waitlist rows live in
 * `wp_ke_waitlist` (KE_Waitlist).
 *
 * ── Timezone contract (READ THIS BEFORE TOUCHING COMPARISONS) ──────────
 * `open_at` is a NAIVE WALL-CLOCK string ("Y-m-d H:i:s"), matching the
 * convention used by `_ke_event_date_start` and `ke_ticket_types.sale_end`.
 * What is NEW here is that it is interpreted in the schedule's OWN timezone
 * (`timezone`, an IANA name), falling back to the event's `_ke_event_timezone`
 * and then to the site timezone. Every other datetime in the plugin is read
 * in the site timezone; this one is explicit because an organizer selling for
 * an event in another country needs "8 PM Panama" to mean 8 PM in Panama no
 * matter how the WordPress site is configured. Always go through
 * self::open_datetime() — never strtotime() the raw string.
 *
 * The single source of truth used by:
 *   - Event builder save             (admin → REST save_event)
 *   - Public event page              (public/views/single-event.php)
 *   - Free checkout                  (REST POST /checkout)
 *   - Paid checkout                  (KE_WooCommerce add_to_cart + cart re-check)
 *   - Waitlist release sweep         (KE_Waitlist_Cron)
 */
class KE_Sales_Schedule {

    const META_KEY = '_ke_event_sales_schedule';

    /** Default config shape — used when meta is empty or malformed. */
    public static function default_config() {
        return array(
            // Master switch. Off → tickets behave exactly as before.
            'enabled'          => false,
            // Wall-clock "Y-m-d H:i:s" read in `timezone` (see class docblock).
            'open_at'          => '',
            // IANA name. Empty → event timezone, then site timezone.
            'timezone'         => '',
            // Show the email-capture form under the notice.
            'waitlist_enabled' => true,
            // Optional extra line rendered under the date on the public notice.
            'note'             => '',
        );
    }

    /**
     * Read + normalise the saved configuration. Always returns the full shape
     * so callers never have to defend against missing keys.
     */
    public static function get_config( $event_id ) {
        $event_id = (int) $event_id;
        $raw = $event_id > 0 ? get_post_meta( $event_id, self::META_KEY, true ) : '';
        if ( ! is_array( $raw ) ) {
            return self::default_config();
        }
        return array(
            'enabled'          => ! empty( $raw['enabled'] ),
            'open_at'          => self::sanitize_datetime( $raw['open_at'] ?? '' ),
            'timezone'         => self::sanitize_timezone( $raw['timezone'] ?? '' ),
            'waitlist_enabled' => array_key_exists( 'waitlist_enabled', $raw ) ? ! empty( $raw['waitlist_enabled'] ) : true,
            'note'             => isset( $raw['note'] ) ? (string) $raw['note'] : '',
        );
    }

    /**
     * Sanitize a posted config blob coming from the event builder. Mirrors
     * KE_Reservations::sanitize_config() in shape: drops unknown keys, coerces
     * types, never trusts the client.
     */
    public static function sanitize_config( $input ) {
        $out = self::default_config();
        if ( ! is_array( $input ) ) {
            return $out;
        }
        $out['enabled']          = ! empty( $input['enabled'] );
        $out['open_at']          = self::sanitize_datetime( $input['open_at'] ?? '' );
        $out['timezone']         = self::sanitize_timezone( $input['timezone'] ?? '' );
        $out['waitlist_enabled'] = array_key_exists( 'waitlist_enabled', $input ) ? ! empty( $input['waitlist_enabled'] ) : true;
        $out['note']             = sanitize_textarea_field( (string) ( $input['note'] ?? '' ) );

        // A schedule without a date is not a schedule. Normalising here means
        // every consumer can trust `enabled` on its own.
        if ( $out['open_at'] === '' ) {
            $out['enabled'] = false;
        }
        return $out;
    }

    /**
     * Normalize a datetime input into "Y-m-d H:i:s". Accepts the HTML
     * datetime-local format ("Y-m-d\TH:i") and MySQL datetime. Returns '' for
     * empty/unparseable input — same contract as
     * KE_Ticket_Types::sanitize_sale_end() except it returns '' instead of
     * null because this value lives inside a meta array, not a SQL column.
     */
    public static function sanitize_datetime( $value ) {
        $value = trim( (string) ( $value ?? '' ) );
        if ( $value === '' || strpos( $value, '0000-00-00' ) === 0 ) return '';
        $value = str_replace( 'T', ' ', $value );
        if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})(:\d{2})?$/', $value ) ) {
            return '';
        }
        if ( strlen( $value ) === 16 ) {
            $value .= ':00';
        }
        return $value;
    }

    /** Only accept real IANA identifiers; anything else falls back to ''. */
    public static function sanitize_timezone( $value ) {
        $value = trim( (string) ( $value ?? '' ) );
        if ( $value === '' ) return '';
        return in_array( $value, timezone_identifiers_list(), true ) ? $value : '';
    }

    /** Format a stored open_at for an <input type="datetime-local">. */
    public static function format_for_input( $raw ) {
        $raw = self::sanitize_datetime( $raw );
        if ( $raw === '' ) return '';
        return substr( $raw, 0, 10 ) . 'T' . substr( $raw, 11, 5 );
    }

    /**
     * Resolve the timezone the schedule's wall clock should be read in:
     * schedule timezone → event timezone → site timezone.
     */
    public static function timezone_for( $event_id, $cfg = null ) {
        if ( ! is_array( $cfg ) ) {
            $cfg = self::get_config( $event_id );
        }
        $candidates = array(
            $cfg['timezone'] ?? '',
            (string) get_post_meta( (int) $event_id, '_ke_event_timezone', true ),
        );
        foreach ( $candidates as $tz ) {
            $tz = self::sanitize_timezone( $tz );
            if ( $tz !== '' ) {
                try {
                    return new DateTimeZone( $tz );
                } catch ( Exception $e ) {
                    // Fall through to the site timezone.
                }
            }
        }
        return wp_timezone();
    }

    /**
     * The absolute instant sales open, or null when nothing is scheduled.
     * This is the only correct way to compare `open_at` against "now".
     */
    public static function open_datetime( $event_id, $cfg = null ) {
        if ( ! is_array( $cfg ) ) {
            $cfg = self::get_config( $event_id );
        }
        if ( empty( $cfg['enabled'] ) || empty( $cfg['open_at'] ) ) {
            return null;
        }
        try {
            return new DateTimeImmutable( $cfg['open_at'], self::timezone_for( $event_id, $cfg ) );
        } catch ( Exception $e ) {
            return null;
        }
    }

    /** True when the event has a usable scheduled opening (past or future). */
    public static function is_scheduled( $event_id ) {
        return self::open_datetime( $event_id ) !== null;
    }

    /**
     * True when ticket sales have NOT opened yet — the gate every purchase
     * surface checks. Fail-open by design: a malformed or absent schedule
     * never blocks a sale.
     */
    public static function is_pending( $event_id, $cfg = null ) {
        $open = self::open_datetime( $event_id, $cfg );
        if ( $open === null ) return false;
        return $open > self::now();
    }

    /** True when the public notice should also offer the waitlist form. */
    public static function waitlist_open( $event_id, $cfg = null ) {
        if ( ! is_array( $cfg ) ) {
            $cfg = self::get_config( $event_id );
        }
        return ! empty( $cfg['waitlist_enabled'] ) && self::is_pending( $event_id, $cfg );
    }

    /** Unix timestamp of the opening moment, or 0. */
    public static function open_timestamp( $event_id, $cfg = null ) {
        $open = self::open_datetime( $event_id, $cfg );
        return $open ? $open->getTimestamp() : 0;
    }

    /** ISO-8601 (with offset) opening moment for the front-end countdown. */
    public static function open_iso( $event_id, $cfg = null ) {
        $open = self::open_datetime( $event_id, $cfg );
        return $open ? $open->format( DateTime::ATOM ) : '';
    }

    /**
     * Human-readable pieces of the opening moment, formatted in the
     * schedule's timezone and the site's locale (wp_date translates the
     * weekday/month names). Returns empty strings when nothing is scheduled.
     *
     *   day   → "viernes"
     *   date  → "5 de septiembre de 2026"   (site date_format)
     *   time  → "8:00 PM"                   (site time_format)
     *   tz    → "GMT-5"                     (see tz_label)
     *   full  → "viernes, 5 de septiembre de 2026 · 8:00 PM (GMT-5)"
     */
    public static function labels( $event_id, $cfg = null ) {
        $empty = array( 'day' => '', 'date' => '', 'time' => '', 'tz' => '', 'full' => '' );
        $open  = self::open_datetime( $event_id, $cfg );
        if ( ! $open ) return $empty;

        $tz   = $open->getTimezone();
        $ts   = $open->getTimestamp();
        $day  = wp_date( 'l', $ts, $tz );
        $date = wp_date( (string) get_option( 'date_format', 'F j, Y' ), $ts, $tz );
        $time = wp_date( (string) get_option( 'time_format', 'g:i a' ), $ts, $tz );
        $tzl  = self::tz_label( $open );

        return array(
            'day'   => $day,
            'date'  => $date,
            'time'  => $time,
            'tz'    => $tzl,
            'full'  => trim( $day . ', ' . $date . ' · ' . $time . ( $tzl !== '' ? ' (' . $tzl . ')' : '' ) ),
        );
    }

    /**
     * Short timezone label, always in the "GMT-5" shape.
     *
     * PHP's own abbreviation is not used on purpose: it renders America/Panama
     * as "EST", which reads as a US timezone to a Panamanian buyer. The UTC
     * offset is unambiguous everywhere and is what the notice promises.
     */
    public static function tz_label( DateTimeInterface $dt ) {
        $offset  = $dt->getOffset();
        $sign    = $offset < 0 ? '-' : '+';
        $offset  = abs( $offset );
        $hours   = (int) floor( $offset / 3600 );
        $minutes = (int) floor( ( $offset % 3600 ) / 60 );
        return 'GMT' . $sign . $hours . ( $minutes ? sprintf( ':%02d', $minutes ) : '' );
    }

    /** "Now" as a DateTimeImmutable, matching KE_Ticket_Types::is_sales_closed. */
    private static function now() {
        return function_exists( 'current_datetime' )
            ? current_datetime()
            : new DateTimeImmutable( 'now', wp_timezone() );
    }

    /**
     * Message shown when a purchase is rejected because sales have not opened.
     * Shared by the free and paid checkout paths so both read identically.
     */
    public static function closed_message( $event_id ) {
        $labels = self::labels( $event_id );
        if ( $labels['full'] === '' ) {
            return __( 'La venta de boletos para este evento aún no está abierta.', 'kiwi-events' );
        }
        return sprintf(
            /* translators: %s: formatted date and time when ticket sales open. */
            __( 'La venta de boletos abre el %s. Aún no puedes comprar.', 'kiwi-events' ),
            $labels['full']
        );
    }
}
