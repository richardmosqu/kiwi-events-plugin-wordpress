/**
 * KiwiEvents — Checkout JS
 * Bottom-sheet modal flow: Ticket Card → Quantity → Details → Confirmation
 */
jQuery(document).ready(function($) {
    'use strict';

    // ─── State ──────────────────────────────────────────
    let currentTicket  = null;
    let qty            = 1;
    let sheetCloseable = false;   // Prevents ghost-click / mid-animation closes
    let ajaxInFlight   = false;   // Prevents duplicate submissions

    const $overlay     = $('#ke-sheet-overlay');
    const $sheet       = $('#ke-checkout-sheet');
    const $stepQty     = $('#ke-sheet-step-qty');
    const $stepDetails = $('#ke-sheet-step-details');

    // Service fee injected per-event via wp_localize_script
    const activeFee = (typeof kePublic !== 'undefined' && kePublic.serviceFee) ? kePublic.serviceFee : null;

    // ─── Fee calculation ─────────────────────────────────
    function calcFee(unitPrice) {
        if (!activeFee || unitPrice === 0) return 0;
        if (activeFee.type === 'formula') {
            return (unitPrice * parseFloat(activeFee.percentage || 0) / 100)
                 + parseFloat(activeFee.fixed_amount || 0);
        }
        return parseFloat(activeFee.fixed_amount || 0);
    }

    // ─── Open Sheet ──────────────────────────────────────────
    $(document).on('click', '.ke-event-page .ke-ticket:not(.ke-ticket--sold-out)', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const $card = $(this);

        currentTicket = {
            id:           parseInt($card.data('ticket-type-id')),
            name:         $card.data('name'),
            price:        parseFloat($card.data('price')) || 0,
            remaining:    parseInt($card.data('remaining')) || 9999,
            min:          parseInt($card.data('min')) || 1,
            max:          parseInt($card.data('max')) || 10,
            customFields: [],
        };

        // Parse custom fields definition from ticket button
        try {
            const cfRaw = $card.attr('data-custom-fields');
            if (cfRaw) {
                const parsed = JSON.parse(cfRaw);
                if (Array.isArray(parsed)) currentTicket.customFields = parsed;
            }
        } catch(e) {}

        currentTicket.max = Math.min(currentTicket.max, currentTicket.remaining);
        qty = currentTicket.min;

        $('#ke-sheet-ticket-name').text(currentTicket.name);

        // Open FIRST (empties attendees container), then render into it
        sheetCloseable = false;
        openSheet();
        $stepQty.show();
        $stepDetails.hide();
        $('#ke-sheet-message').hide();
        updateSheetDisplay();
        setTimeout(function() { sheetCloseable = true; }, 450);
    });

    // ─── Close ───────────────────────────────────────────
    $overlay.on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (sheetCloseable) closeSheet();
    });
    $('#ke-sheet-close-btn').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        closeSheet();
    });
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && sheetCloseable) closeSheet();
    });

    // ─── Quantity Controls ───────────────────────────────
    $('#ke-sheet-minus').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (qty > currentTicket.min) { qty--; updateSheetDisplay(); }
    });
    $('#ke-sheet-plus').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (qty < currentTicket.max) { qty++; updateSheetDisplay(); }
    });

    // ─── Continue to details ─────────────────────────────────
    $('#ke-sheet-continue').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        sheetCloseable = false;
        $stepQty.slideUp(200);
        setTimeout(function() {
            resetDetailsStep();
            $stepDetails.slideDown(200, function() {
                sheetCloseable = true;
            });
        }, 210);
    });

    // ─── Submit ──────────────────────────────────────────
    $('#ke-checkout-form-sheet').on('submit', function(e) {
        e.preventDefault();
        e.stopPropagation();

        if (ajaxInFlight) return;

        const $msg = $('#ke-sheet-message');
        const $btn = $('#ke-sheet-submit');

        const attendees = [];
        let valid = true;

        $('#ke-sheet-attendees-container .ke-sheet-attendee-group').each(function() {
            const n     = $(this).find('.ke-attendee-name').val().trim();
            const email = $(this).find('.ke-attendee-email').val().trim();
            if (!n || !email) valid = false;
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) valid = false;
            const customData = {};
            $(this).find('.ke-custom-field').each(function() {
                const fid = $(this).data('field-id');
                const val = $(this).val().trim();
                if (fid) customData[fid] = val;
                if ($(this).prop('required') && !val) valid = false;
            });
            attendees.push({ name: n, email: email, custom_fields: customData });
        });

        if (attendees.length === 0) {
            showMsg($msg, 'Please fill in your details before continuing.', 'error');
            $btn.prop('disabled', false).html(submitBtnHTML());
            ajaxInFlight = false;
            sheetCloseable = true;
            return;
        }

        if (!valid) {
            showMsg($msg, 'Please provide a valid name and email for all attendees.', 'error');
            return;
        }

        ajaxInFlight = true;
        sheetCloseable = false;  // Block accidental close during submit
        $btn.prop('disabled', true).html('<span class="ke-spinner"></span> Processing...');
        $msg.hide();

        $.ajax({
            url:         kePublic.restUrl.replace(/\/?$/, '/') + 'checkout',
            method:      'POST',
            timeout:     30000,
            data:        JSON.stringify({
                event_id:       parseInt($sheet.data('event-id')),
                ticket_type_id: currentTicket.id,
                quantity:       qty,
                name:           attendees[0].name,
                email:          attendees[0].email,
                attendees:      attendees,
            }),
            contentType: 'application/json',
            beforeSend:  function(xhr) { xhr.setRequestHeader('X-WP-Nonce', kePublic.nonce); },

            success: function(response) {
                ajaxInFlight = false;

                // Paid ticket via WooCommerce → redirect
                if (response.payment_type === 'woocommerce' && response.redirect) {
                    $btn.html('<span class="ke-spinner"></span> Redirecting...');
                    window.location.href = response.redirect;
                    return;
                }

                // Free ticket → show in-sheet confirmation screen
                showConfirmation(response);
                sheetCloseable = true;
            },

            error: function(xhr, status) {
                ajaxInFlight = false;
                sheetCloseable = true;

                let msg = 'Something went wrong. Please try again.';
                if (status === 'timeout') {
                    msg = 'The request timed out. Please check your connection and try again.';
                } else if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }

                showMsg($msg, msg, 'error');
                $btn.prop('disabled', false).html(submitBtnHTML());
            }
        });
    });

    // ─── Confirmation screen ─────────────────────────────────
    function showConfirmation(response) {
        const $formWrap = $('#ke-checkout-form-sheet');
        const $footer   = $stepDetails.find('.ke-sheet-footer');

        // Swap form content for confirmation block
        $formWrap.find('#ke-sheet-attendees-container').hide();
        $formWrap.find('.ke-sheet-breakdown').hide();
        $('#ke-sheet-message').hide();

        const tickets = response.tickets || [];
        let html = '<div class="ke-confirmation">';
        html += '<div class="ke-confirmation-icon">🎉</div>';
        html += '<div class="ke-confirmation-title">You\'re in!</div>';
        html += '<p class="ke-confirmation-msg">' + escHtml(response.message || 'Tickets confirmed!') + '</p>';

        if (tickets.length > 0) {
            const accentColor = (typeof kePublic !== 'undefined' && kePublic.accentColor)
                ? kePublic.accentColor : '#6366f1';

            html += '<div class="ke-confirmation-tickets">';
            tickets.forEach(function(ticket) {
                const shortCode = '#' + ticket.ticket_code.substring(0, 8).toUpperCase();
                const ticketUrl = kePublic.homeUrl.replace(/\/$/, '') + '/ticket/' + ticket.ticket_code;
                const qrUrl    = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&format=png&ecc=H&data='
                               + encodeURIComponent(ticket.ticket_code);

                html += '<div class="ke-confirmation-ticket">';

                // QR image
                html += '<div class="ke-confirmation-qr-wrap">';
                html += '<img class="ke-qr-img-large" src="' + escHtml(qrUrl) + '"'
                      + ' alt="QR Code" loading="lazy" width="200" height="200">';
                html += '</div>';

                // Attendee info
                html += '<div class="ke-confirmation-ticket-meta">';
                html += '<div class="ke-confirmation-attendee">' + escHtml(ticket.attendee_name) + '</div>';
                html += '<div class="ke-confirmation-code">' + escHtml(shortCode) + '</div>';
                html += '</div>';

                // Download button — accent color from plugin settings
                html += '<a class="ke-download-ticket-btn" href="' + escHtml(ticketUrl) + '"'
                      + ' target="_blank" rel="noopener"'
                      + ' style="background:' + accentColor + ' !important;background-color:' + accentColor + ' !important;">'
                      + '⬇️ Download Ticket PDF</a>';

                html += '</div>';
            });
            html += '</div>';
        }

        html += '</div>';
        $formWrap.prepend(html);

        // Replace footer with a single Done button
        $footer.html(
            '<button type="button" class="ke-sheet-btn ke-sheet-btn-primary" id="ke-confirmation-done">Done</button>' +
            '<p class="ke-sheet-terms">A confirmation email is on its way to you.</p>'
        );

        $('#ke-confirmation-done').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            closeSheet();
        });
    }

    function resetDetailsStep() {
        // Remove any previous confirmation block
        $stepDetails.find('.ke-confirmation').remove();
        // Unhide form elements and ensure attendee fields are rendered
        $('#ke-sheet-attendees-container').show();
        renderAttendeeFields();
        $stepDetails.find('.ke-sheet-breakdown').show();
        // Restore submit footer
        $stepDetails.find('.ke-sheet-footer').html(
            '<button type="submit" form="ke-checkout-form-sheet"'
          + ' class="ke-sheet-btn ke-sheet-btn-primary" id="ke-sheet-submit">'
          + submitBtnHTML()
          + '</button>'
          + '<p class="ke-sheet-terms">By continuing you agree to our Terms of Service and Privacy Policy</p>'
        );
    }

    // ─── Core helpers ────────────────────────────────────
    function openSheet() {
        $('#ke-sheet-attendees-container').empty();
        $stepQty.show();
        $stepDetails.hide();
        $overlay.addClass('active');
        $sheet.addClass('active');
        $('body').css('overflow', 'hidden');
    }

    function closeSheet() {
        $overlay.removeClass('active');
        $sheet.removeClass('active');
        $('body').css('overflow', '');
        // Reset guards so the next open starts clean
        sheetCloseable = false;
        ajaxInFlight   = false;
    }

    function updateSheetDisplay() {
        const unitPrice = currentTicket.price;
        const isFree    = unitPrice === 0;
        const subtotal  = unitPrice * qty;
        const feeUnit   = calcFee(unitPrice);
        const totalFee  = feeUnit * qty;
        const total     = subtotal + totalFee;

        $('#ke-sheet-qty-val').text(qty);

        // Fee rows (both step 1 and step 2)
        if (activeFee && feeUnit > 0 && !isFree) {
            $('#ke-fee-label, #ke-fee-label-2').text('Service Fee (' + activeFee.name + ')');
            $('#ke-fee-amount, #ke-fee-amount-2').text('$' + totalFee.toFixed(2));
            $('#ke-fee-row, #ke-fee-row-2').show();
        } else {
            $('#ke-fee-row, #ke-fee-row-2').hide();
        }

        if (isFree) {
            $('#ke-breakdown-label').text('Free \u00d7 ' + qty + (qty === 1 ? ' ticket' : ' tickets'));
            $('#ke-breakdown-subtotal').text('Free');
            $('#ke-sheet-total-val, #ke-sheet-total-val-2').text('Free');
        } else {
            const priceStr = '$' + unitPrice.toFixed(2);
            $('#ke-breakdown-label').text(priceStr + ' \u00d7 ' + qty + (qty === 1 ? ' ticket' : ' tickets'));
            $('#ke-breakdown-subtotal').text('$' + subtotal.toFixed(2));
            $('#ke-sheet-total-val, #ke-sheet-total-val-2').text('$' + total.toFixed(2));
        }

        $('#ke-sheet-minus').css('opacity', qty <= currentTicket.min ? 0.3 : 1);
        $('#ke-sheet-plus').css('opacity',  qty >= currentTicket.max ? 0.3 : 1);

        renderAttendeeFields();
    }

    function renderAttendeeFields() {
        const $container   = $('#ke-sheet-attendees-container');
        const currentCount = $container.children('.ke-sheet-attendee-group').length;
        const fields       = (currentTicket && currentTicket.customFields) ? currentTicket.customFields : [];

        if (qty > currentCount) {
            for (let i = currentCount; i < qty; i++) {
                const label = i === 0 ? 'Purchaser (Attendee 1)' : 'Attendee ' + (i + 1);
                let html = '<div class="ke-sheet-attendee ke-sheet-attendee-group">'
                         + '<div class="ke-sheet-attendee-header">' + label + '</div>'
                         + '<div class="ke-sheet-field"><input type="text" class="ke-attendee-name" placeholder="Full Name" required></div>'
                         + '<div class="ke-sheet-field"><input type="email" class="ke-attendee-email" placeholder="Email Address" required></div>';

                fields.forEach(function(cf) {
                    if (!cf || !cf.label) return;
                    const fid = escHtml(cf.id || cf.label.toLowerCase().replace(/[^a-z0-9]+/g, '_'));
                    const req = cf.required ? ' required' : '';
                    html += '<div class="ke-sheet-field">'
                          + '<label class="ke-field-label">' + escHtml(cf.label)
                          + (cf.required ? ' <span style="color:var(--kep-accent-1)">*</span>' : '') + '</label>';
                    if (cf.type === 'select' && Array.isArray(cf.options)) {
                        html += '<select class="ke-custom-field" data-field-id="' + fid + '"' + req + '>'
                              + '<option value="">— Select —</option>';
                        cf.options.forEach(function(opt) {
                            html += '<option value="' + escHtml(opt) + '">' + escHtml(opt) + '</option>';
                        });
                        html += '</select>';
                    } else if (cf.type === 'textarea') {
                        html += '<textarea class="ke-custom-field" data-field-id="' + fid + '" placeholder="' + escHtml(cf.label) + '"' + req + '></textarea>';
                    } else {
                        html += '<input type="text" class="ke-custom-field" data-field-id="' + fid + '" placeholder="' + escHtml(cf.label) + '"' + req + '>';
                    }
                    html += '</div>';
                });

                html += '</div>';
                $container.append(html);
            }
        } else if (qty < currentCount) {
            $container.children('.ke-sheet-attendee-group').slice(qty).remove();
        }
    }

    function showMsg($el, text, type) {
        $el.html(text).removeClass('ke-msg-success ke-msg-error').addClass('ke-msg-' + type).show();
    }

    function submitBtnHTML() {
        return '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor"'
             + ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
             + '<path d="M1.5 8h13M8.5 3l5 5-5 5"/></svg> Get Tickets';
    }

    function escHtml(str) {
        if (typeof str !== 'string') return '';
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

}); // end jQuery(document).ready
