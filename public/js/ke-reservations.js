/**
 * KiwiEvents — Reservations bottom sheet (public)
 *
 * Mirrors ke-checkout.js patterns: single bottom sheet, three steps
 * (party → contact → success). Capacity is re-fetched on open so a
 * stale page render never blocks a customer once seats free up.
 */
jQuery(document).ready(function ($) {
    'use strict';

    // ─── Bail if not configured for this page ──────────────────────────
    var resvCfg = (typeof window.kePublicResv !== 'undefined') ? window.kePublicResv : null;
    if (!resvCfg || !resvCfg.enabled) return;

    var $sheet    = $('#ke-resv-sheet');
    var $overlay  = $('#ke-resv-overlay');
    if (!$sheet.length) return;

    // ─── State ──────────────────────────────────────────────────────────
    var $stepParty   = $('#ke-resv-step-party');
    var $stepContact = $('#ke-resv-step-contact');
    var $stepSuccess = $('#ke-resv-step-success');
    var $partyVal    = $('#ke-resv-party-val');
    var $partyHidden = $('#ke-resv-party');
    var $arrival     = $('#ke-resv-arrival');
    var $areasWrap   = $('#ke-resv-areas-wrap');
    var $areasGrid   = $('#ke-resv-areas-grid');
    var $availLine   = $('#ke-resv-availability-line');
    var $msg1        = $('#ke-resv-msg-1');
    var $msg2        = $('#ke-resv-msg-2');
    var $continueBtn = $('#ke-resv-continue');
    var $submitBtn   = $('#ke-resv-submit');
    var $extrasBox   = $('#ke-resv-extras-container');

    var partySize       = 2;
    var selectedArea    = null;            // null when no areas configured
    var sheetCloseable  = false;
    var ajaxInFlight    = false;
    var capacityState   = null;            // {total, used, remaining, areas:[]}

    var rest = (typeof kePublic !== 'undefined' && kePublic.restUrl) ? kePublic.restUrl : '/wp-json/ke/v1/';
    var nonce = (typeof kePublic !== 'undefined' && kePublic.nonce) ? kePublic.nonce : '';
    rest = rest.replace(/\/?$/, '/');

    var hasAreas    = Array.isArray(resvCfg.areas) && resvCfg.areas.length > 0;
    var totalCap    = parseInt(resvCfg.totalCapacity, 10) || 0;
    var manualMode  = resvCfg.mode === 'manual';

    // ─── Utilities ──────────────────────────────────────────────────────
    function showMsg($el, text, kind) {
        $el.text(text)
           .removeClass('ke-msg-error ke-msg-success')
           .addClass(kind === 'success' ? 'ke-msg-success' : 'ke-msg-error')
           .show();
    }
    function hideMsg($el) { $el.hide().removeClass('ke-msg-error ke-msg-success').text(''); }

    function defaultArrivalString() {
        // Default to event start (if reasonable) or now+1h, formatted for
        // <input type="datetime-local"> (YYYY-MM-DDTHH:MM, no seconds).
        var d = new Date();
        d.setMinutes(0, 0, 0);
        d.setHours(d.getHours() + 1);
        return d.toISOString().slice(0, 16);
    }

    function pad2(n) { return n < 10 ? '0' + n : '' + n; }

    function setPartySize(n) {
        partySize = Math.max(1, n);
        // Clamp to total capacity remaining (when known) or area cap.
        var cap = effectiveRemaining();
        if (cap > 0 && partySize > cap) partySize = cap;
        $partyVal.text(partySize);
        $partyHidden.val(partySize);
        updateAvailabilityLine();
    }

    function effectiveRemaining() {
        if (!capacityState) return totalCap;
        if (selectedArea) {
            var match = (capacityState.areas || []).find(function (a) { return a.name === selectedArea; });
            if (match) return parseInt(match.remaining, 10) || 0;
        }
        return parseInt(capacityState.remaining, 10) || 0;
    }

    function updateAvailabilityLine() {
        if (!capacityState) {
            $availLine.text('Choose your party size and arrival time');
            return;
        }
        var remaining       = effectiveRemaining();
        var showAreaCap     = resvCfg.showAreaCapacity !== false;
        var showTotalCap    = resvCfg.showTotalCapacity !== false;
        var label;
        if (selectedArea) {
            label = showAreaCap
                ? (remaining + ' spot(s) left in ' + selectedArea)
                : ('Selected area: ' + selectedArea);
        } else {
            label = showTotalCap
                ? (remaining + ' of ' + capacityState.total + ' spots left')
                : 'Choose your party size and arrival time';
        }
        $availLine.text(label);

        // Mirror to the page's pill so the user can see the live count
        // without opening the sheet — only when the publisher allowed it.
        var $pageDisplay = $('[data-resv-remaining-display]');
        if ($pageDisplay.length && !selectedArea) {
            if (remaining <= 0) {
                $pageDisplay.html('<span class="ke-resv-capacity-num">Fully booked</span>');
            } else if (showTotalCap) {
                $pageDisplay.html(
                    '<span class="ke-resv-capacity-num">' + remaining + '</span>' +
                    '<span class="ke-resv-capacity-sub">of ' + capacityState.total + ' spots left</span>'
                );
            } else {
                $pageDisplay.empty();
            }
        }
    }

    // ─── Areas ──────────────────────────────────────────────────────────
    // Radio-card pattern: each area is a <label> wrapping a hidden radio
    // input, so keyboard focus + screen-reader semantics work without
    // custom JS. Selection state is reflected via the .is-selected class
    // (driven by the change handler below) so the CSS owns all visuals —
    // no inline styles, no flood-fill default, no fancy-effect variants.
    function renderAreas() {
        if (!hasAreas) return;
        $areasWrap.show();
        $areasGrid.empty();
        var showAreaCap = resvCfg.showAreaCapacity !== false;

        // Pick a default selection on first render: keep the user's prior
        // pick if it's still available, else the first non-soldout area.
        var firstAvailable = null;
        var stillValid     = false;
        resvCfg.areas.forEach(function (a) {
            var areaState = capacityState
                ? (capacityState.areas || []).find(function (x) { return x.name === a.name; })
                : null;
            var remaining = areaState ? areaState.remaining : a.capacity;
            if (remaining > 0) {
                if (firstAvailable === null) firstAvailable = a.name;
                if (selectedArea === a.name) stillValid = true;
            }
        });
        if (!stillValid) selectedArea = firstAvailable;

        resvCfg.areas.forEach(function (a) {
            var areaState = capacityState
                ? (capacityState.areas || []).find(function (x) { return x.name === a.name; })
                : null;
            var remaining = areaState ? areaState.remaining : a.capacity;
            var full      = remaining <= 0;
            var isSel     = !full && selectedArea === a.name;

            var classes = 'ke-area-card';
            if (isSel) classes += ' is-selected';
            if (full)  classes += ' is-soldout';

            var metaHtml = '';
            if (full) {
                metaHtml = '<div class="ke-area-soldout-badge">Sold out</div>';
            } else if (showAreaCap) {
                metaHtml = '<div class="ke-area-meta">' + remaining + ' spots available</div>';
            }

            var descHtml = a.description
                ? '<div class="ke-area-desc">' + escapeHtml(a.description) + '</div>'
                : '';

            var $card = $(
                '<label class="' + classes + '" data-area="' + escapeAttr(a.name) + '">' +
                  '<input type="radio" name="ke-resv-area" value="' + escapeAttr(a.name) + '"' +
                    (isSel ? ' checked' : '') + (full ? ' disabled' : '') +
                    ' class="ke-area-radio">' +
                  '<div class="ke-area-name">' + escapeHtml(a.name) + '</div>' +
                  descHtml +
                  metaHtml +
                '</label>'
            );
            $areasGrid.append($card);
        });
    }

    $(document).on('change', 'input[name="ke-resv-area"]', function () {
        selectedArea = $(this).val();
        $('.ke-area-card').removeClass('is-selected');
        $(this).closest('.ke-area-card').addClass('is-selected');
        // After area change, re-clamp the party size against the area cap.
        setPartySize(partySize);
    });

    // ─── Extras ─────────────────────────────────────────────────────────
    // Reuses .ke-sheet-field / .ke-field-label / .ke-required from the
    // shared sheet design tokens (see ke-public.css). Don't introduce
    // reservation-specific classes here — both sheets must look identical.
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
                input = '<textarea id="' + id + '" class="ke-resv-extra-field" rows="3" data-field-id="' + escapeAttr(f.id) + '" placeholder="' + escapeAttr(f.label) + '"' + (f.required ? ' required' : '') + '></textarea>';
            } else if (f.type === 'select') {
                var opts = '<option value="">— Select —</option>';
                (f.options || []).forEach(function (o) {
                    opts += '<option value="' + escapeAttr(o) + '">' + escapeHtml(o) + '</option>';
                });
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
        $stepParty.show();
        $stepContact.hide();
        $stepSuccess.hide();
        $overlay.addClass('active');
        $sheet.addClass('active');
        $('body').css('overflow', 'hidden');
        sheetCloseable = false;
        setTimeout(function () { sheetCloseable = true; }, 450);
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
            }
        });
    }

    // ─── Open trigger ───────────────────────────────────────────────────
    $(document).on('click', '#ke-resv-open-btn:not([disabled])', function (e) {
        e.preventDefault();
        e.stopPropagation();

        // Reuse the access gate from kePublic when login is required.
        var accessCfg = (typeof kePublic !== 'undefined' && kePublic.access) ? kePublic.access : null;
        var userCfg   = (typeof kePublic !== 'undefined' && kePublic.user)   ? kePublic.user   : null;
        if (accessCfg && accessCfg.requireLogin && (!userCfg || !userCfg.loggedIn)) {
            // Reservations don't have a dedicated blocked step; bounce the
            // user through the same login URL with redirect-back behavior.
            var redirect = encodeURIComponent(window.location.href);
            var url = (accessCfg.loginUrl || '#') + ((accessCfg.loginUrl || '').indexOf('?') === -1 ? '?' : '&') + 'redirect_to=' + redirect;
            window.location.href = url;
            return;
        }

        // Seed defaults.
        if (!$arrival.val()) $arrival.val(defaultArrivalString());
        partySize = 2;
        selectedArea = null;
        hideMsg($msg1); hideMsg($msg2);
        $('#ke-resv-form')[0].reset();
        renderExtras();

        openSheet();
        fetchAvailability();
    });

    // ─── Close ──────────────────────────────────────────────────────────
    $overlay.on('click', function (e) {
        e.preventDefault();
        if (sheetCloseable) closeSheet();
    });
    $('#ke-resv-close-btn').on('click', function (e) { e.preventDefault(); closeSheet(); });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && sheetCloseable && $sheet.hasClass('active')) closeSheet();
    });

    // ─── Stepper ────────────────────────────────────────────────────────
    $('#ke-resv-party-minus').on('click', function (e) { e.preventDefault(); setPartySize(partySize - 1); });
    $('#ke-resv-party-plus').on('click', function (e)  { e.preventDefault(); setPartySize(partySize + 1); });

    // Initial paint.
    setPartySize(partySize);

    // ─── Continue → contact step ────────────────────────────────────────
    $continueBtn.on('click', function (e) {
        e.preventDefault();

        if (!$arrival.val()) {
            showMsg($msg1, 'Please pick an arrival time.', 'error');
            return;
        }
        if (hasAreas && !selectedArea) {
            showMsg($msg1, 'Please choose an area.', 'error');
            return;
        }
        if (effectiveRemaining() < partySize) {
            var hideTotal = resvCfg.showTotalCapacity === false;
            var hideArea  = resvCfg.showAreaCapacity === false;
            var hide      = selectedArea ? hideArea : hideTotal;
            var capMsg    = hide
                ? 'Sorry, this area is fully booked for that time. Try a different area or time slot.'
                : ('Only ' + effectiveRemaining() + ' spot(s) left' + (selectedArea ? ' in ' + selectedArea : '') + '. Please reduce your party size.');
            showMsg($msg1, capMsg, 'error');
            return;
        }
        hideMsg($msg1);

        sheetCloseable = false;
        $stepParty.slideUp(180);
        setTimeout(function () {
            $stepContact.slideDown(180, function () { sheetCloseable = true; });
        }, 190);
    });

    // ─── Submit ────────────────────────────────────────────────────────
    $('#ke-resv-form').on('submit', function (e) {
        e.preventDefault();
        if (ajaxInFlight) return;

        var name  = ($('#ke-resv-name').val() || '').trim();
        var phone = ($('#ke-resv-phone').val() || '').trim();
        var email = (resvCfg.showEmailField ? ($('#ke-resv-email').val() || '').trim() : '');
        var notes = (resvCfg.showNotesField ? ($('#ke-resv-notes').val() || '').trim() : '');

        if (!name || !phone) {
            showMsg($msg2, 'Please fill in your name and phone number.', 'error');
            return;
        }
        if (resvCfg.showEmailField && (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email))) {
            showMsg($msg2, 'Please provide a valid email address.', 'error');
            return;
        }

        var extras = {};
        var extrasValid = true;
        $extrasBox.find('.ke-resv-extra-field').each(function () {
            var $f = $(this);
            var fid = $f.data('field-id');
            var val = $f.val();
            var s = (val == null) ? '' : String(val).trim();
            if (fid) extras[fid] = s;
            if ($f.prop('required') && !s) extrasValid = false;
        });
        if (!extrasValid) {
            showMsg($msg2, 'Please complete all required fields.', 'error');
            return;
        }

        hideMsg($msg2);
        ajaxInFlight = true;
        sheetCloseable = false;
        $submitBtn.prop('disabled', true).find('.ke-resv-submit-label').text('Processing…');

        $.ajax({
            url: rest + 'reservations',
            method: 'POST',
            data: {
                event_id:       resvCfg.eventId,
                customer_name:  name,
                customer_email: email,
                customer_phone: phone,
                party_size:     partySize,
                arrival_time:   $arrival.val(),
                area:           selectedArea || '',
                notes:          notes,
                extra_fields:   extras
            },
            beforeSend: function (xhr) { if (nonce) xhr.setRequestHeader('X-WP-Nonce', nonce); },
        }).done(function (resp) {
            if (resp && resp.success) {
                $('#ke-resv-success-msg').text(resp.message || '');
                $('#ke-resv-success-code').text((resp.reservation_code || '').toUpperCase());
                $('#ke-resv-success-title').text(
                    resp.confirmation_mode === 'manual' ? 'Reservation submitted' : 'Reservation confirmed!'
                );
                $stepContact.hide();
                $stepSuccess.show();
                sheetCloseable = true;
            } else {
                showMsg($msg2, (resp && resp.message) || 'Could not create your reservation.', 'error');
                ajaxInFlight = false;
                sheetCloseable = true;
                $submitBtn.prop('disabled', false).find('.ke-resv-submit-label').text(manualMode ? 'Submit Request' : 'Confirm Reservation');
            }
        }).fail(function (xhr) {
            var msg = 'Could not submit your reservation. Please try again.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
            showMsg($msg2, msg, 'error');
            ajaxInFlight = false;
            sheetCloseable = true;
            $submitBtn.prop('disabled', false).find('.ke-resv-submit-label').text(manualMode ? 'Submit Request' : 'Confirm Reservation');
        });
    });

    $('#ke-resv-done-btn').on('click', function (e) {
        e.preventDefault();
        closeSheet();
        // Reset form state for next time.
        ajaxInFlight = false;
        $submitBtn.prop('disabled', false).find('.ke-resv-submit-label').text(manualMode ? 'Submit Request' : 'Confirm Reservation');
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
