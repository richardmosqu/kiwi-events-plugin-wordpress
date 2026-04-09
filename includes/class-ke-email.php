<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Email service — sends ticket emails with PDF attachments
 */
class KE_Email {

    /**
     * Send ticket email after order completion
     *
     * @param int $order_id The order ID
     * @return bool|WP_Error
     */
    public function send_ticket_email( $order_id ) {
        $orders_handler  = new KE_Orders();
        $tickets_handler = new KE_Tickets();
        $pdf_generator   = new KE_PDF_Generator();

        $order = $orders_handler->get( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'order_not_found', 'Order not found.' );
        }

        // Get tickets for this order
        $tickets = $tickets_handler->get_by_order( $order_id );
        if ( empty( $tickets ) ) {
            return new WP_Error( 'no_tickets', 'No tickets found for this order.' );
        }

        // Get event info
        $event_title = get_the_title( $order->event_id );
        $event_date  = get_post_meta( $order->event_id, '_ke_event_date_start', true );
        $event_venue = get_post_meta( $order->event_id, '_ke_event_venue', true );

        // Accent color from plugin settings
        $ui_settings  = get_option( 'ke_ui_settings', array() );
        $accent_color = ! empty( $ui_settings['accent_color'] )
                      ? sanitize_hex_color( $ui_settings['accent_color'] )
                      : '#6366f1';
        $rgb           = sscanf( $accent_color, '#%02x%02x%02x' );
        $accent_border = 'rgba(' . $rgb[0] . ',' . $rgb[1] . ',' . $rgb[2] . ',0.20)';

        // Generate PDFs and collect paths — PDF is optional; email sends without it if generation fails
        $attachments = array();
        foreach ( $tickets as $ticket ) {
            try {
                if ( ! empty( $ticket->pdf_path ) && file_exists( $ticket->pdf_path ) ) {
                    $attachments[] = $ticket->pdf_path;
                } else {
                    $pdf_path = $pdf_generator->generate( $ticket );
                    if ( ! is_wp_error( $pdf_path ) && file_exists( $pdf_path ) ) {
                        $attachments[] = $pdf_path;
                    }
                }
            } catch ( \Throwable $e ) {
                // PDF generation failed — skip attachment, email still sends
                error_log( 'KiwiEvents PDF error for ticket ' . $ticket->ticket_code . ': ' . $e->getMessage() );
            }
        }

        // Build email
        $to      = $order->buyer_email;
        $subject = '🎟️ Your tickets for ' . $event_title . ' — Order #' . $order->order_number;

        // Format date with timezone awareness
        $formatted_date = 'TBA';
        if ( $event_date ) {
            try {
                $tz_str         = get_post_meta( $order->event_id, '_ke_event_timezone', true ) ?: wp_timezone_string();
                $dt             = new DateTime( $event_date, new DateTimeZone( $tz_str ) );
                $formatted_date = $dt->format( 'l, F j, Y \a\t g:i A' );
            } catch ( Exception $e ) {
                $formatted_date = date( 'l, F j, Y \a\t g:i A', strtotime( $event_date ) );
            }
        }

        // Build HTML email body
        $body = $this->get_email_html( array(
            'buyer_name'    => $order->buyer_name,
            'event_title'   => $event_title,
            'event_date'    => $formatted_date,
            'event_venue'   => $event_venue,
            'order_number'  => $order->order_number,
            'total_amount'  => $order->total_amount,
            'ticket_count'  => count( $tickets ),
            'tickets'       => $tickets,
            'has_pdf'       => ! empty( $attachments ),
            'accent_color'  => $accent_color,
            'accent_border' => $accent_border,
        ) );

