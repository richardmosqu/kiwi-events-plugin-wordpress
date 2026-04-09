<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$categories = get_terms( array(
    'taxonomy'   => 'ke_event_category',
    'hide_empty' => false,
) );
?>
<div class="wrap ke-builder-wrap">

    <!-- ── Header ── -->
    <div class="ke-builder-header">
        <div class="ke-builder-title">
            <h1>Event Categories</h1>
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
                    <label>Name <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="cat_name" required placeholder="e.g. Music Festival">
                </div>

                <button type="submit" class="ke-submit-full">Add Category</button>
            </form>
        </div>

        <!-- ── Grid ── -->
        <div>
            <?php if ( empty( $categories ) || is_wp_error( $categories ) ) : ?>
                <div class="ke-card">
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
</div>
