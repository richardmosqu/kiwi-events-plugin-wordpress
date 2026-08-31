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
    <?php else :

        // ── Filter data — one pass over the already-fetched events, so the
        // bar costs zero extra queries (get_posts() primed the term cache).
        $filter_organizers = array(); // term_id => name
        $filter_categories = array(); // term_id => name
        $has_no_organizer  = false;
        foreach ( $events as $ev ) {
            $ev_orgs = wp_get_post_terms( $ev->ID, 'ke_organizer' );
            if ( ! empty( $ev_orgs ) && ! is_wp_error( $ev_orgs ) ) {
                $filter_organizers[ $ev_orgs[0]->term_id ] = $ev_orgs[0]->name;
            } else {
                $has_no_organizer = true;
            }
            $ev_cats = wp_get_post_terms( $ev->ID, 'ke_event_category' );
            if ( ! empty( $ev_cats ) && ! is_wp_error( $ev_cats ) ) {
                foreach ( $ev_cats as $ev_cat ) {
                    $filter_categories[ $ev_cat->term_id ] = $ev_cat->name;
                }
            }
        }
        natcasesort( $filter_organizers );
        natcasesort( $filter_categories );

        // Date-preset bounds anchored to the SITE timezone (not the browser's),
        // so "Today / This week" means the same thing for every admin.
        $now_ts      = current_time( 'timestamp' );
        $dow         = (int) date( 'N', $now_ts );
        $date_bounds = array(
            'today'      => date( 'Y-m-d', $now_ts ),
            'weekStart'  => date( 'Y-m-d', $now_ts - ( $dow - 1 ) * DAY_IN_SECONDS ),
            'weekEnd'    => date( 'Y-m-d', $now_ts + ( 7 - $dow ) * DAY_IN_SECONDS ),
            'monthStart' => date( 'Y-m-01', $now_ts ),
            'monthEnd'   => date( 'Y-m-t', $now_ts ),
        );
    ?>

        <!-- ── Filters — client-side: every event is already in the DOM
             (posts_per_page = -1), so filtering/sorting is instant. Selections
             are mirrored into the URL so filtered views can be shared. ── -->
        <form class="ke-section-card ke-filters ke-events-filters" id="ke-events-filters" role="search" aria-label="Filter events">
            <div class="ke-events-filters-top">
                <div class="ke-filter-field ke-filter-search">
                    <label for="ke-f-search">Search</label>
                    <input type="search" id="ke-f-search" placeholder="Title, organizer, or venue…" autocomplete="off">
                </div>
                <button type="button" class="ke-filters-toggle" id="ke-filters-toggle" aria-expanded="false" aria-controls="ke-filters-panel">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true">
                        <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
                    </svg>
                    <span>Filters</span>
                    <span class="ke-filters-toggle-count" id="ke-filters-toggle-count" hidden></span>
                </button>
                <div class="ke-filter-results" id="ke-filter-results" role="status" aria-live="polite"></div>
            </div>
            <div class="ke-filter-row ke-events-filters-panel" id="ke-filters-panel">
                <div class="ke-filter-field">
                    <label for="ke-f-organizer">Organizer</label>
                    <select id="ke-f-organizer">
                        <option value="">All organizers</option>
                        <?php foreach ( $filter_organizers as $tid => $tname ) : ?>
                        <option value="<?php echo (int) $tid; ?>"><?php echo esc_html( $tname ); ?></option>
                        <?php endforeach; ?>
                        <?php if ( $has_no_organizer ) : ?>
                        <option value="none">No organizer</option>
                        <?php endif; ?>
                    </select>
                </div>
                <?php if ( ! empty( $filter_categories ) ) : ?>
                <div class="ke-filter-field">
                    <label for="ke-f-category">Category</label>
                    <select id="ke-f-category">
                        <option value="">All categories</option>
                        <?php foreach ( $filter_categories as $tid => $tname ) : ?>
                        <option value="<?php echo (int) $tid; ?>"><?php echo esc_html( $tname ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="ke-filter-field">
                    <label for="ke-f-date">Event date</label>
                    <select id="ke-f-date">
                        <option value="">Any date</option>
                        <option value="upcoming">Upcoming</option>
                        <option value="today">Today</option>
                        <option value="week">This week</option>
                        <option value="month">This month</option>
                        <option value="past">Past</option>
                        <option value="custom">Custom range…</option>
                    </select>
                </div>
                <div class="ke-filter-field ke-filter-daterange" id="ke-f-range" hidden>
                    <label for="ke-f-from">From – To</label>
                    <div class="ke-filter-daterange-inputs">
                        <input type="date" id="ke-f-from" aria-label="From date">
                        <span aria-hidden="true">–</span>
                        <input type="date" id="ke-f-to" aria-label="To date">
                    </div>
                </div>
                <div class="ke-filter-field">
                    <label for="ke-f-status">Status</label>
                    <select id="ke-f-status">
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Draft / Paused</option>
                        <option value="soldout">Sold out</option>
                    </select>
                </div>
                <div class="ke-filter-field">
                    <label for="ke-f-sort">Sort by</label>
                    <select id="ke-f-sort">
                        <option value="created_desc">Newest created</option>
                        <option value="date_asc">Soonest event</option>
                        <option value="date_desc">Latest event</option>
                        <option value="title_asc">Title A–Z</option>
                        <option value="revenue_desc">Highest sales</option>
                        <option value="sold_desc">Most tickets sold</option>
                    </select>
                </div>
                <div class="ke-filter-field ke-filter-submit">
                    <button type="button" class="ke-btn ke-btn-ghost ke-filter-clear" id="ke-f-clear" hidden>Clear filters</button>
                </div>
            </div>
        </form>

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

                // Organizer / categories / venue — hoisted above the card so
                // the filter data attributes can use them (term cache is warm).
                $organizers = wp_get_post_terms( $event_id, 'ke_organizer' );
                $org        = ( ! empty( $organizers ) && ! is_wp_error( $organizers ) ) ? $organizers[0] : null;
                $cats       = wp_get_post_terms( $event_id, 'ke_event_category' );
                $cat_ids    = ( ! empty( $cats ) && ! is_wp_error( $cats ) ) ? wp_list_pluck( $cats, 'term_id' ) : array();
                $venue      = get_post_meta( $event_id, '_ke_event_venue', true );
                $event_ts   = $date_start ? (int) strtotime( $date_start ) : 0;
            ?>
                <div class="ke-event-card"
                     data-title="<?php echo esc_attr( $event->post_title ); ?>"
                     data-organizer="<?php echo esc_attr( $org ? $org->term_id : '' ); ?>"
                     data-organizer-name="<?php echo esc_attr( $org ? $org->name : '' ); ?>"
                     data-categories="<?php echo esc_attr( implode( ',', $cat_ids ) ); ?>"
                     data-venue="<?php echo esc_attr( $venue ); ?>"
                     data-date="<?php echo esc_attr( $event_ts ? date( 'Y-m-d', $event_ts ) : '' ); ?>"
                     data-ts="<?php echo (int) $event_ts; ?>"
                     data-created="<?php echo (int) strtotime( $event->post_date ); ?>"
                     data-status="<?php echo esc_attr( $display_status ); ?>"
                     data-soldout="<?php echo $is_sold_out ? '1' : '0'; ?>"
                     data-revenue="<?php echo esc_attr( (float) $revenue ); ?>"
                     data-sold="<?php echo (int) $tickets_sold; ?>">
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
                        if ( $org ) {
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

        <!-- Shown by the filter script when no card survives the filters. -->
        <div class="ke-empty-state ke-filter-empty" id="ke-filter-empty" hidden>
            <span class="ke-empty-state-icon">🔍</span>
            <h3>No events match your filters</h3>
            <p style="margin-bottom:20px;">Try a broader search, another date range, or clear everything.</p>
            <button type="button" class="ke-btn ke-btn-ghost ke-filter-clear" id="ke-f-clear-empty">Clear filters</button>
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

/* ═══ Events filter bar ═══════════════════════════════════════
   Reuses the shared .ke-filters field styles (ke-admin.css) and adds:
   a search row with a live results counter, styling for the search/date
   input types the shared sheet doesn't cover, and a phone layout where
   the panel collapses behind a "Filters" disclosure button.
   Every color resolves through a --kiwi-* token. */

/* [hidden] must beat the display rules filter elements carry. */
.ke-builder-wrap [hidden] { display: none !important; }

/* Self-sufficient sizing — don't depend on wp-admin's forms.css. */
.ke-events-filters input,
.ke-events-filters select,
.ke-events-filters button {
    box-sizing: border-box;
}

.ke-events-filters-top {
    display: flex;
    align-items: flex-end;
    gap: 12px;
    flex-wrap: wrap;
}
.ke-filter-search { flex: 1 1 240px; max-width: 420px; }
.ke-filter-search input { width: 100%; }

.ke-events-filters-panel { margin-top: 14px; }

/* Search + date inputs — mirror the shared text/select field look. */
.ke-events-filters input[type="search"],
.ke-events-filters input[type="date"] {
    padding: 9px 12px;
    border: 1px solid var(--kiwi-border);
    border-radius: var(--kiwi-radius-md);
    font-family: var(--ke-font);
    font-size: 13px;
    background: var(--kiwi-surface);
    color: var(--ke-text-1);
    transition: var(--ke-transition);
    box-shadow: 0 1px 2px var(--kiwi-shadow-1) inset;
}
.ke-events-filters input[type="search"] {
    -webkit-appearance: none;
    appearance: none;
}
.ke-events-filters input[type="search"]:focus,
.ke-events-filters input[type="date"]:focus {
    border-color: var(--kiwi-green);
    box-shadow:
        0 0 0 1px var(--kiwi-glass-border-edge),
        0 0 0 3px var(--kiwi-green-glow);
    outline: none;
}

.ke-filter-daterange-inputs {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--kiwi-text-muted);
}
.ke-filter-daterange-inputs input[type="date"] { min-width: 138px; }

.ke-filter-results {
    margin-left: auto;
    padding-bottom: 10px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--kiwi-text-muted);
    white-space: nowrap;
}

