<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Editable event slug + automatic 301 redirect from retired slugs.
 *
 * Three responsibilities:
 *  1. Admin meta box on ke_event showing current URL + editable slug + history.
 *  2. Recording each slug change into wp_ke_event_slug_history on post_updated.
 *  3. Intercepting requests to retired slugs (parse_request) and serving a
 *     301 to the event's current permalink.
 *
 * Schema for the history table lives in KE_Activator::create_tables() so
 * activation + version-bumped reactivation create/migrate it via dbDelta.
 */
class KE_Event_Slug {

    const META_BOX_ID    = 'ke_event_slug_editor';
    const NONCE_NAME     = 'ke_event_slug_nonce';
    const NONCE_ACTION   = 'ke_event_slug_save';
    const INPUT_NAME     = 'ke_event_slug';
    const CACHE_GROUP    = 'ke_slug_history';
    const CACHE_TTL      = 5 * MINUTE_IN_SECONDS;

    /**
     * Hook into WordPress
     */
    public function init() {
        add_action( 'edit_form_after_title', array( $this, 'render_url_row' ) );
        add_action( 'post_updated',          array( $this, 'record_slug_change' ), 10, 3 );
        add_action( 'save_post_ke_event',    array( $this, 'save_slug_input' ), 1, 2 );
        add_action( 'parse_request',         array( $this, 'maybe_redirect_old_slug' ), 5 );
    }

