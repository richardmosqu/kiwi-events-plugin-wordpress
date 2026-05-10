<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Sold-out progress indicator.
 * Available vars: $extra, $extra_config, $event_id, $types
 */

$style          = $extra_config['style'] ?? 'linear';
$color          = $extra_config['color'] ?? '';
$hide_when_full = ! empty( $extra_config['hide_when_full'] );

$total = 0;
$sold  = 0;
if ( isset( $types ) && is_array( $types ) ) {
    foreach ( $types as $t ) {
        if ( ( $t->capacity_type ?? 'limited' ) === 'unlimited' ) continue;
        $total += (int) $t->quantity_total;
        $sold  += (int) $t->quantity_sold;
    }
}
if ( $total < 1 ) return;

$pct       = max( 0, min( 100, round( ( $sold / $total ) * 100 ) ) );
$is_full   = $pct >= 100;
if ( $is_full && $hide_when_full ) return;

$accent = $color ?: 'var(--ke-accent, #6366f1)';
?>
<div class="ke-content-section ke-extra ke-extra-soldout ke-soldout--<?php echo esc_attr( $style ); ?>">
    <?php if ( $is_full ) : ?>
        <span class="ke-soldout-badge">Sold Out</span>
    <?php elseif ( $style === 'badge' ) : ?>
        <span class="ke-soldout-badge ke-soldout-badge--live"
              style="background: <?php echo esc_attr( $accent ); ?>;">
            <?php echo esc_html( $pct ); ?>% booked
        </span>
    <?php elseif ( $style === 'circular' ) : ?>
        <div class="ke-soldout-circle"
             style="--pct: <?php echo (int) $pct; ?>; --color: <?php echo esc_attr( $accent ); ?>;">
            <span><?php echo esc_html( $pct ); ?>%</span>
        </div>
        <p class="ke-soldout-caption"><?php echo (int) $sold; ?> of <?php echo (int) $total; ?> tickets booked</p>
    <?php else : // linear ?>
        <p class="ke-section-label">Availability</p>
        <h2 class="ke-section-title"><?php echo esc_html( $pct ); ?>% booked</h2>
        <div class="ke-soldout-bar" role="progressbar"
             aria-valuenow="<?php echo (int) $pct; ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="ke-soldout-bar-fill"
                 style="width: <?php echo (int) $pct; ?>%; background: <?php echo esc_attr( $accent ); ?>;"></div>
        </div>
        <p class="ke-soldout-caption"><?php echo (int) $sold; ?> of <?php echo (int) $total; ?> tickets booked</p>
    <?php endif; ?>
</div>
