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
    <div class="notice notice-success is-dismissible" style="border-left-color:var(--kiwi-green);">
        <p><strong>🎉 ¡Evento publicado!</strong> Tu evento está listo y visible para el público.</p>
    </div>
    <?php endif; ?>

    <?php if ( ! empty( $_GET['deleted'] ) ) : ?>
    <div class="notice notice-success is-dismissible" style="border-left-color:var(--kiwi-green);">
        <p><strong>Event deleted.</strong> Tickets and attendees remain in the Attendees section.</p>
    </div>
    <?php endif; ?>

    <!-- ── Page header (wrapped on white; event grid below keeps its own card style) ── -->
    <div class="ke-section-card ke-section-card--compact">
        <div class="ke-builder-header">
            <div class="ke-builder-title">
                <h1>All Events</h1>
            </div>
            <div class="ke-builder-actions">
                <a href="<?php echo admin_url('admin.php?page=ke-event-builder'); ?>" class="ke-btn ke-btn-primary">+ Create Event</a>
            </div>
        </div>
    </div>

    <?php if ( empty( $events ) ) : ?>
        <div class="ke-section-card" style="padding:60px 24px;">
            <div class="ke-empty-state">
                <span class="ke-empty-state-icon">📅</span>
                <h3>No Events Yet</h3>
                <p style="margin-bottom:24px;">You haven't created any events yet.</p>
                <a href="<?php echo admin_url('admin.php?page=ke-event-builder'); ?>" class="ke-btn ke-btn-primary">Create Your First Event</a>
            </div>
        </div>
    <?php else : ?>
        <!-- ── Events grid — wrapped in one white container so the individual
             event cards sit on a defined surface, matching the event-editor
             treatment. Cream page background shows in the gaps between cards. ── -->
        <div class="ke-section-card">
        <div class="ke-events-card-grid">
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

                $type_stats = $wpdb->get_row( $wpdb->prepare(
                    "SELECT
                        COALESCE(SUM(CASE WHEN capacity_type = 'limited' THEN quantity_total ELSE 0 END), 0) AS total_capacity,
                        COALESCE(SUM(quantity_sold), 0) AS total_sold,
                        COUNT(*) AS type_count,
                        COALESCE(SUM(CASE WHEN capacity_type = 'unlimited' THEN 1 ELSE 0 END), 0) AS unlimited_count
                     FROM $types_table
                     WHERE event_id = %d AND (is_archived IS NULL OR is_archived = 0)",
                    $event_id
                ) );
                $total_capacity  = (int) ( $type_stats->total_capacity  ?? 0 );
                $total_sold      = (int) ( $type_stats->total_sold      ?? 0 );
                $type_count      = (int) ( $type_stats->type_count      ?? 0 );
                $unlimited_count = (int) ( $type_stats->unlimited_count ?? 0 );
                $all_unlimited   = ( $type_count > 0 && $unlimited_count === $type_count );
                $is_sold_out     = ( $type_count > 0 && $unlimited_count === 0 && $total_capacity > 0 && $total_sold >= $total_capacity );
                $sold_pct        = $total_capacity > 0 ? min( 100, round( ( $total_sold / $total_capacity ) * 100 ) ) : 0;

                $tickets_scanned = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(id) FROM $tickets_table WHERE event_id = %d AND status = 'used'", $event_id
                ) );

                $tickets_sold   = $total_sold;
                $capacity_text  = $all_unlimited ? '∞' : ( $total_capacity > 0 ? $total_capacity : '—' );
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
                                echo '<img src="' . esc_url( $org_logo_url ) . '" style="width:20px; height:20px; border-radius:50%; object-fit:cover; border:1px solid var(--kiwi-border);">';
                            } else {
                                echo '<div style="width:20px; height:20px; border-radius:50%; background:var(--kiwi-cream-deep); display:flex; align-items:center; justify-content:center; font-size:10px; border:1px solid var(--kiwi-border);">🎪</div>';
                            }
                            echo '<span style="font-size:13px; font-weight:600; color:var(--kiwi-text-muted);">' . esc_html( $org->name ) . '</span>';
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
                                <?php if ( $type_count === 0 ) : ?>
                                    <div class="ke-metric-empty">No tickets configured</div>
                                <?php elseif ( $is_sold_out ) : ?>
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                        <div class="ke-metric-label">Tickets Sold</div>
                                        <span class="ke-badge-soldout">SOLD OUT</span>
                                    </div>
                                    <div class="ke-progress-bar">
                                        <div class="ke-progress-fill-sales ke-progress-fill-soldout" style="--ke-bar-w:100%;"></div>
                                    </div>
                                <?php elseif ( $all_unlimited ) : ?>
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <div class="ke-metric-label">Tickets Sold</div>
                                        <div class="ke-metric-sold-count"><?php echo (int) $total_sold; ?> sold <span class="ke-inf">∞</span></div>
                                    </div>
                                <?php else : ?>
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                        <div class="ke-metric-label">Tickets Sold</div>
                                        <div class="ke-metric-sold-count"><?php echo (int) $total_sold; ?> / <?php echo (int) $total_capacity; ?></div>
                                    </div>
                                    <div class="ke-progress-bar">
                                        <div class="ke-progress-fill-sales" style="--ke-bar-w:<?php echo (int) $sold_pct; ?>%;"></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="ke-metric-item" style="grid-column:span 2;">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <div class="ke-metric-label">Checked In</div>
                                    <div style="font-size:13px; font-weight:700; color:var(--kiwi-green-text);"><?php echo $tickets_scanned; ?></div>
                                </div>
                                <div class="ke-progress-bar">
                                    <div class="ke-progress-fill" style="--ke-bar-w:<?php echo (int) $checkin_pct; ?>%;"></div>
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
                            <a href="<?php echo admin_url('admin.php?page=ke-promoters&action=event_dashboard&event_id=' . $event_id); ?>"
                               class="ke-action-btn ke-action-btn-neutral">Promoters</a>
                            <button type="button"
                                    class="ke-action-icon-btn ke-duplicate-event-btn"
                                    title="Duplicate event"
                                    aria-label="Duplicate event"
                                    data-event-id="<?php echo esc_attr( $event_id ); ?>"
                                    data-event-name="<?php echo esc_attr( $event->post_title ); ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                </svg>
                            </button>
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
                            <button type="button"
                                    class="ke-action-btn ke-action-btn-delete ke-delete-event-btn"
                                    data-event-id="<?php echo esc_attr( $event_id ); ?>"
                                    data-event-name="<?php echo esc_attr( $event->post_title ); ?>">
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        </div>
    <?php endif; ?>

