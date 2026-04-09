<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Plugin deactivation cleanup
 */
class KE_Deactivator {

    public static function deactivate() {
        flush_rewrite_rules();
    }
}
