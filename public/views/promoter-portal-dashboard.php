<?php
/**
 * Promoter portal — authenticated dashboard (simplified per CHANGE 5).
 *
 * Locals provided by KE_Promoter_Portal::handle_request():
 *   $promoter      : object   (ke_promoters row)
 *   $slug          : string
 *   $flash         : array|null
 *   $admin_preview : bool     (true when an admin is impersonating)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$admin_preview = isset( $admin_preview ) ? (bool) $admin_preview : false;

$who         = KE_Promoter_Attribution::display_for( $promoter );
$promoter_name  = $who['name']  !== '' ? $who['name']  : (string) $promoter->slug;
$promoter_email = $who['email'];

$page_title = sprintf( '%s — %s', esc_html( $promoter_name ), esc_html( get_bloginfo( 'name' ) ) );

$totals      = KE_Promoter_Commissions::totals_for_promoter( (int) $promoter->id );
$rows        = KE_Promoter_Commissions::list_for_promoter( (int) $promoter->id, array(), 30, 0 );
$assignments = KE_Event_Promoters::list_events_for_promoter( (int) $promoter->id );

// Aggregate per-event sold/earned for this promoter (commission rows).
global $wpdb;
$per_event = array();
$ct = $wpdb->prefix . 'ke_promoter_commissions';
$agg = $wpdb->get_results( $wpdb->prepare(
    "SELECT event_id,
            COUNT(*) AS tickets,
            COALESCE(SUM(commission_amount), 0) AS total,
            COALESCE(SUM(CASE WHEN status IN ('earned','refunded_keep') THEN commission_amount ELSE 0 END), 0) AS owed,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN commission_amount ELSE 0 END), 0) AS paid
       FROM $ct WHERE promoter_id = %d GROUP BY event_id",
    (int) $promoter->id
) );
foreach ( $agg as $r ) {
    $per_event[ (int) $r->event_id ] = array(
        'tickets' => (int) $r->tickets,
        'total'   => (float) $r->total,
        'owed'    => (float) $r->owed,
        'paid'    => (float) $r->paid,
    );
}

// Overall event capacity/sold (same query the admin events list uses) so the
// progress bar reflects the event's full ticket sales, not just this promoter's.
$types_table = $wpdb->prefix . 'ke_ticket_types';
$event_caps  = array();
foreach ( $assignments as $a ) {
    $stats = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN capacity_type = 'limited' THEN quantity_total ELSE 0 END), 0) AS total_capacity,
            COALESCE(SUM(quantity_sold), 0) AS total_sold,
            COUNT(*) AS type_count,
            COALESCE(SUM(CASE WHEN capacity_type = 'unlimited' THEN 1 ELSE 0 END), 0) AS unlimited_count
         FROM $types_table
         WHERE event_id = %d AND (is_archived IS NULL OR is_archived = 0)",
        (int) $a->event_id
    ) );
    $cap   = (int) ( $stats->total_capacity ?? 0 );
    $sold  = (int) ( $stats->total_sold ?? 0 );
    $tc    = (int) ( $stats->type_count ?? 0 );
    $uc    = (int) ( $stats->unlimited_count ?? 0 );
    $all_u = ( $tc > 0 && $uc === $tc );
    $event_caps[ (int) $a->event_id ] = array(
        'capacity'      => $cap,
        'sold'          => $sold,
        'all_unlimited' => $all_u,
        'pct'           => $cap > 0 ? min( 100, round( ( $sold / $cap ) * 100 ) ) : 0,
    );
}

// Sort: upcoming first (by start date asc), past events last (by start date desc).
$today = current_time( 'Y-m-d' );
$upcoming = array();
$past     = array();
foreach ( $assignments as $a ) {
    $start = get_post_meta( $a->event_id, '_ke_event_date_start', true );
    $a->date_start = $start;
    $when = $start ? substr( (string) $start, 0, 10 ) : '';
    if ( $when && $when < $today ) {
        $past[] = $a;
    } else {
        $upcoming[] = $a;
    }
}
usort( $upcoming, function ( $x, $y ) {
    return strcmp( (string) $x->date_start, (string) $y->date_start );
} );
usort( $past, function ( $x, $y ) {
    return strcmp( (string) $y->date_start, (string) $x->date_start );
} );

$currency       = (string) get_option( 'ke_promoter_currency_label', '$' );
$prefs          = (array) get_option( 'ke_promoter_prefs_' . (int) $promoter->id, array() );
$notify_on_earn = isset( $prefs['notify_on_earn'] ) ? (bool) $prefs['notify_on_earn'] : true;

$signout_url = wp_logout_url( home_url( '/' ) );

$prefs_action  = admin_url( 'admin-post.php' );
$prefs_nonce   = wp_create_nonce( 'ke_promoter_save_prefs_' . (int) $promoter->id );
$leave_action  = admin_url( 'admin-post.php' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />
    <title><?php echo $page_title; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" />
    <style>
        /* ──────────────────────────────────────────────────────────────
         * Mobile-first: base styles target phones (≈375–767px).
         *   @media (min-width: 768px)  → tablet adjustments
         *   @media (min-width: 1024px) → desktop adjustments
         * Promoters are 95% mobile (university students), so the phone
         * layout is the canonical experience, not a fallback.
         * ────────────────────────────────────────────────────────────── */
        :root {
            --kep-accent-1:#6366f1;
            --kep-kiwi:#7cb342;
            --kep-kiwi-dark:#558b2f;
            --kep-cream:#fff8e7;
            --kep-cream-deep:#fef3c7;
            --kep-bg:#fffaf0;
            --kep-text:#0f172a;
            --kep-text-muted:#64748b;
            --kep-border:rgba(0,0,0,0.06);
        }
        *, *::before, *::after { box-sizing:border-box; }
        body { background:var(--kep-bg); color:var(--kep-text); font-family:'Inter',system-ui,-apple-system,sans-serif; margin:0; -webkit-text-size-adjust:100%; }
        .kep-wrap { max-width:1200px; margin:0 auto; padding:16px 14px calc(40px + env(safe-area-inset-bottom)); }

        /* Admin preview banner — stacks vertically on mobile; the link
           sits below the text with a top-border divider so it reads as a
           distinct row rather than a button floating on the right. */
        .kep-banner-admin {
            background:linear-gradient(135deg,#fff8db,#fffce5);
            border:1px solid rgba(255,191,0,0.3);
            color:#1f2937;
            border-radius:12px;
            padding:14px 16px;
            margin-bottom:16px;
            font-size:14px;
            display:flex;
            flex-direction:column;
            align-items:center;
            gap:10px;
            text-align:center;
        }
        .kep-banner-admin .kep-banner-icon { font-size:18px; }
        .kep-banner-admin strong { font-weight:600; color:#0f172a; }
        .kep-banner-admin .kep-banner-mode { color:#64748b; font-weight:400; margin-left:6px; }
        .kep-banner-admin a {
            color:#0f172a;
            text-decoration:none;
            font-weight:500;
            padding:14px 8px 6px;
            min-height:44px;
            width:100%;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            border-top:1px solid rgba(0,0,0,0.08);
            margin-top:4px;
        }

        .kep-flash { border-radius:12px; padding:12px 16px; margin-bottom:16px; font-size:14px; }
        .kep-flash.success { background:#dcfce7; color:#166534; }
        .kep-flash.error { background:#fee2e2; color:#991b1b; }

        /* Header — stacks on mobile; signout collapses to a text-link. */
        .kep-header {
            display:flex;
            flex-direction:column;
            align-items:flex-start;
            gap:6px;
            margin-bottom:18px;
        }
        .kep-greet { width:100%; }
        .kep-greet h1 { margin:0; font-size:22px; font-weight:800; }
        .kep-greet p { margin:2px 0 0; color:var(--kep-text-muted); font-size:13px; word-break:break-word; }
        .kep-header .kep-btn-ghost {
            background:transparent;
            border:none;
            padding:4px 0;
            min-height:auto;
            color:var(--kep-accent-1);
            font-size:14px;
            font-weight:500;
            border-radius:0;
        }
        .kep-header .kep-btn-ghost.is-disabled { color:var(--kep-text-muted); }

        .kep-btn {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:10px 16px;
            border-radius:999px;
            font-size:14px;
            font-weight:600;
            text-decoration:none;
            cursor:pointer;
            border:none;
            background:transparent;
            min-height:44px;
            -webkit-tap-highlight-color:rgba(0,0,0,0.05);
        }
        .kep-btn-primary { background:var(--kep-accent-1); color:#fff; }
        .kep-btn-ghost { background:#fff; color:#0f172a; border:1px solid #e2e8f0; }
        .kep-btn[disabled], .kep-btn.is-disabled { opacity:0.5; pointer-events:none; cursor:not-allowed; }

        /* Lifetime earnings hero — 32px value on mobile, 48px at desktop. */
        .kep-stat-big {
            background:linear-gradient(135deg, var(--kep-accent-1), #4f46e5);
            color:#fff;
            border-radius:16px;
            padding:20px 22px;
            margin-bottom:22px;
            box-shadow:0 8px 24px rgba(99,102,241,0.18);
        }
        .kep-stat-big .label { font-size:11px; text-transform:uppercase; letter-spacing:0.08em; opacity:0.85; }
        .kep-stat-big .value { font-size:32px; font-weight:800; margin-top:4px; font-variant-numeric:tabular-nums; line-height:1.05; letter-spacing:-0.5px; }
        .kep-stat-big .sub { font-size:12.5px; margin-top:6px; opacity:0.9; line-height:1.45; }

        .kep-section-title { font-size:13px; font-weight:700; color:var(--kep-text); margin:22px 0 12px; text-transform:uppercase; letter-spacing:0.06em; }

        /* Event grid — single column on mobile. */
        .kep-events-grid { display:grid; grid-template-columns:1fr; gap:16px; }

        .kep-card {
            background:#fff;
            border:1px solid var(--kep-border);
            border-radius:18px;
            overflow:hidden;
            box-shadow:0 1px 0 rgba(255,255,255,0.9) inset, 0 4px 14px rgba(0,0,0,0.05), 0 1px 3px rgba(0,0,0,0.04);
            display:flex;
            flex-direction:column;
            position:relative;
        }
        .kep-card-thumb {
            position:relative;
            aspect-ratio:16/10;
            background:linear-gradient(135deg, var(--kep-cream-deep), #e8f5e9);
            background-size:cover;
            background-position:center;
        }
        .kep-card-thumb::after { content:''; position:absolute; inset:0; background:linear-gradient(to bottom, transparent 55%, rgba(0,0,0,0.22) 100%); }
        .kep-badge { position:absolute; top:12px; left:12px; z-index:2; background:#22c55e; color:#fff; padding:4px 10px; border-radius:999px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; box-shadow:0 2px 6px rgba(0,0,0,0.15); }
        .kep-badge-past { background:#94a3b8; }
        .kep-rate { position:absolute; top:12px; right:12px; z-index:2; background:rgba(0,0,0,0.62); color:#fff; padding:4px 10px; border-radius:999px; font-size:11px; font-weight:600; }
        .kep-card-body { padding:18px 18px 16px; flex:1; display:flex; flex-direction:column; }
        .kep-card-title { font-size:18px; font-weight:700; margin:0 0 6px; color:var(--kep-text); line-height:1.25; }
        .kep-card-date { font-size:13px; color:var(--kep-text-muted); margin:0 0 14px; display:flex; align-items:center; gap:6px; }
        .kep-card-divider { height:1px; background:var(--kep-border); margin:0 0 14px; }
        .kep-metric-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px; }
        .kep-metric .k { font-size:10.5px; color:var(--kep-text-muted); font-weight:700; text-transform:uppercase; letter-spacing:0.6px; margin-bottom:3px; }
        .kep-metric .v { font-size:20px; font-weight:800; color:var(--kep-text); line-height:1; letter-spacing:-0.3px; font-variant-numeric:tabular-nums; }
        .kep-tickets-block { margin-bottom:14px; }
        .kep-tickets-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
        .kep-tickets-head .k { font-size:10.5px; color:var(--kep-text-muted); font-weight:700; text-transform:uppercase; letter-spacing:0.6px; }
        .kep-tickets-head .v { font-size:13px; font-weight:700; color:var(--kep-text); font-variant-numeric:tabular-nums; }
        .kep-progress { height:6px; background:#f1f5f9; border-radius:999px; overflow:hidden; }
        .kep-progress > span { display:block; height:100%; background:linear-gradient(90deg, var(--kep-kiwi), var(--kep-kiwi-dark)); border-radius:999px; }

        /* Action buttons — 2x2 on mobile: [WhatsApp][Copy] / [   QR   ].
           Salir sits below the grid as a less-prominent outlined row. */
        .kep-actions {
            display:grid;
            grid-template-columns:1fr 1fr;
            grid-template-areas:
                "wa copy"
                "qr qr";
            gap:8px;
            margin-bottom:10px;
        }
        .kep-wa      { grid-area:wa; }
        .kep-copy    { grid-area:copy; }
        .kep-show-qr { grid-area:qr; }
        .kep-action-btn {
            display:flex;
            flex-direction:row;
            align-items:center;
            justify-content:center;
            gap:8px;
            padding:12px 8px;
            min-height:44px;
            background:#f8fafc;
            border:1px solid #e2e8f0;
            border-radius:10px;
            font-size:13px;
            font-weight:600;
            color:var(--kep-text);
            cursor:pointer;
            -webkit-tap-highlight-color:rgba(0,0,0,0.05);
            -webkit-touch-callout:none;
            user-select:none;
        }
        .kep-action-btn .icon { font-size:18px; line-height:1; }
        .kep-action-btn:active:not(:disabled) { background:#eef2f7; }
        .kep-action-btn:disabled, .kep-action-btn.is-disabled { opacity:0.5; cursor:not-allowed; pointer-events:auto; }

        .kep-leave-btn {
            width:100%;
            margin-top:6px;
            padding:10px 14px;
            min-height:44px;
            background:transparent;
            border:1px solid #fecaca;
            color:#b91c1c;
            border-radius:10px;
            font-size:13px;
            font-weight:500;
            cursor:pointer;
            -webkit-tap-highlight-color:rgba(220,38,38,0.08);
        }
        .kep-leave-btn:active:not(:disabled) { background:#fef2f2; }
        .kep-leave-btn:disabled, .kep-leave-btn.is-disabled { opacity:0.5; cursor:not-allowed; }
        .kep-leave-form { margin:0; }

        /* Empty state — vertically centered via min-height (no vh units,
           which jump on iOS when the URL bar shows/hides). */
        .kep-empty {
            background:#fff;
            border:1px dashed #e2e8f0;
            border-radius:16px;
            padding:40px 24px;
            min-height:320px;
            display:flex;
            flex-direction:column;
            justify-content:center;
            align-items:center;
            text-align:center;
            color:var(--kep-text-muted);
        }
        .kep-empty .icon { font-size:48px; display:block; margin-bottom:12px; }
        .kep-empty h3 { margin:0 0 8px; color:var(--kep-text); font-weight:700; font-size:18px; }
        .kep-empty p { margin:0; font-size:14px; max-width:280px; }

        .kep-past-toggle {
            display:inline-flex;
            align-items:center;
            gap:6px;
            background:transparent;
            border:none;
            color:var(--kep-text-muted);
            font-size:14px;
            font-weight:600;
            cursor:pointer;
            padding:10px 0;
            min-height:44px;
            margin-top:6px;
            -webkit-tap-highlight-color:rgba(0,0,0,0.05);
        }

        .kep-recent details { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:0; overflow:hidden; }
        .kep-recent summary { padding:14px 16px; cursor:pointer; font-weight:600; color:#0f172a; font-size:14px; min-height:44px; display:flex; align-items:center; }
        .kep-recent table { width:100%; border-collapse:collapse; font-size:13px; }
        .kep-recent th { background:#f8fafc; text-align:left; padding:10px 12px; color:#475569; font-size:11px; text-transform:uppercase; letter-spacing:0.06em; }
        .kep-recent td { padding:10px 12px; border-top:1px solid #f1f5f9; color:#1f2937; }
        .kep-recent td.num { text-align:right; font-variant-numeric:tabular-nums; }

        .kep-prefs { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px; }
        .kep-prefs label { display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px; color:#0f172a; min-height:44px; }
        .kep-prefs label input { width:20px; height:20px; }
        .kep-prefs .kep-prefs-row { display:flex; flex-direction:column; align-items:stretch; gap:12px; }
        .kep-prefs .kep-btn { width:100%; }

        /* Generic modal — full-screen sheet on mobile so the QR is big
           and thumbs reach the close button. position:fixed is fine here;
           it's the modal backdrop, not page chrome. */
        .kep-modal {
            display:none;
            position:fixed;
            inset:0;
            background:rgba(15,23,42,0.72);
            z-index:9999;
            padding:0;
            align-items:stretch;
            justify-content:stretch;
        }
        .kep-modal.is-open { display:flex; }
        .kep-modal .panel {
            background:#fff;
            border-radius:0;
            padding:22px 22px 0;
            padding-bottom:max(24px, env(safe-area-inset-bottom));
            width:100%;
            max-width:none;
            min-height:100%;
            box-shadow:none;
            display:flex;
            flex-direction:column;
            overflow-y:auto;
        }
        .kep-modal .panel.center { text-align:center; align-items:center; }
        .kep-modal h3 { margin:0 0 14px; font-size:18px; }
        .kep-modal img { width:100%; max-width:300px; height:auto; }
        .kep-modal .modal-event { font-size:15px; font-weight:600; color:var(--kep-text); margin:14px 0 4px; }
        .kep-modal .url { font-size:12px; color:var(--kep-text-muted); word-break:break-all; margin:6px 0 14px; font-family:ui-monospace, SFMono-Regular, Menlo, monospace; }
        .kep-modal .modal-body { color:var(--kep-text-muted); font-size:14.5px; line-height:1.5; margin-bottom:auto; padding-bottom:24px; }
        .kep-modal .modal-actions { display:flex; gap:10px; margin-top:auto; }
        .kep-modal .modal-actions .kep-btn { flex:1; min-height:48px; padding:12px 14px; font-size:15px; }
        .kep-modal .kep-btn-danger { background:#dc2626; color:#fff; }
        #kep-qr-close { align-self:stretch; margin-top:auto; min-height:48px; }

        /* Toast — lifted above the iOS home indicator. */
        .kep-toast {
            position:fixed;
            bottom:max(24px, env(safe-area-inset-bottom));
            left:50%;
            transform:translateX(-50%) translateY(100px);
            background:#0f172a;
            color:#fff;
            padding:12px 20px;
            border-radius:999px;
            font-size:14px;
            font-weight:500;
            opacity:0;
            transition:transform 0.25s ease, opacity 0.25s ease;
            z-index:10000;
            pointer-events:none;
        }
        .kep-toast.is-visible { transform:translateX(-50%) translateY(0); opacity:1; }

        .kep-footer { text-align:center; color:#94a3b8; font-size:12px; padding:24px 0 0; }

        /* ─── Tablet (≥ 768px) ────────────────────────────────────── */
        @media (min-width: 768px) {
            .kep-wrap { padding:20px 18px 60px; }

            .kep-banner-admin {
                flex-direction:row;
                align-items:center;
                gap:16px;
                padding:12px 16px;
                text-align:left;
            }
            .kep-banner-admin a {
                margin-left:auto;
                width:auto;
                border-top:none;
                margin-top:0;
                padding:6px 12px;
                border-radius:8px;
                transition:background 0.15s;
            }
            .kep-banner-admin a:hover { background:rgba(0,0,0,0.05); }

            .kep-header { flex-direction:row; justify-content:space-between; align-items:center; gap:12px; }
            .kep-greet { width:auto; }
            .kep-header .kep-btn-ghost {
                background:#fff;
                border:1px solid #e2e8f0;
                color:#0f172a;
                padding:10px 16px;
                border-radius:999px;
                font-weight:600;
                min-height:44px;
            }
            .kep-header .kep-btn-ghost.is-disabled { color:#0f172a; }

            .kep-stat-big { padding:24px 26px; border-radius:18px; margin-bottom:24px; }
            .kep-stat-big .label { font-size:12px; }
            .kep-stat-big .value { font-size:40px; margin-top:6px; }
            .kep-stat-big .sub { font-size:13px; }

            .kep-section-title { font-size:14px; margin:24px 0 12px; }

            .kep-events-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); gap:20px; }

            .kep-card-thumb { aspect-ratio:16/9; }
            .kep-card { transition:transform 0.2s ease, box-shadow 0.2s ease; }
            .kep-card:hover {
                transform:translateY(-2px);
                box-shadow:0 1px 0 rgba(255,255,255,0.9) inset, 0 16px 32px rgba(0,0,0,0.08), 0 4px 8px rgba(0,0,0,0.05);
            }
            .kep-card-body { padding:20px 20px 16px; }
            .kep-card-title { font-size:20px; }

            .kep-actions { grid-template-columns:repeat(3, 1fr); grid-template-areas:"wa copy qr"; }
            .kep-action-btn {
                flex-direction:column;
                gap:4px;
                padding:10px 6px;
                font-size:11.5px;
                transition:background 0.15s, transform 0.15s;
            }
            .kep-action-btn:hover:not(:disabled) { background:#f1f5f9; transform:translateY(-1px); }

            .kep-empty { padding:48px 24px; min-height:360px; }

            .kep-prefs .kep-prefs-row { flex-direction:row; justify-content:space-between; align-items:center; }
            .kep-prefs .kep-btn { width:auto; }

            .kep-modal {
                padding:18px;
                align-items:center;
                justify-content:center;
            }
            .kep-modal .panel {
                max-width:420px;
                min-height:0;
                padding:24px;
                padding-bottom:24px;
                border-radius:16px;
                box-shadow:0 24px 48px rgba(0,0,0,0.25);
                overflow-y:visible;
            }
            .kep-modal .modal-body { padding-bottom:0; margin-bottom:18px; }
            .kep-modal .modal-actions { margin-top:0; }
            .kep-modal .modal-actions .kep-btn { min-height:44px; padding:10px 14px; font-size:14px; }
            .kep-modal .kep-btn-danger:hover { background:#b91c1c; }
            #kep-qr-close { align-self:center; min-width:140px; min-height:44px; }
        }

        /* ─── Desktop (≥ 1024px) ──────────────────────────────────── */
        @media (min-width: 1024px) {
            .kep-wrap { padding:24px 18px 60px; }
            .kep-events-grid { grid-template-columns:repeat(3, minmax(0, 1fr)); gap:24px; }
            .kep-stat-big .label { font-size:13px; }
            .kep-stat-big .value { font-size:48px; }
        }
    </style>
    <?php wp_head(); ?>
</head>
<body class="ke-promo-body ke-promo-body--dash">
    <div class="kep-wrap">

        <?php if ( $admin_preview ) : ?>
            <div class="kep-banner-admin">
                <span class="kep-banner-icon" aria-hidden="true">👀</span>
                <div>
                    <strong>Viendo como <?php echo esc_html( $promoter_name ); ?></strong>
                    <span class="kep-banner-mode">· Modo solo lectura</span>
                </div>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ke-promoters' ) ); ?>">← Volver al admin</a>
            </div>
        <?php endif; ?>

        <?php if ( $flash ) : ?>
            <div class="kep-flash <?php echo esc_attr( $flash['type'] === 'success' ? 'success' : 'error' ); ?>">
                <?php echo esc_html( $flash['message'] ); ?>
            </div>
        <?php endif; ?>

        <!-- Header -->
        <header class="kep-header">
            <div class="kep-greet">
                <h1>Hi, <?php echo esc_html( $promoter_name ); ?> 👋</h1>
                <?php if ( $promoter_email !== '' ) : ?>
                    <p><?php echo esc_html( $promoter_email ); ?></p>
                <?php endif; ?>
            </div>
            <?php if ( ! $admin_preview ) : ?>
                <a href="<?php echo esc_url( $signout_url ); ?>" class="kep-btn kep-btn-ghost">Sign out</a>
            <?php else : ?>
                <span class="kep-btn kep-btn-ghost is-disabled" aria-disabled="true">Sign out</span>
            <?php endif; ?>
        </header>

        <!-- Single hero stat: lifetime earnings -->
        <div class="kep-stat-big">
            <div class="label">Lifetime earnings</div>
            <div class="value"><?php echo esc_html( $currency . number_format( (float) $totals['total'], 2 ) ); ?></div>
            <div class="sub">
                <?php echo (int) $totals['tickets']; ?> ticket<?php echo $totals['tickets'] === 1 ? '' : 's'; ?> attributed ·
                <?php echo esc_html( $currency . number_format( (float) $totals['owed'], 2 ) ); ?> owed ·
                <?php echo esc_html( $currency . number_format( (float) $totals['paid'], 2 ) ); ?> paid
            </div>
        </div>

        <!-- My events -->
        <h2 class="kep-section-title">Mis eventos</h2>
        <?php
        $render_card = function ( $a, $is_past ) use ( $promoter, $currency, $per_event, $event_caps, $admin_preview, $leave_action ) {
            $event_url   = KE_Promoter_Attribution::build_tracking_url( $promoter->slug, $a->event_id );
            $banner_url  = get_the_post_thumbnail_url( $a->event_id, 'large' );
            $date_meta   = $a->date_start;
            $date_label  = $date_meta ? date_i18n( 'M j, Y · g:i A', strtotime( $date_meta ) ) : '';
            $rate_label  = $a->commission_type === 'fixed'
                ? $currency . number_format( $a->commission_value, 2 ) . ' / ticket'
                : number_format( $a->commission_value, 2 ) . '%';
            $stats       = $per_event[ $a->event_id ] ?? array( 'tickets' => 0, 'total' => 0.0 );
            $cap         = $event_caps[ $a->event_id ] ?? array( 'capacity' => 0, 'sold' => 0, 'all_unlimited' => false, 'pct' => 0 );
            $card_id     = 'kep-event-' . (int) $a->event_id;
            $cap_label   = $cap['all_unlimited']
                ? ( (int) $cap['sold'] . ' / ∞' )
                : ( $cap['capacity'] > 0
                    ? ( (int) $cap['sold'] . ' / ' . (int) $cap['capacity'] )
                    : '—' );
            $leave_nonce = wp_create_nonce( 'ke_promoter_leave_event_' . $promoter->id . '_' . $a->event_id );
            $share_msg   = sprintf(
                /* translators: 1: event title, 2: event date, 3: tracking URL */
                __( "Hey! Estoy promoviendo este evento, mira: %1\$s %2\$s %3\$s", 'kiwi-events' ),
                $a->title,
                $date_label,
                $event_url
            );
            $wa_url      = 'https://wa.me/?text=' . rawurlencode( $share_msg );
            $btn_dis     = $admin_preview ? ' disabled' : '';
            $btn_cls     = $admin_preview ? ' is-disabled' : '';
            $btn_tip     = $admin_preview ? ' title="Disponible solo para el promotor"' : '';
            ?>
            <article class="kep-card" id="<?php echo esc_attr( $card_id ); ?>"
                     data-track-url="<?php echo esc_attr( $event_url ); ?>"
                     data-event-title="<?php echo esc_attr( $a->title ); ?>"
                     data-wa-url="<?php echo esc_attr( $wa_url ); ?>">
                <div class="kep-card-thumb" style="<?php echo $banner_url ? 'background-image:url(' . esc_url( $banner_url ) . ');' : ''; ?>">
                    <span class="kep-badge<?php echo $is_past ? ' kep-badge-past' : ''; ?>"><?php echo $is_past ? esc_html__( 'Past', 'kiwi-events' ) : esc_html__( 'Active', 'kiwi-events' ); ?></span>
                    <span class="kep-rate"><?php echo esc_html( $rate_label ); ?></span>
                </div>
                <div class="kep-card-body">
                    <h3 class="kep-card-title"><?php echo esc_html( $a->title ); ?></h3>
                    <?php if ( $date_label ) : ?>
                        <p class="kep-card-date">📅 <span><?php echo esc_html( $date_label ); ?></span></p>
                    <?php endif; ?>
                    <div class="kep-card-divider"></div>

                    <div class="kep-metric-row">
                        <div class="kep-metric">
                            <div class="k">Comisiones</div>
                            <div class="v"><?php echo esc_html( $currency . number_format( (float) $stats['total'], 2 ) ); ?></div>
                        </div>
                        <div class="kep-metric">
                            <div class="k">Vendidos</div>
                            <div class="v"><?php echo (int) $stats['tickets']; ?></div>
                        </div>
                    </div>

                    <div class="kep-tickets-block">
                        <div class="kep-tickets-head">
                            <span class="k">Tickets vendidos (evento)</span>
                            <span class="v"><?php echo esc_html( $cap_label ); ?></span>
                        </div>
                        <div class="kep-progress">
                            <span style="width:<?php echo (int) $cap['pct']; ?>%;"></span>
                        </div>
                    </div>

                    <div class="kep-actions">
                        <button type="button" class="kep-action-btn kep-wa"<?php echo $btn_dis . $btn_cls . $btn_tip; ?> data-target="#<?php echo esc_attr( $card_id ); ?>">
                            <span class="icon" aria-hidden="true">📱</span>
                            <span>WhatsApp</span>
                        </button>
                        <button type="button" class="kep-action-btn kep-copy"<?php echo $btn_dis . $btn_cls . $btn_tip; ?> data-target="#<?php echo esc_attr( $card_id ); ?>">
                            <span class="icon" aria-hidden="true">🔗</span>
                            <span>Copiar link</span>
                        </button>
                        <button type="button" class="kep-action-btn kep-show-qr"<?php echo $btn_dis . $btn_cls . $btn_tip; ?> data-target="#<?php echo esc_attr( $card_id ); ?>">
                            <span class="icon" aria-hidden="true">📷</span>
                            <span>Mostrar QR</span>
                        </button>
                    </div>

                    <form method="POST" action="<?php echo esc_url( $leave_action ); ?>" class="kep-leave-form" data-event-title="<?php echo esc_attr( $a->title ); ?>">
                        <input type="hidden" name="action" value="ke_promoter_leave_event">
                        <input type="hidden" name="event_id" value="<?php echo (int) $a->event_id; ?>">
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $leave_nonce ); ?>">
                        <button type="submit" class="kep-leave-btn kep-leave-trigger"<?php echo $btn_dis . $btn_cls; ?> <?php echo $admin_preview ? 'title="Solo el promotor puede desasignarse de un evento."' : ''; ?>>
                            Salir de este evento
                        </button>
                    </form>
                </div>
            </article>
            <?php
        };
        ?>
        <?php if ( empty( $upcoming ) && empty( $past ) ) : ?>
            <div class="kep-empty">
                <span class="icon">🎟</span>
                <h3>Aún no tienes eventos asignados</h3>
                <p>Contacta a un organizador para que te agregue a sus eventos.</p>
            </div>
        <?php else : ?>
            <?php if ( ! empty( $upcoming ) ) : ?>
                <div class="kep-events-grid">
                    <?php foreach ( $upcoming as $a ) { $render_card( $a, false ); } ?>
                </div>
            <?php else : ?>
                <div class="kep-empty">
                    <span class="icon">📅</span>
                    <h3>Sin eventos próximos</h3>
                    <p>No tienes eventos próximos asignados.</p>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $past ) ) : ?>
                <button type="button" class="kep-past-toggle" id="kep-toggle-past" aria-expanded="false" aria-controls="kep-past-grid">
                    <span>▸</span> <span>Mostrar eventos pasados (<?php echo count( $past ); ?>)</span>
                </button>
                <div class="kep-events-grid" id="kep-past-grid" hidden style="margin-top:12px;">
                    <?php foreach ( $past as $a ) { $render_card( $a, true ); } ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Recent sales (collapsed) -->
        <h2 class="kep-section-title">Recent sales</h2>
        <div class="kep-recent">
            <details>
                <summary>
                    <?php echo count( $rows ); ?> recent commission<?php echo count( $rows ) === 1 ? '' : 's'; ?>
                </summary>
                <?php if ( empty( $rows ) ) : ?>
                    <p style="padding:14px 16px; color:#64748b; margin:0; font-size:13px;">
                        No commissions yet. They'll appear here as buyers purchase through your links.
                    </p>
                <?php else : ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Event</th>
                                <th>Buyer</th>
                                <th class="num">Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $rows as $r ) :
                            $event_post  = get_post( (int) $r->event_id );
                            $event_title = $event_post ? $event_post->post_title : '#' . (int) $r->event_id;
                        ?>
                            <tr>
                                <td><?php echo esc_html( date_i18n( 'M j', strtotime( $r->created_at ) ) ); ?></td>
                                <td><?php echo esc_html( $event_title ); ?></td>
                                <td><?php echo $r->buyer_name ? esc_html( $r->buyer_name ) : '—'; ?></td>
                                <td class="num"><strong><?php echo esc_html( $currency . number_format( (float) $r->commission_amount, 2 ) ); ?></strong></td>
                                <td><?php echo esc_html( $r->status ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </details>
        </div>

        <!-- Notification preferences -->
        <h2 class="kep-section-title">Notifications</h2>
        <form method="POST" action="<?php echo esc_url( $prefs_action ); ?>" class="kep-prefs"<?php echo $admin_preview ? ' onsubmit="return false;"' : ''; ?>>
            <input type="hidden" name="action" value="ke_promoter_save_prefs">
            <input type="hidden" name="slug" value="<?php echo esc_attr( $slug ); ?>">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $prefs_nonce ); ?>">
            <div class="kep-prefs-row">
                <label>
                    <input type="checkbox" name="notify_on_earn" value="1" <?php checked( $notify_on_earn ); ?> <?php disabled( $admin_preview ); ?>>
                    Email me when I earn a commission
                </label>
                <button type="submit" class="kep-btn kep-btn-primary" <?php disabled( $admin_preview ); ?>>Save</button>
            </div>
        </form>

        <p class="kep-footer">
            <?php
            printf(
                /* translators: %s: site name */
                esc_html__( '%s · Promoter Portal', 'kiwi-events' ),
                esc_html( get_bloginfo( 'name' ) )
            );
            ?>
        </p>
    </div>

    <!-- QR modal -->
    <div class="kep-modal" id="kep-qr-modal" role="dialog" aria-modal="true" aria-labelledby="kep-qr-title">
        <div class="panel center">
            <h3 id="kep-qr-title">Código QR</h3>
            <div id="kep-qr-image-wrap"></div>
            <div class="modal-event" id="kep-qr-event"></div>
            <div class="url" id="kep-qr-url"></div>
            <button type="button" class="kep-btn kep-btn-ghost" id="kep-qr-close">Cerrar</button>
        </div>
    </div>

    <!-- Leave-event confirmation modal -->
    <div class="kep-modal" id="kep-leave-modal" role="dialog" aria-modal="true" aria-labelledby="kep-leave-title">
        <div class="panel">
            <h3 id="kep-leave-title">¿Salir de este evento?</h3>
            <div class="modal-body">
                ¿Seguro que quieres dejar de promover <strong id="kep-leave-event-name"></strong>?
                Tu link de promotor para este evento dejará de funcionar y no recibirás más comisiones.
            </div>
            <div class="modal-actions">
                <button type="button" class="kep-btn kep-btn-ghost" id="kep-leave-cancel">Cancelar</button>
                <button type="button" class="kep-btn kep-btn-danger" id="kep-leave-confirm">Sí, salir</button>
            </div>
        </div>
    </div>

    <!-- Toast for copy feedback -->
    <div class="kep-toast" id="kep-toast" role="status" aria-live="polite"></div>

    <script>
    (function () {
        var isAdminView = <?php echo $admin_preview ? 'true' : 'false'; ?>;

        function copyText( str, onDone ) {
            if ( navigator.clipboard && navigator.clipboard.writeText ) {
                navigator.clipboard.writeText( str ).then( onDone, function () {
                    fallbackCopy( str, onDone );
                });
            } else {
                fallbackCopy( str, onDone );
            }
        }
        function fallbackCopy( str, onDone ) {
            var ta = document.createElement('textarea');
            ta.value = str; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild( ta );
            ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild( ta );
            onDone();
        }

        var toast = document.getElementById('kep-toast');
        var toastTimer = null;
        function showToast( msg ) {
            toast.textContent = msg;
            toast.classList.add('is-visible');
            clearTimeout( toastTimer );
            toastTimer = setTimeout(function () { toast.classList.remove('is-visible'); }, 1600);
        }

        // Helper: ignore clicks on disabled action buttons in admin-view mode.
        function isDisabled( btn ) {
            return btn.disabled || btn.classList.contains('is-disabled');
        }

        // Copy link
        document.querySelectorAll('.kep-copy').forEach(function ( btn ) {
            btn.addEventListener('click', function () {
                if ( isDisabled( btn ) ) return;
                var card = document.querySelector( btn.dataset.target );
                if ( ! card ) return;
                var url = card.getAttribute('data-track-url') || '';
                copyText( url, function () { showToast('Link copiado ✓'); });
            });
        });

        // WhatsApp share
        document.querySelectorAll('.kep-wa').forEach(function ( btn ) {
            btn.addEventListener('click', function () {
                if ( isDisabled( btn ) ) return;
                var card = document.querySelector( btn.dataset.target );
                if ( ! card ) return;
                var waUrl = card.getAttribute('data-wa-url') || '';
                if ( waUrl ) window.open( waUrl, '_blank', 'noopener' );
            });
        });

        // QR modal
        var qrModal   = document.getElementById('kep-qr-modal');
        var qrWrap    = document.getElementById('kep-qr-image-wrap');
        var qrUrlEl   = document.getElementById('kep-qr-url');
        var qrEventEl = document.getElementById('kep-qr-event');
        var qrClose   = document.getElementById('kep-qr-close');

        function openQr( url, eventTitle ) {
            var size = 320;
            var src  = 'https://api.qrserver.com/v1/create-qr-code/?size=' + size + 'x' + size + '&data=' + encodeURIComponent( url );
            qrWrap.innerHTML = '<img src="' + src + '" alt="QR code">';
            qrEventEl.textContent = eventTitle || '';
            qrUrlEl.textContent = url;
            qrModal.classList.add('is-open');
        }
        function closeQr() {
            qrModal.classList.remove('is-open');
            qrWrap.innerHTML = '';
        }
        document.querySelectorAll('.kep-show-qr').forEach(function ( btn ) {
            btn.addEventListener('click', function () {
                if ( isDisabled( btn ) ) return;
                var card = document.querySelector( btn.dataset.target );
                if ( ! card ) return;
                openQr(
                    card.getAttribute('data-track-url') || '',
                    card.getAttribute('data-event-title') || ''
                );
            });
        });
        qrClose.addEventListener('click', closeQr );
        qrModal.addEventListener('click', function ( e ) { if ( e.target === qrModal ) closeQr(); });

        // Leave-event confirmation
        var leaveModal  = document.getElementById('kep-leave-modal');
        var leaveName   = document.getElementById('kep-leave-event-name');
        var leaveCancel = document.getElementById('kep-leave-cancel');
        var leaveConfirm= document.getElementById('kep-leave-confirm');
        var pendingForm = null;

        document.querySelectorAll('.kep-leave-form').forEach(function ( form ) {
            form.addEventListener('submit', function ( e ) {
                var btn = form.querySelector('.kep-leave-trigger');
                if ( btn && isDisabled( btn ) ) {
                    e.preventDefault();
                    return;
                }
                e.preventDefault();
                pendingForm = form;
                leaveName.textContent = form.getAttribute('data-event-title') || '';
                leaveModal.classList.add('is-open');
            });
        });
        function closeLeave() { leaveModal.classList.remove('is-open'); pendingForm = null; }
        leaveCancel.addEventListener('click', closeLeave );
        leaveModal.addEventListener('click', function ( e ) { if ( e.target === leaveModal ) closeLeave(); });
        leaveConfirm.addEventListener('click', function () {
            if ( pendingForm ) pendingForm.submit();
        });

        // Past-events toggle
        var pastToggle = document.getElementById('kep-toggle-past');
        var pastGrid   = document.getElementById('kep-past-grid');
        if ( pastToggle && pastGrid ) {
            pastToggle.addEventListener('click', function () {
                var open = ! pastGrid.hasAttribute('hidden');
                if ( open ) {
                    pastGrid.setAttribute('hidden', '');
                    pastToggle.setAttribute('aria-expanded', 'false');
                    pastToggle.firstElementChild.textContent = '▸';
                } else {
                    pastGrid.removeAttribute('hidden');
                    pastToggle.setAttribute('aria-expanded', 'true');
                    pastToggle.firstElementChild.textContent = '▾';
                }
            });
        }

        // Esc closes any open modal
        document.addEventListener('keydown', function ( e ) {
            if ( e.key !== 'Escape' ) return;
            if ( qrModal.classList.contains('is-open') ) closeQr();
            if ( leaveModal.classList.contains('is-open') ) closeLeave();
        });
    })();
    </script>
    <?php wp_footer(); ?>
</body>
</html>
