<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
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

    <?php else : ?>
        <!-- Stats Strip -->
        <div class="ke-stat-strip">
            <div class="ke-stat-strip-item">
                <div class="ke-stat-strip-label">Total Attendees</div>
                <div class="ke-stat-strip-value"><?php echo intval( $total ); ?></div>
            </div>
        </div>

        <!-- Table -->
        <div class="ke-card" style="padding:0; overflow:hidden;">
            <table class="ke-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Ticket Type</th>
                        <th>Description</th>
                        <th>Code</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Checked In</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $attendees as $a ) : ?>
                        <tr>
                            <td class="ke-muted"><?php echo intval( $a->attendee_number ); ?></td>
                            <td><strong><?php echo esc_html( $a->attendee_name ); ?></strong></td>
                            <td class="ke-muted"><?php echo esc_html( $a->attendee_email ); ?></td>
                            <td><?php echo esc_html( $a->ticket_type_name ); ?></td>
                            <td class="ke-muted"><?php echo ! empty( $a->ticket_type_description ) ? esc_html( $a->ticket_type_description ) : '—'; ?></td>
                            <td>
                                <code style="font-size:11px; background:rgba(241,245,249,0.8); padding:3px 7px; border-radius:6px; color:#475569;">
                                    <?php echo esc_html( substr( $a->ticket_code, 0, 12 ) ); ?>…
                                </code>
                            </td>
                            <td>
                                <?php echo $a->ticket_price > 0
                                    ? '<strong>$' . number_format( $a->ticket_price, 2 ) . '</strong>'
                                    : '<span class="ke-badge ke-badge-free">Free</span>';
                                ?>
                            </td>
                            <td>
                                <span class="ke-badge ke-badge-<?php echo esc_attr( $a->status ); ?>">
                                    <?php echo match( $a->status ) {
                                        'valid'     => 'Valid',
                                        'used'      => 'Checked In',
                                        'cancelled' => 'Cancelled',
                                        default     => ucfirst( $a->status ),
                                    }; ?>
                                </span>
                            </td>
                            <td class="ke-muted">
                                <?php echo $a->checked_in_at ? date( 'M j, g:i A', strtotime( $a->checked_in_at ) ) : '—'; ?>
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

</div>
