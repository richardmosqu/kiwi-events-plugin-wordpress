<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ticket-sales waitlist admin page view.
 *
 * Vars in scope (set by KE_Admin_Waitlist::render):
 *   $rows             array   waitlist rows for the current page
 *   $total            int     total matching rows (for pagination)
 *   $stats            array   KE_Waitlist::stats() result
 *   $events           array   WP_Post events (for the filter dropdown)
 *   $schedule         array|null  KE_Sales_Schedule config for the active event
 *   $schedule_labels  array   formatted opening moment for the active event
 *   $schedule_pending bool    whether that event is still counting down
 *   $event_id         int     active event filter (0 = all)
 *   $status_filter    string  current status filter
 *   $search           string  current search term
 *   $page_num         int     1-based page number
 *   $total_pages      int     total pages
 *   $sweep_url        string  nonced manual-sweep URL
 */

$status_options = array(
    ''          => __( 'All statuses', 'kiwi-events' ),
    'pending'   => __( 'Waiting',      'kiwi-events' ),
    'notified'  => __( 'Notified',     'kiwi-events' ),
    'cancelled' => __( 'Cancelled',    'kiwi-events' ),
);
$status_labels = $status_options;
unset( $status_labels[''] );

$base_args = array( 'page' => 'kiwi-events-waitlist' );
if ( $event_id )      $base_args['event_id'] = $event_id;
if ( $status_filter ) $base_args['status']   = $status_filter;
if ( $search !== '' ) $base_args['s']        = $search;
$base_url = add_query_arg( $base_args, admin_url( 'admin.php' ) );

