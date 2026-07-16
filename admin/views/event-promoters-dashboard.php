<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Per-event promoters dashboard (CHANGE 4).
 *
 * Variables provided by KE_Admin_Promoters::render_event_dashboard():
 *   $event     — WP_Post (ke_event)
 *   $event_id  — int
 *   $perf      — array of row objects (event_promoter_performance)
 *   $totals    — { tickets, owed, paid, total, active_promoters }
 *   $top3      — first 3 of $perf
 *   $recent    — recent activity rows
 *   $flash     — flash array or null
 *   $currency  — string
 */

$base_promoters = admin_url( 'admin.php?page=ke-promoters' );
$events_list    = admin_url( 'admin.php?page=ke-events' );

$event_date = get_post_meta( $event_id, '_ke_event_date_start', true );
$event_date_label = $event_date ? date_i18n( 'M j, Y', strtotime( $event_date ) ) : '';

// Search/filter for the table.
$search = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';
$filtered = $perf;
if ( $search !== '' ) {
    $needle = mb_strtolower( $search );
    $filtered = array_values( array_filter( $perf, function ( $r ) use ( $needle ) {
        return mb_stripos( (string) $r->name, $needle ) !== false
            || mb_stripos( (string) $r->email, $needle ) !== false
            || mb_stripos( (string) $r->slug, $needle ) !== false;
    } ) );
}