</div>

<!-- ── Delete confirmation modal (shared with builder header) ── -->
<div class="ke-confirm-overlay" id="ke-delete-overlay" aria-hidden="true">
    <div class="ke-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="ke-delete-title">
        <div class="ke-confirm-icon">🗑</div>
        <h3 class="ke-confirm-title" id="ke-delete-title">Delete this event?</h3>
        <p class="ke-confirm-body">
            Tickets and attendees will be preserved and remain accessible
            in the Attendees section.
        </p>
        <div class="ke-confirm-error" id="ke-delete-error" style="display:none;"></div>
        <div class="ke-confirm-actions">
            <button type="button" class="ke-btn ke-btn-ghost" id="ke-delete-cancel">Cancel</button>
            <button type="button" class="ke-btn ke-btn-danger" id="ke-delete-confirm">Delete Event</button>
        </div>
    </div>
</div>

<!-- ── Duplicate confirmation modal ── -->
<div class="ke-confirm-overlay" id="ke-duplicate-overlay" aria-hidden="true">
    <div class="ke-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="ke-duplicate-title">
        <div class="ke-confirm-icon ke-confirm-icon-info">⧉</div>
        <h3 class="ke-confirm-title" id="ke-duplicate-title">Duplicate this event?</h3>
        <p class="ke-confirm-body">
            A new draft event will be created with the same details, banner,
            ticket types, and extras. Tickets, orders, and testimonials will
            <strong>not</strong> be copied.
        </p>
        <div class="ke-confirm-error" id="ke-duplicate-error" style="display:none;"></div>
        <div class="ke-confirm-actions">
            <button type="button" class="ke-btn ke-btn-ghost" id="ke-duplicate-cancel">Cancel</button>
            <button type="button" class="ke-btn ke-btn-primary" id="ke-duplicate-confirm">Duplicate</button>
        </div>
    </div>
</div>

<!-- Floating toast (non-alert inline feedback) -->
<div class="ke-toast" id="ke-toast" role="status" aria-live="polite"></div>

<style>
/* Events-list page styles. Migrated to Kiwi tokens:
   Tailwind reds → var(--kiwi-red) rgba scale,
   slate text/borders → Kiwi neutrals,
   lime gradients → solid Kiwi green,
   slate-navy primary CTA → Kiwi green primary. */
