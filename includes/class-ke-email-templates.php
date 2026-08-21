<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * KE_Email_Templates
 *
 * Pure renderer: (template_key, context) → { subject, body, headers }.
 * Stateless. All side effects (sending, logging, retries) live in
 * KE_Email_Queue. Anything in here must be safely runnable inside cron
 * (no $_SESSION, no $_GET, no current-user context).
 */
class KE_Email_Templates {

    /**
     * Dispatch by template key. Unknown templates return null.
     */
    public static function render( $template, array $context ) {
        $template = sanitize_key( $template );
        switch ( $template ) {
            case 'promoter_assignment':
                return self::render_promoter_assignment( $context );
            case 'promoter_commission_earned':
                return self::render_commission_earned( $context );
            case 'board_approved':
                return self::render_board_status( $context, true );
            case 'board_rejected':
                return self::render_board_status( $context, false );
            case 'board_submitted_admin':
                return self::render_board_submitted_admin( $context );
            case 'board_submitted_user':
                return self::render_board_submitted_user( $context );
            case 'tickets_on_sale':
                return self::render_tickets_on_sale( $context );
        }
        return null;
    }

    /** Shared board email shell (own footer — layout() is promoter-specific). */
    private static function board_shell( $heading, $inner, $footer ) {
        $accent   = self::accent();
        $sitename = esc_html( get_bloginfo( 'name' ) );
        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
             . '<body style="margin:0; background:#f1f5f9; font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif; color:#0f172a;">'
             . '<div style="max-width:600px; margin:24px auto; background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 1px 3px rgba(15,23,42,0.06);">'
             . '<div style="background:' . esc_attr( $accent ) . '; color:#fff; padding:20px 24px;">'
             . '<div style="font-size:13px; opacity:0.85;">' . $sitename . '</div>'
             . '<h1 style="margin:4px 0 0; font-size:20px; font-weight:700;">' . esc_html( $heading ) . '</h1>'
             . '</div>'
             . '<div style="padding:24px;">' . $inner . '</div>'
             . '<div style="padding:16px 24px; border-top:1px solid #e2e8f0; font-size:11px; color:#94a3b8;">'
             . esc_html( $footer )
             . '</div></div></body></html>';
    }

    /** Standard headers for board mail; admin variant carries the global BCC. */
    private static function board_headers( $include_global_bcc = false ) {
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        if ( $include_global_bcc ) {
            $settings = get_option( 'ke_notifications_settings', array() );
            $bcc      = isset( $settings['global_bcc'] ) ? trim( (string) $settings['global_bcc'] ) : '';
            if ( is_email( $bcc ) ) {
                $headers[] = 'Bcc: ' . $bcc;
            }
        }
        return $headers;
    }

    /**
     * New board submission → admin. Context: post_title, submitter_name,
     * submitter_email, date_display, location, university, organizer,
     * contact, excerpt, moderation_url. All user content escaped.
     */
    private static function render_board_submitted_admin( array $ctx ) {
        $accent = self::accent();
        $row    = static function ( $label, $value ) {
            if ( '' === trim( (string) $value ) ) {
                return '';
            }
            return '<tr><td style="padding:6px 10px 6px 0; color:#64748b; font-size:13px; white-space:nowrap; vertical-align:top;">' . esc_html( $label ) . '</td>'
                 . '<td style="padding:6px 0; font-size:13px;">' . esc_html( $value ) . '</td></tr>';
        };

        $inner = '<p>Hay una nueva actividad esperando revisión en el board:</p>'
               . '<h2 style="margin:0 0 10px; font-size:17px;">' . esc_html( $ctx['post_title'] ?? '' ) . '</h2>'
               . '<table style="border-collapse:collapse; width:100%;">'
               . $row( 'Enviada por', trim( ( $ctx['submitter_name'] ?? '' ) . ' (' . ( $ctx['submitter_email'] ?? '' ) . ')' ) )
               . $row( 'Fecha / hora', $ctx['date_display'] ?? '' )
               . $row( 'Ubicación', $ctx['location'] ?? '' )
               . $row( 'Universidad', $ctx['university'] ?? '' )
               . $row( 'Organiza', $ctx['organizer'] ?? '' )
               . $row( 'Contacto', $ctx['contact'] ?? '' )
               . $row( 'Descripción', $ctx['excerpt'] ?? '' )
               . '</table>'
               . '<p style="margin-top:18px;"><a href="' . esc_url( $ctx['moderation_url'] ?? '' ) . '" style="display:inline-block; background:' . esc_attr( $accent ) . '; color:#fff; padding:10px 18px; border-radius:8px; text-decoration:none; font-weight:700;">Revisar en moderación</a></p>';

        return array(
            'subject' => 'Nueva actividad del board por revisar — ' . sanitize_text_field( $ctx['post_title'] ?? '' ),
            'body'    => self::board_shell( 'Nueva actividad pendiente', $inner, 'Notificación de moderación del board de ' . get_bloginfo( 'name' ) . '.' ),
            'headers' => self::board_headers( true ),
        );
    }

