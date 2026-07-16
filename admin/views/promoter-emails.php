<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Recent emails view.
 *
 * Variables provided by KE_Admin_Promoters::render_emails():
 *   $rows         — array of ke_email_log rows
 *   $total        — int
 *   $total_pages  — int
 *   $page_num     — int
 *   $status       — '' | queued | retrying | sent | failed
 */

$base_url = admin_url( 'admin.php?page=ke-promoters&action=emails' );

$statuses = array(
    ''         => 'All',
    'queued'   => 'Queued',
    'retrying' => 'Retrying',
    'sent'     => 'Sent',
    'failed'   => 'Failed',
);
?>
<div class="wrap ke-builder-wrap">

    <div class="ke-builder-header">
        <div class="ke-builder-title">
            <h1>Recent emails</h1>
            <p style="margin:4px 0 0; color:var(--kiwi-legacy-text-muted); font-size:13px;">
                Outbound promoter notifications. Failed sends auto-retry 3× with exponential backoff (1m / 5m / 30m).
            </p>
        </div>
        <div class="ke-builder-actions">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ke-promoters' ) ); ?>" class="ke-btn ke-btn-ghost">← All promoters</a>
        </div>
    </div>

    <!-- Status filter pills -->
    <div class="ke-card" style="padding:12px 14px; margin-bottom:16px; display:flex; gap:6px; flex-wrap:wrap;">
        <?php foreach ( $statuses as $k => $label ) :
            $active = ( $k === $status );
            $url    = $k === '' ? $base_url : ( $base_url . '&status=' . rawurlencode( $k ) );
        ?>
            <a href="<?php echo esc_url( $url ); ?>"
               class="ke-btn <?php echo $active ? 'ke-btn-primary' : 'ke-btn-ghost'; ?>"
               style="padding:6px 14px; font-size:12px;">
                <?php echo esc_html( $label ); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ( empty( $rows ) ) : ?>
        <div class="ke-card" style="padding:48px 24px; text-align:center; color:var(--kiwi-legacy-text-muted);">
            No emails matching this filter yet.
        </div>
    <?php else : ?>
        <div class="ke-card" style="padding:0; overflow:hidden;">
            <div style="overflow-x:auto;">
                <table class="ke-table" style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:var(--kiwi-legacy-page-bg); text-align:left;">
                            <th style="padding:10px 14px; color:var(--kiwi-legacy-text-mid);">When</th>
                            <th style="padding:10px 14px; color:var(--kiwi-legacy-text-mid);">Recipient</th>
                            <th style="padding:10px 14px; color:var(--kiwi-legacy-text-mid);">Subject</th>
                            <th style="padding:10px 14px; color:var(--kiwi-legacy-text-mid);">Template</th>
                            <th style="padding:10px 14px; color:var(--kiwi-legacy-text-mid); text-align:center;">Attempts</th>
                            <th style="padding:10px 14px; color:var(--kiwi-legacy-text-mid);">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $rows as $r ) :
                        $stat = (string) $r->status;
                        $color = 'var(--kiwi-legacy-text-mid)'; $bg = 'var(--kiwi-legacy-row-bg)';
                        if ( $stat === 'sent' )     { $bg = 'var(--kiwi-legacy-green-pill-bg)';  $color = 'var(--kiwi-legacy-emerald-800)'; }
                        elseif ( $stat === 'failed' )   { $bg = 'var(--kiwi-legacy-red-50)';         $color = 'var(--kiwi-legacy-red-800)'; }
                        elseif ( $stat === 'retrying' ) { $bg = 'var(--kiwi-legacy-yellow-pill-bg)'; $color = 'var(--kiwi-legacy-yellow-pill-text)'; }
                        elseif ( $stat === 'queued' )   { $bg = 'var(--kiwi-legacy-blue-pending-bg)';$color = 'var(--kiwi-legacy-blue-800)'; }

                        $when = $r->sent_at && $r->sent_at !== '0000-00-00 00:00:00'
                              ? date( 'M j, Y g:i A', strtotime( $r->sent_at ) )
                              : date( 'M j, Y g:i A', strtotime( $r->created_at ) );
                    ?>
                        <tr style="border-top:1px solid var(--kiwi-legacy-row-bg-alt);" title="<?php echo esc_attr( (string) $r->error_message ); ?>">
                            <td style="padding:10px 14px; color:var(--kiwi-legacy-text-muted); white-space:nowrap;"><?php echo esc_html( $when ); ?></td>
                            <td style="padding:10px 14px;"><?php echo esc_html( $r->recipient ); ?></td>
                            <td style="padding:10px 14px; max-width:340px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <?php echo esc_html( $r->subject ); ?>
                            </td>
                            <td style="padding:10px 14px;">
                                <code style="font-size:11px; background:var(--kiwi-legacy-row-bg); padding:2px 6px; border-radius:4px;">
                                    <?php echo esc_html( $r->template ); ?>
                                </code>
                            </td>
                            <td style="padding:10px 14px; text-align:center; font-variant-numeric:tabular-nums; color:var(--kiwi-legacy-text-muted);">
                                <?php echo (int) $r->attempts; ?>
                            </td>
                            <td style="padding:10px 14px;">
                                <span style="display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; text-transform:uppercase; background:<?php echo esc_attr( $bg ); ?>; color:<?php echo esc_attr( $color ); ?>;">
                                    <?php echo esc_html( $stat ); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ( $total_pages > 1 ) :
            $base_q = array( 'page' => 'ke-promoters', 'action' => 'emails' );
            if ( $status !== '' ) $base_q['status'] = $status;
        ?>
            <div style="display:flex; justify-content:center; gap:6px; padding:18px 0;">
                <?php
                $prev = max( 1, $page_num - 1 );
                $next = min( $total_pages, $page_num + 1 );
                $prev_url = add_query_arg( array_merge( $base_q, array( 'paged' => $prev ) ), admin_url( 'admin.php' ) );
                $next_url = add_query_arg( array_merge( $base_q, array( 'paged' => $next ) ), admin_url( 'admin.php' ) );
                ?>
                <a href="<?php echo esc_url( $prev_url ); ?>" class="ke-btn ke-btn-ghost" style="padding:6px 12px; font-size:12px; <?php echo $page_num <= 1 ? 'pointer-events:none; opacity:0.4;' : ''; ?>">‹ Prev</a>
                <span style="font-size:13px; color:var(--kiwi-legacy-text-mid); padding:0 8px; align-self:center;">
                    Page <?php echo (int) $page_num; ?> of <?php echo (int) $total_pages; ?>
                </span>
                <a href="<?php echo esc_url( $next_url ); ?>" class="ke-btn ke-btn-ghost" style="padding:6px 12px; font-size:12px; <?php echo $page_num >= $total_pages ? 'pointer-events:none; opacity:0.4;' : ''; ?>">Next ›</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</div>
