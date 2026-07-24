/**
 * KiwiEvents — Event Builder (3-step wizard)
 */
jQuery(document).ready(function ($) {

    // ─── State ─────────────────────────────────────────────────────────────
    let currentStep = 1;
    const TOTAL_STEPS = 3;
    let ticketIndex = 0;
    let cachedTemplates = [];   // templates for selected organizer

    // Highest step the user has reached in this session. In create mode this
    // gates which step indicators are clickable (no skipping ahead). In edit
    // mode every step is treated as reachable so the user can jump anywhere.
    const isEditMode = !!window.keIsEdit;
    let maxStepReached = isEditMode ? TOTAL_STEPS : 1;

    // ─── Step navigation ───────────────────────────────────────────────────
    function goToStep(n) {
        if (n < 1 || n > TOTAL_STEPS) return;
        if (n > maxStepReached) maxStepReached = n;

        const $current = $('.ke-wizard-panel.active');
        const $target  = $('#ke-step-' + n);

        // Smooth cross-fade: fade out current, swap, fade in target.
        function swapPanels() {
            $('.ke-wizard-panel').removeClass('active is-leaving');
            $target.addClass('active is-entering');
            // Next tick: remove is-entering so the .active opacity:1 transition runs.
            requestAnimationFrame(function () {
                requestAnimationFrame(function () { $target.removeClass('is-entering'); });
            });
        }

        if ($current.length && $current.get(0) !== $target.get(0)) {
            $current.addClass('is-leaving');
            setTimeout(swapPanels, 180);
        } else {
            swapPanels();
        }

        // Nav bars
        $('.ke-wizard-nav').hide();
        $('#ke-nav-' + n).show();

        // Progress bar
        updateStepIndicators(n);

        currentStep = n;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Centralizes the active/done class logic AND the clickable/locked
    // affordances. Called whenever currentStep or maxStepReached changes.
    //
    // data-step-state semantics (see CSS):
    //   active     = current step (filled accent circle)
    //   completed  = visited; gets a small ✓ on the circle
    //   available  = clickable but not yet visited (edit mode future steps)
    //   locked     = create mode beyond maxStepReached (gray, non-clickable)
    function updateStepIndicators(n) {
        $('.ke-wizard-step-item').each(function () {
            const s = parseInt($(this).data('step'), 10);
            $(this).removeClass('active done');

            let state;
            if (s === n) {
                state = 'active';
                $(this).addClass('active');
            } else if (s < n) {
                state = 'completed';
                $(this).addClass('done');
            } else if (isEditMode) {
                // Edit mode: data already exists in DB for every step.
                // Treat future steps as visitable but not "completed by user".
                state = 'available';
            } else if (s <= maxStepReached) {
                // Create mode: user has previously walked through this step
                // and moved back — count it as completed.
                state = 'completed';
                $(this).addClass('done');
            } else {
                state = 'locked';
            }
            $(this).attr('data-step-state', state);

            const reachable = state !== 'locked';
            $(this)
                .attr('data-clickable', reachable ? 'true' : 'false')
                .attr('data-locked',    reachable ? 'false' : 'true')
                .attr('aria-disabled',  reachable ? 'false' : 'true');
        });

        // Connector line is "lit" iff neither adjacent step is locked.
        $('.ke-wizard-line').each(function (i) {
            const $left  = $('.ke-wizard-step-item[data-step="' + (i + 1) + '"]');
            const $right = $('.ke-wizard-step-item[data-step="' + (i + 2) + '"]');
            const lit = $left.attr('data-step-state') !== 'locked'
                     && $right.attr('data-step-state') !== 'locked';
            $(this)
                .attr('data-line-state', lit ? 'lit' : 'dim')
                .toggleClass('done', i < n - 1);
        });
    }

    // Attempt a jump triggered by clicking a step indicator. Enforces the
    // create-mode rules (no skipping ahead, and can't leave a step with empty
    // required fields). Edit mode is always permissive.
    function attemptJumpToStep(targetStep) {
        if (targetStep === currentStep) return;
        if (targetStep < 1 || targetStep > TOTAL_STEPS) return;

        if (!isEditMode) {
            if (targetStep > maxStepReached) {
                showError('Complete this step before jumping ahead.', currentStep);
                return;
            }
            // Validate before leaving when moving forward.
            if (targetStep > currentStep && !validateStep(currentStep)) {
                showError('Complete required fields in this step first.', currentStep);
                return;
            }
        }
        goToStep(targetStep);
    }

    // Click + keyboard handlers on the step pills.
    $(document).on('click', '.ke-wizard-step-item', function () {
        if ($(this).attr('data-locked') === 'true') return;
        const s = parseInt($(this).data('step'), 10);
        if (!isNaN(s)) attemptJumpToStep(s);
    });
    $(document).on('keydown', '.ke-wizard-step-item', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
        if ($(this).attr('data-locked') === 'true') return;
        e.preventDefault();
        const s = parseInt($(this).data('step'), 10);
        if (!isNaN(s)) attemptJumpToStep(s);
    });

    // Prime clickable/locked attributes on first load.
    updateStepIndicators(currentStep);

    // Signal to the inline vanilla-JS fallback in event-builder.php that the
    // main wizard module loaded successfully; the fallback uses this to skip
    // double-binding step handlers.
    window.keWizardJsReady = true;

    // ─── Inline error display ─────────────────────────────────────────────
    function showError(msg, step) {
        const id = '#ke-step' + (step || currentStep) + '-error';
        const $el = $(id);
        if ($el.length) {
            $el.text(msg).stop(true).show();
            setTimeout(function () { $el.fadeOut(400); }, 4000);
            window.scrollTo({ top: $el.offset().top - 80, behavior: 'smooth' });
        } else if (window.console && console.warn) {
            console.warn('KiwiEvents:', msg);
        }
    }

    function validateStep(n) {
        if (n === 1) {
            const title = $('#ke-event-title').val().trim();
            if (!title) {
                showError('Please enter an event name before continuing.', 1);
                $('#ke-event-title').focus();
                return false;
            }
            const sd = $('#ke-start-date').val();
            if (!sd) {
                showError('Please set the start date.', 1);
                $('#ke-start-date').focus();
                return false;
            }
            const ed = $('#ke-end-date').val();
            if (!ed) {
                showError('Please set the end date.', 1);
                $('#ke-end-date').focus();
                return false;
            }
        }
        if (n === 3) {
            // Reservations are now a first-class alternative to tickets.
            // The event needs ONE of: at least one ticket type, OR reservations
            // enabled. Both empty is the only configuration we reject.
            const cards = $('#ke-ticket-container .ke-tkt-card');
            const resvOn = $('#ke-resv-enabled').is(':checked');
            if (cards.length === 0 && !resvOn) {
                showError('This event needs either ticket types or reservations enabled.', 3);
                return false;
            }
            // Birthday package: if enabled, all three fields are required.
            if ($('#ke-birthday-enabled').is(':checked')) {
                if (!$('#ke-birthday-title').val().trim() ||
                    !$('#ke-birthday-description').val().trim() ||
                    !$('#ke-birthday-link').val().trim()) {
                    showError('Completa título, descripción y enlace del paquete de cumpleaños, o desactívalo.', 3);
                    return false;
                }
            }
            let valid = true;
            cards.each(function () {
                const name = $(this).find('.ke-tkt-name').val().trim();
                if (!name) {
                    showError('Every ticket type must have a name.', 3);
                    $(this).find('.ke-tkt-name').focus();
                    valid = false;
                    return false;
                }
            });
            return valid;
        }
        return true;
    }

    // Nav buttons
    $('#ke-next-1').on('click', function () {
        if (validateStep(1)) goToStep(2);
    });
    $('#ke-next-2').on('click', function () {
        goToStep(3);
        fetchOrganizerTemplates();  // refresh templates when entering step 3
    });
    $('#ke-back-2').on('click', function () { goToStep(1); });
    $('#ke-back-3').on('click', function () { goToStep(2); });

    $('#ke-draft-1, #ke-draft-2, #ke-draft-3').on('click', function () {
        if (!$('#ke-event-title').val().trim()) {
            goToStep(1);
            showError('Please enter an event name before saving.', 1);
            $('#ke-event-title').focus();
            return;
        }
        saveEvent('draft');
    });

    $('#ke-publish-btn').on('click', function () {
        if (!validateStep(3)) return;
        saveEvent('publish');
    });

    // ─── Slug field (Event URL — single-line inline editor) ──────────────
    //
    // Display row reads: "Event URL: <base>/<slug> [✎]" — one line, no card.
    // Pencil swaps the slug span for an <input> on the same line plus ✓ / ×
    // buttons. Enter confirms, Escape cancels. Validation errors render on
    // their own row below, only while editing.
    //
    // Phase 1 (this commit): no persistence of a "manually set" flag.
    //   • CREATE mode (no existing slug) mirrors the title into the slug span.
    //   • EDIT mode (existing slug) starts with the saved slug, no mirroring.
    //   • Any pencil edit pauses mirroring for the rest of the session.
    // Phase 2 will replace the in-session flag with a persistent post-meta
    // boolean so the lock survives reloads.
    (function initSlugField() {
        const $row     = $('#ke-slug-field');
        if (!$row.length) return;
        const $value   = $('#ke-slug-value');
        const $input   = $('#ke-event-slug');
        const $pencil  = $('#ke-slug-pencil');
        const $confirm = $('#ke-slug-confirm');
        const $cancel  = $('#ke-slug-cancel');
        const $error   = $('#ke-slug-error');

        const existingSlug = String(window.keExistingSlug || '');
        // Persistent lock from `_ke_slug_manually_set` post meta (Phase 2).
        // Once true, the title→slug mirror is permanently off for this event
        // across reloads. New events default to false (auto-tracking on);
        // Phase 3 migration flips existing events to true so their
        // established URLs are safe.
        let slugLockedPersistent = !!window.keSlugManuallySet;
        // True only while the inline editor is open — suppresses mirroring
        // during an in-flight edit but does NOT flip the persistent lock,
        // so cancelling the edit restores the prior mirror behavior.
        let isEditing = false;
        // Snapshot taken when entering edit mode, used to revert on cancel.
        let editStartValue = existingSlug;
        // Expose to collectFormData() so the persistent flag travels with
        // the save payload.
        window.keSlugLockedThisSession = slugLockedPersistent;

        function clientSanitize(raw) {
            return String(raw || '')
                .toLowerCase()
                .normalize('NFD')
                .replace(/[̀-ͯ]/g, '')   // strip accents
                .replace(/[^a-z0-9\s-]/g, '')
                .trim()
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-')
                .replace(/^-|-$/g, '')
                .slice(0, 60);
        }

        function showError(msg) {
            $error.text(msg || '').attr('hidden', msg ? null : true);
        }

        function commitDisplay(slug) {
            $input.val(slug);
            $value.text(slug);
        }

        function enterEditMode() {
            isEditing = true;
            editStartValue = $input.val();
            $row.attr('data-mode', 'edit');
            $value.attr('hidden', true);
            $input.attr('hidden', null).trigger('focus').trigger('select');
            $pencil.attr('hidden', true);
            $confirm.attr('hidden', null);
            $cancel.attr('hidden', null);
        }

        function exitEditMode(commitValue) {
            isEditing = false;
            const final = clientSanitize(commitValue);
            commitDisplay(final);
            $row.attr('data-mode', 'display');
            $value.attr('hidden', null);
            $input.attr('hidden', true);
            $pencil.attr('hidden', null);
            $confirm.attr('hidden', true);
            $cancel.attr('hidden', true);
            showError('');
        }

        // Title → slug mirror. Runs whenever the persistent lock is off AND
        // we're not currently editing the slug directly. Cancelling an edit
        // therefore restores the mirror; only ✓ confirm flips the lock on.
        $('#ke-event-title').on('input', function () {
            if (slugLockedPersistent || isEditing) return;
            commitDisplay(clientSanitize($(this).val()));
        });

        $pencil.on('click', enterEditMode);
        $confirm.on('click', function () {
            // Confirming a manual edit flips the persistent lock. The payload
            // ships slug_manually_set=true so the server writes the meta and
            // future title changes can't auto-overwrite this slug. Reverting
            // via × leaves the flag untouched.
            const next = clientSanitize($input.val());
            if (next !== editStartValue) {
                slugLockedPersistent = true;
                window.keSlugLockedThisSession = true;
            }
            exitEditMode($input.val());
        });
        $cancel.on('click', function () {
            exitEditMode(editStartValue);
        });
        $input.on('keydown', function (e) {
            if (e.key === 'Enter')  { e.preventDefault(); $confirm.trigger('click'); }
            if (e.key === 'Escape') { e.preventDefault(); $cancel.trigger('click'); }
        });

        // Debounced uniqueness check while typing in the inline input. Does
        // NOT flip the persistent lock — that only happens on ✓ confirm.
        let debounceTimer = null;
        let lastQuery     = null;
        $input.on('input', function () {
            const sanitized = clientSanitize($(this).val());
            if (sanitized !== $(this).val()) {
                $(this).val(sanitized);
            }
            $value.text(sanitized);

            if (!sanitized) {
                showError('Lowercase letters, numbers, and hyphens only');
                return;
            }
            // Clear the previous error while we re-check; only show the
            // result when the server responds.
            showError('');
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                const data = window.keBuilderData || {};
                const url  = (data.restUrl || '') + 'events/check-slug?slug='
                           + encodeURIComponent(sanitized)
                           + '&exclude_id=' + encodeURIComponent(window.keEventId || 0);
                lastQuery = sanitized;
                fetch(url, {
                    credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': data.nonce || '' }
                })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (lastQuery !== sanitized) return; // stale
                    if (resp && resp.available) {
                        showError('');
                        return;
                    }
                    const reasons = {
                        invalid_format: 'Lowercase letters, numbers, and hyphens only',
                        too_long:       'Too long — max 60 characters',
                        in_use:         'Already in use by another event',
                        reserved:       'Reserved slug'
                    };
                    showError(reasons[resp && resp.reason] || 'Slug is not available');
                })
                .catch(function () {
                    // Network blip: don't block the user; server will validate.
                    showError('');
                });
            }, 300);
        });
    })();

    // ─── Location type toggle ──────────────────────────────────────────────
    $('input[name="ke_location_type"]').on('change', function () {
        const val = $(this).val();
        if (val === 'virtual') {
            $('#ke-venue-fields').hide();
            $('#ke-virtual-fields').show();
        } else if (val === 'venue') {
            $('#ke-venue-fields').show();
            $('#ke-virtual-fields').hide();
        } else { // hybrid
            $('#ke-venue-fields').show();
            $('#ke-virtual-fields').show();
        }
        $('.ke-radio-option').removeClass('active');
        $(this).closest('.ke-radio-option').addClass('active');
    });

    // ─── Social links collapsible ──────────────────────────────────────────
    $('#ke-social-toggle').on('click', function () {
        const $body = $('#ke-social-body');
        const $caret = $(this).find('.ke-caret');
        if ($body.is(':visible')) {
            $body.slideUp(200);
            $caret.text('▾');
        } else {
            $body.slideDown(200);
            $caret.text('▴');
        }
    });

    // ─── Media uploader ───────────────────────────────────────────────────
    let mediaFrame;

    function openMediaUploader() {
        if (mediaFrame) { mediaFrame.open(); return; }
        mediaFrame = wp.media({
            title:    'Select Banner Image',
            button:   { text: 'Use this image' },
            multiple: false,
            library:  { type: 'image' },
        });
        mediaFrame.on('select', function () {
            const att = mediaFrame.state().get('selection').first().toJSON();
            $('#ke-banner-id').val(att.id);
            $('#ke-banner-preview').css('background-image', 'url(' + att.url + ')').show();
            $('#ke-banner-placeholder').hide();
            $('#ke-banner-btn').text('Change Image');
            $('#ke-banner-remove').show();
        });
        mediaFrame.open();
    }

    $(document).on('click', '#ke-banner-btn, #ke-banner-placeholder', function (e) {
        e.stopPropagation();
        openMediaUploader();
    });

    $(document).on('click', '#ke-banner-remove', function () {
        $('#ke-banner-id').val('');
        $('#ke-banner-preview').css('background-image', '').hide();
        $('#ke-banner-placeholder').show();
        $('#ke-banner-btn').text('Choose Image');
        $(this).hide();
    });

    // ─── Hero background image uploader (optional, separate from banner) ─────
    var heroBgFrame;
    function openHeroBgUploader() {
        if (heroBgFrame) { heroBgFrame.open(); return; }
        heroBgFrame = wp.media({
            title:    'Fondo del evento',
            button:   { text: 'Usar esta imagen' },
            multiple: false,
            library:  { type: 'image' },
        });
        heroBgFrame.on('select', function () {
            const att = heroBgFrame.state().get('selection').first().toJSON();
            $('#ke-herobg-id').val(att.id);
            $('#ke-herobg-preview').css('background-image', 'url(' + att.url + ')').show();
            $('#ke-herobg-placeholder').hide();
            $('#ke-herobg-btn').text('Cambiar imagen');
            $('#ke-herobg-remove').show();
        });
        heroBgFrame.open();
    }
    $(document).on('click', '#ke-herobg-btn, #ke-herobg-placeholder', function (e) {
        e.stopPropagation();
        openHeroBgUploader();
    });
    $(document).on('click', '#ke-herobg-remove', function () {
        $('#ke-herobg-id').val('');
        $('#ke-herobg-preview').css('background-image', '').hide();
        $('#ke-herobg-placeholder').show();
        $('#ke-herobg-btn').text('Elegir imagen');
        $(this).hide();
    });

    // ─── Historias Destacadas selector ─────────────────────────────────────
    $(document).on('change', '#ke-show-highlights', function () {
        $('#ke-hl-picker').toggle(this.checked);
    });
    $(document).on('change', '#ke-hl-all', function () {
        var on = this.checked;
        $('#ke-hl-list').css({ opacity: on ? 0.5 : 1, 'pointer-events': on ? 'none' : '' });
    });

    // ─── Cumpleaños toggle ─────────────────────────────────────────────────
    $(document).on('change', '#ke-birthday-enabled', function () {
        $('#ke-birthday-fields').toggle(this.checked);
    });

    // ─── Ticket card rendering ─────────────────────────────────────────────
    function renderTicketCard(data, idx) {
        const isPaid          = (data.ticket_type || 'free') === 'paid';
        const isLimited       = (data.capacity_type || 'limited') === 'limited';
        const showRem         = (data.show_remaining || 'yes') === 'yes';
        const ticketTypeId    = parseInt(data.id, 10) || 0;
        const sold            = parseInt(data.quantity_sold, 10) || 0;
        const totalQty        = parseInt(data.qty, 10) || 0;
        const isActive        = (data.status || 'active') === 'active';
        // Sold counter only renders for saved ticket types (have a DB id).
        const soldDenominator = isLimited && totalQty > 0 ? `/${totalQty}` : '';
        const soldHtml        = ticketTypeId > 0
            ? `<span class="ke-tkt-sold" title="Tickets sold">Sold: <strong>${sold}</strong>${soldDenominator}</span>`
            : '';
        // Active toggle only renders for saved ticket types; unsaved cards
        // have no DB row to toggle against.
        const toggleHtml      = ticketTypeId > 0
            ? `<button type="button" class="ke-toggle-switch ke-tkt-active-toggle ${isActive ? 'on' : 'off'}" data-ticket-type-id="${ticketTypeId}" title="${isActive ? 'Active — click to deactivate' : 'Inactive — click to activate'}" aria-pressed="${isActive ? 'true' : 'false'}"><span class="ke-toggle-knob"></span></button>`
            : '';
        // "Ventas cerradas" indicator. Computed server-side in PHP hydration
        // (KE_Ticket_Types::is_sales_closed) so the badge never drifts against
        // the admin's local browser clock or timezone.
        const closedBadge     = data.is_closed
            ? `<span class="ke-tkt-badge ke-tkt-closed-badge" title="Sales cutoff has passed">⏱ Ventas cerradas</span>`
            : '';

        return `
<div class="ke-tkt-card ${isActive ? '' : 'is-inactive'}" data-idx="${idx}" data-id="${ticketTypeId}">
    <div class="ke-tkt-header">
        <div class="ke-tkt-header-left">
            <span class="ke-tkt-drag-handle" title="Drag to reorder" aria-label="Drag to reorder">⋮⋮</span>
            <span class="ke-tkt-badge ${isPaid ? 'paid' : 'free'}">${isPaid ? 'PAID' : 'FREE'}</span>
            <span class="ke-tkt-title-preview">${escHtml(data.name || 'New Ticket')}</span>
            ${closedBadge}
        </div>
        <div class="ke-tkt-header-right">
            ${soldHtml}
            ${toggleHtml}
            <button type="button" class="ke-tkt-collapse-btn" title="Collapse/Expand">▴</button>
            <button type="button" class="ke-tkt-remove-btn" title="Remove ticket">✕</button>
        </div>
    </div>

    <div class="ke-tkt-body">
        <div class="ke-form-row">
            <div class="ke-form-group ke-half">
                <label class="ke-label">Ticket Name <span class="ke-required">*</span></label>
                <input type="text" class="ke-input ke-tkt-name" placeholder="E.g., Early Bird" value="${escAttr(data.name || '')}">
            </div>
            <div class="ke-form-group ke-half">
                <label class="ke-label">Type</label>
                <div class="ke-radio-group ke-compact">
                    <label class="ke-radio-option ${!isPaid ? 'active' : ''}">
                        <input type="radio" class="ke-tkt-type-radio" name="ke_tkt_type_${idx}" value="free" ${!isPaid ? 'checked' : ''}>
                        <span>Free</span>
                    </label>
                    <label class="ke-radio-option ${isPaid ? 'active' : ''}">
                        <input type="radio" class="ke-tkt-type-radio" name="ke_tkt_type_${idx}" value="paid" ${isPaid ? 'checked' : ''}>
                        <span>Paid</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="ke-tkt-price-row" ${!isPaid ? 'style="display:none"' : ''}>
            <div class="ke-form-group ke-third">
                <label class="ke-label">Price ($)</label>
                <input type="number" class="ke-input ke-tkt-price" min="0" step="0.01" placeholder="0.00" value="${escAttr(String(data.price || ''))}">
            </div>
        </div>

        <div class="ke-form-group">
            <label class="ke-label">Description</label>
            <input type="text" class="ke-input ke-tkt-desc" placeholder="E.g., Includes 1 drink (optional)"
                   value="${escAttr(data.desc || '')}">
        </div>

        <div class="ke-form-row">
            <div class="ke-form-group ke-half">
                <label class="ke-label">Capacity</label>
                <div class="ke-radio-group ke-compact">
                    <label class="ke-radio-option ${isLimited ? 'active' : ''}">
                        <input type="radio" class="ke-tkt-cap-radio" name="ke_tkt_cap_${idx}" value="limited" ${isLimited ? 'checked' : ''}>
                        <span>Limited</span>
                    </label>
                    <label class="ke-radio-option ${!isLimited ? 'active' : ''}">
                        <input type="radio" class="ke-tkt-cap-radio" name="ke_tkt_cap_${idx}" value="unlimited" ${!isLimited ? 'checked' : ''}>
                        <span>Unlimited</span>
                    </label>
                </div>
            </div>
            <div class="ke-form-group ke-half ke-tkt-qty-wrap" ${!isLimited ? 'style="display:none"' : ''}>
                <label class="ke-label">Total Quantity</label>
                <input type="number" class="ke-input ke-tkt-qty" min="1" placeholder="100" value="${escAttr(String(data.qty || ''))}">
            </div>
        </div>

        <div class="ke-form-row">
            <div class="ke-form-group ke-quarter">
                <label class="ke-label">Min / Order</label>
                <input type="number" class="ke-input ke-tkt-min" min="1" value="${escAttr(String(data.min_per_order || 1))}">
            </div>
            <div class="ke-form-group ke-quarter">
                <label class="ke-label">Max / Order</label>
                <input type="number" class="ke-input ke-tkt-max" min="1" value="${escAttr(String(data.max_per_order || 10))}">
            </div>
        </div>

        <div class="ke-form-group">
            <label class="ke-toggle-wrap">
                <input type="checkbox" class="ke-tkt-show-remaining" ${showRem ? 'checked' : ''}>
                <span class="ke-toggle-slider"></span>
                <span class="ke-toggle-label">Show remaining tickets count</span>
            </label>
        </div>

        <div class="ke-form-group">
            <label class="ke-label">Cierre de venta (opcional)</label>
            <input type="datetime-local" class="ke-input ke-tkt-sale-end" value="${escAttr(data.sale_end || '')}">
            <p class="ke-hint">Ventas se cierran a esta hora aunque queden boletos disponibles. Déjalo vacío para vender hasta que finalice el evento o se agoten.</p>
        </div>
    </div>
</div>`;
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function escAttr(str) {
        return String(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function bindCardEvents($card) {
        // Name preview sync
        $card.find('.ke-tkt-name').on('input', function () {
            const v = $(this).val().trim() || 'New Ticket';
            $card.find('.ke-tkt-title-preview').text(v);
        });

        // Free / Paid toggle
        $card.find('.ke-tkt-type-radio').on('change', function () {
            const isPaid = $(this).val() === 'paid';
            $card.find('.ke-tkt-price-row').toggle(isPaid);
            $card.find('.ke-tkt-badge').removeClass('free paid').addClass(isPaid ? 'paid' : 'free').text(isPaid ? 'PAID' : 'FREE');
            $card.find('.ke-radio-option').removeClass('active');
            $(this).closest('.ke-radio-option').addClass('active');
        });

        // Capacity toggle
        $card.find('.ke-tkt-cap-radio').on('change', function () {
            const isLimited = $(this).val() === 'limited';
            $card.find('.ke-tkt-qty-wrap').toggle(isLimited);
            $card.find('.ke-radio-option').removeClass('active');
            $(this).closest('.ke-radio-option').addClass('active');
        });

        // Collapse
        $card.find('.ke-tkt-collapse-btn').on('click', function () {
            const $body = $card.find('.ke-tkt-body');
            const isOpen = $body.is(':visible');
            $body.slideToggle(180);
            $(this).text(isOpen ? '▾' : '▴');
        });

        // Remove
        $card.find('.ke-tkt-remove-btn').on('click', function () {
            if (!confirm('Remove this ticket type?')) return;
            $card.remove();
            updateEmptyState();
        });

        // Active toggle. Optimistic UI: flip immediately, POST, rollback on
        // failure. Only rendered for saved ticket types (data-ticket-type-id > 0).
        $card.find('.ke-tkt-active-toggle').on('click', function () {
            const $btn = $(this);
            if ($btn.prop('disabled')) return;
            const typeId  = parseInt($btn.attr('data-ticket-type-id'), 10) || 0;
            const eventId = parseInt(window.keEventId, 10) || 0;
            if (!typeId || !eventId) return;

            const base  = (window.keBuilderData && window.keBuilderData.restUrl) || '/wp-json/ke/v1/';
            const nonce = (window.keBuilderData && window.keBuilderData.nonce)   || '';

            const wasOn = $btn.hasClass('on');
            // Optimistic flip
            $btn.toggleClass('on off');
            $card.toggleClass('is-inactive', wasOn);
            $btn.prop('disabled', true).attr('aria-pressed', wasOn ? 'false' : 'true');

            fetch(base + 'events/' + eventId + '/ticket-types/' + typeId + '/toggle-active', {
                method:  'POST',
                headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }
            })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
            .then(function (res) {
                if (!res.ok || !res.data || !res.data.success) {
                    // Rollback
                    $btn.toggleClass('on off');
                    $card.toggleClass('is-inactive', !wasOn);
                    $btn.attr('aria-pressed', wasOn ? 'true' : 'false');
                    alert((res.data && res.data.message) || 'Could not update ticket status.');
                } else {
                    const nowActive = res.data.status === 'active';
                    $btn.attr('title', nowActive ? 'Active — click to deactivate' : 'Inactive — click to activate');
                }
            })
            .catch(function () {
                $btn.toggleClass('on off');
                $card.toggleClass('is-inactive', !wasOn);
                $btn.attr('aria-pressed', wasOn ? 'true' : 'false');
                alert('Network error. Could not update ticket status.');
            })
            .finally(function () { $btn.prop('disabled', false); });
        });
    }

    function updateEmptyState() {
        const count = $('#ke-ticket-container .ke-tkt-card').length;
        $('#ke-no-tickets').toggle(count === 0);
    }

    function addTicketCard(data) {
        data = data || {};
        const idx = ticketIndex++;
        const html = renderTicketCard(data, idx);
        const $card = $(html);
        $('#ke-no-tickets').hide();
        $('#ke-ticket-container').append($card);
        bindCardEvents($card);
    }

    $('#ke-add-ticket').on('click', function () {
        addTicketCard({});
    });

    // Load existing tickets on edit
    if (window.keExistingTickets && window.keExistingTickets.length > 0) {
        window.keExistingTickets.forEach(function (t) { addTicketCard(t); });
    } else {
        updateEmptyState();
    }

    // ─── 12h picker helpers ────────────────────────────────────────────────
    function get24hTime(pickerId) {
        const $p = $('#' + pickerId);
        let h    = parseInt($p.find('.ke-time-h').val(), 10) || 12;
        const m  = $p.find('.ke-time-m').val() || '00';
        const ap = $p.find('.ke-time-ampm').val() || 'AM';
        if (ap === 'AM') { h = (h === 12) ? 0 : h; }
        else             { h = (h === 12) ? 12 : h + 12; }
        return String(h).padStart(2, '0') + ':' + m;
    }

    // ─── Collect form data ─────────────────────────────────────────────────
    function collectFormData() {
        const startDate = $('#ke-start-date').val();
        const startTime = get24hTime('ke-start-time');
        const endDate   = $('#ke-end-date').val();
        const endTime   = get24hTime('ke-end-time');

        const tickets = [];
        $('#ke-ticket-container .ke-tkt-card').each(function () {
            const $c = $(this);
            const capType = $c.find('.ke-tkt-cap-radio:checked').val() || 'limited';

            // Per-ticket sales cutoff. Empty input → null so the REST layer's
            // array_key_exists check still fires (key present, value null) and
            // the CRUD layer writes SQL NULL to clear a previously-set cutoff.
            const saleEndRaw = ($c.find('.ke-tkt-sale-end').val() || '').trim();
            tickets.push({
                id:             parseInt($c.data('id'), 10) || 0,
                name:           $c.find('.ke-tkt-name').val().trim(),
                desc:           $c.find('.ke-tkt-desc').val().trim(),
                ticket_type:    $c.find('.ke-tkt-type-radio:checked').val() || 'free',
                price:          parseFloat($c.find('.ke-tkt-price').val()) || 0,
                capacity_type:  capType,
                qty:            capType === 'limited' ? (parseInt($c.find('.ke-tkt-qty').val(), 10) || 0) : 0,
                min_per_order:  parseInt($c.find('.ke-tkt-min').val(), 10) || 1,
                max_per_order:  parseInt($c.find('.ke-tkt-max').val(), 10) || 10,
                show_remaining: $c.find('.ke-tkt-show-remaining').is(':checked') ? 'yes' : 'no',
                sale_end:       saleEndRaw === '' ? null : saleEndRaw,
            });
        });

        const cats = [];
        $('.ke-cat-check:checked').each(function () {
            cats.push(parseInt($(this).val(), 10));
        });

        return {
            event_id:              window.keEventId || 0,
            title:                 $('#ke-event-title').val().trim(),
            slug:                  ($('#ke-event-slug').val() || '').trim(),
            slug_manually_set:     !!window.keSlugLockedThisSession,
            content:               $('#ke-event-description').val(),
            event_start:           startDate ? startDate + 'T' + startTime : '',
            event_end:             endDate   ? endDate   + 'T' + endTime   : '',
            timezone:              $('#ke-timezone').val(),
            location_type:         $('input[name="ke_location_type"]:checked').val() || 'venue',
            venue:                 $('#ke-venue').val().trim(),
            address:               $('#ke-address').val().trim(),
            virtual_url:           $('#ke-virtual-url').val().trim(),
            maps_embed:            $('#ke-maps-embed').val().trim(),
            social_instagram:      $('#ke-social-instagram').val().trim(),
            social_whatsapp:       $('#ke-social-whatsapp').val().trim(),
            social_website:        $('#ke-social-website').val().trim(),
            social_facebook:       $('#ke-social-facebook').val().trim(),
            event_status:          $('#ke-event-status').val() || 'active',
            organizer:             $('#ke-organizer').val() || '',
            categories:            cats,
            max_tickets_per_person: parseInt($('#ke-max-tickets').val(), 10) || 10,
            email_from_name:       $('#ke-email-from-name').val().trim(),
            email_custom_message:  $('#ke-email-custom-message').val().trim(),
            is_featured:           $('#ke-is-featured').is(':checked') ? 1 : 0,
            show_in_main_shortcode: $('#ke-show-in-main-shortcode').length
                                        ? ($('#ke-show-in-main-shortcode').is(':checked') ? 1 : 0)
                                        : 1,
            promo_label:           $('#ke-promo-label').val().trim(),
            service_fee_id:        $('#ke-service-fee-id').val() || '',
            banner_id:             parseInt($('#ke-banner-id').val(), 10) || 0,
            hero_bg_id:            parseInt($('#ke-herobg-id').val(), 10) || 0,
            show_highlights:       $('#ke-show-highlights').is(':checked') ? 1 : 0,
            highlights_all:        $('#ke-hl-all').is(':checked') ? 1 : 0,
            highlights:            $('.ke-hl-item:checked').map(function () { return parseInt(this.value, 10); }).get(),
            birthday_enabled:      $('#ke-birthday-enabled').is(':checked') ? 1 : 0,
            birthday_title:        $('#ke-birthday-title').val().trim(),
            birthday_description:  $('#ke-birthday-description').val(),
            birthday_link:         $('#ke-birthday-link').val().trim(),
            tickets:               tickets,
            extras:                collectExtras(),
            extra_fields:          collectExtraFields(),
            reservations:          collectReservations(),
            promoter_assignments:  collectPromoterAssignments(),
            promoter_terms:        collectPromoterTerms(),
        };
    }

    function collectPromoterTerms() {
        // wp_editor renders into a textarea named ke_promoter_event_terms;
        // when visual mode is active the rich text is held by tinymce.
        if (typeof tinymce !== 'undefined' && tinymce.get && tinymce.get('ke_promoter_event_terms')) {
            return tinymce.get('ke_promoter_event_terms').getContent() || '';
        }
        const el = document.getElementById('ke_promoter_event_terms');
        return el ? el.value : '';
    }

    function collectExtras() {
        const extras = [];
        $('.ke-extra-toggle').each(function () {
            const $t    = $(this);
            const type  = $t.data('type');
            if (!type) return;
            const config = {};
            if (type === 'lineup') {
                config.artists = lineupArtists
                    .filter(function (a) { return a && (a.name || a.photo_id); })
                    .map(function (a) {
                        return { name: String(a.name || ''), photo_id: parseInt(a.photo_id, 10) || 0 };
                    });
            }
            if (type === 'gallery') {
                config.photos = galleryPhotos
                    .filter(function (p) { return p && p.photo_id > 0; })
                    .map(function (p) {
                        return { photo_id: parseInt(p.photo_id, 10) || 0, caption: String(p.caption || '') };
                    });
            }
            if (type === 'testimonials') {
                config.title            = ($('#ke-testi-title').val() || '').toString().trim() || 'Testimonials';
                config.require_approval = $('#ke-testi-require-approval').is(':checked');
                config.allow_ratings    = $('#ke-testi-allow-ratings').is(':checked');
            }
            if (type === 'schedule') {
                config.slots = scheduleSlots
                    .filter(function (s) { return s && s.title; })
                    .map(function (s) {
                        return {
                            time:        String(s.time || ''),
                            title:       String(s.title || ''),
                            description: String(s.description || ''),
                        };
                    });
            }
            if (type === 'faq') {
                config.title = ($('#ke-faq-title').val() || '').toString().trim() || 'Frequently Asked Questions';
                config.items = faqItems
                    .filter(function (it) { return it && it.question && it.answer; })
                    .map(function (it) {
                        return { question: String(it.question || ''), answer: String(it.answer || '') };
                    });
            }
            if (type === 'additional_info') {
                const r = String($('#ke-addinfo-refundable').val() || '').toLowerCase();
                config.refundable  = (r === 'yes' || r === 'no') ? r : '';
                config.disclaimers = String($('#ke-addinfo-disclaimers').val() || '');
            }
            extras.push({
                type:    type,
                enabled: $t.is(':checked'),
                config:  config,
            });
        });
        return extras;
    }

    // ─── Save indicator + auto-save ────────────────────────────────────────
    const $indicator = $('#ke-save-indicator');

    function showSaveState(state, message) {
        if (!$indicator.length) return;
        $indicator
            .attr('data-state', state)
            .find('.ke-save-text').text(message || '');
        $indicator.addClass('is-visible');

        if (state === 'saved') {
            clearTimeout($indicator.data('hideTimer'));
            $indicator.data('hideTimer', setTimeout(function () {
                $indicator.removeClass('is-visible');
            }, 2000));
        } else if (state === 'error') {
            clearTimeout($indicator.data('hideTimer'));
            $indicator.data('hideTimer', setTimeout(function () {
                $indicator.removeClass('is-visible');
            }, 4000));
        }
    }

    let autoSaveTimer   = null;
    let autoSaveInFlight = false;

    function triggerAutoSave() {
        // Only auto-save when the event has a name — avoids spamming the server with junk drafts
        if (!$('#ke-event-title').val().trim()) return;
        // And skip while the user is explicitly saving/publishing to avoid races
        if (autoSaveInFlight) return;

        clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(runAutoSave, 1500);
    }

    function runAutoSave() {
        if (autoSaveInFlight) return;
        const data = collectFormData();
        if (!data.title) return;
        // Autosave must NOT touch post_status. Sending status:'draft' here
        // silently reverted already-published events to draft (making them
        // vanish from [kiwi_events]). Omitting status entirely tells the REST
        // handler to preserve whatever status the event currently has. Only
        // the explicit Publish / Save-Draft buttons set status.
        delete data.status;

        autoSaveInFlight = true;
        showSaveState('saving', 'Saving…');

        const isEdit = window.keIsEdit && data.event_id > 0;
        const url    = keBuilderData.restUrl + 'events' + (isEdit ? '/' + data.event_id : '');
        const method = isEdit ? 'PUT' : 'POST';

        $.ajax({
            url: url, method: method,
            contentType: 'application/json',
            data: JSON.stringify(data),
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', keBuilderData.nonce);
            },
            success: function (res) {
                if (res && res.id) {
                    window.keEventId = res.id;
                    window.keIsEdit  = true;
                    showSaveState('saved', 'Saved');
                    // Autosave landed — snapshot now matches server. The
                    // persistent-bar (if enabled) drops back to Idle.
                    if (typeof markCleanSnapshot === 'function') markCleanSnapshot();
                } else {
                    showSaveState('error', "Couldn't save");
                }
            },
            error: function () {
                showSaveState('error', "Couldn't save");
            },
            complete: function () {
                autoSaveInFlight = false;
            },
        });
    }

    $(document).on('input change',
        '#ke-builder-form input, #ke-builder-form textarea, #ke-builder-form select, ' +
        '.ke-builder-card input, .ke-builder-card textarea, .ke-builder-card select',
        function () {
            triggerAutoSave();
            schedulePersistentDirtyCheck();
        }
    );

    // ─── Persistent "Save changes" bar (edit mode only) — Item #2 ─────────
    //
    // Snapshot-diff against `collectFormData()` to detect whether the wizard
    // has unsaved changes. The autosave loop keeps things on disk in the
    // background; this bar gives the user an *explicit* status + commit button
    // they can hit from any step without walking to the final wizard panel.
    const $persistentBar    = $('#ke-persistent-savebar');
    const $persistentBtn    = $('#ke-persistent-save-btn');
    const $persistentStatus = $('#ke-persistent-savebar-status');
    const persistentEnabled = isEditMode && $persistentBar.length > 0;

    let cleanSnapshot = null;
    let dirtyCheckTimer = null;
    let persistentSaveInFlight = false;
    let persistentQueuedSave = false;
    let savedFlashTimer = null;

    function captureSnapshot() {
        try {
            return JSON.stringify(collectFormData());
        } catch (e) {
            return null;
        }
    }

    // Called by external save flows (autosave + explicit publish) to mark
    // current form state as "matches what's on the server".
    function markCleanSnapshot() {
        if (!persistentEnabled) return;
        cleanSnapshot = captureSnapshot();
        setPersistentState('idle');
    }
    // Exposed so tests / future code can force a re-snapshot.
    window.keMarkCleanSnapshot = markCleanSnapshot;

    function isDirty() {
        if (cleanSnapshot === null) return false;
        const now = captureSnapshot();
        return now !== null && now !== cleanSnapshot;
    }

    function schedulePersistentDirtyCheck() {
        if (!persistentEnabled) return;
        clearTimeout(dirtyCheckTimer);
        dirtyCheckTimer = setTimeout(function () {
            const state = $persistentBtn.attr('data-state');
            if (state === 'saving') return; // don't fight an in-flight save
            if (isDirty()) {
                if (state !== 'dirty') setPersistentState('dirty');
            } else {
                if (state === 'dirty') setPersistentState('idle');
            }
        }, 150);
    }

    function setPersistentState(state, message) {
        if (!persistentEnabled) return;
        clearTimeout(savedFlashTimer);

        $persistentBtn.attr('data-state', state);
        $persistentStatus.attr('data-state', state);

        const $icon  = $persistentBtn.find('.ke-persistent-save-icon');
        const $label = $persistentBtn.find('.ke-persistent-save-label');
        const $stext = $persistentStatus.find('.ke-savebar-text');

        switch (state) {
            case 'idle':
                $persistentBtn.prop('disabled', true).attr('title', 'No changes to save.');
                $icon.text('✓');
                $label.text('Saved');
                $stext.text('No unsaved changes');
                break;
            case 'dirty':
                $persistentBtn.prop('disabled', false).attr('title', 'Save your changes now.');
                $icon.text('💾');
                $label.text('Save changes');
                $stext.text('Unsaved changes');
                break;
            case 'saving':
                $persistentBtn.prop('disabled', true).attr('title', 'Saving…');
                $icon.text(''); // CSS turns this into a spinner
                $label.text('Saving…');
                $stext.text('Saving…');
                break;
            case 'saved':
                $persistentBtn.prop('disabled', true).attr('title', 'Saved.');
                $icon.text('✓');
                $label.text('Saved');
                $stext.text('All changes saved');
                // Brief flash, then fall back to idle.
                savedFlashTimer = setTimeout(function () {
                    if ($persistentBtn.attr('data-state') === 'saved') setPersistentState('idle');
                }, 2000);
                break;
            case 'error':
                $persistentBtn.prop('disabled', false).attr('title', message || 'Save failed — try again.');
                $icon.text('!');
                $label.text('Try again');
                $stext.text(message || 'Save failed');
                break;
        }
    }

    // Bottom-center toast — used only by the persistent-save flow so the user
    // gets confirmation that doesn't interrupt their current step context.
    function showPersistentToast(message, kind) {
        const $stack = $('#ke-toast-stack');
        if (!$stack.length) return;
        const $t = $('<div class="ke-toast"></div>').text(message);
        if (kind === 'success') $t.addClass('is-success');
        if (kind === 'error')   $t.addClass('is-error');
        $stack.append($t);
        requestAnimationFrame(function () { $t.addClass('is-visible'); });
        setTimeout(function () {
            $t.removeClass('is-visible');
            setTimeout(function () { $t.remove(); }, 250);
        }, 2500);
    }

    function persistentSave() {
        if (!persistentEnabled) return;
        if (persistentSaveInFlight) { persistentQueuedSave = true; return; }

        const data = collectFormData();
        if (!data.title) {
            goToStep(1);
            showError('Event name is required.', 1);
            $('#ke-event-title').focus();
            return;
        }

        // Persistent button only ever lives in edit mode so this is always a PUT.
        // We preserve current post status by sending 'publish' (matches what the
        // explicit Save Changes button does today on existing events).
        data.status = 'publish';

        persistentSaveInFlight = true;
        autoSaveInFlight = true;
        clearTimeout(autoSaveTimer);
        setPersistentState('saving');

        const url = keBuilderData.restUrl + 'events/' + data.event_id;

        $.ajax({
            url: url, method: 'PUT',
            contentType: 'application/json',
            data: JSON.stringify(data),
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', keBuilderData.nonce); },
            success: function (res) {
                if (res && res.id) {
                    markCleanSnapshot();
                    setPersistentState('saved');
                    showPersistentToast('Event saved successfully', 'success');
                } else {
                    setPersistentState('error', 'Unexpected response from server');
                }
            },
            error: function (xhr) {
                let msg = 'Save failed';
                try {
                    const body = JSON.parse(xhr.responseText);
                    if (body && body.message) msg = body.message;
                } catch (e) {}
                setPersistentState('error', msg);
                showPersistentToast(msg, 'error');
            },
            complete: function () {
                persistentSaveInFlight = false;
                autoSaveInFlight = false;
                if (persistentQueuedSave) {
                    persistentQueuedSave = false;
                    // A change came in mid-flight — re-evaluate.
                    schedulePersistentDirtyCheck();
                    if (isDirty()) persistentSave();
                }
            }
        });
    }

    $persistentBtn.on('click', function () {
        if ($(this).prop('disabled')) return;
        persistentSave();
    });

    // beforeunload — only when there are genuine unsaved changes.
    window.addEventListener('beforeunload', function (e) {
        if (!persistentEnabled) return;
        if (persistentSaveInFlight) return; // assume the in-flight save will land
        if (!isDirty()) return;
        e.preventDefault();
        // Modern browsers ignore the custom string but still display a generic prompt.
        e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
        return e.returnValue;
    });

    // Capture the initial baseline AFTER tickets/extras/promoters have hydrated.
    // 250ms is enough headroom for the existing per-step bootstrap code below
    // to populate the DOM from `window.keExisting*` arrays.
    if (persistentEnabled) {
        setTimeout(function () {
            cleanSnapshot = captureSnapshot();
            setPersistentState('idle');
        }, 250);
    }

    // ─── Save ──────────────────────────────────────────────────────────────
    function saveEvent(status) {
        const data = collectFormData();
        data.status = status; // 'draft' or 'publish'

        if (!data.title) {
            goToStep(1);
            showError('Event name is required.', 1);
            $('#ke-event-title').focus();
            return;
        }

        // Suppress auto-save during an explicit save
        clearTimeout(autoSaveTimer);
        autoSaveInFlight = true;

        const wasFirstPublish = !window.keIsEdit && status === 'publish';
        const $btn = status === 'publish' ? $('#ke-publish-btn') : $('.ke-btn-ghost[id^="ke-draft-"]:visible');
        $btn.prop('disabled', true).text('Saving…');

        const isEdit  = window.keIsEdit && data.event_id > 0;
        const url     = keBuilderData.restUrl + 'events' + (isEdit ? '/' + data.event_id : '');
        const method  = isEdit ? 'PUT' : 'POST';

        $.ajax({
            url:         url,
            method:      method,
            contentType: 'application/json',
            data:        JSON.stringify(data),
            beforeSend:  function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', keBuilderData.nonce);
            },
            success: function (res) {
                if (res && res.id) {
                    window.keEventId = res.id;
                    window.keIsEdit  = true;
                    if (wasFirstPublish) celebratePublish();
                    showSuccessModal(res.id, res.permalink || '', status);
                    // Explicit save landed — bring the persistent bar back to Idle.
                    if (typeof markCleanSnapshot === 'function') markCleanSnapshot();
                } else {
                    showError('Unexpected response from server.');
                }
            },
            error: function (xhr) {
                let msg = 'Error saving event.';
                try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
                showError(msg);
            },
            complete: function () {
                autoSaveInFlight = false;
                $btn.prop('disabled', false);
                if (status === 'publish') {
                    $btn.text(window.keIsEdit ? '✔ Save Changes' : '🚀 Publish Event');
                } else {
                    $btn.text('Save Draft');
                }
            }
        });
    }

    // ─── Confetti on first publish ─────────────────────────────────────────
    function celebratePublish() {
        if (typeof window.confetti !== 'function') return;
        window.confetti({
            particleCount: 150,
            spread: 90,
            origin: { y: 0.6 },
            colors: ['#6366f1', '#8b5cf6', '#ec4899', '#10b981', '#f59e0b'],
        });
    }

    function showSuccessModal(eventId, permalink, status) {
        $('#ke-modal-title').text(status === 'draft' ? 'Draft Saved!' : (window.keIsEdit && status === 'publish' ? 'Changes Saved!' : 'Event Published!'));
        $('#ke-modal-msg').text(status === 'draft' ? 'Your draft has been saved.' : 'Your event is live and ready for registrations.');
        if (permalink) {
            $('#ke-modal-preview').attr('href', permalink).show();
        } else {
            $('#ke-modal-preview').hide();
        }
        $('#ke-success-modal').fadeIn(200);
    }

    $('#ke-success-modal').on('click', function (e) {
        if ($(e.target).is('#ke-success-modal')) $(this).fadeOut(200);
    });

    // ─── Ticket Templates ──────────────────────────────────────────────────

    // Fetch templates when organizer changes (Step 2) or when entering Step 3
    $('#ke-organizer').on('change', function () {
        cachedTemplates = [];
        if (currentStep === 3) fetchOrganizerTemplates();
    });

    function fetchOrganizerTemplates() {
        const orgId = $('#ke-organizer').val();
        if (!orgId) {
            $('#ke-tpl-loader-bar').hide();
            return;
        }

        fetch(keBuilderData.restUrl + 'organizers/' + orgId + '/templates', {
            headers: { 'X-WP-Nonce': keBuilderData.nonce }
        })
        .then(r => r.json())
        .then(function (list) {
            cachedTemplates = Array.isArray(list) ? list : [];
            updateTemplateLoaderBar();
        })
        .catch(function () {
            cachedTemplates = [];
            $('#ke-tpl-loader-bar').hide();
        });
    }

    function updateTemplateLoaderBar() {
        if (!cachedTemplates.length) {
            $('#ke-tpl-loader-bar').hide();
            return;
        }
        const orgName = $('#ke-organizer option:selected').text();
        const n = cachedTemplates.length;
        $('#ke-tpl-loader-label').text(orgName + ' has ' + n + ' template' + (n !== 1 ? 's' : ''));
        $('#ke-tpl-loader-sub').text('Load a saved ticket configuration');
        $('#ke-tpl-loader-bar').show();
    }

    // Open template picker
    $('#ke-load-template-btn').on('click', function () {
        const orgName = $('#ke-organizer option:selected').text();
        $('#ke-tpl-picker-org-name').text('Organizer: ' + orgName);
        renderTemplatePicker(cachedTemplates);
        $('#ke-tpl-picker-overlay').fadeIn(180);
    });

    function renderTemplatePicker(list) {
        const $c = $('#ke-tpl-picker-list');
        $c.empty();
        if (!list || !list.length) {
            $c.html('<p style="color:var(--kiwi-legacy-text-faint);font-size:14px;">No templates available.</p>');
            return;
        }
        list.forEach(function (tpl) {
            const chips = (tpl.tickets || []).map(function (t) {
                const price = parseFloat(t.price) > 0 ? '$' + parseFloat(t.price).toFixed(2) : 'Free';
                return '<span class="ke-tpl-picker-chip">' + escHtml(t.name) + ' · ' + escHtml(price) + '</span>';
            }).join('');

            const $card = $([
                '<div class="ke-tpl-picker-card" tabindex="0" role="button">',
                '  <div>',
                '    <div class="ke-tpl-picker-name">' + escHtml(tpl.name) + '</div>',
                '    <div class="ke-tpl-picker-meta">' + (tpl.tickets || []).length + ' ticket type' + ((tpl.tickets || []).length !== 1 ? 's' : '') + '</div>',
                chips ? '<div class="ke-tpl-picker-chips">' + chips + '</div>' : '',
                '  </div>',
                '  <span style="color:var(--kiwi-legacy-indigo-500);font-size:13px;font-weight:700;flex-shrink:0;margin-left:12px;">Apply →</span>',
                '</div>',
            ].join(''));

            $card.on('click keydown', function (e) {
                if (e.type === 'keydown' && e.key !== 'Enter') return;
                applyTemplate(tpl);
                closePicker();
            });
            $c.append($card);
        });
    }

    function applyTemplate(tpl) {
        const existing = $('#ke-ticket-container .ke-tkt-card').length;
        if (existing > 0) {
            if (!confirm('Replace existing ' + existing + ' ticket type(s) with the "' + tpl.name + '" template?')) return;
            $('#ke-ticket-container .ke-tkt-card').remove();
        }
        (tpl.tickets || []).forEach(function (t) {
            addTicketCard({
                name:          t.name,
                desc:          t.description || '',
                ticket_type:   t.ticket_type || 'free',
                price:         t.price || 0,
                capacity_type: t.capacity_type || 'limited',
                qty:           t.quantity || 0,
                min_per_order: t.min_per_order || 1,
                max_per_order: t.max_per_order || 10,
                show_remaining: 'yes',
            });
        });
        updateEmptyState();
    }

    function closePicker() {
        $('#ke-tpl-picker-overlay').fadeOut(180);
    }
    $('#ke-tpl-picker-close, #ke-tpl-picker-overlay').on('click', function (e) {
        if ($(e.target).is('#ke-tpl-picker-overlay') || $(e.target).is('#ke-tpl-picker-close')) {
            closePicker();
        }
    });

    // Pre-load templates on edit if organizer is already set
    if (window.keIsEdit && $('#ke-organizer').val()) {
        fetchOrganizerTemplates();
    }

    // ─── Extras: restore enabled state on edit ─────────────────────────────
    if (Array.isArray(window.keExistingExtras)) {
        window.keExistingExtras.forEach(function (extra) {
            if (!extra || !extra.enabled) return;
            const $toggle = $('.ke-extra-toggle[data-type="' + extra.type + '"]');
            $toggle.prop('checked', true);
            $toggle.closest('.ke-extra-card').addClass('is-enabled');
        });
    }
    $(document).on('change', '.ke-extra-toggle', function () {
        $(this).closest('.ke-extra-card').toggleClass('is-enabled', this.checked);
        const t = $(this).data('type');
        if (t === 'lineup')          updateLineupEditorVisibility();
        if (t === 'gallery')         updateGalleryEditorVisibility();
        if (t === 'testimonials')    updateTestimonialsEditorVisibility();
        if (t === 'schedule')        updateScheduleEditorVisibility();
        if (t === 'faq')             updateFaqEditorVisibility();
        if (t === 'additional_info') updateAddInfoEditorVisibility();
    });

    function updateAddInfoEditorVisibility() {
        const on = $('.ke-extra-toggle[data-type="additional_info"]').is(':checked');
        $('#ke-addinfo-editor').toggle(on);
    }

    // Hydrate Additional Information editor on edit.
    (function hydrateAdditionalInfo() {
        if (!Array.isArray(window.keExistingExtras)) return;
        const extra = window.keExistingExtras.find(function (e) { return e && e.type === 'additional_info'; });
        const cfg   = extra && extra.config ? extra.config : null;
        if (!cfg) return;
        $('#ke-addinfo-refundable').val(
            (cfg.refundable === 'yes' || cfg.refundable === 'no') ? cfg.refundable : ''
        );
        $('#ke-addinfo-disclaimers').val(String(cfg.disclaimers || ''));
    })();
    updateAddInfoEditorVisibility();

    // ─── Lineup editor ─────────────────────────────────────────────────────
    // Artists survive an off→on toggle: we keep the array in memory and in
    // collectExtras() output, but the server only renders the section when
    // the lineup toggle is enabled.
    let lineupArtists = [];

    (function hydrateLineup() {
        if (!Array.isArray(window.keExistingExtras)) return;
        const extra = window.keExistingExtras.find(function (e) { return e && e.type === 'lineup'; });
        const artists = extra && extra.config && Array.isArray(extra.config.artists) ? extra.config.artists : [];
        lineupArtists = artists.map(function (a) {
            return {
                name:      String(a.name || ''),
                photo_id:  parseInt(a.photo_id, 10) || 0,
                photo_url: String(a.photo_url || ''),
            };
        });
    })();

    function renderLineupEditor() {
        const $list  = $('#ke-lineup-list');
        const $empty = $('#ke-lineup-empty');
        if (!$list.length) return;

        if (lineupArtists.length === 0) {
            $list.empty().hide();
            $empty.show();
            return;
        }
        $empty.hide();
        $list.show();

        const html = lineupArtists.map(function (a, i) {
            const photo = a.photo_url
                ? '<div class="ke-lineup-row-photo" style="background-image:url(' + a.photo_url + ');"></div>'
                : '<div class="ke-lineup-row-photo is-empty"><span>+</span></div>';
            return ''
                + '<div class="ke-lineup-row" data-idx="' + i + '">'
                +     '<span class="ke-lineup-drag" title="Drag to reorder" aria-label="Drag to reorder">⋮⋮</span>'
                +     '<button type="button" class="ke-lineup-photo-btn" aria-label="Choose photo">' + photo + '</button>'
                +     '<input type="text" class="ke-input ke-lineup-name" placeholder="Artist name" value="' + escAttr(a.name) + '">'
                +     '<button type="button" class="ke-lineup-remove" aria-label="Remove artist">✕</button>'
                + '</div>';
        }).join('');
        $list.html(html);
    }

    function updateLineupEditorVisibility() {
        const on = $('.ke-extra-toggle[data-type="lineup"]').is(':checked');
        $('#ke-lineup-editor').toggle(on);
    }

    function openLineupMediaFrame(idx) {
        const frame = wp.media({
            title:    'Select Artist Photo',
            button:   { text: 'Use this photo' },
            multiple: false,
            library:  { type: 'image' },
        });
        frame.on('select', function () {
            const att = frame.state().get('selection').first().toJSON();
            if (!lineupArtists[idx]) return;
            lineupArtists[idx].photo_id  = parseInt(att.id, 10) || 0;
            lineupArtists[idx].photo_url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
            renderLineupEditor();
            triggerAutoSave();
        });
        frame.open();
    }

    $(document).on('click', '#ke-lineup-add', function () {
        lineupArtists.push({ name: '', photo_id: 0, photo_url: '' });
        renderLineupEditor();
        triggerAutoSave();
    });

    $(document).on('click', '.ke-lineup-photo-btn', function () {
        const idx = parseInt($(this).closest('.ke-lineup-row').data('idx'), 10);
        if (Number.isNaN(idx)) return;
        openLineupMediaFrame(idx);
    });

    $(document).on('click', '.ke-lineup-remove', function () {
        const idx = parseInt($(this).closest('.ke-lineup-row').data('idx'), 10);
        if (Number.isNaN(idx)) return;
        lineupArtists.splice(idx, 1);
        renderLineupEditor();
        triggerAutoSave();
    });

    $(document).on('input', '.ke-lineup-name', function () {
        const idx = parseInt($(this).closest('.ke-lineup-row').data('idx'), 10);
        if (Number.isNaN(idx) || !lineupArtists[idx]) return;
        lineupArtists[idx].name = $(this).val();
        triggerAutoSave();
    });

    if (typeof window.Sortable === 'function') {
        const lineupListEl = document.getElementById('ke-lineup-list');
        if (lineupListEl) {
            new window.Sortable(lineupListEl, {
                animation:  200,
                ghostClass: 'ke-lineup-ghost',
                handle:     '.ke-lineup-drag',
                onEnd: function (evt) {
                    if (evt.oldIndex === evt.newIndex) return;
                    const moved = lineupArtists.splice(evt.oldIndex, 1)[0];
                    lineupArtists.splice(evt.newIndex, 0, moved);
                    renderLineupEditor();
                    triggerAutoSave();
                },
            });
        }
    }

    updateLineupEditorVisibility();
    renderLineupEditor();

    // ─── Gallery editor ────────────────────────────────────────────────────
    let galleryPhotos = [];

    (function hydrateGallery() {
        if (!Array.isArray(window.keExistingExtras)) return;
        const extra = window.keExistingExtras.find(function (e) { return e && e.type === 'gallery'; });
        if (!extra || !extra.config) return;
        const photos = Array.isArray(extra.config.photos) ? extra.config.photos : [];
        galleryPhotos = photos
            .map(function (p) {
                return {
                    photo_id:  parseInt(p.photo_id, 10) || 0,
                    photo_url: String(p.photo_url || ''),
                    caption:   String(p.caption || ''),
                };
            })
            .filter(function (p) { return p.photo_id > 0; });
    })();

    function renderGalleryEditor() {
        const $list  = $('#ke-gallery-list');
        const $empty = $('#ke-gallery-empty');
        if (!$list.length) return;

        if (galleryPhotos.length === 0) {
            $list.empty().hide();
            $empty.show();
            return;
        }
        $empty.hide();
        $list.show();

        const html = galleryPhotos.map(function (p, i) {
            const bg = p.photo_url ? ('url(' + p.photo_url + ')') : 'none';
            return ''
                + '<div class="ke-gallery-tile" data-idx="' + i + '">'
                +     '<div class="ke-gallery-tile-img" style="background-image:' + bg + ';">'
                +         '<span class="ke-gallery-drag" title="Drag to reorder" aria-label="Drag to reorder">⋮⋮</span>'
                +         '<button type="button" class="ke-gallery-remove" aria-label="Remove photo">✕</button>'
                +     '</div>'
                +     '<input type="text" class="ke-input ke-gallery-caption" placeholder="Caption (optional)" value="' + escAttr(p.caption) + '">'
                + '</div>';
        }).join('');
        $list.html(html);
    }

    function updateGalleryEditorVisibility() {
        const on = $('.ke-extra-toggle[data-type="gallery"]').is(':checked');
        $('#ke-gallery-editor').toggle(on);
    }

    function openGalleryMediaFrame() {
        const frame = wp.media({
            title:    'Add Gallery Photos',
            button:   { text: 'Add to gallery' },
            multiple: 'add',
            library:  { type: 'image' },
        });
        frame.on('select', function () {
            const sel = frame.state().get('selection').toJSON();
            sel.forEach(function (att) {
                const url = (att.sizes && att.sizes.medium && att.sizes.medium.url) || att.url;
                galleryPhotos.push({
                    photo_id:  parseInt(att.id, 10) || 0,
                    photo_url: url,
                    caption:   '',
                });
            });
            renderGalleryEditor();
            triggerAutoSave();
        });
        frame.open();
    }

    $(document).on('click', '#ke-gallery-add', openGalleryMediaFrame);

    $(document).on('click', '.ke-gallery-remove', function () {
        const idx = parseInt($(this).closest('.ke-gallery-tile').data('idx'), 10);
        if (Number.isNaN(idx)) return;
        galleryPhotos.splice(idx, 1);
        renderGalleryEditor();
        triggerAutoSave();
    });

    $(document).on('input', '.ke-gallery-caption', function () {
        const idx = parseInt($(this).closest('.ke-gallery-tile').data('idx'), 10);
        if (Number.isNaN(idx) || !galleryPhotos[idx]) return;
        galleryPhotos[idx].caption = $(this).val();
        triggerAutoSave();
    });

    if (typeof window.Sortable === 'function') {
        const galleryListEl = document.getElementById('ke-gallery-list');
        if (galleryListEl) {
            new window.Sortable(galleryListEl, {
                animation:  200,
                ghostClass: 'ke-gallery-ghost',
                handle:     '.ke-gallery-drag',
                onEnd: function (evt) {
                    if (evt.oldIndex === evt.newIndex) return;
                    const moved = galleryPhotos.splice(evt.oldIndex, 1)[0];
                    galleryPhotos.splice(evt.newIndex, 0, moved);
                    renderGalleryEditor();
                    triggerAutoSave();
                },
            });
        }
    }

    updateGalleryEditorVisibility();
    renderGalleryEditor();

    // ─── Testimonials editor ──────────────────────────────────────────────
    function updateTestimonialsEditorVisibility() {
        const on = $('.ke-extra-toggle[data-type="testimonials"]').is(':checked');
        $('#ke-testi-editor').toggle(on);
        if (on && window.keIsEdit && window.keEventId) {
            loadTestimonialsModList();
        }
    }

    (function hydrateTestimonials() {
        if (!Array.isArray(window.keExistingExtras)) return;
        const extra = window.keExistingExtras.find(function (e) { return e && e.type === 'testimonials'; });
        const cfg = extra && extra.config ? extra.config : {};
        $('#ke-testi-title').val(cfg.title || 'Testimonials');
        // Default both toggles ON when not set (matches server-side defaults).
        $('#ke-testi-require-approval').prop('checked',
            Object.prototype.hasOwnProperty.call(cfg, 'require_approval') ? !!cfg.require_approval : true);
        $('#ke-testi-allow-ratings').prop('checked',
            Object.prototype.hasOwnProperty.call(cfg, 'allow_ratings') ? !!cfg.allow_ratings : true);
    })();

    $(document).on('input change', '#ke-testi-title, #ke-testi-require-approval, #ke-testi-allow-ratings', function () {
        triggerAutoSave();
    });

    function loadTestimonialsModList() {
        if (!window.keEventId) return;
        const $list = $('#ke-testi-mod-list');
        if (!$list.length) return;
        const base  = (window.keBuilderData && window.keBuilderData.restUrl) || '/wp-json/ke/v1/';
        const nonce = (window.keBuilderData && window.keBuilderData.nonce)   || '';

        $list.html('<div class="ke-testi-mod-empty">Loading…</div>');

        fetch(base + 'events/' + window.keEventId + '/testimonials?pending=1&per_page=10', {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce },
        }).then(function (r) { return r.json(); })
          .then(function (data) {
              const items = (data && data.items) || [];
              if (!items.length) {
                  $list.html('<div class="ke-testi-mod-empty">No comments yet.</div>');
                  return;
              }
              $list.empty();
              items.forEach(function (t) { $list.append(renderModRow(t)); });
          })
          .catch(function () {
              $list.html('<div class="ke-testi-mod-empty">Could not load comments.</div>');
          });
    }

    function renderModRow(t) {
        const $row = $(
            '<div class="ke-testi-mod-row" data-id="' + t.id + '">' +
                '<img class="ke-testi-mod-avatar" src="' + escAttr(t.avatar || '') + '" alt="">' +
                '<div class="ke-testi-mod-body">' +
                    '<div class="ke-testi-mod-head">' +
                        '<strong>' + escHtml(t.author || '') + '</strong>' +
                        (t.rating ? ' <span class="ke-testi-mod-rating">' + t.rating + '★</span>' : '') +
                        (t.pinned ? ' <span class="ke-testi-mod-badge is-pin">Pinned</span>' : '') +
                        (t.approved ? '' : ' <span class="ke-testi-mod-badge is-pending">Pending</span>') +
                    '</div>' +
                    '<p class="ke-testi-mod-text">' + escHtml(t.content || '') + '</p>' +
                    '<div class="ke-testi-mod-actions">' +
                        (t.approved
                            ? '<button type="button" class="ke-btn-link" data-action="unapprove">Unapprove</button>'
                            : '<button type="button" class="ke-btn-link" data-action="approve">Approve</button>') +
                        '<button type="button" class="ke-btn-link" data-action="' + (t.pinned ? 'unpin' : 'pin') + '">' + (t.pinned ? 'Unpin' : 'Pin') + '</button>' +
                        '<button type="button" class="ke-btn-link is-danger" data-action="delete">Delete</button>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );
        return $row;
    }

    $(document).on('click', '.ke-testi-mod-actions [data-action]', function () {
        const $btn    = $(this);
        const action  = $btn.data('action');
        const $row    = $btn.closest('.ke-testi-mod-row');
        const id      = parseInt($row.data('id'), 10);
        if (!id || !window.keEventId) return;

        const base  = (window.keBuilderData && window.keBuilderData.restUrl) || '/wp-json/ke/v1/';
        const nonce = (window.keBuilderData && window.keBuilderData.nonce)   || '';
        const url   = base + 'events/' + window.keEventId + '/testimonials/' + id;

        if (action === 'delete') {
            if (!window.confirm('Delete this comment?')) return;
            fetch(url, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce },
            }).then(function () { $row.remove(); });
            return;
        }

        fetch(url, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify({ action: action }),
        }).then(function (r) { return r.json(); })
          .then(function (data) {
              if (data && data.testimonial) {
                  $row.replaceWith(renderModRow(data.testimonial));
              }
          });
    });

    $(document).on('click', '#ke-testi-approve-all', function () {
        if (!window.keEventId) return;
        const $list = $('#ke-testi-mod-list');
        const base  = (window.keBuilderData && window.keBuilderData.restUrl) || '/wp-json/ke/v1/';
        const nonce = (window.keBuilderData && window.keBuilderData.nonce)   || '';
        const $pending = $list.find('.ke-testi-mod-row').filter(function () {
            return $(this).find('.is-pending').length > 0;
        });
        if (!$pending.length) return;
        $pending.each(function () {
            const id = parseInt($(this).data('id'), 10);
            if (!id) return;
            fetch(base + 'events/' + window.keEventId + '/testimonials/' + id, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify({ action: 'approve' }),
            }).then(function () { loadTestimonialsModList(); });
        });
    });

    updateTestimonialsEditorVisibility();

    // ─── Schedule editor ──────────────────────────────────────────────────
    let scheduleSlots = [];

    (function hydrateSchedule() {
        if (!Array.isArray(window.keExistingExtras)) return;
        const extra = window.keExistingExtras.find(function (e) { return e && e.type === 'schedule'; });
        if (!extra || !extra.config) return;
        const raw = Array.isArray(extra.config.slots) ? extra.config.slots
                  : (Array.isArray(extra.config.items) ? extra.config.items : []);
        scheduleSlots = raw.map(function (s) {
            return {
                time:        String(s.time || ''),
                title:       String(s.title || ''),
                description: String(s.description || s.desc || ''),
            };
        });
    })();

    // "HH:MM" 24h → "h:MM AM/PM" for display in the time input.
    function timeTo12(hhmm) {
        const m = /^(\d{1,2}):(\d{2})$/.exec(hhmm || '');
        if (!m) return '';
        let h = parseInt(m[1], 10);
        const mn = m[2];
        const period = h >= 12 ? 'PM' : 'AM';
        h = h % 12;
        if (h === 0) h = 12;
        return h + ':' + mn + ' ' + period;
    }

    // "h:MM AM/PM" → "HH:MM" 24h. Forgives missing AM/PM (assumes AM).
    function timeTo24(str) {
        if (!str) return '';
        const clean = String(str).trim().toUpperCase();
        const m = /^(\d{1,2}):(\d{2})\s*(AM|PM)?$/.exec(clean);
        if (!m) return '';
        let h = parseInt(m[1], 10);
        const mn = parseInt(m[2], 10);
        const period = m[3];
        if (h < 0 || h > 23 || mn < 0 || mn > 59) return '';
        if (period === 'PM' && h < 12) h += 12;
        if (period === 'AM' && h === 12) h = 0;
        return String(h).padStart(2, '0') + ':' + String(mn).padStart(2, '0');
    }

    function renderScheduleEditor() {
        const $list  = $('#ke-schedule-list');
        const $empty = $('#ke-schedule-empty');
        if (!$list.length) return;

        if (scheduleSlots.length === 0) {
            $list.empty();
            $empty.show();
            return;
        }
        $empty.hide();

        const html = scheduleSlots.map(function (s, i) {
            return ''
                + '<div class="ke-schedule-row" data-idx="' + i + '">'
                +     '<span class="ke-schedule-drag" title="Drag to reorder" aria-label="Drag to reorder">⋮⋮</span>'
                +     '<div class="ke-schedule-row-main">'
                +         '<div class="ke-schedule-row-head">'
                +             '<input type="text" class="ke-input ke-schedule-time-input" placeholder="8:00 PM" value="' + escAttr(timeTo12(s.time)) + '">'
                +             '<input type="text" class="ke-input ke-schedule-title-input" placeholder="Doors open" value="' + escAttr(s.title) + '">'
                +             '<button type="button" class="ke-schedule-remove" aria-label="Remove slot">✕</button>'
                +         '</div>'
                +         '<textarea class="ke-input ke-schedule-desc-input" rows="2" placeholder="Description (optional)">' + escHtml(s.description) + '</textarea>'
                +     '</div>'
                + '</div>';
        }).join('');
        $list.html(html);
    }

    function updateScheduleEditorVisibility() {
        const on = $('.ke-extra-toggle[data-type="schedule"]').is(':checked');
        $('#ke-schedule-editor').toggle(on);
    }

    $(document).on('click', '#ke-schedule-add', function () {
        scheduleSlots.push({ time: '', title: '', description: '' });
        renderScheduleEditor();
        triggerAutoSave();
        // Focus the title of the new row.
        $('#ke-schedule-list .ke-schedule-row').last().find('.ke-schedule-title-input').focus();
    });

    $(document).on('click', '.ke-schedule-remove', function () {
        const idx = parseInt($(this).closest('.ke-schedule-row').data('idx'), 10);
        if (Number.isNaN(idx)) return;
        scheduleSlots.splice(idx, 1);
        renderScheduleEditor();
        triggerAutoSave();
    });

    $(document).on('input', '.ke-schedule-title-input', function () {
        const idx = parseInt($(this).closest('.ke-schedule-row').data('idx'), 10);
        if (Number.isNaN(idx) || !scheduleSlots[idx]) return;
        scheduleSlots[idx].title = $(this).val();
    });

    $(document).on('input', '.ke-schedule-desc-input', function () {
        const idx = parseInt($(this).closest('.ke-schedule-row').data('idx'), 10);
        if (Number.isNaN(idx) || !scheduleSlots[idx]) return;
        scheduleSlots[idx].description = $(this).val();
    });

    // Normalize & persist time on blur (so users can type freely while editing).
    $(document).on('blur', '.ke-schedule-time-input', function () {
        const idx = parseInt($(this).closest('.ke-schedule-row').data('idx'), 10);
        if (Number.isNaN(idx) || !scheduleSlots[idx]) return;
        const h24 = timeTo24($(this).val());
        scheduleSlots[idx].time = h24;
        // Repaint the field with the canonical 12h format (or clear if invalid).
        $(this).val(h24 ? timeTo12(h24) : '');
        triggerAutoSave();
    });

    if (typeof window.Sortable === 'function') {
        const scheduleListEl = document.getElementById('ke-schedule-list');
        if (scheduleListEl) {
            new window.Sortable(scheduleListEl, {
                animation: 200,
                ghostClass: 'ke-schedule-ghost',
                handle:     '.ke-schedule-drag',
                onEnd: function (evt) {
                    if (evt.oldIndex === evt.newIndex) return;
                    const moved = scheduleSlots.splice(evt.oldIndex, 1)[0];
                    scheduleSlots.splice(evt.newIndex, 0, moved);
                    renderScheduleEditor();
                    triggerAutoSave();
                },
            });
        }
    }

    updateScheduleEditorVisibility();
    renderScheduleEditor();

    // ─── FAQ editor ───────────────────────────────────────────────────────
    let faqItems = [];

    (function hydrateFaq() {
        if (!Array.isArray(window.keExistingExtras)) return;
        const extra = window.keExistingExtras.find(function (e) { return e && e.type === 'faq'; });
        if (!extra || !extra.config) return;
        $('#ke-faq-title').val(extra.config.title || 'Frequently Asked Questions');
        const items = Array.isArray(extra.config.items) ? extra.config.items : [];
        faqItems = items.map(function (it) {
            return {
                question: String(it.question || ''),
                answer:   String(it.answer || ''),
            };
        });
    })();

    function renderFaqEditor() {
        const $list  = $('#ke-faq-list');
        const $empty = $('#ke-faq-empty');
        if (!$list.length) return;

        if (faqItems.length === 0) {
            $list.empty();
            $empty.show();
            return;
        }
        $empty.hide();

        const html = faqItems.map(function (it, i) {
            return ''
                + '<div class="ke-faq-row" data-idx="' + i + '">'
                +     '<span class="ke-faq-drag" title="Drag to reorder" aria-label="Drag to reorder">⋮⋮</span>'
                +     '<div class="ke-faq-row-main">'
                +         '<input type="text" class="ke-input ke-faq-q-input" placeholder="Question" value="' + escAttr(it.question) + '">'
                +         '<textarea class="ke-input ke-faq-a-input" rows="3" placeholder="Answer">' + escHtml(it.answer) + '</textarea>'
                +     '</div>'
                +     '<button type="button" class="ke-faq-remove" aria-label="Remove question">✕</button>'
                + '</div>';
        }).join('');
        $list.html(html);
    }

    function updateFaqEditorVisibility() {
        const on = $('.ke-extra-toggle[data-type="faq"]').is(':checked');
        $('#ke-faq-editor').toggle(on);
    }

    $(document).on('click', '#ke-faq-add', function () {
        faqItems.push({ question: '', answer: '' });
        renderFaqEditor();
        triggerAutoSave();
        $('#ke-faq-list .ke-faq-row').last().find('.ke-faq-q-input').focus();
    });

    $(document).on('click', '.ke-faq-remove', function () {
        const idx = parseInt($(this).closest('.ke-faq-row').data('idx'), 10);
        if (Number.isNaN(idx)) return;
        faqItems.splice(idx, 1);
        renderFaqEditor();
        triggerAutoSave();
    });

    $(document).on('input', '.ke-faq-q-input', function () {
        const idx = parseInt($(this).closest('.ke-faq-row').data('idx'), 10);
        if (Number.isNaN(idx) || !faqItems[idx]) return;
        faqItems[idx].question = $(this).val();
    });

    $(document).on('input', '.ke-faq-a-input', function () {
        const idx = parseInt($(this).closest('.ke-faq-row').data('idx'), 10);
        if (Number.isNaN(idx) || !faqItems[idx]) return;
        faqItems[idx].answer = $(this).val();
    });

    if (typeof window.Sortable === 'function') {
        const faqListEl = document.getElementById('ke-faq-list');
        if (faqListEl) {
            new window.Sortable(faqListEl, {
                animation: 200,
                ghostClass: 'ke-faq-ghost',
                handle:     '.ke-faq-drag',
                onEnd: function (evt) {
                    if (evt.oldIndex === evt.newIndex) return;
                    const moved = faqItems.splice(evt.oldIndex, 1)[0];
                    faqItems.splice(evt.newIndex, 0, moved);
                    renderFaqEditor();
                    triggerAutoSave();
                },
            });
        }
    }

    updateFaqEditorVisibility();
    renderFaqEditor();

    // ─── Ticket drag-drop reordering (SortableJS) ──────────────────────────
    if (typeof window.Sortable === 'function') {
        const ticketList = document.getElementById('ke-ticket-container');
        if (ticketList) {
            new window.Sortable(ticketList, {
                animation: 200,
                ghostClass: 'ke-tkt-ghost',
                handle: '.ke-tkt-drag-handle',
                onEnd: triggerAutoSave,
            });
        }
    }

    // ─── Extra Fields editor (per-attendee checkout questions) ────────────
    // Field shape: { id, label, helper, type, required, options: string[] }
    let xfEnabled = false;
    let xfItems   = [];

    const XF_TYPES = [
        { v: 'text',     l: 'Short text' },
        { v: 'textarea', l: 'Long text' },
        { v: 'number',   l: 'Number' },
        { v: 'email',    l: 'Email' },
        { v: 'phone',    l: 'Phone' },
        { v: 'select',   l: 'Dropdown' },
    ];
    const XF_VISIBILITIES = [
        { v: 'tickets',      l: 'Tickets' },
        { v: 'reservations', l: 'Reservations' },
        { v: 'both',         l: 'Both' },
    ];
    const XF_DEFAULT_VIS = 'tickets';

    function xfNewId() {
        // Match server-side fld_xxxxxx shape so client-generated rows survive a save round-trip.
        return 'fld_' + Math.random().toString(36).slice(2, 8);
    }

    (function hydrateExtraFields() {
        const cfg = window.keExistingExtraFields;
        if (!cfg || typeof cfg !== 'object') return;
        xfEnabled = !!cfg.enabled;
        if (Array.isArray(cfg.fields)) {
            xfItems = cfg.fields.map(function (f) {
                let vis = String(f.visibility || XF_DEFAULT_VIS);
                if (!XF_VISIBILITIES.some(function (v) { return v.v === vis; })) vis = XF_DEFAULT_VIS;
                return {
                    id:         String(f.id || xfNewId()),
                    label:      String(f.label || ''),
                    helper:     String(f.helper || ''),
                    type:       String(f.type || 'text'),
                    required:   !!f.required,
                    visibility: vis,
                    options:    Array.isArray(f.options) ? f.options.map(String) : [],
                };
            });
        }
        $('#ke-xfields-enabled').prop('checked', xfEnabled);
    })();

    function collectExtraFields() {
        // Live read from the DOM so the latest user input is captured even
        // before the input handlers have synced back into xfItems.
        const fields = [];
        $('#ke-xfields-list .ke-xf-row').each(function () {
            const $row = $(this);
            const idx  = parseInt($row.data('idx'), 10);
            if (Number.isNaN(idx) || !xfItems[idx]) return;
            const it = xfItems[idx];
            const label = ($row.find('.ke-xf-label').val() || '').toString().trim();
            if (!label) return;
            let vis = ($row.find('.ke-xf-visibility').val() || XF_DEFAULT_VIS).toString();
            if (!XF_VISIBILITIES.some(function (v) { return v.v === vis; })) vis = XF_DEFAULT_VIS;
            fields.push({
                id:         it.id || xfNewId(),
                label:      label,
                helper:     ($row.find('.ke-xf-helper').val() || '').toString(),
                type:       ($row.find('.ke-xf-type').val() || 'text').toString(),
                required:   $row.find('.ke-xf-required').is(':checked'),
                visibility: vis,
                options:    (it.options || []).map(String).filter(function (o) { return o.length > 0; }),
            });
        });
        return { enabled: !!$('#ke-xfields-enabled').is(':checked'), fields: fields };
    }

    function renderExtraFieldsEditor() {
        const $list  = $('#ke-xfields-list');
        const $empty = $('#ke-xfields-empty');
        if (!$list.length) return;

        if (xfItems.length === 0) {
            $list.empty();
            $empty.show();
            return;
        }
        $empty.hide();

        const html = xfItems.map(function (it, i) {
            const typeOpts = XF_TYPES.map(function (t) {
                return '<option value="' + t.v + '"' + (t.v === it.type ? ' selected' : '') + '>' + t.l + '</option>';
            }).join('');
            const itVis = XF_VISIBILITIES.some(function (v) { return v.v === it.visibility; }) ? it.visibility : XF_DEFAULT_VIS;
            const visOpts = XF_VISIBILITIES.map(function (v) {
                return '<option value="' + v.v + '"' + (v.v === itVis ? ' selected' : '') + '>' + v.l + '</option>';
            }).join('');

            const optionsBlock = (it.type === 'select')
                ? ''
                    + '<div class="ke-xf-options">'
                    +     '<div class="ke-xf-options-label">Dropdown options</div>'
                    +     '<div class="ke-xf-options-list">'
                    +         (it.options.length ? it.options.map(function (opt, oi) {
                                return ''
                                    + '<div class="ke-xf-option-row" data-oi="' + oi + '">'
                                    +     '<input type="text" class="ke-input ke-xf-option-input" value="' + escAttr(opt) + '" placeholder="Option ' + (oi + 1) + '">'
                                    +     '<button type="button" class="ke-xf-option-remove" aria-label="Remove option">✕</button>'
                                    + '</div>';
                              }).join('') : '<div class="ke-xf-options-empty">No options yet — add at least one.</div>')
                    +     '</div>'
                    +     '<button type="button" class="ke-btn ke-btn-secondary ke-btn-small ke-xf-option-add">+ Add Option</button>'
                    + '</div>'
                : '';

            return ''
                + '<div class="ke-xf-row" data-idx="' + i + '">'
                +     '<span class="ke-xf-drag" title="Drag to reorder" aria-label="Drag to reorder">⋮⋮</span>'
                +     '<div class="ke-xf-row-main">'
                +         '<div class="ke-xf-row-top">'
                +             '<input type="text" class="ke-input ke-xf-label" placeholder="Field label (e.g., University name)" value="' + escAttr(it.label) + '">'
                +             '<select class="ke-select ke-xf-type">' + typeOpts + '</select>'
                +         '</div>'
                +         '<input type="text" class="ke-input ke-xf-helper" placeholder="Helper text (optional)" value="' + escAttr(it.helper) + '">'
                +         '<label class="ke-xf-required-wrap">'
                +             '<input type="checkbox" class="ke-xf-required"' + (it.required ? ' checked' : '') + '>'
                +             '<span>Required</span>'
                +         '</label>'
                +         '<label class="ke-xf-vis-wrap">'
                +             '<span>Show in:</span>'
                +             '<select class="ke-select ke-xf-visibility">' + visOpts + '</select>'
                +         '</label>'
                +         optionsBlock
                +     '</div>'
                +     '<button type="button" class="ke-xf-remove" aria-label="Remove field">✕</button>'
                + '</div>';
        }).join('');
        $list.html(html);
    }

    function updateExtraFieldsBodyVisibility() {
        $('#ke-xfields-body').toggle(xfEnabled);
    }

    $(document).on('change', '#ke-xfields-enabled', function () {
        xfEnabled = $(this).is(':checked');
        updateExtraFieldsBodyVisibility();
        triggerAutoSave();
    });

    $(document).on('click', '#ke-xfields-add', function () {
        xfItems.push({ id: xfNewId(), label: '', helper: '', type: 'text', required: false, visibility: XF_DEFAULT_VIS, options: [] });
        renderExtraFieldsEditor();
        triggerAutoSave();
        $('#ke-xfields-list .ke-xf-row').last().find('.ke-xf-label').focus();
    });

    $(document).on('change', '.ke-xf-visibility', function () {
        const idx = parseInt($(this).closest('.ke-xf-row').data('idx'), 10);
        if (Number.isNaN(idx) || !xfItems[idx]) return;
        let vis = ($(this).val() || XF_DEFAULT_VIS).toString();
        if (!XF_VISIBILITIES.some(function (v) { return v.v === vis; })) vis = XF_DEFAULT_VIS;
        xfItems[idx].visibility = vis;
        triggerAutoSave();
    });

    $(document).on('click', '.ke-xf-remove', function () {
        const idx = parseInt($(this).closest('.ke-xf-row').data('idx'), 10);
        if (Number.isNaN(idx)) return;
        xfItems.splice(idx, 1);
        renderExtraFieldsEditor();
        triggerAutoSave();
    });

    $(document).on('input', '.ke-xf-label', function () {
        const idx = parseInt($(this).closest('.ke-xf-row').data('idx'), 10);
        if (Number.isNaN(idx) || !xfItems[idx]) return;
        xfItems[idx].label = $(this).val();
    });

    $(document).on('input', '.ke-xf-helper', function () {
        const idx = parseInt($(this).closest('.ke-xf-row').data('idx'), 10);
        if (Number.isNaN(idx) || !xfItems[idx]) return;
        xfItems[idx].helper = $(this).val();
    });

    $(document).on('change', '.ke-xf-type', function () {
        const idx = parseInt($(this).closest('.ke-xf-row').data('idx'), 10);
        if (Number.isNaN(idx) || !xfItems[idx]) return;
        const newType = ($(this).val() || 'text').toString();
        xfItems[idx].type = newType;
        // Seed one empty option when switching to select so the UI isn't blank.
        if (newType === 'select' && xfItems[idx].options.length === 0) {
            xfItems[idx].options = [''];
        }
        renderExtraFieldsEditor();
        triggerAutoSave();
    });

    $(document).on('change', '.ke-xf-required', function () {
        const idx = parseInt($(this).closest('.ke-xf-row').data('idx'), 10);
        if (Number.isNaN(idx) || !xfItems[idx]) return;
        xfItems[idx].required = $(this).is(':checked');
        triggerAutoSave();
    });

    $(document).on('click', '.ke-xf-option-add', function () {
        const idx = parseInt($(this).closest('.ke-xf-row').data('idx'), 10);
        if (Number.isNaN(idx) || !xfItems[idx]) return;
        xfItems[idx].options.push('');
        renderExtraFieldsEditor();
        triggerAutoSave();
    });

    $(document).on('click', '.ke-xf-option-remove', function () {
        const $row = $(this).closest('.ke-xf-row');
        const idx  = parseInt($row.data('idx'), 10);
        const oi   = parseInt($(this).closest('.ke-xf-option-row').data('oi'), 10);
        if (Number.isNaN(idx) || Number.isNaN(oi) || !xfItems[idx]) return;
        xfItems[idx].options.splice(oi, 1);
        renderExtraFieldsEditor();
        triggerAutoSave();
    });

    $(document).on('input', '.ke-xf-option-input', function () {
        const $row = $(this).closest('.ke-xf-row');
        const idx  = parseInt($row.data('idx'), 10);
        const oi   = parseInt($(this).closest('.ke-xf-option-row').data('oi'), 10);
        if (Number.isNaN(idx) || Number.isNaN(oi) || !xfItems[idx]) return;
        xfItems[idx].options[oi] = $(this).val();
    });

    if (typeof window.Sortable === 'function') {
        const xfListEl = document.getElementById('ke-xfields-list');
        if (xfListEl) {
            new window.Sortable(xfListEl, {
                animation: 200,
                ghostClass: 'ke-xf-ghost',
                handle:     '.ke-xf-drag',
                onEnd: function (evt) {
                    if (evt.oldIndex === evt.newIndex) return;
                    const moved = xfItems.splice(evt.oldIndex, 1)[0];
                    xfItems.splice(evt.newIndex, 0, moved);
                    renderExtraFieldsEditor();
                    triggerAutoSave();
                },
            });
        }
    }

    updateExtraFieldsBodyVisibility();
    renderExtraFieldsEditor();

    // ─── Reservations editor (capacity bookings, parallel to tickets) ─────
    // State shape mirrors KE_Reservations::default_config() server-side.
    // The save round-trip goes through `reservations:` in the gather payload.
    let resvCfg = {
        enabled:              false,
        description:          '',
        total_capacity:       0,
        show_total_capacity:  true,
        show_area_capacity:   true,
        reservations_open:    '',
        reservations_close:   '',
        confirmation_mode:    'auto',
        grace_period_minutes: 15,
        auto_cancel_no_show:  true,
        show_email_field:     true,
        show_notes_field:     true,
        areas:                [],   // [{ name, description, capacity, fancy_effect }]
    };

    (function hydrateReservations() {
        const cfg = window.keExistingReservations;
        if (!cfg || typeof cfg !== 'object') return;
        resvCfg.enabled              = !!cfg.enabled;
        resvCfg.description          = String(cfg.description || '');
        resvCfg.total_capacity       = Math.max(0, parseInt(cfg.total_capacity, 10) || 0);
        resvCfg.show_total_capacity  = cfg.show_total_capacity !== false;
        resvCfg.show_area_capacity   = cfg.show_area_capacity  !== false;
        resvCfg.reservations_open    = String(cfg.reservations_open  || '');
        resvCfg.reservations_close   = String(cfg.reservations_close || '');
        resvCfg.confirmation_mode    = cfg.confirmation_mode === 'manual' ? 'manual' : 'auto';
        const grace                  = parseInt(cfg.grace_period_minutes, 10);
        resvCfg.grace_period_minutes = Number.isNaN(grace) ? 15 : Math.max(0, Math.min(240, grace));
        resvCfg.auto_cancel_no_show  = cfg.auto_cancel_no_show !== false;
        resvCfg.show_email_field     = cfg.show_email_field !== false;
        resvCfg.show_notes_field     = cfg.show_notes_field !== false;
        resvCfg.areas = Array.isArray(cfg.areas) ? cfg.areas.map(function (a) {
            const effect = (a && typeof a.fancy_effect === 'string') ? a.fancy_effect : 'none';
            return {
                name:         String((a && a.name) || ''),
                description:  String((a && a.description) || ''),
                capacity:     Math.max(0, parseInt(a && a.capacity, 10) || 0),
                fancy_effect: ['none','gold','diamond','vip','crown','neon'].indexOf(effect) >= 0 ? effect : 'none',
            };
        }) : [];
    })();

    // Coerce server "Y-m-d H:i:s" (or already-local "Y-m-dTH:i") to the
    // datetime-local input's required "Y-m-dTH:i" shape.
    function resvToLocalDt(val) {
        if (!val) return '';
        const m = String(val).match(/^(\d{4}-\d{2}-\d{2})[T\s](\d{2}:\d{2})/);
        return m ? m[1] + 'T' + m[2] : '';
    }

    const RESV_FANCY_EFFECTS = ['none', 'gold', 'diamond', 'vip', 'crown', 'neon'];

    function fancyEffectOptions(selected) {
        return RESV_FANCY_EFFECTS.map(function (eff) {
            const label = eff.charAt(0).toUpperCase() + eff.slice(1);
            const sel = (eff === selected) ? ' selected' : '';
            return '<option value="' + eff + '"' + sel + '>' + label + '</option>';
        }).join('');
    }

    function renderResvAreas() {
        const $list = $('#ke-resv-areas-list');
        if (!$list.length) return;
        if (resvCfg.areas.length === 0) {
            $list.empty();
            return;
        }
        const html = resvCfg.areas.map(function (a, i) {
            const effect = (RESV_FANCY_EFFECTS.indexOf(a.fancy_effect) >= 0) ? a.fancy_effect : 'none';
            return ''
                + '<div class="ke-resv-area-row" data-aidx="' + i + '">'
                +     '<div class="ke-resv-area-row-main">'
                +         '<input type="text" class="ke-input ke-resv-area-name" placeholder="Area name (e.g., VIP)" value="' + escAttr(a.name) + '">'
                +         '<div class="ke-resv-area-cap-wrap">'
                +             '<input type="number" class="ke-input ke-resv-area-cap" min="0" step="1" value="' + (parseInt(a.capacity, 10) || 0) + '" aria-label="Area capacity">'
                +         '</div>'
                +         '<button type="button" class="ke-resv-area-remove" aria-label="Remove area">✕</button>'
                +     '</div>'
                +     '<div class="ke-resv-area-row-extra">'
                +         '<input type="text" class="ke-input ke-resv-area-desc" placeholder="Short description (e.g., Premium bottle service)" value="' + escAttr(a.description || '') + '">'
                +         '<div class="ke-resv-area-effect-wrap">'
                +             '<select class="ke-input ke-resv-area-effect" aria-label="Fancy effect">' + fancyEffectOptions(effect) + '</select>'
                +             '<span class="ke-resv-area-effect-preview" data-effect="' + escAttr(effect) + '" aria-hidden="true">Preview</span>'
                +         '</div>'
                +     '</div>'
                + '</div>';
        }).join('');
        $list.html(html);
    }

    function renderResvForm() {
        $('#ke-resv-enabled').prop('checked', resvCfg.enabled);
        $('#ke-resv-body').toggleClass('is-open', !!resvCfg.enabled);
        $('#ke-resv-description').val(resvCfg.description || '');
        $('#ke-resv-capacity').val(resvCfg.total_capacity || '');
        $('#ke-resv-show-total-capacity').prop('checked', resvCfg.show_total_capacity !== false);
        $('#ke-resv-show-area-capacity').prop('checked', resvCfg.show_area_capacity !== false);
        $('#ke-resv-open').val(resvToLocalDt(resvCfg.reservations_open));
        $('#ke-resv-close').val(resvToLocalDt(resvCfg.reservations_close));
        $('input[name="ke_resv_mode"][value="' + resvCfg.confirmation_mode + '"]').prop('checked', true);
        $('#ke-resv-show-email').prop('checked', !!resvCfg.show_email_field);
        $('#ke-resv-show-notes').prop('checked', !!resvCfg.show_notes_field);
        $('#ke-resv-auto-cancel').prop('checked', !!resvCfg.auto_cancel_no_show);
        $('#ke-resv-grace').val(resvCfg.grace_period_minutes);
        $('#ke-resv-grace-row').toggleClass('is-disabled', !resvCfg.auto_cancel_no_show);
        renderResvAreas();
    }

    function collectReservations() {
        // Live-read from DOM so the latest input is captured even before
        // change handlers have synced back into resvCfg.
        const areas = [];
        $('#ke-resv-areas-list .ke-resv-area-row').each(function () {
            const name = ($(this).find('.ke-resv-area-name').val() || '').toString().trim();
            if (!name) return;
            const cap    = parseInt($(this).find('.ke-resv-area-cap').val(), 10);
            const desc   = ($(this).find('.ke-resv-area-desc').val() || '').toString().trim();
            const effect = ($(this).find('.ke-resv-area-effect').val() || 'none').toString();
            areas.push({
                name:         name,
                description:  desc,
                capacity:     Number.isNaN(cap) ? 0 : Math.max(0, cap),
                fancy_effect: RESV_FANCY_EFFECTS.indexOf(effect) >= 0 ? effect : 'none',
            });
        });

        const grace = parseInt($('#ke-resv-grace').val(), 10);

        return {
            enabled:              $('#ke-resv-enabled').is(':checked'),
            description:          ($('#ke-resv-description').val() || '').toString(),
            total_capacity:       Math.max(0, parseInt($('#ke-resv-capacity').val(), 10) || 0),
            show_total_capacity:  $('#ke-resv-show-total-capacity').is(':checked'),
            show_area_capacity:   $('#ke-resv-show-area-capacity').is(':checked'),
            reservations_open:    ($('#ke-resv-open').val()  || '').toString(),
            reservations_close:   ($('#ke-resv-close').val() || '').toString(),
            confirmation_mode:    $('input[name="ke_resv_mode"]:checked').val() === 'manual' ? 'manual' : 'auto',
            grace_period_minutes: Number.isNaN(grace) ? 15 : Math.max(0, Math.min(240, grace)),
            auto_cancel_no_show:  $('#ke-resv-auto-cancel').is(':checked'),
            show_email_field:     $('#ke-resv-show-email').is(':checked'),
            show_notes_field:     $('#ke-resv-show-notes').is(':checked'),
            areas:                areas,
        };
    }

    // Top-level toggle. Note: the visibility class (.is-open) is also flipped
    // by the inline vanilla-JS handler in event-builder.php — that one is the
    // safety net if this jQuery script never reaches this line on production.
    // Both handlers converge on the same class so they don't fight.
    $(document).on('change', '#ke-resv-enabled', function () {
        resvCfg.enabled = $(this).is(':checked');
        $('#ke-resv-body').toggleClass('is-open', resvCfg.enabled);
        triggerAutoSave();
    });

    // Late-arrival sub-toggle
    $(document).on('change', '#ke-resv-auto-cancel', function () {
        resvCfg.auto_cancel_no_show = $(this).is(':checked');
        $('#ke-resv-grace-row').toggleClass('is-disabled', !resvCfg.auto_cancel_no_show);
        triggerAutoSave();
    });

    // Generic auto-save on simple field changes — collectReservations()
    // already live-reads from the DOM so we only need to nudge the saver.
    $(document).on(
        'input change',
        '#ke-resv-description, #ke-resv-capacity, '
        + '#ke-resv-show-total-capacity, #ke-resv-show-area-capacity, '
        + '#ke-resv-open, #ke-resv-close, '
        + 'input[name="ke_resv_mode"], #ke-resv-show-email, '
        + '#ke-resv-show-notes, #ke-resv-grace',
        triggerAutoSave
    );

    // Areas: add row
    $(document).on('click', '#ke-resv-area-add', function () {
        resvCfg.areas.push({ name: '', description: '', capacity: 0, fancy_effect: 'none' });
        renderResvAreas();
        triggerAutoSave();
        $('#ke-resv-areas-list .ke-resv-area-row').last().find('.ke-resv-area-name').focus();
    });

    // Areas: remove row
    $(document).on('click', '.ke-resv-area-remove', function () {
        const idx = parseInt($(this).closest('.ke-resv-area-row').data('aidx'), 10);
        if (Number.isNaN(idx)) return;
        resvCfg.areas.splice(idx, 1);
        renderResvAreas();
        triggerAutoSave();
    });

    // Areas: sync typed values back into state so an add/remove doesn't drop them.
    $(document).on('input', '.ke-resv-area-name', function () {
        const idx = parseInt($(this).closest('.ke-resv-area-row').data('aidx'), 10);
        if (Number.isNaN(idx) || !resvCfg.areas[idx]) return;
        resvCfg.areas[idx].name = $(this).val();
    });
    $(document).on('input', '.ke-resv-area-cap', function () {
        const idx = parseInt($(this).closest('.ke-resv-area-row').data('aidx'), 10);
        if (Number.isNaN(idx) || !resvCfg.areas[idx]) return;
        const cap = parseInt($(this).val(), 10);
        resvCfg.areas[idx].capacity = Number.isNaN(cap) ? 0 : Math.max(0, cap);
    });
    $(document).on('input', '.ke-resv-area-desc', function () {
        const idx = parseInt($(this).closest('.ke-resv-area-row').data('aidx'), 10);
        if (Number.isNaN(idx) || !resvCfg.areas[idx]) return;
        resvCfg.areas[idx].description = $(this).val();
        triggerAutoSave();
    });
    $(document).on('change', '.ke-resv-area-effect', function () {
        const idx = parseInt($(this).closest('.ke-resv-area-row').data('aidx'), 10);
        if (Number.isNaN(idx) || !resvCfg.areas[idx]) return;
        const val = ($(this).val() || 'none').toString();
        const effect = RESV_FANCY_EFFECTS.indexOf(val) >= 0 ? val : 'none';
        resvCfg.areas[idx].fancy_effect = effect;
        $(this).closest('.ke-resv-area-effect-wrap')
               .find('.ke-resv-area-effect-preview')
               .attr('data-effect', effect);
        triggerAutoSave();
    });

    renderResvForm();

    // ═══════════════════════════════════════════════════════════════════
    // PROMOTERS — per-event commission assignment
    // ═══════════════════════════════════════════════════════════════════

    // Local state. Hydrated from the editor's data-attrs on first render.
    let promoterAssignments = []; // [{ promoter_id, name, email, slug, status, commission_type, commission_value }]
    let allPromoters        = []; // [{ id, name, email, slug, status }]

    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function readPromoterEditorDataset() {
        const $ed = $('#ke-promoters-editor');
        if (!$ed.length) return;
        try {
            const raw = $ed.attr('data-assignments') || '[]';
            promoterAssignments = JSON.parse(raw) || [];
        } catch (e) { promoterAssignments = []; }
        try {
            const raw = $ed.attr('data-all-promoters') || '[]';
            allPromoters = JSON.parse(raw) || [];
        } catch (e) { allPromoters = []; }
    }

    function renderPromoterRows() {
        const $list  = $('#ke-promoters-list');
        const $empty = $('#ke-promoters-empty');
        if (!$list.length) return;

        if (!promoterAssignments.length) {
            $list.empty();
            $empty.show();
        } else {
            $empty.hide();
            const rows = promoterAssignments.map(function (a, idx) {
                const typeLabel = a.commission_type === 'fixed' ? '$ fixed' : '% of price';
                const statusBg  = a.status === 'active'   ? 'var(--kiwi-legacy-green-pill-bg)'
                               : a.status === 'pending'   ? 'var(--kiwi-legacy-yellow-pill-bg)'
                                                          : 'var(--kiwi-legacy-row-bg)';
                const statusFg  = a.status === 'active'   ? 'var(--kiwi-legacy-emerald-800)'
                               : a.status === 'pending'   ? 'var(--kiwi-legacy-yellow-pill-text)'
                                                          : 'var(--kiwi-legacy-text-mid)';
                return ''
                    + '<div class="ke-promoter-row" data-idx="' + idx + '" '
                    +      'style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; padding:10px 12px; background:var(--kiwi-surface); border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:8px;">'
                    +   '<div style="flex:1 1 220px; min-width:0;">'
                    +     '<div style="font-weight:600; font-size:13px; color:var(--kiwi-legacy-dark-2);">' + escHtml(a.name)
                    +       ' <span style="display:inline-block; margin-left:6px; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:600; text-transform:uppercase; background:' + statusBg + '; color:' + statusFg + ';">' + escHtml(a.status) + '</span>'
                    +     '</div>'
                    +     '<div style="font-size:12px; color:var(--kiwi-legacy-text-muted);">'
                    +       escHtml(a.email) + ' · <code style="font-size:11px;">' + escHtml(a.slug) + '</code>'
                    +     '</div>'
                    +   '</div>'
                    +   '<select class="ke-select ke-promoter-row-type" style="width:130px;">'
                    +     '<option value="percentage"' + (a.commission_type !== 'fixed' ? ' selected' : '') + '>% of price</option>'
                    +     '<option value="fixed"'      + (a.commission_type === 'fixed' ? ' selected' : '') + '>$ fixed</option>'
                    +   '</select>'
                    +   '<input type="number" class="ke-input ke-input-sm ke-promoter-row-value" min="0" step="0.01" value="' + escHtml(a.commission_value) + '" style="width:90px;">'
                    +   '<button type="button" class="ke-btn ke-btn-ghost ke-promoter-row-remove" aria-label="Remove" title="Remove" style="padding:6px 10px;">✕</button>'
                    + '</div>';
            }).join('');
            $list.html(rows);
        }
    }

    function refreshPromoterPicker() {
        const $picker = $('#ke-promoter-picker');
        if (!$picker.length) return;

        const assignedIds = {};
        promoterAssignments.forEach(function (a) { assignedIds[a.promoter_id] = true; });

        const opts = ['<option value="">— Choose a promoter to add —</option>'];
        allPromoters.forEach(function (p) {
            if (assignedIds[p.id]) return;
            const tag = p.status === 'pending' ? ' (pending)' : '';
            opts.push('<option value="' + p.id + '">' + escHtml(p.name) + tag + ' — ' + escHtml(p.email) + '</option>');
        });
        $picker.html(opts.join(''));
    }

    function renderPromoters() {
        renderPromoterRows();
        refreshPromoterPicker();
    }

    function collectPromoterAssignments() {
        // Single source of truth is the in-memory array — DOM-bound type/value
        // inputs are mirrored into it on change below.
        return promoterAssignments.map(function (a) {
            return {
                promoter_id:      parseInt(a.promoter_id, 10) || 0,
                commission_type:  a.commission_type === 'fixed' ? 'fixed' : 'percentage',
                commission_value: Math.max(0, parseFloat(a.commission_value) || 0),
            };
        }).filter(function (a) { return a.promoter_id > 0; });
    }

    // Add row
    $(document).on('click', '#ke-promoter-add-btn', function () {
        const pid   = parseInt($('#ke-promoter-picker').val(), 10) || 0;
        if (!pid) return;
        const found = allPromoters.find(function (p) { return p.id === pid; });
        if (!found) return;

        const type  = $('#ke-promoter-picker-type').val() === 'fixed' ? 'fixed' : 'percentage';
        const value = Math.max(0, parseFloat($('#ke-promoter-picker-value').val()) || 0);

        promoterAssignments.push({
            promoter_id:      pid,
            name:             found.name,
            email:            found.email,
            slug:             found.slug,
            status:           found.status,
            commission_type:  type,
            commission_value: value,
        });

        $('#ke-promoter-picker-value').val('');
        renderPromoters();
        triggerAutoSave();
    });

    // Remove row
    $(document).on('click', '.ke-promoter-row-remove', function () {
        const idx = parseInt($(this).closest('.ke-promoter-row').data('idx'), 10);
        if (Number.isNaN(idx)) return;
        promoterAssignments.splice(idx, 1);
        renderPromoters();
        triggerAutoSave();
    });

    // Sync type + value back into state
    $(document).on('change', '.ke-promoter-row-type', function () {
        const idx = parseInt($(this).closest('.ke-promoter-row').data('idx'), 10);
        if (Number.isNaN(idx) || !promoterAssignments[idx]) return;
        promoterAssignments[idx].commission_type = $(this).val() === 'fixed' ? 'fixed' : 'percentage';
        triggerAutoSave();
    });
    $(document).on('input', '.ke-promoter-row-value', function () {
        const idx = parseInt($(this).closest('.ke-promoter-row').data('idx'), 10);
        if (Number.isNaN(idx) || !promoterAssignments[idx]) return;
        const v = parseFloat($(this).val());
        promoterAssignments[idx].commission_value = Number.isNaN(v) ? 0 : Math.max(0, v);
        triggerAutoSave();
    });

    readPromoterEditorDataset();
    renderPromoters();

});
