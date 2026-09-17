<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Analytics admin page view.
 *
 * Vars in scope (set by KE_Admin_Analytics::render):
 *   $organizers    WP_Term[]   every ke_organizer term (for the picker)
 *   $organizer     WP_Term|null the selected organizer (null = all)
 *   $organizer_id  int
 *   $range         string      day|week|month|all
 *   $range_labels  array       range => label
 *   $report        array       KE_Event_Analytics::build_report() output
 */

$base_args = array( 'page' => 'ke-analytics' );
if ( $organizer_id > 0 ) $base_args['organizer_id'] = $organizer_id;

$totals      = $report['totals'];
$views_total = (int) $totals['view'];
$ctr_total   = $views_total > 0 ? round( ( (int) $totals['ticket_click'] / $views_total ) * 100, 1 ) : 0.0;
$days        = $report['days'];
$has_series  = count( $days ) > 1;
$events      = $report['events'];
$any_traffic = false;
foreach ( $events as $ev ) {
    if ( (int) $ev['totals']['view'] + (int) $ev['clicks'] > 0 ) { $any_traffic = true; break; }
}

$history      = isset( $report['history'] ) ? $report['history'] : null;
$can_import   = current_user_can( 'manage_options' ) && class_exists( 'KE_Analytics_History' );
$hist_avail   = $can_import && KE_Analytics_History::is_available();
$hist_log     = $can_import ? KE_Analytics_History::log() : null;
$hist_events  = $can_import ? count( KE_Analytics_History::candidate_event_ids() ) : 0;

$range_phrase = array(
    'day'   => __( 'today', 'kiwi-events' ),
    'week'  => __( 'in the last 7 days', 'kiwi-events' ),
    'month' => __( 'in the last 30 days', 'kiwi-events' ),
    'all'   => __( 'so far', 'kiwi-events' ),
);

/**
 * Two-tone daily sparkline (accent = visits, grey = clicks) — same drawing as
 * the organizer dashboard so both surfaces read the same way.
 */
