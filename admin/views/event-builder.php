<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Use the dedicated KE_BUILDER_ASSETS_VER so wizard CSS/JS changes
// bust the WordPress.com edge cache without requiring a full
// KE_VERSION bump (which would invalidate every admin asset).
$ke_builder_assets_ver = defined( 'KE_BUILDER_ASSETS_VER' ) ? KE_BUILDER_ASSETS_VER : KE_VERSION;
wp_enqueue_style( 'ke-event-builder-css', KE_PLUGIN_URL . 'admin/css/ke-event-builder.css', array(), $ke_builder_assets_ver );
wp_enqueue_media();
wp_enqueue_script( 'ke-event-builder-js', KE_PLUGIN_URL . 'admin/js/ke-event-builder.js', array( 'jquery' ), $ke_builder_assets_ver, true );
wp_localize_script( 'ke-event-builder-js', 'keBuilderData', array(
    'restUrl' => esc_url_raw( rest_url( 'ke/v1/' ) ),
    'nonce'   => wp_create_nonce( 'wp_rest' ),
    'homeUrl' => home_url(),
) );

$event_id     = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
$post         = null;
$meta         = array();
$categories   = array();
$organizer_id = 0;
$thumbnail_id = 0;
$thumbnail_url = '';
$tickets_json = '[]';
$fees_json    = '[]';

if ( $event_id > 0 ) {
    $post = get_post( $event_id );
    if ( $post && $post->post_type === 'ke_event' ) {
        $meta = get_post_meta( $event_id );
        $terms = wp_get_post_terms( $event_id, 'ke_event_category', array( 'fields' => 'ids' ) );
        $categories = is_array( $terms ) ? $terms : array();
        $orgs = wp_get_post_terms( $event_id, 'ke_organizer', array( 'fields' => 'ids' ) );
        $organizer_id = ! empty( $orgs ) ? $orgs[0] : 0;
        $thumbnail_id = get_post_thumbnail_id( $event_id );
        if ( $thumbnail_id ) {
            $thumbnail_url = wp_get_attachment_image_url( $thumbnail_id, 'large' );
        }
        $ticket_types = new KE_Ticket_Types();
        $tickets_raw  = $ticket_types->get_by_event( $event_id );
        $tickets_arr  = array();
        foreach ( $tickets_raw as $t ) {
            // sale_end is the per-ticket-type sales cutoff (nullable). The
            // datetime-local input wants "Y-m-d\TH:i", and is_closed is
            // computed server-side so the "Ventas cerradas" indicator never
            // drifts against the user's browser clock / timezone.
            $tickets_arr[] = array(
                'id'             => (int) $t->id,
                'name'           => $t->name,
                'desc'           => $t->description,
                'ticket_type'    => $t->ticket_type ?? 'free',
                'price'          => (float) $t->price,
                'capacity_type'  => $t->capacity_type ?? 'limited',
                'qty'            => (int) $t->quantity_total,
                'quantity_sold'  => (int) ( $t->quantity_sold ?? 0 ),
                'status'         => $t->status ?? 'active',
                'min_per_order'  => (int) ( $t->min_per_order ?? 1 ),
                'max_per_order'  => (int) ( $t->max_per_order ?? 10 ),
                'show_remaining' => $t->show_remaining ?? 'yes',
                'sale_end'       => KE_Ticket_Types::format_sale_end_for_input( $t->sale_end ?? '' ),
                'is_closed'      => KE_Ticket_Types::is_sales_closed( $t ),
            );
        }
        $tickets_json = wp_json_encode( $tickets_arr );
    }
}

$all_fees = get_option( 'ke_service_fees', array() );
if ( ! empty( $all_fees ) ) {
    $fees_json = wp_json_encode( array_values( $all_fees ) );
}

// Per-event Extra Fields config (university name, shirt size, etc.).
// Default to disabled-with-no-fields so the builder JS can boot in a clean
// state for new events.
$extra_fields_cfg  = ( $event_id > 0 && class_exists( 'KE_Event_Extra_Fields' ) )
    ? KE_Event_Extra_Fields::get_config( $event_id )
    : array( 'enabled' => false, 'fields' => array() );
$extra_fields_json = wp_json_encode( $extra_fields_cfg );

// Reservations config — defaults supplied by KE_Reservations so the builder
// JS can hydrate a clean form for events that have never had reservations.
$reservations_cfg  = ( $event_id > 0 && class_exists( 'KE_Reservations' ) )
    ? KE_Reservations::get_config( $event_id )
    : ( class_exists( 'KE_Reservations' ) ? KE_Reservations::default_config() : array( 'enabled' => false ) );
$reservations_json = wp_json_encode( $reservations_cfg );

$extras_json = '[]';
if ( $event_id > 0 ) {
    $extras_raw = get_post_meta( $event_id, '_ke_event_extras', true );
    if ( is_array( $extras_raw ) ) {
        // Resolve photo URLs for editors so the admin can preview existing
        // photos without an extra AJAX round-trip.
        foreach ( $extras_raw as &$_extra ) {
            $_type = $_extra['type'] ?? '';
            if ( $_type === 'lineup' && is_array( $_extra['config']['artists'] ?? null ) ) {
                foreach ( $_extra['config']['artists'] as &$_artist ) {
                    $_pid = (int) ( $_artist['photo_id'] ?? 0 );
                    $_artist['photo_url'] = $_pid ? (string) wp_get_attachment_image_url( $_pid, 'thumbnail' ) : '';
                }
                unset( $_artist );
            }
            if ( $_type === 'gallery' ) {
                // Normalize legacy photo_ids → photos[{photo_id, caption}].
                if ( empty( $_extra['config']['photos'] ) && is_array( $_extra['config']['photo_ids'] ?? null ) ) {
                    $_extra['config']['photos'] = array_map( function ( $pid ) {
                        return array( 'photo_id' => (int) $pid, 'caption' => '' );
                    }, $_extra['config']['photo_ids'] );
                }
                if ( is_array( $_extra['config']['photos'] ?? null ) ) {
                    foreach ( $_extra['config']['photos'] as &$_photo ) {
                        $_pid = (int) ( $_photo['photo_id'] ?? 0 );
                        $_photo['photo_url'] = $_pid ? (string) wp_get_attachment_image_url( $_pid, 'medium' ) : '';
                    }
                    unset( $_photo );
                }
            }
        }
        unset( $_extra );
        $extras_json = wp_json_encode( array_values( $extras_raw ) );
    }
}

function ke_get_meta_val( $meta_array, $key, $default = '' ) {
    return isset( $meta_array[ $key ] ) ? $meta_array[ $key ][0] : $default;
}

// ── Meta values ────────────────────────────────────────────────────────────
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
$service_fee_id = ke_get_meta_val( $meta, '_ke_event_service_fee_id' );
$social_instagram = ke_get_meta_val( $meta, '_ke_social_instagram' );
$social_whatsapp  = ke_get_meta_val( $meta, '_ke_social_whatsapp' );
$social_website   = ke_get_meta_val( $meta, '_ke_social_website' );
$social_facebook  = ke_get_meta_val( $meta, '_ke_social_facebook' );
$event_status     = ke_get_meta_val( $meta, '_ke_event_status', 'active' );
$email_custom_msg = ke_get_meta_val( $meta, '_ke_email_custom_message' );
$email_from_name  = ke_get_meta_val( $meta, '_ke_email_from_name' );
$is_featured      = ke_get_meta_val( $meta, '_ke_event_is_featured', '0' );
$promo_label      = ke_get_meta_val( $meta, '_ke_event_promo_label' );
// Default ON. Treat anything other than '0' (including missing meta on
// pre-existing events) as visible in [kiwi_events]; only an explicit '0' hides.
$show_in_main_raw = ke_get_meta_val( $meta, '_ke_event_show_in_main_shortcode', '1' );
$show_in_main     = ( $show_in_main_raw === '0' || $show_in_main_raw === 0 ) ? '0' : '1';

// Split datetime into date + time parts for the dual inputs
$start_date = $start_time = $end_date = $end_time = '';
if ( $date_start ) {
    $parts = explode( 'T', str_replace( ' ', 'T', $date_start ) );
    $start_date = $parts[0];
    $start_time = isset( $parts[1] ) ? substr( $parts[1], 0, 5 ) : '';
}
if ( $date_end ) {
    $parts = explode( 'T', str_replace( ' ', 'T', $date_end ) );
    $end_date = $parts[0];
    $end_time = isset( $parts[1] ) ? substr( $parts[1], 0, 5 ) : '';
}

// Convert HH:MM (24h) to 12h components for the custom picker
function ke_time_to_12h( $time24 ) {
    if ( ! $time24 ) return array( 'h' => '12', 'm' => '00', 'a' => 'AM' );
    list( $h, $m ) = array_map( 'intval', explode( ':', $time24 ) );
    $ampm = $h >= 12 ? 'PM' : 'AM';
    $h12  = $h % 12 ?: 12;
    return array( 'h' => (string) $h12, 'm' => str_pad( $m, 2, '0', STR_PAD_LEFT ), 'a' => $ampm );
}
$st12 = ke_time_to_12h( $start_time );
$et12 = ke_time_to_12h( $end_time );

