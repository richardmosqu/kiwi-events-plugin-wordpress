<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$categories = get_terms( array(
    'taxonomy'   => 'ke_event_category',
    'hide_empty' => false,
) );
?>
<div class="wrap ke-builder-wrap">

    <!-- ── Header — own white section card ── -->
    <div class="ke-section-card ke-section-card--compact">
        <div class="ke-builder-header">
            <div class="ke-builder-title">
                <h1>Event Categories</h1>
            </div>
        </div>
    </div>

    <div class="ke-split-layout" style="padding-top:8px;">

        <!-- ── Form ── -->
        <div class="ke-form-card">
            <h3>Add Category</h3>
            <p>Group your events by theme or genre — e.g. "Festival", "Nightlife", "Sports".</p>

            <form method="POST" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <input type="hidden" name="action" value="ke_add_category">
                <?php wp_nonce_field('ke_add_category_nonce'); ?>

                <div class="ke-field">
                    <label>Name <span style="color:var(--kiwi-red);">*</span></label>
                    <input type="text" name="cat_name" required placeholder="e.g. Music Festival">
                </div>

                <button type="submit" class="ke-submit-full">Add Category</button>
            </form>
        </div>

        <!-- ── Grid ── -->
        <div>
            <?php if ( empty( $categories ) || is_wp_error( $categories ) ) : ?>
                <div class="ke-section-card">
                    <div class="ke-empty-state">
                        <span class="ke-empty-state-icon">🗂</span>
                        <h3>No Categories Yet</h3>
                        <p>Use the form to create your first category.</p>
                    </div>
                </div>
            <?php else : ?>
                <div class="ke-item-grid">
                    <?php foreach ( $categories as $cat ) :
                        $delete_url = wp_nonce_url(
                            admin_url('admin-post.php?action=ke_delete_category&term_id=' . $cat->term_id),
                            'ke_delete_cat_' . $cat->term_id
                        );
                        $sc_carousel = sprintf( '[kiwi_events_carousel category="%s"]', $cat->slug );
                        $sc_list     = sprintf( '[kiwi_events_list category="%s"]',     $cat->slug );
                        $sc_calendar = sprintf( '[kiwi_events_calendar category="%s"]', $cat->slug );
                        $sc_id       = 'ke-cat-sc-' . intval( $cat->term_id );
                    ?>
                        <div class="ke-item-card">
                            <div class="ke-item-card-header">
                                <div class="ke-item-card-icon">🗂</div>
                                <div>
                                    <div class="ke-id-chip" style="margin-bottom:4px;">ID <?php echo esc_html($cat->term_id); ?></div>
                                    <h3 class="ke-item-card-name"><?php echo esc_html( $cat->name ); ?></h3>
                                    <div class="ke-item-card-meta">
                                        Slug: <code style="font-size:11px;"><?php echo esc_html( $cat->slug ); ?></code>
                                    </div>
                                </div>
                            </div>

                            <div class="ke-cat-shortcodes ke-cat-shortcodes--inline">
                                <p class="ke-cat-shortcodes-help">Shortcodes for this category:</p>
                                <div class="ke-cat-shortcode-row">
                                    <span class="ke-cat-shortcode-label">Carousel</span>
                                    <div class="ke-cat-shortcode-input-group">
                                        <input type="text"
                                               id="<?php echo esc_attr( $sc_id ); ?>-carousel"
                                               class="ke-cat-shortcode-input"
                                               value="<?php echo esc_attr( $sc_carousel ); ?>"
                                               readonly>
                                        <button type="button"
                                                class="button ke-cat-shortcode-copy"
                                                data-target="<?php echo esc_attr( $sc_id ); ?>-carousel"
                                                aria-label="Copy carousel shortcode"
                                                title="Copy shortcode">
                                            <span class="dashicons dashicons-clipboard"></span>
                                        </button>
                                    </div>
                                </div>
                                <div class="ke-cat-shortcode-row">
                                    <span class="ke-cat-shortcode-label">List</span>
                                    <div class="ke-cat-shortcode-input-group">
                                        <input type="text"
                                               id="<?php echo esc_attr( $sc_id ); ?>-list"
                                               class="ke-cat-shortcode-input"
                                               value="<?php echo esc_attr( $sc_list ); ?>"
                                               readonly>
                                        <button type="button"
                                                class="button ke-cat-shortcode-copy"
                                                data-target="<?php echo esc_attr( $sc_id ); ?>-list"
                                                aria-label="Copy list shortcode"
                                                title="Copy shortcode">
                                            <span class="dashicons dashicons-clipboard"></span>
                                        </button>
                                    </div>
                                </div>
                                <div class="ke-cat-shortcode-row">
                                    <span class="ke-cat-shortcode-label">Calendar</span>
                                    <div class="ke-cat-shortcode-input-group">
                                        <input type="text"
                                               id="<?php echo esc_attr( $sc_id ); ?>-calendar"
                                               class="ke-cat-shortcode-input"
                                               value="<?php echo esc_attr( $sc_calendar ); ?>"
                                               readonly>
                                        <button type="button"
                                                class="button ke-cat-shortcode-copy"
                                                data-target="<?php echo esc_attr( $sc_id ); ?>-calendar"
                                                aria-label="Copy calendar shortcode"
                                                title="Copy shortcode">
                                            <span class="dashicons dashicons-clipboard"></span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="ke-item-card-footer">
                                <span class="ke-item-count">📅 <?php echo intval($cat->count); ?> Event<?php echo $cat->count !== 1 ? 's' : ''; ?></span>
                                <a href="<?php echo esc_url($delete_url); ?>"
                                   onclick="return confirm('Delete this category?');"
                                   class="ke-btn ke-btn-danger" style="font-size:12px; padding:6px 12px;">Delete</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <div class="ke-cat-shortcode-toast" role="status" aria-live="polite" hidden>Copied!</div>

    <script>
    (function() {
        var toast = document.querySelector('.ke-cat-shortcode-toast');
        var toastTimer = null;

        function showToast() {
            if ( ! toast ) return;
            toast.hidden = false;
            toast.classList.add('is-visible');
            if ( toastTimer ) clearTimeout( toastTimer );
            toastTimer = setTimeout(function() {
                toast.classList.remove('is-visible');
                setTimeout(function() {
                    if ( ! toast.classList.contains('is-visible') ) toast.hidden = true;
                }, 200);
            }, 1500);
        }

        function copyText( text ) {
            if ( navigator.clipboard && navigator.clipboard.writeText ) {
                return navigator.clipboard.writeText( text );
            }
            return new Promise(function( resolve, reject ) {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'absolute';
                ta.style.left = '-9999px';
                document.body.appendChild( ta );
                ta.select();
                try { document.execCommand( 'copy' ); resolve(); }
                catch ( err ) { reject( err ); }
                finally { document.body.removeChild( ta ); }
            });
        }

        document.querySelectorAll('.ke-cat-shortcode-copy').forEach(function( btn ) {
            btn.addEventListener('click', function() {
                var target = document.getElementById( btn.getAttribute('data-target') );
                if ( ! target ) return;
                copyText( target.value ).then( showToast, function() {
                    target.focus();
                    target.select();
                });
            });
        });

        document.querySelectorAll('.ke-cat-shortcode-input').forEach(function( input ) {
            input.addEventListener('focus', function() { input.select(); });
        });
    })();
    </script>
</div>
