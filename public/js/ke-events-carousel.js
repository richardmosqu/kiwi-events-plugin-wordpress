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
            var s = step();
            var max = track.scrollWidth - track.clientWidth - 2;
            var target = track.scrollLeft + s;
            if (target > max) target = 0; // infinite wrap
            track.scrollTo({ left: target, behavior: 'smooth' });
        }
        function prevCard() {
            var s = step();
            var target = track.scrollLeft - s;
            if (target < 0) target = track.scrollWidth - track.clientWidth;
            track.scrollTo({ left: target, behavior: 'smooth' });
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
