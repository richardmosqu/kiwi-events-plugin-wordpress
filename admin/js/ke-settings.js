/**
 * KiwiEvents — Settings JS
 * Handles UI color pickers and Service Fee CRUD
 */
jQuery(document).ready(function($) {
    'use strict';

    const API = keSettings.restUrl;
    const NONCE = keSettings.nonce;

    // ─── State ─────────────────────────────────────────────
    let fees = [];

    // ─── Boot ──────────────────────────────────────────────
    loadSettings();

    // ══════════════════════════════════════════════════════
    // UI COLOR PICKERS
    // ══════════════════════════════════════════════════════

    function syncAccentUI(hex) {
        if (!isValidHex(hex)) return;
        $('#ke-accent-swatch').css('background', hex);
        $('#ke-preview-btn').css({ background: hex, 'box-shadow': '0 4px 16px ' + hex + '59' });
        $('#ke-preview-pill').css({ background: hex + '14', color: hex, 'border-color': hex + '33' });
    }

    function syncSubtitleUI(hex) {
        if (!isValidHex(hex)) return;
        $('#ke-subtitle-swatch').css('background', hex);
        $('#ke-preview-text').css('color', hex);
    }

    $('#ke-accent-color').on('input', function() {
        const hex = $(this).val();
        $('#ke-accent-hex').val(hex);
        syncAccentUI(hex);
    });

    $('#ke-accent-hex').on('input', function() {
        let hex = $(this).val().trim();
        if (!hex.startsWith('#')) hex = '#' + hex;
        if (isValidHex(hex)) {
            $('#ke-accent-color').val(hex);
            syncAccentUI(hex);
        }
    });

    $('#ke-subtitle-color').on('input', function() {
        const hex = $(this).val();
        $('#ke-subtitle-hex').val(hex);
        syncSubtitleUI(hex);
    });

    $('#ke-subtitle-hex').on('input', function() {
        let hex = $(this).val().trim();
        if (!hex.startsWith('#')) hex = '#' + hex;
        if (isValidHex(hex)) {
            $('#ke-subtitle-color').val(hex);
            syncSubtitleUI(hex);
        }
    });

    $('#ke-save-ui-btn').on('click', function() {
        const $btn = $(this);
        const accent = $('#ke-accent-color').val();
        const subtitle = $('#ke-subtitle-color').val();

        $btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: API + 'settings/ui',
            method: 'POST',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', NONCE); },
            data: JSON.stringify({ accent_color: accent, subtitle_color: subtitle }),
            contentType: 'application/json',
            success: function() {
                showMsg('#ke-ui-msg', 'Colors saved. Reload any event page to see changes.', 'success');
            },
            error: function() {
                showMsg('#ke-ui-msg', 'Could not save colors. Please try again.', 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Save Colors');
            }
        });
    });

    // ══════════════════════════════════════════════════════
    // SERVICE FEES — Load
    // ══════════════════════════════════════════════════════

    function loadSettings() {
        $.ajax({
            url: API + 'settings',
            method: 'GET',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', NONCE); },
            success: function(data) {
                fees = data.fees || [];
                renderFeeList();
                
                if (data.notifications) {
                    $('#ke-admin-email-enabled').prop('checked', data.notifications.admin_email_enabled !== false);
                    if (data.notifications.global_bcc) {
                        $('#ke-global-bcc').val(data.notifications.global_bcc);
                    }
                }

                if (data.access) {
                    $('#ke-require-login').prop('checked', !!data.access.require_login);
                    if (typeof data.access.login_url === 'string')       $('#ke-login-url').val(data.access.login_url);
                    if (typeof data.access.register_url === 'string')    $('#ke-register-url').val(data.access.register_url);
                    if (typeof data.access.login_required_message === 'string' && data.access.login_required_message) {
                        $('#ke-login-required-message').val(data.access.login_required_message);
                    }
                }
            }
        });
    }

    function renderFeeList() {
        const $list = $('#ke-fees-list');

        // Remove existing cards (but not the empty message)
        $list.find('.ke-fee-card').remove();

        if (fees.length === 0) {
            $('#ke-fees-empty').show();
            return;
        }

        $('#ke-fees-empty').hide();

        fees.forEach(function(fee) {
            const formula = fee.type === 'formula'
                ? '(price × ' + parseFloat(fee.percentage || 0).toFixed(2) + '%) + $' + parseFloat(fee.fixed_amount || 0).toFixed(2)
                : '$' + parseFloat(fee.fixed_amount || 0).toFixed(2) + ' per ticket';

            const typeBadge = fee.type === 'formula'
                ? '<span class="ke-fee-badge ke-fee-badge-formula">Formula</span>'
                : '<span class="ke-fee-badge ke-fee-badge-fixed">Fixed</span>';

            const card = `
                <div class="ke-fee-card" data-id="${escHtml(fee.id)}">
                    <div class="ke-fee-card-left">
                        ${typeBadge}
                        <span class="ke-fee-card-name">${escHtml(fee.name)}</span>
                        <span class="ke-fee-card-formula">${escHtml(formula)}</span>
                    </div>
                    <div class="ke-fee-card-actions">
                        <button type="button" class="ke-action-btn ke-action-btn-neutral ke-edit-fee" data-id="${escHtml(fee.id)}">Edit</button>
                        <button type="button" class="ke-action-btn ke-action-btn-danger ke-delete-fee" data-id="${escHtml(fee.id)}">Delete</button>
                    </div>
                </div>
            `;
            $list.append(card);
        });
    }

    // ══════════════════════════════════════════════════════
    // SERVICE FEES — Form
    // ══════════════════════════════════════════════════════

    // Open empty form
    $('#ke-open-fee-form').on('click', function() {
        resetFeeForm();
        $('#ke-fee-form-wrap').slideDown(200);
        $(this).hide();
    });

    // Cancel
    $('#ke-cancel-fee').on('click', function() {
        $('#ke-fee-form-wrap').slideUp(200);
        $('#ke-open-fee-form').show();
        resetFeeForm();
    });

    // Fee type toggle
    $(document).on('click', '.ke-fee-tab', function() {
        const type = $(this).data('type');
        $('.ke-fee-tab').removeClass('ke-tab-active');
        $(this).addClass('ke-tab-active');
        $('#ke-fee-type').val(type);

        if (type === 'formula') {
            $('#ke-formula-fields').show();
            $('#ke-fixed-only-fields').hide();
        } else {
            $('#ke-formula-fields').hide();
            $('#ke-fixed-only-fields').show();
        }
        updateFormulaPreview();
    });

    // Live formula preview
    $('#ke-fee-pct, #ke-fee-fixed').on('input', updateFormulaPreview);
    $('#ke-fee-fixed-only').on('input', function() {
        const val = parseFloat($(this).val()) || 0;
        $('#ke-fixed-chip').text('$' + val.toFixed(2) + ' per ticket');
    });

    function updateFormulaPreview() {
        const pct = parseFloat($('#ke-fee-pct').val()) || 0;
        const fixed = parseFloat($('#ke-fee-fixed').val()) || 0;
        $('#ke-formula-chip').text(
            '(price × ' + pct.toFixed(2) + '%) + $' + fixed.toFixed(2)
        );
    }

    // Edit fee
    $(document).on('click', '.ke-edit-fee', function() {
        const id = $(this).data('id');
        const fee = fees.find(function(f) { return f.id === id; });
        if (!fee) return;

        resetFeeForm();
        $('#ke-edit-fee-id').val(fee.id);
        $('#ke-fee-name').val(fee.name);
        $('#ke-fee-type').val(fee.type);

        // Set type tabs
        $('.ke-fee-tab').removeClass('ke-tab-active');
        $('.ke-fee-tab[data-type="' + fee.type + '"]').addClass('ke-tab-active');

        if (fee.type === 'formula') {
            $('#ke-formula-fields').show();
            $('#ke-fixed-only-fields').hide();
            $('#ke-fee-pct').val(fee.percentage);
            $('#ke-fee-fixed').val(fee.fixed_amount);
            updateFormulaPreview();
        } else {
            $('#ke-formula-fields').hide();
            $('#ke-fixed-only-fields').show();
            $('#ke-fee-fixed-only').val(fee.fixed_amount);
            $('#ke-fixed-chip').text('$' + parseFloat(fee.fixed_amount || 0).toFixed(2) + ' per ticket');
        }

        $('#ke-fee-form-wrap').slideDown(200);
        $('#ke-open-fee-form').hide();
    });

    // Delete fee
    $(document).on('click', '.ke-delete-fee', function() {
        const id = $(this).data('id');
        const fee = fees.find(function(f) { return f.id === id; });
        if (!fee) return;

        if (!confirm('Delete "' + fee.name + '"? This will not affect events that already used it for checkout.')) return;

        const $card = $(this).closest('.ke-fee-card');
        $card.css('opacity', 0.5);

        $.ajax({
            url: API + 'settings/fees/' + id,
            method: 'DELETE',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', NONCE); },
            success: function() {
                fees = fees.filter(function(f) { return f.id !== id; });
                renderFeeList();
            },
            error: function() {
                $card.css('opacity', 1);
                alert('Could not delete fee. Please try again.');
            }
        });
    });

    // Save fee
    $('#ke-save-fee').on('click', function() {
        const $btn = $(this);
        const name = $('#ke-fee-name').val().trim();
        const type = $('#ke-fee-type').val();
        const editId = $('#ke-edit-fee-id').val();

        if (!name) {
            showMsg('#ke-fee-form-msg', 'Please enter a fee name.', 'error');
            return;
        }

        let percentage = 0;
        let fixed_amount = 0;

        if (type === 'formula') {
            percentage = Math.max(0, parseFloat($('#ke-fee-pct').val()) || 0);
            fixed_amount = Math.max(0, parseFloat($('#ke-fee-fixed').val()) || 0);
        } else {
            fixed_amount = Math.max(0, parseFloat($('#ke-fee-fixed-only').val()) || 0);
        }

        const payload = { name: name, type: type, percentage: percentage, fixed_amount: fixed_amount };
        if (editId) payload.id = editId;

        $btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: API + 'settings/fees',
            method: 'POST',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', NONCE); },
            data: JSON.stringify(payload),
            contentType: 'application/json',
            success: function(resp) {
                // Update local array
                const idx = fees.findIndex(function(f) { return f.id === resp.fee.id; });
                if (idx >= 0) {
                    fees[idx] = resp.fee;
                } else {
                    fees.push(resp.fee);
                }
                renderFeeList();
                $('#ke-fee-form-wrap').slideUp(200);
                $('#ke-open-fee-form').show();
                resetFeeForm();
            },
            error: function(xhr) {
                const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Could not save fee.';
                showMsg('#ke-fee-form-msg', msg, 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Save Fee');
            }
        });
    });

    // ─── Helpers ────────────────────────────────────────────
    function resetFeeForm() {
        $('#ke-edit-fee-id').val('');
        $('#ke-fee-name').val('');
        $('#ke-fee-type').val('formula');
        $('#ke-fee-pct').val('');
        $('#ke-fee-fixed').val('');
        $('#ke-fee-fixed-only').val('');
        $('.ke-fee-tab').removeClass('ke-tab-active');
        $('.ke-fee-tab[data-type="formula"]').addClass('ke-tab-active');
        $('#ke-formula-fields').show();
        $('#ke-fixed-only-fields').hide();
        $('#ke-formula-chip').text('(price × 0%) + $0.00');
        $('#ke-fixed-chip').text('$0.00 per ticket');
        showMsg('#ke-fee-form-msg', '', 'hide');
    }

    function isValidHex(hex) {
        return /^#[0-9A-Fa-f]{6}$/.test(hex);
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showMsg(selector, text, type) {
        const $el = $(selector);
        if (type === 'hide') { $el.hide().text(''); return; }
        $el.removeClass('ke-msg-success ke-msg-error')
           .addClass('ke-msg-' + type)
           .text(text)
           .show();
        if (type === 'success') {
            setTimeout(function() { $el.fadeOut(400); }, 3500);
        }
    }

    // ══════════════════════════════════════════════════════
    // NOTIFICATIONS
    // ══════════════════════════════════════════════════════

    $('#ke-save-notifications-btn').on('click', function() {
        const $btn = $(this);
        const enabled = $('#ke-admin-email-enabled').is(':checked');
        const bcc = $('#ke-global-bcc').val().trim();

        $btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: API + 'settings/notifications',
            method: 'POST',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', NONCE); },
            data: JSON.stringify({ admin_email_enabled: enabled, global_bcc: bcc }),
            contentType: 'application/json',
            success: function(resp) {
                showMsg('#ke-notifications-msg', 'Notification settings saved.', 'success');
            },
            error: function(xhr) {
                showMsg('#ke-notifications-msg', 'Could not save notifications settings.', 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Save Notifications');
            }
        });
    });

    // Delegated so it survives any DOM re-render and binds even if the button is
    // rendered after jQuery(ready) fires. Also preventDefault guards against any
    // parent <form> submitting the page before the fetch completes.
    $(document).on('click', '#ke-save-access-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const $btn = $(this);
        const payload = {
            require_login:           $('#ke-require-login').is(':checked'),
            login_url:               $('#ke-login-url').val().trim(),
            register_url:            $('#ke-register-url').val().trim(),
            login_required_message:  $('#ke-login-required-message').val().trim(),
        };

        console.log('[KE Access] Save clicked', payload);
        $btn.prop('disabled', true).html('<span class="ke-spinner"></span> Saving...');
        showMsg('#ke-access-msg', 'hide');

        $.ajax({
            url:         API + 'settings/access',
            method:      'POST',
            beforeSend:  function(xhr) { xhr.setRequestHeader('X-WP-Nonce', NONCE); },
            data:        JSON.stringify(payload),
            contentType: 'application/json',
            success: function(resp) {
                console.log('[KE Access] Save response', resp);
                showMsg('#ke-access-msg', 'Settings saved.', 'success');
            },
            error: function(xhr, status, err) {
                console.error('[KE Access] Save error', { status: xhr.status, statusText: xhr.statusText, body: xhr.responseJSON || xhr.responseText, ajaxStatus: status, err: err });
                let msg = (xhr.responseJSON && xhr.responseJSON.message)
                    || 'Could not save access control settings.';
                if (xhr.status === 401 || xhr.status === 403) {
                    msg = 'Permission denied (' + xhr.status + '). Your account may be missing the manage_kiwi_events capability.';
                } else if (xhr.status === 404) {
                    msg = 'Endpoint missing (404). Visit Settings → Permalinks and click Save to refresh REST routes.';
                } else if (xhr.status === 0) {
                    msg = 'Network error — request never reached the server.';
                }
                showMsg('#ke-access-msg', msg, 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Save Access Control');
            }
        });
    });

    // ── Board tab save (same delegated pattern as Access Control) ──
    $(document).on('click', '#ke-save-board-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const $btn = $(this);
        const payload = {
            enabled:              $('#ke-board-enabled').is(':checked'),
            max_daily:            parseInt($('#ke-board-max-daily').val(), 10) || 3,
            max_gallery:          parseInt($('#ke-board-max-gallery').val(), 10) || 0,
            max_file_mb:          parseInt($('#ke-board-max-file-mb').val(), 10) || 5,
            trending_threshold:   parseInt($('#ke-board-trending').val(), 10) || 20,
            notify_admin_new:     $('#ke-board-notify-admin').is(':checked'),
            notify_user_received: $('#ke-board-notify-received').is(':checked'),
            notify_user_decision: $('#ke-board-notify-decision').is(':checked'),
        };

        $btn.prop('disabled', true).html('<span class="ke-spinner"></span> Saving...');
        showMsg('#ke-board-msg', 'hide');

        $.ajax({
            url:         API + 'settings/board',
            method:      'POST',
            beforeSend:  function(xhr) { xhr.setRequestHeader('X-WP-Nonce', NONCE); },
            data:        JSON.stringify(payload),
            contentType: 'application/json',
            success: function() {
                showMsg('#ke-board-msg', 'Settings saved.', 'success');
            },
            error: function(xhr) {
                let msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Could not save board settings.';
                if (xhr.status === 401 || xhr.status === 403) {
                    msg = 'Permission denied (' + xhr.status + ').';
                } else if (xhr.status === 404) {
                    msg = 'Endpoint missing (404). Visit Settings → Permalinks and click Save to refresh REST routes.';
                }
                showMsg('#ke-board-msg', msg, 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Save Board Settings');
            }
        });
    });

    $('#ke-test-notification-btn').on('click', function() {
        const $btn = $(this);
        $btn.prop('disabled', true).text('Sending...');

        $.ajax({
            url: API + 'settings/test-notification',
            method: 'POST',
            beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', NONCE); },
            success: function(resp) {
                showMsg('#ke-notifications-msg', 'Test notification sent! Check your inbox.', 'success');
            },
            error: function(xhr) {
                const msg = xhr.responseJSON?.message || 'Could not send test notification.';
                showMsg('#ke-notifications-msg', msg, 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Send Test Notification');
            }
        });
    });

    // Copy the public scanner URL.
    $('#ke-public-scanner-copy').on('click', function() {
        const $btn   = $(this);
        const $input = $('#ke-public-scanner-url');
        const url    = $input.val();
        if (!url) return;
        const restore = () => $btn.text('Copy');
        const flash   = () => { $btn.text('Copied!'); setTimeout(restore, 1200); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(flash, () => {
                $input.trigger('select');
                document.execCommand('copy');
                flash();
            });
        } else {
            $input.trigger('select');
            document.execCommand('copy');
            flash();
        }
    });

});