    /** Submission received → submitter. */
    private static function render_board_submitted_user( array $ctx ) {
        $inner = '<p>Hola ' . esc_html( $ctx['submitter_name'] ?? '' ) . ',</p>'
               . '<p>Recibimos tu actividad <strong>' . esc_html( $ctx['post_title'] ?? '' ) . '</strong>'
               . ( ! empty( $ctx['date_display'] ) ? ' (' . esc_html( $ctx['date_display'] ) . ')' : '' ) . '.</p>'
               . '<p>Será revisada por el equipo y te avisaremos por este medio cuando sea aprobada o si no puede publicarse.</p>';

        return array(
            'subject' => 'Recibimos tu actividad — ' . sanitize_text_field( $ctx['post_title'] ?? '' ),
            'body'    => self::board_shell( 'Actividad recibida', $inner, 'Recibes este correo porque enviaste una actividad al board de ' . get_bloginfo( 'name' ) . '.' ),
            'headers' => self::board_headers( false ),
        );
    }

    /**
     * Ticket-sales waitlist release — "the tickets you asked about are on
     * sale now". Queued by KE_Waitlist_Cron once the event's scheduled
     * opening moment has passed.
     *
     * Context: event_title, event_url, event_date, venue, name, event_id.
     * Must stay cron-safe: no current-user context, no superglobals.
     */
    private static function render_tickets_on_sale( array $ctx ) {
        $accent = self::accent();
        $title  = (string) ( $ctx['event_title'] ?? '' );
        $url    = (string) ( $ctx['event_url'] ?? '' );
        $date   = (string) ( $ctx['event_date'] ?? '' );
        $venue  = (string) ( $ctx['venue'] ?? '' );
        $name   = trim( (string) ( $ctx['name'] ?? '' ) );

        $meta = '';
        if ( $date !== '' || $venue !== '' ) {
            $meta = '<table style="border-collapse:collapse; margin:16px 0;">';
            if ( $date !== '' ) {
                $meta .= '<tr><td style="padding:4px 12px 4px 0; color:#64748b; font-size:13px; white-space:nowrap;">Fecha</td>'
                       . '<td style="padding:4px 0; font-size:13px;">' . esc_html( $date ) . '</td></tr>';
            }
            if ( $venue !== '' ) {
                $meta .= '<tr><td style="padding:4px 12px 4px 0; color:#64748b; font-size:13px; white-space:nowrap;">Lugar</td>'
                       . '<td style="padding:4px 0; font-size:13px;">' . esc_html( $venue ) . '</td></tr>';
            }
            $meta .= '</table>';
        }

        $inner = '<p>' . ( $name !== '' ? 'Hola ' . esc_html( $name ) . ',' : '¡Hola!' ) . '</p>'
               . '<p>Los boletos para <strong>' . esc_html( $title ) . '</strong> ya están a la venta. '
               . 'Te avisamos porque pediste que te notificáramos cuando abriera la venta.</p>'
               . $meta
               . '<p style="margin-top:18px;"><a href="' . esc_url( $url ) . '" style="display:inline-block; background:' . esc_attr( $accent ) . '; color:#fff; padding:12px 22px; border-radius:8px; text-decoration:none; font-weight:700;">Comprar boletos</a></p>'
               . '<p style="margin-top:18px; font-size:13px; color:#64748b;">Si el botón no funciona, copia este enlace en tu navegador:<br>'
               . '<a href="' . esc_url( $url ) . '" style="color:' . esc_attr( $accent ) . '; word-break:break-all;">' . esc_html( $url ) . '</a></p>'
               . '<p style="font-size:13px; color:#64748b;">La disponibilidad es por orden de compra — te recomendamos no esperar.</p>';

        return array(
            'subject' => '¡Ya están a la venta! — ' . sanitize_text_field( $title ),
            'body'    => self::board_shell( 'Boletos disponibles', $inner, 'Recibes este correo porque te uniste a la lista de espera de ' . get_bloginfo( 'name' ) . '.' ),
            'headers' => self::board_headers( false ),
        );
    }

