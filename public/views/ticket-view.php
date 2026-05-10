<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── Resolve ticket ───────────────────────────────────────────
$ticket_code     = sanitize_text_field( get_query_var( 'ke_ticket_code' ) );
$tickets_handler = new KE_Tickets();
$ticket          = $tickets_handler->get_by_code( $ticket_code );

if ( ! $ticket || $ticket->status === 'cancelled' ) {
    status_header( 404 );
    nocache_headers();
    ?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ticket Not Found</title>
<style>body{margin:0;background:#f4f4f5;color:#09090b;font-family:system-ui,Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center;}h1{font-size:24px;margin:0 0 10px;}p{color:#71717a;}</style>
</head><body><div><h1>🎟️ Ticket Not Found</h1><p>This ticket code is invalid or has been cancelled.</p></div></body></html>
    <?php
    exit;
}

// ─── Event data ───────────────────────────────────────────────
$event_id    = $ticket->event_id;
$event_name  = get_the_title( $event_id );
$event_date  = get_post_meta( $event_id, '_ke_event_date_start', true );
$event_end   = get_post_meta( $event_id, '_ke_event_date_end',   true );
$event_venue = get_post_meta( $event_id, '_ke_event_venue',      true );
$event_thumb = get_the_post_thumbnail_url( $event_id, 'large' );
$short_code  = strtoupper( substr( $ticket->ticket_code, 0, 8 ) );
$is_used     = $ticket->status === 'used';

$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&format=png&ecc=H&data='
        . urlencode( $ticket->ticket_code );

// Format date
$formatted_date = '';
if ( $event_date ) {
    try {
        $tz_str = get_post_meta( $event_id, '_ke_event_timezone', true ) ?: wp_timezone_string();
        $dt     = new DateTime( $event_date, new DateTimeZone( $tz_str ) );
        $formatted_date = $dt->format( 'l, F j · g:i A' );
        if ( $event_end ) {
            $dt_end = new DateTime( $event_end, new DateTimeZone( $tz_str ) );
            $formatted_date .= ' – ' . $dt_end->format( 'g:i A' );
        }
    } catch ( Exception $e ) {
        $formatted_date = date( 'l, F j · g:i A', strtotime( $event_date ) );
    }
}
$site_name = get_bloginfo( 'name' );

// Accent color from plugin settings
$ui_settings   = get_option( 'ke_ui_settings', array() );
$accent_color  = ! empty( $ui_settings['accent_color'] )
               ? sanitize_hex_color( $ui_settings['accent_color'] )
               : '#6366f1';
$rgb           = sscanf( $accent_color, '#%02x%02x%02x' );
$accent_border = 'rgba(' . $rgb[0] . ',' . $rgb[1] . ',' . $rgb[2] . ',0.20)';
$accent_esc    = esc_attr( $accent_color );
$border_esc    = esc_attr( $accent_border );
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Ticket #<?php echo esc_html( $short_code ); ?> — <?php echo esc_html( $event_name ); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{
    font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;
    background:#f4f4f5;
    min-height:100vh;
    display:flex;
    flex-direction:column;
    align-items:center;
    padding:24px 16px 48px;
    -webkit-font-smoothing:antialiased;
}

