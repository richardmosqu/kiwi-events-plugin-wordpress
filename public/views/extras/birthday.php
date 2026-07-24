<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * "Cumpleaños" extra — birthday-package widget.
 *
 * A self-contained accent card (its own section in the extras flow): title,
 * benefits (line breaks preserved but escaped — no HTML executes), and a link
 * to request more info in a new tab. It is a widget, not a button+modal.
 *
 * Fail-safe: renders nothing unless title, description AND link are all present.
 *
 * Available vars: $extra, $extra_config, $event_id
 */

$bday_title = isset( $extra_config['title'] )       ? (string) $extra_config['title']       : '';
$bday_desc  = isset( $extra_config['description'] )  ? (string) $extra_config['description']  : '';
$bday_link  = isset( $extra_config['link'] )         ? (string) $extra_config['link']         : '';

if ( $bday_title === '' || trim( $bday_desc ) === '' || $bday_link === '' ) {
    return;
}
?>
<div class="ke-content-section ke-extra ke-bday-widget">
    <div class="ke-bday-card">
        <div class="ke-bday-card-head">
            <span class="ke-bday-card-icon" aria-hidden="true">🎂</span>
            <h2 class="ke-bday-card-title"><?php echo esc_html( $bday_title ); ?></h2>
        </div>
        <p class="ke-bday-card-desc"><?php echo nl2br( esc_html( $bday_desc ) ); ?></p>
        <a class="ke-bday-card-link" href="<?php echo esc_url( $bday_link ); ?>" target="_blank" rel="noopener noreferrer">
            <?php esc_html_e( 'Solicitar información', 'kiwi-events' ); ?>
        </a>
    </div>
</div>
