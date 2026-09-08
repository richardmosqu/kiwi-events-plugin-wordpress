/**
 * KiwiEvents — Reservations bottom sheet (public)
 *
 * One screen, two taps: the customer opens the sheet, says what they want
 * (area + how many) and who they are, and presses Reserve. The old
 * party → Continue → contact → Submit staircase is gone. Capacity is
 * re-fetched on open so a stale page render never blocks a customer once
 * seats free up, and the party size is clamped to what is actually left.
 *
 * Mirrors ke-checkout.js patterns (shared .ke-sheet design tokens).
 */
jQuery(document).ready(function ($) {
    'use strict';

    // ─── Bail if not configured for this page ──────────────────────────
    var resvCfg = (typeof window.kePublicResv !== 'undefined') ? window.kePublicResv : null;
    if (!resvCfg || !resvCfg.enabled) return;

    var $sheet   = $('#ke-resv-sheet');
    var $overlay = $('#ke-resv-overlay');
    if (!$sheet.length) return;

    // ─── DOM ────────────────────────────────────────────────────────────
    var $stepForm    = $('#ke-resv-step-form');
    var $stepSuccess = $('#ke-resv-step-success');
    var $form        = $('#ke-resv-form');
    var $partyVal    = $('#ke-resv-party-val');
    var $partyHidden = $('#ke-resv-party');
    var $partyHint   = $('#ke-resv-party-hint');
    var $arrival     = $('#ke-resv-arrival');
    var $areasWrap   = $('#ke-resv-areas-wrap');
    var $areasGrid   = $('#ke-resv-areas-grid');
    var $availLine   = $('#ke-resv-availability-line');
    var $msg         = $('#ke-resv-msg');
    var $submitBtn   = $('#ke-resv-submit');
    var $extrasBox   = $('#ke-resv-extras-container');

    // ─── State ──────────────────────────────────────────────────────────
    var partySize      = 2;
    var selectedArea   = null;   // null when no areas configured
    var sheetCloseable = false;
    var ajaxInFlight   = false;
    var capacityState  = null;   // {total, used, remaining, areas:[]}

    var rest  = (typeof kePublic !== 'undefined' && kePublic.restUrl) ? kePublic.restUrl : '/wp-json/ke/v1/';
    var nonce = (typeof kePublic !== 'undefined' && kePublic.nonce)   ? kePublic.nonce   : '';
    rest = rest.replace(/\/?$/, '/');
    var ajaxUrl = resvCfg.ajaxUrl || (typeof kePublic !== 'undefined' ? kePublic.ajaxUrl : '') || '';
    var userLoggedIn = !!(typeof kePublic !== 'undefined' && kePublic.user && kePublic.user.loggedIn);

    var hasAreas   = Array.isArray(resvCfg.areas) && resvCfg.areas.length > 0;
    var totalCap   = parseInt(resvCfg.totalCapacity, 10) || 0;
    var manualMode = resvCfg.mode === 'manual';
    var submitIdle = manualMode ? 'Request reservation' : 'Reserve';

    // ─── Utilities ──────────────────────────────────────────────────────
    function showMsg(text, kind) {
        $msg.text(text)
            .removeClass('ke-msg-error ke-msg-success')
            .addClass(kind === 'success' ? 'ke-msg-success' : 'ke-msg-error')
            .show();
    }
    function hideMsg() { $msg.hide().removeClass('ke-msg-error ke-msg-success').text(''); }

    // Point the customer at the field that needs attention: scroll it into
    // view inside the sheet and focus it (skip focus for radio groups).
    function attention($el, focus) {
        if (!$el || !$el.length) return;
        try { $el[0].scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (_) {}
        if (focus !== false) { setTimeout(function () { try { $el.trigger('focus'); } catch (_) {} }, 120); }
    }

    function pad2(n) { return n < 10 ? '0' + n : '' + n; }
    // Local wall-clock string for <input type="datetime-local"> — never
    // toISOString(), which is UTC and lands hours off in Panama.
    function localDatetimeString(d) {
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()) + 'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    }
    function defaultArrivalString() {
        if (resvCfg.eventStart) return String(resvCfg.eventStart).replace(' ', 'T');
        var d = new Date();
        d.setMinutes(0, 0, 0);
        d.setHours(d.getHours() + 1);
        return localDatetimeString(d);
    }
    function arrivalIsSelect() { return $arrival.is('select'); }

    // ─── Capacity ───────────────────────────────────────────────────────
    function effectiveRemaining() {
        if (!capacityState) return totalCap;
        if (selectedArea) {
            var match = (capacityState.areas || []).find(function (a) { return a.name === selectedArea; });
            if (match) return parseInt(match.remaining, 10) || 0;
        }
        return parseInt(capacityState.remaining, 10) || 0;
    }

    function setPartySize(n) {
        partySize = Math.max(1, n);
        var cap = effectiveRemaining();
        if (cap > 0 && partySize > cap) partySize = cap;
        $partyVal.text(partySize);
        $partyHidden.val(partySize);
        $partyHint.text(partySize === 1 ? 'Just you' : 'Including you');
        updateAvailabilityLine();
    }

    function updateAvailabilityLine() {
        var showAreaCap  = resvCfg.showAreaCapacity !== false;
        var showTotalCap = resvCfg.showTotalCapacity !== false;
        var label;
        if (!capacityState) {
            label = hasAreas ? 'Pick your area and tell us how many' : 'Tell us how many you are';
        } else {
            var remaining = effectiveRemaining();
            if (selectedArea) {
                label = showAreaCap
                    ? (remaining + (remaining === 1 ? ' spot' : ' spots') + ' left in ' + selectedArea)
                    : ('Selected: ' + selectedArea);
            } else {
                label = showTotalCap
                    ? (remaining + ' of ' + capacityState.total + ' spots left')
                    : (hasAreas ? 'Pick your area and tell us how many' : 'Tell us how many you are');
            }
        }
        $availLine.text(label);

        // Mirror the EVENT-WIDE count to the page's pill so the user can see
        // it without opening the sheet — only when the publisher allowed it.
        // Always the total, whatever area is selected in the sheet: the old
        // code skipped this whenever an area was picked, so the pill kept
        // saying "40 of 40" after a booking.
        var $pageDisplay = $('[data-resv-remaining-display]');
        if ($pageDisplay.length && capacityState) {
            var rem = parseInt(capacityState.remaining, 10) || 0;
            if (rem <= 0) {
                $pageDisplay.html('<span class="ke-resv-capacity-num">Fully booked</span>');
            } else if (showTotalCap) {
                $pageDisplay.html(
                    '<span class="ke-resv-capacity-num">' + rem + '</span>' +
                    '<span class="ke-resv-capacity-sub">of ' + capacityState.total + ' spots left</span>'
                );
            } else {
                $pageDisplay.empty();
            }
        }
    }

    // ─── Areas ──────────────────────────────────────────────────────────
    // Radio-card pattern: each area is a <label> wrapping a hidden radio, so
    // keyboard focus + screen-reader semantics work without custom JS. The
    // .is-selected class drives the visuals (CSS owns them).
    function renderAreas() {
        if (!hasAreas) return;
        $areasWrap.show();
        $areasGrid.empty();
        var showAreaCap = resvCfg.showAreaCapacity !== false;

        // Keep the user's pick if still available, else preselect the first
        // open area — one less decision for a single-area venue.
        var firstAvailable = null;
        var stillValid     = false;
        resvCfg.areas.forEach(function (a) {
            var st  = capacityState ? (capacityState.areas || []).find(function (x) { return x.name === a.name; }) : null;
            var rem = st ? st.remaining : a.capacity;
            if (rem > 0) {
                if (firstAvailable === null) firstAvailable = a.name;
                if (selectedArea === a.name) stillValid = true;
            }
        });
        if (!stillValid) selectedArea = firstAvailable;

        resvCfg.areas.forEach(function (a) {
            var st   = capacityState ? (capacityState.areas || []).find(function (x) { return x.name === a.name; }) : null;
            var rem  = st ? st.remaining : a.capacity;
            var full = rem <= 0;
            var sel  = !full && selectedArea === a.name;

            var classes = 'ke-area-card';
            if (sel)  classes += ' is-selected';
            if (full) classes += ' is-soldout';

            var metaHtml = '';
            if (full) {
                metaHtml = '<div class="ke-area-soldout-badge">Sold out</div>';
            } else if (showAreaCap) {
                metaHtml = '<div class="ke-area-meta">' + rem + (rem === 1 ? ' spot' : ' spots') + ' available</div>';
            }
            var descHtml = a.description ? '<div class="ke-area-desc">' + escapeHtml(a.description) + '</div>' : '';

            $areasGrid.append(
                '<label class="' + classes + '" data-area="' + escapeAttr(a.name) + '">' +
                  '<input type="radio" name="ke-resv-area" value="' + escapeAttr(a.name) + '"' +
                    (sel ? ' checked' : '') + (full ? ' disabled' : '') + ' class="ke-area-radio">' +
                  '<div class="ke-area-name">' + escapeHtml(a.name) + '</div>' +
                  descHtml + metaHtml +
                '</label>'
            );
        });
        updateAvailabilityLine();
    }

    $(document).on('change', 'input[name="ke-resv-area"]', function () {
        selectedArea = $(this).val();
        $('.ke-area-card').removeClass('is-selected');
        $(this).closest('.ke-area-card').addClass('is-selected');
        hideMsg();
        setPartySize(partySize); // re-clamp against the area's cap
    });

    // ─── Extras ─────────────────────────────────────────────────────────
    function renderExtras() {
        $extrasBox.empty();
        var fields = Array.isArray(resvCfg.extraFields) ? resvCfg.extraFields : [];
        if (!fields.length) return;
        fields.forEach(function (f) {
            var id     = 'ke-resv-xf-' + f.id;
            var req    = f.required ? ' <span class="ke-required">*</span>' : '';
            var helper = f.helper ? '<div class="ke-field-helper">' + escapeHtml(f.helper) + '</div>' : '';
            var input;
            if (f.type === 'textarea') {
                input = '<textarea id="' + id + '" class="ke-resv-extra-field" rows="2" data-field-id="' + escapeAttr(f.id) + '" placeholder="' + escapeAttr(f.label) + '"' + (f.required ? ' required' : '') + '></textarea>';
            } else if (f.type === 'select') {
                var opts = '<option value="">— Select —</option>';
                (f.options || []).forEach(function (o) { opts += '<option value="' + escapeAttr(o) + '">' + escapeHtml(o) + '</option>'; });
                input = '<select id="' + id + '" class="ke-resv-extra-field" data-field-id="' + escapeAttr(f.id) + '"' + (f.required ? ' required' : '') + '>' + opts + '</select>';
            } else {
                var t = f.type === 'email' ? 'email' : (f.type === 'phone' ? 'tel' : (f.type === 'number' ? 'number' : 'text'));
                input = '<input type="' + t + '" id="' + id + '" class="ke-resv-extra-field" data-field-id="' + escapeAttr(f.id) + '" placeholder="' + escapeAttr(f.label) + '"' + (f.required ? ' required' : '') + '>';
            }
            $extrasBox.append(
                '<div class="ke-sheet-field">' +
                  '<label class="ke-field-label" for="' + id + '">' + escapeHtml(f.label) + req + '</label>' +
                  input + helper +
                '</div>'
            );
        });
    }

    // ─── Sheet lifecycle ────────────────────────────────────────────────
    function openSheet() {
        $stepForm.show();
        $stepSuccess.hide();
        $overlay.addClass('active');
        $sheet.addClass('active');
        $('body').css('overflow', 'hidden');
        sheetCloseable = false;
        setTimeout(function () { sheetCloseable = true; }, 450);
        try { $sheet.scrollTop(0); } catch (_) {}
    }

    function closeSheet() {
        $overlay.removeClass('active');
        $sheet.removeClass('active');
        $('body').css('overflow', '');
    }

    function fetchAvailability() {
        return $.ajax({
            url: rest + 'events/' + resvCfg.eventId + '/reservations/availability',
            method: 'GET',
            cache: false
        }).done(function (resp) {
            if (resp && resp.enabled) {
                capacityState = {
                    total: resp.total,
                    used: resp.used,
                    remaining: resp.remaining,
                    areas: resp.areas || []
                };
                renderAreas();
                setPartySize(partySize);
                if (effectiveRemaining() <= 0 && !hasAreas) {
                    showMsg('Sorry — this event is fully booked right now.', 'error');
                    $submitBtn.prop('disabled', true);
                } else {
                    $submitBtn.prop('disabled', false);
                }
            }
        }).fail(function () {
            // Keep the sheet usable; the server re-checks capacity on submit.
            updateAvailabilityLine();
        });
    }

    // Fresh, session-correct REST nonce for logged-in users. WordPress.com
    // serves cached HTML, so the nonce baked into the page can be stale by
    // the time the customer taps Reserve — the API then answers
    // rest_cookie_invalid_nonce. admin-ajax is never page-cached. Logged-out
    // visitors don't need one at all.
    var freshNonce = null;
    function getNonce() {
        if (!userLoggedIn || !ajaxUrl) return $.Deferred().resolve(nonce).promise();
        if (freshNonce) return $.Deferred().resolve(freshNonce).promise();
        return $.ajax({ url: ajaxUrl + '?action=ke_fresh_nonce', method: 'POST', cache: false })
            .then(function (d) {
                freshNonce = (d && d.success && d.data && d.data.nonce) ? d.data.nonce : nonce;
                return freshNonce;
            }, function () { return $.Deferred().resolve(nonce).promise(); });
    }

    // ─── Open trigger ───────────────────────────────────────────────────
    $(document).on('click', '#ke-resv-open-btn:not([disabled])', function (e) {
        e.preventDefault();
        e.stopPropagation();

        // Reuse the access gate from kePublic when login is required.
        var accessCfg = (typeof kePublic !== 'undefined' && kePublic.access) ? kePublic.access : null;
        var userCfg   = (typeof kePublic !== 'undefined' && kePublic.user)   ? kePublic.user   : null;
        if (accessCfg && accessCfg.requireLogin && (!userCfg || !userCfg.loggedIn)) {
            var redirect = encodeURIComponent(window.location.href);
            var url = (accessCfg.loginUrl || '#') + ((accessCfg.loginUrl || '').indexOf('?') === -1 ? '?' : '&') + 'redirect_to=' + redirect;
            window.location.href = url;
            return;
        }

        // Fresh start each time the sheet opens.
        if ($form.length) $form[0].reset();
        partySize = 2;
        selectedArea = null;
        hideMsg();
        if (!arrivalIsSelect() && !$arrival.val()) $arrival.val(defaultArrivalString());
        renderExtras();
        setPartySize(partySize);
        $submitBtn.prop('disabled', false).find('.ke-resv-submit-label').text(submitIdle);

        openSheet();
        fetchAvailability();
    });

    // ─── Close ──────────────────────────────────────────────────────────
    $overlay.on('click', function (e) { e.preventDefault(); if (sheetCloseable) closeSheet(); });
    $('#ke-resv-close-btn').on('click', function (e) { e.preventDefault(); closeSheet(); });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && sheetCloseable && $sheet.hasClass('active')) closeSheet();
    });

    // ─── Stepper ────────────────────────────────────────────────────────
    $('#ke-resv-party-minus').on('click', function (e) { e.preventDefault(); hideMsg(); setPartySize(partySize - 1); });
    $('#ke-resv-party-plus').on('click',  function (e) { e.preventDefault(); hideMsg(); setPartySize(partySize + 1); });
    setPartySize(partySize);

    // ─── Submit ─────────────────────────────────────────────────────────
    $form.on('submit', function (e) {
        e.preventDefault();
        if (ajaxInFlight) return;

        // 1 · what they want
        if (hasAreas && !selectedArea) {
            showMsg('Choose an area to continue.', 'error');
            attention($areasWrap, false);
            return;
        }
        if (capacityState && effectiveRemaining() < partySize) {
            var hideTotal = resvCfg.showTotalCapacity === false;
            var hideArea  = resvCfg.showAreaCapacity === false;
            var hide      = selectedArea ? hideArea : hideTotal;
            var rem       = effectiveRemaining();
            showMsg(hide
                ? 'Sorry, ' + (selectedArea ? 'this area' : 'this event') + ' is fully booked for that time. Try a different area or time.'
                : ('Only ' + rem + (rem === 1 ? ' spot' : ' spots') + ' left' + (selectedArea ? ' in ' + selectedArea : '') + '. Please reduce your party size.'), 'error');
            attention($('.ke-resv-party-row'), false);
            return;
        }

        // 2 · who they are
        var name  = ($('#ke-resv-name').val()  || '').trim();
        var phone = ($('#ke-resv-phone').val() || '').trim();
        var email = resvCfg.showEmailField ? ($('#ke-resv-email').val() || '').trim() : '';
        var notes = resvCfg.showNotesField ? ($('#ke-resv-notes').val() || '').trim() : '';
        var arrival = ($arrival.val() || '').trim();

        if (!name)  { showMsg('Please enter your name.', 'error');          attention($('#ke-resv-name'));  return; }
        if (!phone) { showMsg('Please enter your phone number.', 'error');  attention($('#ke-resv-phone')); return; }
        if (resvCfg.showEmailField && (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email))) {
            showMsg('Please enter a valid email address.', 'error'); attention($('#ke-resv-email')); return;
        }
        if (!arrival) { showMsg('Please pick an arrival time.', 'error'); attention($arrival); return; }

        var extras = {};
        var $missing = null;
        $extrasBox.find('.ke-resv-extra-field').each(function () {
            var $f  = $(this);
            var fid = $f.data('field-id');
            var s   = ($f.val() == null) ? '' : String($f.val()).trim();
            if (fid) extras[fid] = s;
            if ($f.prop('required') && !s && !$missing) $missing = $f;
        });
        if ($missing) { showMsg('Please complete all required fields.', 'error'); attention($missing); return; }

        hideMsg();
        ajaxInFlight = true;
        sheetCloseable = false;
        $submitBtn.prop('disabled', true).find('.ke-resv-submit-label').text(manualMode ? 'Sending…' : 'Reserving…');

        var payload = {
            event_id:       resvCfg.eventId,
            customer_name:  name,
            customer_email: email,
            customer_phone: phone,
            party_size:     partySize,
            arrival_time:   arrival,
            area:           selectedArea || '',
            notes:          notes,
            extra_fields:   extras
        };

        function resetSubmit() {
            ajaxInFlight = false;
            sheetCloseable = true;
            $submitBtn.prop('disabled', false).find('.ke-resv-submit-label').text(submitIdle);
        }

        getNonce().then(function (n) {
            return $.ajax({
                url: rest + 'reservations',
                method: 'POST',
                data: payload,
                beforeSend: function (xhr) { if (n) xhr.setRequestHeader('X-WP-Nonce', n); }
            });
        }).done(function (resp) {
            if (resp && resp.success) {
                $('#ke-resv-success-msg').text(resp.message || '');
                $('#ke-resv-success-code').text((resp.reservation_code || '').toUpperCase());
                $('#ke-resv-success-title').text(resp.confirmation_mode === 'manual' ? 'Request sent' : 'You’re booked!');
                $stepForm.hide();
                $stepSuccess.show();
                try { $sheet.scrollTop(0); } catch (_) {}
                ajaxInFlight = false;
                sheetCloseable = true;
            } else {
                showMsg((resp && resp.message) || 'Could not create your reservation.', 'error');
                resetSubmit();
            }
        }).fail(function (xhr) {
            var msg = 'Could not submit your reservation. Please try again.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
            showMsg(msg, 'error');
            resetSubmit();
            // Capacity may have moved under us — refresh so the numbers are honest.
            fetchAvailability();
        });
    });

    $('#ke-resv-done-btn').on('click', function (e) {
        e.preventDefault();
        closeSheet();
        ajaxInFlight = false;
        $submitBtn.prop('disabled', false).find('.ke-resv-submit-label').text(submitIdle);
        // Refresh capacity on the page so the displayed remaining count
        // reflects the booking the customer just made.
        fetchAvailability();
    });

    // ─── Helpers ────────────────────────────────────────────────────────
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function escapeAttr(s) { return escapeHtml(s); }
});
