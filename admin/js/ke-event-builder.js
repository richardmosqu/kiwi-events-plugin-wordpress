/**
 * KiwiEvents - Event Builder Logic
 */
jQuery(document).ready(function($) {

    // 1. Navigation Logic
    const $steps = $('.ke-step');
    const $panels = $('.ke-step-panel');

    $steps.on('click', function() {
        // Simple validation: Ensure current step is somewhat valid before jumping (Optional)
        // For now, allow free movement
        const targetStep = $(this).data('step');
        
        $steps.removeClass('active');
        $(this).addClass('active');

        $panels.removeClass('active');
        $('#step-' + targetStep).addClass('active');
    });

    // 2. Conditional Fields for Location
    $('input[name="location_type"]').on('change', function() {
        const val = $(this).val();
        if (val === 'venue') {
            $('#venue-fields').slideDown();
            $('#virtual-fields').slideUp();
        } else if (val === 'virtual') {
            $('#venue-fields').slideUp();
            $('#virtual-fields').slideDown();
        } else {
            // Hybrid
            $('#venue-fields').slideDown();
            $('#virtual-fields').slideDown();
        }
    });

    // 3. Media Uploader for Banner
    let mediaUploader;
    $('#ke-banner-uploader .ke-btn').on('click', function(e) {
        e.preventDefault();
        
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        
        mediaUploader = wp.media.frames.file_frame = wp.media({
            title: 'Choose Event Banner',
            button: { text: 'Choose Image' },
            multiple: false
        });

        mediaUploader.on('select', function() {
            const attachment = mediaUploader.state().get('selection').first().toJSON();
            $('#event_banner_id').val(attachment.id);
            
            const preview = $('#ke-banner-uploader .uploader-preview');
            let img = preview.find('img');
            if (img.length === 0) {
                img = $('<img>').appendTo(preview);
            }
            img.attr('src', attachment.url).show();
            $('#ke-banner-uploader .ke-btn').text('Change Image');
        });

        mediaUploader.open();
    });

    // 4. Dynamic Ticket Types
    let ticketIndex = 0;
    const $ticketContainer = $('#ke-ticket-types-container');
    const $addTicketBtn = $('#ke-add-ticket-type');

    $addTicketBtn.on('click', function() {
        const $empty = $ticketContainer.find('.ke-empty-state');
        if ($empty.length) $empty.remove();

        const html = `
            <div class="ke-ticket-card">
                <button type="button" class="btn-remove-ticket" title="Remove Ticket">&times;</button>
                <div class="form-group">
                    <label>Ticket Name</label>
                    <input type="text" name="tickets[${ticketIndex}][name]" placeholder="e.g. General Admission" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="tickets[${ticketIndex}][desc]" placeholder="e.g. Includes 2 drinks (optional)">
                </div>
                <div class="form-group">
                    <label>Price</label>
                    <input type="number" step="0.01" name="tickets[${ticketIndex}][price]" placeholder="0.00 (Free)">
                </div>
                <div class="form-group">
                    <label>Quantity Available</label>
                    <input type="number" name="tickets[${ticketIndex}][qty]" placeholder="Unlimited">
                </div>
            </div>
        `;
        $ticketContainer.append(html);
        ticketIndex++;
    });

    $ticketContainer.on('click', '.btn-remove-ticket', function() {
        $(this).closest('.ke-ticket-card').fadeOut(300, function() { 
            $(this).remove(); 
            if ($ticketContainer.children().length === 0) {
                $ticketContainer.append('<div class="ke-empty-state">No tickets added yet.</div>');
            }
        });
    });

    // Add a default ticket if none exist
    if ($ticketContainer.children('.ke-ticket-card').length === 0) {
        $addTicketBtn.click();
    }

    // 5. Form Submission (Save via REST API)
    $('#ke-builder-publish').on('click', function(e) {
        e.preventDefault();
        saveEvent('publish');
    });

    $('#ke-builder-save-draft').on('click', function(e) {
        e.preventDefault();
        saveEvent('draft');
    });

    function saveEvent(status) {
        const formData = new FormData(document.getElementById('ke-event-builder-form'));
        const eventData = Object.fromEntries(formData.entries());
        
        // Handle tinymce description if initialized
        if (typeof tinymce !== 'undefined' && tinymce.get('event_description')) {
            eventData.event_description = tinymce.get('event_description').getContent();
        }

        // Build structured tickets array
        eventData.tickets = [];
        $ticketContainer.find('.ke-ticket-card').each(function() {
            const nameField = $(this).find('input[name*="[name]"]');
            const descField = $(this).find('input[name*="[desc]"]');
            const priceField = $(this).find('input[name*="[price]"]');
            const qtyField = $(this).find('input[name*="[qty]"]');

            const name = nameField.length ? nameField.val().trim() : '';
            const desc = descField.length ? descField.val().trim() : '';
            let price = priceField.length ? priceField.val().trim() : '0';
            let qty = qtyField.length ? qtyField.val().trim() : '0';

            if (price === '') price = '0';
            if (qty === '') qty = '0';

            if (name) {
                eventData.tickets.push({ name, desc, price: parseFloat(price), qty: parseInt(qty, 10) });
            }
        });
        
        // Get categories (array of checkboxes)
        const categories = [];
        $('input[name="event_categories[]"]:checked').each(function() {
            categories.push($(this).val());
        });
        eventData.event_categories = categories;

        eventData.post_status = status;

        const btn = status === 'publish' ? $('#ke-builder-publish') : $('#ke-builder-save-draft');
        const origText = btn.text();
        btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: keBuilderData.restUrl + 'events/save',
            type: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', keBuilderData.nonce);
            },
            data: JSON.stringify(eventData),
            contentType: 'application/json',
            success: function(response) {
                btn.prop('disabled', false).text(origText);

                if (response.event_id && !$('#ke-event-builder-form input[name="event_id"]').val()) {
                    $('#ke-event-builder-form input[name="event_id"]').val(response.event_id);
                    $('.ke-builder-title h1').text('Edit Event');
                    window.history.replaceState({}, '', window.location.href + '&event_id=' + response.event_id);
                }

                if (status === 'publish') {
                    showSuccessPopup(response.event_id, response.permalink);
                } else {
                    alert('Draft saved successfully!');
                }
            },
            error: function(err) {
                console.error(err);
                alert('Error saving event: ' + (err.responseJSON ? err.responseJSON.message : 'Unknown error'));
                btn.prop('disabled', false).text(origText);
            }
        });
    }

    function showSuccessPopup(eventId, permalink) {
        var dashboardUrl = '/wp-admin/admin.php?page=ke-events-list&created=1';
        var popup = document.createElement('div');
        popup.innerHTML =
            '<div style="position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99999;display:flex;align-items:center;justify-content:center;font-family:\'Inter\',sans-serif;">' +
                '<div style="background:#fff;border-radius:20px;padding:40px;max-width:400px;width:90%;text-align:center;box-shadow:0 24px 64px rgba(0,0,0,0.2);">' +
                    '<div style="font-size:48px;margin-bottom:16px;">🎉</div>' +
                    '<h2 style="font-size:22px;font-weight:800;color:#09090b;margin:0 0 8px;">¡Evento publicado!</h2>' +
                    '<p style="color:#71717a;font-size:14px;margin:0 0 28px;">Tu evento está listo y visible para el público.</p>' +
                    '<div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">' +
                        (permalink
                            ? '<a href="' + permalink + '" target="_blank" style="padding:12px 20px;border:1.5px solid #e4e4e7;border-radius:100px;color:#09090b;text-decoration:none;font-size:14px;font-weight:600;">👁️ Preview Event</a>'
                            : '') +
                        '<a href="' + dashboardUrl + '" style="padding:12px 20px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:100px;color:#fff;text-decoration:none;font-size:14px;font-weight:600;">📊 Go to Dashboard</a>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(popup);
    }
});
