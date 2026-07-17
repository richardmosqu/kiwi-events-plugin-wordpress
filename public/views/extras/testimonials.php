<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Testimonials — logged-in users comment with optional 1–5 star rating.
 * Backed by WP native comments with comment_type = 'ke_testimonial'.
 *
 * Available vars: $extra, $extra_config, $event_id
 */

$title            = sanitize_text_field( $extra_config['title'] ?? 'Testimonials' );
$require_approval = array_key_exists( 'require_approval', $extra_config ) ? ! empty( $extra_config['require_approval'] ) : true;
$allow_ratings    = array_key_exists( 'allow_ratings', $extra_config )    ? ! empty( $extra_config['allow_ratings'] )    : true;

$is_logged_in = is_user_logged_in();
// Logged-out gates always resolve through Access Control (prod: /micuenta).
$login_url    = KE_Board::login_redirect_url( get_permalink( $event_id ) );
$per_page     = 10;

// Prime the first page server-side so visitors without JS still see comments.
$pinned = get_comments( array(
    'post_id'    => $event_id,
    'type'       => 'ke_testimonial',
    'status'     => 'approve',
    'meta_key'   => 'ke_pinned',
    'meta_value' => '1',
    'orderby'    => 'comment_date_gmt',
    'order'      => 'DESC',
    'number'     => 100,
) );
$pinned_ids = array_map( fn( $c ) => (int) $c->comment_ID, $pinned );
$unpinned = get_comments( array(
    'post_id'         => $event_id,
    'type'            => 'ke_testimonial',
    'status'          => 'approve',
    'orderby'         => 'comment_date_gmt',
    'order'           => 'DESC',
    'number'          => $per_page,
    'comment__not_in' => $pinned_ids ?: array( 0 ),
) );
$initial_rows = array_merge( $pinned, $unpinned );
$total        = (int) get_comments( array(
    'post_id' => $event_id,
    'type'    => 'ke_testimonial',
    'status'  => 'approve',
    'count'   => true,
) );
?>
<div class="ke-content-section ke-extra ke-extra-testimonials" data-event-id="<?php echo (int) $event_id; ?>" data-per-page="<?php echo (int) $per_page; ?>" data-allow-ratings="<?php echo $allow_ratings ? '1' : '0'; ?>">
    <p class="ke-section-label">Community</p>
    <h2 class="ke-section-title"><?php echo esc_html( $title ); ?></h2>

    <?php if ( $is_logged_in ) : ?>
        <form class="ke-testi-form" novalidate>
            <?php if ( $allow_ratings ) : ?>
                <div class="ke-testi-rating" role="radiogroup" aria-label="Rating">
                    <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                        <button type="button" class="ke-testi-star" data-value="<?php echo $i; ?>" aria-label="<?php echo $i; ?> star<?php echo $i > 1 ? 's' : ''; ?>">
                            <svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path d="M12 2l2.9 6.9 7.1.6-5.4 4.7 1.7 7-6.3-3.9-6.3 3.9 1.7-7L2 9.5l7.1-.6L12 2z" fill="currentColor"/></svg>
                        </button>
                    <?php endfor; ?>
                </div>
                <input type="hidden" name="rating" value="0">
            <?php endif; ?>

            <textarea class="ke-testi-textarea" name="comment" rows="3" maxlength="2000" placeholder="Share your experience…" required></textarea>

            <div class="ke-testi-form-footer">
                <span class="ke-testi-status" role="status" aria-live="polite"></span>
                <button type="submit" class="ke-testi-submit">Post</button>
            </div>
        </form>
    <?php else : ?>
        <div class="ke-testi-login">
            <a class="ke-testi-login-link" href="<?php echo esc_url( $login_url ); ?>">Log in to share your experience</a>
        </div>
    <?php endif; ?>

    <div class="ke-testi-list" role="list">
        <?php foreach ( $initial_rows as $c ) :
            $r    = (int) get_comment_meta( $c->comment_ID, 'ke_rating', true );
            $pin  = (int) get_comment_meta( $c->comment_ID, 'ke_pinned', true );
            $ava  = get_avatar_url( $c->comment_author_email, array( 'size' => 64 ) );
        ?>
            <article class="ke-testi-card<?php echo $pin ? ' is-pinned' : ''; ?>" role="listitem" data-id="<?php echo (int) $c->comment_ID; ?>">
                <img class="ke-testi-avatar" src="<?php echo esc_url( $ava ); ?>" alt="" loading="lazy">
                <div class="ke-testi-body">
                    <div class="ke-testi-meta">
                        <span class="ke-testi-author"><?php echo esc_html( $c->comment_author ); ?></span>
                        <?php if ( $pin ) : ?><span class="ke-testi-pin" aria-label="Pinned">📌</span><?php endif; ?>
                    </div>
                    <?php if ( $r > 0 ) : ?>
                        <div class="ke-testi-stars" aria-label="<?php echo $r; ?> of 5 stars">
                            <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                                <svg viewBox="0 0 24 24" width="14" height="14" class="ke-testi-star-sm<?php echo $i <= $r ? ' is-on' : ''; ?>" aria-hidden="true"><path d="M12 2l2.9 6.9 7.1.6-5.4 4.7 1.7 7-6.3-3.9-6.3 3.9 1.7-7L2 9.5l7.1-.6L12 2z" fill="currentColor"/></svg>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                    <p class="ke-testi-text"><?php echo esc_html( $c->comment_content ); ?></p>
                    <div class="ke-testi-date"><?php echo esc_html( human_time_diff( strtotime( $c->comment_date_gmt ) ) . ' ago' ); ?></div>
                </div>
            </article>
        <?php endforeach; ?>

        <?php if ( empty( $initial_rows ) ) : ?>
            <div class="ke-testi-empty">Be the first to share your experience.</div>
        <?php endif; ?>
    </div>

    <?php if ( $total > count( $initial_rows ) ) : ?>
        <button type="button" class="ke-testi-more" data-page="2">Load more</button>
    <?php endif; ?>
