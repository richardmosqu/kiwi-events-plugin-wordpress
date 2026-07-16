<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Per-promoter commissions view.
 *
 * Variables provided by KE_Admin_Promoters::render_commissions():
 *   $row           — promoters row (object)
 *   $rows          — commission rows for this page
 *   $total_rows    — int
 *   $total_pages   — int
 *   $page_num      — int
 *   $per_page      — int
 *   $status_filter — '' | earned | paid | refunded_keep | voided
 *   $totals        — totals_for_promoter() output
 *   $flash         — [type, message] | null
 */

$base_url  = admin_url( 'admin.php?page=ke-promoters' );
$form_url  = $base_url . '&action=edit&id=' . (int) $row->id;
$this_url  = $base_url . '&action=commissions&id=' . (int) $row->id;

$who         = KE_Promoter_Attribution::display_for( $row );
$row_name    = $who['name']  !== '' ? $who['name']  : (string) $row->slug;
$row_email   = $who['email'];

$status_labels = array(
    'earned'        => array( 'Earned',        'var(--kiwi-legacy-green-pill-bg)', 'var(--kiwi-legacy-emerald-800)' ),
    'paid'          => array( 'Paid',          'var(--kiwi-legacy-blue-pending-bg)', 'var(--kiwi-legacy-blue-800)' ),
    'refunded_keep' => array( 'Refunded — kept', 'var(--kiwi-legacy-amber-50)', 'var(--kiwi-legacy-amber-800)' ),
    'voided'        => array( 'Voided',        'var(--kiwi-legacy-red-50)', 'var(--kiwi-legacy-red-800)' ),
);

