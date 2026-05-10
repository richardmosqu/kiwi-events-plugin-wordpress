<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Countdown until event start.
 * Available vars: $extra, $extra_config, $event_id
 */

$start = get_post_meta( $event_id, '_ke_event_date_start', true );
if ( ! $start ) return;

$show_seconds = ! empty( $extra_config['show_seconds'] );
$live_msg     = $extra_config['message_when_live'] ?? 'Event is happening now!';
$tz           = get_post_meta( $event_id, '_ke_event_timezone', true ) ?: wp_timezone_string();

try {
    $dt = new DateTime( $start, new DateTimeZone( $tz ) );
    $iso = $dt->format( DateTime::ATOM );
} catch ( Exception $e ) {
    return;
}
?>
<div class="ke-content-section ke-extra ke-extra-countdown"
     data-ke-countdown
     data-start="<?php echo esc_attr( $iso ); ?>"
     data-show-seconds="<?php echo $show_seconds ? '1' : '0'; ?>"
     data-live-message="<?php echo esc_attr( $live_msg ); ?>">
    <p class="ke-section-label">Countdown</p>
    <h2 class="ke-section-title">Starts in</h2>
    <div class="ke-countdown-grid">
        <div class="ke-countdown-cell"><span class="ke-countdown-num" data-unit="d">--</span><span class="ke-countdown-unit">days</span></div>
        <div class="ke-countdown-cell"><span class="ke-countdown-num" data-unit="h">--</span><span class="ke-countdown-unit">hours</span></div>
        <div class="ke-countdown-cell"><span class="ke-countdown-num" data-unit="m">--</span><span class="ke-countdown-unit">min</span></div>
        <?php if ( $show_seconds ) : ?>
        <div class="ke-countdown-cell"><span class="ke-countdown-num" data-unit="s">--</span><span class="ke-countdown-unit">sec</span></div>
        <?php endif; ?>
    </div>
    <p class="ke-countdown-live" hidden></p>
</div>
<script>
(function() {
    var root = document.currentScript.previousElementSibling;
    if (!root || !root.hasAttribute('data-ke-countdown')) return;
    var target     = new Date(root.dataset.start).getTime();
    var showSec    = root.dataset.showSeconds === '1';
    var liveMsg    = root.dataset.liveMessage;
    var cells      = {
        d: root.querySelector('[data-unit="d"]'),
        h: root.querySelector('[data-unit="h"]'),
        m: root.querySelector('[data-unit="m"]'),
        s: root.querySelector('[data-unit="s"]'),
    };
    var grid = root.querySelector('.ke-countdown-grid');
    var live = root.querySelector('.ke-countdown-live');

    function tick() {
        var diff = target - Date.now();
        if (diff <= 0) {
            if (grid) grid.hidden = true;
            if (live) { live.hidden = false; live.textContent = liveMsg; }
            return;
        }
        var d = Math.floor(diff / 86400000);
        var h = Math.floor((diff / 3600000) % 24);
        var m = Math.floor((diff / 60000) % 60);
        var s = Math.floor((diff / 1000) % 60);
        if (cells.d) cells.d.textContent = d;
        if (cells.h) cells.h.textContent = String(h).padStart(2, '0');
        if (cells.m) cells.m.textContent = String(m).padStart(2, '0');
        if (cells.s) cells.s.textContent = String(s).padStart(2, '0');
    }
    tick();
    setInterval(tick, showSec ? 1000 : 30000);
})();
</script>