$is_edit = $event_id > 0;

// ── Promoter assignments hydration ──────────────────────────────────
$promoter_assignments_json = '[]';
if ( $is_edit && class_exists( 'KE_Event_Promoters' ) ) {
    $ep_rows = KE_Event_Promoters::list_for_event( $event_id );
    $shaped  = array();
    foreach ( $ep_rows as $r ) {
        $shaped[] = array(
            'promoter_id'      => (int) $r->promoter_id,
            'name'             => $r->name,
            'email'            => $r->email,
            'slug'             => $r->slug,
            'status'           => $r->status,
            'commission_type'  => $r->commission_type,
            'commission_value' => (float) $r->commission_value,
        );
    }
    $promoter_assignments_json = wp_json_encode( $shaped );
}

// Dropdown source: all active + pending promoters, alphabetical. Name/email
// come from the linked WP user — orphaned rows surface as slug only.
$promoters_all_json = '[]';
if ( isset( $GLOBALS['wpdb'] ) ) {
    global $wpdb;
    $pt = $wpdb->prefix . 'ke_promoters';
    $u  = $wpdb->users;
    $all_rows = $wpdb->get_results(
        "SELECT p.id,
                COALESCE(NULLIF(u.display_name, ''), u.user_login, p.slug) AS name,
                COALESCE(u.user_email, '') AS email,
                p.slug, p.status
           FROM $pt p
      LEFT JOIN $u u ON u.ID = p.user_id
          WHERE p.status IN ('active', 'pending')
          ORDER BY name ASC"
    );
    $promoters_all_json = wp_json_encode( array_map( function ( $r ) {
        return array(
            'id'     => (int) $r->id,
            'name'   => $r->name,
            'email'  => $r->email,
            'slug'   => $r->slug,
            'status' => $r->status,
        );
    }, $all_rows ?: array() ) );
}
?>
<div class="wrap ke-builder-wrap">

    <div class="ke-builder-header">
        <div class="ke-builder-title">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ke-events' ) ); ?>" class="back-link">
                <span class="dashicons dashicons-arrow-left-alt2"></span> Events
            </a>
            <h1><?php echo $is_edit ? 'Edit Event' : 'Create New Event'; ?></h1>
        </div>
        <div class="ke-builder-header-actions">
            <div id="ke-save-indicator" class="ke-save-indicator" data-state="idle" aria-live="polite">
                <span class="ke-save-dot"></span>
                <span class="ke-save-text"></span>
            </div>
            <?php if ( $is_edit ) : ?>
            <button type="button"
                    class="ke-action-btn ke-action-btn-delete"
                    id="ke-builder-delete-btn"
                    data-event-id="<?php echo esc_attr( $event_id ); ?>">
                <span class="dashicons dashicons-trash" style="font-size:14px;line-height:1;margin-right:4px;vertical-align:-2px;"></span>
                Delete Event
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Wizard progress bar.
         data-step-state and data-clickable are pre-set in PHP so the
         static HTML renders the correct accent treatment even before
         the wizard JS hydrates. The JS then takes over as the user
         moves between steps. -->
    <?php
    $ke_initial_step_state = function ( $step ) use ( $is_edit ) {
        if ( $step === 1 ) return 'active';
        return $is_edit ? 'available' : 'locked';
    };
    $ke_initial_clickable = function ( $step ) use ( $is_edit ) {
        return ( $step === 1 || $is_edit ) ? 'true' : 'false';
    };
    $ke_initial_locked = function ( $step ) use ( $is_edit ) {
        return ( $step === 1 || $is_edit ) ? 'false' : 'true';
    };
    $ke_initial_line_state = $is_edit ? 'lit' : 'dim';
    ?>
    <div class="ke-wizard-progress<?php echo $is_edit ? ' is-edit-mode' : ''; ?>">
        <div class="ke-wizard-step-item active" data-step="1" data-step-label="<?php esc_attr_e( 'General Info', 'kiwi-events' ); ?>"
             data-step-state="<?php echo esc_attr( $ke_initial_step_state( 1 ) ); ?>"
             data-clickable="<?php echo esc_attr( $ke_initial_clickable( 1 ) ); ?>"
             data-locked="<?php echo esc_attr( $ke_initial_locked( 1 ) ); ?>"
             role="button" tabindex="0"
             aria-label="<?php esc_attr_e( 'Go to step 1: General Info', 'kiwi-events' ); ?>">
            <div class="ke-wiz-num">1</div>
            <div class="ke-wiz-label"><?php esc_html_e( 'General Info', 'kiwi-events' ); ?></div>
        </div>
        <div class="ke-wizard-line" data-line-state="<?php echo esc_attr( $ke_initial_line_state ); ?>"></div>
        <div class="ke-wizard-step-item" data-step="2" data-step-label="<?php esc_attr_e( 'Customization', 'kiwi-events' ); ?>"
             data-step-state="<?php echo esc_attr( $ke_initial_step_state( 2 ) ); ?>"
             data-clickable="<?php echo esc_attr( $ke_initial_clickable( 2 ) ); ?>"
             data-locked="<?php echo esc_attr( $ke_initial_locked( 2 ) ); ?>"
             role="button" tabindex="0"
             aria-label="<?php esc_attr_e( 'Go to step 2: Customization', 'kiwi-events' ); ?>">
            <div class="ke-wiz-num">2</div>
            <div class="ke-wiz-label"><?php esc_html_e( 'Customization', 'kiwi-events' ); ?></div>
        </div>
        <div class="ke-wizard-line" data-line-state="<?php echo esc_attr( $ke_initial_line_state ); ?>"></div>
        <div class="ke-wizard-step-item" data-step="3" data-step-label="<?php esc_attr_e( 'Tickets &amp; Reservations', 'kiwi-events' ); ?>"
             data-step-state="<?php echo esc_attr( $ke_initial_step_state( 3 ) ); ?>"
             data-clickable="<?php echo esc_attr( $ke_initial_clickable( 3 ) ); ?>"
             data-locked="<?php echo esc_attr( $ke_initial_locked( 3 ) ); ?>"
             role="button" tabindex="0"
             aria-label="<?php esc_attr_e( 'Go to step 3: Tickets and Reservations', 'kiwi-events' ); ?>">
            <div class="ke-wiz-num">3</div>
            <div class="ke-wiz-label"><?php esc_html_e( 'Tickets &amp; Reservations', 'kiwi-events' ); ?></div>
        </div>
    </div>

    <?php if ( $is_edit ) : ?>
    <!-- Persistent edit-mode save bar (Item #2). Visible from any wizard step;
         lets the user commit edits without walking to the final step. -->
    <div class="ke-persistent-savebar" id="ke-persistent-savebar">
        <div class="ke-persistent-savebar-inner">
            <div class="ke-persistent-savebar-status" id="ke-persistent-savebar-status">
                <span class="ke-savebar-dot"></span>
                <span class="ke-savebar-text"><?php esc_html_e( 'No unsaved changes', 'kiwi-events' ); ?></span>
            </div>
            <button type="button"
                    id="ke-persistent-save-btn"
                    class="ke-persistent-save-btn"
                    data-state="idle"
                    disabled
                    aria-label="<?php esc_attr_e( 'Save changes', 'kiwi-events' ); ?>"
                    title="<?php esc_attr_e( 'No changes to save.', 'kiwi-events' ); ?>">
                <span class="ke-persistent-save-icon" aria-hidden="true">✓</span>
                <span class="ke-persistent-save-label"><?php esc_html_e( 'Saved', 'kiwi-events' ); ?></span>
            </button>
        </div>
    </div>

    <!-- Toast container (bottom-center) for persistent-save confirmations. -->
    <div class="ke-toast-stack" id="ke-toast-stack" aria-live="polite" aria-atomic="true"></div>
    <?php endif; ?>

    <div class="ke-builder-card">
        <input type="hidden" id="ke-event-id" value="<?php echo esc_attr( $event_id ); ?>">

        <!-- ══════════════════════════════════════════════
             STEP 1 — GENERAL INFO
        ══════════════════════════════════════════════ -->
        <div class="ke-wizard-panel active" id="ke-step-1">

            <div id="ke-step1-error" class="ke-builder-error" style="display:none;"></div>

            <div class="ke-form-group">
                <label class="ke-label" for="ke-event-title">Event Name <span class="ke-required">*</span></label>
                <input type="text" id="ke-event-title" class="ke-input ke-input-xl"
                       placeholder="E.g., Summer Music Festival 2026"
                       value="<?php echo esc_attr( $post ? $post->post_title : '' ); ?>">
            </div>

            <?php
            // Single-line inline slug editor (Eventin pattern). Display state
            // is "Event URL: <base>/<slug> [✎]" on one line. Pencil swaps the
            // slug portion into an inline <input> with confirm/cancel icons.
            // No card wrapper, no persistent helper text.
            $existing_slug = $post ? (string) $post->post_name : '';
            $rewrite_base  = 'events';
            $pt_obj_for_slug = get_post_type_object( 'ke_event' );
            if ( $pt_obj_for_slug && ! empty( $pt_obj_for_slug->rewrite['slug'] ) ) {
                $rewrite_base = $pt_obj_for_slug->rewrite['slug'];
            }
            $slug_base_url = trailingslashit( home_url( '/' . $rewrite_base . '/' ) );
            ?>
            <div class="ke-slug-row"
                 id="ke-slug-field"
                 data-base-url="<?php echo esc_attr( $slug_base_url ); ?>"
                 data-mode="display">
                <span class="ke-slug-label">Event URL:</span>
                <span class="ke-slug-prefix"><?php echo esc_html( $slug_base_url ); ?></span>

                <span class="ke-slug-value"
                      id="ke-slug-value"><?php echo esc_html( $existing_slug ); ?></span>

                <input type="text"
                       id="ke-event-slug"
                       class="ke-slug-input"
                       maxlength="60"
                       autocomplete="off"
                       spellcheck="false"
                       value="<?php echo esc_attr( $existing_slug ); ?>"
                       hidden>

                <button type="button"
                        class="ke-slug-edit-btn ke-slug-pencil-btn"
                        id="ke-slug-pencil"
                        aria-label="Edit URL slug"
                        title="Edit URL slug">
                    <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                </button>
                <button type="button"
                        class="ke-slug-edit-btn ke-slug-confirm-btn"
                        id="ke-slug-confirm"
                        aria-label="Confirm slug"
                        title="Confirm"
                        hidden>
                    <span class="dashicons dashicons-yes" aria-hidden="true"></span>
                </button>
                <button type="button"
                        class="ke-slug-edit-btn ke-slug-cancel-btn"
                        id="ke-slug-cancel"
                        aria-label="Cancel slug edit"
                        title="Cancel"
                        hidden>
                    <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                </button>
            </div>
            <div class="ke-slug-error" id="ke-slug-error" hidden></div>

            <div class="ke-form-group">
                <label class="ke-label">Event Description</label>
                <textarea id="ke-event-description" class="ke-textarea" rows="6"
                          placeholder="Describe your event — what to expect, dress code, special guests…"><?php echo esc_textarea( $post ? $post->post_content : '' ); ?></textarea>
                <p class="ke-hint">Basic HTML is supported.</p>
            </div>

            <div class="ke-form-group">
                <label class="ke-label">Banner Image</label>
                <div class="ke-banner-uploader" id="ke-banner-uploader">
                    <div class="ke-banner-preview" id="ke-banner-preview"
                         <?php if ( $thumbnail_url ) echo 'style="background-image:url(' . esc_url( $thumbnail_url ) . ');display:block;"'; ?>>
                        <div class="ke-banner-placeholder" id="ke-banner-placeholder"
                             <?php if ( $thumbnail_url ) echo 'style="display:none;"'; ?>>
                            <span class="dashicons dashicons-format-image"></span>
                            <span>Click to upload a banner</span>
                        </div>
                    </div>
                    <input type="hidden" id="ke-banner-id" value="<?php echo esc_attr( $thumbnail_id ); ?>">
                    <div class="ke-banner-actions">
                        <button type="button" class="ke-btn ke-btn-ghost ke-small" id="ke-banner-btn">
                            <?php echo $thumbnail_url ? 'Change Image' : 'Choose Image'; ?>
                        </button>
                        <button type="button" class="ke-btn ke-btn-danger ke-small" id="ke-banner-remove"
                                <?php if ( ! $thumbnail_url ) echo 'style="display:none;"'; ?>>Remove</button>
                    </div>
                </div>
            </div>

            <div class="ke-form-row">
                <div class="ke-form-group ke-half">
                    <label class="ke-label">Start Date <span class="ke-required">*</span></label>
                    <input type="date" id="ke-start-date" class="ke-input" value="<?php echo esc_attr( $start_date ); ?>">
                </div>
                <div class="ke-form-group ke-half">
                    <label class="ke-label">Start Time <span class="ke-required">*</span></label>
                    <div class="ke-time-picker" id="ke-start-time">
                        <select class="ke-select ke-time-h">
                            <?php for ( $i = 1; $i <= 12; $i++ ) : ?>
                            <option value="<?php echo $i; ?>" <?php selected( $st12['h'], (string) $i ); ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                        <span class="ke-time-sep">:</span>
                        <select class="ke-select ke-time-m">
                            <?php foreach ( array('00','15','30','45') as $min ) : ?>
                            <option value="<?php echo $min; ?>" <?php selected( $st12['m'], $min ); ?>><?php echo $min; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="ke-select ke-time-ampm">
                            <option value="AM" <?php selected( $st12['a'], 'AM' ); ?>>AM</option>
                            <option value="PM" <?php selected( $st12['a'], 'PM' ); ?>>PM</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="ke-form-row">
                <div class="ke-form-group ke-half">
                    <label class="ke-label">End Date <span class="ke-required">*</span></label>
                    <input type="date" id="ke-end-date" class="ke-input" value="<?php echo esc_attr( $end_date ); ?>">
                </div>
                <div class="ke-form-group ke-half">
                    <label class="ke-label">End Time <span class="ke-required">*</span></label>
                    <div class="ke-time-picker" id="ke-end-time">
                        <select class="ke-select ke-time-h">
                            <?php for ( $i = 1; $i <= 12; $i++ ) : ?>
                            <option value="<?php echo $i; ?>" <?php selected( $et12['h'], (string) $i ); ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                        <span class="ke-time-sep">:</span>
                        <select class="ke-select ke-time-m">
                            <?php foreach ( array('00','15','30','45') as $min ) : ?>
                            <option value="<?php echo $min; ?>" <?php selected( $et12['m'], $min ); ?>><?php echo $min; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="ke-select ke-time-ampm">
                            <option value="AM" <?php selected( $et12['a'], 'AM' ); ?>>AM</option>
                            <option value="PM" <?php selected( $et12['a'], 'PM' ); ?>>PM</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="ke-form-group">
                <label class="ke-label">Timezone</label>
                <select id="ke-timezone" class="ke-select">
                    <?php foreach ( timezone_identifiers_list() as $tz ) : ?>
                        <option value="<?php echo esc_attr( $tz ); ?>" <?php selected( $timezone, $tz ); ?>>
                            <?php echo esc_html( str_replace( '_', ' ', $tz ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ke-divider"></div>

            <div class="ke-form-group">
                <label class="ke-label">Location Type</label>
                <div class="ke-radio-group">
                    <label class="ke-radio-option <?php echo $location_type === 'venue' ? 'active' : ''; ?>">
                        <input type="radio" name="ke_location_type" value="venue" <?php checked( $location_type, 'venue' ); ?>>
                        <span>📍 In-Person</span>
                    </label>
                    <label class="ke-radio-option <?php echo $location_type === 'virtual' ? 'active' : ''; ?>">
                        <input type="radio" name="ke_location_type" value="virtual" <?php checked( $location_type, 'virtual' ); ?>>
                        <span>💻 Virtual</span>
                    </label>
                    <label class="ke-radio-option <?php echo $location_type === 'hybrid' ? 'active' : ''; ?>">
                        <input type="radio" name="ke_location_type" value="hybrid" <?php checked( $location_type, 'hybrid' ); ?>>
                        <span>🌐 Hybrid</span>
                    </label>
                </div>
            </div>

            <div id="ke-venue-fields" <?php echo $location_type === 'virtual' ? 'style="display:none"' : ''; ?>>
                <div class="ke-form-row">
                    <div class="ke-form-group ke-half">
                        <label class="ke-label">Venue Name</label>
                        <input type="text" id="ke-venue" class="ke-input" placeholder="E.g., Madison Square Garden"
                               value="<?php echo esc_attr( $venue ); ?>">
                    </div>
                    <div class="ke-form-group ke-half">
                        <label class="ke-label">Address</label>
                        <input type="text" id="ke-address" class="ke-input" placeholder="E.g., 4 Pennsylvania Plaza, NY"
                               value="<?php echo esc_attr( $address ); ?>">
                    </div>
                </div>
                <div class="ke-form-group">
                    <label class="ke-label">Google Maps Embed</label>
                    <textarea id="ke-maps-embed" class="ke-textarea" rows="3"
                              placeholder="Paste the <iframe> embed code from Google Maps → Share → Embed a map"><?php echo esc_textarea( $maps_embed ); ?></textarea>
                    <p class="ke-hint">In Google Maps: Share → Embed a map → Copy HTML. Paste the full &lt;iframe&gt; tag.</p>
                </div>
            </div>

            <div id="ke-virtual-fields" <?php echo $location_type === 'venue' ? 'style="display:none"' : ''; ?>>
                <div class="ke-form-group">
                    <label class="ke-label">Virtual Event URL</label>
                    <input type="url" id="ke-virtual-url" class="ke-input" placeholder="https://zoom.us/j/…"
                           value="<?php echo esc_attr( $virtual_url ); ?>">
                    <p class="ke-hint">Will be shared with ticket holders in the confirmation email.</p>
                </div>
            </div>

            <div class="ke-divider"></div>

            <!-- Social Links collapsible -->
            <div class="ke-collapsible" id="ke-social-collapsible">
                <button type="button" class="ke-collapsible-toggle" id="ke-social-toggle">
                    <span>🔗 Social Links</span>
                    <span class="ke-caret">▾</span>
                </button>
                <div class="ke-collapsible-body" id="ke-social-body"
                     <?php echo ( $social_instagram || $social_whatsapp || $social_website || $social_facebook ) ? '' : 'style="display:none"'; ?>>
                    <div class="ke-form-row">
                        <div class="ke-form-group ke-half">
                            <label class="ke-label">Instagram</label>
                            <input type="url" id="ke-social-instagram" class="ke-input"
                                   placeholder="https://instagram.com/yourhandle"
                                   value="<?php echo esc_attr( $social_instagram ); ?>">
                        </div>
                        <div class="ke-form-group ke-half">
                            <label class="ke-label">WhatsApp Group Link</label>
                            <input type="url" id="ke-social-whatsapp" class="ke-input"
                                   placeholder="https://chat.whatsapp.com/…"
                                   value="<?php echo esc_attr( $social_whatsapp ); ?>">
                        </div>
                    </div>
                    <div class="ke-form-row">
                        <div class="ke-form-group ke-half">
                            <label class="ke-label">Website / Tickets Link</label>
                            <input type="url" id="ke-social-website" class="ke-input"
                                   placeholder="https://yoursite.com"
                                   value="<?php echo esc_attr( $social_website ); ?>">
                        </div>
                        <div class="ke-form-group ke-half">
                            <label class="ke-label">Facebook Event</label>
                            <input type="url" id="ke-social-facebook" class="ke-input"
                                   placeholder="https://facebook.com/events/…"
                                   value="<?php echo esc_attr( $social_facebook ); ?>">
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /#ke-step-1 -->

        <!-- ══════════════════════════════════════════════
             STEP 2 — CUSTOMIZATION
        ══════════════════════════════════════════════ -->
        <div class="ke-wizard-panel" id="ke-step-2">

            <div class="ke-form-row">
                <div class="ke-form-group ke-half">
                    <label class="ke-label">Organizer</label>
                    <?php
                    $organizers = get_terms( array( 'taxonomy' => 'ke_organizer', 'hide_empty' => false ) );
                    echo '<select id="ke-organizer" class="ke-select"><option value="">— Select Organizer —</option>';
                    if ( ! empty( $organizers ) && ! is_wp_error( $organizers ) ) {
                        foreach ( $organizers as $org ) {
                            $sel = $org->term_id == $organizer_id ? 'selected' : '';
                            echo '<option value="' . esc_attr( $org->term_id ) . '" ' . $sel . '>' . esc_html( $org->name ) . '</option>';
                        }
                    }
                    echo '</select>';
                    ?>
                </div>
                <div class="ke-form-group ke-half">
                    <label class="ke-label">Event Status</label>
                    <select id="ke-event-status" class="ke-select">
                        <option value="active"     <?php selected( $event_status, 'active' ); ?>>Active — accepting registrations</option>
                        <option value="draft"      <?php selected( $event_status, 'draft' ); ?>>Draft — hidden from public</option>
                        <option value="cancelled"  <?php selected( $event_status, 'cancelled' ); ?>>Cancelled</option>
                        <option value="postponed"  <?php selected( $event_status, 'postponed' ); ?>>Postponed</option>
                        <option value="sold_out"   <?php selected( $event_status, 'sold_out' ); ?>>Sold Out</option>
                    </select>
                </div>
            </div>

            <div class="ke-form-group">
                <label class="ke-label">Categories</label>
                <?php
                $cats = get_terms( array( 'taxonomy' => 'ke_event_category', 'hide_empty' => false ) );
                if ( ! empty( $cats ) && ! is_wp_error( $cats ) ) {
                    echo '<div class="ke-checkbox-grid">';
                    foreach ( $cats as $cat ) {
                        $checked = in_array( $cat->term_id, $categories ) ? 'checked' : '';
                        echo '<label class="ke-checkbox-option"><input type="checkbox" class="ke-cat-check" value="' . esc_attr( $cat->term_id ) . '" ' . $checked . '> ' . esc_html( $cat->name ) . '</label>';
                    }
                    echo '</div>';
                } else {
                    echo '<p class="ke-hint">No categories yet. <a href="' . esc_url( admin_url( 'admin.php?page=ke-categories' ) ) . '">Create one</a>.</p>';
                }
                ?>
            </div>

            <div class="ke-form-row">
                <div class="ke-form-group ke-half">
                    <label class="ke-toggle-wrap" style="margin-top:28px;">
                        <input type="checkbox" id="ke-is-featured" <?php checked( $is_featured, '1' ); ?>>
                        <span class="ke-toggle-slider"></span>
                        <span class="ke-toggle-label">Mark as featured event</span>
                    </label>
                </div>
                <div class="ke-form-group ke-half">
                    <label class="ke-label">Promo Label (Optional)</label>
                    <input type="text" id="ke-promo-label" class="ke-input" placeholder="e.g. EARLY BIRD" value="<?php echo esc_attr( $promo_label ); ?>">
                </div>
            </div>

            <div class="ke-form-group">
                <label class="ke-label">Max Tickets per Person</label>
                <input type="number" id="ke-max-tickets" class="ke-input ke-input-sm" min="1" max="100"
                       value="<?php echo esc_attr( $max_tickets ?: 10 ); ?>">
            </div>

            <div class="ke-form-group">
                <label class="ke-toggle-wrap">
                    <input type="checkbox" id="ke-show-in-main-shortcode" <?php checked( $show_in_main, '1' ); ?>>
                    <span class="ke-toggle-slider"></span>
                    <span class="ke-toggle-label"><?php esc_html_e( 'Show in main shortcode [kiwi_events]', 'kiwi-events' ); ?></span>
                </label>
                <p class="ke-hint" style="margin-top:6px;">
                    <?php esc_html_e( 'If disabled, this event won\'t appear in the main events listing on the homepage, but the direct event URL will still work for anyone who has the link. Category-specific shortcodes are unaffected.', 'kiwi-events' ); ?>
                </p>
            </div>

            <div class="ke-divider"></div>

            <h3 class="ke-section-title">📧 Confirmation Email Customization</h3>

            <div class="ke-form-row">
                <div class="ke-form-group ke-half">
                    <label class="ke-label">From Name</label>
                    <input type="text" id="ke-email-from-name" class="ke-input"
                           placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
                           value="<?php echo esc_attr( $email_from_name ); ?>">
                    <p class="ke-hint">Displayed as sender in the ticket email.</p>
                </div>
            </div>

            <div class="ke-form-group">
                <label class="ke-label">Custom Message for Attendees</label>
                <textarea id="ke-email-custom-message" class="ke-textarea" rows="4"
                          placeholder="E.g., Doors open at 7 PM. Parking available on Level 3. Bring a valid ID."><?php echo esc_textarea( $email_custom_msg ); ?></textarea>
                <p class="ke-hint">This message is included in the confirmation email below the ticket QR code.</p>
            </div>

            <!-- ── EXTRAS ── -->
            <div class="ke-form-group">
                <h3 class="ke-subsection-title">⭐ Extras</h3>
                <p class="ke-hint" style="margin-top:0;">
                    Toggle optional sections on the public event page.
                </p>
                <div class="ke-extras-grid">
                    <?php
                    $extras_catalog = array(
                        'sold_out_bar'    => array( '📊', 'Sold-Out Bar',           'Live availability progress.' ),
                        'countdown'       => array( '⏰', 'Countdown',               'Days/hours until it starts.' ),
                        'lineup'          => array( '🎤', 'Lineup',                  'Artists or speakers, with photos.' ),
                        'gallery'         => array( '📸', 'Gallery',                 'Photo carousel from past events.' ),
                        'testimonials'    => array( '💬', 'Testimonials',            'Quotes from past attendees.' ),
                        'schedule'        => array( '📋', 'Schedule',                'Hour-by-hour timeline.' ),
                        'faq'             => array( '❓', 'FAQ',                      'Accordion of common questions.' ),
                        'menu_faq'        => array( '🍔', 'Menu / FAQ',              'Collapsible custom sections.' ),
                        'additional_info' => array( 'ℹ️', 'Additional Information', 'Refundable status + important notes.' ),
                    );
                    foreach ( $extras_catalog as $slug => $spec ) :
                        list( $icon, $label, $desc ) = $spec;
                    ?>
                        <label class="ke-extra-card">
                            <input type="checkbox" class="ke-extra-toggle"
                                   data-type="<?php echo esc_attr( $slug ); ?>">
                            <div class="ke-extra-icon"><?php echo $icon; ?></div>
                            <div class="ke-extra-body">
                                <div class="ke-extra-label"><?php echo esc_html( $label ); ?></div>
                                <div class="ke-extra-desc"><?php echo esc_html( $desc ); ?></div>
                            </div>
                            <div class="ke-extra-switch">
                                <span class="ke-extra-switch-dot"></span>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ── LINEUP EDITOR (shown only when Lineup extra is enabled) ── -->
            <div class="ke-form-group ke-lineup-editor" id="ke-lineup-editor" style="display:none;">
                <h3 class="ke-subsection-title">🎤 Lineup</h3>
                <p class="ke-hint" style="margin-top:0;">
                    Add the artists or speakers that will appear in the public lineup.
                </p>
                <div class="ke-lineup-editor-empty" id="ke-lineup-empty">
                    Add artists to display in the lineup
                </div>
                <div class="ke-lineup-editor-list" id="ke-lineup-list"></div>
                <button type="button" class="ke-btn ke-btn-secondary ke-lineup-add-btn" id="ke-lineup-add">
                    + Add Artist
                </button>
            </div>

            <!-- ── GALLERY EDITOR (shown only when Gallery extra is enabled) ── -->
            <div class="ke-form-group ke-gallery-editor" id="ke-gallery-editor" style="display:none;">
                <h3 class="ke-subsection-title">📸 Gallery</h3>
                <p class="ke-hint" style="margin-top:0;">
                    Upload photos from past events. Add optional captions that show as overlays.
                </p>
                <div class="ke-gallery-editor-empty" id="ke-gallery-empty">
                    Add photos from past events to build your gallery
                </div>
                <div class="ke-gallery-editor-grid" id="ke-gallery-list"></div>
                <button type="button" class="ke-btn ke-btn-secondary ke-gallery-add-btn" id="ke-gallery-add">
                    + Add Photos
                </button>
            </div>

            <!-- ── TESTIMONIALS EDITOR (shown only when Testimonials extra is enabled) ── -->
            <div class="ke-form-group ke-testi-editor" id="ke-testi-editor" style="display:none;">
                <h3 class="ke-subsection-title">💬 Testimonials</h3>
                <p class="ke-hint" style="margin-top:0;">
                    Logged-in visitors can post comments and star ratings. Testimonials always render last on the public page.
                </p>

                <div class="ke-form-row">
                    <label class="ke-label" for="ke-testi-title">Section title</label>
                    <input type="text" id="ke-testi-title" class="ke-input" placeholder="Testimonials" maxlength="120">
                </div>

                <div class="ke-testi-toggles">
                    <label class="ke-testi-toggle">
                        <input type="checkbox" id="ke-testi-require-approval" checked>
                        <span><strong>Require approval</strong> — comments stay hidden until you approve them.</span>
                    </label>
                    <label class="ke-testi-toggle">
                        <input type="checkbox" id="ke-testi-allow-ratings" checked>
                        <span><strong>Allow star ratings</strong> — visitors can attach a 1–5 star rating.</span>
                    </label>
                </div>

                <?php if ( ! empty( $is_edit ) && ! empty( $event_id ) ) : ?>
                    <div class="ke-testi-mod">
                        <div class="ke-testi-mod-header">
                            <strong>Recent comments</strong>
                            <button type="button" class="ke-btn ke-btn-secondary ke-btn-small" id="ke-testi-approve-all">Approve all pending</button>
                        </div>
                        <div class="ke-testi-mod-list" id="ke-testi-mod-list">
                            <div class="ke-testi-mod-empty">Loading…</div>
                        </div>
                    </div>
                <?php else : ?>
                    <p class="ke-hint">Save the event to start moderating comments here.</p>
                <?php endif; ?>
            </div>

            <!-- ── SCHEDULE EDITOR (shown only when Schedule extra is enabled) ── -->
            <div class="ke-form-group ke-schedule-editor" id="ke-schedule-editor" style="display:none;">
                <h3 class="ke-subsection-title">📋 Schedule</h3>
                <p class="ke-hint" style="margin-top:0;">
                    Build an hour-by-hour timeline. Times display in 12-hour AM/PM on the public page.
                </p>
                <div class="ke-schedule-editor-empty" id="ke-schedule-empty">
                    Add time slots to build your event schedule
                </div>
                <div class="ke-schedule-editor-list" id="ke-schedule-list"></div>
                <button type="button" class="ke-btn ke-btn-secondary ke-schedule-add-btn" id="ke-schedule-add">
                    + Add Time Slot
                </button>
            </div>

            <!-- ── FAQ EDITOR (shown only when FAQ extra is enabled) ── -->
            <div class="ke-form-group ke-faq-editor" id="ke-faq-editor" style="display:none;">
                <h3 class="ke-subsection-title">❓ FAQ</h3>
                <p class="ke-hint" style="margin-top:0;">
                    Answer the questions your attendees commonly ask. Renders as an accordion on the public page.
                </p>

                <div class="ke-form-row">
                    <label class="ke-label" for="ke-faq-title">Section title</label>
                    <input type="text" id="ke-faq-title" class="ke-input" placeholder="Frequently Asked Questions" maxlength="120">
                </div>

                <div class="ke-faq-editor-empty" id="ke-faq-empty">
                    Add questions your attendees commonly ask
                </div>
                <div class="ke-faq-editor-list" id="ke-faq-list"></div>
                <button type="button" class="ke-btn ke-btn-secondary ke-faq-add-btn" id="ke-faq-add">
                    + Add Question
                </button>
            </div>

            <!-- ── ADDITIONAL INFO EDITOR (shown only when "Additional Information" extra is enabled) ── -->
            <div class="ke-form-group ke-addinfo-editor" id="ke-addinfo-editor" style="display:none;">
                <h3 class="ke-subsection-title">ℹ️ Additional Information</h3>
                <p class="ke-hint" style="margin-top:0;">
                    Renders as a small, understated section below the main event info on the public page. Each sub-field below is optional — leave a row blank to skip it.
                </p>

                <div class="ke-form-row" style="margin-top:10px;">
                    <label class="ke-label" for="ke-addinfo-refundable">Is this event refundable?</label>
                    <select id="ke-addinfo-refundable" class="ke-input ke-input-sm">
                        <option value="">— Not specified —</option>
                        <option value="yes">Yes — refundable</option>
                        <option value="no">No — non-refundable</option>
                    </select>
                    <p class="ke-hint" style="margin-top:6px;">
                        This is informational only. It is displayed on the event page for buyers' awareness — it doesn't change refund processing.
                    </p>
                </div>

                <div class="ke-form-row" style="margin-top:14px;">
                    <label class="ke-label" for="ke-addinfo-disclaimers">Important notes / Disclaimers</label>
                    <textarea id="ke-addinfo-disclaimers" class="ke-textarea" rows="6"
                              placeholder="E.g., 21+ event. No outside drinks allowed. Parking available at the venue's east entrance."></textarea>
                    <p class="ke-hint" style="margin-top:6px;">
                        Use line breaks for separate notes. Basic HTML (links, line breaks, lists) is preserved.
                    </p>
                </div>
            </div>

            <div class="ke-divider"></div>

            <!-- ── EXTRA FIELDS (per-attendee checkout questions) ── -->
            <div class="ke-form-group ke-xfields-editor" id="ke-xfields-editor">
                <h3 class="ke-subsection-title">📝 Extra Fields</h3>
                <p class="ke-hint" style="margin-top:0;">
                    Collect custom info from each attendee at checkout — university name, dietary restrictions, shirt size, dance crew, etc.
                </p>

                <label class="ke-toggle-wrap" style="margin:12px 0 18px;">
                    <input type="checkbox" id="ke-xfields-enabled">
                    <span class="ke-toggle-slider"></span>
                    <span class="ke-toggle-label">Enable extra fields at checkout</span>
                </label>

                <div class="ke-xfields-body" id="ke-xfields-body" style="display:none;">
                    <div class="ke-xfields-empty" id="ke-xfields-empty">
                        Add fields to collect from each attendee
                    </div>
                    <div class="ke-xfields-list" id="ke-xfields-list"></div>
                    <button type="button" class="ke-btn ke-btn-secondary ke-xfields-add-btn" id="ke-xfields-add">
                        + Add Extra Field
                    </button>
                </div>
            </div>

            <div class="ke-divider"></div>

            <!-- ── PROMOTERS (per-event commission assignments) ── -->
            <div class="ke-form-group ke-promoters-editor"
                 id="ke-promoters-editor"
                 data-assignments="<?php echo esc_attr( $promoter_assignments_json ); ?>"
                 data-all-promoters="<?php echo esc_attr( $promoters_all_json ); ?>">
                <h3 class="ke-subsection-title">📣 Promoters</h3>
                <p class="ke-hint" style="margin-top:0;">
                    Assign promoters to this event. Each row sets the commission they earn per ticket sold through their tracking link.
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ke-promoters' ) ); ?>" target="_blank" rel="noopener">Manage promoters →</a>
                </p>

                <div class="ke-promoters-empty" id="ke-promoters-empty" style="padding:18px 14px; border:1px dashed var(--kiwi-legacy-row-bg-strong); border-radius:8px; color:var(--kiwi-legacy-text-muted); text-align:center; font-size:13px;">
                    No promoters assigned. Use the picker below to add one.
                </div>

                <div class="ke-promoters-list" id="ke-promoters-list" style="display:flex; flex-direction:column; gap:8px;"></div>

                <div class="ke-promoter-add-row" style="margin-top:14px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; padding:12px; background:var(--kiwi-legacy-page-bg); border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:8px;">
                    <select id="ke-promoter-picker" class="ke-select" style="flex:1 1 260px; min-width:0;">
                        <option value="">— Choose a promoter to add —</option>
                    </select>
                    <?php
                        $promo_def_type  = get_option( 'ke_promoter_default_commission_type', 'percentage' );
                        $promo_def_value = (float) get_option( 'ke_promoter_default_commission_value', 0 );
                    ?>
                    <select id="ke-promoter-picker-type" class="ke-select" style="width:130px;">
                        <option value="percentage" <?php selected( $promo_def_type, 'percentage' ); ?>>% of price</option>
                        <option value="fixed"      <?php selected( $promo_def_type, 'fixed' ); ?>>$ fixed</option>
                    </select>
                    <input type="number" id="ke-promoter-picker-value"
                           class="ke-input ke-input-sm"
                           min="0" step="0.01" placeholder="10"
                           value="<?php echo $promo_def_value > 0 ? esc_attr( number_format( $promo_def_value, 2, '.', '' ) ) : ''; ?>"
                           style="width:90px;">
                    <button type="button" id="ke-promoter-add-btn" class="ke-btn ke-btn-secondary">+ Add</button>
                </div>
                <p class="ke-hint" style="font-size:12px; color:var(--kiwi-legacy-text-muted); margin:6px 2px 0;">
                    Commission is calculated per ticket on the base price (excludes service fees and taxes).
                </p>

                <?php
                // Per-event additional terms — appended to the global terms in assignment emails.
                $event_terms_value = $post_id ? (string) get_post_meta( $post_id, '_ke_promoter_terms', true ) : '';
                ?>
                <div style="margin-top:18px; padding:12px 14px; background:var(--kiwi-legacy-stone-50); border:1px solid var(--kiwi-legacy-stone-200); border-radius:8px;">
                    <label class="ke-label" for="ke_promoter_event_terms" style="display:block; font-weight:600; margin-bottom:6px;">
                        Additional terms for this event (optional)
                    </label>
                    <?php
                    wp_editor( $event_terms_value, 'ke_promoter_event_terms', array(
                        'textarea_name' => 'ke_promoter_event_terms',
                        'textarea_rows' => 6,
                        'media_buttons' => false,
                        'teeny'         => true,
                        'quicktags'     => true,
                    ) );
                    ?>
                    <p class="ke-hint" style="font-size:12px; color:var(--kiwi-legacy-text-muted); margin:6px 0 0;">
                        Rendered in the promoter assignment email after the global terms. Supports
                        <code>{commission_rate}</code>, <code>{event_name}</code>, <code>{organizer_name}</code>.
                    </p>
                </div>
            </div>

        </div><!-- /#ke-step-2 -->

        <!-- ══════════════════════════════════════════════
             STEP 3 — TICKETS
        ══════════════════════════════════════════════ -->
        <div class="ke-wizard-panel" id="ke-step-3">

            <div id="ke-step3-error" class="ke-builder-error" style="display:none;"></div>

            <div class="ke-form-group">
                <label class="ke-label">Service Fee</label>
                <?php if ( ! empty( $all_fees ) ) : ?>
                    <select id="ke-service-fee-id" class="ke-select">
                        <option value="">No service fee</option>
                        <?php foreach ( $all_fees as $fee ) :
                            $sel  = ( $fee['id'] === $service_fee_id ) ? 'selected' : '';
                            $lbl  = esc_html( $fee['name'] );
                            if ( $fee['type'] === 'formula' ) {
                                $lbl .= ' — ' . number_format( floatval( $fee['percentage'] ), 2 ) . '% + $' . number_format( floatval( $fee['fixed_amount'] ), 2 );
                            } else {
                                $lbl .= ' — $' . number_format( floatval( $fee['fixed_amount'] ), 2 ) . ' fixed';
                            }
                        ?>
                        <option value="<?php echo esc_attr( $fee['id'] ); ?>" <?php echo $sel; ?>><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="ke-hint">Applied on top of each paid ticket at checkout. Manage fees in <a href="<?php echo esc_url( admin_url( 'admin.php?page=ke-settings' ) ); ?>">Settings</a>.</p>
                <?php else : ?>
                    <p class="ke-hint">No fees defined. <a href="<?php echo esc_url( admin_url( 'admin.php?page=ke-settings' ) ); ?>">Create a service fee</a> first.</p>
                    <input type="hidden" id="ke-service-fee-id" value="">
                <?php endif; ?>
            </div>

            <div class="ke-divider"></div>

            <!-- Template loader bar (shown when organizer has templates) -->
            <div id="ke-tpl-loader-bar" class="ke-tpl-loader-bar" style="display:none">
                <div class="ke-tpl-loader-bar-left">
                    <span class="ke-tpl-loader-icon">📋</span>
                    <div>
                        <div class="ke-tpl-loader-label" id="ke-tpl-loader-label">Templates available</div>
                        <div class="ke-tpl-loader-sub" id="ke-tpl-loader-sub"></div>
                    </div>
                </div>
                <button type="button" class="ke-btn ke-btn-ghost ke-small" id="ke-load-template-btn">
                    Browse Templates
                </button>
            </div>

            <div class="ke-tickets-header">
                <h3 class="ke-section-title">🎟️ Ticket Types</h3>
                <button type="button" class="ke-btn ke-btn-primary ke-small" id="ke-add-ticket">+ Add Ticket</button>
            </div>

            <div id="ke-ticket-container">
                <div class="ke-empty-tickets" id="ke-no-tickets">
                    <span>🎟️</span>
                    <p>No tickets configured. Add ticket types above <strong>OR</strong> enable reservations below to let customers attend this event.</p>
                </div>
            </div>

            <!-- ══ Reservations section (parallel to tickets) ══ -->
            <div class="ke-divider"></div>

            <div class="ke-resv-section">
                <div class="ke-resv-header">
                    <h3 class="ke-section-title">📅 Reservations</h3>
                    <label class="ke-resv-toggle-wrap">
                        <input type="checkbox" id="ke-resv-enabled" <?php checked( ! empty( $reservations_cfg['enabled'] ) ); ?>>
                        <span>Enable reservations for this event</span>
                    </label>
                </div>

                <p class="ke-hint">
                    Reservations are for venues that need to manage seat capacity (restaurants, lounges, discoteca tables) where a single contact reserves a group of people. They run alongside ticket sales — an event can have either, both, or just reservations.
                </p>

                <div id="ke-resv-body"<?php echo ! empty( $reservations_cfg['enabled'] ) ? ' class="is-open"' : ''; ?>>

                    <div class="ke-resv-info-card">
                        💡 This system also works for discoteca tables, restaurant reservations, lounge bookings, or any group-capacity booking. Configure capacity and areas to match your venue.
                    </div>

                    <!-- ── Module description ── -->
                    <div class="ke-form-group">
                        <label class="ke-label" for="ke-resv-description">Reservations description</label>
                        <textarea id="ke-resv-description" class="ke-input" rows="3" placeholder="Reserve your table for an unforgettable night. Walk-ins welcome but reservations are recommended."></textarea>
                        <p class="ke-hint">Shown above the reservation card on the public event page. Optional.</p>
                    </div>

                    <!-- ── Capacity ── -->
                    <div class="ke-form-group">
                        <label class="ke-label" for="ke-resv-capacity">Total capacity</label>
                        <input type="number" id="ke-resv-capacity" class="ke-input" min="0" step="1" placeholder="40">
                        <p class="ke-hint">Total people that can be reserved combined. Example: 40 means a single party of 10 + parties of 5+5+5+5+10 fills the venue.</p>
                    </div>

                    <!-- ── Capacity display ── -->
                    <div class="ke-form-group">
                        <label class="ke-label">Capacity display</label>
                        <div class="ke-resv-checkboxes">
                            <label class="ke-resv-check">
                                <input type="checkbox" id="ke-resv-show-total-capacity" checked>
                                <span>Show total spots remaining ("X of Y spots left")</span>
                            </label>
                            <label class="ke-resv-check">
                                <input type="checkbox" id="ke-resv-show-area-capacity" checked>
                                <span>Show per-area spots remaining ("X left" on each area)</span>
                            </label>
                        </div>
                        <p class="ke-hint">Capacity is always enforced server-side. These toggles only control whether the numbers are visible to customers.</p>
                    </div>

                    <!-- ── Reservation window ── -->
                    <div class="ke-form-row">
                        <div class="ke-form-group">
                            <label class="ke-label" for="ke-resv-open">Reservations open</label>
                            <input type="datetime-local" id="ke-resv-open" class="ke-input">
                            <p class="ke-hint">When reservations start being accepted.</p>
                        </div>
                        <div class="ke-form-group">
                            <label class="ke-label" for="ke-resv-close">Reservations close</label>
                            <input type="datetime-local" id="ke-resv-close" class="ke-input">
                            <p class="ke-hint">Reservations can no longer be made after this time. Defaults to event start.</p>
                        </div>
                    </div>

                    <!-- ── Confirmation mode ── -->
                    <div class="ke-form-group">
                        <label class="ke-label">Confirmation mode</label>
                        <div class="ke-resv-mode-options">
                            <label class="ke-resv-mode-opt">
                                <input type="radio" name="ke_resv_mode" value="auto" checked>
                                <div>
                                    <strong>Automatic</strong>
                                    <span>Reservations confirm immediately if capacity is available.</span>
                                </div>
                            </label>
                            <label class="ke-resv-mode-opt">
                                <input type="radio" name="ke_resv_mode" value="manual">
                                <div>
                                    <strong>Manual</strong>
                                    <span>Each reservation requires venue approval from the dashboard.</span>
                                </div>
                            </label>
                        </div>
                        <p class="ke-hint">Manual mode lets the venue review each reservation before confirming. Pending reservations still consume capacity until approved or declined.</p>
                    </div>

                    <!-- ── Optional fields toggles ── -->
                    <div class="ke-form-group">
                        <label class="ke-label">Form fields</label>
                        <p class="ke-hint" style="margin-top:-2px;">Standard fields (full name, party size, arrival time, phone) are always shown.</p>
                        <div class="ke-resv-checkboxes">
                            <label class="ke-resv-check">
                                <input type="checkbox" id="ke-resv-show-email" checked>
                                <span>Ask for email</span>
                            </label>
                            <label class="ke-resv-check">
                                <input type="checkbox" id="ke-resv-show-notes" checked>
                                <span>Show "additional notes" textarea</span>
                            </label>
                        </div>
                        <p class="ke-hint">Custom extra fields (dietary, special occasion, etc.) are managed in the Extra Fields editor — set each field's "Show in" option to <em>Reservations</em> or <em>Both</em> to surface it on the reservation form.</p>
                    </div>

                    <!-- ── Areas repeater ── -->
                    <div class="ke-form-group">
                        <label class="ke-label">Areas (optional)</label>
                        <p class="ke-hint">Define sub-areas like VIP, Terrace, Bar. Each area has its own capacity that comes out of the total. Add an optional description and a fancy effect to highlight premium areas.</p>
                        <div id="ke-resv-areas-list"></div>
                        <button type="button" class="ke-btn ke-btn-secondary ke-btn-small" id="ke-resv-area-add">+ Add area</button>
                    </div>

                    <!-- ── Late arrival policy ── -->
                    <div class="ke-form-group">
                        <label class="ke-label">Late arrival policy</label>
                        <label class="ke-resv-check">
                            <input type="checkbox" id="ke-resv-auto-cancel" checked>
                            <span>Auto-cancel after grace period</span>
                        </label>
                        <div class="ke-resv-grace-row" id="ke-resv-grace-row">
                            <input type="number" id="ke-resv-grace" class="ke-input ke-input-narrow" min="0" max="240" step="1" value="15">
                            <span>minutes after reserved arrival time</span>
                        </div>
                        <p class="ke-hint">Reservations will auto-cancel and email the customer if they haven't arrived within X minutes after their reserved time. Cron job runs every 5 minutes.</p>
                    </div>

                </div><!-- /#ke-resv-body -->
            </div><!-- /.ke-resv-section -->

        </div><!-- /#ke-step-3 -->

        <!-- ══ Wizard Navigation ══ -->
        <div class="ke-wizard-nav" id="ke-nav-1">
            <div></div>
            <div class="ke-nav-right">
                <button type="button" class="ke-btn ke-btn-ghost" id="ke-draft-1">Save Draft</button>
                <button type="button" class="ke-btn ke-btn-primary" id="ke-next-1">Continue <span class="dashicons dashicons-arrow-right-alt2"></span></button>
            </div>
        </div>

        <div class="ke-wizard-nav" id="ke-nav-2" style="display:none">
            <div>
                <button type="button" class="ke-btn ke-btn-ghost" id="ke-back-2"><span class="dashicons dashicons-arrow-left-alt2"></span> Back</button>
            </div>
            <div class="ke-nav-right">
                <button type="button" class="ke-btn ke-btn-ghost" id="ke-draft-2">Save Draft</button>
                <button type="button" class="ke-btn ke-btn-primary" id="ke-next-2">Continue <span class="dashicons dashicons-arrow-right-alt2"></span></button>
            </div>
        </div>

        <div class="ke-wizard-nav" id="ke-nav-3" style="display:none">
            <div>
                <button type="button" class="ke-btn ke-btn-ghost" id="ke-back-3"><span class="dashicons dashicons-arrow-left-alt2"></span> Back</button>
            </div>
            <div class="ke-nav-right">
                <button type="button" class="ke-btn ke-btn-ghost" id="ke-draft-3">Save Draft</button>
                <button type="button" class="ke-btn ke-btn-primary" id="ke-publish-btn">
                    <?php echo $is_edit ? '✔ Save Changes' : '🚀 Publish Event'; ?>
                </button>
            </div>
        </div>

    </div><!-- /.ke-builder-card -->
</div><!-- /.ke-builder-wrap -->

<!-- ── Template Picker Modal ── -->
<div id="ke-tpl-picker-overlay" class="ke-modal-overlay" style="display:none">
    <div class="ke-modal" style="max-width:480px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h2 style="font-size:18px;font-weight:800;margin:0;">Load Template</h2>
            <button type="button" id="ke-tpl-picker-close" style="background:none;border:none;cursor:pointer;font-size:18px;color:var(--kiwi-legacy-text-faint);">✕</button>
        </div>
        <p id="ke-tpl-picker-org-name" style="font-size:13px;color:var(--kiwi-legacy-text-muted);margin:0 0 16px;"></p>
        <div id="ke-tpl-picker-list"></div>
        <p class="ke-hint" style="margin-top:12px;">Applying a template will replace any existing ticket types.</p>
    </div>
</div>

<!-- ── Success Modal ── -->
<div id="ke-success-modal" class="ke-modal-overlay" style="display:none">
    <div class="ke-modal">
        <div class="ke-modal-icon">🎉</div>
        <h2 id="ke-modal-title">Event Published!</h2>
        <p id="ke-modal-msg">Your event is now live.</p>
        <div class="ke-modal-actions">
            <a href="#" id="ke-modal-preview" class="ke-btn ke-btn-ghost" target="_blank">Preview Event</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ke-events' ) ); ?>" class="ke-btn ke-btn-primary">Back to Events</a>
        </div>
    </div>
</div>

<?php if ( $is_edit ) : ?>
<!-- ── Delete confirmation modal ── -->
<div class="ke-confirm-overlay" id="ke-delete-overlay" aria-hidden="true">
    <div class="ke-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="ke-delete-title">
        <div class="ke-confirm-icon">🗑</div>
        <h3 class="ke-confirm-title" id="ke-delete-title">Delete this event?</h3>
        <p class="ke-confirm-body">
            Tickets and attendees will be preserved and remain accessible
            in the Attendees section.
        </p>
        <div class="ke-confirm-error" id="ke-delete-error" style="display:none;"></div>
        <div class="ke-confirm-actions">
            <button type="button" class="ke-btn ke-btn-ghost" id="ke-delete-cancel">Cancel</button>
            <button type="button" class="ke-btn ke-btn-danger" id="ke-delete-confirm">Delete Event</button>
        </div>
    </div>
</div>

<style>
.ke-builder-header-actions { margin-left: auto; display: flex; gap: 8px; align-items: center; }
.ke-confirm-overlay {
    position: fixed; inset: 0;
    background: var(--kiwi-shadow-7);
    backdrop-filter:         blur(8px);
    -webkit-backdrop-filter: blur(8px);
    display: none;
    align-items: center; justify-content: center;
    z-index: 99999;
    opacity: 0;
    transition: opacity .18s ease;
}
.ke-confirm-overlay.is-open { display: flex; opacity: 1; }
.ke-confirm-modal {
    background: var(--kiwi-surface);
    -webkit-backdrop-filter: none;
    backdrop-filter: none;
    border: 1px solid var(--kiwi-border-hairline);
    border-radius: 24px;
    box-shadow:
        0 1px 0 var(--kiwi-inner-highlight-strong) inset,
        0 24px 48px var(--kiwi-legacy-knob-shadow-15),
        0 8px 16px var(--kiwi-shadow-5);
    padding: 32px 28px 24px;
    width: min(420px, calc(100vw - 32px));
    text-align: center;
    transform: translateY(12px) scale(.97);
    transition: transform .2s cubic-bezier(.16,1,.3,1);
}
.ke-confirm-overlay.is-open .ke-confirm-modal { transform: none; }
.ke-confirm-icon {
    width: 56px; height: 56px; margin: 0 auto 16px;
    display: grid; place-items: center;
    background: var(--kiwi-legacy-red-50); color: var(--kiwi-legacy-red-500);
    border-radius: 50%; font-size: 26px;
}
.ke-confirm-title { margin: 0 0 8px; font-size: 19px; font-weight: 700; color: var(--kiwi-legacy-text-darkest); }
.ke-confirm-body  { margin: 0 0 20px; color: var(--kiwi-legacy-text-mid); font-size: 14px; line-height: 1.5; }
.ke-confirm-error {
    background: var(--kiwi-legacy-red-error-bg); color: var(--kiwi-legacy-red-700);
    border: 1px solid var(--kiwi-legacy-red-200); border-radius: 10px;
    padding: 10px 12px; font-size: 13px; margin-bottom: 16px;
    text-align: left;
}
.ke-confirm-actions { display: flex; gap: 10px; justify-content: center; }
.ke-confirm-actions .ke-btn { min-width: 110px; }
/* Delete-confirmation modal danger button. Inline because the modal lives
   in this PHP template; values pulled from the Kiwi token system so it
   matches every other .ke-btn-danger in the admin. */
.ke-btn-danger {
    background: var(--kiwi-red); color: var(--kiwi-white-ink); border: 1px solid var(--kiwi-red);
    padding: 12px 24px; border-radius: var(--kiwi-radius-md); font-weight: 600;
    cursor: pointer;
    transition: background .15s var(--kiwi-ease),
                box-shadow .15s var(--kiwi-ease),
                transform .12s var(--kiwi-ease);
    box-shadow:
        0 1px 0 var(--kiwi-inner-highlight-faint) inset,
        0 4px 12px var(--kiwi-red-edge-faint);
}
.ke-btn-danger:hover { background: var(--kiwi-red-darker); border-color: var(--kiwi-red-darker); transform: translateY(-1px); }
.ke-btn-danger:disabled { opacity: .6; cursor: not-allowed; }
.ke-action-btn-delete {
    background: transparent;
    border: 1.5px solid var(--kiwi-red-edge); color: var(--kiwi-red);
    cursor: pointer; font-family: inherit;
    font-size: 13px; font-weight: 600;
    padding: 8px 14px; border-radius: var(--kiwi-radius-sm);
    transition: all .18s var(--kiwi-ease); line-height: 1;
}
.ke-action-btn-delete:hover { background: var(--kiwi-red-fill); border-color: var(--kiwi-red); }
</style>

<script>
(function() {
    var nonce     = '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>';
    var restBase  = '<?php echo esc_js( rest_url('ke/v1/') ); ?>';
    var listUrl   = '<?php echo esc_js( admin_url('admin.php?page=ke-events&deleted=1') ); ?>';
    var eventId   = <?php echo (int) $event_id; ?>;

    var overlay    = document.getElementById('ke-delete-overlay');
    var triggerBtn = document.getElementById('ke-builder-delete-btn');
    var confirmBtn = document.getElementById('ke-delete-confirm');
    var cancelBtn  = document.getElementById('ke-delete-cancel');
    var errorEl    = document.getElementById('ke-delete-error');

    function openModal() {
        errorEl.style.display  = 'none';
        errorEl.textContent    = '';
        confirmBtn.disabled    = false;
        confirmBtn.textContent = 'Delete Event';
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
    }
    function closeModal() {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
    }

    triggerBtn.addEventListener('click', openModal);
    cancelBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
    });

    confirmBtn.addEventListener('click', function() {
        confirmBtn.disabled    = true;
        confirmBtn.textContent = 'Deleting…';
        errorEl.style.display  = 'none';

        fetch(restBase + 'events/' + eventId, {
            method: 'DELETE',
            headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }
        })
        .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
        .then(function(res) {
            if (res.ok && res.data && res.data.success) {
                window.location.href = listUrl;
            } else {
                var msg = (res.data && res.data.message) || 'Could not delete event.';
                errorEl.textContent    = msg;
                errorEl.style.display  = 'block';
                confirmBtn.disabled    = false;
                confirmBtn.textContent = 'Delete Event';
            }
        })
        .catch(function() {
            errorEl.textContent    = 'Network error. Please try again.';
            errorEl.style.display  = 'block';
            confirmBtn.disabled    = false;
            confirmBtn.textContent = 'Delete Event';
        });
    });
})();
</script>
<?php endif; ?>

