/**
 * Kiwi Events — Community board [kiwi_board] / [kiwi_create_board].
 * Carousel, submission form, and like toggles. Vanilla JS.
 */
(function () {
    'use strict';

    var cfg = window.keBoard || {};

    /* ─────────────────────────────────────────────
     *  Carousel — step includes the gap; scroll is clamped to max; arrows
     *  disable at the ends (next only when the last card is FULLY visible,
     *  with a small tolerance for subpixel rounding).
     * ───────────────────────────────────────────── */
    function initCarousel(root) {
        if (root._keBoardInit) return;
        root._keBoardInit = true;

        var track   = root.querySelector('.ke-board-track');
        var prevBtn = root.querySelector('.ke-board-arrow--prev');
        var nextBtn = root.querySelector('.ke-board-arrow--next');
        var dotsBox = root.querySelector('.ke-board-dots');
        if (!track) return;

        function step() {
            var first = track.firstElementChild;
            if (!first) return track.clientWidth;
            var styles = getComputedStyle(track);
            var gap = parseFloat(styles.columnGap || styles.gap || 0) || 0;
            return first.getBoundingClientRect().width + gap;
        }

        function maxScroll() {
            return track.scrollWidth - track.clientWidth;
        }

        function updateArrows() {
            var max = maxScroll();
            if (prevBtn) prevBtn.disabled = track.scrollLeft <= 2;
            if (nextBtn) nextBtn.disabled = track.scrollLeft >= max - 2;
        }

        function next() {
            track.scrollTo({ left: Math.min(track.scrollLeft + step(), maxScroll()), behavior: 'smooth' });
        }

        function prev() {
            track.scrollTo({ left: Math.max(track.scrollLeft - step(), 0), behavior: 'smooth' });
        }

        /* Dots page by viewport width */
        function pageCount() {
            return Math.max(1, Math.ceil(track.scrollWidth / track.clientWidth));
        }

        function currentPage() {
            return Math.round(track.scrollLeft / track.clientWidth);
        }

        function buildDots() {
            if (!dotsBox) return;
            dotsBox.innerHTML = '';
            var pages = pageCount();
            if (pages < 2) return;
            for (var i = 0; i < pages; i++) {
                (function (page) {
                    var dot = document.createElement('button');
                    dot.type = 'button';
                    dot.className = 'ke-board-dot';
                    dot.setAttribute('aria-label', 'Página ' + (page + 1));
                    dot.addEventListener('click', function () {
                        track.scrollTo({ left: page * track.clientWidth, behavior: 'smooth' });
                    });
                    dotsBox.appendChild(dot);
                })(i);
            }
            updateDots();
        }

        function updateDots() {
            if (!dotsBox) return;
            var current = currentPage();
            dotsBox.querySelectorAll('.ke-board-dot').forEach(function (d, i) {
                d.classList.toggle('is-active', i === current);
            });
        }

        if (prevBtn) prevBtn.addEventListener('click', prev);
        if (nextBtn) nextBtn.addEventListener('click', next);

        var raf = null;
        track.addEventListener('scroll', function () {
            if (raf) return;
            raf = requestAnimationFrame(function () {
                raf = null;
                updateArrows();
                updateDots();
            });
        });

        var resizeTimer = null;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                buildDots();
                updateArrows();
            }, 150);
        });

        buildDots();
        updateArrows();
    }

    /* ─────────────────────────────────────────────
     *  Likes — optimistic toggle with rollback on failure
     * ───────────────────────────────────────────── */
    function initLikes() {
        document.querySelectorAll('[data-ke-board-like]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var postId  = btn.getAttribute('data-ke-board-like');
                var countEl = btn.querySelector('[data-ke-board-like-count]');
                var labelEl = btn.querySelector('[data-ke-board-like-label]');

                var wasLiked = btn.classList.contains('is-liked');
                var oldCount = parseInt((countEl && countEl.textContent.replace(/[^\d]/g, '')) || '0', 10);

                function paint(liked, count) {
                    btn.classList.toggle('is-liked', liked);
                    btn.setAttribute('aria-pressed', liked ? 'true' : 'false');
                    if (countEl) countEl.textContent = count.toLocaleString();
                    if (labelEl) labelEl.textContent = liked ? 'Te gusta' : 'Me gusta';
                }

                paint(!wasLiked, Math.max(0, oldCount + (wasLiked ? -1 : 1)));

                fetch(cfg.restUrl + 'board/like/' + postId, {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': cfg.nonce },
                    credentials: 'same-origin'
                }).then(function (r) {
                    if (!r.ok) throw new Error('like failed');
                    return r.json();
                }).then(function (data) {
                    paint(!!data.liked, parseInt(data.count, 10) || 0);
                }).catch(function () {
                    paint(wasLiked, oldCount); // rollback
                });
            });
        });
    }

    /* ─────────────────────────────────────────────
     *  Submission form
     * ───────────────────────────────────────────── */
    function initForm(form) {
        if (form._keBoardInit) return;
        form._keBoardInit = true;

        var msgBox  = form.querySelector('[data-ke-board-msg]');
        var success = form.parentElement.querySelector('[data-ke-board-success]');
        var submit  = form.querySelector('.ke-board-form__submit');

        function showError(text) {
            if (!msgBox) return;
            msgBox.textContent = text;
            msgBox.removeAttribute('hidden');
            msgBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (msgBox) msgBox.setAttribute('hidden', '');

            // Client-side nicety: required fields (server re-validates).
            var missing = form.querySelector(':invalid');
            if (missing) {
                missing.focus();
                showError('Completa todos los campos obligatorios.');
                return;
            }

            var galleryInput = form.querySelector('#ke-bf-gallery');
            var maxFiles = galleryInput ? parseInt(galleryInput.getAttribute('data-max-files') || '5', 10) : 5;
            if (galleryInput && galleryInput.files.length > maxFiles) {
                showError('Máximo ' + maxFiles + ' imágenes adicionales.');
                return;
            }

            var fd = new FormData();
            ['title', 'description', 'location', 'date', 'time', 'university', 'organizer', 'contact'].forEach(function (name) {
                var el = form.querySelector('[name="' + name + '"]');
                fd.append(name, el ? el.value : '');
            });

            var mainInput = form.querySelector('#ke-bf-image');
            if (mainInput && mainInput.files[0]) {
                fd.append('main_image', mainInput.files[0]);
            }
            if (galleryInput) {
                for (var i = 0; i < galleryInput.files.length; i++) {
                    fd.append('gallery_' + i, galleryInput.files[i]);
                }
            }

            submit.disabled = true;
            submit.textContent = 'Enviando…';

            fetch(cfg.restUrl + 'board/submit', {
                method: 'POST',
                headers: { 'X-WP-Nonce': cfg.nonce },
                credentials: 'same-origin',
                body: fd
            }).then(function (r) {
                return r.json().then(function (data) { return { ok: r.ok, data: data }; });
            }).then(function (res) {
                if (res.ok && res.data && res.data.success) {
                    form.setAttribute('hidden', '');
                    if (success) success.removeAttribute('hidden');
                    return;
                }
                showError((res.data && res.data.message) || 'No se pudo enviar. Intenta de nuevo.');
            }).catch(function () {
                showError('Error de conexión. Intenta de nuevo.');
            }).finally(function () {
                submit.disabled = false;
                submit.textContent = 'Enviar actividad';
            });
        });
    }

    function boot() {
        document.querySelectorAll('[data-ke-board-carousel]').forEach(initCarousel);
        document.querySelectorAll('[data-ke-board-form]').forEach(initForm);
        initLikes();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