</div>

<script>
(function () {
    var script = document.currentScript;
    var root   = script && script.previousElementSibling;
    while (root && !root.classList.contains('ke-extra-testimonials')) {
        root = root.previousElementSibling;
    }
    if (!root) return;

    var eventId     = parseInt(root.getAttribute('data-event-id'), 10) || 0;
    var perPage     = parseInt(root.getAttribute('data-per-page'), 10) || 10;
    var allowStars  = root.getAttribute('data-allow-ratings') === '1';
    var list        = root.querySelector('.ke-testi-list');
    var moreBtn     = root.querySelector('.ke-testi-more');
    var form        = root.querySelector('.ke-testi-form');
    var stars       = root.querySelectorAll('.ke-testi-star');
    var ratingField = root.querySelector('input[name="rating"]');
    var textarea    = root.querySelector('.ke-testi-textarea');
    var submitBtn   = root.querySelector('.ke-testi-submit');
    var statusEl    = root.querySelector('.ke-testi-status');

    var ke    = (window.kePublic || {});
    var base  = (ke.restUrl || '/wp-json/ke/v1/');
    var nonce = ke.nonce || '';

    // ─── Rating picker ─────────────────────────────────────
    if (allowStars && stars.length) {
        var current = 0;
        function paint(n) {
            stars.forEach(function (s, i) {
                s.classList.toggle('is-on', (i + 1) <= n);
            });
        }
        stars.forEach(function (s) {
            var val = parseInt(s.getAttribute('data-value'), 10);
            s.addEventListener('mouseenter', function () { paint(val); });
            s.addEventListener('mouseleave', function () { paint(current); });
            s.addEventListener('click', function () {
                current = current === val ? 0 : val;
                if (ratingField) ratingField.value = current;
                paint(current);
            });
        });
    }

    // ─── Submit new comment ────────────────────────────────
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var content = (textarea.value || '').trim();
            if (!content) {
                statusEl.textContent = 'Please enter a comment.';
                return;
            }
            submitBtn.disabled = true;
            statusEl.textContent = 'Posting…';

            var body = { comment: content };
            if (allowStars && ratingField) {
                body.rating = parseInt(ratingField.value, 10) || 0;
            }

            fetch(base + 'events/' + eventId + '/testimonials', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce
                },
                body: JSON.stringify(body)
            }).then(function (r) {
                return r.json().then(function (d) { return { ok: r.ok, data: d }; });
            }).then(function (res) {
                submitBtn.disabled = false;
                if (!res.ok) {
                    statusEl.textContent = (res.data && res.data.message) || 'Could not post. Please try again.';
                    return;
                }
                textarea.value = '';
                if (allowStars && ratingField) {
                    ratingField.value = 0;
                    stars.forEach(function (s) { s.classList.remove('is-on'); });
                }
                statusEl.textContent = res.data.message || '';
                if (!res.data.pending && res.data.testimonial) {
                    var empty = list.querySelector('.ke-testi-empty');
                    if (empty) empty.remove();
                    list.insertBefore(renderCard(res.data.testimonial), list.firstChild);
                }
                setTimeout(function () { statusEl.textContent = ''; }, 4000);
            }).catch(function () {
                submitBtn.disabled = false;
                statusEl.textContent = 'Network error. Please try again.';
            });
        });
    }

    // ─── Load more ─────────────────────────────────────────
    if (moreBtn) {
        moreBtn.addEventListener('click', function () {
            var page = parseInt(moreBtn.getAttribute('data-page'), 10) || 2;
            moreBtn.disabled = true;
            moreBtn.textContent = 'Loading…';

            fetch(base + 'events/' + eventId + '/testimonials?page=' + page + '&per_page=' + perPage, {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce }
            }).then(function (r) { return r.json(); })
              .then(function (data) {
                  var rows = (data && data.items) || [];
                  // Pinned already appeared on page 1; don't repeat them.
                  rows.forEach(function (t) {
                      if (page > 1 && t.pinned) return;
                      list.appendChild(renderCard(t));
                  });
                  moreBtn.disabled = false;
                  moreBtn.textContent = 'Load more';
                  if (data.has_more) {
                      moreBtn.setAttribute('data-page', page + 1);
                  } else {
                      moreBtn.remove();
                  }
              }).catch(function () {
                  moreBtn.disabled = false;
                  moreBtn.textContent = 'Load more';
              });
        });
    }

    function renderCard(t) {
        var card = document.createElement('article');
        card.className = 'ke-testi-card' + (t.pinned ? ' is-pinned' : '');
        card.setAttribute('role', 'listitem');
        card.setAttribute('data-id', t.id);

        var starsHtml = '';
        if (t.rating > 0) {
            starsHtml = '<div class="ke-testi-stars" aria-label="' + t.rating + ' of 5 stars">';
            for (var i = 1; i <= 5; i++) {
                starsHtml += '<svg viewBox="0 0 24 24" width="14" height="14" class="ke-testi-star-sm' + (i <= t.rating ? ' is-on' : '') + '" aria-hidden="true"><path d="M12 2l2.9 6.9 7.1.6-5.4 4.7 1.7 7-6.3-3.9-6.3 3.9 1.7-7L2 9.5l7.1-.6L12 2z" fill="currentColor"/></svg>';
            }
            starsHtml += '</div>';
        }

        card.innerHTML =
            '<img class="ke-testi-avatar" src="' + escapeAttr(t.avatar || '') + '" alt="" loading="lazy">' +
            '<div class="ke-testi-body">' +
                '<div class="ke-testi-meta">' +
                    '<span class="ke-testi-author">' + escapeHtml(t.author || '') + '</span>' +
                    (t.pinned ? '<span class="ke-testi-pin" aria-label="Pinned">📌</span>' : '') +
                '</div>' +
                starsHtml +
                '<p class="ke-testi-text">' + escapeHtml(t.content || '') + '</p>' +
                '<div class="ke-testi-date">' + escapeHtml(t.date_rel || '') + '</div>' +
            '</div>';
        return card;
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function escapeAttr(s) { return escapeHtml(s); }
})();
</script>
