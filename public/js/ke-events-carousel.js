(function () {
    'use strict';

    function init(root) {
        if (root._keInit) return;
        root._keInit = true;

        var track    = root.querySelector('.ke-carousel-track');
        var prevBtn  = root.querySelector('.ke-carousel-prev');
        var nextBtn  = root.querySelector('.ke-carousel-next');
        var dotsWrap = root.querySelector('.ke-carousel-dots');
        if (!track) return;

        var autoplay = root.dataset.autoplay === '1';
        var interval = parseInt(root.dataset.interval, 10) || 5000;
        var timer    = null;
        var paused   = false;
        var hovered  = false;
        var offview  = false;
        var focused  = false;

        function step() {
            var first = track.firstElementChild;
            if (!first) return track.clientWidth;
            var gap   = parseFloat(getComputedStyle(track).columnGap || getComputedStyle(track).gap || 0) || 0;
            return first.getBoundingClientRect().width + gap;
        }

        function perView() {
            var s = step();
            return Math.max(1, Math.round(track.clientWidth / s));
        }

        function pageCount() {
            var cards = track.children.length;
            return Math.max(1, Math.ceil(cards / perView()));
        }

        function currentPage() {
            var pageW = track.clientWidth;
            return Math.min(pageCount() - 1, Math.round(track.scrollLeft / pageW));
        }

        function goTo(page, smooth) {
            var pages = pageCount();
            if (page < 0) page = pages - 1;
            if (page >= pages) page = 0;
            track.scrollTo({
                left: page * track.clientWidth,
                behavior: smooth === false ? 'auto' : 'smooth'
            });
        }

        function nextCard() {
            var s   = step();
            var max = track.scrollWidth - track.clientWidth;
            // Already at the end → wrap to the start (keeps the infinite /
            // autoplay loop). Otherwise advance one card, but CLAMP to max so
            // the final step — which is shorter than a full card when the cards
            // don't divide evenly into the viewport — still lands exactly at
            // the end and fully reveals the last card. The old code did
            // `if (target > max) target = 0`, which wrapped to the start on
            // that final partial step, so the last card was never reachable.
            if (track.scrollLeft >= max - 2) {
                track.scrollTo({ left: 0, behavior: 'smooth' });
                return;
            }
            track.scrollTo({ left: Math.min(track.scrollLeft + s, max), behavior: 'smooth' });
        }
        function prevCard() {
            var s   = step();
            var max = track.scrollWidth - track.clientWidth;
            // Already at the start → wrap to the end. Otherwise step back one
            // card, clamped to 0 so the left arrow always lands on the first
            // card rather than overshooting into a wrap.
            if (track.scrollLeft <= 2) {
                track.scrollTo({ left: max, behavior: 'smooth' });
                return;
            }
            track.scrollTo({ left: Math.max(track.scrollLeft - s, 0), behavior: 'smooth' });
        }

        // ── Dots ──
        function renderDots() {
            if (!dotsWrap) return;
            var pages = pageCount();
            if (dotsWrap.children.length === pages) {
                updateDots();
                return;
            }
            dotsWrap.innerHTML = '';
            for (var i = 0; i < pages; i++) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'ke-carousel-dot';
                b.setAttribute('role', 'tab');
                b.setAttribute('aria-label', 'Go to slide ' + (i + 1));
                b.dataset.page = i;
                dotsWrap.appendChild(b);
            }
            dotsWrap.addEventListener('click', function (e) {
                var d = e.target.closest('.ke-carousel-dot');
                if (d) goTo(parseInt(d.dataset.page, 10), true);
            });
            updateDots();
        }

        function updateDots() {
            if (!dotsWrap) return;
            var cur = currentPage();
            Array.prototype.forEach.call(dotsWrap.children, function (d, i) {
                d.classList.toggle('is-active', i === cur);
                d.setAttribute('aria-selected', i === cur ? 'true' : 'false');
            });
        }

        // ── Autoplay ──
        function shouldRun() {
            return autoplay && !paused && !hovered && !offview && !focused && pageCount() > 1 && !document.hidden;
        }
        function tick() {
            if (!shouldRun()) return;
            nextCard();
        }
        function start() {
            stop();
            if (shouldRun()) timer = setInterval(tick, interval);
        }
        function stop() {
            if (timer) { clearInterval(timer); timer = null; }
        }

        // ── Hover / touch / focus / visibility ──
        root.addEventListener('mouseenter', function () { hovered = true;  start(); });
        root.addEventListener('mouseleave', function () { hovered = false; start(); });
        root.addEventListener('touchstart', function () { paused = true;  stop(); }, { passive: true });
        root.addEventListener('focusin',    function () { focused = true; start(); });
        root.addEventListener('focusout',   function () {
            if (!root.contains(document.activeElement)) { focused = false; start(); }
        });
        document.addEventListener('visibilitychange', start);

        // ── Arrows ──
        if (prevBtn) prevBtn.addEventListener('click', function () { prevCard(); start(); });
        if (nextBtn) nextBtn.addEventListener('click', function () { nextCard(); start(); });

        // ── Scroll sync for dots ──
        var scrollTO = null;
        track.addEventListener('scroll', function () {
            if (scrollTO) cancelAnimationFrame(scrollTO);
            scrollTO = requestAnimationFrame(updateDots);
        }, { passive: true });

        // ── Resize ──
        var resizeTO = null;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTO);
            resizeTO = setTimeout(function () { renderDots(); updateDots(); }, 150);
        });

        // ── Pause when out of view ──
        if ('IntersectionObserver' in window) {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (e) {
                    offview = !e.isIntersecting;
                    start();
                });
            }, { threshold: 0.2 });
            io.observe(root);
        }

        renderDots();
        start();
    }

    function boot() {
        document.querySelectorAll('.ke-carousel').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
