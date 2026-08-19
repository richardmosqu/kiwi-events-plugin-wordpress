<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Plugin deactivation cleanup
 */
class KE_Deactivator {

    public static function deactivate() {
        self::clear_cron_jobs();
        flush_rewrite_rules();
    }

    /** Unschedule all KiwiEvents cron hooks so a deactivated plugin stays quiet. */
    private static function clear_cron_jobs() {
        if ( class_exists( 'KE_Reservations_Cron' ) ) {
            KE_Reservations_Cron::deactivate();
        }
        if ( class_exists( 'KE_Waitlist_Cron' ) ) {
            KE_Waitlist_Cron::deactivate();
        }
    }
}
