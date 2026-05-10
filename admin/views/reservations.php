<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reservations admin page view.
 *
 * Vars in scope (set by KE_Admin_Reservations::render):
 *   $rows          array  reservation rows for the current page
 *   $total         int    total matching rows (for pagination)
 *   $stats         array  compute_stats() result
 *   $events        array  WP_Post events (for filter dropdown)
 *   $event_cfg     array  reservations config for the active event (or empty)
 *   $event_id      int    active event filter (0 = all)
 *   $status_filter string current status filter
 *   $search        string current search term
 *   $page_num      int    1-based page number
 *   $total_pages   int    total pages
 */

$status_options = array(
    ''                   => __( 'All statuses',          'kiwi-events' ),
    'pending'            => __( 'Pending',               'kiwi-events' ),
    'confirmed'          => __( 'Confirmed',             'kiwi-events' ),
    'cancelled'          => __( 'Cancelled by customer', 'kiwi-events' ),
    'cancelled_no_show'  => __( 'No-show',               'kiwi-events' ),
    'cancelled_by_venue' => __( 'Cancelled by venue',    'kiwi-events' ),
    'declined'           => __( 'Declined',              'kiwi-events' ),
);

// Pretty label for the status pill — same map without the "All" entry.
$status_labels = $status_options;
unset( $status_labels[''] );

// Build a base URL for pagination/reset that preserves the active filters.
$base_args = array( 'page' => 'kiwi-events-reservations' );
if ( $event_id )      $base_args['event_id'] = $event_id;
if ( $status_filter ) $base_args['status']   = $status_filter;
if ( $search !== '' ) $base_args['s']        = $search;
$base_url = add_query_arg( $base_args, admin_url( 'admin.php' ) );

