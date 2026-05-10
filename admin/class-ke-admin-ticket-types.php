<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ticket Types admin management — meta box on event edit screen
 */
class KE_Admin_Ticket_Types {

    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
        add_action( 'save_post_ke_event', array( $this, 'save_ticket_types' ), 20, 2 );
    }

    /**
     * Add the ticket types meta box
     */
    public function add_meta_box() {
        add_meta_box(
            'ke_ticket_types',
            '🎟️ Tickets',
            array( $this, 'render_meta_box' ),
            'ke_event',
            'normal',
            'high'
        );
    }

    /**
     * Render the ticket types meta box
     */
    public function render_meta_box( $post ) {
        wp_nonce_field( 'ke_ticket_types_nonce', 'ke_tt_nonce' );

        $ticket_types = new KE_Ticket_Types();
        $types = $ticket_types->get_by_event( $post->ID );
        ?>
        <style>
            .ke-tickets-list { margin-top: 8px; }
            .ke-ticket-item {
                background: #f9fafb;
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                padding: 20px;
                margin-bottom: 12px;
                position: relative;
                transition: border-color 0.2s;
            }
            .ke-ticket-item:hover { border-color: #84cc16; }
            .ke-ticket-item-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 16px;
                padding-bottom: 12px;
                border-bottom: 1px solid #e5e7eb;
            }
            .ke-ticket-item-title {
                font-size: 14px;
                font-weight: 700;
                color: #1f2937;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .ke-ticket-item-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 10px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .ke-badge-free-type { background: #ecfccb; color: #365314; }
            .ke-badge-paid-type { background: #dbeafe; color: #1e3a5f; }
            .ke-ticket-remove { color: #9ca3af; cursor: pointer; font-size: 18px; text-decoration: none; padding: 4px 8px; border-radius: 4px; transition: all 0.2s; }
            .ke-ticket-remove:hover { color: #ef4444; background: #fef2f2; }
            .ke-ticket-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
            .ke-ticket-grid-2 { grid-template-columns: 1fr 1fr; }
            .ke-ticket-field { }
            .ke-ticket-field label { display: block; font-size: 11px; font-weight: 700; color: #6b7280; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; }
            .ke-ticket-field input,
            .ke-ticket-field select,
            .ke-ticket-field textarea { width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; background: #fff; }
            .ke-ticket-field input:focus,
            .ke-ticket-field select:focus,
            .ke-ticket-field textarea:focus { border-color: #84cc16; box-shadow: 0 0 0 2px rgba(132, 204, 22, 0.15); outline: none; }
            .ke-ticket-field textarea { resize: vertical; min-height: 50px; }
            .ke-ticket-field-full { grid-column: 1 / -1; }
            .ke-ticket-sold-badge { background: #f3f4f6; padding: 2px 8px; border-radius: 10px; font-size: 11px; color: #6b7280; font-weight: 600; }
            .ke-ticket-section-label { grid-column: 1 / -1; font-size: 11px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.8px; margin-top: 4px; padding-top: 8px; border-top: 1px dashed #e5e7eb; }
            .ke-add-ticket-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 10px 20px;
                background: #84cc16;
                color: #fff;
                border: none;
                border-radius: 8px;
                font-size: 13px;
                font-weight: 700;
                cursor: pointer;
                transition: all 0.2s;
            }
            .ke-add-ticket-btn:hover { background: #65a30d; transform: translateY(-1px); }
            .ke-ticket-type-toggle { display: flex; gap: 0; margin-bottom: 12px; }
            .ke-type-tab { padding: 6px 16px; border: 1px solid #d1d5db; font-size: 12px; font-weight: 600; cursor: pointer; color: #6b7280; background: #fff; transition: all 0.2s; }
            .ke-type-tab:first-child { border-radius: 6px 0 0 6px; }
            .ke-type-tab:last-child { border-radius: 0 6px 6px 0; border-left: none; }
            .ke-type-tab.active { background: #84cc16; color: #fff; border-color: #84cc16; }
            .ke-price-field { display: none; }
            .ke-price-field.visible { display: block; }
        </style>

        <div class="ke-tickets-list" id="ke-tickets-list">
            <?php if ( ! empty( $types ) ) : ?>
                <?php foreach ( $types as $i => $type ) : ?>
                    <?php $this->render_ticket_row( $i, $type ); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div style="margin-top: 12px;">
            <button type="button" class="ke-add-ticket-btn" id="ke-add-ticket-type">+ Add Ticket</button>
        </div>

        <script>
        (function() {
            var index = <?php echo count( $types ); ?>;
            var list = document.getElementById('ke-tickets-list');

            document.getElementById('ke-add-ticket-type').addEventListener('click', function() {
                var div = document.createElement('div');
                div.className = 'ke-ticket-item';
                div.innerHTML = getTicketTemplate(index, { ticket_type: 'free', capacity_type: 'limited', status: 'active', min_per_order: 1, max_per_order: 10, show_remaining: 'yes', quantity_total: 100 });
                list.appendChild(div);
                initTicketItem(div, index);
                index++;
            });

            // Initialize existing items
            document.querySelectorAll('.ke-ticket-item').forEach(function(item, idx) {
                initTicketItem(item, idx);
            });

            function initTicketItem(item, idx) {
                // Type toggle
                item.querySelectorAll('.ke-type-tab').forEach(function(tab) {
                    tab.addEventListener('click', function() {
                        item.querySelectorAll('.ke-type-tab').forEach(function(t) { t.classList.remove('active'); });
                        tab.classList.add('active');
                        var type = tab.dataset.type;
                        item.querySelector('.ke-tt-type-input').value = type;
                        var priceField = item.querySelector('.ke-price-field');
                        if (type === 'paid') {
                            priceField.classList.add('visible');
                        } else {
                            priceField.classList.remove('visible');
                            item.querySelector('.ke-tt-price-input').value = '0.00';
                        }
                        // Update badge
                        var badge = item.querySelector('.ke-ticket-item-badge');
                        if (badge) {
                            badge.className = 'ke-ticket-item-badge ' + (type === 'paid' ? 'ke-badge-paid-type' : 'ke-badge-free-type');
                            badge.textContent = type === 'paid' ? 'PAID' : 'FREE';
                        }
                    });
                });

                // Capacity toggle
                var capSelect = item.querySelector('.ke-tt-capacity-select');
                if (capSelect) {
                    capSelect.addEventListener('change', function() {
                        var qtyField = item.querySelector('.ke-tt-qty-field');
                        if (this.value === 'unlimited') {
                            qtyField.style.display = 'none';
                        } else {
                            qtyField.style.display = 'block';
                        }
                    });
                    // Trigger on load
                    capSelect.dispatchEvent(new Event('change'));
                }
            }

            function getTicketTemplate(idx, defaults) {
                return '<div class="ke-ticket-item-header">'
                    + '<span class="ke-ticket-item-title"><span class="ke-ticket-item-badge ke-badge-free-type">FREE</span> New Ticket</span>'
                    + '<a href="#" class="ke-ticket-remove" title="Remove" onclick="this.closest(\'.ke-ticket-item\').remove(); return false;">✕</a>'
                    + '</div>'
                    + '<input type="hidden" name="ke_tt[' + idx + '][id]" value="0">'
                    + '<input type="hidden" name="ke_tt[' + idx + '][ticket_type]" value="free" class="ke-tt-type-input">'
                    + '<div class="ke-ticket-type-toggle">'
                    + '<div class="ke-type-tab active" data-type="free">🎁 Free</div>'
                    + '<div class="ke-type-tab" data-type="paid">💰 Paid</div>'
                    + '</div>'
                    + '<div class="ke-ticket-grid">'
                    + '<div class="ke-ticket-field ke-ticket-field-full"><label>Ticket Name *</label><input type="text" name="ke_tt[' + idx + '][name]" placeholder="e.g. General Admission, VIP, Early Bird" required></div>'
                    + '<div class="ke-ticket-field ke-ticket-field-full ke-price-field"><label>Price ($)</label><input type="number" name="ke_tt[' + idx + '][price]" value="0.00" step="0.01" min="0" class="ke-tt-price-input"></div>'
                    + '<div class="ke-ticket-field ke-ticket-field-full"><label>Description</label><textarea name="ke_tt[' + idx + '][description]" placeholder="Brief description of what this ticket includes..."></textarea></div>'
                    + '<div class="ke-ticket-section-label">Capacity & Limits</div>'
                    + '<div class="ke-ticket-field"><label>Capacity</label><select name="ke_tt[' + idx + '][capacity_type]" class="ke-tt-capacity-select"><option value="limited">Limited</option><option value="unlimited">Unlimited</option></select></div>'
                    + '<div class="ke-ticket-field ke-tt-qty-field"><label>Number of Tickets</label><input type="number" name="ke_tt[' + idx + '][quantity_total]" value="100" min="1"></div>'
                    + '<div class="ke-ticket-field"><label>Show Available</label><select name="ke_tt[' + idx + '][show_remaining]"><option value="yes">Yes</option><option value="no">No</option></select></div>'
                    + '<div class="ke-ticket-section-label">Purchase Limits</div>'
                    + '<div class="ke-ticket-field"><label>Min Per Order</label><input type="number" name="ke_tt[' + idx + '][min_per_order]" value="1" min="1"></div>'
                    + '<div class="ke-ticket-field"><label>Max Per Order</label><input type="number" name="ke_tt[' + idx + '][max_per_order]" value="10" min="1"></div>'
                    + '<div class="ke-ticket-field"><label>Status</label><select name="ke_tt[' + idx + '][status]"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>'
                    + '<div class="ke-ticket-section-label">Custom Fields</div>'
                    + '<div class="ke-ticket-field-full" style="grid-column:1/-1;">'
                    + '<p style="font-size:12px;color:#6b7280;margin:0 0 8px;">Ask attendees for extra information (e.g. T-shirt size, dietary requirements).</p>'
                    + '<div class="ke-cf-list" data-index="' + idx + '"></div>'
                    + '<button type="button" class="ke-cf-add-btn" data-index="' + idx + '" style="margin-top:6px;padding:5px 12px;font-size:12px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;">+ Add Field</button>'
                    + '<textarea name="ke_tt[' + idx + '][custom_fields]" class="ke-cf-textarea" style="display:none;"></textarea>'
                    + '</div>'
                    + '</div>';
            }
            // ── Custom fields builder ──────────────────────────────
            function getCfRowHtml(cf) {
                cf = cf || {};
                var isSelect = cf.type === 'select';
                return '<div class="ke-cf-row" style="display:flex;gap:6px;align-items:center;margin-bottom:6px;flex-wrap:wrap;">'
                    + '<input type="text" class="ke-cf-label" placeholder="Field label" value="' + (cf.label || '').replace(/"/g,'&quot;') + '" style="flex:2;min-width:120px;padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:12px;">'
                    + '<select class="ke-cf-type" style="flex:1;min-width:90px;padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:12px;">'
                    + '<option value="text"'     + ((!cf.type||cf.type==='text')?' selected':'')     + '>Text</option>'
                    + '<option value="select"'   + (cf.type==='select'?' selected':'')               + '>Select</option>'
                    + '<option value="textarea"' + (cf.type==='textarea'?' selected':'')             + '>Textarea</option>'
                    + '</select>'
                    + '<label style="display:flex;align-items:center;gap:3px;font-size:12px;white-space:nowrap;cursor:pointer;">'
                    + '<input type="checkbox" class="ke-cf-required"' + (cf.required?' checked':'') + '> Required</label>'
                    + '<input type="text" class="ke-cf-options" placeholder="Options (comma-sep)" value="' + ((cf.options||[]).join(', ')).replace(/"/g,'&quot;') + '" style="flex:2;min-width:120px;padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:12px;' + (!isSelect?'display:none;':'') + '">'
                    + '<button type="button" class="ke-cf-remove" style="padding:4px 8px;background:#fef2f2;border:1px solid #fecaca;border-radius:5px;color:#ef4444;cursor:pointer;font-size:12px;" title="Remove">✕</button>'
                    + '</div>';
            }

            function initCfBuilder(item) {
                var cfList    = item.querySelector('.ke-cf-list');
                var cfAddBtn  = item.querySelector('.ke-cf-add-btn');
                var cfTextarea = item.querySelector('.ke-cf-textarea');
                if (!cfList || !cfAddBtn || !cfTextarea) return;

                // Render existing fields from stored JSON
                var existing = [];
                try { existing = JSON.parse(cfTextarea.value || '[]'); } catch(e) {}
                existing.forEach(function(cf) {
                    var row = document.createElement('div');
                    row.innerHTML = getCfRowHtml(cf);
                    cfList.appendChild(row.firstChild);
                });
                initCfRows(cfList);

                cfAddBtn.addEventListener('click', function() {
                    var row = document.createElement('div');
                    row.innerHTML = getCfRowHtml();
                    var cfRow = row.firstChild;
                    cfList.appendChild(cfRow);
                    initCfRow(cfRow);
                });
            }

            function initCfRows(cfList) {
                cfList.querySelectorAll('.ke-cf-row').forEach(initCfRow);
            }

            function initCfRow(row) {
                var typeSelect = row.querySelector('.ke-cf-type');
                var optionsInput = row.querySelector('.ke-cf-options');
                if (typeSelect && optionsInput) {
                    typeSelect.addEventListener('change', function() {
                        optionsInput.style.display = this.value === 'select' ? '' : 'none';
                    });
                }
                var removeBtn = row.querySelector('.ke-cf-remove');
                if (removeBtn) {
                    removeBtn.addEventListener('click', function() { row.remove(); });
                }
            }

            // Initialize custom fields for all existing ticket items
            document.querySelectorAll('.ke-ticket-item').forEach(initCfBuilder);

            // Override initTicketItem to also init custom fields for new items
            var _origInit = initTicketItem;
            // (wrap after the fact is not clean — instead hook into the add-ticket-type click)
            document.getElementById('ke-add-ticket-type').addEventListener('click', function() {
                // The new item was just appended by the earlier handler; find the last one
                var items = list.querySelectorAll('.ke-ticket-item');
                if (items.length) initCfBuilder(items[items.length - 1]);
            });

            // On form submit, serialize each ticket's custom fields table → hidden textarea
            var postForm = document.getElementById('post');
            if (postForm) {
                postForm.addEventListener('submit', function() {
                    document.querySelectorAll('.ke-ticket-item').forEach(function(item) {
                        var cfList    = item.querySelector('.ke-cf-list');
                        var cfTextarea = item.querySelector('.ke-cf-textarea');
                        if (!cfList || !cfTextarea) return;

                        var fields = [];
                        cfList.querySelectorAll('.ke-cf-row').forEach(function(row) {
                            var label = (row.querySelector('.ke-cf-label').value || '').trim();
                            if (!label) return;
                            var type     = row.querySelector('.ke-cf-type').value;
                            var required = row.querySelector('.ke-cf-required').checked;
                            var id       = label.toLowerCase().replace(/[^a-z0-9]+/g, '_');
                            var field    = { id: id, label: label, type: type, required: required };
                            if (type === 'select') {
                                field.options = (row.querySelector('.ke-cf-options').value || '')
                                    .split(',').map(function(s){ return s.trim(); }).filter(Boolean);
                            }
                            fields.push(field);
                        });
                        cfTextarea.value = JSON.stringify(fields);
                    });
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * Render a single ticket type row
     */
    private function render_ticket_row( $index, $type ) {
        $is_paid = ( $type->ticket_type ?? 'free' ) === 'paid' || $type->price > 0;
        $capacity_type = $type->capacity_type ?? 'limited';
        $show_remaining = $type->show_remaining ?? 'yes';
        $min_per = $type->min_per_order ?? 1;
        $max_per = $type->max_per_order ?? 10;
        $description = $type->description ?? '';
        ?>
        <div class="ke-ticket-item">
            <div class="ke-ticket-item-header">
                <span class="ke-ticket-item-title">
                    <span class="ke-ticket-item-badge <?php echo $is_paid ? 'ke-badge-paid-type' : 'ke-badge-free-type'; ?>">
                        <?php echo $is_paid ? 'PAID' : 'FREE'; ?>
                    </span>
                    <?php echo esc_html( $type->name ); ?>
                    <span class="ke-ticket-sold-badge"><?php echo intval( $type->quantity_sold ); ?> sold</span>
                </span>
                <a href="#" class="ke-ticket-remove" title="Remove" onclick="this.closest('.ke-ticket-item').remove(); return false;">✕</a>
            </div>

            <input type="hidden" name="ke_tt[<?php echo $index; ?>][id]" value="<?php echo esc_attr( $type->id ); ?>">
            <input type="hidden" name="ke_tt[<?php echo $index; ?>][ticket_type]" value="<?php echo $is_paid ? 'paid' : 'free'; ?>" class="ke-tt-type-input">

            <!-- Free / Paid Toggle -->
            <div class="ke-ticket-type-toggle">
                <div class="ke-type-tab <?php echo ! $is_paid ? 'active' : ''; ?>" data-type="free">🎁 Free</div>
                <div class="ke-type-tab <?php echo $is_paid ? 'active' : ''; ?>" data-type="paid">💰 Paid</div>
            </div>

            <div class="ke-ticket-grid">
                <!-- Name -->
                <div class="ke-ticket-field ke-ticket-field-full">
                    <label>Ticket Name *</label>
                    <input type="text" name="ke_tt[<?php echo $index; ?>][name]" value="<?php echo esc_attr( $type->name ); ?>" required>
                </div>

                <!-- Price (shown when paid) -->
                <div class="ke-ticket-field ke-ticket-field-full ke-price-field <?php echo $is_paid ? 'visible' : ''; ?>">
                    <label>Price ($)</label>
                    <input type="number" name="ke_tt[<?php echo $index; ?>][price]" value="<?php echo esc_attr( $type->price ); ?>" step="0.01" min="0" class="ke-tt-price-input">
                </div>

                <!-- Description -->
                <div class="ke-ticket-field ke-ticket-field-full">
                    <label>Description</label>
                    <textarea name="ke_tt[<?php echo $index; ?>][description]" placeholder="Brief description of what this ticket includes..."><?php echo esc_textarea( $description ); ?></textarea>
                </div>

                <div class="ke-ticket-section-label">Capacity & Limits</div>

                <!-- Capacity Type -->
                <div class="ke-ticket-field">
                    <label>Capacity</label>
                    <select name="ke_tt[<?php echo $index; ?>][capacity_type]" class="ke-tt-capacity-select">
                        <option value="limited" <?php selected( $capacity_type, 'limited' ); ?>>Limited</option>
                        <option value="unlimited" <?php selected( $capacity_type, 'unlimited' ); ?>>Unlimited</option>
                    </select>
                </div>

                <!-- Quantity (hidden when unlimited) -->
                <div class="ke-ticket-field ke-tt-qty-field" <?php echo $capacity_type === 'unlimited' ? 'style="display:none;"' : ''; ?>>
                    <label>Number of Tickets</label>
                    <input type="number" name="ke_tt[<?php echo $index; ?>][quantity_total]" value="<?php echo esc_attr( $type->quantity_total ); ?>" min="1">
                </div>

                <!-- Show Remaining -->
                <div class="ke-ticket-field">
                    <label>Show Available</label>
                    <select name="ke_tt[<?php echo $index; ?>][show_remaining]">
                        <option value="yes" <?php selected( $show_remaining, 'yes' ); ?>>Yes</option>
                        <option value="no" <?php selected( $show_remaining, 'no' ); ?>>No</option>
                    </select>
                </div>

                <div class="ke-ticket-section-label">Purchase Limits</div>

                <!-- Min/Max Per Order -->
                <div class="ke-ticket-field">
                    <label>Min Per Order</label>
                    <input type="number" name="ke_tt[<?php echo $index; ?>][min_per_order]" value="<?php echo esc_attr( $min_per ); ?>" min="1">
                </div>
                <div class="ke-ticket-field">
                    <label>Max Per Order</label>
                    <input type="number" name="ke_tt[<?php echo $index; ?>][max_per_order]" value="<?php echo esc_attr( $max_per ); ?>" min="1">
                </div>

                <!-- Status -->
                <div class="ke-ticket-field">
                    <label>Status</label>
                    <select name="ke_tt[<?php echo $index; ?>][status]">
                        <option value="active" <?php selected( $type->status, 'active' ); ?>>Active</option>
                        <option value="inactive" <?php selected( $type->status, 'inactive' ); ?>>Inactive</option>
                    </select>
                </div>

                <div class="ke-ticket-section-label">Custom Fields</div>

                <!-- Custom Fields Builder -->
                <div class="ke-ticket-field-full" style="grid-column: 1 / -1;">
                    <p style="font-size:12px;color:#6b7280;margin:0 0 8px;">Ask attendees for extra information (e.g. T-shirt size, dietary requirements).</p>
                    <div class="ke-cf-list" data-index="<?php echo $index; ?>"></div>
                    <button type="button" class="ke-cf-add-btn" data-index="<?php echo $index; ?>" style="margin-top:6px;padding:5px 12px;font-size:12px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;">+ Add Field</button>
                    <!-- Holds serialized JSON on submit -->
                    <textarea name="ke_tt[<?php echo $index; ?>][custom_fields]" class="ke-cf-textarea" style="display:none;"><?php echo esc_textarea( $type->custom_fields ?? '' ); ?></textarea>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Save ticket types when event is saved
     */
    public function save_ticket_types( $post_id, $post ) {
        if ( ! isset( $_POST['ke_tt_nonce'] ) || ! wp_verify_nonce( $_POST['ke_tt_nonce'], 'ke_ticket_types_nonce' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $ticket_types = new KE_Ticket_Types();
        $existing     = $ticket_types->get_by_event( $post_id );
        $existing_ids = array_map( function($t) { return $t->id; }, $existing );

        $submitted = isset( $_POST['ke_tt'] ) ? $_POST['ke_tt'] : array();
        $submitted_ids = array();

        foreach ( $submitted as $tt ) {
            $ticket_type_val = sanitize_text_field( $tt['ticket_type'] ?? 'free' );
            $price = $ticket_type_val === 'free' ? 0.00 : floatval( $tt['price'] ?? 0 );

            // Validate and normalize the custom_fields JSON
            $cf_raw     = trim( wp_unslash( $tt['custom_fields'] ?? '' ) );
            $cf_decoded = $cf_raw !== '' ? json_decode( $cf_raw, true ) : array();
            $cf_clean   = ( json_last_error() === JSON_ERROR_NONE && is_array( $cf_decoded ) )
                          ? wp_json_encode( $cf_decoded )
                          : '[]';

            $data = array(
                'event_id'       => $post_id,
                'name'           => sanitize_text_field( $tt['name'] ?? '' ),
                'description'    => sanitize_textarea_field( $tt['description'] ?? '' ),
                'ticket_type'    => $ticket_type_val,
                'price'          => $price,
                'capacity_type'  => sanitize_text_field( $tt['capacity_type'] ?? 'limited' ),
                'quantity_total' => absint( $tt['quantity_total'] ?? 0 ),
                'min_per_order'  => max( 1, absint( $tt['min_per_order'] ?? 1 ) ),
                'max_per_order'  => max( 1, absint( $tt['max_per_order'] ?? 10 ) ),
                'show_remaining' => sanitize_text_field( $tt['show_remaining'] ?? 'yes' ),
                'status'         => sanitize_text_field( $tt['status'] ?? 'active' ),
                'custom_fields'  => $cf_clean,
            );

            if ( empty( $data['name'] ) ) {
                continue;
            }

            // Set unlimited capacity
            if ( $data['capacity_type'] === 'unlimited' ) {
                $data['quantity_total'] = 999999;
            }

            $id = absint( $tt['id'] ?? 0 );

            if ( $id > 0 ) {
                $ticket_types->update( $id, $data );
                $submitted_ids[] = $id;
            } else {
                $new_id = $ticket_types->create( $data );
                if ( ! is_wp_error( $new_id ) ) {
                    $submitted_ids[] = $new_id;
                }
            }
        }

        // Delete removed ticket types
        $to_delete = array_diff( $existing_ids, $submitted_ids );
        foreach ( $to_delete as $del_id ) {
            $ticket_types->delete( $del_id );
        }
    }
}