    /**
     * Board submission approved/rejected notification.
     * Context: first_name, post_title, post_url.
     * Uses its own footer (layout() carries promoter-specific footer text).
     */
    private static function render_board_status( array $ctx, $approved ) {
        $accent   = self::accent();
        $sitename = esc_html( get_bloginfo( 'name' ) );
        $name      = esc_html( $ctx['first_name'] ?? '' );
        $title     = esc_html( $ctx['post_title'] ?? '' );
        $url       = esc_url( $ctx['post_url'] ?? '' );
        $raw_title = sanitize_text_field( $ctx['post_title'] ?? '' );

        if ( $approved ) {
            $subject = '¡Tu actividad fue aprobada! — ' . $raw_title;
            $inner   = '<p>Hola ' . $name . ',</p>'
                     . '<p>Tu actividad <strong>' . $title . '</strong> fue aprobada y ya es visible en el board. 🎉</p>'
                     . ( $url ? '<p><a href="' . $url . '" style="display:inline-block; background:' . esc_attr( $accent ) . '; color:#fff; padding:10px 18px; border-radius:8px; text-decoration:none; font-weight:700;">Ver mi actividad</a></p>' : '' );
            $heading = 'Actividad aprobada';
        } else {
            $subject = 'Tu actividad no fue aprobada — ' . $raw_title;
            $reason  = trim( (string) ( $ctx['reason'] ?? '' ) );
            $inner   = '<p>Hola ' . $name . ',</p>'
                     . '<p>Tu actividad <strong>' . $title . '</strong> no fue aprobada esta vez, así que no aparecerá en el board.</p>'
                     . ( '' !== $reason
                        ? '<div style="margin:14px 0; padding:12px 16px; background:#f8fafc; border-left:3px solid #cbd5e1; border-radius:6px;"><strong style="font-size:13px; color:#64748b;">Motivo:</strong><br>' . esc_html( $reason ) . '</div>'
                        : '' )
                     . '<p>Si crees que fue un error o quieres ajustarla, contacta al equipo del sitio.</p>';
            $heading = 'Actividad no aprobada';
        }

        $body = '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
              . '<body style="margin:0; background:#f1f5f9; font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif; color:#0f172a;">'
              . '<div style="max-width:600px; margin:24px auto; background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 1px 3px rgba(15,23,42,0.06);">'
              . '<div style="background:' . esc_attr( $accent ) . '; color:#fff; padding:20px 24px;">'
              . '<div style="font-size:13px; opacity:0.85;">' . $sitename . '</div>'
              . '<h1 style="margin:4px 0 0; font-size:20px; font-weight:700;">' . esc_html( $heading ) . '</h1>'
              . '</div>'
              . '<div style="padding:24px;">' . $inner . '</div>'
              . '<div style="padding:16px 24px; border-top:1px solid #e2e8f0; font-size:11px; color:#94a3b8;">'
              . 'Recibes este correo porque enviaste una actividad al board de ' . $sitename . '.'
              . '</div></div></body></html>';

        return array(
            'subject' => $subject,
            'body'    => $body,
            'headers' => array( 'Content-Type: text/html; charset=UTF-8' ),
        );
    }

