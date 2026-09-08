<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * "Cumpleaños" extra — the birthday-package call to action.
 *
 * single-event.php pins this directly under the tickets (page order is
 * Tickets → Cumpleaños → Reservas → the remaining extras), so it has to read
 * as the page's second action, not as one more grey info block: an
 * accent-filled card, the perks as a short checklist, one big white button.
 * The energy comes from the organizer's accent colour and the type — no glow,
 * no motion beyond a hover lift (see .impeccable.md).
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

// One perk per line (that is what the builder asks for). A single line is
// rendered as a paragraph instead of a one-item list.
$bday_perks = array_values( array_filter(
    array_map( 'trim', preg_split( '/\r\n|\r|\n/', $bday_desc ) ),
    'strlen'
) );

// WhatsApp is by far the most common destination for this link; show its
// glyph on the button so the student knows what will open.
$bday_is_wa = (bool) preg_match( '~(wa\.me|whatsapp\.com)~i', $bday_link );
?>
<div class="ke-content-section ke-extra ke-bday-widget">
    <div class="ke-bday-card">
        <div class="ke-bday-card-body">
            <span class="ke-bday-card-eyebrow">
                <span class="ke-bday-card-icon" aria-hidden="true">🎂</span>
                <?php esc_html_e( 'Cumpleaños', 'kiwi-events' ); ?>
            </span>
            <h2 class="ke-bday-card-title"><?php echo esc_html( $bday_title ); ?></h2>
            <?php if ( count( $bday_perks ) > 1 ) : ?>
                <ul class="ke-bday-card-perks">
                    <?php foreach ( $bday_perks as $perk ) : ?>
                        <li>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="9.5" opacity=".45"/>
                                <path d="M8 12.5l2.6 2.6L16.5 9"/>
                            </svg>
                            <span><?php echo esc_html( $perk ); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p class="ke-bday-card-desc"><?php echo esc_html( isset( $bday_perks[0] ) ? $bday_perks[0] : $bday_desc ); ?></p>
            <?php endif; ?>
        </div>
        <a class="ke-bday-card-link" href="<?php echo esc_url( $bday_link ); ?>" target="_blank" rel="noopener noreferrer">
            <?php if ( $bday_is_wa ) : ?>
                <svg class="ke-bday-wa" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
                </svg>
            <?php endif; ?>
            <span><?php esc_html_e( 'Solicitar información', 'kiwi-events' ); ?></span>
            <svg class="ke-bday-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 12h14M13 6l6 6-6 6"/>
            </svg>
        </a>
    </div>
</div>
