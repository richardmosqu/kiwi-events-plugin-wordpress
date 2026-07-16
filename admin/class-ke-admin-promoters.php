<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Promoters admin module (Phase 1 — list + create/edit, status toggle, delete).
 *
 * Lists, search, sort by name/email/status. New/edit form on the same page
 * via ?action=edit&id=N. Uses admin-post.php for form submissions so we get
 * proper redirect + flash-message behavior.
 *
 * Lists, commission overrides, and CSV import land in Phase 2.
 */
class KE_Admin_Promoters {

    const PER_PAGE = 25;

    public function init() {
        add_action( 'admin_post_ke_save_promoter',   array( $this, 'handle_save' ) );
        add_action( 'admin_post_ke_delete_promoter', array( $this, 'handle_delete' ) );
        add_action( 'admin_post_ke_unlink_promoter_user', array( $this, 'handle_unlink_user' ) );
        add_action( 'admin_post_ke_resend_promoter_welcome', array( $this, 'handle_resend_welcome' ) );
        add_action( 'admin_post_ke_reconcile_attribute_order', array( $this, 'handle_reconcile_attribute' ) );
        add_action( 'admin_post_ke_mark_commissions_paid', array( $this, 'handle_mark_paid' ) );
        add_action( 'admin_post_ke_export_promoter_commissions', array( $this, 'handle_export_csv' ) );
        add_action( 'admin_post_ke_save_promoter_settings',      array( $this, 'handle_save_settings' ) );

        add_action( 'admin_post_ke_save_promoter_list',          array( $this, 'handle_save_list' ) );
        add_action( 'admin_post_ke_delete_promoter_list',        array( $this, 'handle_delete_list' ) );
        add_action( 'admin_post_ke_save_promoter_list_members',  array( $this, 'handle_save_list_members' ) );
        add_action( 'admin_post_ke_assign_list_to_event',        array( $this, 'handle_assign_list_to_event' ) );
        add_action( 'admin_post_ke_import_promoters_csv',        array( $this, 'handle_import_csv' ) );
        add_action( 'admin_post_ke_event_mark_all_paid',         array( $this, 'handle_event_mark_all_paid' ) );
        add_action( 'admin_post_ke_event_export_csv',            array( $this, 'handle_event_export_csv' ) );
        add_action( 'admin_post_ke_event_export_pdf',            array( $this, 'handle_event_export_pdf' ) );

        // REST endpoint for the live activity feed (CHANGE 4).
        add_action( 'rest_api_init', array( $this, 'register_rest_endpoints' ) );
    }

    /**
     * REST: GET /kiwi-events/v1/promoters/event/{id}/activity
     * Returns the 10 most recent commission rows for one event, JSON-shaped
     * for the dashboard's 30-second poll.
     */
    public function register_rest_endpoints() {
        register_rest_route( 'kiwi-events/v1', '/promoters/event/(?P<id>\d+)/activity', array(
            'methods'  => 'GET',
            'permission_callback' => function () {
                return current_user_can( 'manage_kiwi_events' ) || current_user_can( 'manage_options' );
            },
            'callback' => function ( WP_REST_Request $req ) {
                $event_id = (int) $req['id'];
                $rows = KE_Promoter_Commissions::event_recent_activity( $event_id, 10 );
                $currency = (string) get_option( 'ke_promoter_currency_label', '$' );
                $out = array();
                foreach ( $rows as $r ) {
                    $out[] = array(
                        'id'                 => (int) $r->id,
                        'created_at'         => $r->created_at,
                        'created_at_ts'      => strtotime( $r->created_at . ' UTC' ),
                        'commission_amount'  => (float) $r->commission_amount,
                        'commission_label'   => $currency . number_format( (float) $r->commission_amount, 2 ),
                        'status'             => (string) $r->status,
                        'buyer_name'         => (string) $r->buyer_name,
                        'promoter_name'      => (string) ( $r->promoter_name ?? '' ),
                        'attribution_method' => (string) ( $r->attribution_method ?? 'session' ),
                    );
                }
                return rest_ensure_response( array( 'rows' => $out, 'server_now' => time() ) );
            },
        ) );
    }

    public function handle_assign_list_to_event() {
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );

        $list_id = isset( $_POST['list_id'] ) ? absint( $_POST['list_id'] ) : 0;
        if ( ! $list_id ) wp_die( 'Missing list id.' );

        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ke_assign_list_to_event_' . $list_id ) ) wp_die( 'Security check failed.' );

        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        $type     = isset( $_POST['commission_type'] ) ? sanitize_text_field( $_POST['commission_type'] ) : 'percentage';
        $value    = isset( $_POST['commission_value'] ) ? floatval( $_POST['commission_value'] ) : 0;

        if ( ! $event_id || get_post_type( $event_id ) !== 'ke_event' ) {
            $this->set_flash( 'error', 'Select a valid event before applying the list.' );
            wp_safe_redirect( admin_url( 'admin.php?page=ke-promoters&action=list_edit&list_id=' . $list_id ) );
            exit;
        }

        $written = KE_Promoter_Lists::assign_list_to_event( $list_id, $event_id, $type, $value );
        $ev_title = get_the_title( $event_id );

        if ( $written > 0 ) {
            // Fire assignment emails for the rows we actually inserted.
            if ( class_exists( 'KE_Promoter_Notifications' ) && ! empty( KE_Promoter_Lists::$last_newly_assigned_ids ) ) {
                KE_Promoter_Notifications::queue_assignment_emails( $event_id, KE_Promoter_Lists::$last_newly_assigned_ids );
            }
            $this->set_flash( 'success', sprintf( '%d promoter(s) assigned to "%s". Notification emails are queued.', $written, $ev_title ) );
        } else {
            $this->set_flash( 'success', sprintf( 'No new assignments — every list member was already on "%s".', $ev_title ) );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=ke-promoters&action=list_edit&list_id=' . $list_id ) );
        exit;
    }

    /**
     * Persist the Promoters settings block on the System Settings page.
     */
    public function handle_save_settings() {
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ke_save_promoter_settings' ) ) {
            wp_die( 'Security check failed.' );
        }

