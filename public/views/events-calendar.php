<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Vars from KE_Shortcodes::render_calendar():
 *   $view = [
 *     instance_id    => unique wrapper id,
 *     category       => slug (may be empty),
 *     year, month    => initial year + 1-based month,
 *     preload_events => array of events for the initial month,
 *     rest_url       => /wp-json/ke/v1/calendar-events
 *     week_start     => 0=Sun ... 6=Sat (WP "Week starts on" setting)
 *   ]
 */

$instance_id    = $view['instance_id'];
$category       = $view['category'];
$year           = (int) $view['year'];
$month          = (int) $view['month'];
$preload_events = $view['preload_events'];
$rest_url       = $view['rest_url'];
$week_start     = (int) $view['week_start'];

// Localized full month name + year for the header.
$month_label = date_i18n( 'F Y', strtotime( sprintf( '%04d-%02d-01', $year, $month ) ) );

// Weekday abbreviations, rotated to honor the WP "Week starts on" setting.
$wp_weekdays = array(
    __( 'Sun' ), __( 'Mon' ), __( 'Tue' ), __( 'Wed' ),
    __( 'Thu' ), __( 'Fri' ), __( 'Sat' ),
);
$weekdays = array();
for ( $i = 0; $i < 7; $i++ ) {
    $weekdays[] = $wp_weekdays[ ( $week_start + $i ) % 7 ];
}

$preload_json = wp_json_encode( $preload_events ? $preload_events : array() );
$preload_key  = sprintf( '%04d-%02d', $year, $month );
?>
<div id="<?php echo esc_attr( $instance_id ); ?>"
     class="ke-calendar"
     data-category="<?php echo esc_attr( $category ); ?>"
     data-rest-url="<?php echo esc_attr( $rest_url ); ?>"
     data-year="<?php echo esc_attr( $year ); ?>"
     data-month="<?php echo esc_attr( $month ); ?>"
     data-week-start="<?php echo esc_attr( $week_start ); ?>"
     data-preload-key="<?php echo esc_attr( $preload_key ); ?>"
     data-preload-events='<?php echo esc_attr( $preload_json ); ?>'>

    <div class="ke-calendar-header">
        <h2 class="ke-calendar-month-label" aria-live="polite"><?php echo esc_html( $month_label ); ?></h2>
        <div class="ke-calendar-nav" role="group" aria-label="<?php esc_attr_e( 'Calendar navigation', 'kiwi-events' ); ?>">
            <button type="button" class="ke-calendar-nav-btn ke-calendar-prev" aria-label="<?php esc_attr_e( 'Previous month', 'kiwi-events' ); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>
            </button>
            <button type="button" class="ke-calendar-today" aria-label="<?php esc_attr_e( 'Go to today', 'kiwi-events' ); ?>"><?php esc_html_e( 'Today', 'kiwi-events' ); ?></button>
            <button type="button" class="ke-calendar-nav-btn ke-calendar-next" aria-label="<?php esc_attr_e( 'Next month', 'kiwi-events' ); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </button>
        </div>
    </div>

    <div class="ke-calendar-divider" aria-hidden="true"></div>

    <div class="ke-calendar-weekdays" role="row">
        <?php foreach ( $weekdays as $wd ) : ?>
            <div class="ke-calendar-weekday" role="columnheader"><?php echo esc_html( $wd ); ?></div>
        <?php endforeach; ?>
    </div>

    <div class="ke-calendar-grid" role="grid" aria-label="<?php esc_attr_e( 'Events calendar', 'kiwi-events' ); ?>">
        <!-- Cells injected by JS -->
    </div>

    <div class="ke-calendar-empty" role="status" hidden>
        <?php esc_html_e( 'No upcoming events in this category.', 'kiwi-events' ); ?>
    </div>

    <div class="ke-calendar-panel" hidden>
        <div class="ke-calendar-panel-header"></div>
        <div class="ke-calendar-panel-events"></div>
    </div>
</div>
