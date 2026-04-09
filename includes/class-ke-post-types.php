<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register Custom Post Type, Taxonomies, and meta boxes for events
 */
class KE_Post_Types {

    /**
     * Hook into WordPress
     */
    public function init() {
        add_action( 'init', array( $this, 'register_post_type' ) );
        add_action( 'init', array( $this, 'register_taxonomies' ) );
        add_action( 'init', array( $this, 'register_ticket_rewrite' ), 1 );
        add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post_ke_event', array( $this, 'save_meta' ), 10, 2 );
        add_filter( 'enter_title_here', array( $this, 'custom_title_placeholder' ), 10, 2 );
    }

    /**
     * Rewrite rule: /ticket/{sha256-hex} → ke_ticket_code query var.
     * Priority 1 ensures it fires before WordPress parses the request.
     * add_rewrite_tag() registers the query var early alongside the rule.
     * Version-based flush runs once after deploy — avoids per-request overhead.
     */
    public function register_ticket_rewrite() {
        add_rewrite_rule(
            '^ticket/([a-f0-9]+)/?$',
            'index.php?ke_ticket_code=$matches[1]',
            'top'
        );
        add_rewrite_tag( '%ke_ticket_code%', '([a-f0-9]+)' );

        // Flush once when the rule version changes (no manual permalink save needed)
        if ( get_option( 'ke_rewrite_version', '' ) !== '1.0.1' ) {
            flush_rewrite_rules( false );
            update_option( 'ke_rewrite_version', '1.0.1' );
        }
    }

    /**
     * Expose ke_ticket_code as a public query var
     */
    public function register_query_vars( $vars ) {
        $vars[] = 'ke_ticket_code';
        return $vars;
    }

    /**
     * Register ke_event Custom Post Type
     */
    public function register_post_type() {
        $labels = array(
            'name'                  => 'Events',
            'singular_name'         => 'Event',
            'menu_name'             => 'KiwiEvents',
            'name_admin_bar'        => 'Event',
            'add_new'               => 'Add New',
            'add_new_item'          => 'Add New Event',
            'new_item'              => 'New Event',
            'edit_item'             => 'Edit Event',
            'view_item'             => 'View Event',
            'all_items'             => 'All Events',
            'search_items'          => 'Search Events',
            'not_found'             => 'No events found.',
            'not_found_in_trash'    => 'No events found in trash.',
            'featured_image'        => 'Event Banner',
            'set_featured_image'    => 'Set event banner',
            'remove_featured_image' => 'Remove event banner',
            'use_featured_image'    => 'Use as event banner',
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => false,
            'query_var'          => true,
            'rewrite'            => array( 'slug' => 'events' ),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => null,
            'menu_icon'          => 'dashicons-calendar-alt',
            'supports'           => array( 'title', 'excerpt' ),
            'show_in_rest'       => true,
        );

        register_post_type( 'ke_event', $args );
    }

    /**
     * Register taxonomies: categories, tags, organizers
     */
    public function register_taxonomies() {
        // Event Categories (hierarchical)
        register_taxonomy( 'ke_event_category', 'ke_event', array(
            'labels' => array(
                'name'              => 'Event Categories',
                'singular_name'     => 'Event Category',
                'search_items'      => 'Search Categories',
                'all_items'         => 'All Categories',
                'parent_item'       => 'Parent Category',
                'parent_item_colon' => 'Parent Category:',
                'edit_item'         => 'Edit Category',
                'update_item'       => 'Update Category',
                'add_new_item'      => 'Add New Category',
                'new_item_name'     => 'New Category Name',
                'menu_name'         => 'Categories',
            ),
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'event-category' ),
            'show_in_rest'      => true,
        ) );

