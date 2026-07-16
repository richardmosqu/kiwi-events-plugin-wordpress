<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * View for [kiwi_organizers].
 *
 * In-scope vars:
 *   $organizers — array of arrays with keys:
 *     name, slug, url, logo_url, initials, active_count, count_label
 *
 * The outer wrap class ke-organizers-wrap exists so the stylesheet can
 * defensively reset link styling for WordPress.com global injection, which
 * targets bare anchors/buttons but not class-scoped descendants.
 */
?>
<div class="ke-organizers-wrap">
    <div class="ke-organizers-catalog" role="list">
        <?php foreach ( $organizers as $o ) : ?>
            <a
                class="ke-organizer-card"
                href="<?php echo esc_url( $o['url'] ); ?>"
                role="listitem"
                aria-label="<?php echo esc_attr( sprintf( '%s — %s', $o['name'], $o['count_label'] ) ); ?>"
            >
                <span class="ke-organizer-card-logo" aria-hidden="true">
                    <?php if ( $o['logo_url'] !== '' ) : ?>
                        <img
                            class="ke-organizer-card-logo-img"
                            src="<?php echo esc_url( $o['logo_url'] ); ?>"
                            alt=""
                            loading="lazy"
                            decoding="async"
                        >
                    <?php else : ?>
                        <span class="ke-organizer-card-initials"><?php echo esc_html( $o['initials'] ); ?></span>
                    <?php endif; ?>
                </span>
                <span class="ke-organizer-card-name"><?php echo esc_html( $o['name'] ); ?></span>
                <span class="ke-organizer-card-count"><?php echo esc_html( $o['count_label'] ); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
