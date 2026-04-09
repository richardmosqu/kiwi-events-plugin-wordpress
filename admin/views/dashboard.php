<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap ke-wrap">

    <!-- ── Header ── -->
    <div class="ke-dashboard-header">
        <div class="ke-page-header-left">
            <h1>KiwiEvents</h1>
            <p>Your event performance at a glance</p>
        </div>
        <div class="ke-header-actions">
            <select id="ke-event-filter" class="ke-select">
                <option value="0">All Events</option>
                <?php foreach ( $events as $event ) : ?>
                    <option value="<?php echo esc_attr( $event->ID ); ?>"><?php echo esc_html( $event->post_title ); ?></option>
                <?php endforeach; ?>
            </select>
            <a href="<?php echo admin_url('admin.php?page=ke-event-builder'); ?>" class="ke-btn ke-btn-primary">+ New Event</a>
        </div>
    </div>

    <!-- ── KPI Cards ── -->
    <div class="ke-kpi-grid">
        <div class="ke-kpi-card ke-kpi-revenue">
            <div class="ke-kpi-icon">💰</div>
            <div class="ke-kpi-content">
                <span class="ke-kpi-label">Total Revenue</span>
                <span class="ke-kpi-value" id="kpi-revenue">$<?php echo number_format( $stats['total_revenue'], 2 ); ?></span>
            </div>
        </div>
        <div class="ke-kpi-card ke-kpi-tickets">
            <div class="ke-kpi-icon">🎟</div>
            <div class="ke-kpi-content">
                <span class="ke-kpi-label">Tickets Sold</span>
                <span class="ke-kpi-value" id="kpi-tickets"><?php echo number_format( $stats['total_tickets'] ); ?></span>
            </div>
        </div>
        <div class="ke-kpi-card ke-kpi-events">
            <div class="ke-kpi-icon">📅</div>
            <div class="ke-kpi-content">
                <span class="ke-kpi-label">Active Events</span>
                <span class="ke-kpi-value" id="kpi-events"><?php echo intval( $events_count ); ?></span>
            </div>
        </div>
        <div class="ke-kpi-card ke-kpi-checkin">
            <div class="ke-kpi-icon">✓</div>
            <div class="ke-kpi-content">
                <span class="ke-kpi-label">Check-in Rate</span>
                <span class="ke-kpi-value" id="kpi-checkin"><?php echo $checkin_rate; ?>%</span>
            </div>
        </div>
    </div>

    <!-- ── Charts Row ── -->
    <div class="ke-charts-grid">
        <div class="ke-card ke-chart-card">
            <h3>Revenue Over Time</h3>
            <canvas id="ke-revenue-chart" height="260"></canvas>
        </div>
        <div class="ke-card ke-chart-card">
            <h3>Tickets Per Event</h3>
            <canvas id="ke-tickets-chart" height="260"></canvas>
        </div>
    </div>

    <!-- ── Distribution ── -->
    <div class="ke-charts-grid ke-charts-single">
        <div class="ke-card ke-chart-card">
            <h3>Ticket Type Distribution</h3>
            <canvas id="ke-type-chart" height="200"></canvas>
        </div>
    </div>

    <!-- ── Recent Orders ── -->
    <div class="ke-card">
        <h3>Recent Orders</h3>
        <?php if ( empty( $recent ) ) : ?>
            <div class="ke-empty-state">
                <span class="ke-empty-state-icon">🎟</span>
                <h3>No orders yet</h3>
                <p>Create an event and start selling tickets.</p>
            </div>
        <?php else : ?>
            <table class="ke-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Event</th>
                        <th>Buyer</th>
                        <th>Qty</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $recent as $order ) : ?>
                        <tr>
                            <td><strong style="font-family:monospace;font-size:12px;"><?php echo esc_html( $order->order_number ); ?></strong></td>
                            <td><?php echo esc_html( $order->event_name ?: '—' ); ?></td>
                            <td>
                                <div style="font-weight:600;"><?php echo esc_html( $order->buyer_name ); ?></div>
                                <div class="ke-muted"><?php echo esc_html( $order->buyer_email ); ?></div>
                            </td>
                            <td><?php echo intval( $order->ticket_quantity ); ?></td>
                            <td>
                                <?php if ( $order->total_amount > 0 ) : ?>
                                    <strong>$<?php echo number_format( $order->total_amount, 2 ); ?></strong>
                                <?php else : ?>
                                    <span class="ke-badge ke-badge-free">Free</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-transform:capitalize;"><?php echo esc_html( $order->payment_method ); ?></td>
                            <td>
                                <span class="ke-badge ke-badge-<?php echo esc_attr( $order->payment_status ); ?>">
                                    <?php echo esc_html( ucfirst( $order->payment_status ) ); ?>
                                </span>
                            </td>
                            <td class="ke-muted"><?php echo date( 'M j, Y', strtotime( $order->created_at ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>
