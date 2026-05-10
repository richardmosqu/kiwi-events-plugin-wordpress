<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Photo gallery carousel.
 * Available vars: $extra, $extra_config, $event_id
 */

// New shape: photos: [{ photo_id, caption }]. Legacy shape: photo_ids: [id, ...].
$photos = array();
if ( is_array( $extra_config['photos'] ?? null ) ) {
    foreach ( $extra_config['photos'] as $p ) {
        if ( ! is_array( $p ) ) continue;
        $pid = (int) ( $p['photo_id'] ?? 0 );
        if ( ! $pid ) continue;
        $photos[] = array(
            'photo_id' => $pid,
            'caption'  => (string) ( $p['caption'] ?? '' ),
        );
    }
}
if ( empty( $photos ) && is_array( $extra_config['photo_ids'] ?? null ) ) {
    foreach ( $extra_config['photo_ids'] as $pid ) {
        $pid = (int) $pid;
        if ( $pid ) $photos[] = array( 'photo_id' => $pid, 'caption' => '' );
    }
}
if ( empty( $photos ) ) return;

$items = array();
foreach ( $photos as $p ) {
    $full  = wp_get_attachment_image_url( $p['photo_id'], 'large' );
    $thumb = wp_get_attachment_image_url( $p['photo_id'], 'medium_large' );
    if ( ! $full ) continue;
    $items[] = array(
        'thumb'   => $thumb ?: $full,
        'full'    => $full,
        'caption' => $p['caption'],
    );
}
if ( empty( $items ) ) return;

$gallery_id = 'ke-gallery-' . (int) $event_id;
?>
<div class="ke-content-section ke-extra ke-extra-gallery" id="<?php echo esc_attr( $gallery_id ); ?>">
    <p class="ke-section-label">Gallery</p>
    <h2 class="ke-section-title">Past Events</h2>

    <div class="ke-gallery-wrap">
        <button type="button" class="ke-gallery-arrow ke-gallery-arrow-prev" aria-label="Previous photos">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
        </button>

        <div class="ke-gallery-carousel" role="list">
            <?php foreach ( $items as $i => $it ) : ?>
                <button type="button"
                        class="ke-gallery-slide"
                        role="listitem"
                        data-index="<?php echo (int) $i; ?>"
                        data-full="<?php echo esc_url( $it['full'] ); ?>"
                        data-caption="<?php echo esc_attr( $it['caption'] ); ?>"
                        aria-label="Open photo<?php echo $it['caption'] ? ': ' . esc_attr( $it['caption'] ) : ''; ?>">
                    <img src="<?php echo esc_url( $it['thumb'] ); ?>" loading="lazy" alt="<?php echo esc_attr( $it['caption'] ); ?>">
                    <?php if ( $it['caption'] ) : ?>
                        <div class="ke-gallery-caption-overlay"><?php echo esc_html( $it['caption'] ); ?></div>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>

        <button type="button" class="ke-gallery-arrow ke-gallery-arrow-next" aria-label="Next photos">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
    </div>
</div>

<div class="ke-gallery-lightbox" id="ke-gallery-lightbox-<?php echo (int) $event_id; ?>" aria-hidden="true">
    <button type="button" class="ke-lb-close" aria-label="Close">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="6" y1="18" x2="18" y2="6"/></svg>
    </button>
    <button type="button" class="ke-lb-prev" aria-label="Previous photo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
    </button>
    <img class="ke-lb-img" src="" alt="">
    <button type="button" class="ke-lb-next" aria-label="Next photo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
    </button>
    <div class="ke-lb-caption"></div>
</div>

<script>
(function () {
    var section = document.getElementById('<?php echo esc_js( $gallery_id ); ?>');
    if (!section || section._keGalInit) return;
    section._keGalInit = true;

    var track   = section.querySelector('.ke-gallery-carousel');
    var slides  = Array.prototype.slice.call(section.querySelectorAll('.ke-gallery-slide'));
    var prevBtn = section.querySelector('.ke-gallery-arrow-prev');
    var nextBtn = section.querySelector('.ke-gallery-arrow-next');
    if (!track || !slides.length) return;

    function step() {
        var first = track.firstElementChild;
        if (!first) return track.clientWidth;
        var gap = parseFloat(getComputedStyle(track).columnGap || getComputedStyle(track).gap || 0) || 0;
        return first.getBoundingClientRect().width + gap;
    }
    function perView() {
        return Math.max(1, Math.round(track.clientWidth / step()));
    }
    function scrollByCards(dir) {
        var pv = perView();
        var target = track.scrollLeft + dir * step() * pv;
        var max = track.scrollWidth - track.clientWidth - 2;
        if (target > max) target = 0;
        if (target < 0)   target = max;
        track.scrollTo({ left: target, behavior: 'smooth' });
    }

    if (prevBtn) prevBtn.addEventListener('click', function () { scrollByCards(-1); });
    if (nextBtn) nextBtn.addEventListener('click', function () { scrollByCards( 1); });

    // ─── Lightbox ──────────────────────────────────────
    var lb = document.getElementById('ke-gallery-lightbox-<?php echo (int) $event_id; ?>');
    if (!lb) return;
    var img      = lb.querySelector('.ke-lb-img');
    var caption  = lb.querySelector('.ke-lb-caption');
    var btnPrev  = lb.querySelector('.ke-lb-prev');
    var btnNext  = lb.querySelector('.ke-lb-next');
    var btnClose = lb.querySelector('.ke-lb-close');
    var current = 0;

    function show(i) {
        if (i < 0) i = slides.length - 1;
        if (i >= slides.length) i = 0;
        current = i;
        var s = slides[i];
        img.src = s.getAttribute('data-full') || '';
        var cap = s.getAttribute('data-caption') || '';
        caption.textContent = cap;
        caption.style.display = cap ? 'block' : 'none';
        btnPrev.style.display = btnNext.style.display = slides.length > 1 ? '' : 'none';
    }
    function open(i) {
        show(i);
        lb.classList.add('is-open');
        lb.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
    function close() {
        lb.classList.remove('is-open');
        lb.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        img.src = '';
    }

    slides.forEach(function (s, i) {
        s.addEventListener('click', function () { open(i); });
    });
    btnPrev.addEventListener('click', function () { show(current - 1); });
    btnNext.addEventListener('click', function () { show(current + 1); });
    btnClose.addEventListener('click', close);
    lb.addEventListener('click', function (e) { if (e.target === lb) close(); });
    document.addEventListener('keydown', function (e) {
        if (!lb.classList.contains('is-open')) return;
        if (e.key === 'Escape')     close();
        if (e.key === 'ArrowLeft')  show(current - 1);
        if (e.key === 'ArrowRight') show(current + 1);
    });
})();
</script>
