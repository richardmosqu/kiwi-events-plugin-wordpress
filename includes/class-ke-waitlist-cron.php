<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Waitlist release sweep.
 *
 * Every 5 minutes: find events that still have people waiting, check whether
 * their KE_Sales_Schedule opening moment has passed, and queue the "ya están
 * a la venta" email for everyone on the list.
 *
 * WP-Cron is request-driven and can fire late on a quiet site, so this class
 * is ONLY the email trigger. The purchase gate itself is always computed at
 * render/checkout time from the stored datetime (KE_Sales_Schedule::is_pending),
 * never from whether this sweep has run.
 *
 * Plumbing mirrors KE_Reservations_Cron (interval registration, init
 * self-heal, admin-gated manual trigger, activate/deactivate).
 */
class KE_Waitlist_Cron {

    const HOOK     = 'ke_waitlist_release_sweep';
    /** Shared with KE_Reservations_Cron — registration is idempotent. */
    const INTERVAL = 'ke_five_minutes';

    /** Seconds between queued messages, matching KE_Promoter_Notifications. */
    const STAGGER_SECS = 2;

    /**
     * Hard ceiling on messages queued per tick, across all events.
     *
     * Deliberately MAX_PER_TICK * STAGGER_SECS ≈ the 300s tick interval: one
     * tick's staggered sends finish right as the next tick starts, so the
     * queue drains at a steady ~0.5 msg/s. A larger ceiling would schedule
     * delay windows that overlap the following ticks and burst well past the
     * rate the stagger is supposed to guarantee.
     */
    const MAX_PER_TICK = 150;

    /** Events inspected per tick. Each one costs a post + meta read. */
    const SCAN_LIMIT = 100;

    /** Where the last tick stopped scanning, so the next one resumes there. */
    const CURSOR_OPTION = 'ke_waitlist_sweep_cursor';

    public function __construct() {
        add_filter( 'cron_schedules', array( $this, 'register_interval' ) );
        add_action( 'init',           array( $this, 'ensure_scheduled' ) );
        add_action( self::HOOK,       array( $this, 'run' ) );
        add_action( 'admin_init',     array( $this, 'maybe_run_manually' ) );
    }