.ke-action-btn-delete {
    background: var(--kiwi-surface);
    border: 1px solid var(--kiwi-red-edge-medium);
    color: var(--kiwi-red);
    cursor: pointer;
    font-family: inherit;
    font-size: 13px;
    font-weight: 600;
    padding: 9px 12px;
    border-radius: 12px;
    transition: background .15s var(--kiwi-ease), border-color .15s var(--kiwi-ease);
    line-height: 1;
}
.ke-action-btn-delete:hover {
    background: var(--kiwi-red-fill);
    border-color: var(--kiwi-red);
}
.ke-confirm-overlay {
    position: fixed; inset: 0;
    background: var(--kiwi-shadow-7);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    display: none;
    align-items: center; justify-content: center;
    z-index: 99999;
    opacity: 0;
    transition: opacity .18s var(--kiwi-ease);
}
.ke-confirm-overlay.is-open { display: flex; opacity: 1; }
.ke-confirm-modal {
    background: var(--kiwi-surface);
    -webkit-backdrop-filter: none;
    backdrop-filter: none;
    border: 1px solid var(--kiwi-border-hairline);
    border-radius: 24px;
    box-shadow:
        0 1px 0 var(--kiwi-inner-highlight-strong) inset,
        0 24px 48px var(--kiwi-legacy-knob-shadow-15),
        0 8px 16px var(--kiwi-shadow-5);
    padding: 32px 28px 24px;
    width: min(420px, calc(100vw - 32px));
    text-align: center;
    transform: translateY(12px) scale(.97);
    transition: transform .2s var(--kiwi-ease);
}
.ke-confirm-overlay.is-open .ke-confirm-modal { transform: none; }
.ke-confirm-icon {
    width: 56px; height: 56px;
    margin: 0 auto 16px;
    display: grid; place-items: center;
    background: var(--kiwi-red-fill-medium);
    color: var(--kiwi-red);
    border: 1px solid var(--kiwi-red-edge-soft);
    border-radius: 50%; font-size: 26px;
}
.ke-confirm-title {
    margin: 0 0 8px; font-size: 19px; font-weight: 700; color: var(--kiwi-text);
}
.ke-confirm-body {
    margin: 0 0 20px; color: var(--kiwi-text-muted); font-size: 14px; line-height: 1.5;
}
.ke-confirm-error {
    background: var(--kiwi-red-fill);
    color: var(--kiwi-red);
    border: 1px solid var(--kiwi-red-edge-mid);
    border-radius: var(--kiwi-radius-sm);
    padding: 10px 12px;
    font-size: 13px;
    margin-bottom: 16px;
    text-align: left;
}
.ke-confirm-actions {
    display: flex; gap: 10px; justify-content: center;
}
.ke-confirm-actions .ke-btn { min-width: 110px; }
.ke-btn-danger {
    background: var(--kiwi-red);
    color: var(--kiwi-white-ink);
    border: 1px solid var(--kiwi-red);
    padding: 10px 16px;
    border-radius: var(--kiwi-radius-sm);
    font-weight: 600;
    cursor: pointer;
    transition: background .15s var(--kiwi-ease), box-shadow .15s var(--kiwi-ease);
    box-shadow: 0 1px 0 var(--kiwi-inner-highlight-fainter) inset, 0 4px 10px var(--kiwi-red-glow-darker);
}
.ke-btn-danger:hover { background: var(--kiwi-red-darker-alt); }
.ke-btn-danger:disabled { opacity: .6; cursor: not-allowed; }
.ke-toast {
    position: fixed; right: 24px; bottom: 24px;
    background: var(--kiwi-surface);
    -webkit-backdrop-filter: none;
    backdrop-filter: none;
    border: 1px solid var(--kiwi-border-hairline);
    color: var(--kiwi-text);
    padding: 12px 18px;
    border-radius: var(--kiwi-radius-md);
    box-shadow:
        0 1px 0 var(--kiwi-inner-highlight-strong) inset,
        0 12px 24px var(--kiwi-shadow-6),
        0 4px 8px var(--kiwi-shadow-4);
    font-size: 14px; font-weight: 500;
    opacity: 0; transform: translateY(8px);
    transition: opacity .2s var(--kiwi-ease), transform .2s var(--kiwi-ease);
    pointer-events: none;
    z-index: 100000;
}
.ke-toast.is-visible { opacity: 1; transform: none; }
.ke-toast.is-error {
    background: var(--kiwi-red-fill-medium);
    border-color: var(--kiwi-red-edge-strong);
    color: var(--kiwi-red);
}