        update_option(
            'ke_promoter_prevent_self_attribution',
            ! empty( $_POST['prevent_self'] ) ? '1' : '0'
        );
        update_option(
            'ke_promoter_notify_on_earn',
            ! empty( $_POST['notify_on_earn'] ) ? '1' : '0'
        );

        $policy = isset( $_POST['refund_policy'] ) ? sanitize_text_field( $_POST['refund_policy'] ) : 'keep';
        if ( ! in_array( $policy, array( 'keep', 'void' ), true ) ) $policy = 'keep';
        update_option( 'ke_promoter_refund_policy', $policy );

        $def_type = isset( $_POST['default_commission_type'] ) ? sanitize_text_field( $_POST['default_commission_type'] ) : 'percentage';
        if ( ! in_array( $def_type, array( 'percentage', 'fixed' ), true ) ) $def_type = 'percentage';
        update_option( 'ke_promoter_default_commission_type', $def_type );

        $def_val = isset( $_POST['default_commission_value'] ) ? floatval( $_POST['default_commission_value'] ) : 0;
        if ( $def_val < 0 ) $def_val = 0;
        if ( $def_type === 'percentage' && $def_val > 100 ) $def_val = 100;
        update_option( 'ke_promoter_default_commission_value', $def_val );

        $currency = isset( $_POST['currency_label'] ) ? sanitize_text_field( $_POST['currency_label'] ) : '$';
        if ( $currency === '' ) $currency = '$';
        update_option( 'ke_promoter_currency_label', substr( $currency, 0, 4 ) );

        $global_terms = isset( $_POST['global_terms'] ) ? wp_kses_post( wp_unslash( $_POST['global_terms'] ) ) : '';
        update_option( 'ke_promoter_global_terms', $global_terms );

        // Flash via the same transient pattern as the rest of the page.
        set_transient(
            'ke_promoter_flash_' . get_current_user_id(),
            array( 'type' => 'success', 'message' => 'Promoter settings saved.' ),
            30
        );

        $redirect = admin_url( 'admin.php?page=ke-settings#ke-promoter-settings' );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Render the page. Branches between list view and edit form based on
     * the `action` query param.
     */
    public function render() {
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized access.' );
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

        if ( $action === 'new' || $action === 'edit' ) {
            $this->render_form();
            return;
        }

        if ( $action === 'commissions' ) {
            $this->render_commissions();
            return;
        }

        if ( $action === 'lists' || $action === 'list_edit' || $action === 'list_new' ) {
            $this->render_lists();
            return;
        }

        if ( $action === 'import' ) {
            $this->render_import();
            return;
        }

        if ( $action === 'stats' ) {
            $this->render_stats();
            return;
        }

        if ( $action === 'active_events' ) {
            $this->render_active_events();
            return;
        }

        if ( $action === 'emails' ) {
            $this->render_emails();
            return;
        }

        if ( $action === 'event_dashboard' ) {
            $this->render_event_dashboard();
            return;
        }

        if ( $action === 'reconcile' ) {
            $this->render_reconcile();
            return;
        }

        $this->render_list();
    }

