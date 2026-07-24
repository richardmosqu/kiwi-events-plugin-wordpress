<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Historias Destacadas (Instagram-style highlights).
 *
 * Highlights belong to an ORGANIZER (the ke_organizer taxonomy term), not to a
 * single event — one highlight can appear on many of that organizer's events.
 *
 * Storage: a private CPT `ke_highlight`.
 *   - post_title                 → name
 *   - featured image (thumbnail) → circular cover
 *   - _ke_highlight_images       → ordered array of attachment IDs (story frames)
 *   - _ke_highlight_organizer_id → owning ke_organizer term id
 *   - menu_order                 → sort order in the dashboard / public row
 *
 * This class owns the DATA MODEL + CPT only. CRUD (with organizer-ownership
 * enforcement and hardened uploads) lives in the REST layer; rendering lives in
 * the dashboard manager, the event-builder selector, and the public row/viewer.
 */
class KE_Highlights {

    const POST_TYPE      = 'ke_highlight';
    const META_IMAGES    = '_ke_highlight_images';
    const META_ORGANIZER = '_ke_highlight_organizer_id';

    /** Per-highlight caps (configurable constants). */
    const MAX_IMAGES  = 20;
    const MAX_FILE_MB = 3;

    /** Upload allowlist — SVG deliberately excluded (stored-XSS vector). */
    const ALLOWED_MIMES = array(
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png'          => 'image/png',
        'webp'         => 'image/webp',
    );

    public function init() {
        add_action( 'init', array( $this, 'register_post_type' ) );
        add_action( 'after_setup_theme', array( $this, 'register_image_sizes' ) );
    }

    /**
     * Private CPT — never publicly queryable, no UI, no REST autoroutes. All
     * access goes through the organizer-session REST endpoints, so the CPT
     * itself stays invisible. Rewrite/flush handled globally, not here.
     */
    public function register_post_type() {
        register_post_type( self::POST_TYPE, array(
            'labels' => array(
                'name'          => 'Historias Destacadas',
                'singular_name' => 'Historia destacada',
            ),
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => false,
            'show_in_menu'       => false,
            'show_in_rest'       => false,
            'query_var'          => false,
            'rewrite'            => false,
            'capability_type'    => 'post',
            'hierarchical'       => false,
            'has_archive'        => false,
            'supports'           => array( 'title', 'thumbnail', 'author', 'page-attributes' ),
        ) );
    }

    /**
     * ke_highlight_cover — square cover for the circular grid/row (retina 600px,
     * hard crop keeps the centered subject). Story frames render at full size in
     * the viewer, so no dedicated size is registered for them.
     */
    public function register_image_sizes() {
        add_image_size( 'ke_highlight_cover', 600, 600, true );
    }

    /* ── Data helpers (used by REST CRUD + rendering) ─────────────────────── */

    /** Owning ke_organizer term id for a highlight (0 if none). */
    public static function get_owner( $highlight_id ) {
        return (int) get_post_meta( (int) $highlight_id, self::META_ORGANIZER, true );
    }

    /**
     * True only if $highlight_id is a real ke_highlight owned by exactly this
     * organizer term. The ownership gate for every write — an organizer can
     * never touch another organizer's highlight by tampering with the id.
     */
    public static function belongs_to_organizer( $highlight_id, $organizer_term_id ) {
        $highlight_id      = (int) $highlight_id;
        $organizer_term_id = (int) $organizer_term_id;
        if ( $highlight_id <= 0 || $organizer_term_id <= 0 ) return false;
        if ( get_post_type( $highlight_id ) !== self::POST_TYPE ) return false;
        return self::get_owner( $highlight_id ) === $organizer_term_id;
    }

    /** Ordered story-frame attachment IDs (only ones that still exist). */
    public static function get_images( $highlight_id ) {
        $ids = get_post_meta( (int) $highlight_id, self::META_IMAGES, true );
        if ( ! is_array( $ids ) ) return array();
        $out = array();
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 && get_post_type( $id ) === 'attachment' ) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** All highlights owned by an organizer, ordered by menu_order then newest. */
    public static function get_for_organizer( $organizer_term_id ) {
        $organizer_term_id = (int) $organizer_term_id;
        if ( $organizer_term_id <= 0 ) return array();
        return get_posts( array(
            'post_type'        => self::POST_TYPE,
            'post_status'      => 'publish',
            'numberposts'      => 200,
            'orderby'          => array( 'menu_order' => 'ASC', 'date' => 'DESC' ),
            'meta_key'         => self::META_ORGANIZER,
            'meta_value'       => $organizer_term_id,
            'suppress_filters' => false,
        ) );
    }

    /** Circular cover URL (empty string when no cover / attachment missing). */
    public static function cover_url( $highlight_id, $size = 'ke_highlight_cover' ) {
        $tid = get_post_thumbnail_id( (int) $highlight_id );
        if ( ! $tid ) return '';
        $url = wp_get_attachment_image_url( $tid, $size );
        return $url ?: '';
    }

    /**
     * Card shape for the grid / public row. Story-frame URLs are intentionally
     * NOT included — they're fetched lazily only when the viewer opens, so a
     * page with many highlights doesn't load every frame up front.
     */
    public static function to_card( $highlight_id ) {
        $highlight_id = (int) $highlight_id;
        $post = get_post( $highlight_id );
        if ( ! $post || $post->post_type !== self::POST_TYPE ) return null;
        return array(
            'id'          => $highlight_id,
            'name'        => (string) $post->post_title,
            'cover'       => self::cover_url( $highlight_id ),
            'image_count' => count( self::get_images( $highlight_id ) ),
        );
    }

    /** Full story-frame payload (id, url, w, h) for the viewer — loaded on open. */
    public static function frames_payload( $highlight_id ) {
        $out = array();
        foreach ( self::get_images( $highlight_id ) as $att_id ) {
            $full = wp_get_attachment_image_src( $att_id, 'large' );
            if ( ! $full ) continue;
            $out[] = array(
                'id'  => (int) $att_id,
                'url' => (string) $full[0],
                'w'   => (int) $full[1],
                'h'   => (int) $full[2],
            );
        }
        return $out;
    }
}
