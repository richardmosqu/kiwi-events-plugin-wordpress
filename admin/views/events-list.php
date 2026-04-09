<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$args = array(
    'post_type'      => 'ke_event',
    'post_status'    => array( 'publish', 'draft' ),
    'posts_per_page' => -1,
    'orderby'        => 'post_date',
    'order'          => 'DESC',
);
$events = get_posts( $args );

global $wpdb;
$orders_table  = $wpdb->prefix . 'ke_orders';
$tickets_table = $wpdb->prefix . 'ke_tickets';
$types_table   = $wpdb->prefix . 'ke_ticket_types';
?>
<div class="wrap ke-builder-wrap">

    <?php if ( ! empty( $_GET['created'] ) ) : ?>
    <div class="notice notice-success is-dismissible" style="border-left-color:#6366f1;">
        <p><strong>🎉 ¡Evento publicado!</strong> Tu evento está listo y visible para el público.</p>
    </div>
    <?php endif; ?>

    <!-- ── Header ── -->
    <div class="ke-builder-header">
        <div class="ke-builder-title">
            <h1>All Events</h1>
        </div>
        <div class="ke-builder-actions">
            <a href="<?php echo admin_url('admin.php?page=ke-event-builder'); ?>" class="ke-btn ke-btn-primary">+ Create Event</a>
        </div>
    </div>

    <?php if ( empty( $events ) ) : ?>
        <div class="ke-card" style="padding:60px 24px;">
            <div class="ke-empty-state">
                <span class="ke-empty-state-icon">📅</span>
                <h3>No Events Yet</h3>
                <p style="margin-bottom:24px;">You haven't created any events yet.</p>
                <a href="<?php echo admin_url('admin.php?page=ke-event-builder'); ?>" class="ke-btn ke-btn-primary">Create Your First Event</a>
            </div>
        </div>
    <?php else : ?>
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(340px,1fr)); gap:20px;">
            <?php foreach ( $events as $event ) :
                $event_id      = $event->ID;
                $status        = get_post_meta( $event_id, '_ke_event_status', true ) ?: 'active';
                $date_start    = get_post_meta( $event_id, '_ke_event_date_start', true );
                $formatted_date = $date_start ? date('M j, Y · g:i A', strtotime( $date_start )) : 'Date TBA';
                $thumb_url     = get_the_post_thumbnail_url( $event_id, 'medium' );
                $is_draft      = $event->post_status === 'draft';

                $revenue = $wpdb->get_var( $wpdb->prepare(
                    "SELECT SUM(total_amount) FROM $orders_table WHERE event_id = %d AND payment_status = 'completed'", $event_id
                ) ) ?: 0;
                $tickets_sold = $wpdb->get_var( $wpdb->prepare(
                    "SELECT SUM(quantity_sold) FROM $types_table WHERE event_id = %d", $event_id
                ) ) ?: 0;
                $tickets_scanned = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(id) FROM $tickets_table WHERE event_id = %d AND status = 'scanned'", $event_id
                ) ) ?: 0;
                $capacity       = get_post_meta( $event_id, '_ke_event_capacity', true ) ?: 0;
                $capacity_text  = $capacity > 0 ? $capacity : '∞';
                $checkin_pct    = $tickets_sold > 0 ? min(100, round(($tickets_scanned / $tickets_sold) * 100)) : 0;
                $display_status = $is_draft ? 'draft' : $status;
            ?>
                <div class="ke-event-card">
                    <!-- Thumbnail -->
                    <div class="ke-event-card-thumb"
                         <?php if ( $thumb_url ) echo 'style="background-image:url(' . esc_url($thumb_url) . ')"'; ?>>
                        <div class="ke-event-card-status">
                            <span class="ke-badge ke-badge-<?php echo esc_attr($display_status); ?>">
                                <?php echo esc_html( ucfirst($display_status) ); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Body -->
                    <div class="ke-event-card-body">
                        <h3 class="ke-event-card-title"><?php echo esc_html( $event->post_title ); ?></h3>
                        <?php 
                        $organizers = wp_get_post_terms( $event_id, 'ke_organizer' );
                        if ( ! empty( $organizers ) && ! is_wp_error( $organizers ) ) {
                            $org = $organizers[0];
                            $org_logo_id = get_term_meta( $org->term_id, 'ke_organizer_logo', true );
                            $org_logo_url = $org_logo_id ? wp_get_attachment_image_url( $org_logo_id, 'thumbnail' ) : '';
                            echo '<div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">';
                            if ( $org_logo_url ) {
                                echo '<img src="' . esc_url( $org_logo_url ) . '" style="width:20px; height:20px; border-radius:50%; object-fit:cover; border:1px solid #e2e8f0;">';
                            } else {
                                echo '<div style="width:20px; height:20px; border-radius:50%; background:#f1f5f9; display:flex; align-items:center; justify-content:center; font-size:10px; border:1px solid #e2e8f0;">🎪</div>';
                            }
                            echo '<span style="font-size:13px; font-weight:600; color:#475569;">' . esc_html( $org->name ) . '</span>';
                            echo '</div>';
                        }
                        ?>
                        <div class="ke-event-card-date">
                            <span>📅</span>
                            <span><?php echo esc_html( $formatted_date ); ?></span>
                        </div>

                        <!-- Metrics -->
                        <div class="ke-metric-row">
                            <div class="ke-metric-item">
                                <div class="ke-metric-label">Net Sales</div>
                                <div class="ke-metric-value">$<?php echo number_format($revenue, 2); ?></div>
                            </div>
                            <div class="ke-metric-item">
                                <div class="ke-metric-label">Sold</div>
                                <div class="ke-metric-value">
                                    <?php echo $tickets_sold; ?>
                                    <span class="ke-metric-sub">/ <?php echo $capacity_text; ?></span>
                                </div>
                            </div>
                            <div class="ke-metric-item" style="grid-column:span 2;">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <div class="ke-metric-label">Checked In</div>
                                    <div style="font-size:13px; font-weight:700; color:#10b981;"><?php echo $tickets_scanned; ?></div>
                                </div>
                                <div class="ke-progress-bar">
                                    <div class="ke-progress-fill" style="width:<?php echo $checkin_pct; ?>%;"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="ke-event-card-actions">
                            <a href="<?php echo admin_url('admin.php?page=ke-event-builder&event_id=' . $event_id); ?>"
                               class="ke-action-btn ke-action-btn-neutral">Edit</a>
                            <a href="<?php echo get_permalink($event_id); ?>" target="_blank"
                               class="ke-action-btn ke-action-btn-neutral">Preview</a>
                            <a href="<?php echo admin_url('admin.php?page=kiwi-events-attendees&event_id=' . $event_id); ?>"
                               class="ke-action-btn ke-action-btn-dark">Attendees</a>
                            <?php $is_on = ($status === 'active' && !$is_draft); ?>
                            <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" class="ke-toggle-form">
                                <input type="hidden" name="action" value="ke_toggle_event_status">
                                <input type="hidden" name="event_id" value="<?php echo $event_id; ?>">
                                <?php wp_nonce_field('ke_toggle_status_nonce'); ?>
                                <button type="submit" class="ke-toggle-pill">
                                    <span><?php echo $is_on ? 'Active' : 'Paused'; ?></span>
                                    <div class="ke-toggle-switch <?php echo $is_on ? 'on' : 'off'; ?>">
                                        <div class="ke-toggle-knob"></div>
                                    </div>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>