    /**
     * Reconcile tool — surfaces paid KE orders that have no commission row
     * attached, and lets the admin manually attribute each one to a promoter.
     * Backstop for sessions where attribution was lost in production (the
     * subject of Item #3) so revenue events aren't permanently orphaned.
     */
    private function render_reconcile() {
        global $wpdb;
        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        $candidates = KE_Promoter_Commissions::unattributed_orders( array(
            'limit'        => 100,
            'event_id'     => $event_id,
            'include_free' => false,
        ) );

        // For the dropdown — only active promoters.
        $promoters = $wpdb->get_results( "
            SELECT p.id, p.slug,
                   COALESCE(NULLIF(u.display_name,''), u.user_login, p.slug) AS name
              FROM {$wpdb->prefix}ke_promoters p
         LEFT JOIN {$wpdb->users} u ON u.ID = p.user_id
             WHERE p.status = 'active'
          ORDER BY name ASC"
        );

        // Events for the filter dropdown.
        $events = get_posts( array(
            'post_type'      => 'ke_event',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        $flash = $this->get_flash();
        ?>
        <div class="wrap">
          <h1><?php esc_html_e( 'Reconcile Promoter Attributions', 'kiwi-events' ); ?></h1>
          <p class="description">
            <?php esc_html_e( 'Paid orders with no commission row. Manually attribute each to a promoter if it should have been captured organically.', 'kiwi-events' ); ?>
          </p>
          <?php if ( $flash ) : ?>
            <div class="notice notice-<?php echo esc_attr( $flash['type'] === 'success' ? 'success' : 'error' ); ?>"><p><?php echo esc_html( $flash['msg'] ); ?></p></div>
          <?php endif; ?>

          <form method="get" style="margin:12px 0;">
            <input type="hidden" name="page" value="ke-promoters">
            <input type="hidden" name="action" value="reconcile">
            <label><?php esc_html_e( 'Filter by event:', 'kiwi-events' ); ?>
              <select name="event_id" onchange="this.form.submit()">
                <option value="0"><?php esc_html_e( 'All events', 'kiwi-events' ); ?></option>
                <?php foreach ( $events as $ev ) : ?>
                  <option value="<?php echo esc_attr( $ev->ID ); ?>" <?php selected( $event_id, $ev->ID ); ?>><?php echo esc_html( $ev->post_title ); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </form>

          <?php if ( empty( $candidates ) ) : ?>
            <p><em><?php esc_html_e( 'No unattributed paid orders found.', 'kiwi-events' ); ?></em></p>
          <?php else : ?>
            <table class="widefat striped">
              <thead>
                <tr>
                  <th><?php esc_html_e( 'Order', 'kiwi-events' ); ?></th>
                  <th><?php esc_html_e( 'Event', 'kiwi-events' ); ?></th>
                  <th><?php esc_html_e( 'Buyer', 'kiwi-events' ); ?></th>
                  <th><?php esc_html_e( 'Amount', 'kiwi-events' ); ?></th>
                  <th><?php esc_html_e( 'Date', 'kiwi-events' ); ?></th>
                  <th style="min-width:280px;"><?php esc_html_e( 'Attribute to', 'kiwi-events' ); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ( $candidates as $c ) : ?>
                  <tr>
                    <td>#<?php echo esc_html( $c->order_number ?: $c->id ); ?></td>
                    <td><?php echo esc_html( $c->event_title ); ?></td>
                    <td>
                      <?php echo esc_html( $c->buyer_name ?: '—' ); ?><br>
                      <small><?php echo esc_html( $c->buyer_email ); ?></small>
                    </td>
                    <td>$<?php echo esc_html( number_format( (float) $c->total_amount, 2 ) ); ?></td>
                    <td><?php echo esc_html( $c->created_at ); ?></td>
                    <td>
                      <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;">
                        <?php wp_nonce_field( 'ke_reconcile_attribute_order_' . $c->id ); ?>
                        <input type="hidden" name="action" value="ke_reconcile_attribute_order">
                        <input type="hidden" name="ke_order_id" value="<?php echo esc_attr( $c->id ); ?>">
                        <select name="promoter_id" required style="flex:1;">
                          <option value=""><?php esc_html_e( 'Select promoter…', 'kiwi-events' ); ?></option>
                          <?php foreach ( $promoters as $p ) : ?>
                            <option value="<?php echo esc_attr( $p->id ); ?>"><?php echo esc_html( $p->name ); ?> (<?php echo esc_html( $p->slug ); ?>)</option>
                          <?php endforeach; ?>
                        </select>
                        <button class="button button-primary" type="submit"><?php esc_html_e( 'Attribute', 'kiwi-events' ); ?></button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handler for the Reconcile tool's per-row form. Writes one commission
     * row per ticket using the (event,promoter) assignment's current terms.
     */
    public function handle_reconcile_attribute() {
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $ke_order_id = isset( $_POST['ke_order_id'] ) ? absint( $_POST['ke_order_id'] ) : 0;
        $promoter_id = isset( $_POST['promoter_id'] ) ? absint( $_POST['promoter_id'] ) : 0;

        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ke_reconcile_attribute_order_' . $ke_order_id ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! $ke_order_id || ! $promoter_id ) {
            $this->set_flash( 'error', 'Missing order or promoter.' );
        } else {
            $written = KE_Promoter_Commissions::manually_attribute_order( $ke_order_id, $promoter_id );
            if ( $written > 0 ) {
                $this->set_flash( 'success', sprintf( 'Wrote %d commission row(s).', $written ) );
            } else {
                $this->set_flash( 'error', 'No commission rows written — promoter may not be assigned to this event.' );
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=ke-promoters&action=reconcile' ) );
        exit;
    }

    /* ─── Per-event dashboard (CHANGE 4) ────────────────────────────── */

    private function render_event_dashboard() {
        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        if ( ! $event_id || get_post_type( $event_id ) !== 'ke_event' ) {
            wp_die( 'Event not found.' );
        }

        $event       = get_post( $event_id );
        $perf        = KE_Promoter_Commissions::event_promoter_performance( $event_id );
        $totals      = KE_Promoter_Commissions::event_totals( $event_id );
        $top3        = array_slice( $perf, 0, 3 );
        $recent      = KE_Promoter_Commissions::event_recent_activity( $event_id, 10 );
        $flash       = $this->consume_flash();
        $currency    = (string) get_option( 'ke_promoter_currency_label', '$' );

        include KE_PLUGIN_DIR . 'admin/views/event-promoters-dashboard.php';
    }

    /**
     * Bulk mark-all-paid for one event. Wired in init().
     */
    public function handle_event_mark_all_paid() {
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        if ( ! $event_id ) wp_die( 'Missing event id.' );
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ke_event_mark_all_paid_' . $event_id ) ) {
            wp_die( 'Security check failed.' );
        }
        $note = isset( $_POST['note'] ) ? sanitize_text_field( $_POST['note'] ) : 'Bulk: marked from event dashboard';
        $n = KE_Promoter_Commissions::mark_all_paid_for_event( $event_id, $note );
        $this->set_flash( 'success', sprintf( '%d commission row(s) marked paid.', $n ) );
        wp_safe_redirect( admin_url( 'admin.php?page=ke-promoters&action=event_dashboard&event_id=' . $event_id ) );
        exit;
    }

    /**
     * Stream a CSV of every commission for one event.
     */
    public function handle_event_export_csv() {
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        if ( ! $event_id ) wp_die( 'Missing event id.' );
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ke_event_export_csv_' . $event_id ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( get_post_type( $event_id ) !== 'ke_event' ) wp_die( 'Event not found.' );

        KE_Promoter_Commissions::stream_csv_for_event( $event_id );
        exit;
    }

    /**
     * Stream a printable HTML report for one event's promoter performance.
     * Served as HTML with an auto-open Print dialog — the browser's Print →
     * Save as PDF gives the operator a clean per-event PDF without pulling
     * in FPDF (which is wired only for the organizer-wide sales report and
     * expects an organizer term, not a single ke_event).
     */
    public function handle_event_export_pdf() {
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        if ( ! $event_id ) wp_die( 'Missing event id.' );
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ke_event_export_pdf_' . $event_id ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( get_post_type( $event_id ) !== 'ke_event' ) wp_die( 'Event not found.' );

        $event    = get_post( $event_id );
        $perf     = KE_Promoter_Commissions::event_promoter_performance( $event_id );
        $totals   = KE_Promoter_Commissions::event_totals( $event_id );
        $currency = (string) get_option( 'ke_promoter_currency_label', '$' );

        $event_date       = get_post_meta( $event_id, '_ke_event_date_start', true );
        $event_date_label = $event_date ? date_i18n( 'F j, Y', strtotime( $event_date ) ) : '';
        $generated_label  = date_i18n( 'F j, Y · H:i', current_time( 'timestamp' ) );

        $ui_settings = get_option( 'ke_ui_settings', array() );
        $accent      = ! empty( $ui_settings['accent_color'] )
                       ? sanitize_hex_color( $ui_settings['accent_color'] ) : '#6366f1';

        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );
        ?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
<meta charset="utf-8">
<title><?php echo esc_html( $event->post_title . ' — Promoter report' ); ?></title>
<style>
    :root { --accent: <?php echo esc_html( $accent ); ?>; --text:#0f172a; --muted:#64748b; --soft:#e2e8f0; }
    * { box-sizing: border-box; }
    html, body { margin:0; padding:0; background:#f5f6fa; color:var(--text); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
    .wrap { max-width: 920px; margin: 0 auto; padding: 28px; }
    .bar { background:#fff; border:1px solid var(--soft); border-radius:12px; padding:14px 18px; display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; }
    .bar p { margin:0; font-size:13px; color:var(--muted); }
    .bar button { padding:8px 18px; border:none; border-radius:999px; background:var(--accent); color:#fff; font-weight:600; font-size:13px; cursor:pointer; }
    .cover { background:var(--accent); color:#fff; border-radius:16px; padding:32px; margin-bottom:24px; }
    .cover .eyebrow { text-transform:uppercase; letter-spacing:0.12em; font-size:11px; opacity:0.85; }
    .cover h1 { margin:6px 0 4px; font-size:26px; font-weight:800; }
    .cover .sub { font-size:14px; opacity:0.95; }
    .meta { background:#fff; border:1px solid var(--soft); border-radius:12px; padding:18px 22px; margin-bottom:24px; display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
    .meta .k { font-size:11px; text-transform:uppercase; letter-spacing:0.08em; color:var(--muted); }
    .meta .v { font-size:14px; font-weight:700; color:var(--text); margin-top:2px; }
    section { background:#fff; border:1px solid var(--soft); border-radius:12px; padding:22px; margin-bottom:18px; }
    section h2 { margin:0 0 14px; font-size:16px; font-weight:700; }
    .grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
    .stat { background:#fafbfc; border:1px solid var(--soft); border-radius:10px; padding:14px 16px; }
    .stat .k { font-size:11px; text-transform:uppercase; letter-spacing:0.08em; color:var(--muted); margin-bottom:4px; }
    .stat .v { font-size:22px; font-weight:800; color:var(--text); font-variant-numeric:tabular-nums; }
    .stat .v.accent { color: var(--accent); }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    table th { text-align:left; padding:10px 12px; background:var(--accent); color:#fff; font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:0.06em; }
    table td { padding:10px 12px; border-bottom:1px solid var(--soft); color:#1f2937; }
    table tr:nth-child(even) td { background:#fafbfc; }
    table .num { text-align:right; font-variant-numeric:tabular-nums; }
    .footer { text-align:center; color:var(--muted); font-size:12px; padding:20px 0; }
    @media print {
        body { background:#fff; }
        .bar { display:none; }
        .wrap { padding:0; }
        section, .meta, .cover { break-inside:avoid; box-shadow:none; }
    }
</style>
</head>
<body>
<div class="wrap">
    <div class="bar">
        <p>Use your browser's Print → Save as PDF to export this report.</p>
        <button type="button" onclick="window.print();">Print / Save as PDF</button>
    </div>

    <div class="cover">
        <p class="eyebrow">KiwiEvents · Promoter report</p>
        <h1><?php echo esc_html( $event->post_title ); ?></h1>
        <?php if ( $event_date_label ) : ?>
            <p class="sub">📅 <?php echo esc_html( $event_date_label ); ?></p>
        <?php endif; ?>
    </div>

    <div class="meta">
        <div><div class="k">Generated</div><div class="v"><?php echo esc_html( $generated_label ); ?></div></div>
        <div><div class="k">Prepared by</div><div class="v"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></div></div>
        <div><div class="k">Active promoters</div><div class="v"><?php echo (int) $totals['active_promoters']; ?></div></div>
    </div>

    <section>
        <h2>Summary</h2>
        <div class="grid">
            <div class="stat"><div class="k">Total raised</div><div class="v accent"><?php echo esc_html( $currency . number_format( $totals['total'], 2 ) ); ?></div></div>
            <div class="stat"><div class="k">Owed</div><div class="v"><?php echo esc_html( $currency . number_format( $totals['owed'], 2 ) ); ?></div></div>
            <div class="stat"><div class="k">Paid out</div><div class="v"><?php echo esc_html( $currency . number_format( $totals['paid'], 2 ) ); ?></div></div>
            <div class="stat"><div class="k">Tickets attributed</div><div class="v"><?php echo (int) $totals['tickets']; ?></div></div>
        </div>
    </section>

    <section>
        <h2>Promoters (<?php echo count( $perf ); ?>)</h2>
        <?php if ( empty( $perf ) ) : ?>
            <p style="color:#64748b;">No promoters assigned to this event yet.</p>
        <?php else : ?>
            <table>
                <thead><tr>
                    <th>Promoter</th>
                    <th>Rate</th>
                    <th class="num">Tickets</th>
                    <th class="num">Owed</th>
                    <th class="num">Paid</th>
                    <th class="num">Total</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $perf as $r ) :
                    $rate_label = $r->commission_type === 'fixed'
                        ? $currency . number_format( (float) $r->commission_value, 2 ) . ' / ticket'
                        : number_format( (float) $r->commission_value, 2 ) . '%';
                ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $r->name ); ?></strong>
                            <div style="color:#94a3b8; font-size:11px;"><?php echo esc_html( $r->email ); ?></div>
                        </td>
                        <td><?php echo esc_html( $rate_label ); ?></td>
                        <td class="num"><?php echo (int) $r->tickets_sold; ?></td>
                        <td class="num"><?php echo esc_html( $currency . number_format( (float) $r->owed, 2 ) ); ?></td>
                        <td class="num"><?php echo esc_html( $currency . number_format( (float) $r->paid, 2 ) ); ?></td>
                        <td class="num"><strong><?php echo esc_html( $currency . number_format( (float) $r->total, 2 ) ); ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <p class="footer">Powered by Kiwi Events · <?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
</div>
<script>
    // Auto-open the print dialog so "Export PDF" feels like a one-click action.
    window.addEventListener( 'load', function () { setTimeout( function () { window.print(); }, 300 ); } );
</script>
</body>
</html>
        <?php
        exit;
    }

    /* ─── Recent emails view ────────────────────────────────────────── */

    private function render_emails() {
        global $wpdb;
        $table = KE_Email_Queue::table();

        $page_num = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $status   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';

        $where = array( '1=1' );
        $params = array();
        if ( in_array( $status, array( 'queued', 'retrying', 'sent', 'failed' ), true ) ) {
            $where[]  = 'status = %s';
            $params[] = $status;
        }
        $where_sql = implode( ' AND ', $where );

        $per_page = 50;
        $offset   = ( $page_num - 1 ) * $per_page;

        $count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
        $total = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
                         : (int) $wpdb->get_var( $count_sql );

        $list_sql = "SELECT * FROM $table WHERE $where_sql
                     ORDER BY id DESC LIMIT %d OFFSET %d";
        $list_params = array_merge( $params, array( $per_page, $offset ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) );

        $total_pages = max( 1, (int) ceil( $total / $per_page ) );

        include KE_PLUGIN_DIR . 'admin/views/promoter-emails.php';
    }

    /* ─── Active-events drawer (per promoter) ───────────────────────── */

    private function render_active_events() {
        global $wpdb;
        $id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $row = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ke_promoters WHERE id = %d", $id ) ) : null;
        if ( ! $row ) {
            wp_die( 'Promoter not found.' );
        }

        $events = KE_Event_Promoters::list_active_events_for_promoter( $id );

        $links = array();
        foreach ( $events as $ev ) {
            $links[] = (object) array(
                'event_id'         => $ev->event_id,
                'title'            => $ev->title,
                'permalink'        => $ev->permalink,
                'tracking_url'     => KE_Promoter_Attribution::build_tracking_url( $row->slug, $ev->event_id ),
                'commission_type'  => $ev->commission_type,
                'commission_value' => $ev->commission_value,
                'date_start'       => $ev->date_start ?? '',
                'date_end'         => $ev->date_end ?? '',
            );
        }

        $promoter = $row;
        include KE_PLUGIN_DIR . 'admin/views/promoter-active-events.php';
    }

    /* ─── Analytics ─────────────────────────────────────────────────── */

    private function render_stats() {
        $period_key = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : 'this_month';
        if ( ! in_array( $period_key, array( 'this_month', 'last_month', 'last_90_days', 'all_time' ), true ) ) {
            $period_key = 'this_month';
        }

        list( $from, $to, $period_label ) = KE_Promoter_Commissions::resolve_period( $period_key );

        $totals = KE_Promoter_Commissions::totals_in_window( $from, $to );
        $top    = KE_Promoter_Commissions::top_promoters( $from, $to, 10 );

        global $wpdb;
        $promoter_counts = $wpdb->get_results(
            "SELECT status, COUNT(*) AS n FROM {$wpdb->prefix}ke_promoters GROUP BY status"
        );
        $counts_by_status = array( 'active' => 0, 'pending' => 0, 'inactive' => 0 );
        foreach ( $promoter_counts as $r ) {
            if ( isset( $counts_by_status[ $r->status ] ) ) {
                $counts_by_status[ $r->status ] = (int) $r->n;
            }
        }

        include KE_PLUGIN_DIR . 'admin/views/promoter-stats.php';
    }

    /* ─── CSV Import ────────────────────────────────────────────────── */

    private function render_import() {
        $report_key = 'ke_promoter_import_report_' . get_current_user_id();
        $report     = get_transient( $report_key );
        if ( $report ) delete_transient( $report_key );

        $flash = $this->consume_flash();
        include KE_PLUGIN_DIR . 'admin/views/promoter-import.php';
    }

    public function handle_import_csv() {
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ke_import_promoters_csv' ) ) wp_die( 'Security check failed.' );

        if ( empty( $_FILES['csv_file'] ) || ! is_uploaded_file( $_FILES['csv_file']['tmp_name'] ) ) {
            $this->set_flash( 'error', 'Please select a CSV file to upload.' );
            wp_safe_redirect( admin_url( 'admin.php?page=ke-promoters&action=import' ) );
            exit;
        }

        $tmp = $_FILES['csv_file']['tmp_name'];
        if ( filesize( $tmp ) > 5 * 1024 * 1024 ) {
            $this->set_flash( 'error', 'CSV file is too large (max 5 MB).' );
            wp_safe_redirect( admin_url( 'admin.php?page=ke-promoters&action=import' ) );
            exit;
        }

        $fh = fopen( $tmp, 'r' );
        if ( ! $fh ) {
            $this->set_flash( 'error', 'Could not open the uploaded file.' );
            wp_safe_redirect( admin_url( 'admin.php?page=ke-promoters&action=import' ) );
            exit;
        }

        $report = $this->import_csv_stream( $fh );
        fclose( $fh );

        set_transient(
            'ke_promoter_import_report_' . get_current_user_id(),
            $report,
            5 * MINUTE_IN_SECONDS
        );

        $this->set_flash( 'success', sprintf(
            'Imported %d promoter(s). %d skipped, %d error(s).',
            $report['created'], $report['skipped'], count( $report['errors'] )
        ) );
        wp_safe_redirect( admin_url( 'admin.php?page=ke-promoters&action=import' ) );
        exit;
    }

    /**
     * Run a CSV stream against the promoters table. Returns a result report.
     * Expected columns (case-insensitive header): name, email, slug, phone, status.
     * Only `name` + `email` are required.
     */
    private function import_csv_stream( $fh ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ke_promoters';

        $header = fgetcsv( $fh );
        if ( ! is_array( $header ) ) {
            return array( 'created' => 0, 'skipped' => 0, 'errors' => array( array( 0, 'CSV appears to be empty.' ) ) );
        }
        // Strip BOM from the very first header cell if still present.
        if ( isset( $header[0] ) ) {
            $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
        }
        $header = array_map( function ( $h ) {
            return strtolower( trim( (string) $h ) );
        }, $header );

        $col = array_flip( $header );
        if ( ! isset( $col['email'] ) ) {
            return array( 'created' => 0, 'skipped' => 0, 'errors' => array( array( 1, 'CSV must include an "email" column. Each row\'s email must match an existing WordPress user.' ) ) );
        }

        $created = 0;
        $skipped = 0;
        $errors  = array();
        $row_n   = 1; // header was row 1

        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            $row_n++;
            if ( count( array_filter( $row, function ( $v ) { return trim( (string) $v ) !== ''; } ) ) === 0 ) {
                continue; // blank line
            }

            $email  = isset( $row[ $col['email'] ] ) ? sanitize_email( $row[ $col['email'] ] ) : '';
            $slug   = isset( $col['slug'],   $row[ $col['slug'] ] )   ? sanitize_title( $row[ $col['slug'] ] )   : '';
            $phone  = isset( $col['phone'],  $row[ $col['phone'] ] )  ? sanitize_text_field( $row[ $col['phone'] ] ) : '';
            $status = isset( $col['status'], $row[ $col['status'] ] ) ? sanitize_text_field( $row[ $col['status'] ] ) : 'pending';

            if ( ! in_array( $status, array( 'active', 'pending', 'inactive' ), true ) ) {
                $status = 'pending';
            }

            if ( ! is_email( $email ) ) {
                $errors[] = array( $row_n, 'Missing or invalid email.' );
                continue;
            }

            $user = get_user_by( 'email', $email );
            if ( ! $user ) {
                $errors[] = array( $row_n, "No WordPress user with email {$email}. User must register first." );
                continue;
            }

            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table WHERE user_id = %d LIMIT 1", $user->ID
            ) );
            if ( $existing ) {
                $skipped++;
                continue;
            }

            if ( $slug === '' ) $slug = sanitize_title( $user->display_name ?: $user->user_login );
            $slug = $this->ensure_unique_slug( $slug, 0 );

            $now = current_time( 'mysql' );
            $ok  = $wpdb->insert(
                $table,
                array(
                    'user_id'    => (int) $user->ID,
                    'slug'       => $slug,
                    'phone'      => $phone,
                    'status'     => $status,
                    'created_at' => $now,
                    'updated_at' => $now,
                ),
                array( '%d', '%s', '%s', '%s', '%s', '%s' )
            );
            if ( $ok ) {
                $created++;
            } else {
                $errors[] = array( $row_n, 'Database insert failed.' );
            }
        }

        return array(
            'created' => $created,
            'skipped' => $skipped,
            'errors'  => $errors,
        );
    }

    /* ─── Lists ─────────────────────────────────────────────────────── */

    private function render_lists() {
        $sub_action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'lists';
        $list_id    = isset( $_GET['list_id'] ) ? absint( $_GET['list_id'] ) : 0;

        $editing   = ( $sub_action === 'list_edit' || $sub_action === 'list_new' );
        $list      = $list_id ? KE_Promoter_Lists::get( $list_id ) : null;
        $is_new    = ( $sub_action === 'list_new' || ! $list );

        $lists     = KE_Promoter_Lists::all_with_counts();
        $members   = $list ? KE_Promoter_Lists::members( $list->id ) : array();

        global $wpdb;
        $u = $wpdb->users;
        $all_promoters = $wpdb->get_results(
            "SELECT p.id,
                    COALESCE(NULLIF(u.display_name, ''), u.user_login, '(unlinked)') AS name,
                    COALESCE(u.user_email, '') AS email,
                    p.slug, p.status
               FROM {$wpdb->prefix}ke_promoters p
          LEFT JOIN $u u ON u.ID = p.user_id
              WHERE p.status IN ('active','pending')
           ORDER BY name ASC"
        );

        $flash = $this->consume_flash();
        include KE_PLUGIN_DIR . 'admin/views/promoter-lists.php';
    }

    public function handle_save_list() {
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ke_save_promoter_list' ) ) wp_die( 'Security check failed.' );

        $id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $name = sanitize_text_field( (string) ( $_POST['name'] ?? '' ) );
        $desc = sanitize_textarea_field( (string) ( $_POST['description'] ?? '' ) );

        if ( $name === '' ) {
            $this->set_flash( 'error', 'A list name is required.' );
            wp_safe_redirect( admin_url( 'admin.php?page=ke-promoters&action=lists' ) );
            exit;
        }

        if ( $id ) {
            KE_Promoter_Lists::update( $id, $name, $desc );
            $this->set_flash( 'success', 'List updated.' );
        } else {
            $id = KE_Promoter_Lists::create( $name, $desc );
            $this->set_flash( 'success', 'List created.' );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=ke-promoters&action=list_edit&list_id=' . (int) $id ) );
        exit;
    }

    public function handle_delete_list() {
        $id = isset( $_GET['list_id'] ) ? absint( $_GET['list_id'] ) : 0;
        if ( ! $id ) wp_die( 'Missing list id.' );

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ke_delete_promoter_list_' . $id ) ) wp_die( 'Security check failed.' );
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );

        KE_Promoter_Lists::delete( $id );
        $this->set_flash( 'success', 'List deleted.' );
        wp_safe_redirect( admin_url( 'admin.php?page=ke-promoters&action=lists' ) );
        exit;
    }

    public function handle_save_list_members() {
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );

        $id = isset( $_POST['list_id'] ) ? absint( $_POST['list_id'] ) : 0;
        if ( ! $id ) wp_die( 'Missing list id.' );

        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ke_save_promoter_list_members_' . $id ) ) wp_die( 'Security check failed.' );

        $ids = isset( $_POST['promoter_ids'] ) && is_array( $_POST['promoter_ids'] )
             ? array_map( 'absint', $_POST['promoter_ids'] )
             : array();

        KE_Promoter_Lists::set_members( $id, $ids );
        $this->set_flash( 'success', sprintf( 'List membership saved (%d).', count( $ids ) ) );
        wp_safe_redirect( admin_url( 'admin.php?page=ke-promoters&action=list_edit&list_id=' . $id ) );
        exit;
    }

    /* ─── List view ─────────────────────────────────────────────────── */

    private function render_list() {
        global $wpdb;
        $table = $wpdb->prefix . 'ke_promoters';
        $users = $wpdb->users;

        $page_num = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $status   = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';

        $where  = array( '1=1' );
        $params = array();

        if ( $search !== '' ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]  = '(u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s OR p.slug LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ( in_array( $status, array( 'active', 'inactive', 'pending', 'orphaned' ), true ) ) {
            $where[]  = 'p.status = %s';
            $params[] = $status;
        }

        $where_sql = implode( ' AND ', $where );

        $count_sql = "SELECT COUNT(*) FROM $table p LEFT JOIN $users u ON u.ID = p.user_id WHERE $where_sql";
        $total     = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
                             : (int) $wpdb->get_var( $count_sql );

        $offset = ( $page_num - 1 ) * self::PER_PAGE;

        $list_sql = "SELECT p.*,
                            COALESCE(NULLIF(u.display_name, ''), u.user_login, '(unlinked)') AS name,
                            COALESCE(u.user_email, '') AS email
                       FROM $table p
                       LEFT JOIN $users u ON u.ID = p.user_id
                      WHERE $where_sql
                      ORDER BY p.created_at DESC LIMIT %d OFFSET %d";
        $list_params = array_merge( $params, array( self::PER_PAGE, $offset ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) );

        $total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );

        // Pre-compute lifetime totals once per row for the list view.
        $totals_by_id = array();
        if ( $rows && class_exists( 'KE_Promoter_Commissions' ) ) {
            foreach ( $rows as $r ) {
                $totals_by_id[ (int) $r->id ] = KE_Promoter_Commissions::totals_for_promoter( $r->id );
            }
        }

        $flash = $this->consume_flash();

        include KE_PLUGIN_DIR . 'admin/views/promoters-list.php';
    }

    /* ─── Edit form ─────────────────────────────────────────────────── */

    private function render_form() {
        global $wpdb;
        $table = $wpdb->prefix . 'ke_promoters';

        $id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $row = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) ) : null;

        $is_new = ! $row;
        $flash  = $this->consume_flash();

        include KE_PLUGIN_DIR . 'admin/views/promoter-form.php';
    }

    /* ─── Commissions view (per promoter) ───────────────────────────── */

    private function render_commissions() {
        global $wpdb;
        $table = $wpdb->prefix . 'ke_promoters';

        $id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $row = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) ) : null;

        if ( ! $row ) {
            wp_die( 'Promoter not found.' );
        }

        $status_filter = isset( $_GET['cstatus'] ) ? sanitize_text_field( $_GET['cstatus'] ) : '';
        $page_num      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $per_page      = 50;

        $filters = array();
        if ( in_array( $status_filter, array( 'earned', 'paid', 'refunded_keep', 'voided' ), true ) ) {
            $filters['status'] = $status_filter;
        }

        $total_rows = KE_Promoter_Commissions::count_for_promoter( $id, $filters );
        $offset     = ( $page_num - 1 ) * $per_page;
        $rows       = KE_Promoter_Commissions::list_for_promoter( $id, $filters, $per_page, $offset );

        $total_pages = max( 1, (int) ceil( $total_rows / $per_page ) );
        $totals      = KE_Promoter_Commissions::totals_for_promoter( $id );
        $flash       = $this->consume_flash();

        include KE_PLUGIN_DIR . 'admin/views/promoter-commissions.php';
    }

    /* ─── Handlers ──────────────────────────────────────────────────── */

    public function handle_save() {
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ke_save_promoter_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ke_promoters';

        $id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        $slug    = sanitize_title( (string) ( $_POST['slug'] ?? '' ) );
        $phone   = sanitize_text_field( (string) ( $_POST['phone'] ?? '' ) );
        $status  = sanitize_text_field( (string) ( $_POST['status'] ?? 'pending' ) );

        if ( ! in_array( $status, array( 'active', 'inactive', 'pending', 'orphaned' ), true ) ) {
            $status = 'pending';
        }

        // For NEW promoters a WP user_id is mandatory. For edits, user_id of 0
        // is allowed only if the row was already orphaned (so admins can re-link
        // by setting one, or unlink intentionally via the dedicated action).
        if ( ! $id && $user_id <= 0 ) {
            $this->set_flash( 'error', 'Pick a WordPress user — promoters must be linked to an existing account.' );
            wp_safe_redirect( $this->form_url( $id ) );
            exit;
        }

        if ( $user_id > 0 ) {
            $u = get_userdata( $user_id );
            if ( ! $u ) {
                $this->set_flash( 'error', 'Selected user does not exist.' );
                wp_safe_redirect( $this->form_url( $id ) );
                exit;
            }
            // user_id must be unique across promoters.
            $taken = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table WHERE user_id = %d AND id <> %d LIMIT 1",
                $user_id, $id
            ) );
            if ( $taken ) {
                $this->set_flash( 'error', 'That user is already a promoter.' );
                wp_safe_redirect( $this->form_url( $id ) );
                exit;
            }
            // Auto-generate slug from display name when empty.
            if ( $slug === '' ) {
                $slug = sanitize_title( $u->display_name ?: $u->user_login );
            }
        } elseif ( $slug === '' ) {
            $slug = 'promoter-' . $id;
        }

        $slug = $this->ensure_unique_slug( $slug, $id );
        $now  = current_time( 'mysql' );

        if ( $id ) {
            // Snapshot the pre-update row so we can detect the
            // first-ever transition to 'active' and dispatch the welcome
            // email exactly once. welcome_email_sent gates retries; if it's
            // already set we never re-send, even on later inactive→active.
            $old = $wpdb->get_row( $wpdb->prepare( "SELECT status, welcome_email_sent FROM $table WHERE id = %d", $id ) );

            $wpdb->update(
                $table,
                array(
                    'user_id'    => $user_id ?: null,
                    'slug'       => $slug,
                    'phone'      => $phone,
                    'status'     => $status,
                    'updated_at' => $now,
                ),
                array( 'id' => $id ),
                array( '%d', '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );

            // First activation → welcome email. send_welcome_email() is a
            // no-op if welcome_email_sent is already populated, so a stray
            // double-save from a misclick won't re-send.
            if ( $old && $status === 'active' && empty( $old->welcome_email_sent )
                 && class_exists( 'KE_Promoter_Notifications' ) ) {
                $ok = KE_Promoter_Notifications::send_welcome_email( $id );
                if ( $ok ) {
                    $this->set_flash( 'success', 'Promoter updated and welcome email sent.' );
                    wp_safe_redirect( admin_url( 'admin.php?page=ke-promoters' ) );
                    exit;
                }
            }
            $this->set_flash( 'success', 'Promoter updated.' );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'user_id'    => $user_id,
                    'slug'       => $slug,
                    'phone'      => $phone,
                    'status'     => $status,
                    'created_at' => $now,
                    'updated_at' => $now,
                ),
                array( '%d', '%s', '%s', '%s', '%s', '%s' )
            );
            $this->set_flash( 'success', 'Promoter created.' );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=ke-promoters' ) );
        exit;
    }

    /**
     * Manually resend the welcome email to one promoter. Used when the
     * original send bounced or the promoter says they didn't receive it.
     * Forces send even if welcome_email_sent is already populated.
     */
    public function handle_resend_welcome() {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( ! $id ) wp_die( 'Missing promoter id.' );

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ke_resend_promoter_welcome_' . $id ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $ok = class_exists( 'KE_Promoter_Notifications' )
              && KE_Promoter_Notifications::send_welcome_email( $id, true );

        $this->set_flash( $ok ? 'success' : 'error',
            $ok ? 'Welcome email resent.' : 'Could not send — see error log. Promoter may be inactive or have no linked WP user.' );
        wp_safe_redirect( admin_url( 'admin.php?page=ke-promoters' ) );
        exit;
    }

    /**
     * Detach the linked WP user from a promoter row. The slug and historical
     * commission rows are preserved so the admin can later re-link by editing
     * the row and selecting a different user.
     */
    public function handle_unlink_user() {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( ! $id ) wp_die( 'Missing promoter id.' );

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ke_unlink_promoter_user_' . $id ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ke_promoters',
            array( 'user_id' => null, 'status' => 'orphaned', 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => $id ),
            array( '%d', '%s', '%s' ),
            array( '%d' )
        );

        $this->set_flash( 'success', 'WP user unlinked. The promoter row is now orphaned — re-link by editing and picking a user.' );
        wp_safe_redirect( $this->form_url( $id ) );
        exit;
    }