/* ── Actions bar ── */
.ktv-actions{
    width:100%;max-width:400px;
    margin-bottom:20px;
    display:flex;gap:10px;
}
.ktv-btn{
    flex:1;padding:13px 16px;
    border:none;border-radius:100px;
    font-family:inherit;font-size:15px;font-weight:600;cursor:pointer;
    text-align:center;text-decoration:none;display:block;
}
.ktv-btn-primary{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;}
.ktv-btn-primary:hover{opacity:.92;color:#fff;}

/* ── Ticket card ── */
.ktv-card{
    width:100%;max-width:400px;
    background:#fff;
    border-radius:24px;
    overflow:hidden;
    box-shadow:0 20px 60px rgba(0,0,0,.12);
}

/* ── Banner ── */
.ktv-banner{width:100%;height:180px;object-fit:cover;display:block;}
.ktv-banner-placeholder{
    width:100%;height:180px;
    background:linear-gradient(135deg,#6366f1,#8b5cf6);
    display:flex;align-items:center;justify-content:center;
    font-size:52px;
}

/* ── Status badge (only shown if used) ── */
.ktv-status{
    display:flex;align-items:center;justify-content:center;gap:8px;
    padding:9px 16px;font-size:12px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;
    background:rgba(16,185,129,.1);color:#059669;border-bottom:1px solid rgba(16,185,129,.15);
}

/* ── Body ── */
.ktv-body{padding:24px 22px 0;}
.ktv-event-name{font-size:21px;font-weight:800;color:#09090b;letter-spacing:-.4px;margin-bottom:10px;line-height:1.25;}
.ktv-meta{font-size:13px;color:#71717a;margin-bottom:6px;display:flex;align-items:flex-start;gap:7px;line-height:1.4;}
.ktv-meta-icon{flex-shrink:0;margin-top:1px;}

/* ── Punched-card divider ── */
.ktv-divider{
    display:flex;align-items:center;
    margin:22px -22px;position:relative;
}
.ktv-divider::before,
.ktv-divider::after{
    content:'';width:22px;height:22px;
    background:#f4f4f5;
    border-radius:50%;flex-shrink:0;
}
.ktv-divider-line{flex:1;border-top:2px dashed #e4e4e7;}

/* ── QR section ── */
.ktv-qr-section{display:flex;flex-direction:column;align-items:center;padding:4px 0 8px;}
.ktv-qr-wrap{background:#fff;border:1.5px solid #e4e4e7;border-radius:16px;padding:12px;margin-bottom:14px;}
.ktv-qr{width:180px;height:180px;border-radius:8px;display:block;}
.ktv-attendee{font-size:17px;font-weight:700;color:#09090b;margin-bottom:5px;}
.ktv-code{
    font-family:'SF Mono','Fira Code','Courier New',monospace;
    font-size:13px;font-weight:700;color:#a1a1aa;letter-spacing:1.5px;
}
.ktv-type{font-size:12px;color:#a1a1aa;margin-top:3px;}

/* ── Footer ── */
.ktv-card-footer{
    background:#fafafa;padding:14px 22px;margin-top:20px;
    text-align:center;font-size:11px;color:#a1a1aa;
    border-top:1px solid #f0f0f0;
}

/* ── Dynamic accent color (from plugin settings) ── */
.ktv-btn-primary{background:<?php echo $accent_esc; ?> !important;}
.ktv-banner-placeholder{background:<?php echo $accent_esc; ?> !important;}
.ktv-qr-wrap{border-color:<?php echo $border_esc; ?> !important;}
.ktv-code-accent{color:<?php echo $accent_esc; ?> !important;}

/* ── Print / Save as PDF ── */
@media print{
    @page{size:400px 700px;margin:0;}
    html,body{width:400px;height:auto;background:#fff !important;padding:0 !important;display:block !important;}
    *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;}
    .ktv-actions{display:none !important;}
    .ktv-card{box-shadow:none !important;border-radius:0 !important;max-width:100% !important;width:100% !important;}
    .ktv-divider::before,.ktv-divider::after{background:#fff !important;}
}

/* ── Mobile ── */
@media(max-width:400px){
    .ktv-event-name{font-size:18px;}
    .ktv-qr{width:160px;height:160px;}
    .ktv-body{padding:20px 18px 0;}
    .ktv-divider{margin:18px -18px;}
    .ktv-divider::before,.ktv-divider::after{width:18px;height:18px;}
}
</style>
</head>
<body>

<div class="ktv-actions">
    <button class="ktv-btn ktv-btn-primary" onclick="downloadTicket()">⬇️ Download Ticket PDF</button>
</div>
<?php
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if ( strpos( $ua, 'iPhone' ) !== false || strpos( $ua, 'iPad' ) !== false ) : ?>
<div style="width:100%;max-width:400px;margin-bottom:12px;">
    <button onclick="downloadTicket()"
            style="display:block;width:100%;padding:13px 20px;background:#000;color:#fff;
                   border:none;border-radius:100px;font-family:inherit;font-size:15px;
                   font-weight:600;cursor:pointer;text-align:center;">
        🍎 Save to Apple Wallet (PDF)
    </button>
</div>
<?php endif; ?>
<script>
function downloadTicket() {
    var orig = document.title;
    document.title = 'Ticket-<?php echo esc_js( $short_code ); ?>';
    window.print();
    document.title = orig;
}
</script>

<div class="ktv-card">

    <?php if ( $event_thumb ) : ?>
        <img class="ktv-banner" src="<?php echo esc_url( $event_thumb ); ?>" alt="<?php echo esc_attr( $event_name ); ?>">
    <?php else : ?>
        <div class="ktv-banner-placeholder">🎉</div>
    <?php endif; ?>

    <?php if ( $is_used ) : ?>
    <div class="ktv-status">
        ✅ Checked in<?php if ( $ticket->checked_in_at ) echo ' · ' . esc_html( date( 'M j, g:i A', strtotime( $ticket->checked_in_at ) ) ); ?>
    </div>
    <?php endif; ?>

    <div class="ktv-body">
        <div class="ktv-event-name"><?php echo esc_html( $event_name ); ?></div>

        <?php if ( $formatted_date ) : ?>
        <div class="ktv-meta">
            <span class="ktv-meta-icon">📅</span>
            <span><?php echo esc_html( $formatted_date ); ?></span>
        </div>
        <?php endif; ?>

        <?php if ( $event_venue ) : ?>
        <div class="ktv-meta">
            <span class="ktv-meta-icon">📍</span>
            <span><?php echo esc_html( $event_venue ); ?></span>
        </div>
        <?php endif; ?>

        <div class="ktv-divider"><div class="ktv-divider-line"></div></div>

        <div class="ktv-qr-section">
            <div class="ktv-qr-wrap">
                <img class="ktv-qr"
                     src="<?php echo esc_url( $qr_url ); ?>"
                     alt="QR Code for ticket #<?php echo esc_attr( $short_code ); ?>"
                     width="180" height="180">
            </div>
            <div class="ktv-attendee"><?php echo esc_html( $ticket->attendee_name ); ?></div>
            <div class="ktv-code">#<?php echo esc_html( $short_code ); ?></div>
            <?php if ( ! empty( $ticket->ticket_type_name ) ) : ?>
                <div class="ktv-type"><?php echo esc_html( $ticket->ticket_type_name ); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="ktv-card-footer">
        <?php echo esc_html( $site_name ); ?> &middot; Show this QR code at the entrance &middot; Single use only
    </div>

</div>

</body>
</html>
