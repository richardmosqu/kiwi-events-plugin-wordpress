<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Promoters list view.
 *
 * Variables provided by KE_Admin_Promoters::render_list():
 *   $rows         — array of promoter rows (from wpdb->get_results)
 *   $total_pages  — int
 *   $page_num     — int (1-based)
 *   $search       — string
 *   $status       — '' | 'active' | 'inactive' | 'pending'
 *   $totals_by_id — array[ promoter_id => [ 'tickets'=>n, 'total'=>f, 'owed'=>f, 'paid'=>f ] ]
 *   $flash        — [ 'type'=>'success|error', 'message'=>'...' ] | null
 */

$base_url = admin_url( 'admin.php?page=ke-promoters' );
$new_url  = $base_url . '&action=new';
?>
<div class="wrap ke-builder-wrap">

    <!-- ── Flash ── -->
    <?php if ( $flash ) : ?>
        <div class="notice notice-<?php echo esc_attr( $flash['type'] === 'success' ? 'success' : 'error' ); ?> is-dismissible">
            <p><?php echo esc_html( $flash['message'] ); ?></p>
        </div>
    <?php endif; ?>

    <!-- ── Attribution rule explainer ── -->
    <div class="notice notice-info" style="margin:12px 0; padding:12px 14px;">
        <p style="margin:0; font-weight:600;">
            <?php esc_html_e( 'Reglas de atribución (URL-only)', 'kiwi-events' ); ?>
        </p>
        <p style="margin:6px 0 0;">
            <?php esc_html_e( 'Las comisiones se generan solo cuando el comprador llega al checkout con el link del promotor activo en la URL (?promo=tu-slug). Si el usuario navega a otra pestaña sin tu link antes de comprar, la comisión no se acredita. No hay persistencia por cookie ni sesión — el link debe estar presente en el URL al momento de comprar.', 'kiwi-events' ); ?>
        </p>
    </div>

    <!-- ── Page header + toolbar — own white section card ── -->
    <div class="ke-section-card ke-section-card--compact">
        <div class="ke-builder-header">
            <div class="ke-builder-title">
                <h1>Promoters</h1>
                <p style="margin:4px 0 0; color:var(--kiwi-text-muted); font-size:13px;">
                    Manage promoter accounts and track commission earnings.
                </p>
            </div>
            <div class="ke-builder-actions">
                <a href="<?php echo esc_url( $base_url . '&action=stats' ); ?>" class="ke-btn ke-btn-ghost">📊 Analytics</a>
                <a href="<?php echo esc_url( $base_url . '&action=reconcile' ); ?>" class="ke-btn ke-btn-ghost" title="Manually attribute paid orders that lost their promoter">🔗 Reconcile</a>
                <a href="<?php echo esc_url( $base_url . '&action=import' ); ?>" class="ke-btn ke-btn-ghost">⬆ Import CSV</a>
                <a href="<?php echo esc_url( $base_url . '&action=lists' ); ?>" class="ke-btn ke-btn-ghost">📋 Lists</a>
                <a href="<?php echo esc_url( $base_url . '&action=emails' ); ?>" class="ke-btn ke-btn-ghost">✉ Emails</a>
                <a href="<?php echo esc_url( $new_url ); ?>" class="ke-btn ke-btn-primary">+ New Promoter</a>
            </div>
        </div>
    </div>

    <!-- ── Filters — own white section card (form is the card itself) ── -->
    <form method="GET" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ke-section-card ke-section-card--compact" style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
        <input type="hidden" name="page" value="ke-promoters">

        <input type="search"
               name="s"
               value="<?php echo esc_attr( $search ); ?>"
               placeholder="Search by name, email, or slug…"
               class="ke-field"
               style="flex:1 1 240px; min-width:0;">

        <select name="status" class="ke-select">
            <option value="">All statuses</option>
            <option value="active"   <?php selected( $status, 'active' ); ?>>Active</option>
            <option value="pending"  <?php selected( $status, 'pending' ); ?>>Pending</option>
            <option value="inactive" <?php selected( $status, 'inactive' ); ?>>Inactive</option>
        </select>

        <button type="submit" class="ke-btn ke-btn-primary" style="padding:8px 16px;">Filter</button>

        <?php if ( $search !== '' || $status !== '' ) : ?>
            <a href="<?php echo esc_url( $base_url ); ?>" class="ke-btn ke-btn-ghost" style="padding:8px 14px;">Clear</a>
        <?php endif; ?>
    </form>

    <!-- ── Table / Empty State — own white section card ── -->
    <?php if ( empty( $rows ) ) : ?>
        <div class="ke-section-card" style="padding:60px 24px;">
            <div class="ke-empty-state">
                <span class="ke-empty-state-icon">📣</span>
                <h3><?php echo ( $search !== '' || $status !== '' ) ? 'No Matching Promoters' : 'No Promoters Yet'; ?></h3>
                <p style="margin-bottom:24px;">
                    <?php if ( $search !== '' || $status !== '' ) : ?>
                        Try adjusting your filters, or
                        <a href="<?php echo esc_url( $base_url ); ?>">clear them</a>.
                    <?php else : ?>
                        Promoters earn commissions when buyers arrive through their tracking link.
                    <?php endif; ?>
                </p>
                <?php if ( $search === '' && $status === '' ) : ?>
                    <a href="<?php echo esc_url( $new_url ); ?>" class="ke-btn ke-btn-primary">Create Your First Promoter</a>
                <?php endif; ?>
            </div>
        </div>
    <?php else : ?>
        <div class="ke-section-card ke-section-card--flush">
            <div style="overflow-x:auto;">
                <table class="ke-table" style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Slug</th>
                            <th>Status</th>
                            <th style="text-align:center;" title="Welcome email sent on first activation">Welcome</th>
                            <th style="text-align:right;">Tickets</th>
                            <th style="text-align:right;">Owed</th>
                            <th style="text-align:right;">Paid</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $row ) :
                            $id        = (int) $row->id;
                            $edit_url  = $base_url . '&action=edit&id=' . $id;
                            $del_url   = wp_nonce_url(
                                admin_url( 'admin-post.php?action=ke_delete_promoter&id=' . $id ),
                                'ke_delete_promoter_' . $id
                            );
                            $totals    = $totals_by_id[ $id ] ?? array( 'tickets'=>0, 'total'=>0.0, 'owed'=>0.0, 'paid'=>0.0 );
                            $status_v  = (string) $row->status;
                            $is_orphan = empty( $row->user_id ) || $status_v === 'orphaned';
                            $display_name  = ( $row->name !== null && $row->name !== '' ) ? $row->name : '(unlinked)';
                            $display_email = (string) $row->email;
                        ?>
                            <tr<?php echo $is_orphan ? ' style="background:var(--kiwi-orange-kpi-mid);"' : ''; ?>>
                                <td>
                                    <a href="<?php echo esc_url( $edit_url ); ?>" style="color:var(--kiwi-text); font-weight:600; text-decoration:none;">
                                        <?php echo esc_html( $display_name ); ?>
                                    </a>
                                    <?php if ( $is_orphan ) : ?>
                                        <span class="ke-badge ke-badge-pending"
                                              title="<?php esc_attr_e( 'This promoter is not linked to a WordPress user. Re-link them on the edit page to restore portal access.', 'kiwi-events' ); ?>"
                                              style="margin-left:6px;">
                                            ⚠ <?php esc_html_e( 'Orphaned', 'kiwi-events' ); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:var(--kiwi-text-muted);">
                                    <?php echo $display_email !== '' ? esc_html( $display_email ) : '<em style="color:var(--kiwi-text-muted);">—</em>'; ?>
                                </td>
                                <td>
                                    <code style="font-size:11px; background:var(--kiwi-cream-deep); border:1px solid var(--kiwi-border); padding:2px 6px; border-radius:var(--kiwi-radius-sm); color:var(--kiwi-text);"><?php echo esc_html( $row->slug ); ?></code>
                                </td>
                                <td>
                                    <span class="ke-badge ke-badge-<?php echo esc_attr( $status_v ); ?>">
                                        <?php echo esc_html( $status_v ); ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <?php
                                    $welcome_sent = ! empty( $row->welcome_email_sent );
                                    if ( $welcome_sent ) {
                                        $sent_label = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row->welcome_email_sent );
                                        $resend_url = wp_nonce_url(
                                            admin_url( 'admin-post.php?action=ke_resend_promoter_welcome&id=' . $id ),
                                            'ke_resend_promoter_welcome_' . $id
                                        );
                                        ?>
                                        <span title="Welcome email sent <?php echo esc_attr( $sent_label ); ?>" style="color:var(--kiwi-green-text); font-weight:700;">✓</span>
                                        <a href="<?php echo esc_url( $resend_url ); ?>"
                                           onclick="return confirm('Resend the welcome email to this promoter?');"
                                           title="Resend welcome email"
                                           style="font-size:11px; color:var(--kiwi-text-muted); text-decoration:none; margin-left:4px;">↻</a>
                                        <?php
                                    } elseif ( $status_v === 'active' ) {
                                        $resend_url = wp_nonce_url(
                                            admin_url( 'admin-post.php?action=ke_resend_promoter_welcome&id=' . $id ),
                                            'ke_resend_promoter_welcome_' . $id
                                        );
                                        ?>
                                        <a href="<?php echo esc_url( $resend_url ); ?>"
                                           onclick="return confirm('Send the welcome email now?');"
                                           title="Welcome email never sent — click to send now"
                                           style="color:var(--kiwi-red); font-weight:700; text-decoration:none;">✗</a>
                                        <?php
                                    } else {
                                        ?>
                                        <span title="Welcome email only sent on first activation" style="color:var(--kiwi-text-muted);">—</span>
                                        <?php
                                    }
                                    ?>
                                </td>
                                <td style="text-align:right; color:var(--kiwi-text); font-variant-numeric:tabular-nums;">
                                    <?php echo (int) $totals['tickets']; ?>
                                </td>
                                <td style="text-align:right; color:var(--kiwi-text); font-variant-numeric:tabular-nums; font-weight:600;">
                                    $<?php echo number_format( (float) $totals['owed'], 2 ); ?>
                                </td>
                                <td style="text-align:right; color:var(--kiwi-text-muted); font-variant-numeric:tabular-nums;">
                                    $<?php echo number_format( (float) $totals['paid'], 2 ); ?>
                                </td>
                                <td style="text-align:right; white-space:nowrap;">
                                    <a href="<?php echo esc_url( $base_url . '&action=active_events&id=' . $id ); ?>" class="ke-btn ke-btn-ghost" style="font-size:12px; padding:5px 10px;">Active events</a>
                                    <a href="<?php echo esc_url( $base_url . '&action=commissions&id=' . $id ); ?>" class="ke-btn ke-btn-ghost" style="font-size:12px; padding:5px 10px; margin-left:4px;">Commissions</a>
                                    <a href="<?php echo esc_url( $edit_url ); ?>" class="ke-btn ke-btn-ghost" style="font-size:12px; padding:5px 10px; margin-left:4px;">Edit</a>
                                    <a href="<?php echo esc_url( $del_url ); ?>"
                                       onclick="return confirm('Delete this promoter? Their existing commission rows will be preserved for audit.');"
                                       class="ke-btn ke-btn-danger" style="font-size:12px; padding:5px 10px; margin-left:4px;">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Pagination ── -->
        <?php if ( $total_pages > 1 ) :
            $base_q = array( 'page' => 'ke-promoters' );
            if ( $search !== '' ) $base_q['s']      = $search;
            if ( $status !== '' ) $base_q['status'] = $status;
        ?>
            <div style="display:flex; justify-content:center; align-items:center; gap:6px; padding:18px 0;">
                <?php
                $prev = max( 1, $page_num - 1 );
                $next = min( $total_pages, $page_num + 1 );

                $prev_url = add_query_arg( array_merge( $base_q, array( 'paged' => $prev ) ), admin_url( 'admin.php' ) );
                $next_url = add_query_arg( array_merge( $base_q, array( 'paged' => $next ) ), admin_url( 'admin.php' ) );
                ?>
                <a href="<?php echo esc_url( $prev_url ); ?>" class="ke-btn ke-btn-ghost" style="padding:6px 12px; font-size:12px; <?php echo $page_num <= 1 ? 'pointer-events:none; opacity:0.4;' : ''; ?>">‹ Prev</a>
                <span style="font-size:13px; color:var(--kiwi-text-muted); padding:0 8px;">
                    Page <?php echo (int) $page_num; ?> of <?php echo (int) $total_pages; ?>
                </span>
                <a href="<?php echo esc_url( $next_url ); ?>" class="ke-btn ke-btn-ghost" style="padding:6px 12px; font-size:12px; <?php echo $page_num >= $total_pages ? 'pointer-events:none; opacity:0.4;' : ''; ?>">Next ›</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</div>