    public function handle_delete() {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( ! $id ) wp_die( 'Missing promoter id.' );

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ke_delete_promoter_' . $id ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        global $wpdb;
        // Hard-delete the promoter row. Phase 2 will add a soft-delete path
        // that preserves historical commission rows; for now commissions are
        // kept (the FK is not enforced — commissions.promoter_id will just
        // point at a missing row), which is the safer default for audit.
        $wpdb->delete( $wpdb->prefix . 'ke_promoters', array( 'id' => $id ), array( '%d' ) );
        $wpdb->delete( $wpdb->prefix . 'ke_promoter_list_members', array( 'promoter_id' => $id ), array( '%d' ) );

        $this->set_flash( 'success', 'Promoter deleted.' );
        wp_safe_redirect( admin_url( 'admin.php?page=ke-promoters' ) );
        exit;
    }

    /**
     * Bulk-mark selected commissions as paid for one promoter.
     * Form posts: action, promoter_id, commission_ids[], paid_note, _wpnonce.
     */
    public function handle_mark_paid() {
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $promoter_id = isset( $_POST['promoter_id'] ) ? absint( $_POST['promoter_id'] ) : 0;
        if ( ! $promoter_id ) wp_die( 'Missing promoter id.' );

        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ke_mark_commissions_paid_' . $promoter_id ) ) {
            wp_die( 'Security check failed.' );
        }

