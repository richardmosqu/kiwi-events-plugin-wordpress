<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Info — KiwiEvents</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f0f1a;
            color: #e0e0e8;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .ticket-info-card {
            background: #1a1a2e;
            border: 1px solid #2a2a45;
            border-radius: 20px;
            padding: 40px;
            max-width: 420px;
            width: 100%;
            text-align: center;
        }
        .ticket-info-card h1 {
            font-size: 24px;
            font-weight: 700;
            color: #6bcb77;
            margin-bottom: 8px;
        }
        .ticket-info-card .event-name {
            font-size: 20px;
            font-weight: 700;
            color: #fff;
            margin: 20px 0 12px;
        }
        .ticket-info-card .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #2a2a45;
            font-size: 14px;
        }
        .ticket-info-card .info-row span { color: #8888a0; }
        .ticket-info-card .info-row strong { color: #fff; }
        .ticket-status {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            margin-top: 20px;
        }
        .status-valid { background: rgba(107,203,119,0.15); color: #6bcb77; }
        .status-used { background: rgba(96,165,250,0.15); color: #60a5fa; }
        .status-cancelled { background: rgba(239,68,68,0.15); color: #ef4444; }
        .not-found { color: #ef4444; }
    </style>
</head>
<body>
    <div class="ticket-info-card">
        <h1>🥝 KiwiEvents</h1>

        <?php if ( ! $ticket ) : ?>
            <p class="not-found" style="margin-top:20px;">Ticket not found or invalid QR code.</p>
        <?php else : ?>
            <p class="event-name"><?php echo esc_html( $ticket->event_name ); ?></p>

            <div class="info-row">
                <span>Attendee</span>
                <strong><?php echo esc_html( $ticket->attendee_name ); ?></strong>
            </div>
            <div class="info-row">
                <span>Ticket Type</span>
                <strong><?php echo esc_html( $ticket->ticket_type_name ); ?></strong>
            </div>
            <div class="info-row">
                <span>Number</span>
                <strong>#<?php echo str_pad( $ticket->attendee_number, 4, '0', STR_PAD_LEFT ); ?></strong>
            </div>

            <span class="ticket-status status-<?php echo esc_attr( $ticket->status ); ?>">
                <?php
                echo match ( $ticket->status ) {
                    'valid'     => '🟢 Valid',
                    'used'      => '✅ Checked In',
                    'cancelled' => '❌ Cancelled',
                    default     => ucfirst( $ticket->status ),
                };
                ?>
            </span>
        <?php endif; ?>
    </div>
</body>
</html>
