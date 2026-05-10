<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Artists / speakers lineup.
 * Available vars: $extra, $extra_config, $event_id
 */

$artists = is_array( $extra_config['artists'] ?? null ) ? $extra_config['artists'] : array();

// Drop artists with no name so the section is hidden when nothing is configured.
$artists = array_values( array_filter( $artists, function ( $a ) {
    return is_array( $a ) && ! empty( $a['name'] );
} ) );

if ( empty( $artists ) ) return;
?>
<div class="ke-content-section ke-extra ke-extra-lineup">
    <p class="ke-section-label">Lineup</p>
    <h2 class="ke-section-title">Featured Artists</h2>
    <div class="ke-lineup-scroll" role="list">
        <?php foreach ( $artists as $artist ) :
            $name     = $artist['name'];
            $photo_id = (int) ( $artist['photo_id'] ?? 0 );
            $photo    = $photo_id ? wp_get_attachment_image_url( $photo_id, 'medium' ) : '';
            $initial  = mb_strtoupper( mb_substr( $name, 0, 1 ) );
        ?>
            <div class="ke-lineup-card" role="listitem">
                <?php if ( $photo ) : ?>
                    <div class="ke-lineup-photo" style="background-image: url('<?php echo esc_url( $photo ); ?>');"></div>
                <?php else : ?>
                    <div class="ke-lineup-photo ke-lineup-photo--placeholder">
                        <span><?php echo esc_html( $initial ); ?></span>
                    </div>
                <?php endif; ?>
                <div class="ke-lineup-name"><?php echo esc_html( $name ); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
