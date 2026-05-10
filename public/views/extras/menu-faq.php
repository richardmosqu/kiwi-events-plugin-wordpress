<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Custom menu / FAQ sections as collapsible accordion.
 * Available vars: $extra, $extra_config, $event_id
 */

$sections = is_array( $extra_config['sections'] ?? null ) ? $extra_config['sections'] : array();
if ( empty( $sections ) ) return;

$allowed_body = array(
    'p'      => array(),
    'br'     => array(),
    'strong' => array(),
    'em'     => array(),
    'a'      => array( 'href' => true, 'target' => true, 'rel' => true ),
    'ul'     => array(),
    'ol'     => array(),
    'li'     => array(),
);
?>
<div class="ke-content-section ke-extra ke-extra-menu-faq">
    <p class="ke-section-label">More</p>
    <h2 class="ke-section-title">Details &amp; FAQ</h2>
    <div class="ke-faq-list">
        <?php foreach ( $sections as $i => $section ) :
            $heading = $section['heading'] ?? '';
            $body    = $section['body']    ?? '';
            if ( ! $heading ) continue;
        ?>
            <details class="ke-faq-item"<?php echo $i === 0 ? ' open' : ''; ?>>
                <summary><?php echo esc_html( $heading ); ?></summary>
                <div class="ke-faq-body">
                    <?php echo wp_kses( $body, $allowed_body ); ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
</div>