$rest_url   = esc_url( rest_url( 'kiwi-events/v1/promoters/event/' . $event_id . '/activity' ) );
$rest_nonce = wp_create_nonce( 'wp_rest' );
?>
<div class="wrap ke-builder-wrap">

    <?php if ( $flash ) : ?>
        <div class="notice notice-<?php echo esc_attr( $flash['type'] === 'success' ? 'success' : 'error' ); ?> is-dismissible">
            <p><?php echo esc_html( $flash['message'] ); ?></p>
        </div>
    <?php endif; ?>

    <!-- Header banner — own white section card -->
    <div class="ke-section-card ke-section-card--compact">
        <div class="ke-builder-header">
            <div class="ke-builder-title">
                <h1>Promoters · <?php echo esc_html( $event->post_title ); ?></h1>
                <p style="margin:4px 0 0; color:var(--kiwi-text-muted); font-size:13px;">
                    <?php if ( $event_date_label ) : ?>📅 <?php echo esc_html( $event_date_label ); ?> · <?php endif; ?>
                    <?php echo (int) $totals['active_promoters']; ?> active promoter<?php echo $totals['active_promoters'] === 1 ? '' : 's'; ?>
                </p>
            </div>
            <div class="ke-builder-actions">
                <a href="<?php echo esc_url( $events_list ); ?>" class="ke-btn ke-btn-ghost">← Events list</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ke-event-builder&event_id=' . $event_id ) ); ?>" class="ke-btn ke-btn-ghost">Edit event</a>
            </div>
        </div>
    </div>

    <!-- KPI cards — wrapped in one white section card -->
    <div class="ke-section-card">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
            <?php
            $kpis = array(
                array( 'Total raised',     $currency . number_format( $totals['total'], 2 ) ),
                array( 'Owed',             $currency . number_format( $totals['owed'],  2 ) ),
                array( 'Paid out',         $currency . number_format( $totals['paid'],  2 ) ),
                array( 'Active promoters', number_format_i18n( $totals['active_promoters'] ) ),
            );
            foreach ( $kpis as $k ) : ?>
                <div style="padding:14px 16px; background:var(--kiwi-cream); border:1px solid var(--kiwi-border); border-radius:var(--kiwi-radius-md);">
                    <div style="font-size:11px; color:var(--kiwi-text-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.04em;">
                        <?php echo esc_html( $k[0] ); ?>
                    </div>
                    <div style="font-size:22px; font-weight:700; color:var(--kiwi-text); margin-top:4px; font-variant-numeric:tabular-nums;">
                        <?php echo esc_html( $k[1] ); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Top 3 podium — own white section card -->
    <?php if ( ! empty( $top3 ) ) : ?>
        <div class="ke-section-card">
            <h2 style="margin:0 0 12px; font-size:15px;">Top performers</h2>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px;">
                <?php
                $rank_labels = array( '🥇 1st', '🥈 2nd', '🥉 3rd' );
                foreach ( $top3 as $i => $row ) :
                    if ( (float) $row->total <= 0 ) continue;
                ?>
                    <div style="border:1px solid var(--kiwi-glass-border); border-radius:var(--kiwi-radius-md); padding:12px 14px; background:var(--kiwi-glass-bg); box-shadow:0 0 0 1px var(--kiwi-glass-border-edge);">
                        <div style="font-size:11px; color:var(--kiwi-text-muted); font-weight:600;">
                            <?php echo esc_html( $rank_labels[ $i ] ?? ( '#' . ( $i + 1 ) ) ); ?>
                        </div>
                        <div style="font-size:15px; font-weight:700; color:var(--kiwi-text); margin-top:2px;">
                            <?php echo esc_html( $row->name ); ?>
                        </div>
                        <div style="font-size:12px; color:var(--kiwi-text-muted); margin-top:2px;">
                            <?php echo (int) $row->tickets_sold; ?> ticket<?php echo $row->tickets_sold === 1 ? '' : 's'; ?>
                            · <?php echo esc_html( $currency . number_format( (float) $row->total, 2 ) ); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Recent activity feed (polls every 30s). Method badges now use Kiwi
         tokens; the four methods map to:
           session → Kiwi green (the happy-path live capture)
           cookie  → Apple-orange tint (warning-ish: stale capture)
           admin   → Apple-blue tint (informational override)
           manual  → cream-deep neutral (audit / reconcile) -->
    <div class="ke-section-card ke-section-card--flush">
        <div style="padding:14px 18px; border-bottom:1px solid var(--kiwi-border); display:flex; justify-content:space-between; align-items:center;">
            <h2 style="margin:0; font-size:15px;">Recent activity</h2>
            <span id="ke-activity-pulse" style="font-size:11px; color:var(--kiwi-text-muted);">
                Live · updated <span id="ke-activity-relative">just now</span>
            </span>
        </div>
        <ul id="ke-activity-list" style="list-style:none; margin:0; padding:0; min-height:60px;">
            <?php if ( empty( $recent ) ) : ?>
                <li style="padding:24px; text-align:center; color:var(--kiwi-text-muted); font-size:13px;">
                    No commission activity for this event yet.
                </li>
            <?php else : ?>
                <?php
                $method_styles = array(
                    'session' => array( 'label' => 'session', 'bg' => 'var(--kiwi-green-tint)',       'fg' => 'var(--kiwi-green-text)', 'title' => 'Captured live via WooCommerce session' ),
                    'cookie'  => array( 'label' => 'cookie',  'bg' => 'var(--kiwi-orange-fill-alt)',  'fg' => 'var(--kiwi-orange-text)',         'title' => 'Recovered from the 30-day promoter cookie after session expired' ),
                    'admin'   => array( 'label' => 'admin',   'bg' => 'var(--kiwi-blue-fill-faint)',  'fg' => 'var(--kiwi-legacy-blue-deep)',    'title' => 'Assigned by an admin' ),
                    'manual'  => array( 'label' => 'manual',  'bg' => 'var(--kiwi-cream-deep)',       'fg' => 'var(--kiwi-text-muted)', 'title' => 'Manually attributed through the Reconcile tool' ),
                );
                foreach ( $recent as $r ) :
                    $method     = (string) ( $r->attribution_method ?? 'session' );
                    $m          = $method_styles[ $method ] ?? $method_styles['session'];
                ?>
                    <li style="padding:10px 18px; border-bottom:1px solid var(--kiwi-border); display:flex; justify-content:space-between; gap:12px;">
                        <div style="min-width:0;">
                            <span style="font-weight:600; color:var(--kiwi-text);">
                                <?php echo esc_html( $r->promoter_name ?: '(deleted promoter)' ); ?>
                            </span>
                            <span style="color:var(--kiwi-text-muted);">
                                · <?php echo esc_html( $r->buyer_name ?: '—' ); ?>
                            </span>
                            <span title="<?php echo esc_attr( $m['title'] ); ?>"
                                  style="display:inline-block; margin-left:8px; padding:1px 7px; border-radius:var(--kiwi-radius-pill); font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.03em; background:<?php echo esc_attr( $m['bg'] ); ?>; color:<?php echo esc_attr( $m['fg'] ); ?>;">
                                <?php echo esc_html( $m['label'] ); ?>
                            </span>
                        </div>
                        <div style="white-space:nowrap; color:var(--kiwi-text); font-variant-numeric:tabular-nums;">
                            <strong><?php echo esc_html( $currency . number_format( (float) $r->commission_amount, 2 ) ); ?></strong>
                            <span style="font-size:11px; color:var(--kiwi-text-muted); margin-left:6px;" class="ke-activity-time" data-ts="<?php echo (int) strtotime( $r->created_at . ' UTC' ); ?>">
                                <?php echo esc_html( human_time_diff( strtotime( $r->created_at ), current_time( 'timestamp' ) ) ); ?> ago
                            </span>
                        </div>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>

    <!-- Action bar — own white section card -->
    <div class="ke-section-card ke-section-card--compact" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
        <form method="GET" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="flex:1 1 240px; display:flex; gap:8px;">
            <input type="hidden" name="page" value="ke-promoters">
            <input type="hidden" name="action" value="event_dashboard">
            <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>">
            <input type="search" name="q" value="<?php echo esc_attr( $search ); ?>" placeholder="Search promoters in this event…"
                   class="ke-field"
                   style="flex:1; font-size:13px;">
            <button class="ke-btn ke-btn-primary" style="padding:7px 14px; font-size:13px;">Search</button>
            <?php if ( $search ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ke-promoters&action=event_dashboard&event_id=' . $event_id ) ); ?>" class="ke-btn ke-btn-ghost" style="padding:7px 12px; font-size:13px;">Clear</a>
            <?php endif; ?>
        </form>

        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ke-event-builder&event_id=' . $event_id . '#ke-promoters-editor' ) ); ?>" class="ke-btn ke-btn-ghost" style="padding:7px 12px; font-size:13px;">+ Add promoters</a>

        <?php
        $csv_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=ke_event_export_csv&event_id=' . $event_id ),
            'ke_event_export_csv_' . $event_id
        );
        $pdf_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=ke_event_export_pdf&event_id=' . $event_id ),
            'ke_event_export_pdf_' . $event_id
        );
        ?>
        <a href="<?php echo esc_url( $csv_url ); ?>" class="ke-btn ke-btn-ghost" style="padding:7px 12px; font-size:13px;">Export CSV</a>
        <a href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" rel="noopener" class="ke-btn ke-btn-ghost" style="padding:7px 12px; font-size:13px;">Export PDF</a>

        <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              onsubmit="return confirm('Mark every still-owed commission for this event as paid?');"
              style="display:inline;">
            <input type="hidden" name="action" value="ke_event_mark_all_paid">
            <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>">
            <?php wp_nonce_field( 'ke_event_mark_all_paid_' . $event_id ); ?>
            <button class="ke-btn ke-btn-primary" style="padding:7px 12px; font-size:13px;">Mark all owed as paid</button>
        </form>
    </div>

    <!-- Full promoter performance table — own white section card -->
    <?php if ( empty( $filtered ) ) : ?>
        <div class="ke-section-card" style="padding:48px 24px; text-align:center; color:var(--kiwi-text-muted);">
            <?php if ( $search !== '' ) : ?>
                <p style="margin:0;">No promoters match "<?php echo esc_html( $search ); ?>".</p>
            <?php else : ?>
                <p style="margin:0 0 8px;">No promoters assigned to this event yet.</p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ke-event-builder&event_id=' . $event_id . '#ke-promoters-editor' ) ); ?>"
                   class="ke-btn ke-btn-primary" style="padding:7px 14px; font-size:13px;">+ Add promoters</a>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <div class="ke-section-card ke-section-card--flush">
            <div style="overflow-x:auto;">
                <table class="ke-table" style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr>
                            <th>Promoter</th>
                            <th>Status</th>
                            <th>Rate</th>
                            <th style="text-align:right;">Tickets</th>
                            <th style="text-align:right;">Owed</th>
                            <th style="text-align:right;">Paid</th>
                            <th style="text-align:right;">Total</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $filtered as $r ) :
                        $rate_label = $r->commission_type === 'fixed'
                            ? $currency . number_format( (float) $r->commission_value, 2 ) . ' / ticket'
                            : number_format( (float) $r->commission_value, 2 ) . '%';
                        // Status colors → Kiwi tokens (active=green, pending=Apple orange, else=neutral)
                        if ( $r->status === 'active' )       { $bg = 'var(--kiwi-green-tint)';       $color = 'var(--kiwi-green-text)'; $border = 'var(--kiwi-green-line)'; }
                        elseif ( $r->status === 'pending' )  { $bg = 'var(--kiwi-orange-fill-alt)';  $color = 'var(--kiwi-orange-text)'; $border = 'var(--kiwi-orange-edge-soft)'; }
                        else                                 { $bg = 'var(--kiwi-cream-deep)';       $color = 'var(--kiwi-text-muted)'; $border = 'var(--kiwi-border)'; }
                    ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( $base_promoters . '&action=edit&id=' . (int) $r->promoter_id ); ?>"
                                   style="color:var(--kiwi-text); font-weight:600; text-decoration:none;">
                                    <?php echo esc_html( $r->name ); ?>
                                </a>
                                <div style="font-size:11px; color:var(--kiwi-text-muted);"><?php echo esc_html( $r->email ); ?></div>
                            </td>
                            <td>
                                <span style="display:inline-block; padding:2px 9px; border-radius:var(--kiwi-radius-pill); font-size:11px; font-weight:600; text-transform:uppercase; background:<?php echo esc_attr( $bg ); ?>; color:<?php echo esc_attr( $color ); ?>; border:1px solid <?php echo esc_attr( $border ); ?>;">
                                    <?php echo esc_html( $r->status ); ?>
                                </span>
                            </td>
                            <td style="color:var(--kiwi-text-muted);"><?php echo esc_html( $rate_label ); ?></td>
                            <td style="text-align:right; font-variant-numeric:tabular-nums;"><?php echo (int) $r->tickets_sold; ?></td>
                            <td style="text-align:right; color:var(--kiwi-text); font-variant-numeric:tabular-nums; font-weight:600;">
                                <?php echo esc_html( $currency . number_format( (float) $r->owed, 2 ) ); ?>
                            </td>
                            <td style="text-align:right; color:var(--kiwi-text-muted); font-variant-numeric:tabular-nums;">
                                <?php echo esc_html( $currency . number_format( (float) $r->paid, 2 ) ); ?>
                            </td>
                            <td style="text-align:right; color:var(--kiwi-text); font-variant-numeric:tabular-nums; font-weight:600;">
                                <?php echo esc_html( $currency . number_format( (float) $r->total, 2 ) ); ?>
                            </td>
                            <td style="text-align:right; white-space:nowrap;">
                                <a href="<?php echo esc_url( $base_promoters . '&action=commissions&id=' . (int) $r->promoter_id ); ?>" class="ke-btn ke-btn-ghost" style="font-size:11px; padding:4px 9px;">Details</a>
                                <a href="<?php echo esc_url( $base_promoters . '&action=active_events&id=' . (int) $r->promoter_id ); ?>" class="ke-btn ke-btn-ghost" style="font-size:11px; padding:4px 9px; margin-left:3px;">Links</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var REST = '<?php echo $rest_url; ?>';
    var NONCE = '<?php echo esc_js( $rest_nonce ); ?>';

    function relativeTime( ts ) {
        var diff = Math.floor( Date.now() / 1000 ) - ts;
        if ( diff < 60 )    return diff + 's ago';
        if ( diff < 3600 )  return Math.floor( diff / 60 ) + 'm ago';
        if ( diff < 86400 ) return Math.floor( diff / 3600 ) + 'h ago';
        return Math.floor( diff / 86400 ) + 'd ago';
    }

    function refreshRelative() {
        document.querySelectorAll('.ke-activity-time').forEach(function (el) {
            var ts = parseInt( el.dataset.ts || '0', 10 );
            if ( ts ) el.textContent = relativeTime( ts );
        });
    }

    // Method badge palette mirrors the PHP-rendered initial pass — Kiwi green
    // for the happy-path live capture, Apple-orange for stale cookie recovery,
    // Apple-blue for admin overrides, neutral cream for manual reconciles.
    var METHOD_STYLES = {
        session: { label: 'session', bg: 'var(--kiwi-green-tint)',  fg: 'var(--kiwi-green-text)', title: 'Captured live via WooCommerce session' },
        cookie:  { label: 'cookie',  bg: 'var(--kiwi-orange-fill-alt)',  fg: 'var(--kiwi-orange-text)',          title: 'Recovered from the 30-day promoter cookie after session expired' },
        admin:   { label: 'admin',   bg: 'var(--kiwi-blue-fill-faint)',  fg: 'var(--kiwi-legacy-blue-deep)',     title: 'Assigned by an admin' },
        manual:  { label: 'manual',  bg: 'var(--kiwi-cream-deep)',  fg: 'var(--kiwi-text-muted)', title: 'Manually attributed through the Reconcile tool' }
    };

    function methodBadge( method ) {
        var m = METHOD_STYLES[ method ] || METHOD_STYLES.session;
        return '<span title="' + escapeHtml( m.title ) + '"'
             + ' style="display:inline-block; margin-left:8px; padding:1px 7px; border-radius:var(--kiwi-radius-pill); font-size:10px;'
             + ' font-weight:700; text-transform:uppercase; letter-spacing:0.03em;'
             + ' background:' + m.bg + '; color:' + m.fg + ';">'
             + escapeHtml( m.label ) + '</span>';
    }

    function render( rows ) {
        var list = document.getElementById('ke-activity-list');
        if ( ! list ) return;
        if ( ! rows.length ) {
            list.innerHTML = '<li style="padding:24px; text-align:center; color:var(--kiwi-text-muted); font-size:13px;">No commission activity for this event yet.</li>';
            return;
        }
        var html = '';
        rows.forEach(function ( r ) {
            html += '<li style="padding:10px 18px; border-bottom:1px solid var(--kiwi-border); display:flex; justify-content:space-between; gap:12px;">'
                  + '<div style="min-width:0;">'
                  + '<span style="font-weight:600; color:var(--kiwi-text);">' + escapeHtml( r.promoter_name || '(deleted promoter)' ) + '</span>'
                  + '<span style="color:var(--kiwi-text-muted);"> · ' + escapeHtml( r.buyer_name || '—' ) + '</span>'
                  + methodBadge( r.attribution_method || 'session' )
                  + '</div>'
                  + '<div style="white-space:nowrap; color:var(--kiwi-text); font-variant-numeric:tabular-nums;">'
                  + '<strong>' + escapeHtml( r.commission_label ) + '</strong>'
                  + '<span style="font-size:11px; color:var(--kiwi-text-muted); margin-left:6px;" class="ke-activity-time" data-ts="' + r.created_at_ts + '">' + relativeTime( r.created_at_ts ) + '</span>'
                  + '</div></li>';
        });
        list.innerHTML = html;
    }

    function escapeHtml( s ) {
        return String( s ).replace(/[&<>"']/g, function ( c ) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ c ];
        });
    }

    function poll() {
        fetch( REST, { credentials: 'same-origin', headers: { 'X-WP-Nonce': NONCE } })
            .then(function ( r ) { return r.ok ? r.json() : null; })
            .then(function ( data ) {
                if ( ! data || ! data.rows ) return;
                render( data.rows );
                var rel = document.getElementById('ke-activity-relative');
                if ( rel ) rel.textContent = 'just now';
            })
            .catch(function () { /* silent */ });
    }

    setInterval( refreshRelative, 30000 );
    setInterval( poll,            30000 );
})();
</script>