    /* ─── Shared helpers ────────────────────────────────────────────── */

    private static function accent() {
        $ui = get_option( 'ke_ui_settings', array() );
        $hex = ! empty( $ui['accent_color'] ) ? sanitize_hex_color( $ui['accent_color'] ) : '#6366f1';
        return $hex ?: '#6366f1';
    }

    private static function currency() {
        return (string) get_option( 'ke_promoter_currency_label', '$' );
    }

    private static function organizer_name() {
        return (string) get_bloginfo( 'name' );
    }

    /**
     * Expand placeholders {commission_rate}, {event_name}, {organizer_name}.
     */
    public static function expand_placeholders( $text, array $vars ) {
        $map = array(
            '{commission_rate}' => isset( $vars['commission_rate'] ) ? (string) $vars['commission_rate'] : '',
            '{event_name}'      => isset( $vars['event_name'] )      ? (string) $vars['event_name']      : '',
            '{organizer_name}'  => isset( $vars['organizer_name'] )  ? (string) $vars['organizer_name']  : '',
        );
        return strtr( (string) $text, $map );
    }

    /**
     * Render commission rate as human label, e.g. "10% of price" or "$5.00 / ticket".
     */
    public static function commission_label( $type, $value ) {
        $currency = self::currency();
        if ( $type === 'fixed' ) {
            return $currency . number_format( (float) $value, 2 ) . ' / ticket';
        }
        return number_format( (float) $value, 2 ) . '% of price';
    }

