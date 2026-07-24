<?php
/**
 * Plugin Name: Kiwi Events — Sold vs Attendees Diagnostic
 * Plugin URI:  https://campuslifepa.com
 * Description: READ-ONLY diagnostic. Reconciles the organizer dashboard "Sold" count (SUM of ke_ticket_types.quantity_sold) against the admin attendee list (COUNT of live ke_tickets rows) for any event, and attributes every discrepancy to a cause: cancelled/refunded rows, archived ticket types, orphaned ticket-type IDs, or counter drift. Does NOT write, update, delete, or repair anything.
 * Version:     1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      Campus Life Panama
 * Author URI:  https://campuslifepa.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ke-sold-audit
 *
 * Deployment: upload this folder to wp-content/plugins/ke-sold-audit/ on
 * production (Plugins → Add New → Upload Plugin, or SFTP), then activate.
 * WordPress.com Business loads only Automattic-managed mu-plugins, so a
 * standard plugin (this) is the supported deployment shape on that platform.
 *
 * This tool is intentionally READ-ONLY. It runs SELECT queries only. Every
 * discrepancy it surfaces is reported for a human to decide the fix — it never
 * touches quantity_sold, tickets, orders, or ticket types.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KE_Sold_Audit {

    const PAGE_SLUG = 'ke-sold-audit';
    const MENU_LABEL = 'KE Sold Audit';

    /** @var string hook suffix returned by add_menu_page, for scoped enqueue */
    private $hook = '';

    public function init() {
        add_action( 'admin_menu',            array( $this, 'register_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_theme' ) );
        add_action( 'admin_head',            array( $this, 'maybe_set_dark_theme' ) );
    }

    /* ──────────────────────────────────────────────────────────────
     *  Menu
     * ────────────────────────────────────────────────────────────── */

    public function register_page() {
        $this->hook = add_menu_page(
            __( 'Sold vs Attendees Diagnostic', 'ke-sold-audit' ),
            self::MENU_LABEL,
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_page' ),
            'dashicons-chart-bar',
            81
        );
    }

    /* ──────────────────────────────────────────────────────────────
     *  Theme — borrow the main plugin's tokens + dark mode so the page
     *  doesn't look broken. The main KE plugin only enqueues these on its
     *  own recognized screens, so we load them ourselves on THIS page.
     * ────────────────────────────────────────────────────────────── */

    public function enqueue_theme( $hook ) {
        if ( $hook !== $this->hook ) {
            return;
        }
        // Pull the brand tokens straight from the active kiwi-events plugin,
        // if present, so var(--kiwi-*) resolves in both light and dark.
        if ( defined( 'KE_PLUGIN_URL' ) ) {
            $ver = defined( 'KE_VERSION' ) ? KE_VERSION : '1.0.0';
            wp_enqueue_style(
                'ke-admin-tokens',
                KE_PLUGIN_URL . 'admin/css/ke-admin-tokens.css',
                array(),
                $ver
            );
        }
    }

    /**
     * Mirror the KE admin color-mode: if the user chose dark in Kiwi Events,
     * stamp <html data-theme="dark"> on our page too so the tokens resolve to
     * their dark values. Read-only meta read.
     */
    public function maybe_set_dark_theme() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->id !== $this->hook ) {
            return;
        }
        $mode = get_user_meta( get_current_user_id(), 'ke_admin_color_mode', true );
        if ( $mode === 'dark' ) {
            echo "<script>document.documentElement.setAttribute('data-theme','dark');</script>\n";
        }
    }

    /* ──────────────────────────────────────────────────────────────
     *  Data (all SELECT — nothing is written)
     * ────────────────────────────────────────────────────────────── */

    private function t( $name ) {
        global $wpdb;
        return $wpdb->prefix . 'ke_' . $name;
    }

    /** Dropdown source: every ke_event, newest first. */
    private function get_events() {
        return get_posts( array(
            'post_type'      => 'ke_event',
            'post_status'    => 'any',
            'numberposts'    => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'suppress_filters' => true,
        ) );
    }

    /**
     * Full read-only analysis for one event. Returns a structured array; the
     * renderer turns it into tables. No mutation anywhere.
     */
    private function analyze( $event_id ) {
        global $wpdb;
        $event_id = (int) $event_id;
        $tk = $this->t( 'tickets' );
        $tt = $this->t( 'ticket_types' );
        $or = $this->t( 'orders' );

        // Headline figures the two surfaces produce.
        $admin_total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tk} WHERE event_id = %d", $event_id ) );
        $dash_sold = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(quantity_sold),0) FROM {$tt}
              WHERE event_id = %d AND (is_archived IS NULL OR is_archived = 0) AND quantity_sold > 0",
            $event_id ) );
        $live_ok = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tk} WHERE event_id = %d AND status != 'cancelled'", $event_id ) );
        $cancelled = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tk} WHERE event_id = %d AND status = 'cancelled'", $event_id ) );

        // Rows by status.
        $by_status = $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) c FROM {$tk} WHERE event_id = %d GROUP BY status ORDER BY c DESC", $event_id ) );

        // Every ticket_type_id referenced by rows, plus every type owned by the event.
        $ref_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT ticket_type_id FROM {$tk} WHERE event_id = %d", $event_id ) );
        $own_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$tt} WHERE event_id = %d", $event_id ) );
        $all_ids = array_values( array_unique( array_map( 'intval', array_merge( $ref_ids, $own_ids ) ) ) );

        $types = array();
        $wrongly_archived = 0;   // non-cancelled rows on archived types
        $wrongly_orphan   = 0;   // non-cancelled rows on missing/foreign types
        $drift_under      = 0;   // live rows beyond qty_sold on live types (under-count)
        $drift_over       = 0;   // qty_sold beyond live rows on live types (over-count)
        $dash_net         = 0.0;

        foreach ( $all_ids as $tid ) {
            $type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tt} WHERE id = %d", $tid ) );
            $rows_all = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tk} WHERE event_id = %d AND ticket_type_id = %d", $event_id, $tid ) );
            $rows_ok  = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tk} WHERE event_id = %d AND ticket_type_id = %d AND status != 'cancelled'", $event_id, $tid ) );
            $cancld   = $rows_all - $rows_ok;

            $exists   = (bool) $type;
            $foreign  = $exists && (int) $type->event_id !== $event_id; // type belongs to a different event
            $archived = $exists && (int) $type->is_archived === 1;
            $qsold    = $exists ? (int) $type->quantity_sold : 0;
            $price    = $exists ? (float) $type->price : 0.0;
            $name     = $exists ? (string) $type->name : '(deleted / unknown)';

            $flag = '';
            if ( ! $exists ) {
                $flag = 'orphan';
                $wrongly_orphan += $rows_ok;
            } elseif ( $foreign ) {
                $flag = 'foreign-event';
                $wrongly_orphan += $rows_ok;
            } elseif ( $archived ) {
                if ( $rows_ok > 0 ) { $flag = 'archived-with-sales'; $wrongly_archived += $rows_ok; }
                else { $flag = 'archived'; }
            } else {
                // Live, non-archived type — belongs in the dashboard breakdown.
                if ( $qsold !== $rows_ok ) {
                    $flag = 'drift';
                    if ( $rows_ok > $qsold ) { $drift_under += ( $rows_ok - $qsold ); }
                    else { $drift_over += ( $qsold - $rows_ok ); }
                }
                if ( $qsold > 0 && $price > 0 ) {
                    $dash_net += $qsold * $price; // mirrors dashboard net model
                }
            }

            $types[] = array(
                'id'       => $tid,
                'name'     => $name,
                'exists'   => $exists,
                'archived' => $archived,
                'foreign'  => $foreign,
                'qsold'    => $qsold,
                'price'    => $price,
                'rows_all' => $rows_all,
                'rows_ok'  => $rows_ok,
                'cancld'   => $cancld,
                'flag'     => $flag,
                'in_dash'  => ( $exists && ! $archived && ! $foreign && $qsold > 0 ),
            );
        }

        // Orders behind these tickets, by payment_status.
        $orders = $wpdb->get_results( $wpdb->prepare(
            "SELECT COALESCE(o.payment_status,'(no order)') ps,
                    COUNT(DISTINCT o.id) orders, COUNT(t.id) tickets
               FROM {$tk} t LEFT JOIN {$or} o ON o.id = t.order_id
              WHERE t.event_id = %d
           GROUP BY ps ORDER BY tickets DESC", $event_id ) );

        return array(
            'event_id'         => $event_id,
            'admin_total'      => $admin_total,
            'dash_sold'        => $dash_sold,
            'live_ok'          => $live_ok,
            'cancelled'        => $cancelled,
            'by_status'        => $by_status,
            'types'            => $types,
            'orders'           => $orders,
            'dash_net'         => round( $dash_net, 2 ),
            'wrongly_archived' => $wrongly_archived,
            'wrongly_orphan'   => $wrongly_orphan,
            'drift_under'      => $drift_under,
            'drift_over'       => $drift_over,
        );
    }

    /* ──────────────────────────────────────────────────────────────
     *  Render
     * ────────────────────────────────────────────────────────────── */

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $events   = $this->get_events();
        $event_id = isset( $_GET['event_id'] ) ? (int) $_GET['event_id'] : 0;

        echo '<div class="wrap ke-sold-audit">';
        $this->print_styles();

        echo '<h1>Sold vs Attendees Diagnostic</h1>';
        echo '<p class="kesa-lead">READ-ONLY. Reconciles the organizer dashboard <strong>Sold</strong> figure '
           . '(<code>SUM(ke_ticket_types.quantity_sold)</code>, non-archived) against the admin attendee list '
           . '(<code>COUNT</code> of live <code>ke_tickets</code> rows) for one event, and attributes every '
           . 'missing ticket to a cause. This tool never writes, updates, deletes, or repairs anything.</p>';

        if ( ! defined( 'KE_PLUGIN_URL' ) ) {
            echo '<div class="kesa-warn">Kiwi Events core plugin not detected — the page still works, '
               . 'but brand styling/dark mode may be reduced.</div>';
        }

        // Event selector.
        echo '<form method="get" class="kesa-form">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
        echo '<label for="kesa-event">Event:</label> ';
        echo '<select name="event_id" id="kesa-event">';
        echo '<option value="0">— select an event —</option>';
        foreach ( $events as $ev ) {
            $date = get_post_meta( $ev->ID, '_ke_event_date_start', true );
            $label = $ev->post_title . ( $date ? '  (' . esc_html( $date ) . ')' : '' )
                   . '  [#' . $ev->ID . ', ' . $ev->post_status . ']';
            printf(
                '<option value="%d" %s>%s</option>',
                (int) $ev->ID,
                selected( $event_id, (int) $ev->ID, false ),
                esc_html( $label )
            );
        }
        echo '</select> ';
        echo '<button type="submit" class="button button-primary">Run Diagnostic</button>';
        echo '</form>';

        if ( $event_id <= 0 ) {
            echo '<p class="kesa-muted">Pick an event above to run the reconciliation.</p></div>';
            return;
        }

        $post = get_post( $event_id );
        if ( ! $post || $post->post_type !== 'ke_event' ) {
            echo '<div class="kesa-warn">That event ID is not a ke_event.</div></div>';
            return;
        }

        $a = $this->analyze( $event_id );

        echo '<h2 class="kesa-h2">' . esc_html( $post->post_title ) . ' <span class="kesa-muted">#' . $event_id . '</span></h2>';

        /* ---- Headline cards ---- */
        $gap = $a['admin_total'] - $a['dash_sold'];
        echo '<div class="kesa-cards">';
        $this->card( 'Admin attendee list', $a['admin_total'], 'COUNT of all ke_tickets rows (incl. cancelled)' );
        $this->card( 'Dashboard "Sold"', $a['dash_sold'], 'SUM quantity_sold, non-archived types' );
        $this->card( 'Live non-cancelled rows', $a['live_ok'], 'ke_tickets where status != cancelled' );
        $this->card( 'Gap (admin − dashboard)', $gap, 'rows the dashboard does not count', $gap !== 0 ? 'bad' : 'good' );
        echo '</div>';

        /* ---- Rows by status ---- */
        echo '<h3 class="kesa-h3">Rows by status</h3>';
        echo '<table class="widefat striped kesa-table"><thead><tr><th>Status</th><th class="num">Count</th></tr></thead><tbody>';
        foreach ( $a['by_status'] as $r ) {
            echo '<tr><td>' . esc_html( $r->status ) . '</td><td class="num">' . (int) $r->c . '</td></tr>';
        }
        echo '</tbody></table>';

        /* ---- Per ticket type ---- */
        echo '<h3 class="kesa-h3">Per ticket type — live rows vs <code>quantity_sold</code> counter</h3>';
        echo '<table class="widefat striped kesa-table"><thead><tr>';
        foreach ( array( 'Type ID', 'Name', 'Archived?', 'qty_sold', 'Rows (all)', 'Rows (non-cancelled)', 'Cancelled', 'In dashboard?', 'Flag' ) as $h ) {
            $cls = in_array( $h, array( 'qty_sold', 'Rows (all)', 'Rows (non-cancelled)', 'Cancelled' ), true ) ? ' class="num"' : '';
            echo '<th' . $cls . '>' . esc_html( $h ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( $a['types'] as $t ) {
            $flag_html = $this->flag_badge( $t );
            $row_cls = $t['flag'] && $t['flag'] !== 'archived' ? ' class="kesa-rowbad"' : '';
            echo '<tr' . $row_cls . '>';
            echo '<td>' . (int) $t['id'] . '</td>';
            echo '<td>' . esc_html( $t['name'] );
            if ( $t['price'] > 0 ) echo ' <span class="kesa-muted">($' . number_format( $t['price'], 2 ) . ')</span>';
            echo '</td>';
            echo '<td>' . ( ! $t['exists'] ? '—' : ( $t['archived'] ? '<strong>YES</strong>' : 'no' ) ) . '</td>';
            echo '<td class="num">' . ( $t['exists'] ? (int) $t['qsold'] : '—' ) . '</td>';
            echo '<td class="num">' . (int) $t['rows_all'] . '</td>';
            echo '<td class="num">' . (int) $t['rows_ok'] . '</td>';
            echo '<td class="num">' . (int) $t['cancld'] . '</td>';
            echo '<td>' . ( $t['in_dash'] ? '✓' : '—' ) . '</td>';
            echo '<td>' . $flag_html . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p class="kesa-legend"><strong>Flags:</strong> '
           . '<span class="kesa-badge bad">archived-with-sales</span> real, non-cancelled sales on an archived type — dropped by the dashboard. '
           . '<span class="kesa-badge bad">orphan</span> / <span class="kesa-badge bad">foreign-event</span> ticket_type_id that no longer resolves to this event — dropped. '
           . '<span class="kesa-badge bad">drift</span> counter ≠ live rows on a live type. '
           . '<span class="kesa-badge">archived</span> archived with no live sales (harmless).</p>';

        /* ---- Orders by payment_status ---- */
        echo '<h3 class="kesa-h3">Orders behind these tickets, by payment status</h3>';
        echo '<table class="widefat striped kesa-table"><thead><tr><th>Payment status</th><th class="num">Orders</th><th class="num">Tickets</th></tr></thead><tbody>';
        foreach ( $a['orders'] as $r ) {
            echo '<tr><td>' . esc_html( $r->ps ) . '</td><td class="num">' . (int) $r->orders . '</td><td class="num">' . (int) $r->tickets . '</td></tr>';
        }
        echo '</tbody></table>';

        /* ---- Net revenue reconciliation ---- */
        echo '<h3 class="kesa-h3">Net revenue reconciliation</h3>';
        echo '<p class="kesa-p">Dashboard net model = <code>SUM(quantity_sold × base price)</code> over non-archived paid types: '
           . '<strong>$' . number_format( $a['dash_net'], 2 ) . '</strong>. '
           . 'Compare this to the figure shown on the organizer dashboard for the same event/range. '
           . 'It is derived from the same <code>quantity_sold</code> counter as the Sold count, so if Sold is wrong, this is wrong by the same rows.</p>';

        /* ---- Verdict ---- */
        $legit   = $a['cancelled'];
        $wrong   = $a['wrongly_archived'] + $a['wrongly_orphan'] + $a['drift_under'];
        echo '<h3 class="kesa-h3">Reconciliation of the gap</h3>';
        echo '<table class="widefat kesa-table kesa-verdict"><tbody>';
        $this->vrow( 'Gap (admin − dashboard)', $gap, '' );
        $this->vrow( 'Cancelled rows', $legit, 'legitimately excluded from “sold”', 'good' );
        $this->vrow( 'Non-cancelled rows on ARCHIVED types', $a['wrongly_archived'], 'real sales wrongly excluded', $a['wrongly_archived'] ? 'bad' : '' );
        $this->vrow( 'Non-cancelled rows on ORPHAN/foreign types', $a['wrongly_orphan'], 'real sales wrongly excluded', $a['wrongly_orphan'] ? 'bad' : '' );
        $this->vrow( 'Counter drift — live rows beyond qty_sold', $a['drift_under'], 'real sales wrongly excluded', $a['drift_under'] ? 'bad' : '' );
        $this->vrow( 'Counter drift — qty_sold beyond live rows', $a['drift_over'], 'dashboard over-counts these', $a['drift_over'] ? 'bad' : '' );
        echo '</tbody></table>';

        echo '<div class="kesa-conclude">';
        if ( $wrong === 0 && $legit > 0 ) {
            echo '<strong>Read:</strong> the entire gap is cancelled tickets. The dashboard’s <strong>'
               . (int) $a['dash_sold'] . '</strong> is the correct “sold” figure; the attendee list is over-inclusive because it counts cancelled rows.';
        } elseif ( $wrong > 0 ) {
            echo '<strong>Read:</strong> <strong>' . (int) $wrong . '</strong> real, non-cancelled sales are being dropped by the dashboard '
               . '(archived/orphan types and/or counter drift). The dashboard <strong>under-counts</strong>; the true paid figure is closer to the '
               . '<strong>' . (int) $a['live_ok'] . '</strong> live non-cancelled rows. See the flagged rows above for exactly which tickets.';
        } else {
            echo '<strong>Read:</strong> no discrepancy detected for this event — admin and dashboard agree (or differ only by counter over-count, shown above).';
        }
        echo '</div>';

        echo '<p class="kesa-muted">All figures produced by SELECT queries. Nothing on this page modifies data.</p>';
        echo '</div>';
    }

    private function card( $label, $value, $sub, $tone = '' ) {
        $cls = 'kesa-card' . ( $tone ? ' kesa-' . $tone : '' );
        echo '<div class="' . esc_attr( $cls ) . '">';
        echo '<div class="kesa-card-v">' . esc_html( (string) $value ) . '</div>';
        echo '<div class="kesa-card-l">' . esc_html( $label ) . '</div>';
        echo '<div class="kesa-card-s">' . esc_html( $sub ) . '</div>';
        echo '</div>';
    }

    private function vrow( $label, $value, $note, $tone = '' ) {
        $vcls = $tone === 'bad' ? ' style="color:var(--kiwi-red,#b91c1c);font-weight:700;"'
              : ( $tone === 'good' ? ' style="color:var(--kiwi-green-text,#15803d);font-weight:700;"' : '' );
        echo '<tr><td>' . esc_html( $label ) . '</td>';
        echo '<td class="num"' . $vcls . '>' . (int) $value . '</td>';
        echo '<td class="kesa-muted">' . esc_html( $note ) . '</td></tr>';
    }

    private function flag_badge( $t ) {
        if ( ! $t['flag'] ) return '<span class="kesa-badge">ok</span>';
        $bad = $t['flag'] !== 'archived';
        $txt = $t['flag'];
        if ( $t['flag'] === 'drift' ) {
            $txt = sprintf( 'drift (qty_sold=%d, rows=%d)', (int) $t['qsold'], (int) $t['rows_ok'] );
        }
        return '<span class="kesa-badge' . ( $bad ? ' bad' : '' ) . '">' . esc_html( $txt ) . '</span>';
    }

    /**
     * Minimal styling using --kiwi-* tokens with light literal fallbacks so
     * the page is legible even if the core tokens stylesheet is absent.
     */
    private function print_styles() {
        ?>
        <style>
        .ke-sold-audit { --c-surface: var(--kiwi-surface, #fff); --c-text: var(--kiwi-text, #1d1d1f);
            --c-muted: var(--kiwi-text-muted, #6e6e73); --c-border: var(--kiwi-border, rgba(0,0,0,.1));
            --c-bad: var(--kiwi-red, #b91c1c); --c-good: var(--kiwi-green-text, #15803d);
            --c-radius: var(--kiwi-radius-md, 10px); color: var(--c-text); }
        .ke-sold-audit .kesa-lead { max-width: 820px; color: var(--c-muted); }
        .ke-sold-audit code { background: var(--kiwi-surface-muted, #f1f5f9); padding: 1px 5px; border-radius: 4px; }
        .ke-sold-audit .kesa-form { margin: 18px 0; padding: 14px; background: var(--c-surface);
            border: 1px solid var(--c-border); border-radius: var(--c-radius); display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .ke-sold-audit .kesa-form select { min-width: 340px; max-width: 100%; }
        .ke-sold-audit .kesa-warn { margin: 14px 0; padding: 10px 14px; border-radius: var(--c-radius);
            background: rgba(var(--kiwi-red-rgb, 185,28,28), .12); border: 1px solid var(--c-bad); color: var(--c-bad); }
        .ke-sold-audit .kesa-muted { color: var(--c-muted); }
        .ke-sold-audit .kesa-h2 { margin-top: 8px; }
        .ke-sold-audit .kesa-h3 { margin-top: 26px; border-bottom: 1px solid var(--c-border); padding-bottom: 6px; }
        .ke-sold-audit .kesa-cards { display: flex; gap: 14px; flex-wrap: wrap; margin: 16px 0; }
        .ke-sold-audit .kesa-card { flex: 1 1 180px; min-width: 180px; padding: 14px 16px; background: var(--c-surface);
            border: 1px solid var(--c-border); border-radius: var(--c-radius); }
        .ke-sold-audit .kesa-card-v { font-size: 30px; font-weight: 700; line-height: 1.1; }
        .ke-sold-audit .kesa-card-l { font-weight: 600; margin-top: 4px; }
        .ke-sold-audit .kesa-card-s { font-size: 12px; color: var(--c-muted); margin-top: 2px; }
        .ke-sold-audit .kesa-card.kesa-bad .kesa-card-v { color: var(--c-bad); }
        .ke-sold-audit .kesa-card.kesa-good .kesa-card-v { color: var(--c-good); }
        .ke-sold-audit .kesa-table { margin: 10px 0; max-width: 1000px; }
        .ke-sold-audit .kesa-table .num { text-align: right; font-variant-numeric: tabular-nums; }
        .ke-sold-audit .kesa-table td, .ke-sold-audit .kesa-table th { color: var(--c-text); }
        .ke-sold-audit .kesa-rowbad td { background: rgba(var(--kiwi-red-rgb, 185,28,28), .08) !important; }
        .ke-sold-audit .kesa-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px;
            font-weight: 600; background: var(--kiwi-surface-muted, #eef2f7); color: var(--c-muted); }
        .ke-sold-audit .kesa-badge.bad { background: rgba(var(--kiwi-red-rgb, 185,28,28), .15); color: var(--c-bad); }
        .ke-sold-audit .kesa-legend { max-width: 1000px; font-size: 12px; color: var(--c-muted); line-height: 1.9; }
        .ke-sold-audit .kesa-verdict { max-width: 720px; }
        .ke-sold-audit .kesa-conclude { max-width: 720px; margin: 14px 0; padding: 12px 16px; border-radius: var(--c-radius);
            background: var(--c-surface); border: 1px solid var(--c-border); }
        </style>
        <?php
    }
}

add_action( 'plugins_loaded', function () {
    ( new KE_Sold_Audit() )->init();
} );