$export_csv_url = add_query_arg( array_merge( $base_args, array( 'ke_export_csv' => '1' ) ), admin_url( 'admin.php' ) );
$export_pdf_url = add_query_arg( array_merge( $base_args, array( 'ke_export_pdf' => '1' ) ), admin_url( 'admin.php' ) );
?>
<div class="wrap ke-wrap">

    <!-- Header -->
    <div class="ke-page-header">
        <div class="ke-page-header-left">
            <h1><?php esc_html_e( 'Reservations', 'kiwi-events' ); ?></h1>
            <p><?php esc_html_e( 'View, filter, and manage reservations across every event.', 'kiwi-events' ); ?></p>
        </div>
        <div class="ke-header-actions">
            <a href="<?php echo esc_url( $export_csv_url ); ?>" class="ke-btn ke-btn-ghost">↓ <?php esc_html_e( 'Export CSV', 'kiwi-events' ); ?></a>
            <a href="<?php echo esc_url( $export_pdf_url ); ?>" class="ke-btn ke-btn-ghost" target="_blank" rel="noopener">↓ <?php esc_html_e( 'Export PDF', 'kiwi-events' ); ?></a>
        </div>
    </div>

    <!-- Filters -->
    <div class="ke-card ke-filters">
        <form method="get" action="">
            <input type="hidden" name="page" value="kiwi-events-reservations">
            <div class="ke-filter-row">
                <div class="ke-filter-field">
                    <label><?php esc_html_e( 'Event', 'kiwi-events' ); ?></label>
                    <select name="event_id" onchange="this.form.submit()">
                        <option value="0"><?php esc_html_e( 'All events', 'kiwi-events' ); ?></option>
                        <?php foreach ( $events as $event ) : ?>
                            <option value="<?php echo esc_attr( $event->ID ); ?>" <?php selected( $event_id, $event->ID ); ?>>
                                <?php echo esc_html( $event->post_title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ke-filter-field">
                    <label><?php esc_html_e( 'Status', 'kiwi-events' ); ?></label>
                    <select name="status">
                        <?php foreach ( $status_options as $val => $label ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $status_filter, $val ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ke-filter-field">
                    <label><?php esc_html_e( 'Search', 'kiwi-events' ); ?></label>
                    <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Name, email, phone, or code…', 'kiwi-events' ); ?>">
                </div>

                <div class="ke-filter-field ke-filter-submit">
                    <button type="submit" class="ke-btn ke-btn-primary"><?php esc_html_e( 'Filter', 'kiwi-events' ); ?></button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=kiwi-events-reservations' ) ); ?>" class="ke-btn ke-btn-ghost"><?php esc_html_e( 'Reset', 'kiwi-events' ); ?></a>
                </div>
            </div>
        </form>
    </div>

    <!-- Stats Strip -->
    <div class="ke-stat-strip">
        <div class="ke-stat-strip-item">
            <div class="ke-stat-strip-label"><?php esc_html_e( 'Pending', 'kiwi-events' ); ?></div>
            <div class="ke-stat-strip-value"><?php echo (int) $stats['by_status']['pending']['rows']; ?></div>
        </div>
        <div class="ke-stat-strip-item">
            <div class="ke-stat-strip-label"><?php esc_html_e( 'Confirmed', 'kiwi-events' ); ?></div>
            <div class="ke-stat-strip-value"><?php echo (int) $stats['by_status']['confirmed']['rows']; ?></div>
        </div>
        <div class="ke-stat-strip-item">
            <div class="ke-stat-strip-label"><?php esc_html_e( 'Holding seats', 'kiwi-events' ); ?></div>
            <div class="ke-stat-strip-value"><?php echo (int) $stats['holding_seats']; ?></div>
        </div>
        <div class="ke-stat-strip-item">
            <div class="ke-stat-strip-label"><?php esc_html_e( 'Checked in', 'kiwi-events' ); ?></div>
            <div class="ke-stat-strip-value"><?php echo (int) $stats['checked_in']; ?></div>
        </div>
        <div class="ke-stat-strip-item">
            <div class="ke-stat-strip-label"><?php esc_html_e( 'No-show', 'kiwi-events' ); ?></div>
            <div class="ke-stat-strip-value"><?php echo (int) $stats['by_status']['cancelled_no_show']['rows']; ?></div>
        </div>
        <div class="ke-stat-strip-item">
            <div class="ke-stat-strip-label"><?php esc_html_e( 'Total in scope', 'kiwi-events' ); ?></div>
            <div class="ke-stat-strip-value"><?php echo (int) $stats['total_rows']; ?></div>
        </div>
    </div>

    <?php if ( empty( $rows ) ) : ?>
        <div class="ke-card">
            <div class="ke-empty-state">
                <span class="ke-empty-state-icon">📅</span>
                <h3><?php esc_html_e( 'No reservations found', 'kiwi-events' ); ?></h3>
                <p><?php esc_html_e( 'No reservations match the selected filters. Try a broader search or pick a different event.', 'kiwi-events' ); ?></p>
            </div>
        </div>
    <?php else : ?>

        <div class="ke-card ke-attendees-card">
            <table class="ke-table ke-attendees-table ke-resv-admin-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Code',     'kiwi-events' ); ?></th>
                        <th><?php esc_html_e( 'Customer', 'kiwi-events' ); ?></th>
                        <th><?php esc_html_e( 'Party',    'kiwi-events' ); ?></th>
                        <th><?php esc_html_e( 'Arrival',  'kiwi-events' ); ?></th>
                        <?php if ( $event_id <= 0 ) : ?>
                            <th><?php esc_html_e( 'Event',    'kiwi-events' ); ?></th>
                        <?php endif; ?>
                        <th><?php esc_html_e( 'Area',     'kiwi-events' ); ?></th>
                        <th><?php esc_html_e( 'Status',   'kiwi-events' ); ?></th>
                        <th><?php esc_html_e( 'Checked in', 'kiwi-events' ); ?></th>
                        <th class="ke-col-actions"><?php esc_html_e( 'Actions', 'kiwi-events' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $r ) :
                        // Stash the full row as JSON so the view modal can
                        // render without an extra fetch round-trip.
                        $payload = array(
                            'id'                 => (int) $r->id,
                            'reservation_code'   => (string) $r->reservation_code,
                            'event_id'           => (int) $r->event_id,
                            'event_title'        => (string) ( $r->event_title ?? '' ),
                            'customer_name'      => (string) $r->customer_name,
                            'customer_email'     => (string) $r->customer_email,
                            'customer_phone'     => (string) $r->customer_phone,
                            'party_size'         => (int) $r->party_size,
                            'arrival_time'       => (string) $r->arrival_time,
                            'area'               => (string) ( $r->area ?? '' ),
                            'status'             => (string) $r->status,
                            'checked_in_at'      => (string) ( $r->checked_in_at ?? '' ),
                            'no_show_processed'  => (int) ( $r->no_show_processed ?? 0 ),
                            'notes'              => (string) ( $r->notes ?? '' ),
                            'decline_reason'     => (string) ( $r->decline_reason ?? '' ),
                            'created_at'         => (string) $r->created_at,
                            'updated_at'         => (string) ( $r->updated_at ?? '' ),
                        );
                        $payload_attr = esc_attr( wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
                        $arrival_fmt  = $r->arrival_time ? date_i18n( 'M j · g:i A', strtotime( $r->arrival_time ) ) : '—';
                        $checkin_fmt  = ! empty( $r->checked_in_at ) ? date_i18n( 'M j · g:i A', strtotime( $r->checked_in_at ) ) : '—';
                        $status_label = $status_labels[ $r->status ] ?? ucfirst( str_replace( '_', ' ', $r->status ) );
                    ?>
                        <tr data-reservation-id="<?php echo (int) $r->id; ?>" data-reservation='<?php echo $payload_attr; ?>'>
                            <td><code class="ke-ticket-id">#<?php echo esc_html( $r->reservation_code ); ?></code></td>
                            <td>
                                <strong><?php echo esc_html( $r->customer_name ?: '—' ); ?></strong>
                                <?php if ( ! empty( $r->customer_email ) ) : ?>
                                    <div class="ke-muted ke-resv-contact"><?php echo esc_html( $r->customer_email ); ?></div>
                                <?php endif; ?>
                                <?php if ( ! empty( $r->customer_phone ) ) : ?>
                                    <div class="ke-muted ke-resv-contact"><?php echo esc_html( $r->customer_phone ); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int) $r->party_size; ?></td>
                            <td class="ke-muted"><?php echo esc_html( $arrival_fmt ); ?></td>
                            <?php if ( $event_id <= 0 ) : ?>
                                <td class="ke-muted"><?php echo esc_html( $r->event_title ?: '—' ); ?></td>
                            <?php endif; ?>
                            <td>
                                <?php if ( ! empty( $r->area ) ) : ?>
                                    <span class="ke-type-pill"><?php echo esc_html( $r->area ); ?></span>
                                <?php else : ?>
                                    <span class="ke-type-empty">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="ke-resv-status-pill ke-resv-status-<?php echo esc_attr( $r->status ); ?>">
                                    <?php echo esc_html( $status_label ); ?>
                                </span>
                            </td>
                            <td class="ke-muted"><?php echo esc_html( $checkin_fmt ); ?></td>
                            <td class="ke-col-actions">
                                <button type="button" class="ke-icon-btn" data-action="view" title="<?php esc_attr_e( 'View details', 'kiwi-events' ); ?>" aria-label="<?php esc_attr_e( 'View', 'kiwi-events' ); ?>">
                                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 10s3-6 9-6 9 6 9 6-3 6-9 6-9-6-9-6z"/><circle cx="10" cy="10" r="3"/></svg>
                                </button>
                                <?php if ( $r->status === 'pending' ) : ?>
                                    <button type="button" class="ke-icon-btn ke-icon-btn-success" data-action="approve" title="<?php esc_attr_e( 'Approve', 'kiwi-events' ); ?>" aria-label="<?php esc_attr_e( 'Approve', 'kiwi-events' ); ?>">
                                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 11 8 15 16 6"/></svg>
                                    </button>
                                    <button type="button" class="ke-icon-btn ke-icon-btn-danger" data-action="decline" title="<?php esc_attr_e( 'Decline', 'kiwi-events' ); ?>" aria-label="<?php esc_attr_e( 'Decline', 'kiwi-events' ); ?>">
                                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="5" x2="15" y2="15"/><line x1="15" y1="5" x2="5" y2="15"/></svg>
                                    </button>
                                <?php endif; ?>
                                <?php if ( $r->status === 'confirmed' && empty( $r->checked_in_at ) ) : ?>
                                    <button type="button" class="ke-icon-btn" data-action="check-in" title="<?php esc_attr_e( 'Check in', 'kiwi-events' ); ?>" aria-label="<?php esc_attr_e( 'Check in', 'kiwi-events' ); ?>">
                                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 1l2.5 6 6.5.5-5 4.5 1.5 6.5L10 15l-5.5 3.5L6 12 1 7.5 7.5 7z"/></svg>
                                    </button>
                                <?php endif; ?>
                                <?php if ( in_array( $r->status, array( 'pending', 'confirmed' ), true ) ) : ?>
                                    <button type="button" class="ke-icon-btn ke-icon-btn-danger" data-action="cancel" title="<?php esc_attr_e( 'Cancel reservation', 'kiwi-events' ); ?>" aria-label="<?php esc_attr_e( 'Cancel', 'kiwi-events' ); ?>">
                                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 5 17 5"/><path d="M8 5V3a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2"/><path d="M5 5l1 12a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2l1-12"/></svg>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="ke-pagination" style="padding:16px 24px;">
                    <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
                        <a href="<?php echo esc_url( add_query_arg( 'paged', $i, $base_url ) ); ?>"
                           class="ke-page-link <?php echo $i === $page_num ? 'active' : ''; ?>">
                            <?php echo (int) $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

    <!-- View Modal -->
    <div class="ke-modal" id="ke-resv-modal-view" hidden>
        <div class="ke-modal-backdrop" data-close></div>
        <div class="ke-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ke-resv-view-title">
            <button type="button" class="ke-modal-close" data-close aria-label="<?php esc_attr_e( 'Close', 'kiwi-events' ); ?>">×</button>
            <div class="ke-modal-body">
                <div class="ke-modal-details" style="grid-column:1/-1;">
                    <h2 id="ke-resv-view-title"><?php esc_html_e( 'Reservation details', 'kiwi-events' ); ?></h2>
                    <dl class="ke-detail-list">
                        <div><dt><?php esc_html_e( 'Code',         'kiwi-events' ); ?></dt><dd><code id="ke-resv-view-code">—</code></dd></div>
                        <div><dt><?php esc_html_e( 'Status',       'kiwi-events' ); ?></dt><dd><span id="ke-resv-view-status" class="ke-badge">—</span></dd></div>
                        <div><dt><?php esc_html_e( 'Customer',     'kiwi-events' ); ?></dt><dd id="ke-resv-view-customer">—</dd></div>
                        <div><dt><?php esc_html_e( 'Email',        'kiwi-events' ); ?></dt><dd id="ke-resv-view-email">—</dd></div>
                        <div><dt><?php esc_html_e( 'Phone',        'kiwi-events' ); ?></dt><dd id="ke-resv-view-phone">—</dd></div>
                        <div><dt><?php esc_html_e( 'Event',        'kiwi-events' ); ?></dt><dd id="ke-resv-view-event">—</dd></div>
                        <div><dt><?php esc_html_e( 'Party size',   'kiwi-events' ); ?></dt><dd id="ke-resv-view-party">—</dd></div>
                        <div><dt><?php esc_html_e( 'Arrival',      'kiwi-events' ); ?></dt><dd id="ke-resv-view-arrival">—</dd></div>
                        <div><dt><?php esc_html_e( 'Area',         'kiwi-events' ); ?></dt><dd id="ke-resv-view-area">—</dd></div>
                        <div><dt><?php esc_html_e( 'Checked in',   'kiwi-events' ); ?></dt><dd id="ke-resv-view-checkin">—</dd></div>
                        <div><dt><?php esc_html_e( 'Notes',        'kiwi-events' ); ?></dt><dd id="ke-resv-view-notes">—</dd></div>
                        <div><dt><?php esc_html_e( 'Decline reason', 'kiwi-events' ); ?></dt><dd id="ke-resv-view-decline">—</dd></div>
                        <div><dt><?php esc_html_e( 'Created',      'kiwi-events' ); ?></dt><dd id="ke-resv-view-created">—</dd></div>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <!-- Decline Modal (asks for an optional reason that's emailed to the customer) -->
    <div class="ke-modal" id="ke-resv-modal-decline" hidden>
        <div class="ke-modal-backdrop" data-close></div>
        <div class="ke-modal-dialog ke-modal-dialog-sm" role="dialog" aria-modal="true" aria-labelledby="ke-resv-decline-title">
            <button type="button" class="ke-modal-close" data-close aria-label="<?php esc_attr_e( 'Close', 'kiwi-events' ); ?>">×</button>
            <div class="ke-modal-body">
                <h2 id="ke-resv-decline-title"><?php esc_html_e( 'Decline reservation', 'kiwi-events' ); ?></h2>
                <p class="ke-muted" style="margin-top:-4px;">
                    <?php esc_html_e( 'Optional — the customer sees this in their decline email.', 'kiwi-events' ); ?>
                </p>
                <div class="ke-form-field">
                    <label for="ke-resv-decline-reason"><?php esc_html_e( 'Reason', 'kiwi-events' ); ?></label>
                    <textarea id="ke-resv-decline-reason" rows="4" placeholder="<?php esc_attr_e( 'e.g. fully booked at that time, party size exceeds available seating, …', 'kiwi-events' ); ?>"></textarea>
                </div>
                <div class="ke-form-error" id="ke-resv-decline-error" hidden></div>
            </div>
            <div class="ke-modal-footer">
                <button type="button" class="ke-btn ke-btn-ghost" data-close><?php esc_html_e( 'Cancel', 'kiwi-events' ); ?></button>
                <button type="button" class="ke-btn ke-btn-danger" id="ke-resv-decline-submit"><?php esc_html_e( 'Decline', 'kiwi-events' ); ?></button>
            </div>
        </div>
    </div>

    <!-- Toasts -->
    <div class="ke-toasts" id="ke-toasts" aria-live="polite"></div>

</div>