$export_csv_url = add_query_arg( array_merge( $base_args, array( 'ke_export_csv' => '1' ) ), admin_url( 'admin.php' ) );
?>
<div class="wrap ke-wrap">

    <!-- Page header -->
    <div class="ke-section-card ke-section-card--compact">
        <div class="ke-page-header">
            <div class="ke-page-header-left">
                <h1><?php esc_html_e( 'Waitlist', 'kiwi-events' ); ?></h1>
                <p><?php esc_html_e( 'People who asked to be notified when a scheduled ticket sale opens. The notification email is queued automatically — this page is read-only.', 'kiwi-events' ); ?></p>
            </div>
            <div class="ke-header-actions">
                <a href="<?php echo esc_url( $export_csv_url ); ?>" class="ke-btn ke-btn-ghost">↓ <?php esc_html_e( 'Export CSV', 'kiwi-events' ); ?></a>
            </div>
        </div>
    </div>

    <?php if ( $event_id > 0 && ! empty( $schedule ) ) : ?>
        <div class="ke-section-card ke-section-card--compact">
            <?php if ( ! empty( $schedule['enabled'] ) && $schedule_labels['full'] !== '' ) : ?>
                <p style="margin:0;">
                    <?php if ( $schedule_pending ) : ?>
                        ⏳ <?php
                        printf(
                            /* translators: %s: formatted date and time when ticket sales open. */
                            esc_html__( 'Sales for this event open %s. Everyone still marked "Waiting" is emailed within 5 minutes of that moment.', 'kiwi-events' ),
                            '<strong>' . esc_html( $schedule_labels['full'] ) . '</strong>'
                        );
                        ?>
                    <?php else : ?>
                        ✅ <?php
                        printf(
                            /* translators: %s: formatted date and time when ticket sales opened. */
                            esc_html__( 'Sales opened %s — the release sweep has queued (or is queuing) the notification emails.', 'kiwi-events' ),
                            '<strong>' . esc_html( $schedule_labels['full'] ) . '</strong>'
                        );
                        ?>
                    <?php endif; ?>
                </p>
            <?php else : ?>
                <p style="margin:0;">
                    ⚠️ <?php esc_html_e( 'This event has no scheduled sale opening configured, so anyone still waiting will be notified on the next sweep.', 'kiwi-events' ); ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="ke-section-card ke-filters">
        <form method="get" action="">
            <input type="hidden" name="page" value="kiwi-events-waitlist">
            <div class="ke-filter-row">
                <div class="ke-filter-field">
                    <label><?php esc_html_e( 'Event', 'kiwi-events' ); ?></label>
                    <select name="event_id" onchange="this.form.submit()">
                        <option value="0"><?php esc_html_e( 'All events', 'kiwi-events' ); ?></option>
                        <?php foreach ( $events as $event ) : ?>
                            <option value="<?php echo esc_attr( $event->ID ); ?>" <?php selected( $event_id, $event->ID ); ?>>
                                <?php echo esc_html( $event->post_title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ke-filter-field">
                    <label><?php esc_html_e( 'Status', 'kiwi-events' ); ?></label>
                    <select name="status">
                        <?php foreach ( $status_options as $val => $label ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $status_filter, $val ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ke-filter-field">
                    <label><?php esc_html_e( 'Search', 'kiwi-events' ); ?></label>
                    <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Email or name…', 'kiwi-events' ); ?>">
                </div>

                <div class="ke-filter-field ke-filter-submit">
                    <button type="submit" class="ke-btn ke-btn-primary"><?php esc_html_e( 'Filter', 'kiwi-events' ); ?></button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=kiwi-events-waitlist' ) ); ?>" class="ke-btn ke-btn-ghost"><?php esc_html_e( 'Reset', 'kiwi-events' ); ?></a>
                </div>
            </div>
        </form>
    </div>

    <!-- Stats strip -->
    <div class="ke-section-card ke-section-card--compact">
        <div class="ke-stat-strip">
            <div class="ke-stat-strip-item">
                <div class="ke-stat-strip-label"><?php esc_html_e( 'Waiting', 'kiwi-events' ); ?></div>
                <div class="ke-stat-strip-value"><?php echo (int) $stats['pending']; ?></div>
            </div>
            <div class="ke-stat-strip-item">
                <div class="ke-stat-strip-label"><?php esc_html_e( 'Notified', 'kiwi-events' ); ?></div>
                <div class="ke-stat-strip-value"><?php echo (int) $stats['notified']; ?></div>
            </div>
            <div class="ke-stat-strip-item">
                <div class="ke-stat-strip-label"><?php esc_html_e( 'Cancelled', 'kiwi-events' ); ?></div>
                <div class="ke-stat-strip-value"><?php echo (int) $stats['cancelled']; ?></div>
            </div>
            <div class="ke-stat-strip-item">
                <div class="ke-stat-strip-label"><?php esc_html_e( 'Total signups', 'kiwi-events' ); ?></div>
                <div class="ke-stat-strip-value"><?php echo (int) $stats['total']; ?></div>
            </div>
        </div>
    </div>

    <?php if ( empty( $rows ) ) : ?>
        <div class="ke-card">
            <div class="ke-empty-state">
                <span class="ke-empty-state-icon">⏳</span>
                <h3><?php esc_html_e( 'No signups yet', 'kiwi-events' ); ?></h3>
                <p><?php esc_html_e( 'Nobody matches these filters. The form appears on the event page only while a scheduled sale has not opened yet.', 'kiwi-events' ); ?></p>
            </div>
        </div>
    <?php else : ?>

        <div class="ke-section-card ke-section-card--flush ke-attendees-card">
            <table class="ke-table ke-attendees-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Email',     'kiwi-events' ); ?></th>
                        <?php if ( $event_id <= 0 ) : ?>
                            <th><?php esc_html_e( 'Event', 'kiwi-events' ); ?></th>
                        <?php endif; ?>
                        <th><?php esc_html_e( 'Status',    'kiwi-events' ); ?></th>
                        <th><?php esc_html_e( 'Signed up', 'kiwi-events' ); ?></th>
                        <th><?php esc_html_e( 'Notified',  'kiwi-events' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $r ) :
                        $created_fmt  = $r->created_at  ? date_i18n( 'M j, Y · g:i A', strtotime( $r->created_at ) ) : '—';
                        $notified_fmt = ! empty( $r->notified_at ) ? date_i18n( 'M j, Y · g:i A', strtotime( $r->notified_at ) ) : '—';
                        $status_label = $status_labels[ $r->status ] ?? ucfirst( (string) $r->status );
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $r->email ); ?></strong>
                                <?php if ( ! empty( $r->name ) ) : ?>
                                    <div class="ke-muted"><?php echo esc_html( $r->name ); ?></div>
                                <?php endif; ?>
                            </td>
                            <?php if ( $event_id <= 0 ) : ?>
                                <td class="ke-muted"><?php echo esc_html( $r->event_title ?: '—' ); ?></td>
                            <?php endif; ?>
                            <td>
                                <span class="ke-resv-status-pill ke-resv-status-<?php echo esc_attr( $r->status === 'notified' ? 'confirmed' : ( $r->status === 'cancelled' ? 'cancelled' : 'pending' ) ); ?>">
                                    <?php echo esc_html( $status_label ); ?>
                                </span>
                            </td>
                            <td class="ke-muted"><?php echo esc_html( $created_fmt ); ?></td>
                            <td class="ke-muted"><?php echo esc_html( $notified_fmt ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="ke-pagination" style="padding:16px 24px;">
                    <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
                        <a href="<?php echo esc_url( add_query_arg( 'paged', $i, $base_url ) ); ?>"
                           class="ke-page-link <?php echo $i === $page_num ? 'active' : ''; ?>">
                            <?php echo (int) $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

    <?php if ( $sweep_url !== '' && current_user_can( 'manage_options' ) ) : ?>
        <p class="ke-muted" style="margin-top:12px; font-size:12px;">
            <?php esc_html_e( 'Notifications go out automatically every 5 minutes via WP-Cron.', 'kiwi-events' ); ?>
            <a href="<?php echo esc_url( $sweep_url ); ?>"><?php esc_html_e( 'Run the release sweep now', 'kiwi-events' ); ?></a>
        </p>
    <?php endif; ?>

</div>
