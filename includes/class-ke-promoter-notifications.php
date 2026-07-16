<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * KE_Promoter_Notifications
 *
 * Builds the *context* for promoter assignment emails (CHANGE 3) and
 * hands rows off to KE_Email_Queue::enqueue() with a 2-second stagger
 * between recipients. Never sends synchronously — that's the queue's job.
 *
 * Triggers wired by callers:
 *   - When a promoter is added to an event (REST save_event):
 *       queue_assignment_emails( $event_id, [ $new_promoter_ids ] )
 *   - When a list is bulk-assigned to an event:
 *       queue_assignment_emails( $event_id, [ $list_member_ids ] )
 *   - When a promoter is added to a list that already has upcoming events:
 *       queue_assignment_emails_for_promoter( $promoter_id, [ $event_ids ] )
 */
class KE_Promoter_Notifications {

    const STAGGER_SECS = 2;

    /**
     * Queue assignment emails for one event to multiple promoters.
     */
    public static function queue_assignment_emails( $event_id, array $promoter_ids ) {
        $event_id = (int) $event_id;
        if ( ! $event_id || empty( $promoter_ids ) ) return 0;

        $ctx_base = self::build_event_context( $event_id );
        if ( ! $ctx_base ) return 0;

        $sent = 0;
        $i    = 0;
        foreach ( array_unique( array_map( 'intval', $promoter_ids ) ) as $pid ) {
            if ( ! $pid ) continue;
            $ctx = self::merge_promoter_context( $ctx_base, $event_id, $pid );
            if ( ! $ctx ) continue;
            $delay = $i * self::STAGGER_SECS;
            KE_Email_Queue::enqueue( 'promoter_assignment', $ctx['promoter_email'], $ctx, $delay );
            $sent++;
            $i++;
        }
        return $sent;
    }

    /**
     * Queue assignment emails for one promoter across multiple events.
     * Used when a promoter is added to a list that already targets events.
     */
    public static function queue_assignment_emails_for_promoter( $promoter_id, array $event_ids ) {
        $promoter_id = (int) $promoter_id;
        if ( ! $promoter_id || empty( $event_ids ) ) return 0;

        $sent = 0;
        $i    = 0;
        foreach ( array_unique( array_map( 'intval', $event_ids ) ) as $eid ) {
            if ( ! $eid ) continue;
            $ctx_base = self::build_event_context( $eid );
            if ( ! $ctx_base ) continue;
            $ctx = self::merge_promoter_context( $ctx_base, $eid, $promoter_id );
            if ( ! $ctx ) continue;
            $delay = $i * self::STAGGER_SECS;
            KE_Email_Queue::enqueue( 'promoter_assignment', $ctx['promoter_email'], $ctx, $delay );
            $sent++;
            $i++;
        }
        return $sent;
    }

    /* ─── Context builders ──────────────────────────────────────────── */

    private static function build_event_context( $event_id ) {
        $post = get_post( $event_id );
        if ( ! $post || $post->post_type !== 'ke_event' ) return null;

        // Date — pull the start. Use site-tz formatted.
        $date_start = (string) get_post_meta( $event_id, '_ke_event_date_start', true );
        $venue      = (string) get_post_meta( $event_id, '_ke_event_venue', true );
        $event_date_label = '';
        if ( $date_start ) {
            try {
                $tz = get_post_meta( $event_id, '_ke_event_timezone', true ) ?: wp_timezone_string();
                $dt = new DateTime( $date_start, new DateTimeZone( $tz ) );
                $event_date_label = $dt->format( 'l, F j, Y \a\t g:i A' );
            } catch ( \Throwable $e ) {
                $event_date_label = date( 'F j, Y', strtotime( $date_start ) );
            }
        }

        // Ticket types.
        $tickets = array();
        if ( class_exists( 'KE_Ticket_Types' ) ) {
            $tt = new KE_Ticket_Types();
            $rows = $tt->get_by_event( $event_id );
            foreach ( $rows as $r ) {
                $total = (int) ( $r->quantity_total ?? 0 );
                $sold  = (int) ( $r->quantity_sold  ?? 0 );
                $sold_out = ( $total > 0 && $sold >= $total );
                $tickets[] = array(
                    'name'     => (string) $r->name,
                    'price'    => (float)  $r->price,
                    'sold_out' => $sold_out,
                );
            }
        }

        return array(
            'event_id'           => $event_id,
            'event_title'        => $post->post_title,
            'event_permalink'    => get_permalink( $post ),
            'event_date'         => $event_date_label,
            'event_venue'        => $venue,
            'event_description'  => wp_strip_all_tags( (string) $post->post_content ),
            'ticket_types'       => $tickets,
            'event_terms_html'   => (string) get_post_meta( $event_id, '_ke_promoter_terms', true ),
            'global_terms_html'  => (string) get_option( 'ke_promoter_global_terms', '' ),
        );
    }

