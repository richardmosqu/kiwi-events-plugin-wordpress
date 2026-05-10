<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extra Fields per event — admin-defined custom questions collected at
 * checkout (university name, dietary, shirt size, dance crew, etc.).
 *
 * Configuration lives in post meta `_ke_event_extra_fields`:
 *   array(
 *     'enabled' => bool,
 *     'fields'  => array of array( id, label, helper, type, required, options[] )
 *   )
 *
 * Per-attendee answers are stored as JSON in `wp_ke_tickets.extra_fields_data`
 * keyed by the field's stable `id` (fld_xxxxxx) so renaming a label later
 * doesn't orphan historical data.
 *
 * Centralised here so the same shape/validation/sanitization is used by:
 *   - Event builder save                (admin → REST update_event)
 *   - Checkout validation               (REST checkout)
 *   - Attendees list rendering          (admin + organizer dashboard + CSV)
 *   - PDF reports                       (organizer per-event report)
 *   - Confirmation emails               (KE_Email)
 */
class KE_Event_Extra_Fields {

    const META_KEY = '_ke_event_extra_fields';

    /** Field types supported by both UI and validator. */
    public static function allowed_types() {
        return array( 'text', 'textarea', 'number', 'email', 'phone', 'select' );
    }

    /**
     * Visibility controls which form (tickets / reservations / both) shows
     * a given extra field. Default 'tickets' preserves the pre-reservations
     * behaviour for fields saved before this column existed.
     */
    public static function allowed_visibilities() {
        return array( 'tickets', 'reservations', 'both' );
    }

    public static function default_visibility() {
        return 'tickets';
    }

    /**
     * Read + normalise the saved configuration. Always returns an array
     * shaped { enabled: bool, fields: array }, even when the meta is empty
     * or malformed — callers don't have to defend against missing keys.
     */
    public static function get_config( $event_id ) {
        $event_id = (int) $event_id;
        $raw = get_post_meta( $event_id, self::META_KEY, true );
        if ( ! is_array( $raw ) ) {
            return array( 'enabled' => false, 'fields' => array() );
        }
        $enabled = ! empty( $raw['enabled'] );
        $fields  = array();
        if ( ! empty( $raw['fields'] ) && is_array( $raw['fields'] ) ) {
            foreach ( $raw['fields'] as $f ) {
                if ( ! is_array( $f ) ) continue;
                $type = isset( $f['type'] ) ? (string) $f['type'] : 'text';
                if ( ! in_array( $type, self::allowed_types(), true ) ) $type = 'text';
                $vis = isset( $f['visibility'] ) ? (string) $f['visibility'] : self::default_visibility();
                if ( ! in_array( $vis, self::allowed_visibilities(), true ) ) {
                    $vis = self::default_visibility();
                }
                $fields[] = array(
                    'id'         => (string) ( $f['id'] ?? '' ),
                    'label'      => (string) ( $f['label']  ?? '' ),
                    'helper'     => (string) ( $f['helper'] ?? '' ),
                    'type'       => $type,
                    'required'   => ! empty( $f['required'] ),
                    'visibility' => $vis,
                    'options'    => ( $type === 'select' && ! empty( $f['options'] ) && is_array( $f['options'] ) )
                                    ? array_values( array_filter( array_map( 'strval', $f['options'] ), 'strlen' ) )
                                    : array(),
                );
            }
        }
        return array( 'enabled' => $enabled, 'fields' => $fields );
    }

    /** True when the event has the toggle on AND at least one valid field. */
    public static function is_active( $event_id ) {
        $cfg = self::get_config( $event_id );
        return ! empty( $cfg['enabled'] ) && ! empty( $cfg['fields'] );
    }

    /**
     * Return the fields that should appear in a given context. `$context`
     * is 'tickets' or 'reservations'. Fields with visibility 'both' appear
     * in either; fields default to 'tickets' for back-compat.
     */
    public static function get_fields_for( $event_id, $context ) {
        $cfg = self::get_config( $event_id );
        if ( empty( $cfg['enabled'] ) || empty( $cfg['fields'] ) ) return array();
        $context = ( $context === 'reservations' ) ? 'reservations' : 'tickets';
        $out = array();
        foreach ( $cfg['fields'] as $f ) {
            $vis = $f['visibility'] ?? self::default_visibility();
            if ( $vis === 'both' || $vis === $context ) {
                $out[] = $f;
            }
        }
        return $out;
    }