<!-- SortableJS + canvas-confetti (for drag-drop tickets and publish celebration) -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>

<script>
window.keIsEdit             = <?php echo $is_edit ? 'true' : 'false'; ?>;
window.keEventId            = <?php echo (int) $event_id; ?>;
window.keExistingSlug       = <?php echo wp_json_encode( $post ? (string) $post->post_name : '' ); ?>;
<?php
// Slug locking flag (Phase 2). Persists across reloads via post meta
// `_ke_slug_manually_set`. New events default to false (auto-tracking on).
// Existing events that haven't had the Phase 3 migration applied yet also
// default to false here; the migration flips them to true so legacy URLs
// don't shift when an admin edits an old title. Cast to int → JS bool so
// the wire format is predictable.
$ke_slug_manually_set = false;
if ( $post && $event_id > 0 ) {
    $ke_slug_manually_set = (bool) get_post_meta( $event_id, '_ke_slug_manually_set', true );
}
?>
window.keSlugManuallySet    = <?php echo $ke_slug_manually_set ? 'true' : 'false'; ?>;
window.keExistingTickets    = <?php echo $tickets_json; ?>;
window.keExistingExtras     = <?php echo $extras_json; ?>;
window.keExistingExtraFields = <?php echo $extra_fields_json; ?>;
window.keExistingReservations = <?php echo $reservations_json; ?>;
window.keServiceFees        = <?php echo $fees_json; ?>;
</script>

