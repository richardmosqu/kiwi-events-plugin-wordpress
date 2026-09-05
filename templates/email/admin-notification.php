<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$site_name     = get_bloginfo( 'name' );
$site_url      = home_url();
$accent        = esc_attr( $accent_color ?? '#6366f1' );
$accent_border = 'rgba(' . sscanf( $accent, '#%02x%02x%02x' )[0] . ',' . sscanf( $accent, '#%02x%02x%02x' )[1] . ',' . sscanf( $accent, '#%02x%02x%02x' )[2] . ',0.20)';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>New Ticket Sale — <?php echo esc_html( $event->post_title ); ?></title>
<style>
body{margin:0;padding:0;background:#f4f4f5;font-family:'Inter',Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;}
table{border-collapse:collapse;}
img{border:0;line-height:100%;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;}
.wrapper{width:100%;background:#f4f4f5;}
.inner{width:100%;max-width:560px;margin:0 auto;}
.card{background:#ffffff;border-radius:16px;overflow:hidden;margin:24px 16px;}
.header{background:linear-gradient(135deg,#6366f1 0%,#8b5cf6 100%);padding:24px 28px;text-align:center;}
.header-logo{color:#ffffff;font-size:20px;font-weight:800;margin:0;letter-spacing:-0.5px;font-family:'Inter',Arial,sans-serif;}
.body{padding:32px 28px 24px;}
.hero-summary{text-align:center;margin-bottom:32px;}
.hero-title{font-size:28px;font-weight:800;color:#1a1a2e;margin:0 0 8px;letter-spacing:-0.5px;}
.hero-event{font-size:18px;color:#3f3f46;margin:0 0 8px;font-weight:600;}
.hero-date{font-size:14px;color:#71717a;margin:0;}
/* Cards */
.info-card{background:#f8f7ff;border:1.5px solid #ede9fe;border-radius:12px;padding:20px;margin-bottom:20px;}
.info-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#9ca3af;margin-bottom:16px;}
.info-row{display:flex;justify-content:space-between;font-size:14px;color:#52525b;padding:6px 0;}
.info-row span:last-child{font-weight:600;color:#18181b;}
.sub-row{font-size:13px;color:#71717a;padding:4px 0 4px 12px;border-left:2px solid #e4e4e7;margin-top:4px;}
/* Buttons */
.btn-group{text-align:center;margin-top:32px;}
.btn{display:inline-block;padding:14px 32px;background:#6366f1;color:#ffffff;text-decoration:none;border-radius:100px;font-weight:600;font-size:15px;letter-spacing:-0.2px;margin-bottom:12px;}
.btn-secondary{background:#f4f4f5;color:#18181b;border:1px solid #e4e4e7;}
/* Footer */
.footer{padding:20px 28px;text-align:center;font-size:11px;color:#9ca3af;border-top:1px solid #f0f0f5;background:#fafafa;}
.footer a{color:#6366f1;text-decoration:none;}
/* Mobile */
@media(max-width:480px){
  .card{margin:16px 8px;border-radius:12px;}
  .body{padding:24px 20px 16px;}
  .btn{display:block;text-align:center;}
}
</style>
</head>
<body>
<table class="wrapper" width="100%" cellpadding="0" cellspacing="0">
<tr><td align="center">
<table class="inner" cellpadding="0" cellspacing="0">
<tr><td>
<div class="card">

  <!-- Header -->
  <div style="background:<?php echo $accent; ?>;" class="header">
    <h1 class="header-logo"><?php echo esc_html( $site_name ); ?></h1>
  </div>

  <!-- Body -->
  <div class="body">
    <div class="hero-summary">
      <h2 class="hero-title">Ticket Sold</h2>
      <p class="hero-event"><?php echo esc_html( $event->post_title ); ?></p>
      <p class="hero-date"><?php echo esc_html( $formatted_date ); ?></p>
    </div>

    <!-- Order Details -->
    <div class="info-card" style="border-color: <?php echo $accent_border; ?>;">
      <div class="info-title">Order Details</div>
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td style="padding:6px 0;font-size:14px;color:#52525b;">Order Number</td>
          <td style="padding:6px 0;font-size:14px;color:#18181b;font-weight:600;text-align:right;"><?php echo esc_html( $order->order_number ); ?></td>
        </tr>
        <tr>
          <td style="padding:6px 0;font-size:14px;color:#52525b;">Ticket Type</td>
          <td style="padding:6px 0;font-size:14px;color:#18181b;font-weight:600;text-align:right;"><?php echo esc_html( $tickets[0]->ticket_type_name ?? 'General' ); ?></td>
        </tr>
        <tr>
          <td style="padding:6px 0;font-size:14px;color:#52525b;">Quantity</td>
          <td style="padding:6px 0;font-size:14px;color:#18181b;font-weight:600;text-align:right;"><?php echo count( $tickets ); ?></td>
        </tr>
        <tr>
          <td style="padding:6px 0;font-size:14px;color:#52525b;">Amount</td>
          <td style="padding:6px 0;font-size:14px;color:#18181b;font-weight:600;text-align:right;"><?php echo floatval($order->total_amount) > 0 ? '$' . number_format( $order->total_amount, 2 ) : 'Free'; ?></td>
        </tr>
        <tr>
          <td style="padding:6px 0;font-size:14px;color:#52525b;">Payment</td>
          <td style="padding:6px 0;font-size:14px;color:#18181b;font-weight:600;text-align:right;text-transform:capitalize;"><?php echo esc_html( $order->payment_method ) . ' (' . esc_html( $order->payment_status ) . ')'; ?></td>
        </tr>
      </table>
    </div>

    <!-- Customer Details -->
    <div class="info-card" style="border-color: <?php echo $accent_border; ?>;">
      <div class="info-title">Customer</div>
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td style="padding:6px 0;font-size:14px;color:#52525b;">Name</td>
          <td style="padding:6px 0;font-size:14px;color:#18181b;font-weight:600;text-align:right;"><?php echo esc_html( $customer_name ); ?></td>
        </tr>
        <tr>
          <td style="padding:6px 0;font-size:14px;color:#52525b;">Email</td>
          <td style="padding:6px 0;font-size:14px;color:#18181b;font-weight:600;text-align:right;"><a href="mailto:<?php echo esc_attr( $customer_email ); ?>" style="color:<?php echo $accent; ?>;text-decoration:none;"><?php echo esc_html( $customer_email ); ?></a></td>
        </tr>
      </table>
      
      <!-- One QR link PER ticket (every attendee), not just the first. A
           multi-ticket order previously surfaced only tickets[0]'s QR here. -->
      <div style="margin-top:12px;padding-top:12px;border-top:1px solid #e4e4e7;">
        <div style="font-size:12px;font-weight:600;color:#71717a;margin-bottom:8px;"><?php echo count( $tickets ) > 1 ? 'ATTENDEES &amp; QR CODES' : 'QR CODE'; ?></div>
        <?php foreach ( $tickets as $ticket ) :
            $t_qr = esc_url( home_url( '/ticket/' . $ticket->ticket_code ) );
        ?>
          <div class="sub-row" style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
            <span><?php echo esc_html( $ticket->attendee_name ); ?><?php if ( ! empty( $ticket->attendee_email ) && $ticket->attendee_email !== $customer_email ) : ?> &middot; <?php echo esc_html( $ticket->attendee_email ); ?><?php endif; ?></span>
            <a href="<?php echo $t_qr; ?>" style="color:<?php echo $accent; ?>;text-decoration:none;font-weight:600;font-size:13px;white-space:nowrap;">View QR &rsaquo;</a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Actions. The single QR button only makes sense for a one-ticket order;
         multi-ticket orders get their per-ticket QR links in the list above. -->
    <div class="btn-group">
      <?php if ( count( $tickets ) === 1 ) : ?>
      <a href="<?php echo esc_url( $qr_link ); ?>" class="btn" style="background:<?php echo $accent; ?>;">View QR Code</a><br>
      <?php endif; ?>
      <a href="<?php echo esc_url( $dashboard_link ); ?>" class="btn btn-secondary">View in Dashboard</a>
    </div>

  </div>

  <!-- Footer -->
  <div class="footer">
    You received this because you are the organizer of this event.<br>
    Powered by <a href="<?php echo esc_url( $site_url ); ?>"><?php echo esc_html( $site_name ); ?></a>
  </div>

</div><!-- .card -->
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