    /**
     * Sanitize a posted config blob coming from the event builder. Drops
     * unknown keys, regenerates ids when missing/malformed, and skips fields
     * with no label (the only required-on-save attribute — empty rows are
     * almost always abandoned UI rows the user forgot to delete).
     */
    public static function sanitize_config( $input ) {
        $out = array( 'enabled' => false, 'fields' => array() );
        if ( ! is_array( $input ) ) return $out;
        $out['enabled'] = ! empty( $input['enabled'] );

        if ( empty( $input['fields'] ) || ! is_array( $input['fields'] ) ) return $out;
        $seen_ids = array();
        foreach ( $input['fields'] as $f ) {
            if ( ! is_array( $f ) ) continue;
            $label = isset( $f['label'] ) ? sanitize_text_field( (string) $f['label'] ) : '';
            if ( $label === '' ) continue;

            $type = isset( $f['type'] ) ? (string) $f['type'] : 'text';
            if ( ! in_array( $type, self::allowed_types(), true ) ) $type = 'text';

            $id = isset( $f['id'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $f['id'] ) ) : '';
            if ( ! $id || strpos( $id, 'fld_' ) !== 0 || isset( $seen_ids[ $id ] ) ) {
                $id = self::generate_id();
            }
            $seen_ids[ $id ] = true;

            $options = array();
            if ( $type === 'select' && ! empty( $f['options'] ) && is_array( $f['options'] ) ) {
                foreach ( $f['options'] as $opt ) {
                    $opt = sanitize_text_field( (string) $opt );
                    if ( $opt !== '' ) $options[] = $opt;
                }
            }

            $vis = isset( $f['visibility'] ) ? (string) $f['visibility'] : self::default_visibility();
            if ( ! in_array( $vis, self::allowed_visibilities(), true ) ) {
                $vis = self::default_visibility();
            }
            $out['fields'][] = array(
                'id'         => $id,
                'label'      => $label,
                'helper'     => isset( $f['helper'] ) ? sanitize_text_field( (string) $f['helper'] ) : '',
                'type'       => $type,
                'required'   => ! empty( $f['required'] ),
                'visibility' => $vis,
                'options'    => $options,
            );
        }
        return $out;
    }

    /** fld_ + 6 lowercase alphanumerics. Stable across renames. */
    public static function generate_id() {
        return 'fld_' . substr( md5( wp_generate_password( 12, true, true ) . microtime( true ) ), 0, 6 );
    }

    /**
     * Sanitize one submitted answer using the field's declared type. Returns
     * the cleaned value as a string (we always store strings; numeric/email
     * just controls the cleanup style).
     */
    public static function sanitize_value( $field, $value ) {
        $type = $field['type'] ?? 'text';
        if ( is_array( $value ) ) {
            // Only the (single) select uses an array form in the wild; flatten
            // by taking the first entry to avoid silently joining unrelated
            // values that would later look like a single weird answer.
            $value = reset( $value );
        }
        $value = (string) ( $value ?? '' );

        switch ( $type ) {
            case 'email':
                $clean = sanitize_email( $value );
                return $clean ? $clean : '';
            case 'phone':
                // Keep digits, +, spaces, parentheses, dashes — drop anything else.
                return trim( preg_replace( '/[^0-9+\-\s()]/', '', $value ) );
            case 'number':
                if ( $value === '' ) return '';
                return (string) floatval( $value );
            case 'textarea':
                return sanitize_textarea_field( $value );
            case 'select':
                $clean   = sanitize_text_field( $value );
                $options = $field['options'] ?? array();
                // Reject values not in the configured options list — protects
                // against tampered submissions injecting arbitrary strings.
                if ( ! empty( $options ) && ! in_array( $clean, $options, true ) ) return '';
                return $clean;
            case 'text':
            default:
                return sanitize_text_field( $value );
        }
    }

    /**
     * Validate submitted answers for a single attendee against the event's
     * field config. Returns array of [field_id => clean_value] on success,
     * or WP_Error('extra_field_required'|'extra_field_invalid') on failure.
     *
     * Empty optional fields are stored as '' so the answer's absence is
     * distinguishable from "field never existed at sale time".
     */
    public static function validate_attendee( $event_id, $submitted, $context = 'tickets' ) {
        $fields = self::get_fields_for( $event_id, $context );
        if ( empty( $fields ) ) {
            return array();
        }
        if ( ! is_array( $submitted ) ) $submitted = array();

        $clean = array();
        foreach ( $fields as $field ) {
            $raw   = $submitted[ $field['id'] ] ?? '';
            $value = self::sanitize_value( $field, $raw );

            if ( ! empty( $field['required'] ) && $value === '' ) {
                return new WP_Error(
                    'extra_field_required',
                    /* translators: %s: extra field label */
                    sprintf( __( 'Please complete: %s', 'kiwi-events' ), $field['label'] ),
                    array( 'status' => 400, 'field_id' => $field['id'] )
                );
            }
            // Email format check on non-empty input — sanitize_email above
            // can return '' for malformed addresses; surface that as a 400.
            if ( $field['type'] === 'email' && $raw !== '' && $value === '' ) {
                return new WP_Error(
                    'extra_field_invalid',
                    sprintf( __( 'Please enter a valid email for: %s', 'kiwi-events' ), $field['label'] ),
                    array( 'status' => 400, 'field_id' => $field['id'] )
                );
            }
            $clean[ $field['id'] ] = $value;
        }
        return $clean;
    }

    /**
     * Decode stored extra_fields_data and resolve each id → label using the
     * current field config. Returns array of { id, label, value, type }.
     * Falls back to the stored id as the label when the field has been
     * removed from the config (graceful degradation for old answers).
     */
    public static function resolve_for_ticket( $event_id, $stored_json ) {
        if ( ! $stored_json ) return array();
        $decoded = json_decode( (string) $stored_json, true );
        if ( ! is_array( $decoded ) || empty( $decoded ) ) return array();

        $cfg     = self::get_config( $event_id );
        $by_id   = array();
        foreach ( $cfg['fields'] as $f ) $by_id[ $f['id'] ] = $f;

        $out = array();
        foreach ( $decoded as $id => $value ) {
            $field = $by_id[ $id ] ?? null;
            $out[] = array(
                'id'    => (string) $id,
                'label' => $field ? (string) $field['label'] : (string) $id,
                'value' => is_scalar( $value ) ? (string) $value : '',
                'type'  => $field ? (string) $field['type']  : 'text',
            );
        }
        return $out;
    }
}