<script>
// Defensive vanilla-JS toggle for the Reservations panel.
//
// The main builder logic lives in ke-event-builder.js inside a single
// jQuery(document).ready() IIFE — if anything earlier in that ~1900-line
// file throws on production, every handler after the throw never binds
// and the user is left with a checkbox that does nothing. This isolated
// listener runs independently with try/catch + null-checks so the
// reservations toggle keeps working even if the main script fails.
(function () {
    function init() {
        try {
            var toggle = document.getElementById('ke-resv-enabled');
            var panel  = document.getElementById('ke-resv-body');
            if (!toggle || !panel) return;
            // Sync initial state from the (server-pre-checked) checkbox.
            panel.classList.toggle('is-open', !!toggle.checked);
            toggle.addEventListener('change', function () {
                panel.classList.toggle('is-open', !!toggle.checked);
            });
        } catch (e) {
            if (window.console && console.error) {
                console.error('KiwiEvents: reservations toggle init failed', e);
            }
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

// Defensive vanilla-JS fallback for wizard step navigation.
//
// Same rationale as the reservations toggle above: if jQuery or the main
// ke-event-builder.js IIFE throws in production (CDN miss, stale edge cache,
// runtime error before handlers bind), the step pills become inert and the
// user can't move between General Info / Customization / Tickets. This
// listener wires click + keyboard navigation independently of jQuery and
// only activates if window.keWizardJsReady didn't flip to true within 800ms,
// so it never double-fires with the main handler when things are healthy.
(function () {
    var ACTIVATE_DELAY_MS = 800;

    function activateFallback() {
        if (window.keWizardJsReady) return;
        try {
            var steps  = document.querySelectorAll('.ke-wizard-step-item');
            var panels = document.querySelectorAll('.ke-wizard-panel');
            var lines  = document.querySelectorAll('.ke-wizard-line');
            var navs   = document.querySelectorAll('.ke-wizard-nav');
            if (!steps.length || !panels.length) return;

            var isEdit = !!window.keIsEdit;

            function jumpTo(target) {
                if (target < 1 || target > 3) return;

                // Panels
                panels.forEach(function (p) { p.classList.remove('active'); });
                var panel = document.getElementById('ke-step-' + target);
                if (panel) panel.classList.add('active');

                // Nav bars
                navs.forEach(function (n) { n.style.display = 'none'; });
                var nav = document.getElementById('ke-nav-' + target);
                if (nav) nav.style.display = '';

                // Step pills + connector lines
                steps.forEach(function (s) {
                    var n = parseInt(s.getAttribute('data-step'), 10);
                    s.classList.remove('active', 'done');
                    var state;
                    if (n === target) {
                        state = 'active';
                        s.classList.add('active');
                    } else if (n < target) {
                        state = 'completed';
                        s.classList.add('done');
                    } else if (isEdit) {
                        state = 'available';
                    } else {
                        state = 'locked';
                    }
                    s.setAttribute('data-step-state', state);
                    var reachable = state !== 'locked';
                    s.setAttribute('data-clickable', reachable ? 'true' : 'false');
                    s.setAttribute('data-locked',    reachable ? 'false' : 'true');
                    s.setAttribute('aria-disabled',  reachable ? 'false' : 'true');
                });
                lines.forEach(function (line, i) {
                    var left  = document.querySelector('.ke-wizard-step-item[data-step="' + (i + 1) + '"]');
                    var right = document.querySelector('.ke-wizard-step-item[data-step="' + (i + 2) + '"]');
                    var lit   = left  && left.getAttribute('data-step-state')  !== 'locked'
                             && right && right.getAttribute('data-step-state') !== 'locked';
                    line.setAttribute('data-line-state', lit ? 'lit' : 'dim');
                    line.classList.toggle('done', i < target - 1);
                });

                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            steps.forEach(function (step) {
                step.addEventListener('click', function () {
                    if (step.getAttribute('data-locked') === 'true') return;
                    var n = parseInt(step.getAttribute('data-step'), 10);
                    if (!isNaN(n)) jumpTo(n);
                });
                step.addEventListener('keydown', function (e) {
                    if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
                    if (step.getAttribute('data-locked') === 'true') return;
                    e.preventDefault();
                    var n = parseInt(step.getAttribute('data-step'), 10);
                    if (!isNaN(n)) jumpTo(n);
                });
            });

            if (window.console && console.warn) {
                console.warn('KiwiEvents: wizard fallback navigation engaged (main JS did not signal ready).');
            }
        } catch (e) {
            if (window.console && console.error) {
                console.error('KiwiEvents: wizard fallback init failed', e);
            }
        }
    }

    function schedule() {
        setTimeout(activateFallback, ACTIVATE_DELAY_MS);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', schedule);
    } else {
        schedule();
    }
})();
</script>
