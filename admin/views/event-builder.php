<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Enqueue styles for the builder
wp_enqueue_style( 'ke-event-builder-css', KE_PLUGIN_URL . 'admin/css/ke-event-builder.css', array(), KE_VERSION );

// Ensure WP media uploader is loaded
wp_enqueue_media();

wp_enqueue_script( 'ke-event-builder-js', KE_PLUGIN_URL . 'admin/js/ke-event-builder.js', array( 'jquery' ), KE_VERSION, true );
wp_localize_script( 'ke-event-builder-js', 'keBuilderData', array(
    'restUrl' => esc_url_raw( rest_url( 'ke/v1/' ) ),
    'nonce'   => wp_create_nonce( 'wp_rest' ),
    'homeUrl' => home_url(),
));

$event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
$post = null;
$meta = array();
$categories = array();
$organizer_id = 0;
$tickets_json = '[]';
$thumbnail_id = 0;
$thumbnail_url = '';

if ( $event_id > 0 ) {
    $post = get_post( $event_id );
    if ( $post && $post->post_type === 'ke_event' ) {
        $meta = get_post_meta( $event_id );
        
        $terms = wp_get_post_terms( $event_id, 'ke_event_category', array( 'fields' => 'ids' ) );
        $categories = is_array($terms) ? $terms : array();

        $orgs = wp_get_post_terms( $event_id, 'ke_organizer', array( 'fields' => 'ids' ) );
        $organizer_id = !empty($orgs) ? $orgs[0] : 0;

        $ticket_types = new KE_Ticket_Types();
        $tickets_raw = $ticket_types->get_by_event( $event_id );
        $tickets_array = array();
        foreach ( $tickets_raw as $t ) {
            $tickets_array[] = array(
                'name'  => $t->name,
                'desc'  => $t->description,
                'price' => $t->price,
                'qty'   => $t->quantity_total
            );
        }
        $tickets_json = wp_json_encode( $tickets_array );

        $thumbnail_id = get_post_thumbnail_id( $event_id );
        if ( $thumbnail_id ) {
            $thumbnail_url = wp_get_attachment_image_url( $thumbnail_id, 'large' );
        }
    }
}

// Helpers
function ke_get_meta_val($meta_array, $key, $default = '') {
    return isset($meta_array[$key]) ? $meta_array[$key][0] : $default;
}

$date_start    = ke_get_meta_val( $meta, '_ke_event_date_start' );
$date_end      = ke_get_meta_val( $meta, '_ke_event_date_end' );
$timezone      = ke_get_meta_val( $meta, '_ke_event_timezone', wp_timezone_string() );
$location_type = ke_get_meta_val( $meta, '_ke_event_location_type', 'venue' );
$venue         = ke_get_meta_val( $meta, '_ke_event_venue' );
$address       = ke_get_meta_val( $meta, '_ke_event_address' );
$virtual_url   = ke_get_meta_val( $meta, '_ke_event_virtual_url' );
$capacity      = ke_get_meta_val( $meta, '_ke_event_capacity' );
$max_tickets   = ke_get_meta_val( $meta, '_ke_event_max_tickets_per_person', 10 );
$maps_embed    = ke_get_meta_val( $meta, '_ke_event_maps_embed' );
$event_service_fee_id = ke_get_meta_val( $meta, '_ke_event_service_fee_id' );
?>