    /**
     * Add the 5-minute schedule if nothing registered it yet. Guarded so it
     * coexists with KE_Reservations_Cron, which declares the same key.
     */
    public function register_interval( $schedules ) {
        if ( ! isset( $schedules[ self::INTERVAL ] ) ) {
            $schedules[ self::INTERVAL ] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 5 minutes (KiwiEvents)', 'kiwi-events' ),
            );
        }
        return $schedules;
    }

    /**
     * Belt-and-suspenders: WP-Cron entries scheduled at activation get lost on
     * managed hosts (object-cache flushes, cron resets). Re-add on every init
     * — wp_schedule_event is a no-op when the hook is already scheduled.
     */
    public function ensure_scheduled() {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 60, self::INTERVAL, self::HOOK );
        }
    }

    /**
     * The sweep. Walks events that still have pending waitlist rows:
     *   - event gone / trashed / cancelled → park the rows, never mail them
     *   - sale still in the future         → leave them alone
     *   - sale open (or schedule removed)  → claim each row and queue its mail
     *
     * Claiming is a conditional UPDATE (KE_Waitlist::claim), so two overlapping
     * sweeps can never send the same person twice.
     */
    public function run() {
        $stats = array(
            'events'    => 0,
            'queued'    => 0,
            'waiting'   => 0,
            'skipped'   => 0,
            'cancelled' => 0,
            'failed'    => 0,
        );

        if ( ! class_exists( 'KE_Waitlist' ) || ! class_exists( 'KE_Sales_Schedule' ) ) {
            return $stats;
        }

        // Walk the pending events in id order, resuming where the last tick
        // stopped and wrapping around when the tail is reached. On a site with
        // more simultaneous pre-sales than SCAN_LIMIT this is what stops the
        // high-id events from being starved forever.
        $cursor    = (int) get_option( self::CURSOR_OPTION, 0 );
        $event_ids = KE_Waitlist::pending_event_ids( self::SCAN_LIMIT, $cursor );
        if ( empty( $event_ids ) && $cursor > 0 ) {
            $event_ids = KE_Waitlist::pending_event_ids( self::SCAN_LIMIT, 0 );
        }

        $sent      = 0;
        $last_seen = 0;
        $walked    = true;

        foreach ( $event_ids as $event_id ) {
            // Out of budget. Leave the cursor on the last event we actually
            // inspected so the next tick picks up exactly here — advancing
            // past events this tick never looked at would skip them until the
            // next full wrap-around.
            if ( $sent >= self::MAX_PER_TICK ) {
                $walked = false;
                break;
            }
            $last_seen = $event_id;

            $post = get_post( $event_id );

            // Post is gone for good — park the rows so the sweep stops
            // rescanning an event that will never open.
            if ( ! $post || $post->post_type !== 'ke_event' ) {
                $stats['cancelled'] += KE_Waitlist::cancel_pending_for_event( $event_id );
                continue;
            }

            // Trash is a SOFT delete in this plugin (DELETE /events/{id} calls
            // wp_trash_post), so the rows stay pending and recover with the
            // event instead of being cancelled behind the organizer's back.
            if ( $post->post_status === 'trash' ) {
                $stats['skipped']++;
                continue;
            }

            $event_status = get_post_meta( $event_id, '_ke_event_status', true ) ?: 'active';
            if ( $event_status === 'cancelled' ) {
                $stats['cancelled'] += KE_Waitlist::cancel_pending_for_event( $event_id );
                continue;
            }

            // Not public yet: the email's whole point is a link to the event,
            // and a draft/pending/private permalink 404s for the recipient.
            // Hold the list until the organizer publishes.
            if ( $post->post_status !== 'publish' ) {
                $stats['skipped']++;
                continue;
            }

            // Still counting down — nothing to do for this event yet.
            if ( KE_Sales_Schedule::is_pending( $event_id ) ) {
                $stats['waiting']++;
                continue;
            }

            $stats['events']++;
            $ctx_base = self::build_context( $event_id, $post );
            $rows     = KE_Waitlist::get_pending( $event_id, KE_Waitlist::RELEASE_BATCH );

            foreach ( $rows as $row ) {
                if ( $sent >= self::MAX_PER_TICK ) {
                    $walked = false;
                    break;
                }

                // Claim first: if queueing blows up afterwards we would rather
                // hand the row back explicitly than risk sending it twice.
                if ( ! KE_Waitlist::claim( $row->id ) ) {
                    continue;
                }

                $ctx = array_merge( $ctx_base, array(
                    'name' => (string) ( $row->name ?? '' ),
                ) );

                $queued = 0;
                try {
                    if ( class_exists( 'KE_Email_Queue' ) ) {
                        $queued = (int) KE_Email_Queue::enqueue( 'tickets_on_sale', $row->email, $ctx, $sent * self::STAGGER_SECS );
                    }
                } catch ( \Throwable $e ) {
                    error_log( 'KiwiEvents waitlist release threw for row ' . (int) $row->id . ': ' . $e->getMessage() );
                }

                if ( $queued > 0 ) {
                    $stats['queued']++;
                    $sent++;   // only a real send consumes a stagger slot
                    continue;
                }

                // enqueue() returns 0 without throwing (bad address, failed
                // insert). Give the row back so the next tick retries —
                // unless the address itself is undeliverable, which no amount
                // of retrying fixes.
                $stats['failed']++;
                if ( is_email( $row->email ) ) {
                    KE_Waitlist::release_claim( $row->id );
                }
                error_log( 'KiwiEvents waitlist release could not queue row ' . (int) $row->id );
            }
        }

        // Wrap only when the whole page was walked AND it was the last page.
        $next_cursor = ( $walked && count( $event_ids ) < self::SCAN_LIMIT ) ? 0 : $last_seen;
        update_option( self::CURSOR_OPTION, $next_cursor, false );

        if ( $stats['queued'] || $stats['cancelled'] || $stats['failed'] ) {
            error_log( sprintf(
                'KiwiEvents waitlist sweep: events=%d queued=%d waiting=%d skipped=%d cancelled=%d failed=%d',
                $stats['events'], $stats['queued'], $stats['waiting'], $stats['skipped'], $stats['cancelled'], $stats['failed']
            ) );
        }

        return $stats;
    }

    /** Shared email context for one event's blast. */
    private static function build_context( $event_id, $post = null ) {
        $post = $post ?: get_post( $event_id );

        $date_display = '';
        $date_start   = (string) get_post_meta( $event_id, '_ke_event_date_start', true );
        if ( $date_start !== '' ) {
            $tz = class_exists( 'KE_Sales_Schedule' ) ? KE_Sales_Schedule::timezone_for( $event_id ) : wp_timezone();
            try {
                $dt = new DateTimeImmutable( str_replace( 'T', ' ', $date_start ), $tz );
                $date_display = wp_date(
                    (string) get_option( 'date_format', 'F j, Y' ) . ' · ' . (string) get_option( 'time_format', 'g:i a' ),
                    $dt->getTimestamp(),
                    $tz
                );
            } catch ( Exception $e ) {
                $date_display = '';
            }
        }

        return array(
            'event_id'     => (int) $event_id,
            'event_title'  => $post ? (string) $post->post_title : '',
            'event_url'    => (string) get_permalink( $event_id ),
            'event_date'   => $date_display,
            'venue'        => (string) get_post_meta( $event_id, '_ke_event_venue', true ),
        );
    }

    /**
     * Admin-gated manual trigger for verification without waiting for a tick:
     *   /wp-admin/?ke_run_waitlist_sweep=1
     * Requires manage_options + a fresh nonce — use manual_run_url().
     */
    public function maybe_run_manually() {
        if ( ! isset( $_GET['ke_run_waitlist_sweep'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        check_admin_referer( 'ke_run_waitlist_sweep' );

        $stats = $this->run();
        wp_die(
            '<h1>KiwiEvents — Waitlist sweep complete</h1>'
            . '<p>Events released: ' . (int) $stats['events'] . '</p>'
            . '<p>Emails queued: ' . (int) $stats['queued'] . '</p>'
            . '<p>Events still counting down: ' . (int) $stats['waiting'] . '</p>'
            . '<p>Events skipped (trashed or not published yet): ' . (int) $stats['skipped'] . '</p>'
            . '<p>Rows parked (event cancelled/deleted): ' . (int) $stats['cancelled'] . '</p>'
            . '<p>Failures: ' . (int) $stats['failed'] . '</p>'
            . '<p><a href="' . esc_url( admin_url( 'admin.php?page=kiwi-events-waitlist' ) ) . '">← Waitlist</a></p>',
            'Waitlist sweep complete',
            array( 'response' => 200 )
        );
    }

    /** Nonced URL admins can hit to run the sweep manually. */
    public static function manual_run_url() {
        return wp_nonce_url(
            add_query_arg( 'ke_run_waitlist_sweep', '1', admin_url() ),
            'ke_run_waitlist_sweep'
        );
    }

    /** Activation: schedule the recurring sweep. Called from KE_Activator. */
    public static function activate() {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 60, self::INTERVAL, self::HOOK );
        }
    }

    /** Deactivation: clear the recurring sweep. Called from KE_Deactivator. */
    public static function deactivate() {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
        wp_clear_scheduled_hook( self::HOOK );
    }
}