/* ── Tickets Sold / Checked In bars ── */
.ke-progress-bar {
    overflow: hidden;
    border-radius: var(--kiwi-radius-pill);
    background: var(--kiwi-cream-deep);
    border: 1px solid var(--kiwi-border);
}
.ke-progress-fill-sales {
    height: 100%;
    width: var(--ke-bar-w, 0%);
    background: var(--kiwi-green);
    border-radius: var(--kiwi-radius-pill);
    animation: ke-bar-grow 900ms var(--kiwi-ease) both;
}
.ke-progress-fill {
    height: 100%;
    width: var(--ke-bar-w, 0%);
    background: var(--kiwi-green);
    border-radius: var(--kiwi-radius-pill);
    animation: ke-bar-grow 900ms 120ms var(--kiwi-ease) both;
}
.ke-progress-fill-soldout {
    background: var(--kiwi-red) !important;
}
@keyframes ke-bar-grow {
    from { width: 0; }
}
.ke-metric-empty {
    font-size: 12px;
    color: var(--kiwi-text-muted);
    font-style: italic;
    padding: 6px 0;
}
.ke-metric-sold-count {
    font-size: 13px;
    font-weight: 700;
    color: var(--kiwi-green-text);
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.ke-metric-sold-count .ke-inf {
    font-size: 15px;
    line-height: 1;
    color: var(--kiwi-text-muted);
    font-weight: 600;
}
.ke-badge-soldout {
    display: inline-block;
    background: var(--kiwi-red-fill-medium);
    color: var(--kiwi-red);
    border: 1px solid var(--kiwi-red-edge-medium);
    border-radius: var(--kiwi-radius-pill);
    padding: 2px 10px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .4px;
    text-transform: uppercase;
}

/* ── Duplicate icon button — rounded to match the card-action family ── */
.ke-action-icon-btn {
    background: var(--kiwi-surface);
    border: 1px solid var(--kiwi-border);
    color: var(--kiwi-text-muted);
    cursor: pointer;
    font-family: inherit;
    padding: 8px 10px;
    border-radius: 12px;
    transition: background .15s var(--kiwi-ease), border-color .15s var(--kiwi-ease), color .15s var(--kiwi-ease);
    line-height: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.ke-action-icon-btn:hover {
    background: var(--kiwi-green-soft);
    border-color: var(--kiwi-green-line);
    color: var(--kiwi-green-text);
}
.ke-action-icon-btn:disabled {
    opacity: .55;
    cursor: not-allowed;
}

/* ── Info-variant confirm modal (duplicate) ── */
.ke-confirm-icon-info {
    background: var(--kiwi-green-tint) !important;
    color: var(--kiwi-green-text) !important;
    border-color: var(--kiwi-green-line) !important;
    font-size: 28px !important;
}
/* Primary CTA — Kiwi green with dark text. White-on-lime fails WCAG AA. */
.ke-btn-primary {
    background: var(--kiwi-green);
    color: var(--kiwi-text);
    border: 1px solid var(--kiwi-green);
    padding: 10px 16px;
    border-radius: var(--kiwi-radius-sm);
    font-weight: 600;
    cursor: pointer;
    transition: background .15s var(--kiwi-ease), box-shadow .15s var(--kiwi-ease);
    box-shadow:
        0 1px 0 var(--kiwi-inner-highlight) inset,
        0 4px 10px var(--kiwi-green-glow);
}
.ke-btn-primary:hover { background: var(--kiwi-green-dark); }
.ke-btn-primary:disabled { opacity: .6; cursor: not-allowed; }
.ke-btn-ghost {
    background: transparent;
    color: var(--kiwi-text);
    border: 1px solid var(--kiwi-border);
    padding: 10px 16px;
    border-radius: var(--kiwi-radius-sm);
    font-weight: 600;
    cursor: pointer;
    transition: background .15s var(--kiwi-ease), border-color .15s var(--kiwi-ease);
}
.ke-btn-ghost:hover {
    background: var(--kiwi-cream-deep);
    border-color: var(--kiwi-glass-border);
}
</style>

<script>
(function() {
    var nonce    = '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>';
    var restBase = '<?php echo esc_js( rest_url('ke/v1/') ); ?>';

    var overlay      = document.getElementById('ke-delete-overlay');
    var confirmBtn   = document.getElementById('ke-delete-confirm');
    var cancelBtn    = document.getElementById('ke-delete-cancel');
    var errorEl      = document.getElementById('ke-delete-error');
    var toast        = document.getElementById('ke-toast');
    var pending      = null; // { eventId, card, trigger }

    function showToast(msg, isError) {
        toast.textContent = msg;
        toast.classList.toggle('is-error', !!isError);
        toast.classList.add('is-visible');
        clearTimeout(toast._t);
        toast._t = setTimeout(function() { toast.classList.remove('is-visible'); }, 3000);
    }

    function openModal(ctx) {
        pending = ctx;
        errorEl.style.display = 'none';
        errorEl.textContent   = '';
        confirmBtn.disabled   = false;
        confirmBtn.textContent = 'Delete Event';
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
    }
    function closeModal() {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        pending = null;
    }

    cancelBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
    });

    confirmBtn.addEventListener('click', function() {
        if (!pending) return;
        var ctx = pending;
        confirmBtn.disabled   = true;
        confirmBtn.textContent = 'Deleting…';
        errorEl.style.display = 'none';

        fetch(restBase + 'events/' + ctx.eventId, {
            method: 'DELETE',
            headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }
        })
        .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
        .then(function(res) {
            if (res.ok && res.data && res.data.success) {
                closeModal();
                if (ctx.card) {
                    ctx.card.style.transition = 'opacity .3s, transform .3s';
                    ctx.card.style.opacity    = '0';
                    ctx.card.style.transform  = 'scale(.96)';
                    setTimeout(function() { ctx.card.remove(); }, 320);
                }
                showToast('Event deleted. Attendees preserved.');
            } else {
                var msg = (res.data && res.data.message) || 'Could not delete event.';
                errorEl.textContent   = msg;
                errorEl.style.display = 'block';
                confirmBtn.disabled    = false;
                confirmBtn.textContent = 'Delete Event';
            }
        })
        .catch(function() {
            errorEl.textContent    = 'Network error. Please try again.';
            errorEl.style.display  = 'block';
            confirmBtn.disabled    = false;
            confirmBtn.textContent = 'Delete Event';
        });
    });

    document.querySelectorAll('.ke-delete-event-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openModal({
                eventId: this.dataset.eventId,
                card:    this.closest('.ke-event-card'),
                trigger: this,
            });
        });
    });

    /* ── Duplicate event ── */
    var dupOverlay     = document.getElementById('ke-duplicate-overlay');
    var dupConfirmBtn  = document.getElementById('ke-duplicate-confirm');
    var dupCancelBtn   = document.getElementById('ke-duplicate-cancel');
    var dupErrorEl     = document.getElementById('ke-duplicate-error');
    var dupPending     = null; // { eventId, trigger }

    function openDupModal(ctx) {
        dupPending = ctx;
        dupErrorEl.style.display = 'none';
        dupErrorEl.textContent   = '';
        dupConfirmBtn.disabled   = false;
        dupConfirmBtn.textContent = 'Duplicate';
        dupOverlay.classList.add('is-open');
        dupOverlay.setAttribute('aria-hidden', 'false');
    }
    function closeDupModal() {
        dupOverlay.classList.remove('is-open');
        dupOverlay.setAttribute('aria-hidden', 'true');
        if (dupPending && dupPending.trigger) dupPending.trigger.disabled = false;
        dupPending = null;
    }

    dupCancelBtn.addEventListener('click', closeDupModal);
    dupOverlay.addEventListener('click', function(e) {
        if (e.target === dupOverlay) closeDupModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && dupOverlay.classList.contains('is-open')) closeDupModal();
    });

    dupConfirmBtn.addEventListener('click', function() {
        if (!dupPending) return;
        var ctx = dupPending;
        dupConfirmBtn.disabled    = true;
        dupConfirmBtn.textContent = 'Duplicating…';
        dupErrorEl.style.display  = 'none';

        fetch(restBase + 'events/' + ctx.eventId + '/duplicate', {
            method: 'POST',
            headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }
        })
        .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
        .then(function(res) {
            if (res.ok && res.data && res.data.success) {
                showToast('Event duplicated. Opening draft…');
                setTimeout(function() {
                    window.location.href = res.data.edit_url;
                }, 700);
            } else {
                var msg = (res.data && res.data.message) || 'Could not duplicate event.';
                dupErrorEl.textContent   = msg;
                dupErrorEl.style.display = 'block';
                dupConfirmBtn.disabled    = false;
                dupConfirmBtn.textContent = 'Duplicate';
            }
        })
        .catch(function() {
            dupErrorEl.textContent    = 'Network error. Please try again.';
            dupErrorEl.style.display  = 'block';
            dupConfirmBtn.disabled    = false;
            dupConfirmBtn.textContent = 'Duplicate';
        });
    });

    document.querySelectorAll('.ke-duplicate-event-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openDupModal({
                eventId: this.dataset.eventId,
                trigger: this,
            });
        });
    });
})();
</script>