        $ids   = isset( $_POST['commission_ids'] ) && is_array( $_POST['commission_ids'] )
               ? array_map( 'absint', $_POST['commission_ids'] )
               : array();
        $note  = isset( $_POST['paid_note'] ) ? sanitize_text_field( (string) $_POST['paid_note'] ) : '';

        $updated = KE_Promoter_Commissions::mark_paid( $ids, $promoter_id, $note );

        if ( $updated > 0 ) {
            $this->set_flash( 'success', sprintf( '%d commission %s marked as paid.', $updated, $updated === 1 ? 'row' : 'rows' ) );
        } else {
            $this->set_flash( 'error', 'No commissions were updated. Already-paid or voided rows are skipped.' );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=ke-promoters&action=commissions&id=' . $promoter_id ) );
        exit;
    }

    /**
     * Stream a CSV of one promoter's commissions, honoring the current
     * status filter. Admin-only.
     */
    public function handle_export_csv() {
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( ! $id ) wp_die( 'Missing promoter id.' );

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ke_export_promoter_commissions_' . $id ) ) {
            wp_die( 'Security check failed.' );
        }

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ke_promoters WHERE id = %d", $id ) );
        if ( ! $row ) wp_die( 'Promoter not found.' );

        $status = isset( $_GET['cstatus'] ) ? sanitize_text_field( $_GET['cstatus'] ) : '';
        $filters = array();
        if ( in_array( $status, array( 'earned', 'paid', 'refunded_keep', 'voided' ), true ) ) {
            $filters['status'] = $status;
        }