.ke-filter-clear { white-space: nowrap; }

/* "Filters" disclosure — phone only. */
.ke-filters-toggle {
    display: none;
    align-items: center;
    gap: 7px;
    padding: 11px 14px;
    border: 1px solid var(--kiwi-border);
    border-radius: var(--kiwi-radius-md);
    background: var(--kiwi-surface);
    color: var(--kiwi-text);
    font-family: inherit;
    font-size: 13px;
    font-weight: 600;
    line-height: 1;
    cursor: pointer;
    transition: background .15s var(--kiwi-ease), border-color .15s var(--kiwi-ease);
}
.ke-filters-toggle:hover { background: var(--kiwi-cream-deep); }
.ke-filters-toggle:focus-visible {
    border-color: var(--kiwi-green);
    box-shadow: 0 0 0 3px var(--kiwi-green-glow);
    outline: none;
}
.ke-filters-toggle[aria-expanded="true"] {
    background: var(--kiwi-green-tint);
    border-color: var(--kiwi-green-line);
    color: var(--kiwi-green-text);
}
.ke-filters-toggle-count {
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--kiwi-green);
    color: var(--kiwi-text);
    border-radius: var(--kiwi-radius-pill);
    font-size: 11px;
    font-weight: 800;
}

/* Cards the active filters reject. */
.ke-hidden-by-filter { display: none !important; }