$method_styles = array(
    'session' => array( 'Session', 'var(--kiwi-legacy-blue-pending-bg)', 'var(--kiwi-legacy-blue-800)', 'Captured live via WooCommerce session' ),
    'cookie'  => array( 'Cookie',  'var(--kiwi-legacy-amber-50)', 'var(--kiwi-legacy-amber-800)', 'Recovered from the 30-day promoter cookie after session expired' ),
    'admin'   => array( 'Admin',   'var(--kiwi-legacy-violet-100)', 'var(--kiwi-legacy-violet-800)', 'Assigned by an admin' ),
    'manual'  => array( 'Manual',  'var(--kiwi-legacy-pink-100)', 'var(--kiwi-legacy-pink-800)', 'Manually attributed through the Reconcile tool' ),
);
?>
<div class="wrap ke-builder-wrap">

    <?php if ( $flash ) : ?>
        <div class="notice notice-<?php echo esc_attr( $flash['type'] === 'success' ? 'success' : 'error' ); ?> is-dismissible">
            <p><?php echo esc_html( $flash['message'] ); ?></p>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="ke-builder-header">
        <div class="ke-builder-title">
            <h1>Commissions — <?php echo esc_html( $row_name ); ?></h1>
            <p style="margin:4px 0 0; color:var(--kiwi-legacy-text-muted); font-size:13px;">
                <?php if ( $row_email !== '' ) : ?>
                    <?php echo esc_html( $row_email ); ?> ·
                <?php endif; ?>
                <code style="font-size:11px; background:var(--kiwi-legacy-row-bg); padding:1px 6px; border-radius:4px;"><?php echo esc_html( $row->slug ); ?></code>
            </p>
        </div>
        <div class="ke-builder-actions">
            <a href="<?php echo esc_url( $form_url ); ?>" class="ke-btn ke-btn-ghost">← Back to promoter</a>
            <a href="<?php echo esc_url( $base_url ); ?>" class="ke-btn ke-btn-ghost">All promoters</a>
        </div>
    </div>

    <!-- KPIs -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:12px; margin-bottom:18px;">
        <?php
        $kpis = array(
            array( 'Tickets sold',  (int) $totals['tickets'] ),
            array( 'Owed',          '$' . number_format( (float) $totals['owed'],  2 ) ),
            array( 'Paid',          '$' . number_format( (float) $totals['paid'],  2 ) ),
            array( 'Lifetime',      '$' . number_format( (float) $totals['total'], 2 ) ),
        );
        foreach ( $kpis as $k ) : ?>
            <div class="ke-card" style="padding:14px 16px;">
                <div style="font-size:11px; color:var(--kiwi-legacy-text-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:4px;">
                    <?php echo esc_html( $k[0] ); ?>
                </div>
                <div style="font-size:22px; font-weight:700; color:var(--kiwi-legacy-text-darkest); font-variant-numeric:tabular-nums;">
                    <?php echo esc_html( (string) $k[1] ); ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Filter bar -->
    <form method="GET" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"
          class="ke-card" style="padding:12px 14px; margin-bottom:14px; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
        <input type="hidden" name="page"   value="ke-promoters">
        <input type="hidden" name="action" value="commissions">
        <input type="hidden" name="id"     value="<?php echo (int) $row->id; ?>">

        <label style="font-size:12px; color:var(--kiwi-legacy-text-mid); font-weight:600;">Status</label>
        <select name="cstatus" style="padding:7px 10px; border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:8px; font-size:13px;">
            <option value="">All</option>
            <?php foreach ( $status_labels as $sk => $sl ) : ?>
                <option value="<?php echo esc_attr( $sk ); ?>" <?php selected( $status_filter, $sk ); ?>><?php echo esc_html( $sl[0] ); ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="ke-btn ke-btn-primary" style="padding:7px 14px;">Apply</button>
        <?php if ( $status_filter !== '' ) : ?>
            <a href="<?php echo esc_url( $this_url ); ?>" class="ke-btn ke-btn-ghost" style="padding:7px 12px;">Clear</a>
        <?php endif; ?>

        <span style="flex:1;"></span>
        <?php
            $export_args = array(
                'action' => 'ke_export_promoter_commissions',
                'id'     => (int) $row->id,
            );
            if ( $status_filter !== '' ) $export_args['cstatus'] = $status_filter;
            $export_url = wp_nonce_url(
                add_query_arg( $export_args, admin_url( 'admin-post.php' ) ),
                'ke_export_promoter_commissions_' . (int) $row->id
            );
        ?>
        <a href="<?php echo esc_url( $export_url ); ?>" class="ke-btn ke-btn-ghost" style="padding:7px 12px;" title="Download as CSV">
            ⬇ Export CSV
        </a>
        <span style="font-size:12px; color:var(--kiwi-legacy-text-muted);"><?php echo (int) $total_rows; ?> rows</span>
    </form>

    <?php if ( empty( $rows ) ) : ?>
        <div class="ke-card" style="padding:40px 24px; text-align:center; color:var(--kiwi-legacy-text-muted);">
            No commissions <?php echo $status_filter !== '' ? 'match this filter' : 'recorded yet'; ?>.
        </div>
    <?php else :
        $can_mark = in_array( $status_filter, array( '', 'earned', 'refunded_keep' ), true );
    ?>
        <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              class="ke-card" style="padding:0; overflow:hidden;">
            <input type="hidden" name="action"      value="ke_mark_commissions_paid">
            <input type="hidden" name="promoter_id" value="<?php echo (int) $row->id; ?>">
            <?php wp_nonce_field( 'ke_mark_commissions_paid_' . (int) $row->id ); ?>

            <div style="overflow-x:auto;">
                <table class="ke-table" style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:var(--kiwi-legacy-page-bg); text-align:left;">
                            <?php if ( $can_mark ) : ?>
                                <th style="padding:12px 12px 12px 16px; width:28px;">
                                    <input type="checkbox" id="ke-comm-check-all" title="Select all on this page">
                                </th>
                            <?php endif; ?>
                            <th style="padding:12px 16px; font-weight:600; color:var(--kiwi-legacy-text-mid); white-space:nowrap;">Date</th>
                            <th style="padding:12px 16px; font-weight:600; color:var(--kiwi-legacy-text-mid); white-space:nowrap;">Event</th>
                            <th style="padding:12px 16px; font-weight:600; color:var(--kiwi-legacy-text-mid); white-space:nowrap;">Buyer</th>
                            <th style="padding:12px 16px; font-weight:600; color:var(--kiwi-legacy-text-mid); white-space:nowrap; text-align:right;">Base</th>
                            <th style="padding:12px 16px; font-weight:600; color:var(--kiwi-legacy-text-mid); white-space:nowrap; text-align:right;">Commission</th>
                            <th style="padding:12px 16px; font-weight:600; color:var(--kiwi-legacy-text-mid); white-space:nowrap;">Status</th>
                            <th style="padding:12px 16px; font-weight:600; color:var(--kiwi-legacy-text-mid); white-space:nowrap;" title="How this commission was attributed to the promoter">Source</th>
                            <th style="padding:12px 16px; font-weight:600; color:var(--kiwi-legacy-text-mid); white-space:nowrap;">Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $c ) :
                            $st  = (string) $c->status;
                            $lbl = $status_labels[ $st ] ?? array( $st, 'var(--kiwi-legacy-row-bg)', 'var(--kiwi-legacy-text-mid)' );
                            $ev_title = get_the_title( (int) $c->event_id );
                            if ( ! $ev_title ) $ev_title = '(deleted event)';
                            $is_selectable = in_array( $st, array( 'earned', 'refunded_keep' ), true );
                        ?>
                            <tr style="border-top:1px solid var(--kiwi-legacy-row-bg-alt);">
                                <?php if ( $can_mark ) : ?>
                                    <td style="padding:12px 12px 12px 16px;">
                                        <?php if ( $is_selectable ) : ?>
                                            <input type="checkbox" name="commission_ids[]" value="<?php echo (int) $c->id; ?>" class="ke-comm-check">
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td style="padding:12px 16px; color:var(--kiwi-legacy-text-mid); white-space:nowrap;">
                                    <?php echo esc_html( date( 'M j, Y', strtotime( $c->created_at ) ) ); ?>
                                </td>
                                <td style="padding:12px 16px; color:var(--kiwi-legacy-text-darkest); max-width:280px; overflow:hidden; text-overflow:ellipsis;">
                                    <?php echo esc_html( $ev_title ); ?>
                                </td>
                                <td style="padding:12px 16px; color:var(--kiwi-legacy-text-mid);">
                                    <?php echo esc_html( $c->buyer_name ?: '—' ); ?>
                                    <?php if ( $c->buyer_email ) : ?>
                                        <div style="font-size:11px; color:var(--kiwi-legacy-text-faint);"><?php echo esc_html( $c->buyer_email ); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:12px 16px; text-align:right; color:var(--kiwi-legacy-text-mid); font-variant-numeric:tabular-nums;">
                                    $<?php echo number_format( (float) $c->ticket_base_price, 2 ); ?>
                                </td>
                                <td style="padding:12px 16px; text-align:right; color:var(--kiwi-legacy-text-darkest); font-weight:600; font-variant-numeric:tabular-nums;">
                                    $<?php echo number_format( (float) $c->commission_amount, 2 ); ?>
                                    <div style="font-size:11px; color:var(--kiwi-legacy-text-faint); font-weight:400;">
                                        <?php echo $c->commission_type === 'percentage'
                                            ? number_format( (float) $c->commission_value, 2 ) . '%'
                                            : '$' . number_format( (float) $c->commission_value, 2 ) . ' fixed'; ?>
                                    </div>
                                </td>
                                <td style="padding:12px 16px; white-space:nowrap;">
                                    <span style="display:inline-block; padding:3px 9px; border-radius:10px; font-size:11px; font-weight:600; text-transform:uppercase; background:<?php echo esc_attr( $lbl[1] ); ?>; color:<?php echo esc_attr( $lbl[2] ); ?>;">
                                        <?php echo esc_html( $lbl[0] ); ?>
                                    </span>
                                </td>
                                <td style="padding:12px 16px; white-space:nowrap;">
                                    <?php
                                    $method = (string) ( $c->attribution_method ?? 'session' );
                                    $ml     = $method_styles[ $method ] ?? array( $method, 'var(--kiwi-legacy-row-bg)', 'var(--kiwi-legacy-text-mid)', '' );
                                    ?>
                                    <span title="<?php echo esc_attr( $ml[3] ); ?>"
                                          style="display:inline-block; padding:2px 8px; border-radius:8px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.03em; background:<?php echo esc_attr( $ml[1] ); ?>; color:<?php echo esc_attr( $ml[2] ); ?>;">
                                        <?php echo esc_html( $ml[0] ); ?>
                                    </span>
                                </td>
                                <td style="padding:12px 16px; color:var(--kiwi-legacy-text-muted); white-space:nowrap;">
                                    <?php if ( $c->paid_at && $c->paid_at !== '0000-00-00 00:00:00' ) : ?>
                                        <?php echo esc_html( date( 'M j, Y', strtotime( $c->paid_at ) ) ); ?>
                                        <?php if ( ! empty( $c->paid_note ) ) : ?>
                                            <div style="font-size:11px; color:var(--kiwi-legacy-text-faint);" title="<?php echo esc_attr( $c->paid_note ); ?>">
                                                <?php echo esc_html( wp_trim_words( $c->paid_note, 6 ) ); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ( $can_mark ) : ?>
                <div style="padding:14px 16px; border-top:1px solid var(--kiwi-legacy-row-bg-alt); background:var(--kiwi-legacy-page-bg); display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <input type="text" name="paid_note"
                           placeholder="Optional payout note (e.g. wire ref, payment method)"
                           style="flex:1 1 240px; min-width:0; padding:8px 12px; border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:8px; font-size:13px;">
                    <button type="submit"
                            onclick="return confirm('Mark the selected commissions as paid? This sets paid_at and cannot be undone from this screen.');"
                            class="ke-btn ke-btn-primary"
                            style="padding:8px 16px;">
                        Mark selected as paid
                    </button>
                </div>
            <?php endif; ?>
        </form>

        <?php if ( $total_pages > 1 ) :
            $base_q = array(
                'page'    => 'ke-promoters',
                'action'  => 'commissions',
                'id'      => (int) $row->id,
            );
            if ( $status_filter !== '' ) $base_q['cstatus'] = $status_filter;
            $prev = max( 1, $page_num - 1 );
            $next = min( $total_pages, $page_num + 1 );
            $prev_url = add_query_arg( array_merge( $base_q, array( 'paged' => $prev ) ), admin_url( 'admin.php' ) );
            $next_url = add_query_arg( array_merge( $base_q, array( 'paged' => $next ) ), admin_url( 'admin.php' ) );
        ?>
            <div style="display:flex; justify-content:center; align-items:center; gap:6px; padding:18px 0;">
                <a href="<?php echo esc_url( $prev_url ); ?>" class="ke-btn ke-btn-ghost" style="padding:6px 12px; font-size:12px; <?php echo $page_num <= 1 ? 'pointer-events:none; opacity:0.4;' : ''; ?>">‹ Prev</a>
                <span style="font-size:13px; color:var(--kiwi-legacy-text-mid); padding:0 8px;">
                    Page <?php echo (int) $page_num; ?> of <?php echo (int) $total_pages; ?>
                </span>
                <a href="<?php echo esc_url( $next_url ); ?>" class="ke-btn ke-btn-ghost" style="padding:6px 12px; font-size:12px; <?php echo $page_num >= $total_pages ? 'pointer-events:none; opacity:0.4;' : ''; ?>">Next ›</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <script>
    (function () {
        var all = document.getElementById('ke-comm-check-all');
        if ( ! all ) return;
        all.addEventListener('change', function () {
            document.querySelectorAll('.ke-comm-check').forEach(function (cb) { cb.checked = all.checked; });
        });
    })();
    </script>
</div>