        KE_Promoter_Commissions::stream_csv_for_promoter( $row, $filters );
        exit;
    }

    /* ─── Helpers ───────────────────────────────────────────────────── */

    private function form_url( $id ) {
        $url = admin_url( 'admin.php?page=ke-promoters&action=' . ( $id ? 'edit' : 'new' ) );
        if ( $id ) $url .= '&id=' . $id;
        return $url;
    }

    /**
     * Append -2, -3, ... until the slug is unique among other rows.
     */
    private function ensure_unique_slug( $slug, $ignore_id = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ke_promoters';
        $base  = $slug;
        $i     = 2;

        while ( true ) {
            $hit = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table WHERE slug = %s AND id <> %d LIMIT 1",
                $slug, (int) $ignore_id
            ) );
            if ( ! $hit ) return $slug;
            $slug = $base . '-' . $i++;
            if ( $i > 50 ) return $slug . '-' . wp_generate_password( 4, false, false );
        }
    }

    private function set_flash( $type, $message ) {
        set_transient( 'ke_promoter_flash_' . get_current_user_id(),
            array( 'type' => $type, 'message' => $message ),
            30
        );
    }

    private function consume_flash() {
        $key   = 'ke_promoter_flash_' . get_current_user_id();
        $flash = get_transient( $key );
        if ( $flash ) delete_transient( $key );
        return $flash ?: null;
    }
}
