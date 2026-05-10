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
        error_log( 'KiwiEvents: attempting wp_mail() to ' . $to . ' | subject: ' . $subject );
        $sent = wp_mail( $to, $subject, $body, $headers, $attachments );
        error_log( 'KiwiEvents: wp_mail() result for order ' . $order_id . ': ' . ( $sent ? 'true' : 'false' ) );

        if ( ! $sent ) {
            return new WP_Error( 'email_failed', 'Failed to send ticket email.' );
        }

        // Send admin notification
        try {
            $this->send_admin_notification( $order_id );
        } catch ( \Throwable $e ) {
            error_log( 'KiwiEvents admin notification error for order ' . $order_id . ': ' . $e->getMessage() );
        }

        return true;
    }

    /**
     * Send email notification to admin/organizer when a ticket is purchased
     *
     * @param int $order_id The order ID
     * @return bool
     */
    public function send_admin_notification( $order_id ) {
        // Check global setting
        $settings = get_option( 'ke_notifications_settings', array() );
        $is_enabled = isset( $settings['admin_email_enabled'] ) ? $settings['admin_email_enabled'] : true;
        if ( ! $is_enabled ) {
            return false;
        }

        $orders_handler  = new KE_Orders();
        $tickets_handler = new KE_Tickets();

        $order = $orders_handler->get( $order_id );
        if ( ! $order ) {
            return false;
        }

        $tickets = $tickets_handler->get_by_order( $order_id );
        if ( empty( $tickets ) ) {
            return false;
        }

        $event = get_post( $order->event_id );
        if ( ! $event ) {
            return false;
        }

        // Accent color from plugin settings
        $ui_settings  = get_option( 'ke_ui_settings', array() );
        $accent_color = ! empty( $ui_settings['accent_color'] )
                      ? sanitize_hex_color( $ui_settings['accent_color'] )
                      : '#6366f1';

        // Links
        $qr_link        = esc_url( home_url( '/ticket/' . $tickets[0]->ticket_code ) );
        $dashboard_link = admin_url( 'admin.php?page=ke-attendees&event_id=' . $event->ID . '&order_id=' . $order_id );
        
        $customer_name  = $order->buyer_name;
        $customer_email = $order->buyer_email;
        $formatted_date = date_i18n( get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' ), strtotime( $order->created_at ) );

        // Determine recipient
        $recipients = array();
        
        // 1. Check organizer term meta
        $organizers = wp_get_post_terms( $event->ID, 'ke_organizer' );
        if ( ! is_wp_error( $organizers ) && ! empty( $organizers ) ) {
            $organizer_email = get_term_meta( $organizers[0]->term_id, '_ke_organizer_email', true );
            if ( $organizer_email ) {
                $emails = array_map( 'trim', explode( ',', $organizer_email ) );
                foreach ( $emails as $em ) {
                    if ( is_email( $em ) ) {
                        $recipients[] = $em;
                    }
                }
            }
        }

        // 2. Fallback to admin email
        if ( empty( $recipients ) ) {
            $recipients[] = get_option( 'admin_email' );
        }

        $subject = '🎟️ New Ticket Sale — ' . $event->post_title;
        $to      = implode( ',', $recipients );

        // Prepare context for template
        $template_args = array(
            'event'          => $event,
            'order'          => $order,
            'tickets'        => $tickets,
            'accent_color'   => $accent_color,
            'customer_name'  => $customer_name,
            'customer_email' => $customer_email,
            'qr_link'        => $qr_link,
            'dashboard_link' => $dashboard_link,
            'formatted_date' => $formatted_date,
        );

        ob_start();
        $template_path = KE_PLUGIN_DIR . 'templates/email/admin-notification.php';
        if ( file_exists( $template_path ) ) {
            extract( $template_args );
            include $template_path;
        }
        $body = ob_get_clean();

        if ( ! $body ) {
            return false;
        }

        // Email headers
        $from_name  = get_option( 'ke_email_from_name', get_bloginfo( 'name' ) );
        $from_email = get_option( 'ke_email_from_address', get_bloginfo( 'admin_email' ) );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from_name} <{$from_email}>",
        );

        // Global BCC
        $bcc = isset( $settings['global_bcc'] ) ? trim( $settings['global_bcc'] ) : '';
        if ( is_email( $bcc ) ) {
            $headers[] = 'Bcc: ' . $bcc;
        }

        return wp_mail( $to, $subject, $body, $headers );
    }

    /**
     * Send test admin notification using dummy data
     */
    public function send_test_admin_notification() {
        $ui_settings  = get_option( 'ke_ui_settings', array() );
        $accent_color = ! empty( $ui_settings['accent_color'] )
                      ? sanitize_hex_color( $ui_settings['accent_color'] )
                      : '#6366f1';

        $dummy_event = new stdClass();
        $dummy_event->ID = 0;
        $dummy_event->post_title = 'Sample Event Name';
        
        $dummy_order = new stdClass();
        $dummy_order->order_number = 'TEST-ORDER-123';
        $dummy_order->total_amount = '25.00';
        $dummy_order->payment_method = 'woocommerce';
        $dummy_order->payment_status = 'completed';
        $dummy_order->created_at = current_time('mysql');

        $dummy_ticket = new stdClass();
        $dummy_ticket->ticket_code = 'TEST123456789';
        $dummy_ticket->ticket_type_name = 'General Admission';
        $dummy_ticket->attendee_name = 'Test Customer';
        $dummy_ticket->attendee_email = 'customer@example.com';
        
        $tickets = array( $dummy_ticket );
        
        $qr_link        = home_url();
        $dashboard_link = admin_url( 'admin.php?page=ke-attendees' );
        
        $customer_name  = 'Test Customer';
        $customer_email = 'customer@example.com';
        $formatted_date = date_i18n( get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' ) );

        $template_args = array(
            'event'          => $dummy_event,
            'order'          => $dummy_order,
            'tickets'        => $tickets,
            'accent_color'   => $accent_color,
            'customer_name'  => $customer_name,
            'customer_email' => $customer_email,
            'qr_link'        => $qr_link,
            'dashboard_link' => $dashboard_link,
            'formatted_date' => $formatted_date,
        );

        ob_start();
        $template_path = KE_PLUGIN_DIR . 'templates/email/admin-notification.php';
        if ( file_exists( $template_path ) ) {
            extract( $template_args );
            include $template_path;
        }
        $body = ob_get_clean();

        $from_name  = get_option( 'ke_email_from_name', get_bloginfo( 'name' ) );
        $from_email = get_option( 'ke_email_from_address', get_bloginfo( 'admin_email' ) );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from_name} <{$from_email}>",
        );

        $currentUser = wp_get_current_user();
        if ( ! $currentUser || ! $currentUser->exists() ) {
            return new WP_Error( 'not_logged_in', 'Must be logged in to send test notification.' );
        }

        $to = $currentUser->user_email;
        $subject = '[TEST] 🎟️ New Ticket Sale — ' . $dummy_event->post_title;

        $sent = wp_mail( $to, $subject, $body, $headers );
        if ( ! $sent ) {
            return new WP_Error( 'mail_failed', 'wp_mail() returned false.' );
        }

        return true;
    }

    /* ─── Reservations ─────────────────────────────────────────────────
     * Reservation flows reuse the same wp_mail dispatch pattern as
     * tickets: load a template into an output buffer, then send. The
     * shared helper below resolves the recipient/from/headers so the
     * customer + organizer methods stay focused on payload assembly.
     * ────────────────────────────────────────────────────────────── */

    /**
     * Build the standard {from_name, from_email, headers, bcc} bundle
     * used by reservation emails. Mirrors the headers built in
     * send_ticket_email() / send_admin_notification() so we keep one
     * From/BCC policy across all KiwiEvents email.
     */
    private function build_reservation_headers( $include_global_bcc = false ) {
        $from_name  = get_option( 'ke_email_from_name', get_bloginfo( 'name' ) );
        $from_email = get_option( 'ke_email_from_address', get_bloginfo( 'admin_email' ) );
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from_name} <{$from_email}>",
        );
        if ( $include_global_bcc ) {
            $settings = get_option( 'ke_notifications_settings', array() );
            $bcc = isset( $settings['global_bcc'] ) ? trim( $settings['global_bcc'] ) : '';
            if ( is_email( $bcc ) ) $headers[] = 'Bcc: ' . $bcc;
        }
        return $headers;
    }

    /**
     * Format a "Y-m-d H:i:s" datetime string in the event's timezone.
     * Falls back to site timezone if the event meta is empty/invalid so
     * customer emails never render "TBA" for a real arrival time.
     */
    private function format_event_datetime( $event_id, $datetime_mysql ) {
        if ( ! $datetime_mysql ) return 'TBA';
        try {
            $tz_str = get_post_meta( $event_id, '_ke_event_timezone', true ) ?: wp_timezone_string();
            $dt     = new DateTime( $datetime_mysql, new DateTimeZone( $tz_str ) );
            return $dt->format( 'l, F j, Y \a\t g:i A' );
        } catch ( \Exception $e ) {
            return date( 'l, F j, Y \a\t g:i A', strtotime( $datetime_mysql ) );
        }
    }

    /**
     * Resolve the per-reservation extras to label/value rows using the
     * event's reservations-context field config. Mirrors the ticket
     * resolver but filters out empty answers so we never email an empty
     * "Tu información" block.
     */
    private function reservation_extras_rows( $event_id, $reservation ) {
        if ( empty( $reservation->extra_fields_data ) || ! class_exists( 'KE_Event_Extra_Fields' ) ) {
            return array();
        }
        $rows = KE_Event_Extra_Fields::resolve_for_ticket( (int) $event_id, $reservation->extra_fields_data );
        return array_values( array_filter( $rows, function ( $r ) {
            return isset( $r['value'] ) && $r['value'] !== '';
        } ) );
    }

    /**
     * Send the customer-facing reservation email.
     *
     * Auto-confirm mode → "Reservation confirmed!"
     * Manual mode (status=pending) → "Reservation received" (submitted)
     *
     * Returns true on success, WP_Error on failure. Callers should
     * tolerate failure — a missed email never blocks the booking flow.
     */
    public function send_reservation_customer_email( $reservation_id ) {
        if ( ! class_exists( 'KE_Reservations' ) ) {
            return new WP_Error( 'reservations_unavailable', 'Reservations module unavailable.' );
        }

        $resv_handler = new KE_Reservations();
        $reservation  = $resv_handler->get( (int) $reservation_id );
        if ( ! $reservation ) {
            return new WP_Error( 'reservation_not_found', 'Reservation not found.' );
        }

        // No customer email on file → nothing to send. Not an error;
        // venues frequently skip the optional email field.
        if ( empty( $reservation->customer_email ) || ! is_email( $reservation->customer_email ) ) {
            return false;
        }

        $event = get_post( (int) $reservation->event_id );
        if ( ! $event ) {
            return new WP_Error( 'event_not_found', 'Event not found.' );
        }

        $event_date = get_post_meta( $event->ID, '_ke_event_date_start', true );
        $venue      = get_post_meta( $event->ID, '_ke_event_venue', true );
        $address    = get_post_meta( $event->ID, '_ke_event_address', true );

        $ui_settings  = get_option( 'ke_ui_settings', array() );
        $accent_color = ! empty( $ui_settings['accent_color'] )
                      ? sanitize_hex_color( $ui_settings['accent_color'] )
                      : '#6366f1';

        $template_args = array(
            'reservation'          => $reservation,
            'event'                => $event,
            'event_date_formatted' => $this->format_event_datetime( $event->ID, $event_date ),
            'arrival_formatted'    => $this->format_event_datetime( $event->ID, $reservation->arrival_time ),
            'venue'                => $venue,
            'address'              => $address,
            'extras'               => $this->reservation_extras_rows( $event->ID, $reservation ),
            'accent_color'         => $accent_color,
            'is_pending'           => ( (string) $reservation->status === 'pending' ),
            'site_name'            => get_bloginfo( 'name' ),
            'site_url'             => home_url(),
        );

        ob_start();
        $template_path = KE_PLUGIN_DIR . 'templates/email/reservation-customer.php';
        if ( file_exists( $template_path ) ) {
            extract( $template_args );
            include $template_path;
        }
        $body = ob_get_clean();
        if ( ! $body ) {
            return new WP_Error( 'template_missing', 'Reservation email template missing.' );
        }

        $subject = $template_args['is_pending']
            ? '⏳ Reservation received — ' . $event->post_title
            : '🎉 Reservation confirmed — ' . $event->post_title;

        $sent = wp_mail( $reservation->customer_email, $subject, $body, $this->build_reservation_headers( false ) );
        if ( ! $sent ) {
            return new WP_Error( 'email_failed', 'Could not send reservation email.' );
        }
        return true;
    }

    /**
     * Notify the organizer (or admin fallback) when a new reservation
     * is created. Recipient resolution mirrors send_admin_notification()
     * so the same operator inbox handles both ticket sales and bookings.
     */
    public function send_reservation_organizer_email( $reservation_id ) {
        $settings = get_option( 'ke_notifications_settings', array() );
        // Reuse the same admin-email toggle so operators control both
        // notification types from one switch.
        $is_enabled = isset( $settings['admin_email_enabled'] ) ? $settings['admin_email_enabled'] : true;
        if ( ! $is_enabled ) return false;

        if ( ! class_exists( 'KE_Reservations' ) ) {
            return new WP_Error( 'reservations_unavailable', 'Reservations module unavailable.' );
        }
        $resv_handler = new KE_Reservations();
        $reservation  = $resv_handler->get( (int) $reservation_id );
        if ( ! $reservation ) return false;

        $event = get_post( (int) $reservation->event_id );
        if ( ! $event ) return false;

        $recipients = array();
        $organizers = wp_get_post_terms( $event->ID, 'ke_organizer' );
        if ( ! is_wp_error( $organizers ) && ! empty( $organizers ) ) {
            $organizer_email = get_term_meta( $organizers[0]->term_id, '_ke_organizer_email', true );
            if ( $organizer_email ) {
                foreach ( array_map( 'trim', explode( ',', $organizer_email ) ) as $em ) {
                    if ( is_email( $em ) ) $recipients[] = $em;
                }
            }
        }
        if ( empty( $recipients ) ) {
            $recipients[] = get_option( 'admin_email' );
        }

        $ui_settings  = get_option( 'ke_ui_settings', array() );
        $accent_color = ! empty( $ui_settings['accent_color'] )
                      ? sanitize_hex_color( $ui_settings['accent_color'] )
                      : '#6366f1';

        $event_date = get_post_meta( $event->ID, '_ke_event_date_start', true );

        // Phase 3 will wire the organizer dashboard URL; for now point
        // operators at the WP admin attendee/event view as a stable
        // landing page so the email's CTA always works.
        $dashboard_link = admin_url( 'admin.php?page=ke-attendees&event_id=' . $event->ID );

        $template_args = array(
            'reservation'          => $reservation,
            'event'                => $event,
            'event_date_formatted' => $this->format_event_datetime( $event->ID, $event_date ),
            'arrival_formatted'    => $this->format_event_datetime( $event->ID, $reservation->arrival_time ),
            'extras'               => $this->reservation_extras_rows( $event->ID, $reservation ),
            'dashboard_link'       => $dashboard_link,
            'accent_color'         => $accent_color,
            'is_pending'           => ( (string) $reservation->status === 'pending' ),
            'site_name'            => get_bloginfo( 'name' ),
            'site_url'             => home_url(),
        );

        ob_start();
        $template_path = KE_PLUGIN_DIR . 'templates/email/reservation-organizer.php';
        if ( file_exists( $template_path ) ) {
            extract( $template_args );
            include $template_path;
        }
        $body = ob_get_clean();
        if ( ! $body ) return false;

        $subject = $template_args['is_pending']
            ? '📥 Reservation request — ' . $event->post_title
            : '✅ New reservation — ' . $event->post_title;

        $to = implode( ',', $recipients );
        return wp_mail( $to, $subject, $body, $this->build_reservation_headers( true ) );
    }

    /**
     * Shared dispatcher for the three reservation status-change emails sent
     * from the organizer dashboard (approve / decline / cancel-by-venue).
     *
     * One template (`reservation-status.php`) handles all three variants —
     * the copy and accent vary by `$variant` but the layout, fields, and
     * recipient logic are identical. Returns true on success, false when the
     * customer has no email on file (not an error — venues frequently skip
     * the optional email field), or WP_Error.
     *
     * Variants: 'approved' | 'declined' | 'cancelled_by_venue'
     */
    private function send_reservation_status_email( $reservation_id, $variant ) {
        if ( ! class_exists( 'KE_Reservations' ) ) {
            return new WP_Error( 'reservations_unavailable', 'Reservations module unavailable.' );
        }

        $resv_handler = new KE_Reservations();
        $reservation  = $resv_handler->get( (int) $reservation_id );
        if ( ! $reservation ) {
            return new WP_Error( 'reservation_not_found', 'Reservation not found.' );
        }
        if ( empty( $reservation->customer_email ) || ! is_email( $reservation->customer_email ) ) {
            return false;
        }

        $event = get_post( (int) $reservation->event_id );
        if ( ! $event ) {
            return new WP_Error( 'event_not_found', 'Event not found.' );
        }

        $event_date = get_post_meta( $event->ID, '_ke_event_date_start', true );
        $venue      = get_post_meta( $event->ID, '_ke_event_venue', true );
        $address    = get_post_meta( $event->ID, '_ke_event_address', true );

        $ui_settings  = get_option( 'ke_ui_settings', array() );
        $accent_color = ! empty( $ui_settings['accent_color'] )
                      ? sanitize_hex_color( $ui_settings['accent_color'] )
                      : '#6366f1';

        // Subject + heading copy per variant. Kept here (not in the template)
        // so wp_mail() and the template stay in sync from one switch.
        $copy = array(
            'approved' => array(
                'subject'  => '🎉 Reservation confirmed — ' . $event->post_title,
                'emoji'    => '🎉',
                'headline' => 'Reservation confirmed!',
                'intro'    => 'Great news — the venue has approved your reservation. Show this email or your reservation code at arrival.',
                'pill'     => 'Confirmed',
                'pill_color' => '#15803d',
            ),
            'declined' => array(
                'subject'  => 'Update on your reservation — ' . $event->post_title,
                'emoji'    => '🙁',
                'headline' => 'Reservation declined',
                'intro'    => 'Unfortunately the venue could not confirm your reservation. See details below — feel free to reach out for an alternative.',
                'pill'     => 'Declined',
                'pill_color' => '#b91c1c',
            ),
            'cancelled_by_venue' => array(
                'subject'  => 'Your reservation was cancelled — ' . $event->post_title,
                'emoji'    => '⚠️',
                'headline' => 'Reservation cancelled',
                'intro'    => 'The venue has cancelled this reservation. We&rsquo;re sorry for the inconvenience — please contact the venue if you have questions.',
                'pill'     => 'Cancelled',
                'pill_color' => '#b91c1c',
            ),
            'no_show' => array(
                'subject'  => 'Your reservation was released — ' . $event->post_title,
                'emoji'    => '⏰',
                'headline' => 'Reservation released',
                'intro'    => 'We didn&rsquo;t see you within the grace period after your reserved time, so the venue has released your spot.',
                'pill'     => 'No-show',
                'pill_color' => '#b45309',
            ),
        );
        if ( ! isset( $copy[ $variant ] ) ) {
            return new WP_Error( 'invalid_variant', 'Unknown email variant.' );
        }
        $variant_copy = $copy[ $variant ];

        $template_args = array(
            'reservation'          => $reservation,
            'event'                => $event,
            'event_date_formatted' => $this->format_event_datetime( $event->ID, $event_date ),
            'arrival_formatted'    => $this->format_event_datetime( $event->ID, $reservation->arrival_time ),
            'venue'                => $venue,
            'address'              => $address,
            'extras'               => $this->reservation_extras_rows( $event->ID, $reservation ),
            'accent_color'         => $accent_color,
            'variant'              => $variant,
            'variant_copy'         => $variant_copy,
            'site_name'            => get_bloginfo( 'name' ),
            'site_url'             => home_url(),
        );

        ob_start();
        $template_path = KE_PLUGIN_DIR . 'templates/email/reservation-status.php';
        if ( file_exists( $template_path ) ) {
            extract( $template_args );
            include $template_path;
        }
        $body = ob_get_clean();
        if ( ! $body ) {
            return new WP_Error( 'template_missing', 'Reservation status email template missing.' );
        }

        $sent = wp_mail( $reservation->customer_email, $variant_copy['subject'], $body, $this->build_reservation_headers( false ) );
        if ( ! $sent ) {
            return new WP_Error( 'email_failed', 'Could not send reservation status email.' );
        }
        return true;
    }

    public function send_reservation_approved_email( $reservation_id ) {
        return $this->send_reservation_status_email( $reservation_id, 'approved' );
    }

    public function send_reservation_declined_email( $reservation_id ) {
        return $this->send_reservation_status_email( $reservation_id, 'declined' );
    }

    public function send_reservation_cancelled_by_venue_email( $reservation_id ) {
        return $this->send_reservation_status_email( $reservation_id, 'cancelled_by_venue' );
    }

    public function send_reservation_no_show_email( $reservation_id ) {
        return $this->send_reservation_status_email( $reservation_id, 'no_show' );
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
          // Resolve per-attendee extras (label-resolved against the event's
          // current config) so the buyer sees the answers they submitted.
          $ticket_xfields = array();
          if ( class_exists( 'KE_Event_Extra_Fields' ) && ! empty( $ticket->extra_fields_data ) ) {
              $ticket_xfields = KE_Event_Extra_Fields::resolve_for_ticket(
                  (int) ( $ticket->event_id ?? $data['event_id'] ?? 0 ),
                  $ticket->extra_fields_data
              );
              $ticket_xfields = array_values( array_filter( $ticket_xfields, function ( $row ) {
                  return isset( $row['value'] ) && $row['value'] !== '';
              } ) );
          }
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

        <?php if ( ! empty( $ticket_xfields ) ) : ?>
        <!-- Per-attendee submitted answers ("Tu información") -->
        <div style="margin-top:16px;padding:14px 16px;border:1px solid #f0f0f0;border-radius:12px;background:#fafafa;text-align:left;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;color:#71717a;margin-bottom:10px;font-family:'Inter',Arial,sans-serif;">
            Tu información
          </div>
          <table width="100%" cellpadding="0" cellspacing="0" style="font-family:'Inter',Arial,sans-serif;">
            <?php foreach ( $ticket_xfields as $row ) : ?>
            <tr>
              <td style="padding:3px 0;font-size:12px;color:#71717a;width:45%;vertical-align:top;"><?php echo esc_html( $row['label'] ); ?></td>
              <td style="padding:3px 0;font-size:13px;color:#09090b;font-weight:500;vertical-align:top;"><?php echo esc_html( $row['value'] ); ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
        </div>
        <?php endif; ?>
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