<div class="wrap ke-builder-wrap">
    
    <div class="ke-builder-header">
        <div class="ke-builder-title">
            <a href="<?php echo admin_url('edit.php?post_type=ke_event'); ?>" class="back-link">
                <span class="dashicons dashicons-arrow-left-alt2"></span> Back
            </a>
            <h1><?php echo $event_id ? 'Edit Event' : 'Create New Event'; ?></h1>
        </div>
        <div class="ke-builder-actions">
            <?php if ( ! $event_id ) : ?>
                <button id="ke-builder-save-draft" class="ke-btn ke-btn-ghost">Save Draft</button>
            <?php endif; ?>
            <button id="ke-builder-publish" class="ke-btn ke-btn-primary"><?php echo $event_id ? 'Save Edits' : 'Publish Event'; ?></button>
        </div>
    </div>

    <div class="ke-builder-main">
        <!-- Stepper Sidebar -->
        <aside class="ke-builder-sidebar">
            <ul class="ke-stepper">
                <li class="ke-step active" data-step="1">
                    <span class="step-icon">1</span>
                    <span class="step-label">Event Basics</span>
                </li>
                <li class="ke-step" data-step="2">
                    <span class="step-icon">2</span>
                    <span class="step-label">Time & Location</span>
                </li>
                <li class="ke-step" data-step="3">
                    <span class="step-icon">3</span>
                    <span class="step-label">Taxonomy & Details</span>
                </li>
                <li class="ke-step" data-step="4">
                    <span class="step-icon">4</span>
                    <span class="step-label">Ticketing & Capacity</span>
                </li>
            </ul>
        </aside>

        <!-- Builder Content -->
        <div class="ke-builder-content">
            <form id="ke-event-builder-form">
                <input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>">
                
                <!-- STEP 1: BASICS -->
                <div class="ke-step-panel active" id="step-1">
                    <h2>Event Basics</h2>
                    <p class="step-desc">Start by giving your event a catchy title and an eye-catching banner.</p>

                    <div class="form-group">
                        <label>Event Name <span>*</span></label>
                        <input type="text" name="event_title" class="ke-input-large" placeholder="E.g., Summer Music Festival 2026" value="<?php echo esc_attr( $post ? $post->post_title : '' ); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Event Banner Image</label>
                        <div class="ke-image-uploader" id="ke-banner-uploader">
                            <div class="uploader-preview" style="<?php echo $thumbnail_url ? 'background-image:url('.esc_url($thumbnail_url).');' : ''; ?>"></div>
                            <button type="button" class="ke-btn ke-btn-ghost">Choose Image</button>
                            <input type="hidden" name="event_banner_id" id="event_banner_id" value="<?php echo esc_attr( $thumbnail_id ); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Event Description</label>
                        <?php 
                        wp_editor( $post ? $post->post_content : '', 'event_description', array(
                            'media_buttons' => false,
                            'textarea_rows' => 8,
                            'teeny'         => true,
                        ) ); 
                        ?>
                    </div>
                </div>

                <!-- STEP 2: TIME & LOCATION -->
                <div class="ke-step-panel" id="step-2">
                    <h2>Time & Location</h2>
                    <p class="step-desc">When and where is this event taking place?</p>

                    <div class="ke-flex-row">
                        <div class="form-group half">
                            <label>Start Date & Time <span>*</span></label>
                            <input type="datetime-local" name="event_start" value="<?php echo esc_attr( $date_start ); ?>" required>
                        </div>
                        <div class="form-group half">
                            <label>End Date & Time <span>*</span></label>
                            <input type="datetime-local" name="event_end" value="<?php echo esc_attr( $date_end ); ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Timezone <span>*</span></label>
                        <select name="event_timezone">
                            <?php foreach ( timezone_identifiers_list() as $tz ) : ?>
                                <option value="<?php echo esc_attr($tz); ?>" <?php selected( $timezone, $tz ); ?>><?php echo esc_html(str_replace('_', ' ', $tz)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <hr>

                    <div class="form-group">
                        <label>Location Type</label>
                        <div class="ke-toggle-group">
                            <label><input type="radio" name="location_type" value="venue" <?php checked( $location_type, 'venue' ); ?>> In-Person</label>
                            <label><input type="radio" name="location_type" value="virtual" <?php checked( $location_type, 'virtual' ); ?>> Virtual</label>
                            <label><input type="radio" name="location_type" value="hybrid" <?php checked( $location_type, 'hybrid' ); ?>> Hybrid</label>
                        </div>
                    </div>

                    <div id="venue-fields" class="conditional-fields" style="<?php echo $location_type === 'virtual' ? 'display:none;' : ''; ?>">
                        <div class="form-group">
                            <label>Venue Name</label>
                            <input type="text" name="event_venue" value="<?php echo esc_attr( $venue ); ?>" placeholder="E.g., Central Park">
                        </div>
                        <div class="form-group">
                            <label>Address</label>
                            <input type="text" name="event_address" value="<?php echo esc_attr( $address ); ?>" placeholder="E.g., 123 Main St">
                        </div>
                    </div>

                    <div id="virtual-fields" class="conditional-fields" style="<?php echo $location_type === 'venue' ? 'display:none;' : ''; ?>">
                        <div class="form-group">
                            <label>Virtual Event URL</label>
                            <input type="url" name="event_virtual_url" value="<?php echo esc_attr( $virtual_url ); ?>" placeholder="E.g., https://zoom.us/...">
                            <small>Will be shared with ticket holders automatically.</small>
                        </div>
                    </div>
                </div>

                <!-- STEP 3: TAXONOMY & DETAILS -->
                <div class="ke-step-panel" id="step-3">
                    <h2>Taxonomy & Additional Details</h2>
                    <p class="step-desc">Categorize your event to help attendees find it.</p>

                    <div class="form-group">
                        <label>Categories</label>
                        <?php 
                        $cats = get_terms( array( 'taxonomy' => 'ke_event_category', 'hide_empty' => false ) );
                        if ( ! empty( $cats ) && ! is_wp_error( $cats ) ) {
                            echo '<div class="ke-checkbox-grid">';
                            foreach ( $cats as $cat ) {
                                $checked = in_array( $cat->term_id, $categories ) ? 'checked' : '';
                                echo '<label><input type="checkbox" name="event_categories[]" value="' . esc_attr( $cat->term_id ) . '" ' . $checked . '> ' . esc_html( $cat->name ) . '</label>';
                            }
                            echo '</div>';
                        } else {
                            echo '<p>No categories found. Create some in the Categories menu.</p>';
                        }
                        ?>
                    </div>

                    <div class="form-group">
                        <label>Organizer</label>
                        <?php
                        $organizers = get_terms( array( 'taxonomy' => 'ke_organizer', 'hide_empty' => false ) );
                        if ( ! empty( $organizers ) && ! is_wp_error( $organizers ) ) {
                            echo '<select name="event_organizer"><option value="">Select Organizer...</option>';
                            foreach ( $organizers as $org ) {
                                $selected = $org->term_id == $organizer_id ? 'selected' : '';
                                echo '<option value="' . esc_attr( $org->term_id ) . '" ' . $selected . '>' . esc_html( $org->name ) . '</option>';
                            }
                            echo '</select>';
                        } else {
                            echo '<p>No organizers found.</p>';
                        }
                        ?>
                    </div>

                    <div class="form-group">
                        <label>Service Fee</label>
                        <?php
                        $all_fees = get_option( 'ke_service_fees', array() );
                        if ( ! empty( $all_fees ) ) {
                            echo '<select name="event_service_fee_id">';
                            echo '<option value="">No service fee</option>';
                            foreach ( $all_fees as $fee ) {
                                $sel = ( $fee['id'] === $event_service_fee_id ) ? 'selected' : '';
                                $label = esc_html( $fee['name'] );
                                if ( $fee['type'] === 'formula' ) {
                                    $label .= ' — ' . number_format( floatval( $fee['percentage'] ), 2 ) . '% + $' . number_format( floatval( $fee['fixed_amount'] ), 2 );
                                } else {
                                    $label .= ' — $' . number_format( floatval( $fee['fixed_amount'] ), 2 ) . ' fixed';
                                }
                                echo '<option value="' . esc_attr( $fee['id'] ) . '" ' . $sel . '>' . $label . '</option>';
                            }
                            echo '</select>';
                            echo '<small>Fee shown to attendees during checkout. Manage fees in <a href="' . admin_url('admin.php?page=ke-settings') . '">System Settings</a>.</small>';
                        } else {
                            echo '<p class="ke-muted">No fees defined yet. <a href="' . admin_url('admin.php?page=ke-settings') . '">Create a service fee</a> first.</p>';
                        }
                        ?>
                    </div>

                    <div class="form-group">
                        <label>Google Maps Embed</label>
                        <textarea name="event_maps_embed" rows="4" placeholder='Paste the &lt;iframe&gt; embed code from Google Maps → Share → Embed a map. A plain Google Maps URL or [googlemaps ...] shortcode also works.'><?php echo esc_textarea( $maps_embed ); ?></textarea>
                        <small>In Google Maps: Share → Embed a map → Copy HTML. Paste the full &lt;iframe&gt; tag here. Plain URLs and shortcodes are also accepted.</small>
                    </div>
                </div>

                <!-- STEP 4: TICKETING -->
                <div class="ke-step-panel" id="step-4">
                    <h2>Ticketing & Capacity</h2>
                    <p class="step-desc">Configure your tickets, pricing, and availability.</p>

                    <div class="ke-flex-row">
                        <div class="form-group half">
                            <label>Total Event Capacity</label>
                            <input type="number" name="event_capacity" value="<?php echo esc_attr( $capacity ); ?>" placeholder="0 = unlimited">
                        </div>
                        <div class="form-group half">
                            <label>Max Tickets per Person</label>
                            <input type="number" name="event_max_tickets" value="<?php echo esc_attr( $max_tickets ); ?>">
                        </div>
                    </div>

                    <hr>

                    <h3>Ticket Types</h3>
                    <div id="ke-ticket-types-container">
                        <!-- Ticket types will be rendered here dynamically via JS -->
                        <div class="ke-empty-state">No tickets added yet.</div>
                    </div>
                    
                    <button type="button" class="ke-btn ke-btn-ghost" id="ke-add-ticket-type">
                        + Add Ticket Type
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Inject preloaded ticket types
    const existingTickets = <?php echo $tickets_json; ?>;
    if (existingTickets && existingTickets.length > 0) {
        // Find the 'add' button via jQuery which is already loaded by ke-event-builder.js
        const container = jQuery('#ke-ticket-types-container');
        container.empty(); // Remove empty state
        
        existingTickets.forEach(function(t, i) {
            // Escape any quotes/HTML in the desc value so it's safe in an attribute
            const descVal = (t.desc || '').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const html = `
                <div class="ke-ticket-card" style="position:relative;">
                    <button type="button" class="btn-remove-ticket" title="Remove">×</button>
                    <div class="form-group" style="margin:0;">
                        <label>Ticket Name</label>
                        <input type="text" name="tickets[${i}][name]" value="${t.name}" placeholder="e.g. Early Bird" required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Description</label>
                        <input type="text" name="tickets[${i}][desc]" value="${descVal}" placeholder="e.g. Includes 2 drinks (optional)">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Price ($)</label>
                        <input type="number" step="0.01" name="tickets[${i}][price]" value="${t.price}" placeholder="0 = Free">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Quantity</label>
                        <input type="number" name="tickets[${i}][qty]" value="${t.qty}" placeholder="Total">
                    </div>
                </div>
            `;
            container.append(html);
        });
    }
});
</script>
