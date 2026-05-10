<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Accordion FAQ section.
 * Available vars: $extra, $extra_config, $event_id
 */

$title = sanitize_text_field( $extra_config['title'] ?? 'Frequently Asked Questions' ) ?: 'Frequently Asked Questions';
$items = array();
if ( is_array( $extra_config['items'] ?? null ) ) {
    foreach ( $extra_config['items'] as $it ) {
        if ( ! is_array( $it ) ) continue;
        $q = (string) ( $it['question'] ?? '' );
        $a = (string) ( $it['answer']   ?? '' );
        if ( $q === '' || $a === '' ) continue;
        $items[] = array( 'question' => $q, 'answer' => $a );
    }
}
if ( empty( $items ) ) return;

// Stable base id so aria-controls targets are unique per section instance.
$base = 'ke-faq-' . (int) $event_id;

// JSON-LD FAQPage schema for rich results.
$schema = array(
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'mainEntity' => array_map( function( $it ) {
        return array(
            '@type'          => 'Question',
            'name'           => $it['question'],
            'acceptedAnswer' => array(
                '@type' => 'Answer',
                'text'  => $it['answer'],
            ),
        );
    }, $items ),
);
?>
<script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>

<div class="ke-content-section ke-extra ke-extra-faq">
    <p class="ke-section-label">FAQ</p>
    <h2 class="ke-section-title"><?php echo esc_html( $title ); ?></h2>

    <div class="ke-faq-list">
        <?php foreach ( $items as $i => $it ) :
            $panel_id = $base . '-panel-' . $i;
            $btn_id   = $base . '-btn-'   . $i;
        ?>
            <div class="ke-faq-item">
                <button type="button"
                        class="ke-faq-question"
                        id="<?php echo esc_attr( $btn_id ); ?>"
                        aria-expanded="false"
                        aria-controls="<?php echo esc_attr( $panel_id ); ?>">
                    <span class="ke-faq-q-text"><?php echo esc_html( $it['question'] ); ?></span>
                    <span class="ke-faq-chevron" aria-hidden="true">
                        <svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="5 8 10 13 15 8"/>
                        </svg>
                    </span>
                </button>
                <div class="ke-faq-answer"
                     id="<?php echo esc_attr( $panel_id ); ?>"
                     role="region"
                     aria-labelledby="<?php echo esc_attr( $btn_id ); ?>"
                     hidden>
                    <div class="ke-faq-answer-inner"><?php echo nl2br( esc_html( $it['answer'] ) ); ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
(function () {
    var script = document.currentScript;
    var root   = script && script.previousElementSibling;
    while (root && !root.classList.contains('ke-extra-faq')) {
        root = root.previousElementSibling;
    }
    if (!root) return;

    var items = root.querySelectorAll('.ke-faq-item');

    function closeItem(item) {
        var btn    = item.querySelector('.ke-faq-question');
        var answer = item.querySelector('.ke-faq-answer');
        if (!btn || !answer) return;
        item.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
        // Animate from current height down to 0.
        answer.style.maxHeight = answer.scrollHeight + 'px';
        requestAnimationFrame(function () {
            answer.style.maxHeight = '0px';
        });
        // After the transition, hide the element.
        setTimeout(function () {
            if (!item.classList.contains('is-open')) {
                answer.hidden = true;
                answer.style.maxHeight = '';
            }
        }, 260);
    }

    function openItem(item) {
        var btn    = item.querySelector('.ke-faq-question');
        var answer = item.querySelector('.ke-faq-answer');
        if (!btn || !answer) return;
        item.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        answer.hidden = false;
        // Force layout, then set max-height so CSS transition runs.
        var target = answer.scrollHeight;
        answer.style.maxHeight = '0px';
        requestAnimationFrame(function () {
            answer.style.maxHeight = target + 'px';
        });
        // Release the height cap once the animation finishes, so it can
        // re-flow naturally on viewport changes.
        setTimeout(function () {
            if (item.classList.contains('is-open')) {
                answer.style.maxHeight = 'none';
            }
        }, 260);
    }

    function toggle(item) {
        if (item.classList.contains('is-open')) {
            closeItem(item);
            return;
        }
        // Accordion behavior: close all others first.
        items.forEach(function (other) {
            if (other !== item && other.classList.contains('is-open')) {
                closeItem(other);
            }
        });
        openItem(item);
    }

    items.forEach(function (item) {
        var btn = item.querySelector('.ke-faq-question');
        if (!btn) return;
        btn.addEventListener('click', function () { toggle(item); });
        // <button> handles Enter/Space natively — no extra keydown needed.
    });
})();
</script>
