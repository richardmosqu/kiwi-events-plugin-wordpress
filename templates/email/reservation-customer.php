<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Customer-facing reservation email.
 *
 * Provided variables:
 *   $reservation        — wp_ke_reservations row (object)
 *   $event              — WP_Post for the event
 *   $event_date_formatted — string, "Saturday, June 14, 2026 at 8:00 PM"
 *   $arrival_formatted    — string, "Saturday, June 14, 2026 at 8:30 PM"
 *   $venue, $address    — strings (may be empty)
 *   $extras             — array of { id, label, value, type }
 *   $accent_color       — hex string
 *   $is_pending         — bool (manual-mode "submitted" copy vs auto-confirmed)
 *   $site_name, $site_url
 */

$accent        = esc_attr( $accent_color ?? '#6366f1' );
$rgb           = sscanf( $accent, '#%02x%02x%02x' );
$accent_border = 'rgba(' . $rgb[0] . ',' . $rgb[1] . ',' . $rgb[2] . ',0.20)';
$accent_soft   = 'rgba(' . $rgb[0] . ',' . $rgb[1] . ',' . $rgb[2] . ',0.06)';
$short_code    = strtoupper( (string) ( $reservation->reservation_code ?? '' ) );

$headline      = $is_pending ? 'Reservation received' : 'Reservation confirmed!';
$intro         = $is_pending
    ? 'Thanks &mdash; we&rsquo;ve sent your request to the venue. You&rsquo;ll get another email as soon as it&rsquo;s confirmed.'
    : 'Your reservation is locked in. Show this email or your reservation code at arrival.';
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
  <div style="background:<?php echo $accent; ?>;padding:36px 28px;text-align:center;">
    <h1 style="color:#ffffff;font-size:26px;font-weight:800;margin:0 0 6px;letter-spacing:-0.5px;font-family:'Inter',Arial,sans-serif;">
      <?php echo $is_pending ? '⏳ ' : '🎉 '; ?><?php echo esc_html( $headline ); ?>
    </h1>
    <p style="color:rgba(255,255,255,0.85);font-size:15px;margin:0;font-family:'Inter',Arial,sans-serif;">
      <?php echo wp_kses( $intro, array() ); ?>
    </p>
  </div>

  <!-- Body -->
  <div style="padding:28px 28px 8px;">
    <p style="font-size:16px;color:#1a1a2e;margin:0 0 6px;">Hi <strong><?php echo esc_html( $reservation->customer_name ); ?></strong>,</p>
    <p style="font-size:14px;color:#71717a;margin:0 0 24px;line-height:1.6;">
      Here are your reservation details for <strong><?php echo esc_html( $event->post_title ); ?></strong>.
    </p>

    <!-- Event details strip -->
    <div style="background:#fafafa;border-top:1px solid #f0f0f0;border-bottom:1px solid #f0f0f0;padding:16px 0;margin-bottom:20px;">
      <div style="font-size:17px;font-weight:700;color:#09090b;margin-bottom:8px;letter-spacing:-0.3px;font-family:'Inter',Arial,sans-serif;"><?php echo esc_html( $event->post_title ); ?></div>
      <p style="font-size:13px;color:#71717a;line-height:1.7;margin:0;font-family:'Inter',Arial,sans-serif;">
        📅 &nbsp;<?php echo esc_html( $event_date_formatted ); ?>
        <?php if ( ! empty( $venue ) ) : ?><br>📍 &nbsp;<?php echo esc_html( $venue ); ?><?php endif; ?>
        <?php if ( ! empty( $address ) ) : ?><br>&nbsp; &nbsp; &nbsp; <?php echo esc_html( $address ); ?><?php endif; ?>
      </p>
    </div>

    <!-- Reservation card -->
    <div style="background:#ffffff;border:1.5px solid <?php echo $accent_border; ?>;border-radius:16px;padding:20px;margin-bottom:16px;text-align:center;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#71717a;margin-bottom:6px;font-family:'Inter',Arial,sans-serif;">
        Reservation Code
      </div>
      <div style="font-family:monospace;font-size:22px;font-weight:700;color:<?php echo $accent; ?>;letter-spacing:3px;margin-bottom:14px;">
        <?php echo esc_html( $short_code ); ?>
      </div>

      <table width="100%" cellpadding="0" cellspacing="0" style="font-family:'Inter',Arial,sans-serif;text-align:left;">
        <tr>
          <td style="padding:6px 0;font-size:13px;color:#71717a;width:45%;">Arrival</td>
          <td style="padding:6px 0;font-size:14px;color:#09090b;font-weight:600;text-align:right;"><?php echo esc_html( $arrival_formatted ); ?></td>
        </tr>
        <tr>
          <td style="padding:6px 0;font-size:13px;color:#71717a;">Party size</td>
          <td style="padding:6px 0;font-size:14px;color:#09090b;font-weight:600;text-align:right;"><?php echo (int) $reservation->party_size; ?></td>
        </tr>
        <?php if ( ! empty( $reservation->area ) ) : ?>
        <tr>
          <td style="padding:6px 0;font-size:13px;color:#71717a;">Area</td>
          <td style="padding:6px 0;font-size:14px;color:#09090b;font-weight:600;text-align:right;"><?php echo esc_html( $reservation->area ); ?></td>
        </tr>
        <?php endif; ?>
        <tr>
          <td style="padding:6px 0;font-size:13px;color:#71717a;">Status</td>
          <td style="padding:6px 0;font-size:14px;color:<?php echo $is_pending ? '#b45309' : '#15803d'; ?>;font-weight:700;text-align:right;text-transform:uppercase;letter-spacing:0.4px;">
            <?php echo $is_pending ? 'Pending review' : 'Confirmed'; ?>
          </td>
        </tr>
      </table>

      <?php if ( ! empty( $reservation->notes ) ) : ?>
      <div style="margin-top:14px;padding-top:14px;border-top:1px solid #f0f0f0;text-align:left;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;color:#71717a;margin-bottom:4px;font-family:'Inter',Arial,sans-serif;">Your note</div>
        <p style="font-size:13px;color:#27272a;line-height:1.6;margin:0;font-family:'Inter',Arial,sans-serif;"><?php echo nl2br( esc_html( $reservation->notes ) ); ?></p>
      </div>
      <?php endif; ?>
    </div>

    <?php if ( ! empty( $extras ) ) : ?>
    <!-- Submitted answers -->
    <div style="margin-bottom:16px;padding:14px 16px;border:1px solid #f0f0f0;border-radius:12px;background:#fafafa;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;color:#71717a;margin-bottom:10px;font-family:'Inter',Arial,sans-serif;">Tu información</div>
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

    <p style="font-size:12px;color:#9ca3af;margin:20px 0 8px;line-height:1.6;">
      <?php if ( $is_pending ) : ?>
        We&rsquo;ll email you again once the venue confirms or has questions.
      <?php else : ?>
        Need to cancel? Reply to this email and let the venue know as early as possible.
      <?php endif; ?>
    </p>
  </div>

  <!-- Footer -->
  <div style="padding:20px 28px;text-align:center;font-size:11px;color:#9ca3af;border-top:1px solid #f0f0f5;">
    Powered by <a href="<?php echo esc_url( $site_url ); ?>" style="color:<?php echo $accent; ?>;text-decoration:none;"><?php echo esc_html( $site_name ); ?></a>
  </div>

</div>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