$ke_an_spark = function ( array $days, array $views, array $clicks ) {
    $n = count( $days );
    if ( $n < 2 ) return '';
    $h = 36; $w = $n * 10; $max = 1;
    for ( $i = 0; $i < $n; $i++ ) {
        $max = max( $max, (int) ( $views[ $i ] ?? 0 ), (int) ( $clicks[ $i ] ?? 0 ) );
    }
    $bars = '';
    for ( $i = 0; $i < $n; $i++ ) {
        $v  = (int) ( $views[ $i ] ?? 0 );
        $c  = (int) ( $clicks[ $i ] ?? 0 );
        $vh = (int) round( ( $v / $max ) * ( $h - 2 ) );
        $ch = (int) round( ( $c / $max ) * ( $h - 2 ) );
        $x  = $i * 10;
        $label = date_i18n( 'M j', strtotime( $days[ $i ] ) ) . ' · ' . $v . ' visits · ' . $c . ' clicks';
        $bars .= '<rect class="ke-an-bar-v" x="' . ( $x + 1 ) . '" y="' . ( $h - $vh ) . '" width="4" height="' . $vh . '" rx="1"/>'
               . '<rect class="ke-an-bar-c" x="' . ( $x + 5 ) . '" y="' . ( $h - $ch ) . '" width="4" height="' . $ch . '" rx="1"/>'
               . '<rect class="ke-an-bar-hit" x="' . $x . '" y="0" width="10" height="' . $h . '"><title>' . esc_html( $label ) . '</title></rect>';
    }
    return '<svg class="ke-an-spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__( 'Daily visits and clicks', 'kiwi-events' ) . '">' . $bars . '</svg>';
};
?>
<div class="wrap ke-wrap ke-analytics-page">

    <!-- Page header -->
    <div class="ke-section-card ke-section-card--compact">
        <div class="ke-page-header">
            <div class="ke-page-header-left">
                <h1><?php esc_html_e( 'Analytics', 'kiwi-events' ); ?></h1>
                <p><?php esc_html_e( 'Visits to each event page and clicks on its tickets, reservations, birthday and share buttons — by organizer, event by event. Counted in the visitor’s browser, one visit per session per event; staff views are not counted.', 'kiwi-events' ); ?></p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="ke-section-card ke-section-card--compact">
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ke-an-filters" id="ke-an-filters">
            <input type="hidden" name="page" value="ke-analytics">
            <input type="hidden" name="range" value="<?php echo esc_attr( $range ); ?>">
            <div class="ke-an-filter ke-an-filter--organizer">
                <label for="ke-an-organizer"><?php esc_html_e( 'Organizer', 'kiwi-events' ); ?></label>
                <select id="ke-an-organizer" name="organizer_id" class="ke-select">
                    <option value="0"<?php selected( $organizer_id, 0 ); ?>><?php esc_html_e( 'All organizers', 'kiwi-events' ); ?></option>
                    <?php foreach ( $organizers as $term ) : ?>
                        <option value="<?php echo (int) $term->term_id; ?>"<?php selected( $organizer_id, (int) $term->term_id ); ?>>
                            <?php echo esc_html( $term->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ke-an-filter">
                <span class="ke-an-filter-label"><?php esc_html_e( 'Range', 'kiwi-events' ); ?></span>
                <div class="ke-an-pills" role="tablist" aria-label="<?php esc_attr_e( 'Range', 'kiwi-events' ); ?>">
                    <?php foreach ( $range_labels as $key => $label ) :
                        $url = add_query_arg( array_merge( $base_args, array( 'range' => $key ) ), admin_url( 'admin.php' ) );
                    ?>
                        <a class="ke-an-pill<?php echo $key === $range ? ' is-active' : ''; ?>"
                           role="tab" aria-selected="<?php echo $key === $range ? 'true' : 'false'; ?>"
                           href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ( $report['from'] ) : ?>
                <p class="ke-an-window ke-muted">
                    <?php
                    echo esc_html( $report['from'] === $report['to']
                        ? date_i18n( 'l, M j', strtotime( $report['to'] ) )
                        : date_i18n( 'M j', strtotime( $report['from'] ) ) . ' – ' . date_i18n( 'M j, Y', strtotime( $report['to'] ) ) );
                    ?>
                </p>
            <?php endif; ?>
        </form>
    </div>

    <!-- KPI row -->
    <div class="ke-section-card">
        <div class="ke-kpi-grid ke-an-kpi-grid">
            <div class="ke-kpi-card ke-kpi-events ke-an-kpi--views">
                <div class="ke-kpi-icon">👀</div>
                <div class="ke-kpi-content">
                    <span class="ke-kpi-label"><?php esc_html_e( 'Visits', 'kiwi-events' ); ?></span>
                    <span class="ke-kpi-value"><?php echo esc_html( number_format_i18n( $views_total ) ); ?></span>
                </div>
            </div>
            <div class="ke-kpi-card ke-kpi-tickets">
                <div class="ke-kpi-icon">🎟</div>
                <div class="ke-kpi-content">
                    <span class="ke-kpi-label"><?php esc_html_e( 'Ticket clicks', 'kiwi-events' ); ?></span>
                    <span class="ke-kpi-value"><?php echo esc_html( number_format_i18n( (int) $totals['ticket_click'] ) ); ?></span>
                </div>
            </div>
            <div class="ke-kpi-card ke-kpi-checkin">
                <div class="ke-kpi-icon">🍽</div>
                <div class="ke-kpi-content">
                    <span class="ke-kpi-label"><?php esc_html_e( 'Reservations', 'kiwi-events' ); ?></span>
                    <span class="ke-kpi-value"><?php echo esc_html( number_format_i18n( (int) $totals['reserve_click'] ) ); ?></span>
                </div>
            </div>
            <div class="ke-kpi-card ke-kpi-revenue">
                <div class="ke-kpi-icon">🎂</div>
                <div class="ke-kpi-content">
                    <span class="ke-kpi-label"><?php esc_html_e( 'Birthday', 'kiwi-events' ); ?></span>
                    <span class="ke-kpi-value"><?php echo esc_html( number_format_i18n( (int) $totals['birthday_click'] ) ); ?></span>
                </div>
            </div>
            <div class="ke-kpi-card ke-kpi-events">
                <div class="ke-kpi-icon">↗</div>
                <div class="ke-kpi-content">
                    <span class="ke-kpi-label"><?php esc_html_e( 'Shares', 'kiwi-events' ); ?></span>
                    <span class="ke-kpi-value"><?php echo esc_html( number_format_i18n( (int) $totals['share_click'] ) ); ?></span>
                </div>
            </div>
            <div class="ke-kpi-card ke-kpi-checkin">
                <div class="ke-kpi-icon">%</div>
                <div class="ke-kpi-content">
                    <span class="ke-kpi-label"><?php esc_html_e( 'Ticket CTR', 'kiwi-events' ); ?></span>
                    <span class="ke-kpi-value"><?php echo esc_html( $ctr_total ); ?>%</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Events table -->
    <div class="ke-section-card">
        <div class="ke-an-table-head">
            <h3>
                <?php
                echo $organizer
                    ? esc_html( sprintf( __( 'Events by %s', 'kiwi-events' ), $organizer->name ) )
                    : esc_html__( 'All events', 'kiwi-events' );
                ?>
                <span class="ke-an-count"><?php echo esc_html( number_format_i18n( count( $events ) ) ); ?></span>
            </h3>
            <?php if ( $has_series ) : ?>
                <div class="ke-an-legend">
                    <span><i class="ke-an-legend-v"></i><?php esc_html_e( 'Visits', 'kiwi-events' ); ?></span>
                    <span><i class="ke-an-legend-c"></i><?php esc_html_e( 'Clicks', 'kiwi-events' ); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <?php if ( empty( $events ) ) : ?>
            <div class="ke-empty-state">
                <span class="ke-empty-state-icon">📊</span>
                <h3><?php esc_html_e( 'No events', 'kiwi-events' ); ?></h3>
                <p><?php echo $organizer
                    ? esc_html__( 'This organizer has no events yet.', 'kiwi-events' )
                    : esc_html__( 'Publish an event and its traffic will appear here.', 'kiwi-events' ); ?></p>
            </div>
        <?php else : ?>
            <?php if ( ! $any_traffic ) : ?>
                <p class="ke-an-note">
                    <?php echo esc_html( sprintf( __( 'No visits recorded %s. Numbers start appearing as soon as visitors open an event page.', 'kiwi-events' ), $range_phrase[ $range ] ?? '' ) ); ?>
                </p>
            <?php endif; ?>
            <?php if ( $history && ( $range === 'all' || ( $report['from'] && $report['from'] < $history['cutover'] ) ) ) : ?>
                <p class="ke-an-note ke-an-note--history">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: 1: date, 2: source name */
                        __( 'Visits before %1$s come from %2$s and count page views, so they read a little higher than the plugin’s one-per-session visits. Clicks only exist from that date on.', 'kiwi-events' ),
                        date_i18n( 'M j, Y', strtotime( $history['cutover'] ) ),
                        $history['provider'] ? $history['provider'] : 'WordPress.com Stats'
                    ) );
                    ?>
                </p>
            <?php endif; ?>
            <div class="ke-an-table-wrap">
                <table class="ke-table ke-an-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Event', 'kiwi-events' ); ?></th>
                            <?php if ( ! $organizer ) : ?><th><?php esc_html_e( 'Organizer', 'kiwi-events' ); ?></th><?php endif; ?>
                            <th class="ke-an-num"><?php esc_html_e( 'Visits', 'kiwi-events' ); ?></th>
                            <th class="ke-an-num"><?php esc_html_e( 'Ticket clicks', 'kiwi-events' ); ?></th>
                            <th class="ke-an-num"><?php esc_html_e( 'Reservations', 'kiwi-events' ); ?></th>
                            <th class="ke-an-num"><?php esc_html_e( 'Birthday', 'kiwi-events' ); ?></th>
                            <th class="ke-an-num"><?php esc_html_e( 'Shares', 'kiwi-events' ); ?></th>
                            <th class="ke-an-num"><?php esc_html_e( 'Ticket CTR', 'kiwi-events' ); ?></th>
                            <?php if ( $has_series ) : ?><th class="ke-an-trend"><?php esc_html_e( 'Trend', 'kiwi-events' ); ?></th><?php endif; ?>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $events as $ev ) :
                            $t          = $ev['totals'];
                            $date_label = $ev['date'] ? date_i18n( 'M j, Y · g:i A', strtotime( str_replace( 'T', ' ', $ev['date'] ) ) ) : '';
                            $edit_url   = admin_url( 'admin.php?page=ke-event-builder&event_id=' . (int) $ev['id'] );
                        ?>
                            <tr class="<?php echo ( (int) $t['view'] + (int) $ev['clicks'] ) > 0 ? '' : 'ke-an-row--quiet'; ?>">
                                <td class="ke-an-event-cell">
                                    <a class="ke-an-event-title" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $ev['title'] ); ?></a>
                                    <div class="ke-muted ke-an-event-meta">
                                        <?php echo esc_html( $date_label ); ?>
                                        <?php if ( $ev['post_status'] !== 'publish' ) : ?>
                                            · <span class="ke-badge ke-badge-draft"><?php echo esc_html( $ev['post_status'] ); ?></span>
                                        <?php elseif ( $ev['status'] !== 'active' ) : ?>
                                            · <span class="ke-badge ke-badge-<?php echo esc_attr( $ev['status'] ); ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $ev['status'] ) ) ); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <?php if ( ! $organizer ) : ?>
                                    <td class="ke-muted"><?php echo $ev['organizer'] !== '' ? esc_html( $ev['organizer'] ) : '—'; ?></td>
                                <?php endif; ?>
                                <td class="ke-an-num ke-an-num--views"><?php echo esc_html( number_format_i18n( (int) $t['view'] ) ); ?></td>
                                <td class="ke-an-num"><?php echo esc_html( number_format_i18n( (int) $t['ticket_click'] ) ); ?></td>
                                <td class="ke-an-num"><?php echo esc_html( number_format_i18n( (int) $t['reserve_click'] ) ); ?></td>
                                <td class="ke-an-num"><?php echo esc_html( number_format_i18n( (int) $t['birthday_click'] ) ); ?></td>
                                <td class="ke-an-num"><?php echo esc_html( number_format_i18n( (int) $t['share_click'] ) ); ?></td>
                                <td class="ke-an-num"><?php echo esc_html( $ev['ticket_ctr'] ); ?>%</td>
                                <?php if ( $has_series ) : ?>
                                    <td class="ke-an-trend"><?php echo $ev['series'] ? $ke_an_spark( $days, $ev['series']['view'], $ev['series']['clicks'] ) : ''; ?></td>
                                <?php endif; ?>
                                <td class="ke-an-actions">
                                    <a class="ke-btn ke-btn-ghost ke-btn-small" href="<?php echo esc_url( $ev['permalink'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'kiwi-events' ); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ( $can_import ) : ?>
    <!-- Historical visits import (site admins only) -->
    <div class="ke-section-card ke-an-history" id="ke-an-history" data-available="<?php echo $hist_avail ? '1' : '0'; ?>">
        <div class="ke-an-history-head">
            <div>
                <h3><?php esc_html_e( 'Import visit history from WordPress.com Stats', 'kiwi-events' ); ?></h3>
                <p class="ke-muted ke-an-history-intro">
                    <?php esc_html_e( 'The plugin’s own counter only started the day this version went live. WordPress.com Stats has counted views of every event page since the site exists; this brings those days in, event by event, for dates before today only. Days the plugin already counted are never touched, and running it again only fills gaps. WordPress.com counts page views (a repeat open counts twice) while the plugin counts one visit per session, so imported days read a little higher. Clicks cannot be recovered: nothing ever recorded them.', 'kiwi-events' ); ?>
                </p>
            </div>
        </div>

        <div class="ke-an-history-state">
            <?php if ( ! empty( $data_state['rows'] ) ) : ?>
                <?php
                printf(
                    /* translators: 1: visits, 2: clicks, 3: number of events, 4: first date, 5: last date */
                    esc_html__( 'Recorded so far: %1$s visits and %2$s clicks across %3$s events, from %4$s to %5$s.', 'kiwi-events' ),
                    '<strong>' . esc_html( number_format_i18n( (int) $data_state['views'] ) ) . '</strong>',
                    '<strong>' . esc_html( number_format_i18n( (int) $data_state['clicks'] ) ) . '</strong>',
                    esc_html( number_format_i18n( (int) $data_state['events'] ) ),
                    '<strong>' . esc_html( date_i18n( 'M j, Y', strtotime( $data_state['first_day'] ) ) ) . '</strong>',
                    '<strong>' . esc_html( date_i18n( 'M j, Y', strtotime( $data_state['last_day'] ) ) ) . '</strong>'
                );
                ?>
                <?php if ( $data_state['first_day'] === current_time( 'Y-m-d' ) ) : ?>
                    <em><?php esc_html_e( 'Everything recorded is from today — no history has been imported yet.', 'kiwi-events' ); ?></em>
                <?php endif; ?>
            <?php else : ?>
                <?php esc_html_e( 'Nothing recorded yet.', 'kiwi-events' ); ?>
            <?php endif; ?>
        </div>

        <div class="ke-an-history-status">
            <?php if ( ! $hist_avail ) : ?>
                <span class="ke-badge ke-badge-cancelled"><?php esc_html_e( 'Not available', 'kiwi-events' ); ?></span>
                <span class="ke-muted"><?php esc_html_e( 'WordPress.com Stats (Jetpack) is not reachable from this site, so there is nothing to import. On the live site it appears automatically when Jetpack Stats is active.', 'kiwi-events' ); ?></span>
            <?php else : ?>
                <span class="ke-badge ke-badge-active"><?php esc_html_e( 'Available', 'kiwi-events' ); ?></span>
                <span class="ke-muted">
                    <?php echo esc_html( sprintf( _n( '%s event to look at.', '%s events to look at.', $hist_events, 'kiwi-events' ), number_format_i18n( $hist_events ) ) ); ?>
                    <?php if ( $hist_log && ! empty( $hist_log['imported_at'] ) ) : ?>
                        <?php echo esc_html( sprintf( __( 'Last import %1$s: %2$s visits across %3$s days.', 'kiwi-events' ), date_i18n( 'M j, Y · g:i A', strtotime( $hist_log['imported_at'] ) ), number_format_i18n( (int) $hist_log['total_views'] ), number_format_i18n( (int) $hist_log['total_days'] ) ) ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'Nothing imported yet.', 'kiwi-events' ); ?>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="ke-an-history-actions">
            <button type="button" class="ke-btn ke-btn-secondary" id="ke-an-h-preview" <?php disabled( ! $hist_avail ); ?>><?php esc_html_e( 'Preview import', 'kiwi-events' ); ?></button>
            <button type="button" class="ke-btn ke-btn-primary" id="ke-an-h-import" hidden></button>
            <a class="ke-btn ke-btn-ghost" id="ke-an-h-reload" href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'range' => $range ) ), admin_url( 'admin.php' ) ) ); ?>" hidden><?php esc_html_e( 'Reload page', 'kiwi-events' ); ?></a>
        </div>
        <div class="ke-an-history-progress" id="ke-an-h-progress" hidden>
            <div class="ke-an-history-bar"><span id="ke-an-h-bar"></span></div>
            <span class="ke-muted" id="ke-an-h-progress-text"></span>
        </div>
        <div class="ke-an-history-result" id="ke-an-h-result" aria-live="polite"></div>
    </div>
    <?php endif; ?>

</div>
<script>
(function () {
    // Changing the organizer reloads with the same range; no JS state to keep.
    var form = document.getElementById('ke-an-filters');
    var sel  = document.getElementById('ke-an-organizer');
    if (form && sel) sel.addEventListener('change', function () { form.submit(); });
})();
</script>
