<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Hour-by-hour event timeline.
 * Available vars: $extra, $extra_config, $event_id
 */

// New shape: slots: [{ time, title, description }]. Legacy: items: [{ time, title, desc }].
$raw = is_array( $extra_config['slots'] ?? null ) ? $extra_config['slots'] : ( is_array( $extra_config['items'] ?? null ) ? $extra_config['items'] : array() );
$slots = array();
foreach ( $raw as $it ) {
    if ( ! is_array( $it ) ) continue;
    $title = (string) ( $it['title'] ?? '' );
    if ( ! $title ) continue;
    $slots[] = array(
        'time'        => (string) ( $it['time'] ?? '' ),
        'title'       => $title,
        'description' => (string) ( $it['description'] ?? $it['desc'] ?? '' ),
    );
}
if ( empty( $slots ) ) return;

// Render HH:MM 24h as "h:MM AM/PM".
$fmt = function( $hhmm ) {
    if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $hhmm, $m ) ) return $hhmm;
    $h = (int) $m[1];
    $mn = $m[2];
    $period = $h >= 12 ? 'PM' : 'AM';
    $h12 = $h % 12;
    if ( $h12 === 0 ) $h12 = 12;
    return $h12 . ':' . $mn . ' ' . $period;
};
?>
<div class="ke-content-section ke-extra ke-extra-schedule">
    <p class="ke-section-label">Schedule</p>
    <h2 class="ke-section-title">Event Timeline</h2>
    <ol class="ke-schedule-timeline">
        <?php foreach ( $slots as $slot ) : ?>
            <li class="ke-schedule-slot">
                <div class="ke-schedule-time"><?php echo esc_html( $slot['time'] ? $fmt( $slot['time'] ) : '' ); ?></div>
                <div class="ke-schedule-marker" aria-hidden="true">
                    <span class="ke-schedule-dot"></span>
                </div>
                <div class="ke-schedule-body">
                    <div class="ke-schedule-title"><?php echo esc_html( $slot['title'] ); ?></div>
                    <?php if ( $slot['description'] !== '' ) : ?>
                        <div class="ke-schedule-desc"><?php echo esc_html( $slot['description'] ); ?></div>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
</div>
