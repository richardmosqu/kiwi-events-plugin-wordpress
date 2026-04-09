<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * Email template — included by KE_Email class
 * Variables available: $data array with buyer info, event details, tickets
 */
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background-color:#f4f4f8;font-family:Arial,Helvetica,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f8;padding:30px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#181826;border-radius:16px;overflow:hidden;">
                    <tr>
                        <td style="background:linear-gradient(135deg,#6bcb77,#4aa356);padding:30px 40px;">
                            <h1 style="margin:0;color:#fff;font-size:22px;">🥝 KiwiEvents</h1>
                            <p style="margin:8px 0 0;color:rgba(255,255,255,0.85);font-size:14px;">Your tickets are ready!</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:40px;">
                            <p style="color:#e0e0e8;font-size:16px;margin:0 0 30px;">
                                Hi <strong style="color:#fff;"><?php echo esc_html( $data['buyer_name'] ); ?></strong>, your tickets are attached as PDF files.
                            </p>
                            <table width="100%" style="background:#22223a;border-radius:12px;border:1px solid #2a2a45;">
                                <tr><td style="padding:24px;">
                                    <h2 style="margin:0 0 12px;color:#fff;font-size:20px;"><?php echo esc_html( $data['event_title'] ); ?></h2>
                                    <p style="margin:4px 0;color:#8888a0;font-size:13px;">📅 <?php echo esc_html( $data['event_date'] ); ?></p>
                                    <?php if ( $data['event_venue'] ) : ?>
                                        <p style="margin:4px 0;color:#8888a0;font-size:13px;">📍 <?php echo esc_html( $data['event_venue'] ); ?></p>
                                    <?php endif; ?>
                                </td></tr>
                            </table>
                            <p style="color:#8888a0;font-size:12px;margin:30px 0 0;">📎 PDF tickets attached &bull; 📱 Show QR at venue &bull; ❌ Single use only</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 40px;border-top:1px solid #2a2a45;">
                            <p style="margin:0;color:#5a5a70;font-size:11px;text-align:center;">Powered by KiwiEvents</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