    /* ─── Schema helper ──────────────────────────────────────────────── */

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ke_event_slug_history';
    }

    /* ─── Phase A: URL row directly under the title box ──────────────── */

    /**
     * Render the URL display + pencil-edit toggle directly under the post
     * title input. Uses edit_form_after_title so it sits inside #titlediv —
     * visually attached to the title box, matching the user's request.
     */
    public function render_url_row( $post ) {
        if ( ! $post instanceof WP_Post || $post->post_type !== 'ke_event' ) return;

        wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

        $current_slug = $post->post_name;
        if ( empty( $current_slug ) ) {
            $current_slug = sanitize_title( $post->post_title );
        }

        $rewrite_slug = 'events';
        $pt_obj = get_post_type_object( 'ke_event' );
        if ( $pt_obj && ! empty( $pt_obj->rewrite['slug'] ) ) {
            $rewrite_slug = $pt_obj->rewrite['slug'];
        }
        $front_url = home_url( '/' . $rewrite_slug . '/' . $current_slug . '/' );

        $history = $this->get_history_for_event( (int) $post->ID );
        ?>
        <div class="ke-slug-row" data-current-slug="<?php echo esc_attr( $current_slug ); ?>">
            <div class="ke-slug-display">
                <span class="ke-slug-display-label">URL:</span>
                <a class="ke-slug-display-link" href="<?php echo esc_url( $front_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $front_url ); ?></a>
                <button type="button" class="ke-slug-edit-toggle" aria-label="Edit URL slug" title="Edit URL slug">
                    <span class="dashicons dashicons-edit"></span>
                </button>
            </div>

            <div class="ke-slug-editor" hidden>
                <label for="<?php echo esc_attr( self::INPUT_NAME ); ?>" class="screen-reader-text">URL slug</label>
                <input
                    type="text"
                    id="<?php echo esc_attr( self::INPUT_NAME ); ?>"
                    name="<?php echo esc_attr( self::INPUT_NAME ); ?>"
                    value="<?php echo esc_attr( $current_slug ); ?>"
                    class="ke-slug-input"
                    autocomplete="off"
                    spellcheck="false"
                    maxlength="60"
                >
                <button type="button" class="button button-small ke-slug-edit-done">Done</button>
                <div class="ke-slug-status" aria-live="polite" style="min-height:1.4em;margin-top:4px;font-size:12px;"></div>
                <p class="description ke-slug-help">
                    Only lowercase letters, numbers, and hyphens. Max 60 characters. Old URLs will redirect automatically. Saved when you update the event.
                </p>
            </div>

            <?php if ( ! empty( $history ) ) : ?>
                <details class="ke-slug-history">
                    <summary>Previous URLs (<?php echo count( $history ); ?>)</summary>
                    <ul class="ke-slug-history-list">
                        <?php foreach ( $history as $row ) :
                            $ts = strtotime( $row->created_at );
                            $when = $ts ? date_i18n( get_option( 'date_format' ), $ts ) : '';
                        ?>
                            <li>
                                <code><?php echo esc_html( $row->old_slug ); ?></code>
                                <?php if ( $when ) : ?>
                                    <span class="ke-slug-history-when">retired <?php echo esc_html( $when ); ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
        <script>
        (function() {
            var row = document.currentScript.previousElementSibling;
            if ( ! row ) return;
            var toggle  = row.querySelector('.ke-slug-edit-toggle');
            var done    = row.querySelector('.ke-slug-edit-done');
            var editor  = row.querySelector('.ke-slug-editor');
            var display = row.querySelector('.ke-slug-display');
            var input   = row.querySelector('.ke-slug-input');
            var link    = row.querySelector('.ke-slug-display-link');
            var status  = row.querySelector('.ke-slug-status');
            if ( ! toggle || ! editor || ! input ) return;

            var REST_ROOT  = <?php echo wp_json_encode( esc_url_raw( rest_url( 'ke/v1/' ) ) ); ?>;
            var REST_NONCE = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
            var EVENT_ID   = <?php echo (int) $post->ID; ?>;
            var ORIGINAL   = <?php echo wp_json_encode( $current_slug ); ?>;

            // Save / Publish buttons we'll disable on invalid input.
            var saveBtns = document.querySelectorAll(
                '#publish, #save-post, #post-preview'
            );
            function setSubmitDisabled(disabled) {
                saveBtns.forEach(function (b) { b.disabled = !!disabled; });
            }

            function setStatus(state, text) {
                if (!status) return;
                var color = '#666';
                if (state === 'ok')      color = '#15803d';
                if (state === 'warn')    color = '#a16207';
                if (state === 'error')   color = '#b91c1c';
                if (state === 'pending') color = '#6b7280';
                status.style.color = color;
                status.textContent = text || '';
            }

            function explain(reason) {
                switch (reason) {
                    case 'invalid_format': return '✗ Invalid characters — use a–z, 0–9, hyphens only (no leading / trailing / double hyphens).';
                    case 'too_long':       return '✗ Too long — maximum 60 characters.';
                    case 'in_use':         return '⚠ Slug already in use by another event.';
                    case 'reserved':       return '⚠ This slug is reserved by the system.';
                    default:               return '⚠ Slug is not available.';
                }
            }

            var debounceTimer = null;
            var lastQuery = null;
            function validate() {
                var v = (input.value || '').trim();

                // Unchanged → silently OK, no network call.
                if (v === ORIGINAL) {
                    setStatus('', '');
                    setSubmitDisabled(false);
                    return;
                }
                if (v === '') {
                    setStatus('error', '✗ Slug cannot be empty.');
                    setSubmitDisabled(true);
                    return;
                }
                setStatus('pending', 'Checking…');
                setSubmitDisabled(true);

                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function () {
                    var url = REST_ROOT + 'events/check-slug?slug=' + encodeURIComponent(v)
                            + '&exclude_id=' + encodeURIComponent(EVENT_ID);
                    lastQuery = v;
                    fetch(url, {
                        credentials: 'same-origin',
                        headers: { 'X-WP-Nonce': REST_NONCE }
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        // Ignore stale responses (user kept typing past this one).
                        if (lastQuery !== v) return;
                        if (data && data.available) {
                            setStatus('ok', '✓ Available');
                            setSubmitDisabled(false);
                        } else {
                            setStatus(data && data.reason === 'invalid_format' ? 'error' : 'warn',
                                      explain(data && data.reason));
                            setSubmitDisabled(true);
                        }
                    })
                    .catch(function () {
                        // Network blip — don't block saving; let the server-side
                        // sanitize_title at save time be the final authority.
                        setStatus('warn', '⚠ Could not verify availability. Save to try.');
                        setSubmitDisabled(false);
                    });
                }, 300);
            }

            function show() {
                editor.hidden = false;
                row.classList.add('is-editing');
                input.focus();
                input.select();
                validate();
            }
            function hide() {
                editor.hidden = true;
                row.classList.remove('is-editing');
                // Live-preview the URL text (real save still happens on post update).
                try {
                    var slug = input.value.trim() || row.dataset.currentSlug || '';
                    var url = new URL( link.href );
                    var parts = url.pathname.replace(/\/$/, '').split('/');
                    if ( parts.length >= 2 ) {
                        parts[ parts.length - 1 ] = slug;
                        url.pathname = parts.join('/') + '/';
                        link.href = url.toString();
                        link.textContent = url.toString();
                    }
                } catch (e) {}
            }
            toggle.addEventListener('click', show);
            if ( done ) done.addEventListener('click', hide);
            input.addEventListener('input', validate);
            input.addEventListener('keydown', function(e) {
                if ( e.key === 'Enter' ) { e.preventDefault(); hide(); }
                if ( e.key === 'Escape' ) { hide(); }
            });
        })();
        </script>
        <?php
    }

    /* ─── Phase A: capture slug input before WP's save runs ──────────── */

    /**
     * Inject the sanitized slug from the meta box into the post update.
     * `save_post_ke_event` fires after WP has already written post_name,
     * so to influence it we update post_name in a follow-up wp_update_post()
     * call. The collision suffix (-2, -3) is handled by WP automatically.
     */
    public function save_slug_input( $post_id, $post ) {
        if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) return;
        if ( ! wp_verify_nonce( $_POST[ self::NONCE_NAME ], self::NONCE_ACTION ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        if ( ! isset( $_POST[ self::INPUT_NAME ] ) ) return;
        if ( wp_is_post_revision( $post_id ) ) return;

        $raw = (string) wp_unslash( $_POST[ self::INPUT_NAME ] );
        $clean = sanitize_title( $raw );

        // Reject blank slugs: keep whatever WP already wrote.
        if ( $clean === '' ) return;

        // Already current — no-op so we don't trigger an extra update cycle.
        if ( $clean === $post->post_name ) return;

        // Defer to wp_update_post; collision handling appends -2, -3, …
        remove_action( 'save_post_ke_event', array( $this, 'save_slug_input' ), 1 );
        wp_update_post( array(
            'ID'        => $post_id,
            'post_name' => $clean,
        ) );
        add_action( 'save_post_ke_event', array( $this, 'save_slug_input' ), 1, 2 );
    }

    /* ─── Phase B: record slug changes ───────────────────────────────── */

    public function record_slug_change( $post_id, $post_after, $post_before ) {
        if ( $post_after->post_type !== 'ke_event' ) return;
        if ( $post_before->post_name === $post_after->post_name ) return;

        $old_slug = $post_before->post_name;
        if ( $old_slug === '' ) return;

        // Don't redirect a slug to itself if it matches the new one.
        if ( $old_slug === $post_after->post_name ) return;

        try {
            global $wpdb;
            $table = self::table_name();

            // Avoid duplicates for the same (event_id, old_slug) pair.
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE event_id = %d AND old_slug = %s LIMIT 1",
                $post_id, $old_slug
            ) );
            if ( $exists ) return;

            $wpdb->insert(
                $table,
                array(
                    'event_id'   => (int) $post_id,
                    'old_slug'   => $old_slug,
                    'created_at' => current_time( 'mysql' ),
                ),
                array( '%d', '%s', '%s' )
            );

            // Bust both the slug→event lookup (old slug just got registered)
            // and any history list cache for this event.
            wp_cache_delete( 'slug_' . $old_slug, self::CACHE_GROUP );
            wp_cache_delete( 'event_' . (int) $post_id, self::CACHE_GROUP );
        } catch ( \Throwable $e ) {
            // Never let a logging hiccup break the post save.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'KE_Event_Slug record_slug_change: ' . $e->getMessage() );
            }
        }
    }

    /* ─── Phase B: 301 redirect from retired slug ────────────────────── */

    public function maybe_redirect_old_slug( $wp ) {
        if ( is_admin() ) return;
        if ( empty( $wp->request ) ) return;
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;

        // Only handle requests under the ke_event front-end slug. This keeps
        // unrelated 404s (/foo-bar-baz) from triggering a history lookup.
        $pt_obj = get_post_type_object( 'ke_event' );
        $rewrite_slug = ( $pt_obj && ! empty( $pt_obj->rewrite['slug'] ) )
            ? $pt_obj->rewrite['slug']
            : 'events';

        // Normalize the request: WP gives us "events/cinco-de-drinko".
        $request = trim( (string) $wp->request, '/' );
        $prefix = $rewrite_slug . '/';
        if ( strpos( $request, $prefix ) !== 0 ) return;

        $tail = substr( $request, strlen( $prefix ) );
        // Ignore deeper paths like /events/foo/bar/ — only single-segment slugs.
        $tail = trim( $tail, '/' );
        if ( $tail === '' || strpos( $tail, '/' ) !== false ) return;

        $requested_slug = sanitize_title( $tail );
        if ( $requested_slug === '' ) return;

        // If the slug is currently in use by a published event, let WP handle it.
        $existing = get_page_by_path( $requested_slug, OBJECT, 'ke_event' );
        if ( $existing instanceof WP_Post && $existing->post_status === 'publish' ) {
            return;
        }

        $event_id = $this->lookup_event_id_by_old_slug( $requested_slug );
        if ( ! $event_id ) return;

        $event = get_post( $event_id );
        if ( ! $event instanceof WP_Post ) return;
        if ( $event->post_status !== 'publish' ) return;
        if ( $event->post_name === $requested_slug ) return; // safety: never redirect to self

        $target = get_permalink( $event_id );
        if ( ! $target ) return;

        wp_safe_redirect( $target, 301 );
        exit;
    }

    /**
     * Cached lookup: old_slug → event_id. Returns 0 when no row exists.
     * Negative results are cached as 0 to avoid hammering the DB on repeated
     * 404s for the same bogus URL.
     */
    private function lookup_event_id_by_old_slug( $slug ) {
        $key = 'slug_' . $slug;
        $cached = wp_cache_get( $key, self::CACHE_GROUP );
        if ( $cached !== false ) {
            return (int) $cached;
        }

        global $wpdb;
        $table = self::table_name();
        $event_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT event_id FROM {$table} WHERE old_slug = %s ORDER BY id DESC LIMIT 1",
            $slug
        ) );

        wp_cache_set( $key, $event_id, self::CACHE_GROUP, self::CACHE_TTL );
        return $event_id;
    }

    /* ─── Phase C: list history for the meta box ─────────────────────── */

    private function get_history_for_event( $event_id ) {
        if ( ! $event_id ) return array();

        $key = 'event_' . $event_id;
        $cached = wp_cache_get( $key, self::CACHE_GROUP );
        if ( is_array( $cached ) ) return $cached;

        global $wpdb;
        $table = self::table_name();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT old_slug, created_at FROM {$table} WHERE event_id = %d ORDER BY id DESC",
            $event_id
        ) );
        $rows = is_array( $rows ) ? $rows : array();

        wp_cache_set( $key, $rows, self::CACHE_GROUP, self::CACHE_TTL );
        return $rows;
    }
}
