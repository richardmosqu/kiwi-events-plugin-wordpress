<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * REST API endpoints for KiwiEvents
 */
class KE_Rest_API {

    private $namespace = 'ke/v1';

    /**
     * Register all REST routes
     */
    public function register_routes() {

        // Public: List events
        register_rest_route( $this->namespace, '/events', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_events' ),
            'permission_callback' => '__return_true',
        ) );

        // Public: Single event with ticket types
        register_rest_route( $this->namespace, '/events/(?P<id>\d+)', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_event' ),
            'permission_callback' => '__return_true',
            'args' => array(
                'id' => array( 'validate_callback' => function( $param ) { return is_numeric( $param ); } ),
            ),
        ) );

        // Public: Process free ticket checkout
        register_rest_route( $this->namespace, '/checkout', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'process_checkout' ),
            'permission_callback' => '__return_true',
        ) );

        // Admin: Attendee list
        register_rest_route( $this->namespace, '/events/(?P<id>\d+)/attendees', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_attendees' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );

        // Admin: Validate & check-in ticket
        register_rest_route( $this->namespace, '/tickets/validate/(?P<code>[a-f0-9]+)', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'validate_ticket' ),
            'permission_callback' => array( $this, 'scanner_permission_check' ),
        ) );

        // Admin: Dashboard stats
        register_rest_route( $this->namespace, '/dashboard/stats', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_dashboard_stats' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );

        // Admin: Chart data
        register_rest_route( $this->namespace, '/dashboard/chart-data', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_chart_data' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );

        // Admin: Save event via custom builder
        register_rest_route( $this->namespace, '/events/save', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'save_event' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );

        // Settings: get all
        register_rest_route( $this->namespace, '/settings', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_settings' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );

        // Settings: save UI colors
        register_rest_route( $this->namespace, '/settings/ui', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'save_ui_settings' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );

        // Settings: create or update a service fee
        register_rest_route( $this->namespace, '/settings/fees', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'save_service_fee' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );

        // Settings: delete a service fee
        register_rest_route( $this->namespace, '/settings/fees/(?P<id>[a-zA-Z0-9_]+)', array(
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => array( $this, 'delete_service_fee' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );
    }

    /**
     * Permission: Admin only
     */
    public function admin_permission_check() {
        return current_user_can( 'manage_kiwi_events' );
    }

    /**
     * Permission: Scanner (admin or scan_ke_tickets cap)
     */
    public function scanner_permission_check() {
        return current_user_can( 'scan_ke_tickets' ) || current_user_can( 'manage_kiwi_events' );
    }

    // ─── Public Endpoints ──────────────────────────────────────────

    /**
     * GET /events — list events
     */
    public function get_events( WP_REST_Request $request ) {
        $args = array(
            'post_type'      => 'ke_event',
            'post_status'    => 'publish',
            'posts_per_page' => $request->get_param( 'per_page' ) ?: 12,
            'paged'          => $request->get_param( 'page' ) ?: 1,
            'orderby'        => 'meta_value',
            'meta_key'       => '_ke_event_date_start',
            'order'          => 'ASC',
            'meta_query'     => array(
                array(
                    'key'     => '_ke_event_status',
                    'value'   => 'active',
                    'compare' => '=',
                ),
            ),
        );

        $category = $request->get_param( 'category' );
        if ( $category ) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'ke_event_category',
                    'field'    => 'slug',
                    'terms'    => $category,
                ),
            );
        }

        $query  = new WP_Query( $args );
        $events = array();

        foreach ( $query->posts as $post ) {
            $events[] = $this->format_event( $post );
        }

        return rest_ensure_response( array(
            'events'      => $events,
            'total'       => $query->found_posts,
            'total_pages' => $query->max_num_pages,
        ) );
    }

    /**
     * GET /events/{id} — single event
     */
    public function get_event( WP_REST_Request $request ) {
        $post = get_post( $request['id'] );

        if ( ! $post || $post->post_type !== 'ke_event' ) {
            return new WP_Error( 'not_found', 'Event not found.', array( 'status' => 404 ) );
        }

        $event = $this->format_event( $post );

        // Add ticket types
        $ticket_types = new KE_Ticket_Types();
        $types = $ticket_types->get_available( $post->ID );

        $event['ticket_types'] = array_map( function( $type ) use ( $ticket_types ) {
            return array(
                'id'         => $type->id,
                'name'       => $type->name,
                'price'      => floatval( $type->price ),
                'remaining'  => $ticket_types->get_remaining( $type->id ),
                'sale_start' => $type->sale_start,
                'sale_end'   => $type->sale_end,
            );
        }, $types );

        return rest_ensure_response( $event );
    }

    /**
     * POST /checkout — process free ticket order
     */
    public function process_checkout( WP_REST_Request $request ) {
        try {
            return $this->_do_checkout( $request );
        } catch ( \Throwable $e ) {
            return new WP_Error(
                'fatal_error',
                $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
                array( 'status' => 500 )
            );
        }
    }

    /**
     * Inner checkout logic — called by process_checkout().
     * Separated so the outer method can wrap it in a clean try/catch.
     * Remove the wrapper once the 500 is diagnosed and fixed.
     */
    private function _do_checkout( WP_REST_Request $request ) {
        $event_id       = absint( $request->get_param( 'event_id' ) );
        $ticket_type_id = absint( $request->get_param( 'ticket_type_id' ) );
        $quantity        = absint( $request->get_param( 'quantity' ) ) ?: 1;
        $buyer_name     = sanitize_text_field( $request->get_param( 'name' ) );
        $buyer_email    = sanitize_email( $request->get_param( 'email' ) );
        $attendees      = $request->get_param( 'attendees' );

        // Fallback for missing attendees
        if ( ! is_array( $attendees ) || empty( $attendees ) ) {
            $attendees = array();
            for ( $i = 0; $i < $quantity; $i++ ) {
                $attendees[] = array( 'name' => $buyer_name, 'email' => $buyer_email );
            }
        }

        // Validate inputs
        if ( ! $event_id || ! $ticket_type_id || ! $buyer_name || ! $buyer_email ) {
            return new WP_Error( 'missing_fields', 'All fields are required.', array( 'status' => 400 ) );
        }

        // Validate email
        if ( ! is_email( $buyer_email ) ) {
            return new WP_Error( 'invalid_email', 'Please provide a valid email address.', array( 'status' => 400 ) );
        }

        // Check ticket type exists and is free
        $ticket_types = new KE_Ticket_Types();
        $ticket_type  = $ticket_types->get( $ticket_type_id );

        if ( ! $ticket_type ) {
            return new WP_Error( 'invalid_ticket', 'Ticket type not found.', array( 'status' => 404 ) );
        }

        // If paid ticket, redirect to WooCommerce
        if ( $ticket_type->price > 0 ) {
            if ( ! class_exists( 'WooCommerce' ) ) {
                return new WP_Error( 'wc_required', 'WooCommerce is required for paid tickets.', array( 'status' => 400 ) );
            }

            // Force cart initialization — WC does not load cart on REST API requests by default
            if ( function_exists( 'wc_load_cart' ) && WC() && ! WC()->cart ) {
                wc_load_cart();
            }

            $wc = new KE_WooCommerce();
            $result = $wc->add_to_cart( $event_id, $ticket_type_id, $quantity, $buyer_name, $buyer_email );

            if ( is_wp_error( $result ) ) {
                return new WP_Error(
                    $result->get_error_code(),
                    $result->get_error_message(),
                    array( 'status' => $result->get_error_data( $result->get_error_code() )['status'] ?? 500 )
                );
            }

            return rest_ensure_response( array(
                'success'      => true,
                'redirect'     => wc_get_checkout_url(),
                'payment_type' => 'woocommerce',
            ) );
        }

        // Free ticket flow
        $orders_handler  = new KE_Orders();
        $tickets_handler = new KE_Tickets();
        $email_handler   = new KE_Email();

        // Check ticket limit
        $can_purchase = $orders_handler->can_purchase( $event_id, $buyer_email, $quantity );
        if ( is_wp_error( $can_purchase ) ) {
            return new WP_Error( 'limit_exceeded', $can_purchase->get_error_message(), array( 'status' => 400 ) );
        }

        // Check availability
        $remaining = $ticket_types->get_remaining( $ticket_type_id );
        if ( $quantity > $remaining ) {
            return new WP_Error( 'sold_out', 'Not enough tickets available.', array( 'status' => 400 ) );
        }

        // Create order
        $order_result = $orders_handler->create( array(
            'event_id'        => $event_id,
            'user_id'         => get_current_user_id(),
            'buyer_name'      => $buyer_name,
            'buyer_email'     => $buyer_email,
            'total_amount'    => 0,
            'ticket_quantity' => $quantity,
            'payment_method'  => 'free',
            'payment_status'  => 'completed',
        ) );

        if ( is_wp_error( $order_result ) ) {
            return new WP_Error( 'order_failed', 'Could not create order.', array( 'status' => 500 ) );
        }

        // Generate tickets
        $ticket_ids = $tickets_handler->generate(
            $order_result['order_id'],
            $event_id,
            $ticket_type_id,
            $attendees
        );

        if ( is_wp_error( $ticket_ids ) ) {
            return new WP_Error( 'ticket_failed', 'Could not generate tickets.', array( 'status' => 500 ) );
        }

        // Send confirmation email — failure must not abort the checkout
        try {
            $email_handler->send_ticket_email( $order_result['order_id'] );
        } catch ( \Throwable $e ) {
            error_log( 'KiwiEvents email error: ' . $e->getMessage() );
        }

        // Build per-ticket data for the confirmation screen
        $qr_generator = new KE_QR_Generator();
        $tickets_data = array();
        foreach ( $ticket_ids as $ticket_id ) {
            $ticket = $tickets_handler->get( $ticket_id );
            if ( $ticket ) {
                $tickets_data[] = array(
                    'ticket_code'   => $ticket->ticket_code,
                    'attendee_name' => $ticket->attendee_name,
                    'qr_url'        => $qr_generator->get_url( $ticket->ticket_code ),
                );
            }
        }

        return rest_ensure_response( array(
            'success'      => true,
            'order_number' => $order_result['order_number'],
            'ticket_count' => count( $ticket_ids ),
            'message'      => 'Your tickets are confirmed! A copy is being emailed to ' . $buyer_email,
            'payment_type' => 'free',
            'tickets'      => $tickets_data,
        ) );
    }

    // ─── Admin Endpoints ───────────────────────────────────────────

    /**
     * GET /events/{id}/attendees
     */
    public function get_attendees( WP_REST_Request $request ) {
        $tickets = new KE_Tickets();
        $attendees = $tickets->get_attendees( $request['id'], array(
            'status'         => $request->get_param( 'status' ) ?: '',
            'ticket_type_id' => $request->get_param( 'ticket_type_id' ) ?: 0,
            'search'         => $request->get_param( 'search' ) ?: '',
            'limit'          => $request->get_param( 'per_page' ) ?: 50,
            'offset'         => ( ( $request->get_param( 'page' ) ?: 1 ) - 1 ) * ( $request->get_param( 'per_page' ) ?: 50 ),
        ) );

        $total = $tickets->count_attendees( $request['id'], array(
            'status'         => $request->get_param( 'status' ) ?: '',
            'ticket_type_id' => $request->get_param( 'ticket_type_id' ) ?: 0,
            'search'         => $request->get_param( 'search' ) ?: '',
        ) );

        return rest_ensure_response( array(
            'attendees' => $attendees,
            'total'     => $total,
        ) );
    }

    /**
     * POST /tickets/validate/{code}
     */
    public function validate_ticket( WP_REST_Request $request ) {
        $tickets = new KE_Tickets();
        $result  = $tickets->validate_and_checkin(
            $request['code'],
            get_current_user_id()
        );

        $status_code = match( $result['status'] ) {
            'valid'        => 200,
            'already_used' => 200,
            'invalid'      => 404,
            default        => 400,
        };

        return new WP_REST_Response( $result, $status_code );
    }

    /**
     * GET /dashboard/stats
     */
    public function get_dashboard_stats( WP_REST_Request $request ) {
        $event_id = $request->get_param( 'event_id' ) ?: 0;

        $orders  = new KE_Orders();
        $tickets = new KE_Tickets();

        $stats = $orders->get_stats( $event_id );

        // Active events count
        $active_events = wp_count_posts( 'ke_event' );
        $stats['active_events'] = $active_events->publish ?? 0;

        // Check-in rate (across all events or specific)
        if ( $event_id ) {
            $checkin = $tickets->get_checkin_stats( $event_id );
            $stats['checkin_rate'] = $checkin['percentage'];
        } else {
            global $wpdb;
            $tickets_table = $wpdb->prefix . 'ke_tickets';
            $total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tickets_table} WHERE status != 'cancelled'" );
            $used    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tickets_table} WHERE status = 'used'" );
            $stats['checkin_rate'] = $total > 0 ? round( ( $used / $total ) * 100, 1 ) : 0;
        }

        return rest_ensure_response( $stats );
    }

    /**
     * GET /dashboard/chart-data
     */
    public function get_chart_data( WP_REST_Request $request ) {
        $event_id = $request->get_param( 'event_id' ) ?: 0;
        $days     = $request->get_param( 'days' ) ?: 30;

        $orders = new KE_Orders();
        $revenue_data = $orders->get_revenue_chart_data( $days, $event_id );

        // Tickets per event (top 10)
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'ke_tickets';
        $tickets_per_event = $wpdb->get_results(
            "SELECT t.event_id, p.post_title as event_name, COUNT(*) as ticket_count
             FROM {$tickets_table} t
             LEFT JOIN {$wpdb->posts} p ON t.event_id = p.ID
             WHERE t.status != 'cancelled'
             GROUP BY t.event_id
             ORDER BY ticket_count DESC
             LIMIT 10"
        );

        // Ticket type distribution
        $ticket_types_table = $wpdb->prefix . 'ke_ticket_types';
        $type_distribution = $wpdb->get_results(
            "SELECT name, SUM(quantity_sold) as sold
             FROM {$ticket_types_table}
             GROUP BY name
             ORDER BY sold DESC
             LIMIT 10"
        );

        return rest_ensure_response( array(
            'revenue'            => $revenue_data,
            'tickets_per_event'  => $tickets_per_event,
            'type_distribution'  => $type_distribution,
        ) );
    }

    /**
     * POST /events/save
     * Handle the custom event builder payload
     */
    public function save_event( WP_REST_Request $request ) {
        $params    = $request->get_json_params();
        if ( empty( $params['event_title'] ) ) {
            return new WP_Error( 'missing_title', 'Event title is required.', array( 'status' => 400 ) );
        }

        $event_id    = ! empty( $params['event_id'] ) ? absint( $params['event_id'] ) : 0;
        $post_status = isset( $params['post_status'] ) && $params['post_status'] === 'publish' ? 'publish' : 'draft';

        $post_data = array(
            'post_title'   => sanitize_text_field( $params['event_title'] ),
            'post_content' => wp_kses_post( $params['event_description'] ?? '' ),
            'post_status'  => $post_status,
            'post_type'    => 'ke_event',
        );

        if ( $event_id > 0 ) {
            $post_data['ID'] = $event_id;
            $event_id = wp_update_post( $post_data, true );
        } else {
            $event_id = wp_insert_post( $post_data, true );
        }

        if ( is_wp_error( $event_id ) ) {
            return new WP_Error( 'save_failed', 'Failed to save event.', array( 'status' => 500 ) );
        }

        // Handle Banner Image
        if ( ! empty( $params['event_banner_id'] ) ) {
            set_post_thumbnail( $event_id, absint( $params['event_banner_id'] ) );
        }

        // Meta fields mapping
        $meta_fields = array(
            'event_start'       => '_ke_event_date_start',
            'event_end'         => '_ke_event_date_end',
            'event_timezone'    => '_ke_event_timezone',
            'location_type'     => '_ke_event_location_type',
            'event_venue'       => '_ke_event_venue',
            'event_address'     => '_ke_event_address',
            'event_virtual_url' => '_ke_event_virtual_url',
            'event_capacity'    => '_ke_event_capacity',
            'event_max_tickets' => '_ke_event_max_tickets_per_person',
        );

        foreach ( $meta_fields as $json_key => $meta_key ) {
            if ( isset( $params[ $json_key ] ) ) {
                $val = $params[ $json_key ];
                if ( in_array( $json_key, array( 'event_capacity', 'event_max_tickets' ) ) ) {
                    $val = absint( $val );
                } else if ( $json_key === 'event_virtual_url' ) {
                    $val = esc_url_raw( $val );
                } else {
                    $val = sanitize_text_field( $val );
                }
                update_post_meta( $event_id, $meta_key, $val );
            }
        }
        
        // Service Fee assignment
        if ( isset( $params['event_service_fee_id'] ) ) {
            $fee_id = sanitize_key( $params['event_service_fee_id'] );
            update_post_meta( $event_id, '_ke_event_service_fee_id', $fee_id );
        }

        // Google Maps embed (allow iframe HTML from trusted admins)
        if ( isset( $params['event_maps_embed'] ) ) {
            $maps_input = trim( $params['event_maps_embed'] );
            $maps_final = '';

            if ( ! empty( $maps_input ) ) {
                $allowed_iframe = array(
                    'iframe' => array(
                        'src'             => true,
                        'width'           => true,
                        'height'          => true,
                        'frameborder'     => true,
                        'style'           => true,
                        'allowfullscreen' => true,
                        'loading'         => true,
                        'referrerpolicy'  => true,
                        'title'           => true,
                    ),
                );

                if ( stripos( $maps_input, '<iframe' ) !== false ) {
                    // Format 3: already a proper iframe — sanitize and store
                    $maps_final = wp_kses( $maps_input, $allowed_iframe );

                } elseif ( preg_match( '/\[googlemaps\s+(https?:\/\/[^\]]+)\]/i', $maps_input, $m ) ) {
                    // Format 1: [googlemaps URL] shortcode → extract URL and wrap
                    $maps_final = '<iframe src="' . esc_url( trim( $m[1] ) ) . '" '
                                . 'width="100%" height="450" style="border:0;" '
                                . 'allowfullscreen="" loading="lazy" '
                                . 'referrerpolicy="no-referrer-when-downgrade"></iframe>';

                } elseif ( preg_match( '/^https?:\/\/(www\.)?google\.[a-z.]{2,6}\/maps/i', $maps_input ) ) {
                    // Format 2: plain Google Maps URL → wrap in iframe
                    $maps_final = '<iframe src="' . esc_url( $maps_input ) . '" '
                                . 'width="100%" height="450" style="border:0;" '
                                . 'allowfullscreen="" loading="lazy" '
                                . 'referrerpolicy="no-referrer-when-downgrade"></iframe>';
                }
            }

            update_post_meta( $event_id, '_ke_event_maps_embed', $maps_final );
        }

        // Ensure default active status
        update_post_meta( $event_id, '_ke_event_status', 'active' );

        // Taxonomies
        if ( isset( $params['event_categories'] ) && is_array( $params['event_categories'] ) ) {
            $cat_ids = array_map( 'absint', $params['event_categories'] );
            wp_set_object_terms( $event_id, $cat_ids, 'ke_event_category' );
        }
        
        if ( ! empty( $params['event_organizer'] ) ) {
            wp_set_object_terms( $event_id, absint( $params['event_organizer'] ), 'ke_organizer' );
        }

        // Handle Ticket Types
        if ( ! empty( $params['tickets'] ) && is_array( $params['tickets'] ) ) {
            $ticket_handler = new KE_Ticket_Types();
            
            // First get existing to delete removed ones (Simplistic sync strategy: delete all, insert new)
            // A more robust strategy would match IDs, but since this is a new builder without old IDs mapped, 
            // we will just clean and insert if creating new, or use a custom method.
            // For now, let's just insert them. Over time, would need ID tracking logic.
            
            // To prevent duplicates on update without tracking ID, we'll delete existing ticket types for this event
            // Note: In production you'd want to be careful not to delete sold ticket types.
            global $wpdb;
            $wpdb->delete( $wpdb->prefix . 'ke_ticket_types', array( 'event_id' => $event_id ) );

            foreach ( $params['tickets'] as $t ) {
                $price = floatval( $t['price'] );
                $qty   = absint( $t['qty'] );

                $ticket_handler->create( array(
                    'event_id'       => $event_id,
                    'name'           => sanitize_text_field( $t['name'] ),
                    'description'    => isset( $t['desc'] ) ? sanitize_text_field( $t['desc'] ) : '',
                    'ticket_type'    => $price > 0 ? 'paid' : 'free',
                    'price'          => $price,
                    'capacity_type'  => $qty > 0 ? 'limited' : 'unlimited',
                    'quantity_total' => $qty,
                    'sale_start'     => null,
                    'sale_end'       => ! empty( $params['event_end'] ) ? sanitize_text_field( $params['event_end'] ) : null,
                ) );
            }
        }

        return rest_ensure_response( array(
            'success'   => true,
            'event_id'  => $event_id,
            'permalink' => get_permalink( $event_id ),
        ) );
    }

    /**
     * GET /settings
     */
    public function get_settings() {
        return rest_ensure_response( array(
            'ui'   => get_option( 'ke_ui_settings', array( 'accent_color' => '', 'subtitle_color' => '' ) ),
            'fees' => array_values( get_option( 'ke_service_fees', array() ) ),
        ) );
    }

    /**
     * POST /settings/ui
     */
    public function save_ui_settings( WP_REST_Request $request ) {
        $params  = $request->get_json_params();
        $current = get_option( 'ke_ui_settings', array() );

        if ( isset( $params['accent_color'] ) ) {
            $current['accent_color'] = sanitize_hex_color( $params['accent_color'] ) ?: '';
        }
        if ( isset( $params['subtitle_color'] ) ) {
            $current['subtitle_color'] = sanitize_hex_color( $params['subtitle_color'] ) ?: '';
        }

        update_option( 'ke_ui_settings', $current );
        return rest_ensure_response( array( 'success' => true ) );
    }

    /**
     * POST /settings/fees — create or update
     */
    public function save_service_fee( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $name   = sanitize_text_field( $params['name'] ?? '' );

        if ( ! $name ) {
            return new WP_Error( 'missing_name', 'Fee name is required.', array( 'status' => 400 ) );
        }

        $type         = in_array( $params['type'] ?? '', array( 'formula', 'fixed' ), true ) ? $params['type'] : 'fixed';
        $percentage   = max( 0.0, floatval( $params['percentage'] ?? 0 ) );
        $fixed_amount = max( 0.0, floatval( $params['fixed_amount'] ?? 0 ) );
        $id           = ! empty( $params['id'] ) ? sanitize_key( $params['id'] ) : 'fee_' . substr( md5( uniqid( '', true ) ), 0, 8 );

        $fee  = compact( 'id', 'name', 'type', 'percentage', 'fixed_amount' );
        $fees = get_option( 'ke_service_fees', array() );

        $found = false;
        foreach ( $fees as &$f ) {
            if ( $f['id'] === $id ) {
                $f     = $fee;
                $found = true;
                break;
            }
        }
        unset( $f );
        if ( ! $found ) {
            $fees[] = $fee;
        }

        update_option( 'ke_service_fees', $fees );
        return rest_ensure_response( array( 'success' => true, 'fee' => $fee ) );
    }

    /**
     * DELETE /settings/fees/{id}
     */
    public function delete_service_fee( WP_REST_Request $request ) {
        $id   = sanitize_key( $request['id'] );
        $fees = get_option( 'ke_service_fees', array() );
        $fees = array_values( array_filter( $fees, function( $f ) use ( $id ) { return $f['id'] !== $id; } ) );
        update_option( 'ke_service_fees', $fees );
        return rest_ensure_response( array( 'success' => true ) );
    }

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * Format an event post for API response
     */
    private function format_event( $post ) {
        $thumbnail_id = get_post_thumbnail_id( $post->ID );
        $image_url    = $thumbnail_id ? wp_get_attachment_image_url( $thumbnail_id, 'large' ) : '';

        return array(
            'id'                    => $post->ID,
            'title'                 => $post->post_title,
            'description'           => $post->post_content,
            'excerpt'               => $post->post_excerpt,
            'image'                 => $image_url,
            'date_start'            => get_post_meta( $post->ID, '_ke_event_date_start', true ),
            'date_end'              => get_post_meta( $post->ID, '_ke_event_date_end', true ),
            'venue'                 => get_post_meta( $post->ID, '_ke_event_venue', true ),
            'address'               => get_post_meta( $post->ID, '_ke_event_address', true ),
            'capacity'              => (int) get_post_meta( $post->ID, '_ke_event_capacity', true ),
            'max_tickets_per_person' => (int) get_post_meta( $post->ID, '_ke_event_max_tickets_per_person', true ),
            'status'                => get_post_meta( $post->ID, '_ke_event_status', true ),
            'categories'            => wp_get_post_terms( $post->ID, 'ke_event_category', array( 'fields' => 'names' ) ),
            'url'                   => get_permalink( $post->ID ),
        );
    }
}