        // Email headers
        $from_name  = get_option( 'ke_email_from_name', get_bloginfo( 'name' ) );
        $from_email = get_option( 'ke_email_from_address', get_bloginfo( 'admin_email' ) );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from_name} <{$from_email}>",
        );

        // Send
        $sent = wp_mail( $to, $subject, $body, $headers, $attachments );

        if ( ! $sent ) {
            return new WP_Error( 'email_failed', 'Failed to send ticket email.' );
        }

        return true;
    }

    /**
     * Build the HTML email template — mobile-first, table-based layout.
     */
    private function get_email_html( $data ) {
        ob_start();
        $site_name     = get_bloginfo( 'name' );
        $site_url      = home_url();
        $accent        = esc_attr( $data['accent_color']  ?? '#6366f1' );
        $accent_border = esc_attr( $data['accent_border'] ?? 'rgba(99,102,241,0.20)' );
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Your tickets — <?php echo esc_html( $data['event_title'] ); ?></title>
<style>
body{margin:0;padding:0;background:#f4f4f5;font-family:'Inter',Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;}
table{border-collapse:collapse;}
img{border:0;line-height:100%;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;}
.wrapper{width:100%;background:#f4f4f5;}
.inner{width:100%;max-width:560px;margin:0 auto;}
.card{background:#ffffff;border-radius:16px;overflow:hidden;margin:24px 16px;}
.header{background:linear-gradient(135deg,#6366f1 0%,#8b5cf6 100%);padding:36px 28px;text-align:center;}
.header h1{color:#ffffff;font-size:26px;font-weight:800;margin:0 0 6px;letter-spacing:-0.5px;}
.header p{color:rgba(255,255,255,0.85);font-size:15px;margin:0;}
.body{padding:28px 28px 8px;}
.greeting{font-size:16px;color:#1a1a2e;margin:0 0 6px;}
.sub{font-size:14px;color:#71717a;margin:0 0 24px;line-height:1.6;}
/* Event info card */
.event-card{background:#f8f7ff;border:1.5px solid #ede9fe;border-radius:12px;padding:20px;margin-bottom:20px;}
.event-title{font-size:18px;font-weight:700;color:#1a1a2e;margin:0 0 12px;letter-spacing:-0.3px;}
.event-meta{font-size:13px;color:#6b7280;line-height:1.7;margin:0;}
/* Ticket block */
.ticket-block{background:#f8f7ff;border:1.5px solid #ede9fe;border-radius:12px;padding:20px;margin-bottom:12px;text-align:center;}
.attendee-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:#9ca3af;margin-bottom:4px;}
.attendee-name{font-size:18px;font-weight:700;color:#1a1a2e;margin-bottom:4px;}
.ticket-type{font-size:12px;color:#9ca3af;margin-bottom:16px;}
.ticket-code{font-family:'SF Mono','Fira Code','Courier New',monospace;font-size:20px;font-weight:700;color:#6366f1;letter-spacing:2px;margin-bottom:16px;}
/* CTA button */
.btn{display:inline-block;padding:14px 32px;background:#6366f1;color:#ffffff;text-decoration:none;border-radius:100px;font-weight:600;font-size:15px;letter-spacing:-0.2px;}
/* Divider */
.divider{height:1px;background:#f0f0f5;margin:20px 0;}
/* Order summary */
.summary-row{display:flex;justify-content:space-between;font-size:13px;color:#6b7280;padding:6px 0;}
.summary-row span:last-child{font-weight:600;color:#1a1a2e;}
.summary-total span:first-child{color:#1a1a2e;font-weight:600;}
.summary-total span:last-child{font-size:17px;color:#6366f1;font-weight:700;}
/* Footer */
.footer{padding:20px 28px;text-align:center;font-size:11px;color:#9ca3af;border-top:1px solid #f0f0f5;}
.footer a{color:#6366f1;text-decoration:none;}
/* Mobile */
@media(max-width:480px){
  .card{margin:16px 8px;border-radius:12px;}
  .header{padding:28px 20px;}
  .header h1{font-size:22px;}
  .body{padding:20px 20px 8px;}
  .btn{display:block;text-align:center;}
  .ticket-code{font-size:17px;}
}
</style>
</head>
<body>
<table class="wrapper" width="100%" cellpadding="0" cellspacing="0">
<tr><td align="center">
<table class="inner" cellpadding="0" cellspacing="0">
<tr><td>
<div class="card">

  <!-- Header — gradient inlined so it renders in all email clients -->
  <div style="background:<?php echo $accent; ?>;padding:36px 28px;text-align:center;">
    <h1 style="color:#ffffff;font-size:26px;font-weight:800;margin:0 0 6px;letter-spacing:-0.5px;font-family:'Inter',Arial,sans-serif;">🎉 You&rsquo;re in!</h1>
    <p style="color:rgba(255,255,255,0.85);font-size:15px;margin:0;font-family:'Inter',Arial,sans-serif;">Your ticket<?php echo intval( $data['ticket_count'] ) > 1 ? 's are' : ' is'; ?> confirmed for <strong><?php echo esc_html( $data['event_title'] ); ?></strong></p>
  </div>

  <!-- Body -->
  <div class="body">
    <p class="greeting">Hi <strong><?php echo esc_html( $data['buyer_name'] ); ?></strong>,</p>
    <p class="sub">Great news &mdash; your order is complete. Show the QR code below at the entrance.</p>

    <!-- Event details -->
    <div style="background:#fafafa;border-top:1px solid #f0f0f0;border-bottom:1px solid #f0f0f0;padding:16px 0;margin-bottom:20px;">
      <div style="font-size:17px;font-weight:700;color:#09090b;margin-bottom:8px;letter-spacing:-0.3px;font-family:'Inter',Arial,sans-serif;"><?php echo esc_html( $data['event_title'] ); ?></div>
      <p style="font-size:13px;color:#71717a;line-height:1.7;margin:0;font-family:'Inter',Arial,sans-serif;">
        📅 &nbsp;<?php echo esc_html( $data['event_date'] ); ?><?php if ( $data['event_venue'] ) : ?><br>📍 &nbsp;<?php echo esc_html( $data['event_venue'] ); ?><?php endif; ?>
      </p>
    </div>

    <!-- Per-ticket blocks -->
    <?php if ( ! empty( $data['tickets'] ) ) : ?>
      <?php foreach ( $data['tickets'] as $ticket ) :
          $short_code  = '#' . strtoupper( substr( $ticket->ticket_code, 0, 8 ) );
          $ticket_url  = esc_url( home_url( '/ticket/' . $ticket->ticket_code ) );
          $qr_src      = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&format=png&ecc=H&data='
                       . urlencode( $ticket->ticket_code );
          $type_name   = ! empty( $ticket->ticket_type_name ) ? $ticket->ticket_type_name : '';
      ?>
      <!-- Ticket block — all styles inlined for email client compatibility -->
      <div style="background:#ffffff;border:1.5px solid <?php echo $accent_border; ?>;border-radius:16px;padding:20px;margin-bottom:12px;text-align:center;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#71717a;margin-bottom:4px;font-family:'Inter',Arial,sans-serif;">Attendee</div>
        <div style="font-size:18px;font-weight:700;color:#09090b;margin-bottom:4px;font-family:'Inter',Arial,sans-serif;"><?php echo esc_html( $ticket->attendee_name ); ?></div>
        <?php if ( $type_name ) : ?>
          <div style="font-size:12px;color:#a1a1aa;margin-bottom:8px;font-family:'Inter',Arial,sans-serif;"><?php echo esc_html( $type_name ); ?></div>
        <?php endif; ?>
        <div style="font-family:monospace;font-size:18px;font-weight:700;color:<?php echo $accent; ?>;letter-spacing:2px;margin-bottom:16px;"><?php echo esc_html( $short_code ); ?></div>

        <!-- Inline QR code -->
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td align="center" style="padding:4px 0 8px;">
              <img src="<?php echo esc_url( $qr_src ); ?>"
                   width="200" height="200"
                   alt="QR Code"
                   style="display:block;border-radius:12px;border:1px solid #e4e4e7;background:#f4f4f5;">
            </td>
          </tr>
          <tr>
            <td align="center" style="padding:0 0 8px;">
              <p style="font-size:11px;color:#a1a1aa;margin:0;font-family:'Inter',Arial,sans-serif;">Show this QR code at the entrance</p>
            </td>
          </tr>
          <tr>
            <td align="center" style="padding:8px 0 4px;">
              <a href="<?php echo $ticket_url; ?>"
                 style="display:inline-block;padding:14px 32px;background:<?php echo $accent; ?>;color:#ffffff;text-decoration:none;border-radius:100px;font-size:15px;font-weight:700;font-family:'Inter',Arial,sans-serif;letter-spacing:-0.2px;">
                ⬇️ Download Ticket PDF
              </a>
            </td>
          </tr>
          <tr>
            <td align="center" style="padding:8px 0 0;">
              <p style="font-size:11px;color:#a1a1aa;margin:0;font-family:'Inter',Arial,sans-serif;">Opens a page where you can save your ticket as PDF</p>
            </td>
          </tr>
        </table>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="divider"></div>

    <!-- Order summary -->
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr>
        <td style="padding:4px 0;font-size:13px;color:#6b7280;">Order</td>
        <td style="padding:4px 0;font-size:13px;color:#1a1a2e;font-weight:600;text-align:right;"><?php echo esc_html( $data['order_number'] ); ?></td>
      </tr>
      <tr>
        <td style="padding:4px 0;font-size:13px;color:#6b7280;">Tickets</td>
        <td style="padding:4px 0;font-size:13px;color:#1a1a2e;font-weight:600;text-align:right;"><?php echo intval( $data['ticket_count'] ); ?></td>
      </tr>
      <tr>
        <td style="padding:10px 0 4px;font-size:13px;color:#1a1a2e;font-weight:600;border-top:1px solid #f0f0f5;">Total</td>
        <td style="padding:10px 0 4px;font-size:17px;color:#6366f1;font-weight:700;text-align:right;border-top:1px solid #f0f0f5;">
          <?php echo $data['total_amount'] > 0 ? '$' . number_format( $data['total_amount'], 2 ) : 'FREE'; ?>
        </td>
      </tr>
    </table>

    <p style="font-size:12px;color:#9ca3af;margin:20px 0 8px;line-height:1.6;">
      <?php if ( ! empty( $data['has_pdf'] ) ) : ?>📎 Ticket PDF(s) attached to this email.<br><?php endif; ?>
      ❌ Each ticket is valid for one entry only.
    </p>
  </div>

  <!-- Footer -->
  <div class="footer">
    Powered by <a href="<?php echo esc_url( $site_url ); ?>"><?php echo esc_html( $site_name ); ?></a>
  </div>

</div><!-- .card -->
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
        <?php
        return ob_get_clean();
    }
}