    private static function merge_promoter_context( array $base, $event_id, $promoter_id ) {
        global $wpdb;

        $promoter = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ke_promoters WHERE id = %d", $promoter_id
        ) );
        if ( ! $promoter ) return null;

        // Resolve the linked WP user. Orphaned promoters (no user_id) can't
        // receive assignment email — skip them.
        $who = KE_Promoter_Attribution::display_for( $promoter );
        if ( $who['email'] === '' ) return null;

        // Per-event commission (if assigned).
        $assignment = KE_Event_Promoters::get( $event_id, $promoter_id );
        $type  = $assignment ? (string) $assignment->commission_type  : (string) get_option( 'ke_promoter_default_commission_type', 'percentage' );
        $value = $assignment ? (float)  $assignment->commission_value : (float)  get_option( 'ke_promoter_default_commission_value', 0 );

        // Tracking URL: event-permalink + ?promo=slug.
        $tracking_url = KE_Promoter_Attribution::build_tracking_url( $promoter->slug, $event_id );
        $portal_url   = KE_Promoter_Portal::portal_url( $promoter->slug );

        return array_merge( $base, array(
            'promoter_id'      => (int) $promoter->id,
            'promoter_name'    => $who['name'],
            'promoter_email'   => $who['email'],
            'promoter_slug'    => $promoter->slug,
            'commission_type'  => $type,
            'commission_value' => $value,
            'tracking_url'     => $tracking_url,
            'portal_url'       => $portal_url,
            'register_url'     => wp_registration_url(),
        ) );
    }

    /**
     * Send the one-shot welcome email to a freshly-activated promoter.
     *
     * Idempotent: returns false (no-op) if welcome_email_sent is already
     * set on the row. On success, stamps welcome_email_sent = NOW().
     *
     * @param int  $promoter_id
     * @param bool $force        when true, sends even if welcome_email_sent
     *                           is set — used by the admin "Resend welcome"
     *                           link.
     * @return bool true on send, false on skip/fail
     */
    public static function send_welcome_email( $promoter_id, $force = false ) {
        global $wpdb;
        $promoter_id = (int) $promoter_id;
        if ( ! $promoter_id ) return false;

        $promoter = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ke_promoters WHERE id = %d LIMIT 1",
            $promoter_id
        ) );
        if ( ! $promoter ) return false;
        if ( $promoter->status !== 'active' ) return false;
        if ( ! $force && ! empty( $promoter->welcome_email_sent ) ) return false;

        $who = KE_Promoter_Attribution::display_for( $promoter );
        if ( $who['email'] === '' ) {
            error_log( sprintf(
                'KiwiEvents: welcome email skipped for promoter #%d — no linked WP user email',
                $promoter_id
            ) );
            return false;
        }

        $portal_url = class_exists( 'KE_Promoter_Portal' )
                      ? KE_Promoter_Portal::portal_url( $promoter->slug )
                      : home_url( '/promoter/' . rawurlencode( $promoter->slug ) . '/' );

        $accent  = get_option( 'ke_ui_settings', array() );
        $accent  = ! empty( $accent['accent_color'] ) ? sanitize_hex_color( $accent['accent_color'] ) : '#6366f1';
        $terms   = (string) get_option( 'ke_promoter_global_terms', '' );
        $site    = get_bloginfo( 'name' );

        $subject = sprintf(
            /* translators: %s = site name */
            __( '¡Bienvenido al equipo de promotores de %s! 🎉', 'kiwi-events' ),
            $site
        );

        $body = self::render_welcome_html( array(
            'promoter_name' => $who['name'] !== '' ? $who['name'] : $promoter->slug,
            'portal_url'    => $portal_url,
            'site_name'     => $site,
            'accent'        => $accent,
            'global_terms'  => $terms,
        ) );

        $from_name  = get_option( 'ke_email_from_name', $site );
        $from_email = get_option( 'ke_email_from_address', get_bloginfo( 'admin_email' ) );
        $headers    = array(
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from_name} <{$from_email}>",
        );

        $sent = wp_mail( $who['email'], $subject, $body, $headers );

        if ( $sent ) {
            $wpdb->update(
                $wpdb->prefix . 'ke_promoters',
                array( 'welcome_email_sent' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ),
                array( 'id' => $promoter_id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
            return true;
        }

        error_log( sprintf(
            'KiwiEvents: welcome email wp_mail() returned false for promoter #%d (%s)',
            $promoter_id, $who['email']
        ) );
        return false;
    }

    /**
     * Render the welcome email body. Self-contained Apple-style HTML so it
     * looks consistent across mail clients without external CSS.
     */
    private static function render_welcome_html( array $ctx ) {
        $accent     = esc_attr( $ctx['accent'] );
        $name       = esc_html( $ctx['promoter_name'] );
        $portal     = esc_url( $ctx['portal_url'] );
        $site       = esc_html( $ctx['site_name'] );
        $has_terms  = $ctx['global_terms'] !== '';
        $terms_html = $has_terms ? wp_kses_post( $ctx['global_terms'] ) : '';

        ob_start();
        ?><!doctype html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title><?php echo esc_html__( 'Bienvenido', 'kiwi-events' ); ?></title></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:'Inter','Helvetica Neue',Arial,sans-serif;color:#18181b;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 12px;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.05);">

        <tr><td style="background:<?php echo $accent; ?>;padding:32px 32px 24px;text-align:center;">
          <div style="font-size:34px;line-height:1;margin-bottom:8px;">🎉</div>
          <h1 style="margin:0;color:#fff;font-size:24px;font-weight:700;letter-spacing:-0.3px;font-family:'Inter','Helvetica Neue',Arial,sans-serif;">
            <?php echo esc_html( sprintf( __( '¡Bienvenido, %s!', 'kiwi-events' ), $name ) ); ?>
          </h1>
          <p style="margin:8px 0 0;color:rgba(255,255,255,0.92);font-size:14px;">
            <?php echo esc_html( sprintf( __( 'Eres parte oficial del equipo de promotores de %s', 'kiwi-events' ), $site ) ); ?>
          </p>
        </td></tr>

        <tr><td style="padding:28px 32px 8px;">
          <p style="font-size:15px;line-height:1.65;margin:0 0 20px;color:#27272a;">
            <?php esc_html_e( 'Gracias por unirte al equipo. Como promotor, tienes acceso a tu propio dashboard donde puedes ver tus ventas, tus comisiones, y descargar tus links únicos para compartir.', 'kiwi-events' ); ?>
          </p>
        </td></tr>

        <tr><td style="padding:0 32px;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e4e7;border-radius:12px;margin-bottom:16px;">
            <tr><td style="padding:18px 20px;border-bottom:1px solid #f4f4f5;">
              <div style="font-size:14px;font-weight:700;margin-bottom:4px;">🔗 <?php esc_html_e( 'Tus links únicos', 'kiwi-events' ); ?></div>
              <div style="font-size:13px;color:#52525b;line-height:1.55;">
                <?php esc_html_e( 'Cada evento al que te asignen tendrá su propio link único. Comparte estos links con tus amigos y por cada boleto vendido a través de tu link, ganarás tu comisión.', 'kiwi-events' ); ?>
              </div>
            </td></tr>
            <tr><td style="padding:18px 20px;border-bottom:1px solid #f4f4f5;">
              <div style="font-size:14px;font-weight:700;margin-bottom:4px;">📊 <?php esc_html_e( 'Tu dashboard', 'kiwi-events' ); ?></div>
              <div style="font-size:13px;color:#52525b;line-height:1.55;margin-bottom:10px;">
                <?php esc_html_e( 'Accede a tu portal:', 'kiwi-events' ); ?>
                <code style="background:#f4f4f5;padding:2px 6px;border-radius:4px;font-size:12px;"><?php echo $portal; ?></code>
              </div>
              <a href="<?php echo $portal; ?>" style="display:inline-block;background:<?php echo $accent; ?>;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;">
                <?php esc_html_e( 'Abrir mi dashboard', 'kiwi-events' ); ?>
              </a>
            </td></tr>
            <tr><td style="padding:18px 20px;">
              <div style="font-size:14px;font-weight:700;margin-bottom:4px;">💰 <?php esc_html_e( 'Cómo funcionan las comisiones', 'kiwi-events' ); ?></div>
              <div style="font-size:13px;color:#52525b;line-height:1.55;">
                <?php esc_html_e( 'Las comisiones se calculan automáticamente sobre el precio base del boleto (sin incluir el service fee). El monto exacto depende de cada evento — siempre podrás verlo en tu dashboard.', 'kiwi-events' ); ?>
              </div>
            </td></tr>
          </table>
        </td></tr>

        <tr><td style="padding:8px 32px 0;">
          <div style="font-size:13px;font-weight:700;color:#27272a;margin-bottom:8px;"><?php esc_html_e( 'Algunas recomendaciones para empezar:', 'kiwi-events' ); ?></div>
          <ul style="margin:0 0 16px;padding-left:18px;font-size:13px;line-height:1.7;color:#52525b;">
            <li><?php esc_html_e( 'Comparte tus links por WhatsApp, Instagram, o donde tengas audiencia.', 'kiwi-events' ); ?></li>
            <li><?php esc_html_e( 'Cada compra dentro de la sesión donde se abrió tu link cuenta para ti.', 'kiwi-events' ); ?></li>
            <li><?php esc_html_e( 'Si la persona cierra y vuelve sin tu link, no contará — mejor que compren rápido.', 'kiwi-events' ); ?></li>
            <li><?php esc_html_e( 'Las cortesías y boletos gratuitos no generan comisión.', 'kiwi-events' ); ?></li>
          </ul>
        </td></tr>

        <?php if ( $has_terms ) : ?>
        <tr><td style="padding:0 32px;">
          <details style="margin:0 0 16px;font-size:12px;color:#71717a;border-top:1px solid #e4e4e7;padding-top:14px;">
            <summary style="cursor:pointer;font-weight:600;color:#52525b;"><?php esc_html_e( 'Términos y condiciones', 'kiwi-events' ); ?></summary>
            <div style="margin-top:10px;font-size:12px;line-height:1.6;"><?php echo $terms_html; ?></div>
          </details>
        </td></tr>
        <?php endif; ?>

        <tr><td style="padding:20px 32px 28px;border-top:1px solid #f4f4f5;">
          <p style="font-size:13px;color:#52525b;margin:0 0 8px;line-height:1.55;">
            <?php esc_html_e( '¿Preguntas? Responde este correo o contacta a tu organizador. ¡Bienvenido y mucho éxito vendiendo!', 'kiwi-events' ); ?>
          </p>
          <p style="font-size:12px;color:#a1a1aa;margin:6px 0 0;">— <?php echo $site; ?></p>
        </td></tr>

      </table>
    </td></tr>
  </table>
</body></html>
        <?php
        return ob_get_clean();
    }
}