        // Event Tags (non-hierarchical)
        register_taxonomy( 'ke_event_tag', 'ke_event', array(
            'labels' => array(
                'name'                       => 'Event Tags',
                'singular_name'              => 'Event Tag',
                'search_items'               => 'Search Tags',
                'popular_items'              => 'Popular Tags',
                'all_items'                  => 'All Tags',
                'edit_item'                  => 'Edit Tag',
                'update_item'                => 'Update Tag',
                'add_new_item'               => 'Add New Tag',
                'new_item_name'              => 'New Tag Name',
                'separate_items_with_commas' => 'Separate tags with commas',
                'add_or_remove_items'        => 'Add or remove tags',
                'choose_from_most_used'      => 'Choose from the most used tags',
                'menu_name'                  => 'Tags',
            ),
            'hierarchical'      => false,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'event-tag' ),
            'show_in_rest'      => true,
        ) );

        // Organizers (hierarchical for dropdown selection)
        register_taxonomy( 'ke_organizer', 'ke_event', array(
            'labels' => array(
                'name'              => 'Organizers',
                'singular_name'     => 'Organizer',
                'search_items'      => 'Search Organizers',
                'all_items'         => 'All Organizers',
                'edit_item'         => 'Edit Organizer',
                'update_item'       => 'Update Organizer',
                'add_new_item'      => 'Add New Organizer',
                'new_item_name'     => 'New Organizer Name',
                'menu_name'         => 'Organizers',
            ),
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'event-organizer' ),
            'show_in_rest'      => true,
        ) );
    }

    /**
     * Add meta boxes for event details
     */
    public function add_meta_boxes() {
        add_meta_box(
            'ke_event_details',
            '📋 Event Details',
            array( $this, 'render_event_details_meta_box' ),
            'ke_event',
            'normal',
            'high'
        );
    }

    /**
     * Render the event details meta box
     */
    public function render_event_details_meta_box( $post ) {
        wp_nonce_field( 'ke_event_details_nonce', 'ke_event_nonce' );

        $date_start     = get_post_meta( $post->ID, '_ke_event_date_start', true );
        $date_end       = get_post_meta( $post->ID, '_ke_event_date_end', true );
        $timezone       = get_post_meta( $post->ID, '_ke_event_timezone', true );
        $location_type  = get_post_meta( $post->ID, '_ke_event_location_type', true );
        $venue          = get_post_meta( $post->ID, '_ke_event_venue', true );
        $address        = get_post_meta( $post->ID, '_ke_event_address', true );
        $virtual_url    = get_post_meta( $post->ID, '_ke_event_virtual_url', true );
        $capacity       = get_post_meta( $post->ID, '_ke_event_capacity', true );
        $max_tickets    = get_post_meta( $post->ID, '_ke_event_max_tickets_per_person', true );
        $event_status   = get_post_meta( $post->ID, '_ke_event_status', true );

        if ( empty( $timezone ) ) {
            $timezone = wp_timezone_string();
        }
        if ( empty( $location_type ) ) {
            $location_type = 'venue';
        }
        if ( empty( $max_tickets ) ) {
            $max_tickets = get_option( 'ke_default_ticket_limit', 10 );
        }
        if ( empty( $event_status ) ) {
            $event_status = 'active';
        }

        // Get timezone list
        $tz_list = timezone_identifiers_list();
        ?>
        <style>
            .ke-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
            .ke-meta-field { margin-bottom: 4px; }
            .ke-meta-field label { display: block; font-weight: 600; margin-bottom: 4px; color: #1d2327; font-size: 13px; }
            .ke-meta-field .ke-help { display: block; font-size: 11px; color: #646970; margin-top: 2px; }
            .ke-meta-field input,
            .ke-meta-field select,
            .ke-meta-field textarea { width: 100%; padding: 8px 10px; border: 1px solid #8c8f94; border-radius: 6px; font-size: 13px; }
            .ke-meta-field input:focus,
            .ke-meta-field select:focus { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; outline: none; }
            .ke-meta-full { grid-column: 1 / -1; }
            .ke-meta-section-title { grid-column: 1 / -1; font-size: 14px; font-weight: 700; color: #1d2327; border-bottom: 2px solid #e0e0e8; padding-bottom: 8px; margin-top: 12px; display: flex; align-items: center; gap: 6px; }
            .ke-location-tabs { grid-column: 1 / -1; display: flex; gap: 0; margin-bottom: 4px; }
            .ke-location-tab { padding: 8px 20px; border: 1px solid #c3c4c7; background: #f6f7f7; font-size: 13px; font-weight: 600; cursor: pointer; color: #50575e; transition: all 0.2s; }
            .ke-location-tab:first-child { border-radius: 6px 0 0 6px; }
            .ke-location-tab:last-child { border-radius: 0 6px 6px 0; }
            .ke-location-tab:not(:first-child) { border-left: none; }
            .ke-location-tab.active { background: #2271b1; color: #fff; border-color: #2271b1; }
            .ke-location-panel { display: none; grid-column: 1 / -1; }
            .ke-location-panel.active { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        </style>

        <div class="ke-meta-grid">
            <!-- Date & Time -->
            <div class="ke-meta-section-title">📅 Date & Time</div>
            <div class="ke-meta-field">
                <label for="ke_date_start">Start Date & Time *</label>
                <input type="datetime-local" id="ke_date_start" name="ke_date_start" value="<?php echo esc_attr( $date_start ); ?>" required>
            </div>
            <div class="ke-meta-field">
                <label for="ke_date_end">End Date & Time *</label>
                <input type="datetime-local" id="ke_date_end" name="ke_date_end" value="<?php echo esc_attr( $date_end ); ?>" required>
            </div>
            <div class="ke-meta-field">
                <label for="ke_timezone">Time Zone *</label>
                <select id="ke_timezone" name="ke_timezone">
                    <?php foreach ( $tz_list as $tz ) : ?>
                        <option value="<?php echo esc_attr( $tz ); ?>" <?php selected( $timezone, $tz ); ?>><?php echo esc_html( str_replace( '_', ' ', $tz ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ke-meta-field">
                <label for="ke_event_status">Event Status</label>
                <select id="ke_event_status" name="ke_event_status">
                    <option value="active" <?php selected( $event_status, 'active' ); ?>>Active</option>
                    <option value="cancelled" <?php selected( $event_status, 'cancelled' ); ?>>Cancelled</option>
                    <option value="postponed" <?php selected( $event_status, 'postponed' ); ?>>Postponed</option>
                    <option value="completed" <?php selected( $event_status, 'completed' ); ?>>Completed</option>
                </select>
            </div>

            <!-- Location -->
            <div class="ke-meta-section-title">📍 Location</div>
            <div class="ke-location-tabs">
                <div class="ke-location-tab <?php echo $location_type === 'venue' ? 'active' : ''; ?>" data-type="venue">🏛 Venue</div>
                <div class="ke-location-tab <?php echo $location_type === 'virtual' ? 'active' : ''; ?>" data-type="virtual">💻 Virtual</div>
                <div class="ke-location-tab <?php echo $location_type === 'hybrid' ? 'active' : ''; ?>" data-type="hybrid">🔄 Hybrid</div>
            </div>
            <input type="hidden" id="ke_location_type" name="ke_location_type" value="<?php echo esc_attr( $location_type ); ?>">

            <!-- Venue Panel -->
            <div class="ke-location-panel <?php echo in_array( $location_type, array( 'venue', 'hybrid' ) ) ? 'active' : ''; ?>" id="ke-panel-venue">
                <div class="ke-meta-field">
                    <label for="ke_venue">Venue Name</label>
                    <input type="text" id="ke_venue" name="ke_venue" value="<?php echo esc_attr( $venue ); ?>" placeholder="e.g. Convention Center">
                </div>
                <div class="ke-meta-field">
                    <label for="ke_address">Address</label>
                    <input type="text" id="ke_address" name="ke_address" value="<?php echo esc_attr( $address ); ?>" placeholder="e.g. 123 Main St, City, State">
                </div>
            </div>

            <!-- Virtual Panel -->
            <div class="ke-location-panel <?php echo in_array( $location_type, array( 'virtual', 'hybrid' ) ) ? 'active' : ''; ?>" id="ke-panel-virtual">
                <div class="ke-meta-field ke-meta-full">
                    <label for="ke_virtual_url">Virtual Event URL</label>
                    <input type="url" id="ke_virtual_url" name="ke_virtual_url" value="<?php echo esc_attr( $virtual_url ); ?>" placeholder="e.g. https://zoom.us/j/123456789">
                    <span class="ke-help">Link will be shared with attendees after ticket purchase</span>
                </div>
            </div>

            <!-- Capacity -->
            <div class="ke-meta-section-title">🎟️ Capacity & Limits</div>
            <div class="ke-meta-field">
                <label for="ke_capacity">Total Capacity</label>
                <input type="number" id="ke_capacity" name="ke_capacity" value="<?php echo esc_attr( $capacity ); ?>" min="0" placeholder="0 = unlimited">
                <span class="ke-help">Leave at 0 for unlimited capacity</span>
            </div>
            <div class="ke-meta-field">
                <label for="ke_max_tickets">Max Tickets Per Person</label>
                <input type="number" id="ke_max_tickets" name="ke_max_tickets" value="<?php echo esc_attr( $max_tickets ); ?>" min="1" max="100">
                <span class="ke-help">Maximum tickets a single person can purchase</span>
            </div>
        </div>

        <script>
        (function() {
            var tabs = document.querySelectorAll('.ke-location-tab');
            var typeInput = document.getElementById('ke_location_type');

            tabs.forEach(function(tab) {
                tab.addEventListener('click', function() {
                    tabs.forEach(function(t) { t.classList.remove('active'); });
                    tab.classList.add('active');
                    var type = tab.dataset.type;
                    typeInput.value = type;

                    var venuePanel = document.getElementById('ke-panel-venue');
                    var virtualPanel = document.getElementById('ke-panel-virtual');

                    venuePanel.classList.remove('active');
                    virtualPanel.classList.remove('active');

                    if (type === 'venue' || type === 'hybrid') venuePanel.classList.add('active');
                    if (type === 'virtual' || type === 'hybrid') virtualPanel.classList.add('active');
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Save event meta data
     */
    public function save_meta( $post_id, $post ) {
        if ( ! isset( $_POST['ke_event_nonce'] ) || ! wp_verify_nonce( $_POST['ke_event_nonce'], 'ke_event_details_nonce' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $fields = array(
            'ke_date_start'     => '_ke_event_date_start',
            'ke_date_end'       => '_ke_event_date_end',
            'ke_timezone'       => '_ke_event_timezone',
            'ke_location_type'  => '_ke_event_location_type',
            'ke_venue'          => '_ke_event_venue',
            'ke_address'        => '_ke_event_address',
            'ke_virtual_url'    => '_ke_event_virtual_url',
            'ke_capacity'       => '_ke_event_capacity',
            'ke_max_tickets'    => '_ke_event_max_tickets_per_person',
            'ke_event_status'   => '_ke_event_status',
        );

        foreach ( $fields as $post_key => $meta_key ) {
            if ( isset( $_POST[ $post_key ] ) ) {
                $value = sanitize_text_field( $_POST[ $post_key ] );

                if ( $meta_key === '_ke_event_virtual_url' ) {
                    $value = esc_url_raw( $_POST[ $post_key ] );
                }

                if ( in_array( $meta_key, array( '_ke_event_capacity', '_ke_event_max_tickets_per_person' ) ) ) {
                    $value = absint( $value );
                }

                update_post_meta( $post_id, $meta_key, $value );
            }
        }
    }

    /**
     * Custom title placeholder
     */
    public function custom_title_placeholder( $title, $post ) {
        if ( $post->post_type === 'ke_event' ) {
            return 'Write your event name';
        }
        return $title;
    }
}
