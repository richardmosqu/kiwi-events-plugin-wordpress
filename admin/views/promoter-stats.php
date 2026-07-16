<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Promoter analytics dashboard.
 *
 * Variables provided by KE_Admin_Promoters::render_stats():
 *   $period_key       : this_month | last_month | last_90_days | all_time
 *   $period_label     : human label
 *   $from, $to        : window bounds (mysql strings or '')
 *   $totals           : aggregate totals_in_window()
 *   $top              : top_promoters()
 *   $counts_by_status : { active, pending, inactive }
 */

$base_url = admin_url( 'admin.php?page=ke-promoters' );
$currency = (string) get_option( 'ke_promoter_currency_label', '$' );

$periods = array(
    'this_month'   => 'This month',
    'last_month'   => 'Last month',
    'last_90_days' => 'Last 90 days',
    'all_time'     => 'All time',
);

?>
<div class="wrap ke-builder-wrap">

    <div class="ke-builder-header">
        <div class="ke-builder-title">
            <h1>Promoter Analytics</h1>
            <p style="margin:4px 0 0; color:var(--kiwi-legacy-text-muted); font-size:13px;">
                Aggregate commission performance across all promoters.
            </p>
        </div>
        <div class="ke-builder-actions">
            <a href="<?php echo esc_url( $base_url ); ?>" class="ke-btn ke-btn-ghost">← All promoters</a>
        </div>
    </div>

    <!-- Period filter -->
    <div class="ke-card" style="padding:12px 14px; margin-bottom:18px; display:flex; gap:6px; flex-wrap:wrap;">
        <?php foreach ( $periods as $k => $label ) :
            $is_active = ( $k === $period_key );
            $url = add_query_arg( array( 'page' => 'ke-promoters', 'action' => 'stats', 'period' => $k ), admin_url( 'admin.php' ) );
        ?>
            <a href="<?php echo esc_url( $url ); ?>"
               class="ke-btn <?php echo $is_active ? 'ke-btn-primary' : 'ke-btn-ghost'; ?>"
               style="padding:7px 14px; font-size:13px;">
                <?php echo esc_html( $label ); ?>
            </a>
        <?php endforeach; ?>
        <span style="flex:1;"></span>
        <span style="font-size:12px; color:var(--kiwi-legacy-text-muted); align-self:center;">
            <?php echo esc_html( $period_label ); ?>
            <?php if ( $from && $to ) : ?>
                · <?php echo esc_html( date( 'M j, Y', strtotime( $from ) ) ); ?> – <?php echo esc_html( date( 'M j, Y', strtotime( $to ) ) ); ?>
            <?php endif; ?>
        </span>
    </div>

    <!-- KPIs -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:12px; margin-bottom:20px;">
        <?php
        $kpis = array(
            array( 'Commissions written', number_format_i18n( $totals['tickets'] ) ),
            array( 'Owed',                $currency . number_format( (float) $totals['owed'],  2 ) ),
            array( 'Paid out',            $currency . number_format( (float) $totals['paid'],  2 ) ),
            array( 'Total volume',        $currency . number_format( (float) $totals['total'], 2 ) ),
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

    <!-- Promoter status mix -->
    <div class="ke-card" style="padding:16px 18px; margin-bottom:20px;">
        <h2 style="margin:0 0 10px; font-size:15px;">Promoter accounts</h2>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <span style="background:var(--kiwi-legacy-green-pill-bg); color:var(--kiwi-legacy-emerald-800); padding:6px 12px; border-radius:8px; font-weight:600; font-size:13px;">
                Active: <?php echo (int) $counts_by_status['active']; ?>
            </span>
            <span style="background:var(--kiwi-legacy-yellow-pill-bg); color:var(--kiwi-legacy-yellow-pill-text); padding:6px 12px; border-radius:8px; font-weight:600; font-size:13px;">
                Pending: <?php echo (int) $counts_by_status['pending']; ?>
            </span>
            <span style="background:var(--kiwi-legacy-row-bg); color:var(--kiwi-legacy-text-mid); padding:6px 12px; border-radius:8px; font-weight:600; font-size:13px;">
                Inactive: <?php echo (int) $counts_by_status['inactive']; ?>
            </span>
        </div>
    </div>

    <!-- Top promoters -->
    <div class="ke-card" style="padding:0; overflow:hidden;">
        <div style="padding:14px 18px; border-bottom:1px solid var(--kiwi-legacy-row-bg-alt);">
            <h2 style="margin:0; font-size:15px;">Top promoters · <?php echo esc_html( $period_label ); ?></h2>
        </div>

        <?php if ( empty( $top ) ) : ?>
            <div style="padding:40px 24px; text-align:center; color:var(--kiwi-legacy-text-muted);">
                No commission activity in this window yet.
            </div>
        <?php else : ?>
            <div style="overflow-x:auto;">
                <table class="ke-table" style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:var(--kiwi-legacy-page-bg); text-align:left;">
                            <th style="padding:10px 16px; color:var(--kiwi-legacy-text-mid); width:40px;">#</th>
                            <th style="padding:10px 16px; color:var(--kiwi-legacy-text-mid);">Promoter</th>
                            <th style="padding:10px 16px; color:var(--kiwi-legacy-text-mid); text-align:right;">Tickets</th>
                            <th style="padding:10px 16px; color:var(--kiwi-legacy-text-mid); text-align:right;">Owed</th>
                            <th style="padding:10px 16px; color:var(--kiwi-legacy-text-mid); text-align:right;">Paid</th>
                            <th style="padding:10px 16px; color:var(--kiwi-legacy-text-mid); text-align:right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $top as $i => $t ) :
                            $edit  = $base_url . '&action=edit&id=' . (int) $t->promoter_id;
                            $comms = $base_url . '&action=commissions&id=' . (int) $t->promoter_id;
                            $name  = $t->name ?: '(deleted promoter)';
                        ?>
                            <tr style="border-top:1px solid var(--kiwi-legacy-row-bg-alt);">
                                <td style="padding:10px 16px; color:var(--kiwi-legacy-text-muted); font-variant-numeric:tabular-nums;"><?php echo (int) ( $i + 1 ); ?></td>
                                <td style="padding:10px 16px;">
                                    <a href="<?php echo esc_url( $edit ); ?>" style="color:var(--kiwi-legacy-text-darkest); font-weight:600; text-decoration:none;">
                                        <?php echo esc_html( $name ); ?>
                                    </a>
                                    <?php if ( $t->email ) : ?>
                                        <div style="font-size:11px; color:var(--kiwi-legacy-text-faint);"><?php echo esc_html( $t->email ); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 16px; text-align:right; font-variant-numeric:tabular-nums;"><?php echo (int) $t->tickets; ?></td>
                                <td style="padding:10px 16px; text-align:right; font-variant-numeric:tabular-nums; color:var(--kiwi-legacy-text-mid);">
                                    <?php echo esc_html( $currency . number_format( (float) $t->owed, 2 ) ); ?>
                                </td>
                                <td style="padding:10px 16px; text-align:right; font-variant-numeric:tabular-nums; color:var(--kiwi-legacy-text-mid);">
                                    <?php echo esc_html( $currency . number_format( (float) $t->paid, 2 ) ); ?>
                                </td>
                                <td style="padding:10px 16px; text-align:right; font-variant-numeric:tabular-nums; font-weight:600; color:var(--kiwi-legacy-text-darkest);">
                                    <?php echo esc_html( $currency . number_format( (float) $t->total, 2 ) ); ?>
                                    <div style="margin-top:4px;">
                                        <a href="<?php echo esc_url( $comms ); ?>" style="font-size:11px; color:var(--kiwi-legacy-indigo-500); text-decoration:none;">View →</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