    /**
     * Inline-base64 QR for the tracking URL. Returns a data URI or '' on failure.
     */
    public static function qr_data_uri( $url ) {
        if ( ! class_exists( 'KE_QR_Generator' ) ) return '';
        try {
            // data_uri() renders PNG, or SVG on a server without GD. Before
            // 2026-08-21 this called a generate_png_blob() that did not exist,
            // so the method_exists() guard silently returned '' and no
            // promoter email ever carried a QR.
            $gen = new KE_QR_Generator();
            return $gen->data_uri( $url, 220 );
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    private static function layout( $title_html, $inner_html ) {
        $accent = self::accent();
        $sitename = esc_html( get_bloginfo( 'name' ) );
        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
             . '<body style="margin:0; background:#f1f5f9; font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif; color:#0f172a;">'
             . '<div style="max-width:600px; margin:24px auto; background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 1px 3px rgba(15,23,42,0.06);">'
             . '<div style="background:' . esc_attr( $accent ) . '; color:#fff; padding:20px 24px;">'
             . '<div style="font-size:13px; opacity:0.85;">' . $sitename . '</div>'
             . '<h1 style="margin:4px 0 0; font-size:20px; font-weight:700;">' . $title_html . '</h1>'
             . '</div>'
             . '<div style="padding:24px;">' . $inner_html . '</div>'
             . '<div style="padding:16px 24px; border-top:1px solid #e2e8f0; font-size:11px; color:#94a3b8;">'
             . esc_html__( 'You are receiving this because you are a registered promoter.', 'kiwi-events' )
             . '</div></div></body></html>';
    }

    /* ─── Templates ─────────────────────────────────────────────────── */

    /**
     * Assignment notification (CHANGE 3).
     *
     * Context keys (all optional unless noted):
     *   promoter_id, promoter_name, promoter_email, promoter_slug (required)
     *   event_id (required), event_title, event_date, event_venue,
     *   event_description, ticket_types (array of { name, price, sold_out }),
     *   commission_type, commission_value,
     *   tracking_url,
     *   global_terms_html, event_terms_html,
     *   portal_url (optional override; default built from promoter_slug),
     *   register_url (optional — link to wp_registration_url for new users)
     */
    private static function render_promoter_assignment( array $ctx ) {
        $accent  = self::accent();
        $org     = self::organizer_name();

        $promoter_name  = isset( $ctx['promoter_name'] ) ? (string) $ctx['promoter_name'] : '';
        $event_title    = isset( $ctx['event_title'] )   ? (string) $ctx['event_title']   : '';
        $event_date     = isset( $ctx['event_date'] )    ? (string) $ctx['event_date']    : '';
        $event_venue    = isset( $ctx['event_venue'] )   ? (string) $ctx['event_venue']   : '';
        $event_desc_raw = isset( $ctx['event_description'] ) ? (string) $ctx['event_description'] : '';
        $tracking_url   = isset( $ctx['tracking_url'] )  ? (string) $ctx['tracking_url']  : '';
        $commission_type  = isset( $ctx['commission_type'] )  ? (string) $ctx['commission_type']  : 'percentage';
        $commission_value = isset( $ctx['commission_value'] ) ? (float)  $ctx['commission_value'] : 0;
        $rate_label     = self::commission_label( $commission_type, $commission_value );
        $ticket_types   = isset( $ctx['ticket_types'] ) && is_array( $ctx['ticket_types'] ) ? $ctx['ticket_types'] : array();

        $vars = array(
            'commission_rate' => $rate_label,
            'event_name'      => $event_title,
            'organizer_name'  => $org,
        );

        $global_terms = isset( $ctx['global_terms_html'] ) ? (string) $ctx['global_terms_html'] : '';
        $event_terms  = isset( $ctx['event_terms_html'] )  ? (string) $ctx['event_terms_html']  : '';
        $global_terms = wp_kses_post( self::expand_placeholders( $global_terms, $vars ) );
        $event_terms  = wp_kses_post( self::expand_placeholders( $event_terms,  $vars ) );

        // Event description preview (200 chars + Read more).
        $desc_plain = trim( wp_strip_all_tags( $event_desc_raw ) );
        $desc_short = mb_strlen( $desc_plain ) > 200 ? ( mb_substr( $desc_plain, 0, 200 ) . '…' ) : $desc_plain;
        $event_permalink = isset( $ctx['event_permalink'] ) ? (string) $ctx['event_permalink'] : '';

        // QR for the tracking URL.
        $qr_uri = $tracking_url ? self::qr_data_uri( $tracking_url ) : '';

        $subject = sprintf(
            /* translators: %s = event title */
            __( 'You\'re assigned to promote %s', 'kiwi-events' ),
            $event_title
        );

        ob_start(); ?>
        <p style="margin:0 0 14px; font-size:15px;">
            <?php echo esc_html( sprintf( __( 'Hi %s,', 'kiwi-events' ), $promoter_name ) ); ?>
        </p>
        <p style="margin:0 0 18px; font-size:14px; line-height:1.55;">
            <?php echo esc_html( sprintf( __( '%s has assigned you to promote a new event. Here\'s everything you need to start selling.', 'kiwi-events' ), $org ) ); ?>
        </p>

        <!-- Event info block -->
        <div style="border:1px solid #e2e8f0; border-radius:10px; padding:16px; margin-bottom:18px;">
            <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.06em; font-weight:600;">
                <?php esc_html_e( 'Event', 'kiwi-events' ); ?>
            </div>
            <div style="font-size:18px; font-weight:700; margin:4px 0 8px;"><?php echo esc_html( $event_title ); ?></div>
            <?php if ( $event_date ) : ?>
                <div style="font-size:13px; color:#475569;">📅 <?php echo esc_html( $event_date ); ?></div>
            <?php endif; ?>
            <?php if ( $event_venue ) : ?>
                <div style="font-size:13px; color:#475569;">📍 <?php echo esc_html( $event_venue ); ?></div>
            <?php endif; ?>
            <?php if ( $desc_short ) : ?>
                <p style="margin:10px 0 0; font-size:13px; line-height:1.55; color:#334155;">
                    <?php echo esc_html( $desc_short ); ?>
                    <?php if ( $event_permalink && mb_strlen( $desc_plain ) > 200 ) : ?>
                        <a href="<?php echo esc_url( $event_permalink ); ?>" style="color:<?php echo esc_attr( $accent ); ?>; text-decoration:none;"><?php esc_html_e( 'Read more', 'kiwi-events' ); ?></a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>

        <!-- Ticket types block -->
        <?php if ( ! empty( $ticket_types ) ) : ?>
            <div style="margin-bottom:18px;">
                <div style="font-size:13px; font-weight:600; color:#0f172a; margin-bottom:8px;">
                    <?php esc_html_e( 'Ticket types', 'kiwi-events' ); ?>
                </div>
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <?php foreach ( $ticket_types as $tt ) :
                        $name = isset( $tt['name'] ) ? (string) $tt['name'] : '';
                        $price = isset( $tt['price'] ) ? (float) $tt['price'] : 0;
                        $sold_out = ! empty( $tt['sold_out'] );
                    ?>
                        <tr>
                            <td style="padding:7px 0; border-bottom:1px solid #f1f5f9;">
                                <?php echo esc_html( $name ); ?>
                                <?php if ( $sold_out ) : ?>
                                    <span style="margin-left:6px; background:#fee2e2; color:#991b1b; padding:1px 8px; border-radius:10px; font-size:11px; font-weight:600;">
                                        <?php esc_html_e( 'Sold out', 'kiwi-events' ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:7px 0; border-bottom:1px solid #f1f5f9; text-align:right; font-variant-numeric:tabular-nums;">
                                <?php echo esc_html( self::currency() . number_format( $price, 2 ) ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>

        <!-- Commission terms -->
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:14px 16px; margin-bottom:18px;">
            <div style="font-size:12px; color:#166534; font-weight:600; text-transform:uppercase; letter-spacing:0.06em;">
                <?php esc_html_e( 'Your commission', 'kiwi-events' ); ?>
            </div>
            <div style="font-size:18px; font-weight:700; color:#14532d; margin-top:4px;">
                <?php echo esc_html( $rate_label ); ?>
            </div>
        </div>

        <!-- Tracking link card + QR -->
        <?php if ( $tracking_url ) : ?>
            <div style="border:2px dashed <?php echo esc_attr( $accent ); ?>; border-radius:12px; padding:18px; margin-bottom:18px; text-align:center;">
                <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.06em; font-weight:600;">
                    <?php esc_html_e( 'Your tracking link', 'kiwi-events' ); ?>
                </div>
                <div style="margin:8px 0; word-break:break-all; font-family:ui-monospace, Menlo, monospace; font-size:13px; color:#0f172a; background:#f8fafc; border-radius:6px; padding:8px 10px;">
                    <?php echo esc_html( $tracking_url ); ?>
                </div>
                <a href="<?php echo esc_url( $tracking_url ); ?>"
                   style="display:inline-block; background:<?php echo esc_attr( $accent ); ?>; color:#fff; padding:10px 18px; border-radius:8px; text-decoration:none; font-weight:600; font-size:13px;">
                    <?php esc_html_e( 'Open & copy link', 'kiwi-events' ); ?>
                </a>
                <?php if ( $qr_uri ) : ?>
                    <div style="margin-top:14px;">
                        <img src="<?php echo esc_attr( $qr_uri ); ?>" alt="QR" width="160" height="160" style="border-radius:8px;">
                        <div style="margin-top:6px; font-size:11px; color:#64748b;">
                            <?php esc_html_e( 'Scan to share', 'kiwi-events' ); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Terms -->
        <?php if ( $global_terms || $event_terms ) : ?>
            <div style="border:1px solid #e2e8f0; border-radius:10px; padding:14px 16px; margin-bottom:18px;">
                <div style="font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:8px;">
                    <?php esc_html_e( 'Terms', 'kiwi-events' ); ?>
                </div>
                <?php if ( $global_terms ) : ?>
                    <div style="font-size:13px; line-height:1.55; color:#334155;"><?php echo $global_terms; ?></div>
                <?php endif; ?>
                <?php if ( $event_terms ) : ?>
                    <div style="margin-top:10px; padding-top:10px; border-top:1px solid #f1f5f9; font-size:13px; line-height:1.55; color:#334155;">
                        <?php echo $event_terms; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Portal link -->
        <?php
        $portal_url = isset( $ctx['portal_url'] ) ? (string) $ctx['portal_url'] : '';
        if ( $portal_url === '' && ! empty( $ctx['promoter_slug'] ) ) {
            $portal_url = home_url( '/promoter/' . rawurlencode( $ctx['promoter_slug'] ) . '/' );
        }
        $register_url = isset( $ctx['register_url'] ) ? (string) $ctx['register_url'] : '';
        ?>
        <?php if ( $portal_url ) : ?>
            <p style="margin:0 0 6px; font-size:13px; color:#475569;">
                <?php esc_html_e( 'View your full dashboard, sales, and other tracking links:', 'kiwi-events' ); ?>
                <br><a href="<?php echo esc_url( $portal_url ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;"><?php echo esc_html( $portal_url ); ?></a>
            </p>
        <?php endif; ?>
        <?php if ( $register_url ) : ?>
            <p style="margin:14px 0 0; padding-top:12px; border-top:1px solid #f1f5f9; font-size:12px; color:#94a3b8;">
                <?php esc_html_e( 'Don\'t have an account yet? Sign in with the email address this message was sent to, or create one here:', 'kiwi-events' ); ?>
                <br><a href="<?php echo esc_url( $register_url ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;"><?php echo esc_html( $register_url ); ?></a>
            </p>
        <?php endif; ?>
        <?php
        $body_inner = ob_get_clean();
        $title_html = esc_html__( 'New event to promote', 'kiwi-events' );

        return array(
            'subject' => $subject,
            'body'    => self::layout( $title_html, $body_inner ),
            'headers' => array( 'Content-Type: text/html; charset=UTF-8' ),
        );
    }

    private static function render_commission_earned( array $ctx ) {
        $accent  = self::accent();
        $promoter_name = (string) ( $ctx['promoter_name'] ?? '' );
        $event_title   = (string) ( $ctx['event_title']   ?? '' );
        $amount        = (float)  ( $ctx['commission_amount'] ?? 0 );
        $buyer_name    = (string) ( $ctx['buyer_name']    ?? '' );
        $portal_url    = (string) ( $ctx['portal_url']    ?? '' );

        $subject = sprintf(
            /* translators: %s amount */
            __( 'You earned %s commission', 'kiwi-events' ),
            self::currency() . number_format( $amount, 2 )
        );

        ob_start(); ?>
        <p style="margin:0 0 12px; font-size:15px;"><?php echo esc_html( sprintf( __( 'Nice work, %s!', 'kiwi-events' ), $promoter_name ) ); ?></p>
        <p style="margin:0 0 16px; font-size:14px; line-height:1.55;">
            <?php
            printf(
                /* translators: 1: amount, 2: event */
                esc_html__( 'You just earned %1$s on a sale for %2$s.', 'kiwi-events' ),
                '<strong>' . esc_html( self::currency() . number_format( $amount, 2 ) ) . '</strong>',
                '<strong>' . esc_html( $event_title ) . '</strong>'
            );
            ?>
        </p>
        <?php if ( $buyer_name ) : ?>
            <p style="margin:0 0 14px; font-size:13px; color:#475569;">
                <?php echo esc_html( sprintf( __( 'Buyer: %s', 'kiwi-events' ), $buyer_name ) ); ?>
            </p>
        <?php endif; ?>
        <?php if ( $portal_url ) : ?>
            <a href="<?php echo esc_url( $portal_url ); ?>"
               style="display:inline-block; background:<?php echo esc_attr( $accent ); ?>; color:#fff; padding:9px 16px; border-radius:8px; text-decoration:none; font-weight:600; font-size:13px;">
                <?php esc_html_e( 'View dashboard', 'kiwi-events' ); ?>
            </a>
        <?php endif; ?>
        <?php
        $body_inner = ob_get_clean();
        return array(
            'subject' => $subject,
            'body'    => self::layout( esc_html__( 'Commission earned', 'kiwi-events' ), $body_inner ),
            'headers' => array( 'Content-Type: text/html; charset=UTF-8' ),
        );
    }

}
