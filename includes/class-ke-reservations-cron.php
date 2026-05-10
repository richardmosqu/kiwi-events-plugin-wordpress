<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reservations cron — auto-cancel no-show.
 *
 * Runs every 5 minutes to find confirmed reservations whose arrival_time
 * has passed by more than the event's grace period without a check-in,
 * transitions them to `cancelled_no_show`, and notifies the customer.
 *
 * Heavy lifting lives in KE_Reservations::run_no_show_sweep() — this
 * class is just the WP-Cron plumbing (interval registration, hook,
 * activate/deactivate helpers, optional admin manual trigger).
 *
 * Phase 4 of the reservations rollout.
 */
class KE_Reservations_Cron {

    const HOOK     = 'ke_reservations_no_show_sweep';
    const INTERVAL = 'ke_five_minutes';

    public function __construct() {
        add_filter( 'cron_schedules',         array( $this, 'register_interval' ) );
        add_action( 'init',                   array( $this, 'ensure_scheduled' ) );
        add_action( self::HOOK,               array( $this, 'run' ) );
        add_action( 'admin_init',             array( $this, 'maybe_run_manually' ) );
    }

    /**
     * Add a 5-minute schedule to WP-Cron's known intervals. WordPress only
     * ships hourly/twicedaily/daily by default.
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
     * Belt-and-suspenders: WP-Cron events scheduled at activation can be
     * lost (object-cache flushes, manual cron resets). Re-add on every
     * `init` if missing — wp_schedule_event itself is a no-op when the
     * hook is already scheduled, so this is cheap.
     */
    public function ensure_scheduled() {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 60, self::INTERVAL, self::HOOK );
        }
    }

    /**
     * The cron callback. Delegates to KE_Reservations::run_no_show_sweep()
     * and logs the result so site owners can audit via Query Monitor / log.
     */
    public function run() {
        if ( ! class_exists( 'KE_Reservations' ) ) return;
        $resv  = new KE_Reservations();
        $stats = $resv->run_no_show_sweep();
        if ( ! empty( $stats['cancelled'] ) || ! empty( $stats['email_fail'] ) ) {
            error_log( sprintf(
                'KiwiEvents no-show sweep: scanned=%d cancelled=%d skipped=%d email_fail=%d',
                (int) ( $stats['scanned']    ?? 0 ),
                (int) ( $stats['cancelled']  ?? 0 ),
                (int) ( $stats['skipped']    ?? 0 ),
                (int) ( $stats['email_fail'] ?? 0 )
            ) );
        }
        return $stats;
    }

    /**
     * Admin-gated manual trigger. Useful for verification without waiting
     * for the next cron tick or installing WP-CLI. Hits via:
     *   /wp-admin/?ke_run_resv_sweep=1
     * Requires manage_options + a fresh nonce (use the link emitted by
     * KE_Reservations_Cron::manual_run_url() to satisfy both).
     */
    public function maybe_run_manually() {
        if ( ! isset( $_GET['ke_run_resv_sweep'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        check_admin_referer( 'ke_run_resv_sweep' );

        $stats = $this->run();
        wp_die(
            '<h1>KiwiEvents — No-show sweep complete</h1>'
            . '<p>Scanned: ' . (int) ( $stats['scanned']    ?? 0 ) . '</p>'
            . '<p>Cancelled: ' . (int) ( $stats['cancelled']  ?? 0 ) . '</p>'
            . '<p>Skipped (grace not yet up or auto-cancel disabled): ' . (int) ( $stats['skipped']    ?? 0 ) . '</p>'
            . '<p>Email failures (cancellation still applied): ' . (int) ( $stats['email_fail'] ?? 0 ) . '</p>'
            . '<p><a href="' . esc_url( admin_url() ) . '">← Back to admin</a></p>',
            'No-show sweep complete',
            array( 'response' => 200 )
        );
    }

    /** Build a nonced URL admins can hit to run the sweep manually. */
    public static function manual_run_url() {
        return wp_nonce_url(
            add_query_arg( 'ke_run_resv_sweep', '1', admin_url() ),
            'ke_run_resv_sweep'
        );
    }

    /** Activation: schedule the recurring sweep. Called from KE_Activator. */
    public static function activate() {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            // Defer 60s so activation itself doesn't kick a sweep on the
            // very first request — gives caches/options time to settle.
            wp_schedule_event( time() + 60, self::INTERVAL, self::HOOK );
        }
    }

    /** Deactivation: clear the recurring sweep. Called from KE_Deactivator. */
    public static function deactivate() {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
        // Belt-and-suspenders: clear any other scheduled instances.
        wp_clear_scheduled_hook( self::HOOK );
    }
}
