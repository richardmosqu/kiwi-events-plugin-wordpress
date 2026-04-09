<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$organizers = get_terms( array(
    'taxonomy'   => 'ke_organizer',
    'hide_empty' => false,
) );

wp_enqueue_media();
?>
<div class="wrap ke-builder-wrap">

    <!-- ── Header ── -->
    <div class="ke-builder-header">
        <div class="ke-builder-title">
            <h1>Event Organizers</h1>
        </div>
    </div>

    <div class="ke-split-layout" style="padding-top:8px;">

        <!-- ── Form ── -->
        <div class="ke-form-card">
            <h3>Add Organizer</h3>
            <p>Create an organizer profile to associate with your events.</p>

            <form method="POST" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <input type="hidden" name="action" value="ke_add_organizer">
                <?php wp_nonce_field('ke_add_organizer_nonce'); ?>

                <div class="ke-field">
                    <label>Name <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="organizer_name" required placeholder="e.g. Kiwi Productions">
                </div>

                <div class="ke-field">
                    <label>Logo Photo</label>
                    <div class="ke-image-uploader" id="ke-org-logo-uploader" style="padding:16px;">
                        <div class="uploader-preview"></div>
                        <button type="button" class="ke-btn ke-btn-ghost" style="padding:6px 12px; font-size:12px;">Choose Logo</button>
                        <input type="hidden" name="organizer_logo_id" id="organizer_logo_id" value="">
                    </div>
                </div>

                <button type="submit" class="ke-submit-full" style="margin-top:16px;">Add Organizer</button>
            </form>
        </div>

        <!-- ── Grid ── -->
        <div>
            <?php if ( empty( $organizers ) || is_wp_error( $organizers ) ) : ?>
                <div class="ke-card">
                    <div class="ke-empty-state">
                        <span class="ke-empty-state-icon">🎪</span>
                        <h3>No Organizers Yet</h3>
                        <p>Use the form to create your first organizer.</p>
                    </div>
                </div>
            <?php else : ?>
                <div class="ke-item-grid">
                    <?php foreach ( $organizers as $org ) :
                        $delete_url = wp_nonce_url(
                            admin_url('admin-post.php?action=ke_delete_organizer&term_id=' . $org->term_id),
                            'ke_delete_org_' . $org->term_id
                        );
                        $logo_id = get_term_meta( $org->term_id, 'ke_organizer_logo', true );
                        $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
                    ?>
                        <div class="ke-item-card">
                            <div class="ke-item-card-header" style="align-items:center;">
                                <?php if ( $logo_url ) : ?>
                                    <img src="<?php echo esc_url( $logo_url ); ?>" alt="Logo" style="width:48px; height:48px; border-radius:50%; object-fit:cover; border:1px solid rgba(226,232,240,0.6); flex-shrink:0;">
                                <?php else : ?>
                                    <div class="ke-item-card-icon" style="width:48px; height:48px; font-size:20px; flex-shrink:0;">🎪</div>
                                <?php endif; ?>
                                
                                <div>
                                    <div class="ke-id-chip" style="margin-bottom:4px;">ID <?php echo esc_html($org->term_id); ?></div>
                                    <h3 class="ke-item-card-name"><?php echo esc_html( $org->name ); ?></h3>
                                    <div class="ke-item-card-meta">
                                        Slug: <code style="font-size:11px;"><?php echo esc_html( $org->slug ); ?></code>
                                    </div>
                                </div>
                            </div>
                            <div class="ke-item-card-footer">
                                <span class="ke-item-count">📅 <?php echo intval($org->count); ?> Event<?php echo $org->count !== 1 ? 's' : ''; ?></span>
                                <a href="<?php echo esc_url($delete_url); ?>"
                                   onclick="return confirm('Delete this organizer?');"
                                   class="ke-btn ke-btn-danger" style="font-size:12px; padding:6px 12px;">Delete</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
jQuery(document).ready(function($) {
    let orgMediaUploader;
    $('#ke-org-logo-uploader .ke-btn').on('click', function(e) {
        e.preventDefault();
        
        if (orgMediaUploader) {
            orgMediaUploader.open();
            return;
        }
        
        orgMediaUploader = wp.media.frames.file_frame = wp.media({
            title: 'Choose Organizer Logo',
            button: { text: 'Select Logo' },
            multiple: false
        });

        orgMediaUploader.on('select', function() {
            const attachment = orgMediaUploader.state().get('selection').first().toJSON();
            $('#organizer_logo_id').val(attachment.id);
            
            const preview = $('#ke-org-logo-uploader .uploader-preview');
            let img = preview.find('img');
            if (img.length === 0) {
                img = $('<img>').appendTo(preview).css({
                    'width': '64px',
                    'height': '64px',
                    'border-radius': '50%',
                    'object-fit': 'cover',
                    'margin-bottom': '12px'
                });
            }
            img.attr('src', attachment.url).show();
            $('#ke-org-logo-uploader .ke-btn').text('Change Logo');
        });

        orgMediaUploader.open();
    });
});
</script>
