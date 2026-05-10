<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Per-event extra fields config — when an event filter is active and the
// event has extras configured, render one column per field. On the All
// Events view we only flag attendees that have extras (no per-column data
// since the field set differs per event).
$xf_cfg     = ( $event_id && class_exists( 'KE_Event_Extra_Fields' ) )
              ? KE_Event_Extra_Fields::get_config( $event_id )
              : array( 'enabled' => false, 'fields' => array() );
$xf_active  = ! empty( $xf_cfg['enabled'] ) && ! empty( $xf_cfg['fields'] );
$xf_columns = $xf_active ? $xf_cfg['fields'] : array();
?>
<div class="wrap ke-wrap">

    <!-- ── Header ── -->
    <div class="ke-page-header">
        <div class="ke-page-header-left">
            <h1>Attendees</h1>
            <p>View and manage event attendees</p>
        </div>
        <div class="ke-header-actions">
            <?php if ( $event_id ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'ke_export_csv', '1' ) ); ?>" class="ke-btn ke-btn-ghost">
                    ↓ Export CSV
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Filters ── -->
    <div class="ke-card ke-filters">
        <form method="get" action="">
            <input type="hidden" name="page" value="kiwi-events-attendees">
            <div class="ke-filter-row">
                <div class="ke-filter-field">
                    <label>Event</label>
                    <select name="event_id" onchange="this.form.submit()">
                        <option value="0">— Select Event —</option>
                        <?php foreach ( $events as $event ) : ?>
                            <option value="<?php echo esc_attr( $event->ID ); ?>" <?php selected( $event_id, $event->ID ); ?>>
                                <?php echo esc_html( $event->post_title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ( $event_id ) : ?>
                    <div class="ke-filter-field">
                        <label>Status</label>
                        <select name="status">
                            <option value="">All Statuses</option>
                            <option value="valid"     <?php selected( $status_filter, 'valid' ); ?>>Valid</option>
                            <option value="used"      <?php selected( $status_filter, 'used' ); ?>>Checked In</option>
                            <option value="cancelled" <?php selected( $status_filter, 'cancelled' ); ?>>Cancelled</option>
                        </select>
                    </div>

                    <?php if ( ! empty( $types ) ) : ?>
                        <div class="ke-filter-field">
                            <label>Ticket Type</label>
                            <select name="ticket_type_id">
                                <option value="0">All Types</option>
                                <?php foreach ( $types as $type ) : ?>
                                    <option value="<?php echo esc_attr( $type->id ); ?>" <?php selected( $type_filter, $type->id ); ?>>
                                        <?php echo esc_html( $type->name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="ke-filter-field">
                        <label>Search</label>
                        <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Name, email, or code…">
                    </div>

                    <div class="ke-filter-field ke-filter-submit">
                        <button type="submit" class="ke-btn ke-btn-primary">Filter</button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=kiwi-events-attendees&event_id=' . $event_id ) ); ?>" class="ke-btn ke-btn-ghost">Reset</a>
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ( ! $event_id ) : ?>
        <div class="ke-card">
            <div class="ke-empty-state">
                <span class="ke-empty-state-icon">👥</span>
                <h3>Select an Event</h3>
                <p>Choose an event above to view its attendees.</p>
            </div>
        </div>

    <?php elseif ( empty( $attendees ) ) : ?>
        <div class="ke-card">
            <div class="ke-empty-state">
                <span class="ke-empty-state-icon">🎟</span>
                <h3>No Attendees Found</h3>
                <p>No attendees match the selected filters.</p>
            </div>
        </div>

    <?php else :
        // Resolve event-level info once — the modal reuses it for every row.
        $event_title    = get_the_title( $event_id );
        $event_date_raw = get_post_meta( $event_id, '_ke_event_date_start', true );
        $event_venue    = get_post_meta( $event_id, '_ke_event_venue', true );
        $event_date_fmt = '';
        if ( $event_date_raw ) {
            try {
                $tz_str = get_post_meta( $event_id, '_ke_event_timezone', true ) ?: wp_timezone_string();
                $dt     = new DateTime( $event_date_raw, new DateTimeZone( $tz_str ) );
                $event_date_fmt = $dt->format( 'D, M j, Y · g:i A' );
            } catch ( Exception $e ) {
                $event_date_fmt = date( 'D, M j, Y · g:i A', strtotime( $event_date_raw ) );
            }
        }
    ?>
        <!-- Stats Strip -->
        <div class="ke-stat-strip">
            <div class="ke-stat-strip-item">
                <div class="ke-stat-strip-label">Total Attendees</div>
                <div class="ke-stat-strip-value"><?php echo intval( $total ); ?></div>
            </div>
        </div>

        <!-- Bulk Actions Bar (shown when rows are selected) -->
        <div class="ke-bulk-bar" id="ke-bulk-bar" hidden>
            <span class="ke-bulk-count"><span id="ke-bulk-count">0</span> selected</span>
            <div class="ke-bulk-actions">
                <button type="button" class="ke-btn ke-btn-ghost" data-bulk="mark_used">Mark as Used</button>
                <button type="button" class="ke-btn ke-btn-ghost" data-bulk="mark_unused">Mark as Unused</button>
                <button type="button" class="ke-btn ke-btn-ghost" data-bulk="resend">Resend Emails</button>
                <button type="button" class="ke-btn ke-btn-danger" data-bulk="delete">Cancel Tickets</button>
            </div>
        </div>

        <!-- Table -->
        <div class="ke-card ke-attendees-card">
            <table class="ke-table ke-attendees-table">
                <thead>
                    <tr>
                        <th class="ke-col-check">
                            <input type="checkbox" class="ke-check-all" aria-label="Select all">
                        </th>
                        <th>#</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Ticket ID</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Checked In</th>
                        <?php foreach ( $xf_columns as $xf ) : ?>
                            <th class="ke-col-extra"><?php echo esc_html( $xf['label'] ); ?></th>
                        <?php endforeach; ?>
                        <th class="ke-col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $attendees as $a ) :
                        // Build a JSON payload on the row so JS can open modals
                        // without fetching again. Everything the View Modal needs.
                        $row_payload = array(
                            'id'               => (int) $a->id,
                            'ticket_code'      => (string) $a->ticket_code,
                            'short_code'       => strtoupper( substr( (string) $a->ticket_code, 0, 8 ) ),
                            'attendee_name'    => (string) $a->attendee_name,
                            'attendee_email'   => (string) $a->attendee_email,
                            'attendee_number'  => (int) $a->attendee_number,
                            'status'           => (string) $a->status,
                            'checked_in_at'    => $a->checked_in_at,
                            'ticket_type_name' => (string) ( $a->ticket_type_name ?? '' ),
                            'ticket_price'     => (float) ( $a->ticket_price ?? 0 ),
                            'order_id'         => (int) $a->order_id,
                            'order_number'     => (string) ( $a->order_number ?? '' ),
                            'payment_method'   => (string) ( $a->payment_method ?? 'free' ),
                            'payment_status'   => (string) ( $a->payment_status ?? '' ),
                            'order_total'      => (float) ( $a->order_total ?? 0 ),
                            'purchase_date'    => (string) $a->created_at,
                            'qr_code_path'     => (string) ( $a->qr_code_path ?? '' ),
                            'event_name'       => $event_title,
                            'event_date'       => $event_date_fmt,
                            'event_venue'      => (string) $event_venue,
                        );
                        $payload_attr = esc_attr( wp_json_encode( $row_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
                        $short_code   = $row_payload['short_code'];
                    ?>
                        <tr data-ticket-id="<?php echo (int) $a->id; ?>" data-ticket='<?php echo $payload_attr; ?>'>
                            <td class="ke-col-check">
                                <input type="checkbox" class="ke-row-check" value="<?php echo (int) $a->id; ?>" aria-label="Select row">
                            </td>
                            <td class="ke-muted"><?php echo intval( $a->attendee_number ); ?></td>
                            <td><strong><?php echo esc_html( $a->attendee_name ); ?></strong></td>
                            <td class="ke-muted"><?php echo esc_html( $a->attendee_email ); ?></td>
                            <td>
                                <code class="ke-ticket-id">#<?php echo esc_html( $short_code ); ?></code>
                            </td>
                            <td>
                                <?php if ( ! empty( $a->ticket_type_name ) ) : ?>
                                    <span class="ke-type-pill" title="<?php echo esc_attr( $a->ticket_type_name ); ?>">
                                        <?php echo esc_html( $a->ticket_type_name ); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="ke-type-empty">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="ke-status-cell">
                                    <select class="ke-status-select ke-status-<?php echo esc_attr( $a->status ); ?>" data-original="<?php echo esc_attr( $a->status ); ?>">
                                        <option value="valid"     <?php selected( $a->status, 'valid' ); ?>>Valid</option>
                                        <option value="used"      <?php selected( $a->status, 'used' ); ?>>Checked In</option>
                                        <option value="cancelled" <?php selected( $a->status, 'cancelled' ); ?>>Cancelled</option>
                                    </select>
                                    <span class="ke-status-spinner" aria-hidden="true"></span>
                                </div>
                            </td>
                            <td class="ke-muted ke-checkin-cell">
                                <?php echo $a->checked_in_at ? esc_html( date( 'M j, g:i A', strtotime( $a->checked_in_at ) ) ) : '—'; ?>
                            </td>
                            <?php
                            if ( $xf_active ) {
                                $xf_decoded = $a->extra_fields_data ? json_decode( (string) $a->extra_fields_data, true ) : array();
                                if ( ! is_array( $xf_decoded ) ) $xf_decoded = array();
                                foreach ( $xf_columns as $xf ) {
                                    $val = $xf_decoded[ $xf['id'] ] ?? '';
                                    if ( $val === '' || $val === null ) {
                                        echo '<td class="ke-muted ke-col-extra">—</td>';
                                    } else {
                                        echo '<td class="ke-col-extra" title="' . esc_attr( (string) $val ) . '">' . esc_html( (string) $val ) . '</td>';
                                    }
                                }
                            }
                            ?>
                            <td class="ke-col-actions">
                                <button type="button" class="ke-icon-btn" data-action="view" title="View details" aria-label="View">
                                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 10s3-6 9-6 9 6 9 6-3 6-9 6-9-6-9-6z"/><circle cx="10" cy="10" r="3"/></svg>
                                </button>
                                <button type="button" class="ke-icon-btn" data-action="edit" title="Edit attendee" aria-label="Edit">
                                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2.5a2.12 2.12 0 0 1 3 3L7 16l-4 1 1-4 10.5-10.5z"/></svg>
                                </button>
                                <button type="button" class="ke-icon-btn ke-icon-btn-danger" data-action="delete" title="Delete (Shift for permanent)" aria-label="Delete">
                                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 5 17 5"/><path d="M8 5V3a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2"/><path d="M5 5l1 12a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2l1-12"/></svg>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="ke-pagination" style="padding:16px 24px;">
                    <?php
                    $base_url = admin_url( 'admin.php?page=kiwi-events-attendees&event_id=' . $event_id );
                    if ( $status_filter ) $base_url .= '&status=' . $status_filter;
                    if ( $type_filter )   $base_url .= '&ticket_type_id=' . $type_filter;
                    if ( $search )        $base_url .= '&s=' . urlencode( $search );
                    for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
                        <a href="<?php echo esc_url( $base_url . '&paged=' . $i ); ?>"
                           class="ke-page-link <?php echo $i === $page_num ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ── View Modal ── -->
    <div class="ke-modal" id="ke-modal-view" hidden>
        <div class="ke-modal-backdrop" data-close></div>
        <div class="ke-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ke-modal-view-title">
            <button type="button" class="ke-modal-close" data-close aria-label="Close">×</button>
            <div class="ke-modal-body">
                <div class="ke-modal-qr">
                    <img id="ke-view-qr" alt="Ticket QR code">
                </div>
                <div class="ke-modal-details">
                    <h2 id="ke-modal-view-title">Ticket Details</h2>
                    <dl class="ke-detail-list">
                        <div><dt>Attendee</dt><dd id="ke-view-name">—</dd></div>
                        <div><dt>Email</dt><dd id="ke-view-email">—</dd></div>
                        <div><dt>Ticket code</dt><dd><code id="ke-view-code">—</code></dd></div>
                        <div><dt>Ticket type</dt><dd id="ke-view-type">—</dd></div>
                        <div><dt>Event</dt><dd id="ke-view-event">—</dd></div>
                        <div><dt>Event date</dt><dd id="ke-view-date">—</dd></div>
                        <div><dt>Venue</dt><dd id="ke-view-venue">—</dd></div>
                        <div><dt>Purchased</dt><dd id="ke-view-purchased">—</dd></div>
                        <div><dt>Payment</dt><dd id="ke-view-payment">—</dd></div>
                        <div><dt>Order #</dt><dd id="ke-view-order">—</dd></div>
                        <div><dt>Status</dt><dd><span id="ke-view-status" class="ke-badge">—</span></dd></div>
                        <div><dt>Check-in time</dt><dd id="ke-view-checkin">—</dd></div>
                    </dl>
                </div>
            </div>
            <div class="ke-modal-footer">
                <button type="button" class="ke-btn ke-btn-ghost" data-modal-action="edit">Edit</button>
                <button type="button" class="ke-btn ke-btn-ghost" data-modal-action="resend">Resend Email</button>
                <button type="button" class="ke-btn ke-btn-danger" data-modal-action="delete">Delete</button>
            </div>
        </div>
    </div>

    <!-- ── Edit Modal ── -->
    <div class="ke-modal" id="ke-modal-edit" hidden>
        <div class="ke-modal-backdrop" data-close></div>
        <div class="ke-modal-dialog ke-modal-dialog-sm" role="dialog" aria-modal="true" aria-labelledby="ke-modal-edit-title">
            <button type="button" class="ke-modal-close" data-close aria-label="Close">×</button>
            <div class="ke-modal-body">
                <h2 id="ke-modal-edit-title">Edit Attendee</h2>
                <p class="ke-muted" style="margin-top:-4px;">Ticket code and type cannot be changed.</p>
                <div class="ke-form-field">
                    <label for="ke-edit-name">Attendee name</label>
                    <input type="text" id="ke-edit-name">
                </div>
                <div class="ke-form-field">
                    <label for="ke-edit-email">Attendee email</label>
                    <input type="email" id="ke-edit-email">
                </div>
                <div class="ke-form-error" id="ke-edit-error" hidden></div>
            </div>
            <div class="ke-modal-footer">
                <button type="button" class="ke-btn ke-btn-ghost" data-close>Cancel</button>
                <button type="button" class="ke-btn ke-btn-primary" id="ke-edit-save">Save</button>
            </div>
        </div>
    </div>

    <!-- ── Toast container ── -->
    <div class="ke-toasts" id="ke-toasts" aria-live="polite"></div>

</div>
