<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Organizer-facing reservation notification.
 *
 * Provided variables:
 *   $reservation         — wp_ke_reservations row
 *   $event               — WP_Post for the event
 *   $event_date_formatted — string
 *   $arrival_formatted   — string
 *   $extras              — array of { id, label, value, type }
 *   $dashboard_link      — URL to the org dashboard view of this reservation
 *   $accent_color        — hex string
 *   $is_pending          — bool (manual mode)
 *   $site_name, $site_url
 */

$accent        = esc_attr( $accent_color ?? '#6366f1' );
$rgb           = sscanf( $accent, '#%02x%02x%02x' );
$accent_border = 'rgba(' . $rgb[0] . ',' . $rgb[1] . ',' . $rgb[2] . ',0.20)';

$headline = $is_pending ? 'Reservation request' : 'New reservation';
$short_code = strtoupper( (string) ( $reservation->reservation_code ?? '' ) );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( $headline ); ?> &mdash; <?php echo esc_html( $event->post_title ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:'Inter',Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;">
<tr><td>
<div style="background:#ffffff;border-radius:16px;overflow:hidden;margin:24px 16px;">

  <!-- Header -->
  <div style="background:<?php echo $accent; ?>;padding:24px 28px;text-align:center;">
    <h1 style="color:#ffffff;font-size:20px;font-weight:800;margin:0;letter-spacing:-0.5px;font-family:'Inter',Arial,sans-serif;"><?php echo esc_html( $site_name ); ?></h1>
  </div>

  <!-- Body -->
  <div style="padding:32px 28px 24px;">
    <div style="text-align:center;margin-bottom:28px;">
      <h2 style="font-size:26px;font-weight:800;color:#1a1a2e;margin:0 0 8px;letter-spacing:-0.5px;font-family:'Inter',Arial,sans-serif;">
        <?php echo esc_html( $headline ); ?>
      </h2>
      <p style="font-size:16px;color:#3f3f46;margin:0 0 6px;font-weight:600;font-family:'Inter',Arial,sans-serif;"><?php echo esc_html( $event->post_title ); ?></p>
      <p style="font-size:13px;color:#71717a;margin:0;font-family:'Inter',Arial,sans-serif;"><?php echo esc_html( $event_date_formatted ); ?></p>
      <p style="font-family:monospace;font-size:14px;font-weight:700;color:<?php echo $accent; ?>;letter-spacing:2px;margin:10px 0 0;">
        <?php echo esc_html( $short_code ); ?>
      </p>
    </div>

    <!-- Reservation details -->
    <div style="background:#f8f7ff;border:1.5px solid <?php echo $accent_border; ?>;border-radius:12px;padding:20px;margin-bottom:16px;">
      <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#9ca3af;margin-bottom:14px;font-family:'Inter',Arial,sans-serif;">Reservation</div>
      <table width="100%" cellpadding="0" cellspacing="0" style="font-family:'Inter',Arial,sans-serif;">
        <tr><td style="padding:6px 0;font-size:14px;color:#52525b;">Arrival</td><td style="padding:6px 0;font-size:14px;color:#18181b;font-weight:600;text-align:right;"><?php echo esc_html( $arrival_formatted ); ?></td></tr>
        <tr><td style="padding:6px 0;font-size:14px;color:#52525b;">Party size</td><td style="padding:6px 0;font-size:14px;color:#18181b;font-weight:600;text-align:right;"><?php echo (int) $reservation->party_size; ?></td></tr>
        <?php if ( ! empty( $reservation->area ) ) : ?>
        <tr><td style="padding:6px 0;font-size:14px;color:#52525b;">Area</td><td style="padding:6px 0;font-size:14px;color:#18181b;font-weight:600;text-align:right;"><?php echo esc_html( $reservation->area ); ?></td></tr>
        <?php endif; ?>
        <tr>
          <td style="padding:6px 0;font-size:14px;color:#52525b;">Status</td>
          <td style="padding:6px 0;font-size:14px;color:<?php echo $is_pending ? '#b45309' : '#15803d'; ?>;font-weight:700;text-align:right;text-transform:uppercase;letter-spacing:0.4px;">
            <?php echo $is_pending ? 'Pending' : 'Confirmed'; ?>
          </td>
        </tr>
      </table>

      <?php if ( ! empty( $reservation->notes ) ) : ?>
      <div style="margin-top:12px;padding-top:12px;border-top:1px solid #e4e4e7;">
        <div style="font-size:12px;font-weight:600;color:#71717a;margin-bottom:6px;">CUSTOMER NOTE</div>
        <p style="font-size:13px;color:#27272a;line-height:1.6;margin:0;"><?php echo nl2br( esc_html( $reservation->notes ) ); ?></p>
      </div>
      <?php endif; ?>
    </div>

    <!-- Customer block -->
    <div style="background:#f8f7ff;border:1.5px solid <?php echo $accent_border; ?>;border-radius:12px;padding:20px;margin-bottom:16px;">
      <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#9ca3af;margin-bottom:14px;font-family:'Inter',Arial,sans-serif;">Customer</div>
      <table width="100%" cellpadding="0" cellspacing="0" style="font-family:'Inter',Arial,sans-serif;">
        <tr><td style="padding:6px 0;font-size:14px;color:#52525b;">Name</td><td style="padding:6px 0;font-size:14px;color:#18181b;font-weight:600;text-align:right;"><?php echo esc_html( $reservation->customer_name ); ?></td></tr>
        <?php if ( ! empty( $reservation->customer_email ) ) : ?>
        <tr><td style="padding:6px 0;font-size:14px;color:#52525b;">Email</td><td style="padding:6px 0;font-size:14px;text-align:right;"><a href="mailto:<?php echo esc_attr( $reservation->customer_email ); ?>" style="color:<?php echo $accent; ?>;font-weight:600;text-decoration:none;"><?php echo esc_html( $reservation->customer_email ); ?></a></td></tr>
        <?php endif; ?>
        <tr><td style="padding:6px 0;font-size:14px;color:#52525b;">Phone</td><td style="padding:6px 0;font-size:14px;text-align:right;"><a href="tel:<?php echo esc_attr( $reservation->customer_phone ); ?>" style="color:<?php echo $accent; ?>;font-weight:600;text-decoration:none;"><?php echo esc_html( $reservation->customer_phone ); ?></a></td></tr>
      </table>
    </div>

    <?php if ( ! empty( $extras ) ) : ?>
    <!-- Extras -->
    <div style="background:#fafafa;border:1px solid #f0f0f0;border-radius:12px;padding:16px 18px;margin-bottom:16px;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;color:#71717a;margin-bottom:10px;font-family:'Inter',Arial,sans-serif;">Submitted answers</div>
      <table width="100%" cellpadding="0" cellspacing="0" style="font-family:'Inter',Arial,sans-serif;">
        <?php foreach ( $extras as $row ) : ?>
        <tr>
          <td style="padding:3px 0;font-size:12px;color:#71717a;width:45%;vertical-align:top;"><?php echo esc_html( $row['label'] ); ?></td>
          <td style="padding:3px 0;font-size:13px;color:#09090b;font-weight:500;vertical-align:top;"><?php echo esc_html( $row['value'] ); ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>

    <!-- Action -->
    <?php if ( ! empty( $dashboard_link ) ) : ?>
    <div style="text-align:center;margin-top:24px;">
      <a href="<?php echo esc_url( $dashboard_link ); ?>"
         style="display:inline-block;padding:14px 32px;background:<?php echo $accent; ?>;color:#ffffff;text-decoration:none;border-radius:100px;font-weight:600;font-size:15px;letter-spacing:-0.2px;font-family:'Inter',Arial,sans-serif;">
        <?php echo $is_pending ? 'Review Reservation' : 'Open Dashboard'; ?>
      </a>
    </div>
    <?php endif; ?>
  </div>

  <!-- Footer -->
  <div style="padding:20px 28px;text-align:center;font-size:11px;color:#9ca3af;border-top:1px solid #f0f0f5;background:#fafafa;">
    You received this because you are the organizer of this event.<br>
    Powered by <a href="<?php echo esc_url( $site_url ); ?>" style="color:<?php echo $accent; ?>;text-decoration:none;"><?php echo esc_html( $site_name ); ?></a>
  </div>

</div>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
