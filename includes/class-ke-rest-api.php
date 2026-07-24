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

        // Public: List events / Admin: Create event
        register_rest_route( $this->namespace, '/events', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_events' ),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'save_event' ),
                'permission_callback' => array( $this, 'admin_permission_check' ),
            ),
        ) );

        // Public: Calendar events for a given date range + category. Returns
        // a minimal payload optimized for the [kiwi_events_calendar] shortcode
        // grid (id, title, slug, permalink, banner, datetimes, venue).
        register_rest_route( $this->namespace, '/calendar-events', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_calendar_events' ),
            'permission_callback' => '__return_true',
        ) );

        // Public: Single event / Admin: Update or Delete event
        register_rest_route( $this->namespace, '/events/(?P<id>\d+)', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_event' ),
                'permission_callback' => '__return_true',
                'args' => array(
                    'id' => array( 'validate_callback' => function( $param ) { return is_numeric( $param ); } ),
                ),
            ),
            array(
                'methods'             => 'PUT',
                'callback'            => array( $this, 'save_event' ),
                'permission_callback' => array( $this, 'admin_permission_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'delete_event' ),
                'permission_callback' => array( $this, 'admin_permission_check' ),
            ),
        ) );

        // Admin: Duplicate an event (creates a draft copy without tickets/orders)
        register_rest_route( $this->namespace, '/events/(?P<id>\d+)/duplicate', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'duplicate_event' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );

        // Admin: Check-in stats for an event
        register_rest_route( $this->namespace, '/events/(?P<id>\d+)/checkin-stats', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_checkin_stats' ),
            'permission_callback' => array( $this, 'scanner_permission_check' ),
        ) );

        // Admin: Toggle a ticket type's active/inactive status. Flips the
        // `status` column on wp_ke_ticket_types so inactive types stop
        // appearing in the public picker without losing sales history.
        register_rest_route( $this->namespace, '/events/(?P<id>\d+)/ticket-types/(?P<type_id>\d+)/toggle-active', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'toggle_ticket_type_active' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
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

        // Admin: Add attendee directly (real or courtesy) without going through
        // checkout. Creates a synthetic order, mints a ticket, emails the QR.
        register_rest_route( $this->namespace, '/events/(?P<id>\d+)/attendees/add', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'admin_add_attendee' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );

        // Validate & check-in ticket. Public route — caller must present a
        // valid scanner session token via the X-KE-Scanner-Token header,
        // OR have admin/scan capability for in-house testing.
        register_rest_route( $this->namespace, '/tickets/validate/(?P<code>[a-f0-9]+)', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'validate_ticket' ),
            'permission_callback' => array( $this, 'scanner_or_token_permission' ),
        ) );

        // Scanner authentication — exchange organizer password for a
        // session token. Public; rate-limited per IP+event by the underlying
        // KE_Scanner_Password helper.
        register_rest_route( $this->namespace, '/scanner/auth', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'scanner_auth' ),
            'permission_callback' => '__return_true',
        ) );

        // Active events list for the scanner UI — public. Returns minimal
        // metadata only; no ticket codes, attendee names, or order totals.
        register_rest_route( $this->namespace, '/scanner/active-events', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_public_active_events' ),
            'permission_callback' => '__return_true',
        ) );

        // Admin: Change ticket status (inline dropdown, modal, etc.)
        register_rest_route( $this->namespace, '/tickets/(?P<id>\d+)/update-status', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_update_ticket_status' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );

        // Admin: Resend the ticket email for this ticket's order
        register_rest_route( $this->namespace, '/tickets/(?P<id>\d+)/resend-email', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_resend_ticket_email' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );

        // Admin: Resend the admin notification email for an order
        register_rest_route( $this->namespace, '/orders/(?P<id>\d+)/resend-admin-notification', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_resend_admin_notification' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );


        // Admin: PUT updates attendee name/email; DELETE soft-cancels (or hard-deletes with ?hard=1)
        register_rest_route( $this->namespace, '/tickets/(?P<id>\d+)', array(
            array(
                'methods'             => 'PUT',
                'callback'            => array( $this, 'rest_update_ticket' ),
                'permission_callback' => array( $this, 'admin_permission_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'rest_delete_ticket' ),
                'permission_callback' => array( $this, 'admin_permission_check' ),
            ),
        ) );

        // Admin: Bulk actions across many tickets
        register_rest_route( $this->namespace, '/tickets/bulk', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_bulk_tickets' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
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

        // Admin: live slug validation for the slug editor.
        register_rest_route( $this->namespace, '/events/check-slug', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'check_event_slug' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
            'args'                => array(
                'slug'       => array( 'type' => 'string', 'required' => true ),
                'exclude_id' => array( 'type' => 'integer', 'required' => false, 'default' => 0 ),
            ),
        ) );

        // Organizer ticket templates — list + create
        register_rest_route( $this->namespace, '/organizers/(?P<id>\d+)/templates', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_organizer_templates' ),
                'permission_callback' => array( $this, 'admin_permission_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'save_organizer_template' ),
                'permission_callback' => array( $this, 'admin_permission_check' ),
            ),
        ) );

        // Organizer password meta — returns has_password + length only.
        // Never returns the plaintext or the hash.
        register_rest_route( $this->namespace, '/organizers/(?P<id>\d+)/password-meta', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_organizer_password_meta' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );

        // Organizer password — set/replace. Hashes via wp_hash_password and
        // invalidates active scanner + dashboard sessions in one shot.
        register_rest_route( $this->namespace, '/organizers/(?P<id>\d+)/update-password', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'update_organizer_password' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );

        // Organizer ticket templates — update + delete
        register_rest_route( $this->namespace, '/organizers/(?P<id>\d+)/templates/(?P<tpl_id>[a-zA-Z0-9_]+)', array(
            array(
                'methods'             => 'PUT',
                'callback'            => array( $this, 'save_organizer_template' ),
                'permission_callback' => array( $this, 'admin_permission_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'delete_organizer_template' ),
                'permission_callback' => array( $this, 'admin_permission_check' ),
            ),
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

        // Settings: save notifications
        register_rest_route( $this->namespace, '/settings/notifications', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'save_notifications_settings' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );

        // Settings: save access control (require login to purchase)
        register_rest_route( $this->namespace, '/settings/access', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'save_access_settings' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );

        // Settings: test notification
        register_rest_route( $this->namespace, '/settings/test-notification', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'send_test_admin_notification' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );

        // Testimonials — list (public) + create (logged-in users)
        register_rest_route( $this->namespace, '/events/(?P<id>\d+)/testimonials', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'list_testimonials' ),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'create_testimonial' ),
                'permission_callback' => function() { return is_user_logged_in(); },
            ),
        ) );

        // Testimonials — moderate (admin only: approve/unapprove/pin) + delete
        register_rest_route( $this->namespace, '/events/(?P<id>\d+)/testimonials/(?P<comment_id>\d+)', array(
            array(
                'methods'             => 'PUT',
                'callback'            => array( $this, 'moderate_testimonial' ),
                'permission_callback' => array( $this, 'admin_permission_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'delete_testimonial' ),
                'permission_callback' => array( $this, 'admin_permission_check' ),
            ),
        ) );

        /* ─── Reservations (public booking) ─────────────────────────────
         * Public REST endpoints used by the single-event reservations
         * sheet. Capacity tracking is enforced server-side inside
         * KE_Reservations::create() (transaction + SELECT FOR UPDATE) so
         * the availability endpoint is purely advisory for UX — the create
         * call is the source of truth.
         * ─────────────────────────────────────────────────────────────── */

        // Public: live capacity snapshot (total/used/remaining + areas)
        register_rest_route( $this->namespace, '/events/(?P<id>\d+)/reservations/availability', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_reservation_availability' ),
            'permission_callback' => '__return_true',
        ) );

        // Public: submit a reservation request
        register_rest_route( $this->namespace, '/reservations', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'process_create_reservation' ),
            'permission_callback' => '__return_true',
        ) );

        /* ─── Organizer self-service dashboard ──────────────────────────
         * Public routes guarded by an HTTP-only cookie session. Auth issues
         * the cookie; subsequent endpoints validate the cookie's bound
         * organizer_id matches the requested slug.
         *
         * Every callback is wrapped in `safe_organizer_callback()` so a
         * Throwable inside any handler degrades to a clean JSON 500 instead
         * of a WSOD that takes the public site down with it (see BUG 8).
         * ─────────────────────────────────────────────────────────────── */

        // Public: organizer login — verifies password, sets session cookie.
        register_rest_route( $this->namespace, '/organizer/auth', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => $this->safe_organizer_callback( 'organizer_auth' ),
            'permission_callback' => '__return_true',
        ) );

        // Logout — clears cookie + transient. Idempotent.
        register_rest_route( $this->namespace, '/organizer/(?P<slug>[a-z0-9_-]+)/logout', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => $this->safe_organizer_callback( 'organizer_logout' ),
            'permission_callback' => '__return_true',
        ) );

        // Headline stats + chart series + per-event breakdown for the dashboard.
        register_rest_route( $this->namespace, '/organizer/(?P<slug>[a-z0-9_-]+)/stats', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => $this->safe_organizer_callback( 'organizer_stats' ),
            'permission_callback' => array( $this, 'organizer_session_permission_check' ),
        ) );

        // Attendees, paginated and searchable.
        register_rest_route( $this->namespace, '/organizer/(?P<slug>[a-z0-9_-]+)/attendees', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => $this->safe_organizer_callback( 'organizer_attendees' ),
            'permission_callback' => array( $this, 'organizer_session_permission_check' ),
        ) );

        // Recent activity (last 10 sales).
        register_rest_route( $this->namespace, '/organizer/(?P<slug>[a-z0-9_-]+)/activity', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => $this->safe_organizer_callback( 'organizer_activity' ),
            'permission_callback' => array( $this, 'organizer_session_permission_check' ),
        ) );

        // Real-time sales beacon. Cheap: one transient read, no DB query.
        // Client polls every 8s while the dashboard tab is visible and
        // triggers a full stats refresh when the timestamp advances.
        register_rest_route( $this->namespace, '/organizer/(?P<slug>[a-z0-9_-]+)/last-sale', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => $this->safe_organizer_callback( 'organizer_last_sale' ),
            'permission_callback' => array( $this, 'organizer_session_permission_check' ),
        ) );

        // CSV export — attendees.
        register_rest_route( $this->namespace, '/organizer/(?P<slug>[a-z0-9_-]+)/export/csv', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => $this->safe_organizer_callback( 'organizer_export_csv' ),
            'permission_callback' => array( $this, 'organizer_session_permission_check' ),
        ) );

        // PDF export — full report (organizer-wide or single-event when ?event_id=N).
        register_rest_route( $this->namespace, '/organizer/(?P<slug>[a-z0-9_-]+)/export/pdf', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => $this->safe_organizer_callback( 'organizer_export_pdf' ),
            'permission_callback' => array( $this, 'organizer_session_permission_check' ),
        ) );

        // Reservations — list with filters (status, event, date, search).
        register_rest_route( $this->namespace, '/organizer/(?P<slug>[a-z0-9_-]+)/reservations', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => $this->safe_organizer_callback( 'organizer_reservations_list' ),
            'permission_callback' => array( $this, 'organizer_session_permission_check' ),
        ) );

        // Reservations — status transitions. Single endpoint dispatches by
        // {action} param so the front-end has one consistent call shape and
        // we don't multiply route-registration boilerplate.
        register_rest_route(
            $this->namespace,
            '/organizer/(?P<slug>[a-z0-9_-]+)/reservations/(?P<id>\d+)/(?P<action>approve|decline|check-in|cancel)',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => $this->safe_organizer_callback( 'organizer_reservation_action' ),
                'permission_callback' => array( $this, 'organizer_session_permission_check' ),
            )
        );

        // Historias Destacadas — organizer-owned highlight collections.
        // Reads allow the owner OR an admin (read-only impersonation); writes
        // require the real organizer session (enforced in require_highlight_write).
        // Numeric ids come straight from the (?P<id>\d+) regex, so no bare-
        // internal validate/sanitize callbacks are needed (PHP 8 safe).
        register_rest_route( $this->namespace, '/organizer/(?P<slug>[a-z0-9_-]+)/highlights', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => $this->safe_organizer_callback( 'organizer_highlights_list' ),
                'permission_callback' => array( $this, 'organizer_session_permission_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => $this->safe_organizer_callback( 'organizer_highlight_create' ),
                'permission_callback' => array( $this, 'organizer_session_permission_check' ),
            ),
        ) );
        register_rest_route( $this->namespace, '/organizer/(?P<slug>[a-z0-9_-]+)/highlights/(?P<id>\d+)', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => $this->safe_organizer_callback( 'organizer_highlight_get' ),
                'permission_callback' => array( $this, 'organizer_session_permission_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE, // POST (multipart) update
                'callback'            => $this->safe_organizer_callback( 'organizer_highlight_update' ),
                'permission_callback' => array( $this, 'organizer_session_permission_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => $this->safe_organizer_callback( 'organizer_highlight_delete' ),
                'permission_callback' => array( $this, 'organizer_session_permission_check' ),
            ),
        ) );

        // Admin (wp-admin) reservation actions — same transitions as the
        // organizer endpoint, but gated on manage_kiwi_events instead of an
        // organizer session, and not scoped to a specific organizer's events.
        // Used by the wp-admin Reservations list page for inline status
        // changes (cancel, approve, decline, check-in).
        register_rest_route(
            $this->namespace,
            '/admin/reservations/(?P<id>\d+)/(?P<action>approve|decline|check-in|cancel)',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'admin_reservation_action' ),
                'permission_callback' => array( $this, 'admin_permission_check' ),
            )
        );

        // Admin: WP-user search for the promoter create/edit form. LIKE-matches
        // on user_email / display_name / user_login. Optionally excludes users
        // that already have a promoter row.
        register_rest_route( $this->namespace, '/users/search', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'search_users' ),
            'permission_callback' => array( $this, 'admin_permission_check' ),
        ) );
    }

    /**
     * Search WP users by email, display name, or login. Returns up to 10
     * results. Used by the admin promoter form's type-ahead picker.
     *
     * Query args:
     *   q                          required, min 2 chars
     *   exclude_existing_promoters bool — when truthy, omit users that already
     *                              have a row in ke_promoters
     */
    public function search_users( WP_REST_Request $request ) {
        $q = trim( (string) $request->get_param( 'q' ) );
        if ( strlen( $q ) < 2 ) {
            return rest_ensure_response( array() );
        }

        global $wpdb;
        $like = '%' . $wpdb->esc_like( $q ) . '%';

        $sql = "SELECT ID, display_name, user_login, user_email
                FROM {$wpdb->users}
                WHERE user_email LIKE %s
                   OR display_name LIKE %s
                   OR user_login LIKE %s
                ORDER BY display_name ASC
                LIMIT 30";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $like, $like, $like ) );

        $exclude = ! empty( $request->get_param( 'exclude_existing_promoters' ) );
        $taken   = array();
        if ( $exclude ) {
            $ids = $wpdb->get_col( "SELECT user_id FROM {$wpdb->prefix}ke_promoters WHERE user_id IS NOT NULL" );
            $taken = array_flip( array_map( 'intval', (array) $ids ) );
        }

        $out = array();
        foreach ( (array) $rows as $r ) {
            if ( $exclude && isset( $taken[ (int) $r->ID ] ) ) continue;
            $out[] = array(
                'id'           => (int) $r->ID,
                'display_name' => $r->display_name ?: $r->user_login,
                'email'        => $r->user_email,
                'avatar_url'   => get_avatar_url( (int) $r->ID, array( 'size' => 48 ) ),
            );
            if ( count( $out ) >= 10 ) break;
        }
        return rest_ensure_response( $out );
    }

    /**
     * Returns a closure that calls the given method and converts any
     * Throwable into a JSON 500 response. Logged so we can diagnose without
     * crashing the site.
     *
     * If the response has already started streaming (CSV/PDF) we can't fix
     * it up — just log and exit so we don't double-write a partial body.
     */
    private function safe_organizer_callback( $method_name ) {
        return function ( WP_REST_Request $request ) use ( $method_name ) {
            try {
                return $this->$method_name( $request );
            } catch ( \Throwable $e ) {
                // Log full stack trace — without it, every dashboard failure
                // looks like the same generic 500 to the operator. The trace
                // lands in wp-content/debug.log when WP_DEBUG_LOG is on.
                error_log( sprintf(
                    "[KiwiEvents] organizer dashboard %s threw: %s in %s:%d\n%s",
                    $method_name,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    $e->getTraceAsString()
                ) );
                if ( headers_sent() ) {
                    exit;
                }
                return new WP_Error(
                    'dashboard_error',
                    __( 'Could not load dashboard data. Please try again or contact support.', 'kiwi-events' ),
                    array( 'status' => 500 )
                );
            }
        };
    }

    /**
     * Permission: Admin only
     */
    public function admin_permission_check() {
        // Fall back to manage_options so site admins can always use plugin REST endpoints
        // even if the custom manage_kiwi_events cap wasn't granted (e.g., activator skipped).
        return current_user_can( 'manage_kiwi_events' ) || current_user_can( 'manage_options' );
    }

    /**
     * Permission: Scanner (admin or scan_ke_tickets cap)
     */
    public function scanner_permission_check() {
        return current_user_can( 'scan_ke_tickets' ) || current_user_can( 'manage_kiwi_events' );
    }

    /**
     * Permission: scanner-token-or-admin. Validates the X-KE-Scanner-Token
     * header against the live transient when present; falls back to admin
     * caps so internal tooling and tests still work without a token.
     *
     * The token's payload (event_id) is stashed on the request via a
     * REST attribute for the validate handler to pick up.
     */
    public function scanner_or_token_permission( WP_REST_Request $request ) {
        $token = $request->get_header( 'x-ke-scanner-token' );
        if ( $token ) {
            $session = KE_Scanner_Password::verify_session_token( $token );
            if ( is_array( $session ) ) {
                if ( ! KE_Scanner_Password::check_scan_rate( $token ) ) {
                    return new WP_Error( 'rate_limited', __( 'Too many scans. Slow down.', 'kiwi-events' ), array( 'status' => 429 ) );
                }
                $request->set_param( '_ke_scanner_session', $session );
                $request->set_param( '_ke_scanner_token',   $token );
                return true;
            }
            return new WP_Error( 'invalid_token', __( 'Scanner session expired or invalid.', 'kiwi-events' ), array( 'status' => 401 ) );
        }
        // No token — allow admin/scan caps as a fallback.
        return $this->scanner_permission_check();
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
     * GET /calendar-events?category=slug&from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * Returns published events whose start datetime falls in [from, to],
     * filtered by ke_event_category slug when given. Designed for the
     * [kiwi_events_calendar] shortcode — minimal payload, no pagination.
     *
     * If from/to are missing or unparseable, defaults to the current month.
     */
    public function get_calendar_events( WP_REST_Request $request ) {
        $category = sanitize_title( (string) $request->get_param( 'category' ) );
        $from_raw = (string) $request->get_param( 'from' );
        $to_raw   = (string) $request->get_param( 'to' );

        $from_ts = $from_raw ? strtotime( $from_raw . ' 00:00:00' ) : false;
        $to_ts   = $to_raw   ? strtotime( $to_raw   . ' 23:59:59' ) : false;

        if ( ! $from_ts || ! $to_ts || $to_ts < $from_ts ) {
            $from_ts = strtotime( date( 'Y-m-01 00:00:00' ) );
            $to_ts   = strtotime( date( 'Y-m-t 23:59:59' ) );
        }

        // Guard rail: cap the range to ~6 months to keep queries bounded
        // even if a client passes a huge window.
        if ( $to_ts - $from_ts > 86400 * 200 ) {
            $to_ts = $from_ts + ( 86400 * 200 );
        }

        $from_str = date( 'Y-m-d H:i:s', $from_ts );
        $to_str   = date( 'Y-m-d H:i:s', $to_ts );

        $args = array(
            'post_type'      => 'ke_event',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'meta_value',
            'meta_key'       => '_ke_event_date_start',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => '_ke_event_status',
                    'value'   => 'active',
                    'compare' => '=',
                ),
                array(
                    'key'     => '_ke_event_date_start',
                    'value'   => array( $from_str, $to_str ),
                    'compare' => 'BETWEEN',
                    'type'    => 'DATETIME',
                ),
            ),
        );

        if ( $category !== '' ) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'ke_event_category',
                    'field'    => 'slug',
                    'terms'    => array( $category ),
                ),
            );
        }

        $query  = new WP_Query( $args );
        $events = array();

        // Drop events whose end datetime has passed — keeps month-paging
        // consistent with the initial preload in KE_Shortcodes::render_calendar.
        $posts = $query->posts;
        if ( class_exists( 'KE_Shortcodes' ) ) {
            $posts = KE_Shortcodes::filter_expired_posts( $posts );
        }

        foreach ( $posts as $post ) {
            $thumb_id  = get_post_thumbnail_id( $post->ID );
            $banner    = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';
            $start     = (string) get_post_meta( $post->ID, '_ke_event_date_start', true );
            $end       = (string) get_post_meta( $post->ID, '_ke_event_date_end', true );
            $venue     = (string) get_post_meta( $post->ID, '_ke_event_venue', true );

            $events[] = array(
                'id'             => $post->ID,
                'title'          => get_the_title( $post ),
                'slug'           => $post->post_name,
                'permalink'      => get_permalink( $post->ID ),
                'banner_url'     => $banner,
                'start_datetime' => $start,
                'end_datetime'   => $end,
                'venue_name'     => $venue,
            );
        }

        return rest_ensure_response( array(
            'from'   => date( 'Y-m-d', $from_ts ),
            'to'     => date( 'Y-m-d', $to_ts ),
            'events' => $events,
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
                'id'        => $type->id,
                'name'      => $type->name,
                'price'     => floatval( $type->price ),
                'remaining' => $ticket_types->get_remaining( $type->id ),
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
        $access = self::get_access_settings();
        if ( ! empty( $access['require_login'] ) && ! is_user_logged_in() ) {
            return new WP_Error(
                'login_required',
                $access['login_required_message'],
                array( 'status' => 401 )
            );
        }

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

        // Validate per-attendee extra fields against the event's saved config.
        // We do this *before* WC redirect / order creation so a missing
        // required field never produces a half-finished cart or order.
        if ( class_exists( 'KE_Event_Extra_Fields' ) && KE_Event_Extra_Fields::is_active( $event_id ) ) {
            foreach ( $attendees as $i => $att ) {
                $submitted = isset( $att['extra_fields'] ) && is_array( $att['extra_fields'] ) ? $att['extra_fields'] : array();
                $clean     = KE_Event_Extra_Fields::validate_attendee( $event_id, $submitted );
                if ( is_wp_error( $clean ) ) {
                    // Annotate which attendee failed so the checkout sheet can
                    // jump focus to the right card.
                    $data = $clean->get_error_data();
                    if ( ! is_array( $data ) ) $data = array();
                    $data['attendee_index'] = (int) $i;
                    return new WP_Error( $clean->get_error_code(), $clean->get_error_message(), $data );
                }
                $attendees[ $i ]['extra_fields'] = $clean;
            }
        } else {
            // Strip any submitted extras so callers can't sneak data past the
            // disabled toggle.
            foreach ( $attendees as $i => $att ) {
                unset( $attendees[ $i ]['extra_fields'] );
            }
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
            $result = $wc->add_to_cart( $event_id, $ticket_type_id, $quantity, $buyer_name, $buyer_email, $attendees );

            if ( is_wp_error( $result ) ) {
                return new WP_Error(
                    $result->get_error_code(),
                    $result->get_error_message(),
                    array( 'status' => $result->get_error_data( $result->get_error_code() )['status'] ?? 500 )
                );
            }

            // URL-only attribution: propagate ?promo= from the event page that
            // originated this REST request into the checkout redirect URL.
            // wc_get_checkout_url() runs through the woocommerce_get_checkout_url
            // filter, but the REST request's own $_GET doesn't carry the param —
            // we have to lift it from HTTP_REFERER ourselves.
            $checkout_url = wc_get_checkout_url();
            $referrer     = (string) $request->get_header( 'referer' );
            if ( $referrer !== '' && class_exists( 'KE_Promoter_Attribution' ) ) {
                $parsed = wp_parse_url( $referrer );
                if ( ! empty( $parsed['query'] ) ) {
                    parse_str( $parsed['query'], $rp );
                    $raw = $rp[ KE_Promoter_Attribution::QUERY_PARAM ]
                        ?? ( $rp[ KE_Promoter_Attribution::QUERY_PARAM_LEGACY ] ?? '' );
                    $slug = sanitize_title( (string) $raw );
                    if ( $slug !== '' ) {
                        $checkout_url = add_query_arg( KE_Promoter_Attribution::QUERY_PARAM, $slug, $checkout_url );
                    }
                }
            }

            return rest_ensure_response( array(
                'success'      => true,
                'redirect'     => $checkout_url,
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

        // Promoter attribution (free flow). URL-only: parse the REST request's
        // referrer (the event page that submitted the form) for ?promo=. The
        // assignment check happens inside resolve_from_referrer().
        if ( class_exists( 'KE_Promoter_Attribution' ) && class_exists( 'KE_Promoter_Commissions' ) ) {
            $referrer = (string) $request->get_header( 'referer' );
            $promoter = KE_Promoter_Attribution::resolve_from_referrer( $referrer, $event_id );
            if ( defined( 'KE_PROMOTER_DEBUG' ) && KE_PROMOTER_DEBUG ) {
                error_log( sprintf(
                    '[KE-PROMO] free_checkout: ke_order=%d event=%d referrer=%s → %s',
                    (int) $order_result['order_id'], (int) $event_id,
                    $referrer !== '' ? $referrer : '(empty)',
                    $promoter ? ('slug=' . (string) $promoter->slug) : 'no-attribution'
                ) );
            }
            if ( $promoter ) {
                KE_Promoter_Commissions::generate_for_order( array(
                    'event_id'           => $event_id,
                    'order_id'           => (int) $order_result['order_id'],
                    'wc_order_id'        => 0,
                    'ticket_ids'         => $ticket_ids,
                    'ticket_base_price'  => floatval( $ticket_type->price ),
                    'promoter_slug'      => (string) $promoter->slug,
                    'buyer_name'         => $buyer_name,
                    'buyer_email'        => $buyer_email,
                    'attribution_method' => 'url',
                ) );
            }
        }

        // Send confirmation email — failure must not abort the checkout
        try {
            $sent = $email_handler->send_ticket_email( $order_result['order_id'] );
            if ( is_wp_error( $sent ) ) {
                error_log( 'KiwiEvents: email failed for order ' . $order_result['order_id'] . ' — ' . $sent->get_error_message() );
            } else {
                error_log( 'KiwiEvents: email sent for order ' . $order_result['order_id'] . ' to ' . $buyer_email );
            }
        } catch ( \Throwable $e ) {
            error_log( 'KiwiEvents email error for order ' . $order_result['order_id'] . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
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

    /* ─── Reservation Endpoints ────────────────────────────────────────
     * Public-facing reservation flow. Capacity-safe creation lives in
     * KE_Reservations::create() — this layer is just request shaping,
     * extras validation, and post-create email dispatch.
     * ────────────────────────────────────────────────────────────── */

    /**
     * GET /events/{id}/reservations/availability
     *
     * Lightweight, public capacity snapshot used by the public sheet to
     * show "X seats remaining" and disable area options that are full.
     * Never exposes customer data — only counts. Returns a stable shape
     * even when reservations are disabled so the JS branch is identical.
     */
    public function get_reservation_availability( WP_REST_Request $request ) {
        $event_id = (int) $request['id'];
        if ( ! class_exists( 'KE_Reservations' ) ) {
            return new WP_Error( 'reservations_unavailable', 'Reservations module is unavailable.', array( 'status' => 503 ) );
        }
        $event = get_post( $event_id );
        if ( ! $event || $event->post_type !== 'ke_event' ) {
            return new WP_Error( 'event_not_found', 'Event not found.', array( 'status' => 404 ) );
        }

        $cfg = KE_Reservations::get_config( $event_id );
        if ( empty( $cfg['enabled'] ) ) {
            return rest_ensure_response( array(
                'enabled' => false,
                'total'   => 0,
                'used'    => 0,
                'remaining' => 0,
                'areas'   => array(),
            ) );
        }

        $resv  = new KE_Reservations();
        $state = $resv->get_capacity_state( $event_id );

        return rest_ensure_response( array(
            'enabled'             => true,
            'total'               => (int) $state['total'],
            'used'                => (int) $state['used'],
            'remaining'           => (int) $state['remaining'],
            'areas'               => $state['areas'],
            'reservations_open'   => $cfg['reservations_open'],
            'reservations_close'  => $cfg['reservations_close'],
            'confirmation_mode'   => $cfg['confirmation_mode'],
        ) );
    }

    /**
     * POST /reservations
     *
     * Wraps create() in a try/catch so a runtime fault returns a clean
     * JSON 500 rather than a WSOD on the public site.
     */
    public function process_create_reservation( WP_REST_Request $request ) {
        try {
            return $this->_do_create_reservation( $request );
        } catch ( \Throwable $e ) {
            error_log( 'KiwiEvents reservation error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
            return new WP_Error(
                'fatal_error',
                'Could not process your reservation. Please try again.',
                array( 'status' => 500 )
            );
        }
    }

    private function _do_create_reservation( WP_REST_Request $request ) {
        if ( ! class_exists( 'KE_Reservations' ) ) {
            return new WP_Error( 'reservations_unavailable', 'Reservations module is unavailable.', array( 'status' => 503 ) );
        }

        // Honour the same access gate as ticket checkout — if the site
        // requires login to purchase, it requires login to reserve.
        $access = self::get_access_settings();
        if ( ! empty( $access['require_login'] ) && ! is_user_logged_in() ) {
            return new WP_Error(
                'login_required',
                $access['login_required_message'],
                array( 'status' => 401 )
            );
        }

        $event_id   = absint( $request->get_param( 'event_id' ) );
        $name       = sanitize_text_field( (string) $request->get_param( 'customer_name' ) );
        $email      = sanitize_email( (string) $request->get_param( 'customer_email' ) );
        $phone      = sanitize_text_field( (string) $request->get_param( 'customer_phone' ) );
        $party_size = absint( $request->get_param( 'party_size' ) );
        $arrival    = (string) $request->get_param( 'arrival_time' );
        $area       = sanitize_text_field( (string) $request->get_param( 'area' ) );
        $notes      = (string) $request->get_param( 'notes' );
        $extras_in  = $request->get_param( 'extra_fields' );

        if ( ! $event_id ) {
            return new WP_Error( 'missing_event', 'Event is required.', array( 'status' => 400 ) );
        }
        $event = get_post( $event_id );
        if ( ! $event || $event->post_type !== 'ke_event' ) {
            return new WP_Error( 'event_not_found', 'Event not found.', array( 'status' => 404 ) );
        }
        if ( ! KE_Reservations::is_active( $event_id ) ) {
            return new WP_Error( 'reservations_disabled', 'Reservations are not available for this event.', array( 'status' => 400 ) );
        }

        $cfg = KE_Reservations::get_config( $event_id );

        // Booking window enforcement — closed window blocks submission.
        $now_ts = current_time( 'timestamp' );
        if ( ! empty( $cfg['reservations_open'] ) ) {
            $open_ts = strtotime( $cfg['reservations_open'] );
            if ( $open_ts && $now_ts < $open_ts ) {
                return new WP_Error( 'window_not_open', 'Reservations are not yet open for this event.', array( 'status' => 400 ) );
            }
        }
        if ( ! empty( $cfg['reservations_close'] ) ) {
            $close_ts = strtotime( $cfg['reservations_close'] );
            if ( $close_ts && $now_ts > $close_ts ) {
                return new WP_Error( 'window_closed', 'Reservations are closed for this event.', array( 'status' => 400 ) );
            }
        }

        if ( $name === '' || $phone === '' ) {
            return new WP_Error( 'missing_fields', 'Name and phone are required.', array( 'status' => 400 ) );
        }
        if ( ! empty( $cfg['show_email_field'] ) ) {
            if ( $email === '' || ! is_email( $email ) ) {
                return new WP_Error( 'invalid_email', 'Please provide a valid email address.', array( 'status' => 400 ) );
            }
        }
        if ( $party_size < 1 ) {
            return new WP_Error( 'invalid_party_size', 'Party size must be at least 1.', array( 'status' => 400 ) );
        }
        if ( $arrival === '' ) {
            return new WP_Error( 'invalid_arrival', 'Please pick an arrival time.', array( 'status' => 400 ) );
        }

        // Validate extras with the reservations visibility filter so
        // tickets-only fields aren't required here (and reservations-only
        // fields are).
        $clean_extras = array();
        if ( class_exists( 'KE_Event_Extra_Fields' ) && KE_Event_Extra_Fields::is_active( $event_id ) ) {
            $submitted = is_array( $extras_in ) ? $extras_in : array();
            $maybe = KE_Event_Extra_Fields::validate_attendee( $event_id, $submitted, 'reservations' );
            if ( is_wp_error( $maybe ) ) return $maybe;
            $clean_extras = $maybe;
        }

        $resv = new KE_Reservations();
        $row  = $resv->create( array(
            'event_id'       => $event_id,
            'customer_name'  => $name,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'party_size'     => $party_size,
            'arrival_time'   => $arrival,
            'area'           => $area !== '' ? $area : null,
            'notes'          => $notes,
            'extra_fields'   => $clean_extras,
        ) );
        if ( is_wp_error( $row ) ) {
            // When the publisher hides numeric capacity from customers, the
            // server-side error must not leak the remaining count. Swap to a
            // generic "fully booked" message in those cases.
            $code = $row->get_error_code();
            if ( $code === 'capacity_full' && empty( $cfg['show_total_capacity'] ) ) {
                return new WP_Error(
                    'capacity_full',
                    'Sorry, this event is fully booked for that time. Try a different time slot.',
                    array( 'status' => 409 )
                );
            }
            if ( $code === 'area_full' && empty( $cfg['show_area_capacity'] ) ) {
                return new WP_Error(
                    'area_full',
                    'Sorry, this area is fully booked for that time. Try a different area or time slot.',
                    array( 'status' => 409 )
                );
            }
            return $row;
        }

        // Send customer + organizer emails — failures are logged but
        // never block a successful reservation. Customer sees success;
        // operator can resend from the dashboard if needed.
        if ( class_exists( 'KE_Email' ) ) {
            try {
                $email_handler = new KE_Email();
                if ( method_exists( $email_handler, 'send_reservation_customer_email' ) ) {
                    $email_handler->send_reservation_customer_email( (int) $row->id );
                }
                if ( method_exists( $email_handler, 'send_reservation_organizer_email' ) ) {
                    $email_handler->send_reservation_organizer_email( (int) $row->id );
                }
            } catch ( \Throwable $e ) {
                error_log( 'KiwiEvents reservation email error for #' . $row->id . ': ' . $e->getMessage() );
            }
        }

        $message = ( $cfg['confirmation_mode'] === 'manual' )
            ? 'Reservation submitted! The venue will review and confirm shortly.'
            : 'Reservation confirmed! Check your email for the details.';

        return rest_ensure_response( array(
            'success'           => true,
            'reservation_id'    => (int) $row->id,
            'reservation_code'  => (string) $row->reservation_code,
            'status'            => (string) $row->status,
            'confirmation_mode' => (string) $cfg['confirmation_mode'],
            'message'           => $message,
        ) );
    }

    // ─── Admin Endpoints ───────────────────────────────────────────

    /**
     * GET /events/{id}/attendees
     */
    public function get_attendees( WP_REST_Request $request ) {
        $tickets = new KE_Tickets();
        $event_id = (int) $request['id'];

        // Tri-state courtesy filter: 'real' (is_courtesy=0), 'courtesy' (is_courtesy=1),
        // or '' / 'all' / missing → no filter.
        $type_param  = (string) $request->get_param( 'attendee_type' );
        $is_courtesy = null;
        if ( $type_param === 'real' )     $is_courtesy = 0;
        if ( $type_param === 'courtesy' ) $is_courtesy = 1;

        $attendees = $tickets->get_attendees( $event_id, array(
            'status'         => $request->get_param( 'status' ) ?: '',
            'ticket_type_id' => $request->get_param( 'ticket_type_id' ) ?: 0,
            'search'         => $request->get_param( 'search' ) ?: '',
            'is_courtesy'    => $is_courtesy,
            'limit'          => $request->get_param( 'per_page' ) ?: 50,
            'offset'         => ( ( $request->get_param( 'page' ) ?: 1 ) - 1 ) * ( $request->get_param( 'per_page' ) ?: 50 ),
        ) );

        $total = $tickets->count_attendees( $event_id, array(
            'status'         => $request->get_param( 'status' ) ?: '',
            'ticket_type_id' => $request->get_param( 'ticket_type_id' ) ?: 0,
            'search'         => $request->get_param( 'search' ) ?: '',
            'is_courtesy'    => $is_courtesy,
        ) );

        // Resolve raw JSON in extra_fields_data → labelled array using the
        // event's current config. The DB column stores stable IDs so this
        // step is what makes the values human-readable downstream.
        if ( class_exists( 'KE_Event_Extra_Fields' ) ) {
            foreach ( $attendees as $a ) {
                $a->extra_fields = KE_Event_Extra_Fields::resolve_for_ticket(
                    $event_id,
                    $a->extra_fields_data ?? null
                );
            }
            $extra_cfg = KE_Event_Extra_Fields::get_config( $event_id );
        } else {
            $extra_cfg = array( 'enabled' => false, 'fields' => array() );
        }

        return rest_ensure_response( array(
            'attendees'           => $attendees,
            'total'               => $total,
            'extra_fields_config' => $extra_cfg,
        ) );
    }

    /**
     * POST /events/{id}/attendees/add — admin-only "Add attendee" flow.
     *
     * Creates a synthetic order with payment_method='admin', mints a single
     * ticket via KE_Tickets::generate(), and emails the QR. Skips the
     * promoter-commission path (no session, no slug). Capacity is enforced
     * for BOTH real and courtesy attendees — courtesy occupies seats.
     */
    public function admin_add_attendee( WP_REST_Request $request ) {
        $event_id       = (int) $request['id'];
        $ticket_type_id = (int) $request->get_param( 'ticket_type_id' );
        $name           = sanitize_text_field( (string) $request->get_param( 'name' ) );
        $email          = sanitize_email( (string) $request->get_param( 'email' ) );
        $is_courtesy    = filter_var( $request->get_param( 'is_courtesy' ), FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
        $extra_fields   = $request->get_param( 'extra_fields' );

        if ( ! $event_id || ! $ticket_type_id || $name === '' || ! is_email( $email ) ) {
            return new WP_Error( 'missing_fields', __( 'Name, email, and ticket type are required.', 'kiwi-events' ), array( 'status' => 400 ) );
        }

        $ticket_types = new KE_Ticket_Types();
        $type         = $ticket_types->get( $ticket_type_id );
        if ( ! $type || (int) $type->event_id !== $event_id ) {
            return new WP_Error( 'invalid_ticket', __( 'Ticket type not found for this event.', 'kiwi-events' ), array( 'status' => 404 ) );
        }

        // Capacity check applies to courtesy too — they occupy seats.
        $remaining = $ticket_types->get_remaining( $ticket_type_id );
        if ( $remaining < 1 ) {
            return new WP_Error( 'sold_out', __( 'No remaining capacity on this ticket type.', 'kiwi-events' ), array( 'status' => 400 ) );
        }

        // Validate per-attendee extra fields against the event's saved config.
        $clean_extras = null;
        if ( class_exists( 'KE_Event_Extra_Fields' ) && KE_Event_Extra_Fields::is_active( $event_id ) ) {
            $submitted = is_array( $extra_fields ) ? $extra_fields : array();
            $clean     = KE_Event_Extra_Fields::validate_attendee( $event_id, $submitted );
            if ( is_wp_error( $clean ) ) {
                return $clean;
            }
            $clean_extras = $clean;
        }

        // Real attendee: contribute the ticket's base price to the synthetic
        // order total so dashboards see it as a paid sale.
        // Courtesy: order total is $0 — admin gift, no revenue.
        $price        = (float) $type->price;
        $order_total  = $is_courtesy ? 0.0 : $price;

        $orders_handler = new KE_Orders();
        $order_result   = $orders_handler->create( array(
            'event_id'        => $event_id,
            'user_id'         => get_current_user_id(),
            'buyer_name'      => $name,
            'buyer_email'     => $email,
            'total_amount'    => $order_total,
            'ticket_quantity' => 1,
            'payment_method'  => 'admin',
            'payment_status'  => 'completed',
        ) );

        if ( is_wp_error( $order_result ) ) {
            return new WP_Error( 'order_failed', __( 'Could not create order.', 'kiwi-events' ), array( 'status' => 500 ) );
        }

        $attendees = array( array(
            'name'         => $name,
            'email'        => $email,
            'is_courtesy'  => $is_courtesy,
            'extra_fields' => $clean_extras,
        ) );

        $tickets_handler = new KE_Tickets();
        $ticket_ids      = $tickets_handler->generate(
            $order_result['order_id'],
            $event_id,
            $ticket_type_id,
            $attendees
        );

        if ( is_wp_error( $ticket_ids ) || empty( $ticket_ids ) ) {
            return new WP_Error( 'ticket_failed', __( 'Could not generate the ticket.', 'kiwi-events' ), array( 'status' => 500 ) );
        }

        // Send the ticket email (best-effort — failure does not roll back the
        // ticket, since the admin can resend from the attendees list).
        $email_handler = new KE_Email();
        $email_sent    = $email_handler->send_ticket_email( $order_result['order_id'] );

        return rest_ensure_response( array(
            'success'      => true,
            'ticket_ids'   => array_map( 'intval', (array) $ticket_ids ),
            'order_id'     => (int) $order_result['order_id'],
            'order_number' => $order_result['order_number'],
            'is_courtesy'  => (bool) $is_courtesy,
            'email_sent'   => ! is_wp_error( $email_sent ),
        ) );
    }

    /**
     * POST /tickets/validate/{code}
     *
     * Public route gated by scanner_or_token_permission. When the caller
     * presents a session token, the token's event scope is enforced and the
     * response is stripped of fields that aren't needed at the door.
     */
    public function validate_ticket( WP_REST_Request $request ) {
        $tickets = new KE_Tickets();
        $session = $request->get_param( '_ke_scanner_session' );

        $result = $tickets->validate_and_checkin(
            $request['code'],
            get_current_user_id()
        );

        // Token callers may only validate tickets for the event the token
        // was issued against. Anything else is a 403 — never 'valid' for
        // the wrong event.
        if ( is_array( $session ) && isset( $result['ticket'] ) ) {
            $ticket_event_id = is_object( $result['ticket'] )
                ? (int) ( $result['ticket']->event_id ?? 0 )
                : (int) ( $result['ticket']['event_id'] ?? 0 );
            if ( $ticket_event_id > 0 && $ticket_event_id !== (int) $session['event_id'] ) {
                return new WP_Error(
                    'wrong_event',
                    __( 'This ticket belongs to a different event.', 'kiwi-events' ),
                    array( 'status' => 403 )
                );
            }
        }

        // Strip PII for token-authed callers — scanners at the door don't
        // need email addresses or order totals. Note: $result['ticket'] is
        // a DB row stdClass, so cast to array first.
        if ( is_array( $session ) && isset( $result['ticket'] ) ) {
            $row     = is_object( $result['ticket'] ) ? get_object_vars( $result['ticket'] ) : (array) $result['ticket'];
            $allowed = array( 'attendee_name', 'event_id', 'event_name', 'ticket_type_name', 'status', 'checked_in_at', 'ticket_code' );
            $row     = array_intersect_key( $row, array_flip( $allowed ) );
            // Normalize keys for the public client.
            if ( isset( $row['ticket_type_name'] ) ) { $row['ticket_type'] = $row['ticket_type_name']; unset( $row['ticket_type_name'] ); }
            if ( isset( $row['ticket_code'] ) )      { $row['code']        = $row['ticket_code'];      unset( $row['ticket_code'] ); }
            $result['ticket'] = $row;
        }

        $status_code = match( $result['status'] ) {
            'valid'        => 200,
            'already_used' => 200,
            'invalid'      => 404,
            default        => 400,
        };

        return new WP_REST_Response( $result, $status_code );
    }

    /**
     * POST /scanner/auth
     *
     * Public. Exchanges an organizer password for a 4-hour scanner session
     * token bound to a single event. Rate-limited per IP+event.
     *
     * Body: { event_id: int, password: string }
     *
     * 200 { success: true, token, event_name, organizer_name,
     *        total_tickets, checked_in, expires_at }
     * 401 { code: 'invalid_password',   attempts_remaining }
     * 429 { code: 'rate_limited',       retry_after }
     */
    public function scanner_auth( WP_REST_Request $request ) {
        $event_id = absint( $request->get_param( 'event_id' ) );
        if ( $event_id <= 0 || get_post_type( $event_id ) !== 'ke_event' ) {
            return new WP_Error( 'invalid_event', __( 'Invalid event.', 'kiwi-events' ), array( 'status' => 400 ) );
        }

        $organizer_id = KE_Scanner_Password::get_organizer_id_for_event( $event_id );
        if ( $organizer_id <= 0 || ! KE_Scanner_Password::organizer_has_password( $organizer_id ) ) {
            // Per Request B's security model, every event MUST have an
            // organizer password before the scanner can be used.
            return new WP_Error(
                'no_password_set',
                __( 'This event has no organizer password configured. Set one before scanning.', 'kiwi-events' ),
                array( 'status' => 403 )
            );
        }

        $ip = KE_Scanner_Password::get_request_ip();
        if ( KE_Scanner_Password::attempts_remaining( $ip, $event_id ) <= 0 ) {
            return new WP_Error(
                'rate_limited',
                __( 'Too many failed attempts. Please wait a moment and try again.', 'kiwi-events' ),
                array(
                    'status'      => 429,
                    'retry_after' => KE_Scanner_Password::ATTEMPT_WINDOW,
                )
            );
        }

        $password = (string) $request->get_param( 'password' );
        if ( $password === '' || ! KE_Scanner_Password::verify_organizer_password( $organizer_id, $password ) ) {
            KE_Scanner_Password::record_failed_attempt( $ip, $event_id );
            return new WP_Error(
                'invalid_password',
                __( 'Incorrect scanner password.', 'kiwi-events' ),
                array(
                    'status'             => 401,
                    'attempts_remaining' => KE_Scanner_Password::attempts_remaining( $ip, $event_id ),
                )
            );
        }
        KE_Scanner_Password::clear_attempts( $ip, $event_id );

        $session = KE_Scanner_Password::issue_session_token( $event_id );

        global $wpdb;
        $tt = $wpdb->prefix . 'ke_tickets';
        $checked_in = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tt} WHERE event_id = %d AND status = 'used'", $event_id ) );
        $total      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tt} WHERE event_id = %d AND status != 'cancelled'", $event_id ) );

        $event_name = get_the_title( $event_id );
        $organizer_name = '';
        $term = get_term( $organizer_id, 'ke_organizer' );
        if ( $term && ! is_wp_error( $term ) ) $organizer_name = $term->name;

        return rest_ensure_response( array(
            'success'        => true,
            'token'          => $session['token'],
            'expires_at'     => $session['expires_at'],
            'event_id'       => $event_id,
            'event_name'     => $event_name,
            'organizer_name' => $organizer_name,
            'total_tickets'  => $total,
            'checked_in'     => $checked_in,
        ) );
    }

    /**
     * GET /scanner/active-events
     *
     * Public, no-auth list of events currently inside the scanning window
     * (24h before start through 12h after end). Returns minimal metadata —
     * never includes ticket codes, attendee names, or order totals.
     */
    public function get_public_active_events( WP_REST_Request $request ) {
        $now            = current_time( 'timestamp' );
        $window_before  = 24 * HOUR_IN_SECONDS;
        $window_after   = 12 * HOUR_IN_SECONDS;

        $posts = get_posts( array(
            'post_type'      => 'ke_event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'meta_value',
            'meta_key'       => '_ke_event_date_start',
            'order'          => 'ASC',
        ) );

        $events = array();
        $ticket_types_helper = class_exists( 'KE_Ticket_Types' ) ? new KE_Ticket_Types() : null;
        foreach ( $posts as $p ) {
            $start = (int) strtotime( (string) get_post_meta( $p->ID, '_ke_event_date_start', true ) );
            $end   = (int) strtotime( (string) get_post_meta( $p->ID, '_ke_event_date_end',   true ) );
            if ( $end <= 0 ) $end = $start + ( 4 * HOUR_IN_SECONDS ); // sensible default
            if ( $start <= 0 ) continue;
            if ( $now < ( $start - $window_before ) ) continue; // too far in the future
            if ( $now > ( $end   + $window_after  ) ) continue; // ended too long ago

            // Hide reservation-only events — the QR scanner has no codes to
            // validate against them. Reservation check-in is a manual action
            // on the organizer dashboard / wp-admin Reservations page.
            if ( $ticket_types_helper ) {
                $event_ticket_types = $ticket_types_helper->get_by_event( (int) $p->ID );
                if ( empty( $event_ticket_types ) ) continue;
            }

            $organizer_id   = KE_Scanner_Password::get_organizer_id_for_event( $p->ID );
            $organizer_name = '';
            if ( $organizer_id ) {
                $term = get_term( $organizer_id, 'ke_organizer' );
                if ( $term && ! is_wp_error( $term ) ) $organizer_name = $term->name;
            }

            $events[] = array(
                'id'             => (int) $p->ID,
                'name'           => $p->post_title,
                'date_start'     => mysql2date( 'c', date( 'Y-m-d H:i:s', $start ) ),
                'date_label'     => date_i18n( 'M j · g:i A', $start ),
                'organizer_name' => $organizer_name,
                'has_password'   => $organizer_id > 0 && KE_Scanner_Password::organizer_has_password( $organizer_id ),
            );
        }

        return rest_ensure_response( array( 'events' => $events ) );
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
     * POST /events — create event
     * PUT  /events/{id} — update event
     * POST /events/save — legacy alias (kept for backward compat)
     */
    public function save_event( WP_REST_Request $request ) {
        global $wpdb;
        $params = $request->get_json_params();

        // Support both new field names (wizard) and old names (legacy)
        $title = sanitize_text_field(
            $params['title'] ?? $params['event_title'] ?? ''
        );
        if ( ! $title ) {
            return new WP_Error( 'missing_title', 'Event title is required.', array( 'status' => 400 ) );
        }

        // Event ID: from URL param (PUT /events/{id}) or body
        $url_id   = absint( $request->get_param( 'id' ) );
        $event_id = $url_id ?: absint( $params['event_id'] ?? 0 );

        // Post status: only change it when the request EXPLICITLY includes a
        // status (the Publish / Save-Draft buttons send 'publish'|'draft' as
        // $params['status']). A status-less save (autosave) must preserve the
        // event's current status, so editing a published event can never
        // silently revert it to draft. New events with no status default to
        // draft, matching the prior create-as-draft autosave behavior.
        if ( isset( $params['status'] ) || isset( $params['post_status'] ) ) {
            $raw_status  = $params['status'] ?? $params['post_status'];
            $post_status = $raw_status === 'publish' ? 'publish' : 'draft';
        } else {
            $post_status = ( $event_id > 0 )
                ? ( get_post_status( $event_id ) ?: 'draft' )
                : 'draft';
        }

        $post_data = array(
            'post_title'   => $title,
            'post_content' => wp_kses_post( $params['content'] ?? $params['event_description'] ?? '' ),
            'post_status'  => $post_status,
            'post_type'    => 'ke_event',
        );

        // Slug resolution (Phase 4 enforcement).
        //
        // Policy:
        //   • flag=true  → only honor an explicit slug in the payload. Never
        //                  re-derive from title. Protects established URLs.
        //   • flag=false → re-derive from title on every save, regardless of
        //                  what the payload contains. Keeps the slug in lock-
        //                  step with the title for auto-tracking events.
        //
        // The wizard's JS already mirrors title→slug in real time so the two
        // usually match at save time, but server re-derivation is the
        // authoritative fallback for non-wizard clients (REST scripts, future
        // bulk-edit tools, plugin integrations).
        //
        // Uniqueness: pre-resolve via wp_unique_post_slug() so we know the
        // exact slug WP will land on (collision suffixes -2, -3, …) and the
        // client's check-slug result matches reality. wp_update_post would
        // suffix silently otherwise. wp_old_slug_redirect fires automatically
        // when post_name actually changes.
        $resolved_lock = null; // null = unknown (new event without explicit flag)
        if ( $event_id > 0 ) {
            $resolved_lock = get_post_meta( $event_id, '_ke_slug_manually_set', true ) === '1';
        }
        if ( array_key_exists( 'slug_manually_set', $params ) ) {
            // Caller explicitly sent the flag — that overrides the stored
            // value for this save's slug-resolution decision (the meta
            // itself is updated further below).
            $resolved_lock = ! empty( $params['slug_manually_set'] );
        }

        $submitted_slug = isset( $params['slug'] ) ? sanitize_title( (string) $params['slug'] ) : '';

        if ( $resolved_lock === true ) {
            // Locked: only set post_name when an explicit slug was supplied.
            // An empty submitted_slug leaves the existing post_name alone.
            if ( $submitted_slug !== '' ) {
                $current_name = ( $event_id > 0 ) ? (string) get_post_field( 'post_name', $event_id ) : '';
                $unique = wp_unique_post_slug(
                    $submitted_slug,
                    (int) $event_id,
                    $post_status,
                    'ke_event',
                    0
                );
                if ( $unique !== $current_name ) {
                    $post_data['post_name'] = $unique;
                }
            }
        } else {
            // Auto-tracking (flag=false or new event without explicit flag):
            // derive from title every save so renaming an unlocked event
            // updates its URL. Honor an explicit submitted slug only if it
            // matches the sanitized title — otherwise the wizard JS got out
            // of sync and the title is the source of truth.
            $derived = sanitize_title( $title );
            if ( $derived !== '' ) {
                $unique = wp_unique_post_slug(
                    $derived,
                    (int) $event_id,
                    $post_status,
                    'ke_event',
                    0
                );
                $post_data['post_name'] = $unique;
            }
        }

        if ( $event_id > 0 ) {
            $post_data['ID'] = $event_id;
            $event_id = wp_update_post( $post_data, true );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( '[KE save_event] UPDATE event_id=%d title=%s', (int) $event_id, $title ) );
            }
        } else {
            // Idempotency guard: if a client retries a "create" because the
            // previous response was lost/corrupted (network blip, a 500 from
            // a later step in this function, etc.), wp_insert_post would
            // happily create a second post. Before inserting, look for an
            // existing draft by the same author with the same title created
            // within the last 60 seconds and return that one instead.
            //
            // This is the underlying defense for the bug that produced 11
            // copies of "Pruebas promotores" in production: a fatal in the
            // promoter-assignment block (SQL error on the dropped p.name/
            // p.email columns) corrupted the JSON response, the JS treated
            // it as an error, and the user's next keystroke triggered an
            // auto-save that POSTed again with event_id=0 — looping.
            $author_id = get_current_user_id();
            $existing  = $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                  WHERE post_type   = 'ke_event'
                    AND post_author = %d
                    AND post_title  = %s
                    AND post_status IN ('draft','auto-draft','publish')
                    AND post_date_gmt >= UTC_TIMESTAMP() - INTERVAL 60 SECOND
                  ORDER BY ID DESC
                  LIMIT 1",
                $author_id,
                $title
            ) );
            if ( $existing ) {
                $event_id = (int) $existing;
                $post_data['ID'] = $event_id;
                wp_update_post( $post_data, true );
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf( '[KE save_event] DEDUPE matched recent draft event_id=%d title=%s', $event_id, $title ) );
                }
            } else {
                $event_id = wp_insert_post( $post_data, true );
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf( '[KE save_event] INSERT new event_id=%s title=%s', is_wp_error( $event_id ) ? 'ERR' : (string) (int) $event_id, $title ) );
                }
            }
        }

        if ( is_wp_error( $event_id ) ) {
            return new WP_Error( 'save_failed', 'Failed to save event.', array( 'status' => 500 ) );
        }

        // Slug lock flag (Phase 2). Persist `_ke_slug_manually_set` whenever
        // the wizard explicitly sends it. The wizard always sends it after
        // Phase 2 deploys, so missing means a legacy/external caller — leave
        // the meta untouched in that case so the Phase 3 migration value
        // isn't accidentally cleared. Stored as '1' / '0' string for consistent
        // get_post_meta() truthiness checks.
        if ( array_key_exists( 'slug_manually_set', $params ) ) {
            update_post_meta(
                $event_id,
                '_ke_slug_manually_set',
                ! empty( $params['slug_manually_set'] ) ? '1' : '0'
            );
        }

        // Banner image — set if provided, clear if the field is present but empty
        $banner_key = array_key_exists( 'banner_id', $params )
            ? 'banner_id'
            : ( array_key_exists( 'event_banner_id', $params ) ? 'event_banner_id' : null );
        if ( $banner_key !== null ) {
            $banner_id = absint( $params[ $banner_key ] );
            if ( $banner_id > 0 ) {
                set_post_thumbnail( $event_id, $banner_id );
            } else {
                delete_post_thumbnail( $event_id );
            }
        }

        // Per-event hero background image (separate from the poster/featured
        // image). Stored as an attachment id; 0/empty clears it so the hero
        // falls back to the default gradient + blurred-poster ambient.
        if ( array_key_exists( 'hero_bg_id', $params ) ) {
            $hero_bg_id = absint( $params['hero_bg_id'] );
            if ( $hero_bg_id > 0 ) {
                update_post_meta( $event_id, '_ke_event_hero_bg_id', $hero_bg_id );
            } else {
                delete_post_meta( $event_id, '_ke_event_hero_bg_id' );
            }
        }

        // Historias Destacadas — whether to show the row and which highlights.
        // Stored as '_ke_event_show_highlights' (1/0) and '_ke_event_highlights'
        // ('all' or an array of highlight IDs). The public renderer intersects
        // the stored selection with the event's CURRENT organizer at render
        // time, so a later organizer change / deleted highlight just drops out.
        if ( array_key_exists( 'show_highlights', $params ) ) {
            update_post_meta( $event_id, '_ke_event_show_highlights', filter_var( $params['show_highlights'], FILTER_VALIDATE_BOOLEAN ) ? '1' : '0' );
        }
        if ( array_key_exists( 'highlights_all', $params ) || array_key_exists( 'highlights', $params ) ) {
            if ( ! empty( $params['highlights_all'] ) && filter_var( $params['highlights_all'], FILTER_VALIDATE_BOOLEAN ) ) {
                update_post_meta( $event_id, '_ke_event_highlights', 'all' );
            } else {
                $hl_ids = ( isset( $params['highlights'] ) && is_array( $params['highlights'] ) )
                    ? array_values( array_unique( array_filter( array_map( 'absint', $params['highlights'] ) ) ) )
                    : array();
                if ( ! empty( $hl_ids ) ) {
                    update_post_meta( $event_id, '_ke_event_highlights', $hl_ids );
                } else {
                    delete_post_meta( $event_id, '_ke_event_highlights' );
                }
            }
        }

        // Cumpleaños — per-event birthday package CTA. Content is per-event by
        // design (no global default / no reuse). Description keeps line breaks
        // via sanitize_textarea_field (no HTML); link is URL-validated. The
        // fields persist across toggling so content isn't lost; the public
        // renderer only shows the CTA when enabled AND all three are non-empty.
        if ( array_key_exists( 'birthday_enabled', $params ) ) {
            update_post_meta( $event_id, '_ke_birthday_enabled', filter_var( $params['birthday_enabled'], FILTER_VALIDATE_BOOLEAN ) ? '1' : '0' );
        }
        if ( array_key_exists( 'birthday_title', $params ) ) {
            update_post_meta( $event_id, '_ke_birthday_title', sanitize_text_field( $params['birthday_title'] ) );
        }
        if ( array_key_exists( 'birthday_description', $params ) ) {
            update_post_meta( $event_id, '_ke_birthday_description', sanitize_textarea_field( $params['birthday_description'] ) );
        }
        if ( array_key_exists( 'birthday_link', $params ) ) {
            update_post_meta( $event_id, '_ke_birthday_link', esc_url_raw( $params['birthday_link'] ) );
        }

        // ── Simple meta fields (sanitize_text_field) ──────────────────────
        $text_meta = array(
            'event_start'    => '_ke_event_date_start',
            'event_end'      => '_ke_event_date_end',
            'timezone'       => '_ke_event_timezone',
            'location_type'  => '_ke_event_location_type',
            'venue'          => '_ke_event_venue',
            'address'        => '_ke_event_address',
            'social_instagram' => '_ke_social_instagram',
            'social_whatsapp'  => '_ke_social_whatsapp',
            'social_facebook'  => '_ke_social_facebook',
            'email_from_name'  => '_ke_email_from_name',
            'promo_label'      => '_ke_event_promo_label',
        );
        foreach ( $text_meta as $key => $meta_key ) {
            if ( array_key_exists( $key, $params ) ) {
                update_post_meta( $event_id, $meta_key, sanitize_text_field( $params[ $key ] ) );
            }
        }

        // Boolean meta — store as 1/0 int
        if ( array_key_exists( 'is_featured', $params ) ) {
            $flag = filter_var( $params['is_featured'], FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
            update_post_meta( $event_id, '_ke_event_is_featured', $flag );
        }
        if ( array_key_exists( 'show_in_main_shortcode', $params ) ) {
            $flag = filter_var( $params['show_in_main_shortcode'], FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
            update_post_meta( $event_id, '_ke_event_show_in_main_shortcode', $flag );
        }

        // URL meta
        $url_meta = array(
            'virtual_url'    => '_ke_event_virtual_url',
            'social_website' => '_ke_social_website',
        );
        foreach ( $url_meta as $key => $meta_key ) {
            if ( array_key_exists( $key, $params ) ) {
                update_post_meta( $event_id, $meta_key, esc_url_raw( $params[ $key ] ) );
            }
        }

        // Numeric meta
        if ( array_key_exists( 'max_tickets_per_person', $params ) ) {
            update_post_meta( $event_id, '_ke_event_max_tickets_per_person', absint( $params['max_tickets_per_person'] ) );
        }

        // Textarea meta
        if ( array_key_exists( 'email_custom_message', $params ) ) {
            update_post_meta( $event_id, '_ke_email_custom_message', sanitize_textarea_field( $params['email_custom_message'] ) );
        }

        // Event status
        $allowed_statuses = array( 'active', 'draft', 'cancelled', 'postponed', 'sold_out' );
        $event_status = sanitize_key( $params['event_status'] ?? 'active' );
        if ( ! in_array( $event_status, $allowed_statuses, true ) ) {
            $event_status = 'active';
        }
        update_post_meta( $event_id, '_ke_event_status', $event_status );

        // Service fee
        if ( array_key_exists( 'service_fee_id', $params ) ) {
            update_post_meta( $event_id, '_ke_event_service_fee_id', sanitize_key( $params['service_fee_id'] ) );
        }

        // Google Maps embed (allow iframe from trusted admins)
        $maps_key = array_key_exists( 'maps_embed', $params ) ? 'maps_embed' : ( array_key_exists( 'event_maps_embed', $params ) ? 'event_maps_embed' : null );
        if ( $maps_key !== null ) {
            $maps_input = trim( $params[ $maps_key ] );
            $maps_final = '';
            if ( ! empty( $maps_input ) ) {
                $allowed_iframe = array(
                    'iframe' => array(
                        'src' => true, 'width' => true, 'height' => true,
                        'frameborder' => true, 'style' => true,
                        'allowfullscreen' => true, 'loading' => true,
                        'referrerpolicy' => true, 'title' => true,
                    ),
                );
                if ( stripos( $maps_input, '<iframe' ) !== false ) {
                    $maps_final = wp_kses( $maps_input, $allowed_iframe );
                } elseif ( preg_match( '/\[googlemaps\s+(https?:\/\/[^\]]+)\]/i', $maps_input, $m ) ) {
                    $maps_final = '<iframe src="' . esc_url( trim( $m[1] ) ) . '" width="100%" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';
                } elseif ( strpos( $maps_input, 'google.com/maps' ) !== false ) {
                    $maps_final = '<iframe src="' . esc_url( $maps_input ) . '" width="100%" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';
                }
            }
            update_post_meta( $event_id, '_ke_event_maps_embed', $maps_final );
        }

        // Taxonomies
        $cats_key = array_key_exists( 'categories', $params ) ? 'categories' : 'event_categories';
        if ( isset( $params[ $cats_key ] ) && is_array( $params[ $cats_key ] ) ) {
            wp_set_object_terms( $event_id, array_map( 'absint', $params[ $cats_key ] ), 'ke_event_category' );
        }
        $org_key = array_key_exists( 'organizer', $params ) ? 'organizer' : 'event_organizer';
        if ( ! empty( $params[ $org_key ] ) ) {
            wp_set_object_terms( $event_id, absint( $params[ $org_key ] ), 'ke_organizer' );
        }

        // Event extras (optional enhancement widgets)
        if ( array_key_exists( 'extras', $params ) && is_array( $params['extras'] ) ) {
            $allowed_types = array(
                'sold_out_bar', 'countdown', 'lineup', 'gallery',
                'testimonials', 'schedule', 'menu_faq', 'faq',
                'additional_info',
            );
            $clean = array();
            foreach ( $params['extras'] as $extra ) {
                if ( ! is_array( $extra ) ) continue;
                $type = sanitize_key( $extra['type'] ?? '' );
                if ( ! in_array( $type, $allowed_types, true ) ) continue;
                $config = isset( $extra['config'] ) && is_array( $extra['config'] ) ? $extra['config'] : array();
                $clean[] = array(
                    'type'    => $type,
                    'enabled' => ! empty( $extra['enabled'] ),
                    'config'  => $this->sanitize_extra_config( $type, $config ),
                );
            }
            update_post_meta( $event_id, '_ke_event_extras', $clean );
        }

        // Per-attendee Extra Fields (university name, dietary, shirt size, …).
        // Stored under `_ke_event_extra_fields`; sanitisation lives on the
        // dedicated helper class so checkout/admin/PDF all share one shape.
        if ( array_key_exists( 'extra_fields', $params ) && is_array( $params['extra_fields'] ) && class_exists( 'KE_Event_Extra_Fields' ) ) {
            $clean_xf = KE_Event_Extra_Fields::sanitize_config( $params['extra_fields'] );
            update_post_meta( $event_id, KE_Event_Extra_Fields::META_KEY, $clean_xf );
        }

        // Reservations config — group/capacity bookings (Phase 1: builder
        // can configure; Phase 2 wires up the public booking flow).
        if ( array_key_exists( 'reservations', $params ) && is_array( $params['reservations'] ) && class_exists( 'KE_Reservations' ) ) {
            $clean_resv = KE_Reservations::sanitize_config( $params['reservations'] );
            update_post_meta( $event_id, KE_Reservations::META_KEY, $clean_resv );
        }

        // Promoter assignments — full-set replace via the dedicated helper.
        // Validates promoter_id, commission_type, and commission_value internally.
        if ( array_key_exists( 'promoter_assignments', $params ) && is_array( $params['promoter_assignments'] ) && class_exists( 'KE_Event_Promoters' ) ) {
            $prior = KE_Event_Promoters::list_for_event( $event_id );
            $prior_ids = array();
            foreach ( $prior as $p ) { $prior_ids[ (int) $p->promoter_id ] = true; }

            KE_Event_Promoters::set_for_event( $event_id, $params['promoter_assignments'] );

            // Trigger CHANGE 3 notifications for *newly* assigned promoters only.
            if ( class_exists( 'KE_Promoter_Notifications' ) ) {
                $new_ids = array();
                foreach ( $params['promoter_assignments'] as $a ) {
                    $pid = isset( $a['promoter_id'] ) ? (int) $a['promoter_id'] : 0;
                    if ( $pid && empty( $prior_ids[ $pid ] ) ) $new_ids[] = $pid;
                }
                if ( $new_ids ) {
                    KE_Promoter_Notifications::queue_assignment_emails( $event_id, $new_ids );
                }
            }
        }

        // Per-event promoter terms (rich text). Filtered via wp_kses_post.
        if ( array_key_exists( 'promoter_terms', $params ) ) {
            update_post_meta( $event_id, '_ke_promoter_terms', wp_kses_post( (string) $params['promoter_terms'] ) );
        }

        // Ticket types — sync by ID: update existing, insert new, and
        // soft-archive (or hard-delete) removed types. NEVER destructively
        // wipe the table: that resets quantity_sold and orphans real tickets.
        if ( isset( $params['tickets'] ) && is_array( $params['tickets'] ) ) {
            global $wpdb;
            $tt_table       = $wpdb->prefix . 'ke_ticket_types';
            $ticket_handler = new KE_Ticket_Types();

            // Only diff against LIVE rows. Already-archived rows are invisible to the
            // admin edit form, so leave them alone on subsequent saves.
            $existing = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, quantity_sold FROM {$tt_table}
                 WHERE event_id = %d
                   AND (is_archived IS NULL OR is_archived = 0)",
                $event_id
            ) );
            $existing_by_id = array();
            foreach ( $existing as $row ) {
                $existing_by_id[ (int) $row->id ] = (int) $row->quantity_sold;
            }

            $seen_ids = array();

            foreach ( $params['tickets'] as $t ) {
                if ( empty( $t['name'] ) ) continue;

                $cap_type = ( $t['capacity_type'] ?? 'limited' ) === 'unlimited' ? 'unlimited' : 'limited';
                if ( $cap_type === 'limited' && absint( $t['qty'] ?? 0 ) < 1 ) {
                    $cap_type = 'unlimited';
                }

                $fields = array(
                    'name'           => sanitize_text_field( $t['name'] ),
                    'description'    => sanitize_text_field( $t['desc'] ?? '' ),
                    'ticket_type'    => in_array( $t['ticket_type'] ?? 'free', array( 'free', 'paid' ), true ) ? $t['ticket_type'] : 'free',
                    'price'          => max( 0, floatval( $t['price'] ?? 0 ) ),
                    'capacity_type'  => $cap_type,
                    'quantity_total' => absint( $t['qty'] ?? 0 ),
                    'min_per_order'  => max( 1, absint( $t['min_per_order'] ?? 1 ) ),
                    'max_per_order'  => max( 1, absint( $t['max_per_order'] ?? 10 ) ),
                    'show_remaining' => ( $t['show_remaining'] ?? 'yes' ) === 'no' ? 'no' : 'yes',
                    'status'         => 'active',
                );

                // Per-ticket-type sales cutoff. Pass through unconditionally when
                // the key is present so an explicit null (admin cleared the input)
                // reaches the CRUD layer and writes SQL NULL. KE_Ticket_Types
                // sanitizes the value, so we hand off the raw string here.
                if ( array_key_exists( 'sale_end', $t ) ) {
                    $fields['sale_end'] = $t['sale_end'];
                }

                $tid = isset( $t['id'] ) ? absint( $t['id'] ) : 0;

                if ( $tid && isset( $existing_by_id[ $tid ] ) ) {
                    // Update in place — preserve quantity_sold and the row's ID.
                    // Also clear is_archived in case the row was previously soft-archived.
                    $ticket_handler->update( $tid, $fields );
                    $wpdb->update(
                        $tt_table,
                        array( 'is_archived' => 0 ),
                        array( 'id' => $tid ),
                        array( '%d' ),
                        array( '%d' )
                    );
                    $seen_ids[] = $tid;
                } else {
                    $new_id = $ticket_handler->create( array_merge( $fields, array( 'event_id' => $event_id ) ) );
                    if ( ! is_wp_error( $new_id ) && $new_id ) {
                        $seen_ids[] = (int) $new_id;
                    }
                }
            }

            // Anything that existed before but wasn't sent back was removed by the admin.
            // Hard-delete only if it has zero sold; otherwise soft-archive to preserve history.
            foreach ( $existing_by_id as $old_id => $sold ) {
                if ( in_array( $old_id, $seen_ids, true ) ) continue;
                if ( $sold > 0 ) {
                    $wpdb->update(
                        $tt_table,
                        array( 'is_archived' => 1, 'status' => 'archived' ),
                        array( 'id' => $old_id ),
                        array( '%d', '%s' ),
                        array( '%d' )
                    );
                } else {
                    $ticket_handler->delete( $old_id );
                }
            }
        }

        return rest_ensure_response( array(
            'success'   => true,
            'id'        => $event_id,
            'event_id'  => $event_id,
            'permalink' => get_permalink( $event_id ),
        ) );
    }

    /**
     * DELETE /events/{id}
     */
    public function delete_event( WP_REST_Request $request ) {
        $event_id = absint( $request->get_param( 'id' ) );
        $post     = $event_id ? get_post( $event_id ) : null;
        if ( ! $post || $post->post_type !== 'ke_event' ) {
            return new WP_Error( 'not_found', 'Event not found.', array( 'status' => 404 ) );
        }
        if ( ! wp_trash_post( $event_id ) ) {
            return new WP_Error( 'delete_failed', 'Could not delete event.', array( 'status' => 500 ) );
        }
        return rest_ensure_response( array( 'success' => true, 'id' => $event_id ) );
    }

    /**
     * GET /events/{id}/checkin-stats
     */
    public function get_checkin_stats( WP_REST_Request $request ) {
        global $wpdb;
        $event_id   = absint( $request->get_param( 'id' ) );
        $table      = $wpdb->prefix . 'ke_tickets';
        $checked_in = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND status = 'used'", $event_id
        ) );
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND status != 'cancelled'", $event_id
        ) );
        return rest_ensure_response( array( 'checked_in' => $checked_in, 'total' => $total ) );
    }

    /**
     * Flip a ticket type's status between 'active' and 'inactive'.
     *
     * Inactive ticket types are hidden from the public picker but keep all
     * historical sales/courtesy attribution intact (we never delete the row,
     * because tickets in wp_ke_tickets reference its id).
     */
    public function toggle_ticket_type_active( WP_REST_Request $request ) {
        $event_id = absint( $request->get_param( 'id' ) );
        $type_id  = absint( $request->get_param( 'type_id' ) );

        if ( ! $event_id || ! $type_id ) {
            return new WP_Error( 'bad_request', 'Missing event or ticket type id.', array( 'status' => 400 ) );
        }

        $ticket_types = new KE_Ticket_Types();
        $row          = $ticket_types->get( $type_id );
        if ( ! $row || (int) $row->event_id !== $event_id ) {
            return new WP_Error( 'not_found', 'Ticket type not found for this event.', array( 'status' => 404 ) );
        }

        $next   = ( ( $row->status ?? 'active' ) === 'active' ) ? 'inactive' : 'active';
        $result = $ticket_types->update( $type_id, array( 'status' => $next ) );
        if ( $result === false || is_wp_error( $result ) ) {
            return new WP_Error( 'db_error', 'Could not update ticket type status.', array( 'status' => 500 ) );
        }

        return rest_ensure_response( array(
            'success' => true,
            'id'      => $type_id,
            'status'  => $next,
        ) );
    }

    /**
     * ── Organizer Template helpers ─────────────────────────────────────────
     */
    private function get_tpl_list( $term_id ) {
        $raw = get_term_meta( $term_id, '_ke_ticket_templates', true );
        return is_array( $raw ) ? $raw : array();
    }

    private function set_tpl_list( $term_id, $list ) {
        update_term_meta( $term_id, '_ke_ticket_templates', array_values( $list ) );
    }

    private function sanitize_tpl_tickets( $raw ) {
        if ( ! is_array( $raw ) ) return array();
        $out = array();
        foreach ( $raw as $t ) {
            if ( empty( $t['name'] ) ) continue;
            $cap = ( $t['capacity_type'] ?? 'limited' ) === 'unlimited' ? 'unlimited' : 'limited';
            $out[] = array(
                'name'          => sanitize_text_field( $t['name'] ),
                'description'   => sanitize_text_field( $t['description'] ?? $t['desc'] ?? '' ),
                'ticket_type'   => in_array( $t['ticket_type'] ?? 'free', array( 'free', 'paid' ), true ) ? $t['ticket_type'] : 'free',
                'price'         => max( 0, floatval( $t['price'] ?? 0 ) ),
                'capacity_type' => $cap,
                'quantity'      => absint( $t['quantity'] ?? $t['qty'] ?? 0 ),
                'min_per_order' => max( 1, absint( $t['min_per_order'] ?? 1 ) ),
                'max_per_order' => max( 1, absint( $t['max_per_order'] ?? 10 ) ),
            );
        }
        return $out;
    }

    /**
     * GET /organizers/{id}/password-meta
     *
     * Returns metadata about the organizer's stored password without ever
     * shipping the password (or its hash) to the browser. The admin UI uses
     * `length` to draw the right number of bullet-dot placeholders.
     */
    public function get_organizer_password_meta( WP_REST_Request $request ) {
        $term_id = absint( $request->get_param( 'id' ) );
        $term    = get_term( $term_id, 'ke_organizer' );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'not_found', 'Organizer not found.', array( 'status' => 404 ) );
        }
        $has = KE_Scanner_Password::organizer_has_password( $term_id );
        return rest_ensure_response( array(
            'has_password' => $has,
            'length'       => $has ? KE_Scanner_Password::organizer_password_length( $term_id ) : 0,
        ) );
    }

    /**
     * POST /organizers/{id}/update-password
     * Body: { password: string }
     *
     * Hashes via wp_hash_password and invalidates every active scanner +
     * dashboard session for this organizer (handled inside
     * KE_Scanner_Password::set_organizer_password).
     */
    public function update_organizer_password( WP_REST_Request $request ) {
        $term_id = absint( $request->get_param( 'id' ) );
        $term    = get_term( $term_id, 'ke_organizer' );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'not_found', 'Organizer not found.', array( 'status' => 404 ) );
        }

        $params = $request->get_json_params();
        if ( ! is_array( $params ) ) $params = array();
        $plain = isset( $params['password'] ) ? (string) $params['password'] : '';
        // Don't trim: a leading/trailing space might be intentional. But reject
        // an entirely empty/whitespace value — that path is "clear", which the
        // UI doesn't expose.
        if ( $plain === '' || trim( $plain ) === '' ) {
            return new WP_Error( 'empty_password', 'Password cannot be empty.', array( 'status' => 400 ) );
        }
        if ( mb_strlen( $plain ) < 4 ) {
            return new WP_Error( 'short_password', 'Password must be at least 4 characters.', array( 'status' => 400 ) );
        }
        if ( mb_strlen( $plain ) > 128 ) {
            return new WP_Error( 'long_password', 'Password must be 128 characters or fewer.', array( 'status' => 400 ) );
        }

        KE_Scanner_Password::set_organizer_password( $term_id, $plain );

        return rest_ensure_response( array(
            'success' => true,
            'length'  => KE_Scanner_Password::organizer_password_length( $term_id ),
            'message' => 'Password updated. All active scanner sessions for this organizer have been invalidated.',
        ) );
    }

    /**
     * GET /organizers/{id}/templates
     */
    public function get_organizer_templates( WP_REST_Request $request ) {
        $term_id = absint( $request->get_param( 'id' ) );
        $term    = get_term( $term_id, 'ke_organizer' );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'not_found', 'Organizer not found.', array( 'status' => 404 ) );
        }
        return rest_ensure_response( $this->get_tpl_list( $term_id ) );
    }

    /**
     * POST /organizers/{id}/templates        — create
     * PUT  /organizers/{id}/templates/{tpl_id} — update
     */
    public function save_organizer_template( WP_REST_Request $request ) {
        $term_id = absint( $request->get_param( 'id' ) );
        $tpl_id  = sanitize_key( $request->get_param( 'tpl_id' ) ?? '' );
        $term    = get_term( $term_id, 'ke_organizer' );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'not_found', 'Organizer not found.', array( 'status' => 404 ) );
        }

        $params  = $request->get_json_params();
        $name    = sanitize_text_field( $params['name'] ?? '' );
        if ( ! $name ) {
            return new WP_Error( 'missing_name', 'Template name is required.', array( 'status' => 400 ) );
        }
        $tickets = $this->sanitize_tpl_tickets( $params['tickets'] ?? array() );
        $list    = $this->get_tpl_list( $term_id );

        if ( $tpl_id ) {
            // Update existing
            $found = false;
            foreach ( $list as &$tpl ) {
                if ( $tpl['id'] === $tpl_id ) {
                    $tpl['name']    = $name;
                    $tpl['tickets'] = $tickets;
                    $found          = true;
                    $result         = $tpl;
                    break;
                }
            }
            unset( $tpl );
            if ( ! $found ) {
                return new WP_Error( 'not_found', 'Template not found.', array( 'status' => 404 ) );
            }
        } else {
            // Create new
            $result = array(
                'id'      => 'tpl_' . substr( md5( uniqid( 'ke', true ) ), 0, 8 ),
                'name'    => $name,
                'tickets' => $tickets,
            );
            $list[] = $result;
        }

        $this->set_tpl_list( $term_id, $list );
        return rest_ensure_response( $result );
    }

    /**
     * DELETE /organizers/{id}/templates/{tpl_id}
     */
    public function delete_organizer_template( WP_REST_Request $request ) {
        $term_id = absint( $request->get_param( 'id' ) );
        $tpl_id  = sanitize_key( $request->get_param( 'tpl_id' ) );
        $list    = $this->get_tpl_list( $term_id );
        $this->set_tpl_list( $term_id, array_filter( $list, fn( $t ) => $t['id'] !== $tpl_id ) );
        return rest_ensure_response( array( 'success' => true ) );
    }

    /**
     * GET /settings
     */
    public function get_settings() {
        return rest_ensure_response( array(
            'ui'            => get_option( 'ke_ui_settings', array( 'accent_color' => '', 'subtitle_color' => '' ) ),
            'fees'          => array_values( get_option( 'ke_service_fees', array() ) ),
            'notifications' => get_option( 'ke_notifications_settings', array( 'admin_email_enabled' => true, 'global_bcc' => '' ) ),
            'access'        => self::get_access_settings(),
        ) );
    }

    /**
     * Read the access-control settings with sensible defaults.
     */
    public static function get_access_settings() {
        $stored = get_option( 'ke_access_settings', array() );
        return array(
            'require_login'          => ! empty( $stored['require_login'] ),
            'login_url'              => isset( $stored['login_url'] )    ? (string) $stored['login_url']    : wp_login_url(),
            'register_url'           => isset( $stored['register_url'] ) ? (string) $stored['register_url'] : wp_registration_url(),
            'login_required_message' => isset( $stored['login_required_message'] ) && $stored['login_required_message'] !== ''
                ? (string) $stored['login_required_message']
                : __( 'You need an account to purchase tickets for this event.', 'kiwi-events' ),
        );
    }

    /**
     * POST /settings/access
     * Body: { require_login, login_url, register_url, login_required_message }
     * When require_login flips ON, also force-enable WooCommerce's
     * "Users must be logged in to purchase" (belt-and-suspenders).
     */
    public function save_access_settings( WP_REST_Request $request ) {
        $params  = $request->get_json_params() ?: array();
        $current = get_option( 'ke_access_settings', array() );

        $require = isset( $params['require_login'] ) ? rest_sanitize_boolean( $params['require_login'] ) : ! empty( $current['require_login'] );

        $login_url    = isset( $params['login_url'] )    ? $this->sanitize_internal_url( $params['login_url'] )    : ( $current['login_url']    ?? '' );
        $register_url = isset( $params['register_url'] ) ? $this->sanitize_internal_url( $params['register_url'] ) : ( $current['register_url'] ?? '' );
        $message      = isset( $params['login_required_message'] )
            ? sanitize_text_field( $params['login_required_message'] )
            : ( $current['login_required_message'] ?? '' );

        $new = array(
            'require_login'          => $require,
            'login_url'              => $login_url,
            'register_url'           => $register_url,
            'login_required_message' => $message,
        );

        update_option( 'ke_access_settings', $new );

        // Mirror the setting into WooCommerce's own guest-checkout toggle.
        if ( $require && function_exists( 'update_option' ) ) {
            update_option( 'woocommerce_enable_guest_checkout', 'no' );
        }

        return rest_ensure_response( array( 'success' => true, 'access' => self::get_access_settings() ) );
    }

    /**
     * Accepts absolute same-site URLs or site-relative paths, rejects
     * external hosts. Returns a cleaned string (possibly empty).
     */
    private function sanitize_internal_url( $raw ) {
        $raw = trim( (string) $raw );
        if ( $raw === '' ) return '';

        // Relative paths pass through unchanged.
        if ( $raw[0] === '/' ) {
            return esc_url_raw( $raw );
        }

        $parsed = wp_parse_url( $raw );
        if ( empty( $parsed['host'] ) ) {
            return esc_url_raw( $raw );
        }
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( strcasecmp( $parsed['host'], $site_host ) !== 0 ) {
            return '';
        }
        return esc_url_raw( $raw );
    }

    /**
     * POST /settings/notifications
     */
    public function save_notifications_settings( WP_REST_Request $request ) {
        $params  = $request->get_json_params();
        $current = get_option( 'ke_notifications_settings', array() );

        if ( isset( $params['admin_email_enabled'] ) ) {
            $current['admin_email_enabled'] = rest_sanitize_boolean( $params['admin_email_enabled'] );
        }
        if ( isset( $params['global_bcc'] ) ) {
            $current['global_bcc'] = sanitize_email( $params['global_bcc'] );
        }

        update_option( 'ke_notifications_settings', $current );
        return rest_ensure_response( array( 'success' => true ) );
    }

    /**
     * POST /settings/test-notification
     */
    public function send_test_admin_notification( WP_REST_Request $request ) {
        if ( ! class_exists( 'KE_Email' ) ) {
            require_once KE_PLUGIN_DIR . 'includes/class-ke-email.php';
        }

        $email = new KE_Email();
        $result = $email->send_test_admin_notification();
        if ( is_wp_error( $result ) ) {
            return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 500 ) );
        }

        return rest_ensure_response( array( 'success' => true ) );
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

    /**
     * Sanitize per-extra config. Whitelist keys per type; coerce scalars.
     */
    private function sanitize_extra_config( $type, $config ) {
        $out = array();
        switch ( $type ) {
            case 'sold_out_bar':
                $style = sanitize_key( $config['style'] ?? 'linear' );
                $out['style']          = in_array( $style, array( 'linear', 'circular', 'badge' ), true ) ? $style : 'linear';
                $out['color']          = sanitize_hex_color( $config['color'] ?? '' ) ?: '';
                $out['hide_when_full'] = ! empty( $config['hide_when_full'] );
                break;
            case 'countdown':
                $out['show_seconds']       = ! empty( $config['show_seconds'] );
                $out['message_when_live']  = sanitize_text_field( $config['message_when_live'] ?? '' );
                break;
            case 'lineup':
                $artists = array();
                if ( is_array( $config['artists'] ?? null ) ) {
                    foreach ( $config['artists'] as $a ) {
                        if ( ! is_array( $a ) ) continue;
                        $name = sanitize_text_field( $a['name'] ?? '' );
                        if ( ! $name ) continue;
                        $artists[] = array(
                            'name'     => $name,
                            'photo_id' => absint( $a['photo_id'] ?? 0 ),
                        );
                    }
                }
                $out['artists'] = $artists;
                break;
            case 'gallery':
                $photos = array();
                // New shape: [{ photo_id, caption }, ...]
                if ( is_array( $config['photos'] ?? null ) ) {
                    foreach ( $config['photos'] as $p ) {
                        if ( ! is_array( $p ) ) continue;
                        $pid = absint( $p['photo_id'] ?? 0 );
                        if ( ! $pid ) continue;
                        $photos[] = array(
                            'photo_id' => $pid,
                            'caption'  => sanitize_text_field( $p['caption'] ?? '' ),
                        );
                    }
                }
                // Legacy shape: photo_ids => [id, id, ...]
                if ( empty( $photos ) && is_array( $config['photo_ids'] ?? null ) ) {
                    foreach ( $config['photo_ids'] as $pid ) {
                        $pid = absint( $pid );
                        if ( $pid ) {
                            $photos[] = array( 'photo_id' => $pid, 'caption' => '' );
                        }
                    }
                }
                $out['photos'] = $photos;
                break;
            case 'testimonials':
                $out['title']            = sanitize_text_field( $config['title'] ?? 'Testimonials' ) ?: 'Testimonials';
                $out['require_approval'] = array_key_exists( 'require_approval', $config ) ? ! empty( $config['require_approval'] ) : true;
                $out['allow_ratings']    = array_key_exists( 'allow_ratings', $config )    ? ! empty( $config['allow_ratings'] )    : true;
                break;
            case 'schedule':
                $slots = array();
                // New shape: slots: [{ time, title, description }]. Legacy: items: [{ time, title, desc }].
                $raw = is_array( $config['slots'] ?? null ) ? $config['slots'] : ( is_array( $config['items'] ?? null ) ? $config['items'] : array() );
                foreach ( $raw as $it ) {
                    if ( ! is_array( $it ) ) continue;
                    $title = sanitize_text_field( $it['title'] ?? '' );
                    if ( ! $title ) continue;
                    // Normalize time to HH:MM 24h; accept "HH:MM" or "H:MM".
                    $time_raw = trim( (string) ( $it['time'] ?? '' ) );
                    $time     = '';
                    if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $time_raw, $m ) ) {
                        $h = max( 0, min( 23, (int) $m[1] ) );
                        $mn = max( 0, min( 59, (int) $m[2] ) );
                        $time = sprintf( '%02d:%02d', $h, $mn );
                    }
                    $slots[] = array(
                        'time'        => $time,
                        'title'       => $title,
                        'description' => sanitize_textarea_field( $it['description'] ?? $it['desc'] ?? '' ),
                    );
                }
                $out['slots'] = $slots;
                break;
            case 'menu_faq':
                $sections = array();
                if ( is_array( $config['sections'] ?? null ) ) {
                    foreach ( $config['sections'] as $s ) {
                        if ( ! is_array( $s ) ) continue;
                        $heading = sanitize_text_field( $s['heading'] ?? '' );
                        if ( ! $heading ) continue;
                        $sections[] = array(
                            'heading' => $heading,
                            'body'    => wp_kses_post( $s['body'] ?? '' ),
                        );
                    }
                }
                $out['sections'] = $sections;
                break;
            case 'faq':
                $out['title'] = sanitize_text_field( $config['title'] ?? 'Frequently Asked Questions' ) ?: 'Frequently Asked Questions';
                $items = array();
                if ( is_array( $config['items'] ?? null ) ) {
                    foreach ( $config['items'] as $it ) {
                        if ( ! is_array( $it ) ) continue;
                        $q = sanitize_text_field( $it['question'] ?? '' );
                        $a = sanitize_textarea_field( $it['answer'] ?? '' );
                        if ( ! $q || ! $a ) continue;
                        $items[] = array( 'question' => $q, 'answer' => $a );
                    }
                }
                $out['items'] = $items;
                break;
            case 'additional_info':
                // Refundable status: 'yes' | 'no' | '' (unspecified).
                $refundable = sanitize_key( $config['refundable'] ?? '' );
                $out['refundable'] = in_array( $refundable, array( 'yes', 'no' ), true ) ? $refundable : '';
                // Disclaimers: free-form rich text, restricted to post-safe HTML.
                $out['disclaimers'] = wp_kses_post( (string) ( $config['disclaimers'] ?? '' ) );
                break;
        }
        return $out;
    }

    // ─── Helpers ───────────────────────────────────────────────────

    // ─── Testimonials ──────────────────────────────────────────────

    /**
     * Look up the testimonials extra config for an event.
     * Returns null when the extra is not present or disabled.
     */
    private function get_testimonials_config( $event_id ) {
        $extras = get_post_meta( $event_id, '_ke_event_extras', true );
        if ( ! is_array( $extras ) ) return null;
        foreach ( $extras as $e ) {
            if ( ( $e['type'] ?? '' ) === 'testimonials' && ! empty( $e['enabled'] ) ) {
                $cfg = is_array( $e['config'] ?? null ) ? $e['config'] : array();
                return array(
                    'title'            => $cfg['title']            ?? 'Testimonials',
                    'require_approval' => array_key_exists( 'require_approval', $cfg ) ? ! empty( $cfg['require_approval'] ) : true,
                    'allow_ratings'    => array_key_exists( 'allow_ratings', $cfg )    ? ! empty( $cfg['allow_ratings'] )    : true,
                );
            }
        }
        return null;
    }

    /**
     * Shape a comment row for the API response.
     */
    private function format_testimonial( $comment ) {
        $rating = (int) get_comment_meta( $comment->comment_ID, 'ke_rating', true );
        $pinned = (int) get_comment_meta( $comment->comment_ID, 'ke_pinned', true );
        $avatar = get_avatar_url( $comment->comment_author_email, array( 'size' => 64 ) );

        return array(
            'id'        => (int) $comment->comment_ID,
            'author'    => $comment->comment_author,
            'content'   => $comment->comment_content,
            'rating'    => $rating > 0 ? min( 5, max( 1, $rating ) ) : 0,
            'pinned'    => $pinned ? true : false,
            'approved'  => $comment->comment_approved === '1',
            'date'      => mysql2date( 'c', $comment->comment_date_gmt, false ),
            'date_rel'  => human_time_diff( strtotime( $comment->comment_date_gmt ) ) . ' ago',
            'avatar'    => $avatar,
            'user_id'   => (int) $comment->user_id,
        );
    }

    /**
     * GET /events/{id}/testimonials
     * Public endpoint: returns approved comments. Admin callers also see pending.
     */
    public function list_testimonials( WP_REST_Request $request ) {
        $event_id = absint( $request->get_param( 'id' ) );
        $post     = $event_id ? get_post( $event_id ) : null;
        if ( ! $post || $post->post_type !== 'ke_event' ) {
            return new WP_Error( 'not_found', 'Event not found.', array( 'status' => 404 ) );
        }

        $per_page = max( 1, min( 50, absint( $request->get_param( 'per_page' ) ) ?: 10 ) );
        $page     = max( 1, absint( $request->get_param( 'page' ) ) ?: 1 );
        $include_pending = ! empty( $request->get_param( 'pending' ) ) && $this->admin_permission_check();

        $status = $include_pending ? 'all' : 'approve';

        $total = (int) get_comments( array(
            'post_id'    => $event_id,
            'type'       => 'ke_testimonial',
            'status'     => $status,
            'count'      => true,
        ) );

        // Pinned first (by comment_ID desc), then unpinned newest-first.
        // get_comments() doesn't support meta-based ordering cleanly, so we
        // split the query into two passes.
        $pinned = get_comments( array(
            'post_id'    => $event_id,
            'type'       => 'ke_testimonial',
            'status'     => $status,
            'meta_key'   => 'ke_pinned',
            'meta_value' => '1',
            'orderby'    => 'comment_date_gmt',
            'order'      => 'DESC',
            'number'     => 100,
        ) );

        $pinned_ids = array_map( fn( $c ) => (int) $c->comment_ID, $pinned );

        $unpinned_args = array(
            'post_id'     => $event_id,
            'type'        => 'ke_testimonial',
            'status'      => $status,
            'orderby'     => 'comment_date_gmt',
            'order'       => 'DESC',
            'number'      => $per_page,
            'offset'      => ( $page - 1 ) * $per_page,
            'comment__not_in' => $pinned_ids ?: array( 0 ),
        );
        $unpinned = get_comments( $unpinned_args );

        // Pinned only on page 1.
        $rows = $page === 1 ? array_merge( $pinned, $unpinned ) : $unpinned;

        $items = array();
        foreach ( $rows as $c ) {
            $items[] = $this->format_testimonial( $c );
        }

        return rest_ensure_response( array(
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'has_more'    => ( $page * $per_page ) < $total - count( $pinned_ids ),
        ) );
    }

    /**
     * POST /events/{id}/testimonials
     * Body: { rating: 1-5 (optional), comment: string }
     */
    public function create_testimonial( WP_REST_Request $request ) {
        $event_id = absint( $request->get_param( 'id' ) );
        $post     = $event_id ? get_post( $event_id ) : null;
        if ( ! $post || $post->post_type !== 'ke_event' ) {
            return new WP_Error( 'not_found', 'Event not found.', array( 'status' => 404 ) );
        }

        $config = $this->get_testimonials_config( $event_id );
        if ( ! $config ) {
            return new WP_Error( 'disabled', 'Testimonials are not enabled for this event.', array( 'status' => 403 ) );
        }

        $params  = $request->get_json_params() ?: array();
        $content = sanitize_textarea_field( $params['comment'] ?? '' );
        $rating  = absint( $params['rating'] ?? 0 );

        if ( ! $content ) {
            return new WP_Error( 'empty_comment', 'Your comment cannot be empty.', array( 'status' => 400 ) );
        }
        if ( strlen( $content ) > 2000 ) {
            return new WP_Error( 'too_long', 'Comments are limited to 2000 characters.', array( 'status' => 400 ) );
        }

        $user = wp_get_current_user();
        if ( ! $user || ! $user->exists() ) {
            return new WP_Error( 'not_logged_in', 'You must be logged in.', array( 'status' => 401 ) );
        }

        $approved = $config['require_approval'] ? 0 : 1;

        $comment_id = wp_insert_comment( array(
            'comment_post_ID'      => $event_id,
            'comment_author'       => $user->display_name,
            'comment_author_email' => $user->user_email,
            'comment_author_url'   => '',
            'comment_content'      => $content,
            'comment_type'         => 'ke_testimonial',
            'comment_approved'     => $approved,
            'user_id'              => $user->ID,
            'comment_date'         => current_time( 'mysql' ),
            'comment_date_gmt'     => current_time( 'mysql', 1 ),
        ) );

        if ( ! $comment_id ) {
            return new WP_Error( 'save_failed', 'Could not save your comment.', array( 'status' => 500 ) );
        }

        if ( $config['allow_ratings'] && $rating >= 1 && $rating <= 5 ) {
            add_comment_meta( $comment_id, 'ke_rating', $rating, true );
        }

        $comment = get_comment( $comment_id );

        return rest_ensure_response( array(
            'success'   => true,
            'pending'   => $approved === 0,
            'message'   => $approved === 0
                ? 'Thanks! Your comment is awaiting approval.'
                : 'Thanks for sharing!',
            'testimonial' => $this->format_testimonial( $comment ),
        ) );
    }

    /**
     * PUT /events/{id}/testimonials/{comment_id}
     * Admin moderation — body: { action: 'approve'|'unapprove'|'pin'|'unpin' }
     */
    public function moderate_testimonial( WP_REST_Request $request ) {
        $event_id   = absint( $request->get_param( 'id' ) );
        $comment_id = absint( $request->get_param( 'comment_id' ) );
        $comment    = get_comment( $comment_id );

        if ( ! $comment || (int) $comment->comment_post_ID !== $event_id || $comment->comment_type !== 'ke_testimonial' ) {
            return new WP_Error( 'not_found', 'Testimonial not found.', array( 'status' => 404 ) );
        }

        $params = $request->get_json_params() ?: array();
        $action = sanitize_key( $params['action'] ?? '' );

        switch ( $action ) {
            case 'approve':
                wp_set_comment_status( $comment_id, 'approve' );
                break;
            case 'unapprove':
                wp_set_comment_status( $comment_id, 'hold' );
                break;
            case 'pin':
                update_comment_meta( $comment_id, 'ke_pinned', 1 );
                break;
            case 'unpin':
                delete_comment_meta( $comment_id, 'ke_pinned' );
                break;
            default:
                return new WP_Error( 'invalid_action', 'Unknown moderation action.', array( 'status' => 400 ) );
        }

        $fresh = get_comment( $comment_id );
        return rest_ensure_response( array(
            'success'     => true,
            'testimonial' => $this->format_testimonial( $fresh ),
        ) );
    }

    /**
     * DELETE /events/{id}/testimonials/{comment_id}
     */
    public function delete_testimonial( WP_REST_Request $request ) {
        $event_id   = absint( $request->get_param( 'id' ) );
        $comment_id = absint( $request->get_param( 'comment_id' ) );
        $comment    = get_comment( $comment_id );

        if ( ! $comment || (int) $comment->comment_post_ID !== $event_id || $comment->comment_type !== 'ke_testimonial' ) {
            return new WP_Error( 'not_found', 'Testimonial not found.', array( 'status' => 404 ) );
        }

        wp_delete_comment( $comment_id, true );
        return rest_ensure_response( array( 'success' => true, 'id' => $comment_id ) );
    }

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

    // ─── Ticket management (admin) ───────────────────────────────────────

    /**
     * POST /tickets/{id}/update-status
     * Body: { status: 'valid' | 'unused' | 'used' | 'cancelled' }
     */
    public function rest_update_ticket_status( WP_REST_Request $request ) {
        $id     = absint( $request->get_param( 'id' ) );
        $status = sanitize_text_field( (string) $request->get_param( 'status' ) );

        $tickets = new KE_Tickets();
        $result  = $tickets->update_status( $id, $status );
        if ( is_wp_error( $result ) ) {
            return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
        }

        return rest_ensure_response( array(
            'success' => true,
            'ticket'  => $this->format_ticket_row( $result ),
        ) );
    }

    /**
     * PUT /tickets/{id}
     * Body: { attendee_name?, attendee_email? }
     */
    public function rest_update_ticket( WP_REST_Request $request ) {
        $id     = absint( $request->get_param( 'id' ) );
        $fields = array();
        if ( $request->has_param( 'attendee_name' ) ) {
            $fields['attendee_name'] = (string) $request->get_param( 'attendee_name' );
        }
        if ( $request->has_param( 'attendee_email' ) ) {
            $fields['attendee_email'] = (string) $request->get_param( 'attendee_email' );
        }

        $tickets = new KE_Tickets();
        $result  = $tickets->update_attendee( $id, $fields );
        if ( is_wp_error( $result ) ) {
            return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
        }

        return rest_ensure_response( array(
            'success' => true,
            'ticket'  => $this->format_ticket_row( $result ),
        ) );
    }

    /**
     * DELETE /tickets/{id}  (?hard=1 for permanent)
     */
    public function rest_delete_ticket( WP_REST_Request $request ) {
        $id   = absint( $request->get_param( 'id' ) );
        $hard = (int) $request->get_param( 'hard' ) === 1;

        $tickets = new KE_Tickets();
        $result  = $hard ? $tickets->hard_delete( $id ) : $tickets->cancel( $id );
        if ( is_wp_error( $result ) ) {
            return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
        }

        return rest_ensure_response( array(
            'success' => true,
            'hard'    => $hard,
            'ticket'  => $hard ? null : $this->format_ticket_row( $result ),
        ) );
    }

    /**
     * POST /tickets/{id}/resend-email
     * Re-sends the ticket email for this ticket's order.
     */
    public function rest_resend_ticket_email( WP_REST_Request $request ) {
        $id      = absint( $request->get_param( 'id' ) );
        $tickets = new KE_Tickets();
        $ticket  = $tickets->get( $id );
        if ( ! $ticket ) {
            return new WP_Error( 'not_found', 'Ticket not found.', array( 'status' => 404 ) );
        }

        $email  = new KE_Email();
        $result = $email->send_ticket_email( (int) $ticket->order_id );
        if ( is_wp_error( $result ) ) {
            return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 500 ) );
        }

        return rest_ensure_response( array( 'success' => true ) );
    }

    /**
     * POST /orders/{id}/resend-admin-notification
     * Re-sends the admin notification email for an order.
     */
    public function rest_resend_admin_notification( WP_REST_Request $request ) {
        $id      = absint( $request->get_param( 'id' ) );
        
        $email  = new KE_Email();
        $result = $email->send_admin_notification( $id );
        if ( ! $result ) {
            return new WP_Error( 'mail_failed', 'Failed to send admin notification.', array( 'status' => 500 ) );
        }

        return rest_ensure_response( array( 'success' => true ) );
    }

    /**
     * POST /tickets/bulk
     * Body: { ids: [1,2,3], action: 'delete'|'hard_delete'|'resend'|'mark_used'|'mark_unused' }
     */
    public function rest_bulk_tickets( WP_REST_Request $request ) {
        $ids    = (array) $request->get_param( 'ids' );
        $action = sanitize_text_field( (string) $request->get_param( 'action' ) );
        $ids    = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

        if ( empty( $ids ) ) {
            return new WP_Error( 'no_ids', 'No ticket IDs provided.', array( 'status' => 400 ) );
        }

        $tickets = new KE_Tickets();
        $email   = new KE_Email();
        $ok      = 0;
        $failed  = array();
        $touched_orders = array();

        foreach ( $ids as $id ) {
            switch ( $action ) {
                case 'delete':
                    $r = $tickets->cancel( $id );
                    break;
                case 'hard_delete':
                    $r = $tickets->hard_delete( $id );
                    break;
                case 'mark_used':
                    $r = $tickets->update_status( $id, 'used' );
                    break;
                case 'mark_unused':
                    $r = $tickets->update_status( $id, 'valid' );
                    break;
                case 'resend':
                    // Dedupe by order_id — one ticket per order is enough since
                    // send_ticket_email() emails the whole order at once.
                    $t = $tickets->get( $id );
                    if ( ! $t ) { $r = new WP_Error( 'not_found', 'Ticket not found.' ); break; }
                    if ( isset( $touched_orders[ (int) $t->order_id ] ) ) { $r = true; break; }
                    $r = $email->send_ticket_email( (int) $t->order_id );
                    $touched_orders[ (int) $t->order_id ] = true;
                    break;
                default:
                    return new WP_Error( 'invalid_action', 'Unknown bulk action.', array( 'status' => 400 ) );
            }

            if ( is_wp_error( $r ) ) {
                $failed[] = array( 'id' => $id, 'message' => $r->get_error_message() );
            } else {
                $ok++;
            }
        }

        return rest_ensure_response( array(
            'success' => empty( $failed ),
            'ok'      => $ok,
            'failed'  => $failed,
        ) );
    }

    /**
     * Shape a ticket row for responses the admin UI consumes.
     */
    private function format_ticket_row( $t ) {
        if ( ! $t ) return null;
        return array(
            'id'               => (int) $t->id,
            'ticket_code'      => (string) $t->ticket_code,
            'attendee_name'    => (string) $t->attendee_name,
            'attendee_email'   => (string) $t->attendee_email,
            'attendee_number'  => (int) $t->attendee_number,
            'status'           => (string) $t->status,
            'checked_in_at'    => $t->checked_in_at,
            'ticket_type_id'   => (int) $t->ticket_type_id,
            'ticket_type_name' => isset( $t->ticket_type_name ) ? (string) $t->ticket_type_name : '',
            'ticket_price'     => isset( $t->ticket_price ) ? (float) $t->ticket_price : 0.0,
            'order_id'         => (int) $t->order_id,
            'event_id'         => (int) $t->event_id,
            'qr_code_path'     => (string) ( $t->qr_code_path ?? '' ),
        );
    }

    /**
     * POST /events/{id}/duplicate
     *
     * Creates a draft copy of the source event with: title " (Copy)" appended,
     * content/excerpt, featured image, all custom post meta, taxonomy terms, and
     * fresh ticket type rows (non-archived, quantity_sold reset). Attendees,
     * orders, and testimonials stay with the original.
     */
    public function duplicate_event( WP_REST_Request $request ) {
        $source_id = absint( $request->get_param( 'id' ) );
        $source    = $source_id ? get_post( $source_id ) : null;
        if ( ! $source || $source->post_type !== 'ke_event' ) {
            return new WP_Error( 'not_found', 'Event not found.', array( 'status' => 404 ) );
        }

        $new_id = wp_insert_post( array(
            'post_type'    => 'ke_event',
            'post_status'  => 'draft',
            'post_title'   => $source->post_title . ' (Copy)',
            'post_content' => $source->post_content,
            'post_excerpt' => $source->post_excerpt,
            'post_author'  => get_current_user_id(),
        ), true );
        if ( is_wp_error( $new_id ) ) {
            return new WP_Error( 'duplicate_failed', $new_id->get_error_message(), array( 'status' => 500 ) );
        }

        // Featured image — attachments are shared, so re-point the new post
        // at the existing attachment rather than deep-copying the media file.
        $thumb_id = get_post_thumbnail_id( $source_id );
        if ( $thumb_id ) {
            set_post_thumbnail( $new_id, (int) $thumb_id );
        }

        // Copy all post meta except keys WP manages per-post.
        $skip_keys = array( '_edit_lock', '_edit_last', '_thumbnail_id', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug' );
        $all_meta  = get_post_meta( $source_id );
        if ( is_array( $all_meta ) ) {
            foreach ( $all_meta as $meta_key => $raw_values ) {
                if ( in_array( $meta_key, $skip_keys, true ) ) continue;
                foreach ( (array) $raw_values as $raw_val ) {
                    add_post_meta( $new_id, $meta_key, maybe_unserialize( $raw_val ) );
                }
            }
        }

        // Copy taxonomies (categories, tags, organizer).
        foreach ( array( 'ke_event_category', 'ke_event_tag', 'ke_organizer' ) as $tax ) {
            $term_ids = wp_get_object_terms( $source_id, $tax, array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $term_ids ) && ! empty( $term_ids ) ) {
                wp_set_object_terms( $new_id, array_map( 'intval', $term_ids ), $tax, false );
            }
        }

        // Clone non-archived ticket types with quantity_sold reset.
        global $wpdb;
        $types_table = $wpdb->prefix . 'ke_ticket_types';
        $types = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$types_table}
             WHERE event_id = %d AND (is_archived IS NULL OR is_archived = 0)
             ORDER BY id ASC",
            $source_id
        ) );

        if ( ! empty( $types ) ) {
            $now = current_time( 'mysql' );
            foreach ( $types as $t ) {
                $row = (array) $t;
                unset( $row['id'] );
                $row['event_id']      = $new_id;
                $row['quantity_sold'] = 0;
                $row['is_archived']   = 0;
                $row['created_at']    = $now;
                $wpdb->insert( $types_table, $row );
            }
        }

        return rest_ensure_response( array(
            'success'  => true,
            'id'       => (int) $new_id,
            'title'    => get_the_title( $new_id ),
            'edit_url' => admin_url( 'admin.php?page=ke-event-builder&event_id=' . (int) $new_id ),
        ) );
    }

    /* ─── Organizer self-service dashboard endpoints ─────────────────── */

    /**
     * POST /organizer/auth
     * Body: { slug: string, password?: string, admin_nonce?: string }
     * Sets the HTTP-only session cookie on success. Reuses the scanner's
     * IP+identifier rate-limit transient (keyed by organizer term_id, not
     * event_id, since the dashboard is organizer-scoped).
     */
    public function organizer_auth( WP_REST_Request $request ) {
        $slug = sanitize_title( (string) $request->get_param( 'slug' ) );
        if ( $slug === '' ) {
            return new WP_Error( 'invalid_slug', __( 'Organizer slug is required.', 'kiwi-events' ), array( 'status' => 400 ) );
        }

        $term = get_term_by( 'slug', $slug, 'ke_organizer' );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'invalid_organizer', __( 'Organizer not found.', 'kiwi-events' ), array( 'status' => 404 ) );
        }
        if ( ! KE_Scanner_Password::organizer_has_password( $term->term_id ) ) {
            return new WP_Error( 'no_password_set', __( 'This organizer has no dashboard password configured.', 'kiwi-events' ), array( 'status' => 404 ) );
        }

        $ip = KE_Scanner_Password::get_request_ip();
        // Negative event_id buckets organizer-scope attempts away from per-event scanner attempts.
        $rate_bucket = -1 * (int) $term->term_id;

        if ( KE_Scanner_Password::attempts_remaining( $ip, $rate_bucket ) <= 0 ) {
            return new WP_Error(
                'rate_limited',
                __( 'Too many failed attempts. Please wait a moment and try again.', 'kiwi-events' ),
                array( 'status' => 429, 'retry_after' => KE_Scanner_Password::ATTEMPT_WINDOW )
            );
        }

        // Admin override: a logged-in admin with a valid wp_rest nonce can skip the password.
        $admin_nonce       = (string) $request->get_param( 'admin_nonce' );
        $is_admin_override = false;
        if ( $admin_nonce !== ''
             && wp_verify_nonce( $admin_nonce, 'wp_rest' )
             && KE_Organizer_Dashboard::is_admin_user() ) {
            $is_admin_override = true;
        }

        $password = (string) $request->get_param( 'password' );

        if ( ! $is_admin_override ) {
            if ( $password === '' ) {
                return new WP_Error(
                    'password_required',
                    __( 'Password is required.', 'kiwi-events' ),
                    array(
                        'status'             => 401,
                        'attempts_remaining' => KE_Scanner_Password::attempts_remaining( $ip, $rate_bucket ),
                    )
                );
            }
            if ( ! KE_Scanner_Password::verify_organizer_password( $term->term_id, $password ) ) {
                KE_Scanner_Password::record_failed_attempt( $ip, $rate_bucket );
                return new WP_Error(
                    'invalid_password',
                    __( 'Incorrect password.', 'kiwi-events' ),
                    array(
                        'status'             => 401,
                        'attempts_remaining' => KE_Scanner_Password::attempts_remaining( $ip, $rate_bucket ),
                    )
                );
            }
        }

        KE_Scanner_Password::clear_attempts( $ip, $rate_bucket );
        $session = KE_Organizer_Dashboard::issue_session( $term->term_id, $term->slug );

        return rest_ensure_response( array(
            'success'        => true,
            'organizer_id'   => (int) $term->term_id,
            'organizer_slug' => $term->slug,
            'organizer_name' => $term->name,
            'expires_at'     => $session['expires_at'],
            'ttl'            => $session['ttl'],
            'admin_override' => $is_admin_override,
        ) );
    }

    /**
     * Permission callback: cookie session must match the slug in the URL.
     * Admins bypass the cookie check (KE_Organizer_Dashboard::require_session_for_slug
     * returns the term object for them). Returns true on success or a
     * WP_Error that REST will surface as 401/404.
     */
    public function organizer_session_permission_check( WP_REST_Request $request ) {
        $slug   = (string) $request->get_param( 'slug' );
        $result = KE_Organizer_Dashboard::require_session_for_slug( $slug );
        if ( is_wp_error( $result ) ) return $result;
        return true;
    }

    /**
     * GET /organizer/{slug}/last-sale
     *
     * Real-time sales beacon. Returns the Unix timestamp of the most
     * recent ticket creation for this organizer, or 0 if none.
     * Designed to be called every ~8s by the dashboard — one transient
     * read, no database query, Cache-Control: no-store.
     */
    public function organizer_last_sale( WP_REST_Request $request ) {
        $slug = (string) $request->get_param( 'slug' );
        $term = KE_Organizer_Dashboard::require_session_for_slug( $slug );
        if ( is_wp_error( $term ) ) return $term;

        $ts = (int) get_transient( 'ke_last_sale_' . (int) $term->term_id );

        $resp = rest_ensure_response( array(
            'last_sale' => $ts,
        ) );
        $resp->header( 'Cache-Control', 'no-store, private, max-age=0' );
        return $resp;
    }

    /**
     * GET /organizer/{slug}/stats?range=30
     */
    public function organizer_stats( WP_REST_Request $request ) {
        $slug  = (string) $request->get_param( 'slug' );
        $term  = KE_Organizer_Dashboard::require_session_for_slug( $slug );
        if ( is_wp_error( $term ) ) return $term;

        $range = (string) $request->get_param( 'range' );
        if ( $range === '' ) $range = '30';
        if ( ! in_array( $range, array( '7', '30', '90', '365', 'all' ), true ) ) {
            $range = '30';
        }

        // 30s transient cache so 50 organizers polling at the same time hit the
        // DB once per scope, not 50 times. Admins bypass so the WP backoffice
        // always reflects the current state when debugging an organizer view.
        $is_admin    = current_user_can( 'manage_options' );
        $cache_key   = 'ke_org_stats_' . (int) $term->term_id . '_' . $range;
        $payload     = $is_admin ? false : get_transient( $cache_key );

        if ( false === $payload ) {
            $payload = array(
                'organizer' => array(
                    'id'   => (int) $term->term_id,
                    'slug' => $term->slug,
                    'name' => $term->name,
                ),
                'range'    => $range,
                'headline' => KE_Organizer_Stats::headline_stats( $term->term_id, $range ),
                'series'   => KE_Organizer_Stats::daily_series( $term->term_id, $range ),
                'events'   => KE_Organizer_Stats::events_breakdown( $term->term_id, $range ),
            );
            if ( ! $is_admin ) {
                set_transient( $cache_key, $payload, 30 );
            }
        }

        $payload['generated_at'] = current_time( 'c' );

        return rest_ensure_response( $payload );
    }

    /**
     * GET /organizer/{slug}/attendees
     * Query: event_id (optional), search (name/email substring), page, per_page (max 100)
     */
    public function organizer_attendees( WP_REST_Request $request ) {
        $slug = (string) $request->get_param( 'slug' );
        $term = KE_Organizer_Dashboard::require_session_for_slug( $slug );
        if ( is_wp_error( $term ) ) return $term;

        $event_ids = KE_Organizer_Stats::get_event_ids_for_organizer( $term->term_id );
        if ( empty( $event_ids ) ) {
            return rest_ensure_response( array(
                'attendees' => array(),
                'total'     => 0,
                'page'      => 1,
                'per_page'  => 25,
                'pages'     => 0,
            ) );
        }

        $event_id = (int) $request->get_param( 'event_id' );
        $search   = trim( (string) $request->get_param( 'search' ) );
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = (int) $request->get_param( 'per_page' );
        if ( $per_page <= 0 ) $per_page = 25;
        if ( $per_page > 100 ) $per_page = 100;

        // Restrict event_id to organizer's set; otherwise an authenticated cookie
        // could enumerate other organizers' events by id.
        if ( $event_id > 0 && ! in_array( $event_id, $event_ids, true ) ) {
            return new WP_Error( 'forbidden_event', __( 'Event not in this organizer.', 'kiwi-events' ), array( 'status' => 403 ) );
        }
        $scoped_ids = $event_id > 0 ? array( $event_id ) : $event_ids;

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $scoped_ids ), '%d' ) );
        $where  = "WHERE t.event_id IN ($placeholders)";
        $params = $scoped_ids;
        if ( $search !== '' ) {
            $where .= ' AND ( t.attendee_name LIKE %s OR t.attendee_email LIKE %s )';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like; $params[] = $like;
        }

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ke_tickets t $where",
            $params
        ) );
        $offset = ( $page - 1 ) * $per_page;
        $limit_params = $params;
        $limit_params[] = $per_page;
        $limit_params[] = $offset;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.id, t.event_id, t.ticket_code, t.attendee_name, t.attendee_email,
                    t.status, t.checked_in_at, t.ticket_type_snapshot, t.created_at,
                    t.extra_fields_data,
                    p.post_title AS event_title
             FROM {$wpdb->prefix}ke_tickets t
             LEFT JOIN {$wpdb->posts} p ON p.ID = t.event_id
             $where
             ORDER BY t.created_at DESC
             LIMIT %d OFFSET %d",
            $limit_params
        ) );

        $attendees = array();
        foreach ( $rows as $r ) {
            $attendees[] = array(
                'id'            => (int) $r->id,
                'event_id'      => (int) $r->event_id,
                'event_title'   => (string) $r->event_title,
                'ticket_code'   => substr( (string) $r->ticket_code, 0, 12 ),
                'name'          => (string) $r->attendee_name,
                'email'         => (string) $r->attendee_email,
                'ticket_type'   => (string) $r->ticket_type_snapshot,
                'status'        => (string) $r->status,
                'checked_in_at' => $r->checked_in_at ? mysql2date( 'c', $r->checked_in_at ) : null,
                'created_at'    => mysql2date( 'c', $r->created_at ),
                'extra_fields'  => class_exists( 'KE_Event_Extra_Fields' )
                                   ? KE_Event_Extra_Fields::resolve_for_ticket( (int) $r->event_id, $r->extra_fields_data )
                                   : array(),
            );
        }

        return rest_ensure_response( array(
            'attendees' => $attendees,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $per_page,
            'pages'     => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
        ) );
    }

    /**
     * GET /organizer/{slug}/activity
     * Most recent completed sales for this organizer's events (max 10).
     */
    public function organizer_activity( WP_REST_Request $request ) {
        $slug = (string) $request->get_param( 'slug' );
        $term = KE_Organizer_Dashboard::require_session_for_slug( $slug );
        if ( is_wp_error( $term ) ) return $term;

        $event_ids = KE_Organizer_Stats::get_event_ids_for_organizer( $term->term_id );
        if ( empty( $event_ids ) ) return rest_ensure_response( array( 'items' => array() ) );

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );
        // Pull the most-recently-created ticket for each order so we can show
        // a representative ticket type alongside the buyer's name. Orders with
        // mixed ticket types just surface the most recent one — good enough
        // for an at-a-glance feed.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT o.id, o.event_id, o.buyer_name, o.buyer_email, o.ticket_quantity, o.total_amount, o.created_at,
                    p.post_title AS event_title,
                    (
                        SELECT t.ticket_type_snapshot
                        FROM {$wpdb->prefix}ke_tickets t
                        WHERE t.order_id = o.id AND t.status != 'cancelled'
                        ORDER BY t.id DESC
                        LIMIT 1
                    ) AS ticket_type
             FROM {$wpdb->prefix}ke_orders o
             LEFT JOIN {$wpdb->posts} p ON p.ID = o.event_id
             WHERE o.payment_status = 'completed' AND o.event_id IN ($placeholders)
             ORDER BY o.created_at DESC
             LIMIT 10",
            $event_ids
        ) );

        $items = array();
        foreach ( $rows as $r ) {
            $items[] = array(
                'id'           => (int) $r->id,
                'event_id'     => (int) $r->event_id,
                'event_title'  => (string) $r->event_title,
                'buyer_name'   => (string) $r->buyer_name,
                'buyer_email'  => (string) $r->buyer_email,
                'ticket_type'  => (string) ( $r->ticket_type ?? '' ),
                'quantity'     => (int) $r->ticket_quantity,
                'gross'        => (float) $r->total_amount,
                'created_at'   => mysql2date( 'c', $r->created_at ),
            );
        }
        return rest_ensure_response( array( 'items' => $items ) );
    }

    /**
     * GET /organizer/{slug}/export/csv?event_id=
     */
    public function organizer_export_csv( WP_REST_Request $request ) {
        $slug = (string) $request->get_param( 'slug' );
        $term = KE_Organizer_Dashboard::require_session_for_slug( $slug );
        if ( is_wp_error( $term ) ) return $term;

        $event_ids = KE_Organizer_Stats::get_event_ids_for_organizer( $term->term_id );
        if ( empty( $event_ids ) ) {
            return new WP_Error( 'no_data', __( 'No events found.', 'kiwi-events' ), array( 'status' => 404 ) );
        }
        $event_id = (int) $request->get_param( 'event_id' );
        if ( $event_id > 0 && ! in_array( $event_id, $event_ids, true ) ) {
            return new WP_Error( 'forbidden_event', __( 'Event not in this organizer.', 'kiwi-events' ), array( 'status' => 403 ) );
        }
        $scoped = $event_id > 0 ? array( $event_id ) : $event_ids;

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $scoped ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.event_id, t.attendee_name, t.attendee_email, t.ticket_type_snapshot, t.status,
                    t.is_courtesy, t.checked_in_at, t.created_at, t.extra_fields_data, p.post_title AS event_title
             FROM {$wpdb->prefix}ke_tickets t
             LEFT JOIN {$wpdb->posts} p ON p.ID = t.event_id
             WHERE t.event_id IN ($placeholders) AND t.status != 'cancelled'
             ORDER BY t.created_at DESC",
            $scoped
        ) );

        // Compute the union of extra-field columns across all in-scope events.
        // Each event has its own field set, so we present an "every column we
        // know about" header and leave blanks where a row's event didn't ask.
        $xf_columns = array(); // [ "{event_id}|{field_id}" => [ event_id, field, header ] ]
        if ( class_exists( 'KE_Event_Extra_Fields' ) ) {
            foreach ( $scoped as $eid ) {
                $cfg = KE_Event_Extra_Fields::get_config( $eid );
                if ( empty( $cfg['enabled'] ) || empty( $cfg['fields'] ) ) continue;
                $event_title_for_col = get_the_title( $eid );
                foreach ( $cfg['fields'] as $f ) {
                    $key = $eid . '|' . $f['id'];
                    // When exporting all events, prefix the column with the
                    // event title so identical labels from different events
                    // (e.g., two events both asking "Shirt size") stay separated.
                    $header = ( count( $scoped ) > 1 )
                              ? $event_title_for_col . ' — ' . $f['label']
                              : $f['label'];
                    $xf_columns[ $key ] = array(
                        'event_id' => (int) $eid,
                        'field_id' => (string) $f['id'],
                        'header'   => $header,
                    );
                }
            }
        }

        $filename = 'organizer-' . sanitize_title( $term->slug ) . '-attendees-' . gmdate( 'Y-m-d' ) . '.csv';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        // BOM for Excel UTF-8 detection
        fwrite( $out, "\xEF\xBB\xBF" );

        $header_row = array( 'Event', 'Name', 'Email', 'Ticket Type', 'Attendee Type', 'Status', 'Purchased At', 'Checked In At' );
        foreach ( $xf_columns as $col ) {
            $header_row[] = $col['header'];
        }
        fputcsv( $out, $header_row );

        foreach ( $rows as $r ) {
            $base = array(
                $r->event_title,
                $r->attendee_name,
                $r->attendee_email,
                $r->ticket_type_snapshot,
                ! empty( $r->is_courtesy ) ? 'Courtesy' : 'Real',
                $r->status === 'used' ? 'Checked In' : ( $r->status === 'valid' ? 'Valid' : ucfirst( (string) $r->status ) ),
                $r->created_at,
                $r->checked_in_at ?: '',
            );
            $decoded = $r->extra_fields_data ? json_decode( (string) $r->extra_fields_data, true ) : array();
            if ( ! is_array( $decoded ) ) $decoded = array();
            foreach ( $xf_columns as $col ) {
                if ( (int) $r->event_id !== $col['event_id'] ) {
                    $base[] = '';
                    continue;
                }
                $val = $decoded[ $col['field_id'] ] ?? '';
                $base[] = is_scalar( $val ) ? (string) $val : '';
            }
            fputcsv( $out, $base );
        }
        fclose( $out );
        exit;
    }

    /**
     * GET /organizer/{slug}/export/pdf?range=...&event_id=N
     *
     * Full organizer report by default; pass event_id to scope to a single
     * event (the per-event "PDF" button on the Events list uses this). The
     * event_id is validated against this organizer's term so a session
     * cookie can never be used to enumerate other organizers' events.
     */
    public function organizer_export_pdf( WP_REST_Request $request ) {
        $slug = (string) $request->get_param( 'slug' );
        $term = KE_Organizer_Dashboard::require_session_for_slug( $slug );
        if ( is_wp_error( $term ) ) return $term;

        if ( ! class_exists( 'KE_Organizer_Report_PDF' ) ) {
            return new WP_Error( 'pdf_unavailable', __( 'PDF reporting is not yet available.', 'kiwi-events' ), array( 'status' => 501 ) );
        }
        $range = (string) $request->get_param( 'range' );
        if ( ! in_array( $range, array( '7', '30', '90', '365', 'all' ), true ) ) {
            $range = 'all';
        }

        $event_id = (int) $request->get_param( 'event_id' );
        if ( $event_id > 0 ) {
            $event_ids = KE_Organizer_Stats::get_event_ids_for_organizer( $term->term_id );
            if ( ! in_array( $event_id, $event_ids, true ) ) {
                return new WP_Error( 'forbidden_event', __( 'Event not in this organizer.', 'kiwi-events' ), array( 'status' => 403 ) );
            }
        } else {
            $event_id = 0;
        }

        $pdf = new KE_Organizer_Report_PDF( $term, $range, $event_id );
        $pdf->stream();
        exit;
    }

    /**
     * GET /organizer/{slug}/reservations
     * Query: status, event_id, from (Y-m-d), to (Y-m-d), search,
     *        page, per_page (max 100), orderby, order
     *
     * Lists reservations across all the organizer's events, filtered/paged
     * for the dashboard. event_id (when provided) is validated against the
     * organizer's event set so a session cookie can never enumerate other
     * organizers' reservations by id.
     */
    public function organizer_reservations_list( WP_REST_Request $request ) {
        $slug = (string) $request->get_param( 'slug' );
        $term = KE_Organizer_Dashboard::require_session_for_slug( $slug );
        if ( is_wp_error( $term ) ) return $term;

        $event_ids = KE_Organizer_Stats::get_event_ids_for_organizer( $term->term_id );
        if ( empty( $event_ids ) ) {
            return rest_ensure_response( array(
                'reservations' => array(),
                'total'        => 0,
                'page'         => 1,
                'per_page'     => 25,
                'pages'        => 0,
                'counts'       => array(),
            ) );
        }

        $event_id = (int) $request->get_param( 'event_id' );
        if ( $event_id > 0 && ! in_array( $event_id, $event_ids, true ) ) {
            return new WP_Error( 'forbidden_event', __( 'Event not in this organizer.', 'kiwi-events' ), array( 'status' => 403 ) );
        }
        $scoped = $event_id > 0 ? array( $event_id ) : $event_ids;

        $status = (string) $request->get_param( 'status' );
        if ( $status !== '' && ! in_array( $status, KE_Reservations::ALL_STATUSES, true ) ) {
            $status = '';
        }

        $search   = trim( (string) $request->get_param( 'search' ) );
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = (int) $request->get_param( 'per_page' );
        if ( $per_page <= 0 )  $per_page = 25;
        if ( $per_page > 100 ) $per_page = 100;

        // Date filters operate on arrival_time (the venue cares when guests
        // are showing up, not when the booking was created).
        $from = (string) $request->get_param( 'from' );
        $to   = (string) $request->get_param( 'to' );
        $from = $from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : '';
        $to   = $to   && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to )   ? $to   : '';

        $orderby_raw = (string) $request->get_param( 'orderby' );
        $allowed     = array( 'arrival_time', 'created_at', 'party_size', 'status' );
        $orderby     = in_array( $orderby_raw, $allowed, true ) ? $orderby_raw : 'arrival_time';
        $order       = strtoupper( (string) $request->get_param( 'order' ) ) === 'DESC' ? 'DESC' : 'ASC';

        global $wpdb;
        $table = $wpdb->prefix . 'ke_reservations';

        $placeholders = implode( ',', array_fill( 0, count( $scoped ), '%d' ) );
        $where  = "WHERE r.event_id IN ($placeholders)";
        $params = $scoped;

        if ( $status !== '' ) {
            $where .= ' AND r.status = %s';
            $params[] = $status;
        }
        if ( $from !== '' ) {
            $where .= ' AND r.arrival_time >= %s';
            $params[] = $from . ' 00:00:00';
        }
        if ( $to !== '' ) {
            $where .= ' AND r.arrival_time <= %s';
            $params[] = $to . ' 23:59:59';
        }
        if ( $search !== '' ) {
            $where .= ' AND ( r.customer_name LIKE %s OR r.customer_email LIKE %s OR r.customer_phone LIKE %s OR r.reservation_code LIKE %s )';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} r {$where}",
            $params
        ) );

        $offset = ( $page - 1 ) * $per_page;
        $list_params = $params;
        $list_params[] = $per_page;
        $list_params[] = $offset;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, p.post_title AS event_title
             FROM {$table} r
             LEFT JOIN {$wpdb->posts} p ON p.ID = r.event_id
             {$where}
             ORDER BY r.{$orderby} {$order}, r.id DESC
             LIMIT %d OFFSET %d",
            $list_params
        ) );

        // Status counts across the same scope (ignoring the status filter so
        // the dashboard pills always show real totals). Uses the same date
        // and search filters for consistency with the visible list.
        $count_where  = "WHERE r.event_id IN ($placeholders)";
        $count_params = $scoped;
        if ( $from !== '' ) {
            $count_where .= ' AND r.arrival_time >= %s';
            $count_params[] = $from . ' 00:00:00';
        }
        if ( $to !== '' ) {
            $count_where .= ' AND r.arrival_time <= %s';
            $count_params[] = $to . ' 23:59:59';
        }
        if ( $search !== '' ) {
            $count_where .= ' AND ( r.customer_name LIKE %s OR r.customer_email LIKE %s OR r.customer_phone LIKE %s OR r.reservation_code LIKE %s )';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $count_params[] = $like; $count_params[] = $like; $count_params[] = $like; $count_params[] = $like;
        }
        $count_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.status, COUNT(*) AS n FROM {$table} r {$count_where} GROUP BY r.status",
            $count_params
        ) );
        $counts = array();
        foreach ( KE_Reservations::ALL_STATUSES as $s ) {
            $counts[ $s ] = 0;
        }
        $counts['all'] = 0;
        foreach ( $count_rows as $cr ) {
            $counts[ (string) $cr->status ] = (int) $cr->n;
            $counts['all'] += (int) $cr->n;
        }

        $reservations = array();
        foreach ( $rows as $r ) {
            $reservations[] = $this->format_reservation_row( $r );
        }

        return rest_ensure_response( array(
            'reservations' => $reservations,
            'total'        => $total,
            'page'         => $page,
            'per_page'     => $per_page,
            'pages'        => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
            'counts'       => $counts,
        ) );
    }

    /**
     * Shared shape for a reservation row in dashboard responses. Resolves
     * extras the same way the customer/organizer email templates do so the
     * detail drawer can show labelled values.
     */
    private function format_reservation_row( $r ) {
        $extras = array();
        if ( class_exists( 'KE_Event_Extra_Fields' ) && ! empty( $r->extra_fields_data ) ) {
            $extras = KE_Event_Extra_Fields::resolve_for_ticket( (int) $r->event_id, $r->extra_fields_data );
        }
        return array(
            'id'               => (int) $r->id,
            'event_id'         => (int) $r->event_id,
            'event_title'      => (string) ( $r->event_title ?? '' ),
            'reservation_code' => (string) $r->reservation_code,
            'status'           => (string) $r->status,
            'customer_name'    => (string) $r->customer_name,
            'customer_email'   => (string) $r->customer_email,
            'customer_phone'   => (string) $r->customer_phone,
            'party_size'       => (int) $r->party_size,
            'arrival_time'     => $r->arrival_time ? mysql2date( 'c', $r->arrival_time ) : null,
            'area'             => $r->area ? (string) $r->area : '',
            'notes'            => $r->notes ? (string) $r->notes : '',
            'decline_reason'   => isset( $r->decline_reason ) && $r->decline_reason ? (string) $r->decline_reason : '',
            'checked_in_at'    => $r->checked_in_at ? mysql2date( 'c', $r->checked_in_at ) : null,
            'created_at'       => mysql2date( 'c', $r->created_at ),
            'updated_at'       => $r->updated_at ? mysql2date( 'c', $r->updated_at ) : null,
            'extra_fields'     => $extras,
        );
    }

    /**
     * POST /organizer/{slug}/reservations/{id}/{action}
     *  action ∈ approve | decline | check-in | cancel
     *
     * Resolves the reservation, confirms it belongs to one of the organizer's
     * events (defence-in-depth — the row could be edited via SQL), enforces
     * status preconditions, runs the transition through KE_Reservations, and
     * fires the matching customer email. Email failures are logged but never
     * block the state change so an outage on the SMTP side can't strand the
     * dashboard.
     */
    public function organizer_reservation_action( WP_REST_Request $request ) {
        $slug = (string) $request->get_param( 'slug' );
        $term = KE_Organizer_Dashboard::require_session_for_slug( $slug );
        if ( is_wp_error( $term ) ) return $term;

        $id     = (int) $request->get_param( 'id' );
        $action = (string) $request->get_param( 'action' );
        if ( $id <= 0 ) {
            return new WP_Error( 'invalid_id', __( 'Invalid reservation id.', 'kiwi-events' ), array( 'status' => 400 ) );
        }

        $reservations = new KE_Reservations();
        $row = $reservations->get( $id );
        if ( ! $row ) {
            return new WP_Error( 'not_found', __( 'Reservation not found.', 'kiwi-events' ), array( 'status' => 404 ) );
        }

        $event_ids = KE_Organizer_Stats::get_event_ids_for_organizer( $term->term_id );
        if ( ! in_array( (int) $row->event_id, $event_ids, true ) ) {
            return new WP_Error( 'forbidden_reservation', __( 'Reservation not in this organizer.', 'kiwi-events' ), array( 'status' => 403 ) );
        }

        $email_method = '';
        $email_args   = array();
        $extra_update = array();
        $new_status   = '';

        switch ( $action ) {
            case 'approve':
                if ( $row->status !== 'pending' ) {
                    return new WP_Error( 'invalid_transition', __( 'Only pending reservations can be approved.', 'kiwi-events' ), array( 'status' => 409 ) );
                }
                $new_status   = 'confirmed';
                $email_method = 'send_reservation_approved_email';
                break;

            case 'decline':
                if ( $row->status !== 'pending' ) {
                    return new WP_Error( 'invalid_transition', __( 'Only pending reservations can be declined.', 'kiwi-events' ), array( 'status' => 409 ) );
                }
                $reason = sanitize_textarea_field( (string) $request->get_param( 'reason' ) );
                $extra_update['decline_reason'] = $reason;
                $new_status   = 'declined';
                $email_method = 'send_reservation_declined_email';
                break;

            case 'check-in':
                if ( $row->status !== 'confirmed' ) {
                    return new WP_Error( 'invalid_transition', __( 'Only confirmed reservations can be checked in.', 'kiwi-events' ), array( 'status' => 409 ) );
                }
                $checked = $reservations->check_in( $id, get_current_user_id() );
                if ( is_wp_error( $checked ) ) return $checked;
                return rest_ensure_response( array(
                    'success'     => true,
                    'reservation' => $this->format_reservation_row( $checked ),
                ) );

            case 'cancel':
                if ( ! in_array( $row->status, array( 'pending', 'confirmed' ), true ) ) {
                    return new WP_Error( 'invalid_transition', __( 'This reservation cannot be cancelled.', 'kiwi-events' ), array( 'status' => 409 ) );
                }
                $new_status   = 'cancelled_by_venue';
                $email_method = 'send_reservation_cancelled_by_venue_email';
                break;

            default:
                return new WP_Error( 'invalid_action', __( 'Unknown action.', 'kiwi-events' ), array( 'status' => 400 ) );
        }

        $updated = $reservations->update_status( $id, $new_status, $extra_update );
        if ( is_wp_error( $updated ) ) return $updated;

        if ( $email_method && class_exists( 'KE_Email' ) ) {
            try {
                $email = new KE_Email();
                if ( method_exists( $email, $email_method ) ) {
                    call_user_func( array( $email, $email_method ), (int) $updated->id );
                }
            } catch ( \Throwable $e ) {
                error_log( sprintf(
                    '[KiwiEvents] reservation %s email failed for #%d: %s',
                    $email_method, (int) $updated->id, $e->getMessage()
                ) );
            }
        }

        return rest_ensure_response( array(
            'success'     => true,
            'reservation' => $this->format_reservation_row( $updated ),
        ) );
    }

    /* ─── Historias Destacadas (highlights) CRUD ──────────────────────────── */

    /**
     * Write gate: resolve the organizer term AND require the REAL organizer
     * session. require_session_for_slug() lets an admin through for reads
     * (read-only impersonation), so here we additionally reject when the
     * caller isn't the actual session owner — admins cannot modify another
     * organizer's highlights. Returns the term or a WP_Error.
     */
    private function require_highlight_write( $slug ) {
        $term = KE_Organizer_Dashboard::require_session_for_slug( $slug );
        if ( is_wp_error( $term ) ) {
            return $term;
        }
        $auth_id = KE_Organizer_Dashboard::get_authenticated_organizer_id();
        if ( $auth_id !== (int) $term->term_id ) {
            return new WP_Error( 'ke_hl_read_only', __( 'Modo solo lectura: no puedes modificar estas historias.', 'kiwi-events' ), array( 'status' => 403 ) );
        }
        return $term;
    }

    /** GET /organizer/{slug}/highlights — cards for the grid (owner or admin). */
    public function organizer_highlights_list( WP_REST_Request $request ) {
        $slug = (string) $request->get_param( 'slug' );
        $term = KE_Organizer_Dashboard::require_session_for_slug( $slug );
        if ( is_wp_error( $term ) ) {
            return $term;
        }
        $items = array();
        foreach ( KE_Highlights::get_for_organizer( $term->term_id ) as $post ) {
            $card = KE_Highlights::to_card( $post->ID );
            if ( $card ) {
                $items[] = $card;
            }
        }
        // can_edit drives the dashboard's read-only UI for admin impersonation.
        $can_edit = ( KE_Organizer_Dashboard::get_authenticated_organizer_id() === (int) $term->term_id );
        return rest_ensure_response( array( 'items' => $items, 'can_edit' => $can_edit ) );
    }

    /** GET /organizer/{slug}/highlights/{id} — detail for the edit form. */
    public function organizer_highlight_get( WP_REST_Request $request ) {
        $slug = (string) $request->get_param( 'slug' );
        $term = KE_Organizer_Dashboard::require_session_for_slug( $slug );
        if ( is_wp_error( $term ) ) {
            return $term;
        }
        $id = (int) $request->get_param( 'id' );
        if ( ! KE_Highlights::belongs_to_organizer( $id, $term->term_id ) ) {
            return new WP_Error( 'ke_hl_not_found', __( 'Historia no encontrada.', 'kiwi-events' ), array( 'status' => 404 ) );
        }
        $post   = get_post( $id );
        $images = array();
        foreach ( KE_Highlights::get_images( $id ) as $att ) {
            $src = wp_get_attachment_image_src( $att, 'medium' );
            $images[] = array( 'id' => (int) $att, 'url' => $src ? $src[0] : wp_get_attachment_url( $att ) );
        }
        return rest_ensure_response( array(
            'id'     => $id,
            'name'   => $post ? $post->post_title : '',
            'cover'  => KE_Highlights::cover_url( $id ),
            'images' => $images,
        ) );
    }

    /** POST /organizer/{slug}/highlights — create (multipart: name, cover, image_*). */
    public function organizer_highlight_create( WP_REST_Request $request ) {
        $slug = (string) $request->get_param( 'slug' );
        $term = $this->require_highlight_write( $slug );
        if ( is_wp_error( $term ) ) {
            return $term;
        }

        $name = trim( (string) $request->get_param( 'name' ) );
        if ( $name === '' ) {
            return new WP_Error( 'ke_hl_name_required', __( 'El nombre es obligatorio.', 'kiwi-events' ), array( 'status' => 400 ) );
        }

        $files = $request->get_file_params();
        if ( empty( $files['cover'] ) || empty( $files['cover']['tmp_name'] ) ) {
            return new WP_Error( 'ke_hl_cover_required', __( 'La portada es obligatoria.', 'kiwi-events' ), array( 'status' => 400 ) );
        }

        $image_keys = $this->collect_highlight_image_keys( $files );
        if ( empty( $image_keys ) ) {
            return new WP_Error( 'ke_hl_images_required', __( 'Agrega al menos una imagen.', 'kiwi-events' ), array( 'status' => 400 ) );
        }
        if ( count( $image_keys ) > KE_Highlights::MAX_IMAGES ) {
            return new WP_Error( 'ke_hl_too_many', sprintf( __( 'Máximo %d imágenes por historia.', 'kiwi-events' ), KE_Highlights::MAX_IMAGES ), array( 'status' => 400 ) );
        }

        // Validate EVERY file (cover + frames) before creating anything.
        $max_bytes = KE_Highlights::MAX_FILE_MB * 1024 * 1024;
        foreach ( array_merge( array( 'cover' ), $image_keys ) as $key ) {
            $v = KE_Highlights::validate_image_file( $files[ $key ], $max_bytes );
            if ( is_wp_error( $v ) ) {
                return $v;
            }
        }

        $post_id = wp_insert_post( array(
            'post_type'   => KE_Highlights::POST_TYPE,
            'post_status' => 'publish',
            'post_title'  => $name,
            'post_author' => get_current_user_id(),
        ), true );
        if ( is_wp_error( $post_id ) ) {
            return new WP_Error( 'ke_hl_create_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
        }
        update_post_meta( $post_id, KE_Highlights::META_ORGANIZER, (int) $term->term_id );

        $cover_id = KE_Highlights::handle_upload( 'cover', $post_id );
        if ( is_wp_error( $cover_id ) ) {
            wp_delete_post( $post_id, true );
            return new WP_Error( 'ke_hl_cover_failed', $cover_id->get_error_message(), array( 'status' => 400 ) );
        }
        set_post_thumbnail( $post_id, $cover_id );

        $image_ids = array();
        foreach ( $image_keys as $key ) {
            $gid = KE_Highlights::handle_upload( $key, $post_id );
            if ( ! is_wp_error( $gid ) ) {
                $image_ids[] = (int) $gid;
            }
        }
        if ( empty( $image_ids ) ) {
            wp_delete_post( $post_id, true );
            return new WP_Error( 'ke_hl_images_failed', __( 'No se pudieron subir las imágenes.', 'kiwi-events' ), array( 'status' => 400 ) );
        }
        update_post_meta( $post_id, KE_Highlights::META_IMAGES, $image_ids );

        return rest_ensure_response( array( 'success' => true, 'highlight' => KE_Highlights::to_card( $post_id ) ) );
    }

    /** POST /organizer/{slug}/highlights/{id} — update (name, cover, keep_images order, new image_*). */
    public function organizer_highlight_update( WP_REST_Request $request ) {
        $slug = (string) $request->get_param( 'slug' );
        $term = $this->require_highlight_write( $slug );
        if ( is_wp_error( $term ) ) {
            return $term;
        }
        $id = (int) $request->get_param( 'id' );
        if ( ! KE_Highlights::belongs_to_organizer( $id, $term->term_id ) ) {
            return new WP_Error( 'ke_hl_not_found', __( 'Historia no encontrada.', 'kiwi-events' ), array( 'status' => 404 ) );
        }

        $name = trim( (string) $request->get_param( 'name' ) );
        if ( $name !== '' ) {
            wp_update_post( array( 'ID' => $id, 'post_title' => $name ) );
        }

        $max_bytes = KE_Highlights::MAX_FILE_MB * 1024 * 1024;
        $files     = $request->get_file_params();

        // Optional replacement cover.
        if ( ! empty( $files['cover'] ) && ! empty( $files['cover']['tmp_name'] ) ) {
            $v = KE_Highlights::validate_image_file( $files['cover'], $max_bytes );
            if ( is_wp_error( $v ) ) {
                return $v;
            }
            $cover_id = KE_Highlights::handle_upload( 'cover', $id );
            if ( is_wp_error( $cover_id ) ) {
                return new WP_Error( 'ke_hl_cover_failed', $cover_id->get_error_message(), array( 'status' => 400 ) );
            }
            $old_cover = (int) get_post_thumbnail_id( $id );
            set_post_thumbnail( $id, $cover_id );
            if ( $old_cover && $old_cover !== (int) $cover_id ) {
                wp_delete_attachment( $old_cover, true );
            }
        }

        // Kept existing frames (ordered), intersected with what actually exists.
        $current  = KE_Highlights::get_images( $id );
        $keep_raw = (string) $request->get_param( 'keep_images' );
        if ( $request->get_param( 'keep_images' ) === null ) {
            $keep = $current; // no keep param → keep all
        } else {
            $keep = array();
            foreach ( explode( ',', $keep_raw ) as $kid ) {
                $kid = (int) trim( $kid );
                if ( $kid > 0 && in_array( $kid, $current, true ) && ! in_array( $kid, $keep, true ) ) {
                    $keep[] = $kid;
                }
            }
        }

        $image_keys = $this->collect_highlight_image_keys( $files );
        if ( count( $keep ) + count( $image_keys ) > KE_Highlights::MAX_IMAGES ) {
            return new WP_Error( 'ke_hl_too_many', sprintf( __( 'Máximo %d imágenes por historia.', 'kiwi-events' ), KE_Highlights::MAX_IMAGES ), array( 'status' => 400 ) );
        }
        foreach ( $image_keys as $key ) {
            $v = KE_Highlights::validate_image_file( $files[ $key ], $max_bytes );
            if ( is_wp_error( $v ) ) {
                return $v;
            }
        }
        $new_ids = array();
        foreach ( $image_keys as $key ) {
            $gid = KE_Highlights::handle_upload( $key, $id );
            if ( ! is_wp_error( $gid ) ) {
                $new_ids[] = (int) $gid;
            }
        }

        $final = array_merge( $keep, $new_ids );
        if ( empty( $final ) ) {
            return new WP_Error( 'ke_hl_images_required', __( 'La historia debe tener al menos una imagen.', 'kiwi-events' ), array( 'status' => 400 ) );
        }
        update_post_meta( $id, KE_Highlights::META_IMAGES, $final );

        // Delete frames the organizer removed (present before, not kept).
        foreach ( $current as $cid ) {
            if ( ! in_array( (int) $cid, $keep, true ) ) {
                wp_delete_attachment( (int) $cid, true );
            }
        }

        return rest_ensure_response( array( 'success' => true, 'highlight' => KE_Highlights::to_card( $id ) ) );
    }

    /** DELETE /organizer/{slug}/highlights/{id}. */
    public function organizer_highlight_delete( WP_REST_Request $request ) {
        $slug = (string) $request->get_param( 'slug' );
        $term = $this->require_highlight_write( $slug );
        if ( is_wp_error( $term ) ) {
            return $term;
        }
        $id = (int) $request->get_param( 'id' );
        if ( ! KE_Highlights::belongs_to_organizer( $id, $term->term_id ) ) {
            return new WP_Error( 'ke_hl_not_found', __( 'Historia no encontrada.', 'kiwi-events' ), array( 'status' => 404 ) );
        }

        $atts  = KE_Highlights::get_images( $id );
        $cover = (int) get_post_thumbnail_id( $id );
        if ( $cover ) {
            $atts[] = $cover;
        }
        foreach ( array_unique( array_map( 'intval', $atts ) ) as $att ) {
            if ( $att > 0 ) {
                wp_delete_attachment( $att, true );
            }
        }
        wp_delete_post( $id, true );

        return rest_ensure_response( array( 'success' => true, 'id' => $id ) );
    }

    /**
     * Collect uploaded story-frame file keys (image_0, image_1, …) in natural
     * numeric order so the frames keep the order the organizer arranged them.
     */
    private function collect_highlight_image_keys( array $files ) {
        $keys = array();
        foreach ( array_keys( $files ) as $key ) {
            if ( 0 === strpos( $key, 'image_' ) && ! empty( $files[ $key ]['tmp_name'] ) ) {
                $keys[] = $key;
            }
        }
        sort( $keys, SORT_NATURAL );
        return $keys;
    }

    /**
     * POST /admin/reservations/{id}/{action}
     *  action ∈ approve | decline | check-in | cancel
     *
     * Same surface as organizer_reservation_action but skips the
     * organizer-scope check — the wp-admin Reservations page is cross-organizer.
     * Authenticated by admin_permission_check (manage_kiwi_events / admin).
     */
    public function admin_reservation_action( WP_REST_Request $request ) {
        $id     = (int) $request->get_param( 'id' );
        $action = (string) $request->get_param( 'action' );
        if ( $id <= 0 ) {
            return new WP_Error( 'invalid_id', __( 'Invalid reservation id.', 'kiwi-events' ), array( 'status' => 400 ) );
        }

        $reservations = new KE_Reservations();
        $row = $reservations->get( $id );
        if ( ! $row ) {
            return new WP_Error( 'not_found', __( 'Reservation not found.', 'kiwi-events' ), array( 'status' => 404 ) );
        }

        $email_method = '';
        $extra_update = array();
        $new_status   = '';

        switch ( $action ) {
            case 'approve':
                if ( $row->status !== 'pending' ) {
                    return new WP_Error( 'invalid_transition', __( 'Only pending reservations can be approved.', 'kiwi-events' ), array( 'status' => 409 ) );
                }
                $new_status   = 'confirmed';
                $email_method = 'send_reservation_approved_email';
                break;

            case 'decline':
                if ( $row->status !== 'pending' ) {
                    return new WP_Error( 'invalid_transition', __( 'Only pending reservations can be declined.', 'kiwi-events' ), array( 'status' => 409 ) );
                }
                $reason = sanitize_textarea_field( (string) $request->get_param( 'reason' ) );
                $extra_update['decline_reason'] = $reason;
                $new_status   = 'declined';
                $email_method = 'send_reservation_declined_email';
                break;

            case 'check-in':
                if ( $row->status !== 'confirmed' ) {
                    return new WP_Error( 'invalid_transition', __( 'Only confirmed reservations can be checked in.', 'kiwi-events' ), array( 'status' => 409 ) );
                }
                $checked = $reservations->check_in( $id, get_current_user_id() );
                if ( is_wp_error( $checked ) ) return $checked;
                return rest_ensure_response( array(
                    'success'     => true,
                    'reservation' => $this->format_reservation_row( $checked ),
                ) );

            case 'cancel':
                if ( ! in_array( $row->status, array( 'pending', 'confirmed' ), true ) ) {
                    return new WP_Error( 'invalid_transition', __( 'This reservation cannot be cancelled.', 'kiwi-events' ), array( 'status' => 409 ) );
                }
                $new_status   = 'cancelled_by_venue';
                $email_method = 'send_reservation_cancelled_by_venue_email';
                break;

            default:
                return new WP_Error( 'invalid_action', __( 'Unknown action.', 'kiwi-events' ), array( 'status' => 400 ) );
        }

        $updated = $reservations->update_status( $id, $new_status, $extra_update );
        if ( is_wp_error( $updated ) ) return $updated;

        if ( $email_method && class_exists( 'KE_Email' ) ) {
            try {
                $email = new KE_Email();
                if ( method_exists( $email, $email_method ) ) {
                    call_user_func( array( $email, $email_method ), (int) $updated->id );
                }
            } catch ( \Throwable $e ) {
                error_log( sprintf(
                    '[KiwiEvents] admin reservation %s email failed for #%d: %s',
                    $email_method, (int) $updated->id, $e->getMessage()
                ) );
            }
        }

        return rest_ensure_response( array(
            'success'     => true,
            'reservation' => $this->format_reservation_row( $updated ),
        ) );
    }

    /**
     * POST /organizer/{slug}/logout
     * Always returns 200 — the goal is to invalidate the cookie/transient
     * regardless of prior state. Intentionally not slug-bound; clearing a
     * cookie issued for a different organizer is harmless.
     */
    public function organizer_logout( WP_REST_Request $request ) {
        KE_Organizer_Dashboard::clear_session();
        return rest_ensure_response( array( 'success' => true ) );
    }

    /**
     * GET /events/check-slug?slug=foo-bar&exclude_id=123
     *
     * Live validation for the editable slug field. Returns:
     *   { available: bool, reason: 'invalid_format'|'too_long'|'in_use'|'reserved'|null,
     *     normalized: 'sanitized-slug' }
     *
     * Stricter than sanitize_title() — we enforce the rules the slug editor's
     * help text promises (lowercase a-z + 0-9 + hyphens only, no leading/trailing
     * or double hyphens, max 60 chars). sanitize_title() would silently strip
     * accents / uppercase / spaces; here we reject those so the user sees a
     * clear "invalid characters" message instead of a normalized surprise.
     */
    public function check_event_slug( WP_REST_Request $request ) {
        $raw    = (string) $request->get_param( 'slug' );
        $excl   = (int) $request->get_param( 'exclude_id' );
        $slug   = strtolower( trim( $raw ) );

        // The sanitized form is the canonical comparison target for uniqueness;
        // we still validate the raw input against the strict format rules.
        $normalized = sanitize_title( $slug );

        $reason = null;
        if ( $slug === '' ) {
            $reason = 'invalid_format';
        } elseif ( strlen( $slug ) > 60 ) {
            $reason = 'too_long';
        } elseif ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
            // Catches: uppercase, spaces, accents, leading/trailing hyphen, double hyphen, empty segments.
            $reason = 'invalid_format';
        }

        // Reserve a small set of slugs that would collide with KE rewrites
        // (the CPT archive base is "events"; an event slug named "events" would
        // shadow the archive). Keep this list minimal.
        if ( $reason === null ) {
            $reserved = array( 'events', 'event', 'add', 'edit', 'new' );
            if ( in_array( $slug, $reserved, true ) ) {
                $reason = 'reserved';
            }
        }

        if ( $reason === null ) {
            // Uniqueness check via post_name. Limit to ke_event CPT but include
            // every non-trash status so a draft can't silently shadow a publish.
            global $wpdb;
            $where_excl = $excl > 0 ? $wpdb->prepare( ' AND ID != %d', $excl ) : '';
            $clash = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_name = %s
                   AND post_type = 'ke_event'
                   AND post_status != 'trash'"
                . $where_excl,
                $slug
            ) );
            if ( $clash > 0 ) {
                $reason = 'in_use';
            }
        }

        return rest_ensure_response( array(
            'available'  => ( $reason === null ),
            'reason'     => $reason,
            'normalized' => $normalized,
        ) );
    }
}