/* Legacy 'paused' status meta — orange, same family as pending badges. */
.ke-badge-paused {
    background: var(--kiwi-orange-fill);
    color: var(--kiwi-orange-text-deep);
    border: 1px solid var(--kiwi-orange-edge-medium);
}

.ke-filter-empty { padding: 48px 24px; }

@media (max-width: 768px) {
    .ke-filter-search { flex-basis: 100%; max-width: none; }
    .ke-filters-toggle { display: inline-flex; }
    .ke-filter-results { padding-bottom: 12px; }
    /* Collapsed until toggled — the shared ≤768px rule stacks the fields.
       align-items:stretch overrides the row's flex-end, which in column
       direction would right-align (and overflow) every field. */
    .ke-events-filters .ke-events-filters-panel { display: none; align-items: stretch; }
    .ke-events-filters .ke-events-filters-panel.is-open { display: flex; }
    .ke-events-filters .ke-filter-field { width: 100%; }
    .ke-events-filters input[type="search"],
    .ke-events-filters input[type="date"] { min-width: 100%; }
    .ke-filter-daterange-inputs { width: 100%; }
    .ke-filter-daterange-inputs input[type="date"] { min-width: 0; flex: 1; }
    .ke-filter-submit { width: 100%; }
    .ke-filter-clear { width: 100%; }
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

/* ── Client-side filters — every event is already in the DOM
   (posts_per_page = -1), so filtering and sorting never hit the server.
   State mirrors into the URL (replaceState) so a filtered view can be
   shared, bookmarked, or reloaded. ── */
(function() {
    var grid = document.querySelector('.ke-events-card-grid');
    var form = document.getElementById('ke-events-filters');
    if (!grid || !form) return;

    // Preset boundaries computed in the SITE timezone (see PHP above).
    var bounds = <?php echo wp_json_encode( isset( $date_bounds ) ? $date_bounds : array() ); ?>;

    var searchEl  = document.getElementById('ke-f-search');
    var orgEl     = document.getElementById('ke-f-organizer');
    var catEl     = document.getElementById('ke-f-category'); // absent when no categories exist
    var dateEl    = document.getElementById('ke-f-date');
    var rangeEl   = document.getElementById('ke-f-range');
    var fromEl    = document.getElementById('ke-f-from');
    var toEl      = document.getElementById('ke-f-to');
    var statusEl  = document.getElementById('ke-f-status');
    var sortEl    = document.getElementById('ke-f-sort');
    var clearBtns = [document.getElementById('ke-f-clear'), document.getElementById('ke-f-clear-empty')];
    var resultsEl = document.getElementById('ke-filter-results');
    var emptyEl   = document.getElementById('ke-filter-empty');
    var toggleBtn = document.getElementById('ke-filters-toggle');
    var panelEl   = document.getElementById('ke-filters-panel');
    var countEl   = document.getElementById('ke-filters-toggle-count');

    // Remember the server order so "Newest created" restores it exactly.
    grid.querySelectorAll('.ke-event-card').forEach(function(card, i) {
        card.dataset.idx = i;
    });

    // Accent-insensitive: "peña" matches "Pena" and vice versa.
    function norm(s) {
        return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    function cards() {
        return Array.prototype.slice.call(grid.querySelectorAll('.ke-event-card'));
    }
    function numAttr(card, key) {
        return parseFloat(card.dataset[key]) || 0;
    }
    function state() {
        return {
            q:      norm(searchEl.value.trim()),
            org:    orgEl.value,
            cat:    catEl ? catEl.value : '',
            date:   dateEl.value,
            from:   fromEl.value,
            to:     toEl.value,
            status: statusEl.value,
            sort:   sortEl.value || 'created_desc'
        };
    }
    function panelCount(st) {
        var n = 0;
        if (st.org) n++;
        if (st.cat) n++;
        if (st.date) n++;
        if (st.status) n++;
        if (st.sort !== 'created_desc') n++;
        return n;
    }

    function dateOK(d, st) {
        if (!st.date) return true;
        if (st.date === 'custom') {
            if (!st.from && !st.to) return true; // nothing picked yet
            if (!d) return false;
            if (st.from && d < st.from) return false;
            if (st.to && d > st.to) return false;
            return true;
        }
        if (!d) return false; // "Date TBA" events only show under "Any date"
        switch (st.date) {
            case 'upcoming': return d >= bounds.today;
            case 'today':    return d === bounds.today;
            case 'week':     return d >= bounds.weekStart && d <= bounds.weekEnd;
            case 'month':    return d >= bounds.monthStart && d <= bounds.monthEnd;
            case 'past':     return d < bounds.today;
        }
        return true;
    }

    function matches(card, st) {
        var ds = card.dataset;
        if (st.q && norm(ds.title + ' ' + ds.organizerName + ' ' + ds.venue).indexOf(st.q) === -1) return false;
        if (st.org === 'none') {
            if (ds.organizer !== '') return false;
        } else if (st.org && ds.organizer !== st.org) {
            return false;
        }
        if (st.cat && (',' + ds.categories + ',').indexOf(',' + st.cat + ',') === -1) return false;
        if (st.status === 'soldout') {
            if (ds.soldout !== '1') return false;
        } else if (st.status === 'active') {
            if (ds.status !== 'active') return false;
        } else if (st.status && ds.status === 'active') {
            return false; // 'inactive' = draft or legacy paused
        }
        return dateOK(ds.date || '', st);
    }

    var sorters = {
        created_desc: function(a, b) { return numAttr(b, 'created') - numAttr(a, 'created'); },
        // Undated events (ts = 0) always sort last, in both directions.
        date_asc:     function(a, b) { return (numAttr(a, 'ts') || 9e12) - (numAttr(b, 'ts') || 9e12); },
        date_desc:    function(a, b) { return (numAttr(b, 'ts') || -1) - (numAttr(a, 'ts') || -1); },
        title_asc:    function(a, b) { return norm(a.dataset.title).localeCompare(norm(b.dataset.title)); },
        revenue_desc: function(a, b) { return numAttr(b, 'revenue') - numAttr(a, 'revenue'); },
        sold_desc:    function(a, b) { return numAttr(b, 'sold') - numAttr(a, 'sold'); }
    };

    function syncUrl(st) {
        try {
            var url = new URL(window.location.href);
            var params = {
                s:      searchEl.value.trim(),
                org:    st.org,
                cat:    st.cat,
                date:   st.date,
                from:   st.date === 'custom' ? st.from : '',
                to:     st.date === 'custom' ? st.to : '',
                status: st.status,
                sort:   st.sort === 'created_desc' ? '' : st.sort
            };
            Object.keys(params).forEach(function(k) {
                if (params[k]) { url.searchParams.set(k, params[k]); }
                else { url.searchParams.delete(k); }
            });
            history.replaceState(null, '', url.toString());
        } catch (e) { /* URL API unavailable — filters still work, just unshareable */ }
    }

    function apply() {
        var st = state();
        var list = cards();
        var shown = 0;

        list.forEach(function(card) {
            var ok = matches(card, st);
            card.classList.toggle('ke-hidden-by-filter', !ok);
            if (ok) shown++;
        });

        var cmp = sorters[st.sort] || sorters.created_desc;
        list.sort(function(a, b) {
            return cmp(a, b) || (numAttr(a, 'idx') - numAttr(b, 'idx'));
        }).forEach(function(card) { grid.appendChild(card); });

        resultsEl.textContent = (shown === list.length)
            ? list.length + (list.length === 1 ? ' event' : ' events')
            : shown + ' of ' + list.length + ' events';

        emptyEl.hidden = shown !== 0;

        var active = !!(st.q || st.org || st.cat || st.date || st.status) || st.sort !== 'created_desc';
        clearBtns.forEach(function(b) { if (b) b.hidden = !active; });

        var n = panelCount(st);
        countEl.hidden = n === 0;
        countEl.textContent = n;

        rangeEl.hidden = st.date !== 'custom';

        syncUrl(st);
    }

    function clearAll() {
        searchEl.value = '';
        orgEl.value = '';
        if (catEl) catEl.value = '';
        dateEl.value = '';
        fromEl.value = '';
        toEl.value = '';
        statusEl.value = '';
        sortEl.value = 'created_desc';
        apply();
    }

    form.addEventListener('submit', function(e) { e.preventDefault(); });
    searchEl.addEventListener('input', apply);
    [orgEl, catEl, dateEl, fromEl, toEl, statusEl, sortEl].forEach(function(el) {
        if (el) el.addEventListener('change', apply);
    });
    clearBtns.forEach(function(b) { if (b) b.addEventListener('click', clearAll); });

    toggleBtn.addEventListener('click', function() {
        var open = panelEl.classList.toggle('is-open');
        toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    // Keep the counter honest after a card is deleted. Our own sort
    // re-appends nodes (they stay connected), so only real removals count.
    new MutationObserver(function(muts) {
        var removed = muts.some(function(m) {
            return Array.prototype.some.call(m.removedNodes, function(n) {
                return n.nodeType === 1 && !n.isConnected;
            });
        });
        if (removed) apply();
    }).observe(grid, { childList: true });

    // Restore any shared/bookmarked filter state, then paint once.
    (function initFromUrl() {
        try {
            var p = new URL(window.location.href).searchParams;
            if (p.get('s')) searchEl.value = p.get('s');
            if (p.get('org')) orgEl.value = p.get('org');
            if (catEl && p.get('cat')) catEl.value = p.get('cat');
            if (p.get('date')) dateEl.value = p.get('date');
            if (p.get('from')) fromEl.value = p.get('from');
            if (p.get('to')) toEl.value = p.get('to');
            if (p.get('status')) statusEl.value = p.get('status');
            if (p.get('sort')) sortEl.value = p.get('sort');
            // An unknown value leaves a select at '' — snap sort back to default.
            if (!sortEl.value) sortEl.value = 'created_desc';
            if (dateEl.value !== 'custom') { fromEl.value = ''; toEl.value = ''; }
            // Open the phone panel when a shared link carries panel filters.
            if (panelCount(state()) > 0 && window.matchMedia('(max-width: 768px)').matches) {
                panelEl.classList.add('is-open');
                toggleBtn.setAttribute('aria-expanded', 'true');
            }
        } catch (e) { /* no restorable state */ }
        apply();
    })();
})();
</script>
