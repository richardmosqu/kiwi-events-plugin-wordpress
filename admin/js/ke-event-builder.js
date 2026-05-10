/**
 * KiwiEvents — Event Builder (3-step wizard)
 */
jQuery(document).ready(function ($) {

    // ─── State ─────────────────────────────────────────────────────────────
    let currentStep = 1;
    const TOTAL_STEPS = 3;
    let ticketIndex = 0;
    let cachedTemplates = [];   // templates for selected organizer

    // ─── Step navigation ───────────────────────────────────────────────────
    function goToStep(n) {
        if (n < 1 || n > TOTAL_STEPS) return;

        // Panels
        $('.ke-wizard-panel').removeClass('active');
        $('#ke-step-' + n).addClass('active');

        // Nav bars
        $('.ke-wizard-nav').hide();
        $('#ke-nav-' + n).show();

        // Progress bar
        $('.ke-wizard-step-item').each(function () {
            const s = parseInt($(this).data('step'), 10);
            $(this).removeClass('active done');
            if (s < n)       $(this).addClass('done');
            else if (s === n) $(this).addClass('active');
        });
        $('.ke-wizard-line').each(function (i) {
            $(this).toggleClass('done', i < n - 1);
        });

        currentStep = n;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

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

    // ─── Ticket card rendering ─────────────────────────────────────────────
    function renderTicketCard(data, idx) {
        const isPaid          = (data.ticket_type || 'free') === 'paid';
        const isLimited       = (data.capacity_type || 'limited') === 'limited';
        const showRem         = (data.show_remaining || 'yes') === 'yes';

        return `
<div class="ke-tkt-card" data-idx="${idx}" data-id="${data.id || 0}">
    <div class="ke-tkt-header">
        <div class="ke-tkt-header-left">
            <span class="ke-tkt-drag-handle" title="Drag to reorder" aria-label="Drag to reorder">⋮⋮</span>
            <span class="ke-tkt-badge ${isPaid ? 'paid' : 'free'}">${isPaid ? 'PAID' : 'FREE'}</span>
            <span class="ke-tkt-title-preview">${escHtml(data.name || 'New Ticket')}</span>
        </div>
        <div class="ke-tkt-header-right">
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
            });
        });

        const cats = [];
        $('.ke-cat-check:checked').each(function () {
            cats.push(parseInt($(this).val(), 10));
        });

        return {
            event_id:              window.keEventId || 0,
            title:                 $('#ke-event-title').val().trim(),
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
            promo_label:           $('#ke-promo-label').val().trim(),
            service_fee_id:        $('#ke-service-fee-id').val() || '',
            banner_id:             parseInt($('#ke-banner-id').val(), 10) || 0,
            tickets:               tickets,
            extras:                collectExtras(),
            extra_fields:          collectExtraFields(),
            reservations:          collectReservations(),
        };
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
        data.status = 'draft';

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
        triggerAutoSave
    );

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
            $c.html('<p style="color:#94a3b8;font-size:14px;">No templates available.</p>');
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
                '  <span style="color:#6366f1;font-size:13px;font-weight:700;flex-shrink:0;margin-left:12px;">Apply →</span>',
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
        if (t === 'lineup')       updateLineupEditorVisibility();
        if (t === 'gallery')      updateGalleryEditorVisibility();
        if (t === 'testimonials') updateTestimonialsEditorVisibility();
        if (t === 'schedule')     updateScheduleEditorVisibility();
        if (t === 'faq')          updateFaqEditorVisibility();
    });

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

});
