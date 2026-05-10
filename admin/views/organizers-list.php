<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$organizers = get_terms( array( 'taxonomy' => 'ke_organizer', 'hide_empty' => false ) );

wp_enqueue_media();

// Pre-load template counts for all organizers
$tpl_counts = array();
if ( ! is_wp_error( $organizers ) ) {
    foreach ( $organizers as $org ) {
        $raw = get_term_meta( $org->term_id, '_ke_ticket_templates', true );
        $tpl_counts[ $org->term_id ] = is_array( $raw ) ? count( $raw ) : 0;
    }
}
?>
<div class="wrap ke-builder-wrap">

    <!-- ── Header ── -->
    <div class="ke-builder-header">
        <div class="ke-builder-title">
            <h1>Event Organizers</h1>
        </div>
    </div>

    <div class="ke-split-layout" style="padding-top:8px;">

        <!-- ── Add Form ── -->
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

                <div class="ke-field">
                    <label><?php esc_html_e( 'Organizer Password', 'kiwi-events' ); ?></label>
                    <input type="password" name="organizer_scanner_password" value="" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Optional', 'kiwi-events' ); ?>">
                    <p class="ke-help" style="font-size:12px;color:#64748b;margin-top:6px;">
                        <?php esc_html_e( 'This password protects two things: (1) Kiwi Scanner access for staff to scan tickets at events, and (2) the Organizer Dashboard at /organizer/[slug] where you can view sales, attendees, and download reports for all your events.', 'kiwi-events' ); ?>
                    </p>
                </div>

                <button type="submit" class="ke-submit-full" style="margin-top:16px;"><?php esc_html_e( 'Add Organizer', 'kiwi-events' ); ?></button>
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
                        $logo_id  = get_term_meta( $org->term_id, 'ke_organizer_logo', true );
                        $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
                        $n_tpl    = $tpl_counts[ $org->term_id ] ?? 0;
                        $has_pwd  = KE_Scanner_Password::organizer_has_password( $org->term_id );

                        // Public-profile term meta. The pencil/edit modal
                        // reads these so the form pre-fills with whatever's
                        // already saved.
                        $hero_id          = (int) get_term_meta( $org->term_id, 'ke_organizer_hero_image', true );
                        $hero_url         = $hero_id ? wp_get_attachment_image_url( $hero_id, 'medium' ) : '';
                        $org_category     = (string) get_term_meta( $org->term_id, 'ke_organizer_category', true );
                        $org_gallery_ids  = get_term_meta( $org->term_id, 'ke_organizer_gallery_photo_ids', true );
                        $org_gallery_ids  = is_array( $org_gallery_ids ) ? array_values( array_filter( array_map( 'intval', $org_gallery_ids ) ) ) : array();
                        $public_url       = home_url( '/organizers/' . $org->slug . '/' );
                    ?>
                        <div class="ke-item-card ke-org-card">
                            <a href="<?php echo esc_url($delete_url); ?>"
                               class="ke-org-card-delete"
                               aria-label="<?php esc_attr_e( 'Delete organizer', 'kiwi-events' ); ?>"
                               title="<?php esc_attr_e( 'Delete organizer', 'kiwi-events' ); ?>"
                               data-confirm="<?php echo esc_attr( __( 'Delete this organizer?', 'kiwi-events' ) ); ?>">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <line x1="6" y1="6" x2="18" y2="18"/>
                                    <line x1="6" y1="18" x2="18" y2="6"/>
                                </svg>
                            </a>
                            <div class="ke-item-card-header" style="align-items:center;">
                                <button type="button"
                                        class="ke-org-logo-btn<?php echo $logo_url ? '' : ' is-empty'; ?>"
                                        data-term-id="<?php echo esc_attr( $org->term_id ); ?>"
                                        aria-label="<?php esc_attr_e( 'Change logo', 'kiwi-events' ); ?>"
                                        title="<?php esc_attr_e( 'Change logo', 'kiwi-events' ); ?>">
                                    <?php if ( $logo_url ) : ?>
                                        <img src="<?php echo esc_url( $logo_url ); ?>" alt="Logo" class="ke-org-logo-img">
                                    <?php else : ?>
                                        <span class="ke-org-logo-placeholder" aria-hidden="true">🎪</span>
                                    <?php endif; ?>
                                    <span class="ke-org-logo-overlay" aria-hidden="true">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                                            <circle cx="12" cy="13" r="4"/>
                                        </svg>
                                    </span>
                                </button>

                                <div>
                                    <div class="ke-id-chip" style="margin-bottom:4px;">ID <?php echo esc_html($org->term_id); ?></div>
                                    <h3 class="ke-item-card-name"><?php echo esc_html( $org->name ); ?></h3>
                                    <div class="ke-item-card-meta">
                                        Slug: <code style="font-size:11px;"><?php echo esc_html( $org->slug ); ?></code>
                                    </div>
                                </div>
                            </div>

                            <div class="ke-item-card-footer" style="flex-wrap:wrap;gap:6px;">
                                <span class="ke-item-count">📅 <?php echo intval($org->count); ?> Event<?php echo $org->count !== 1 ? 's' : ''; ?></span>

                                <?php if ( $has_pwd ) :
                                    $dashboard_url = home_url( '/organizer/' . $org->slug . '/' );
                                ?>
                                    <a href="<?php echo esc_url( $dashboard_url ); ?>"
                                       target="_blank"
                                       rel="noopener"
                                       class="ke-btn ke-btn-primary ke-view-dashboard-btn"
                                       style="font-size:12px;padding:5px 12px;display:inline-flex;align-items:center;gap:5px;">
                                        📊 <?php esc_html_e( 'View Dashboard', 'kiwi-events' ); ?>
                                    </a>
                                <?php endif; ?>

                                <a href="<?php echo esc_url( $public_url ); ?>"
                                   target="_blank"
                                   rel="noopener"
                                   class="ke-btn ke-btn-ghost ke-view-public-btn"
                                   style="font-size:12px;padding:5px 12px;display:inline-flex;align-items:center;gap:5px;">
                                    🌐 <?php esc_html_e( 'Ver página pública', 'kiwi-events' ); ?>
                                </a>

                                <button type="button"
                                        class="ke-btn ke-btn-ghost ke-open-profile-btn"
                                        style="font-size:12px;padding:5px 10px;"
                                        data-term-id="<?php echo esc_attr( $org->term_id ); ?>"
                                        data-org-name="<?php echo esc_attr( $org->name ); ?>"
                                        data-hero-id="<?php echo esc_attr( $hero_id ); ?>"
                                        data-hero-url="<?php echo esc_attr( $hero_url ); ?>"
                                        data-category="<?php echo esc_attr( $org_category ); ?>"
                                        data-gallery="<?php echo esc_attr( wp_json_encode( $org_gallery_ids ) ); ?>">
                                    🎨 <?php esc_html_e( 'Profile', 'kiwi-events' ); ?>
                                </button>

                                <button type="button"
                                        class="ke-btn ke-btn-ghost ke-open-tpl-btn"
                                        style="font-size:12px;padding:5px 10px;"
                                        data-term-id="<?php echo esc_attr( $org->term_id ); ?>"
                                        data-org-name="<?php echo esc_attr( $org->name ); ?>">
                                    🎟️ <?php esc_html_e( 'Templates', 'kiwi-events' ); ?>
                                    <span class="ke-tpl-count-badge"><?php echo $n_tpl; ?></span>
                                </button>

                                <button type="button"
                                        class="ke-btn ke-btn-ghost ke-open-pwd-btn<?php echo $has_pwd ? ' ke-pwd-set' : ''; ?>"
                                        style="font-size:12px;padding:5px 10px;"
                                        data-term-id="<?php echo esc_attr( $org->term_id ); ?>"
                                        data-org-name="<?php echo esc_attr( $org->name ); ?>"
                                        data-has-pwd="<?php echo $has_pwd ? '1' : '0'; ?>">
                                    <?php echo $has_pwd ? '🔒' : '🔓'; ?> <?php esc_html_e( 'Organizer Password', 'kiwi-events' ); ?>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- /.ke-split-layout -->
</div><!-- /.ke-builder-wrap -->

<!-- ══════════════════════════════════════════════════════════
     TEMPLATE MANAGER PANEL
══════════════════════════════════════════════════════════ -->
<div id="ke-tpl-overlay" class="ke-tpl-overlay" style="display:none" aria-hidden="true"></div>

<div id="ke-tpl-panel" class="ke-tpl-panel" style="transform:translateX(100%)" role="dialog" aria-modal="true">

    <div class="ke-tpl-panel-header">
        <div>
            <h2 class="ke-tpl-panel-title" id="ke-tpl-panel-title">Ticket Templates</h2>
            <p class="ke-tpl-panel-org" id="ke-tpl-panel-org"></p>
        </div>
        <button type="button" class="ke-tpl-panel-close" id="ke-tpl-close" aria-label="Close">
            <span class="dashicons dashicons-no-alt"></span>
        </button>
    </div>

    <div class="ke-tpl-panel-body">

        <!-- VIEW: Template list -->
        <div id="ke-tpl-list-view">
            <div id="ke-tpl-list-container">
                <div class="ke-tpl-empty" id="ke-tpl-list-empty" style="display:none">
                    <span>🎟️</span>
                    <p>No templates yet. Create one to reuse ticket configurations across events.</p>
                </div>
            </div>

            <div class="ke-tpl-list-footer">
                <button type="button" class="ke-btn ke-btn-primary" id="ke-tpl-new-btn">+ New Template</button>
            </div>
        </div>

        <!-- VIEW: Template editor -->
        <div id="ke-tpl-editor-view" style="display:none">

            <div class="ke-tpl-editor-nav">
                <button type="button" class="ke-btn ke-btn-ghost ke-small" id="ke-tpl-back-btn">
                    <span class="dashicons dashicons-arrow-left-alt2"></span> Back
                </button>
                <h3 id="ke-tpl-editor-heading">New Template</h3>
            </div>

            <div class="ke-form-group" style="margin-top:12px;">
                <label class="ke-label">Template Name <span class="ke-required">*</span></label>
                <input type="text" id="ke-tpl-name-input" class="ke-input" placeholder="e.g., Jueves de Furia">
            </div>

            <div class="ke-tpl-tickets-header">
                <span class="ke-label" style="margin-bottom:0;">Ticket Types</span>
                <button type="button" class="ke-btn ke-btn-primary ke-small" id="ke-tpl-add-ticket-btn">+ Add Ticket</button>
            </div>

            <div id="ke-tpl-ticket-container">
                <div class="ke-tpl-tickets-empty" id="ke-tpl-tickets-empty">
                    No ticket types yet.
                </div>
            </div>

            <div class="ke-tpl-editor-footer">
                <button type="button" class="ke-btn ke-btn-ghost" id="ke-tpl-cancel-btn">Cancel</button>
                <button type="button" class="ke-btn ke-btn-primary" id="ke-tpl-save-btn">Save Template</button>
            </div>

        </div><!-- /#ke-tpl-editor-view -->
    </div><!-- /.ke-tpl-panel-body -->
</div><!-- /#ke-tpl-panel -->

<!-- ══════════════════════════════════════════════════════════
     ORGANIZER PASSWORD MODAL
     Two states inside the same modal:
       • "view" — bullet dots matching the stored password's length, with a
         single Reset/Set-password button. No plaintext is ever shipped here:
         the dots are pure UI, fetched length-only via /password-meta.
       • "edit" — empty input + Save + Cancel. Save POSTs to /update-password,
         which re-hashes via wp_hash_password and invalidates active
         scanner + dashboard sessions for this organizer.
══════════════════════════════════════════════════════════ -->
<div id="ke-pwd-overlay" class="ke-pwd-overlay" style="display:none" aria-hidden="true"></div>
<div id="ke-pwd-modal" class="ke-pwd-modal" style="display:none" role="dialog" aria-modal="true" aria-labelledby="ke-pwd-title">
    <input type="hidden" id="ke-pwd-term-id" value="">

    <div class="ke-pwd-modal-header">
        <h2 id="ke-pwd-title"><?php esc_html_e( 'Organizer Password', 'kiwi-events' ); ?></h2>
        <button type="button" class="ke-pwd-close" id="ke-pwd-close" aria-label="<?php esc_attr_e( 'Close', 'kiwi-events' ); ?>">
            <span class="dashicons dashicons-no-alt"></span>
        </button>
    </div>

    <div class="ke-pwd-modal-body">
        <p class="ke-pwd-org-name" id="ke-pwd-org-name"></p>

        <p id="ke-pwd-error" class="ke-pwd-error" style="display:none;" role="alert"></p>

        <!-- VIEW state: dots + Reset / Set password -->
        <div class="ke-pwd-view" id="ke-pwd-view">
            <label class="ke-pwd-label"><?php esc_html_e( 'Current password', 'kiwi-events' ); ?></label>
            <div class="ke-pwd-display-row">
                <div class="ke-pwd-dots" id="ke-pwd-dots" aria-live="polite">
                    <span class="ke-pwd-loading"><?php esc_html_e( 'Loading…', 'kiwi-events' ); ?></span>
                </div>
                <button type="button" class="ke-btn ke-btn-primary" id="ke-pwd-reset-btn" disabled>
                    <?php esc_html_e( 'Reset', 'kiwi-events' ); ?>
                </button>
            </div>
            <p class="ke-help" style="font-size:12px;color:#64748b;margin-top:10px;">
                <?php esc_html_e( 'For your security, the password cannot be displayed. Reset it to a value you choose if you need to share or recover access. All active scanner and dashboard sessions for this organizer will be signed out on reset.', 'kiwi-events' ); ?>
            </p>
        </div>

        <!-- EDIT state: input + Save + Cancel -->
        <div class="ke-pwd-edit" id="ke-pwd-edit" style="display:none;">
            <label for="ke-pwd-input" class="ke-pwd-label"><?php esc_html_e( 'New password', 'kiwi-events' ); ?></label>
            <div class="ke-pwd-input-wrap">
                <input type="password" id="ke-pwd-input" value="" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Enter new password', 'kiwi-events' ); ?>">
                <button type="button" id="ke-pwd-toggle" class="ke-pwd-toggle" aria-label="<?php esc_attr_e( 'Show password', 'kiwi-events' ); ?>">
                    <span class="dashicons dashicons-visibility"></span>
                </button>
            </div>
            <div class="ke-pwd-edit-actions">
                <button type="button" class="ke-btn ke-btn-primary" id="ke-pwd-save-btn">
                    <?php esc_html_e( 'Save', 'kiwi-events' ); ?>
                </button>
                <button type="button" class="ke-pwd-cancel-link" id="ke-pwd-cancel-edit">
                    <?php esc_html_e( 'Cancel', 'kiwi-events' ); ?>
                </button>
            </div>
            <p class="ke-help" style="font-size:12px;color:#64748b;margin-top:10px;">
                <?php esc_html_e( 'Minimum 4 characters. The password protects (1) Kiwi Scanner access at events, and (2) the Organizer Dashboard at /organizer/[slug].', 'kiwi-events' ); ?>
            </p>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     PUBLIC PROFILE MODAL
     Edits the three new term meta keys backing /organizers/{slug}:
       • ke_organizer_hero_image          (single attachment ID)
       • ke_organizer_category            (short text label)
       • ke_organizer_gallery_photo_ids   (ordered list of attachment IDs)
     Submits a normal POST to admin-post.php (handle_save_organizer_profile)
     so a page reload reflects the new state — same pattern as the rest of
     the organizer admin handlers.
══════════════════════════════════════════════════════════ -->
<div id="ke-prof-overlay" class="ke-prof-overlay" style="display:none" aria-hidden="true"></div>
<div id="ke-prof-modal" class="ke-prof-modal" style="display:none" role="dialog" aria-modal="true" aria-labelledby="ke-prof-title">
    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ke-prof-form">
        <input type="hidden" name="action" value="ke_save_organizer_profile">
        <?php wp_nonce_field( 'ke_save_organizer_profile_nonce' ); ?>
        <input type="hidden" name="term_id" id="ke-prof-term-id" value="">
        <input type="hidden" name="hero_image_id" id="ke-prof-hero-id" value="">
        <input type="hidden" name="gallery_photo_ids" id="ke-prof-gallery-ids" value="">

        <div class="ke-prof-modal-header">
            <div>
                <h2 id="ke-prof-title"><?php esc_html_e( 'Public Profile', 'kiwi-events' ); ?></h2>
                <p class="ke-prof-org-name" id="ke-prof-org-name"></p>
            </div>
            <button type="button" class="ke-prof-close" id="ke-prof-close" aria-label="<?php esc_attr_e( 'Close', 'kiwi-events' ); ?>">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>

        <div class="ke-prof-modal-body">

            <div class="ke-prof-field">
                <label class="ke-prof-label"><?php esc_html_e( 'Hero image', 'kiwi-events' ); ?></label>
                <p class="ke-prof-help"><?php esc_html_e( 'Wide image at the top of the public profile (recommended ≥1600×600).', 'kiwi-events' ); ?></p>
                <div class="ke-prof-hero-uploader" id="ke-prof-hero-uploader">
                    <div class="ke-prof-hero-preview" id="ke-prof-hero-preview"></div>
                    <div class="ke-prof-hero-actions">
                        <button type="button" class="ke-btn ke-btn-ghost" id="ke-prof-hero-pick"><?php esc_html_e( 'Choose image', 'kiwi-events' ); ?></button>
                        <button type="button" class="ke-btn ke-btn-ghost ke-prof-hero-clear" id="ke-prof-hero-clear" style="display:none;"><?php esc_html_e( 'Remove', 'kiwi-events' ); ?></button>
                    </div>
                </div>
            </div>

            <div class="ke-prof-field">
                <label class="ke-prof-label" for="ke-prof-category"><?php esc_html_e( 'Category', 'kiwi-events' ); ?></label>
                <p class="ke-prof-help"><?php esc_html_e( 'Short label shown under the organizer name. e.g. “Productora de eventos”.', 'kiwi-events' ); ?></p>
                <input type="text" id="ke-prof-category" name="organizer_category" maxlength="80" placeholder="<?php esc_attr_e( 'e.g. Productora de eventos', 'kiwi-events' ); ?>">
            </div>

            <div class="ke-prof-field">
                <label class="ke-prof-label"><?php esc_html_e( 'Gallery', 'kiwi-events' ); ?></label>
                <p class="ke-prof-help"><?php esc_html_e( 'Photos shown in the Galería tab. Click an image to remove it.', 'kiwi-events' ); ?></p>
                <div class="ke-prof-gallery" id="ke-prof-gallery"></div>
                <button type="button" class="ke-btn ke-btn-ghost" id="ke-prof-gallery-add" style="margin-top:10px;">
                    <?php esc_html_e( '+ Add photos', 'kiwi-events' ); ?>
                </button>
            </div>

        </div>

        <div class="ke-prof-modal-footer">
            <button type="button" class="ke-btn ke-btn-ghost" id="ke-prof-cancel"><?php esc_html_e( 'Cancel', 'kiwi-events' ); ?></button>
            <button type="submit" class="ke-btn ke-btn-primary"><?php esc_html_e( 'Save profile', 'kiwi-events' ); ?></button>
        </div>
    </form>
</div>

<!-- Toast: success message after save. -->
<div id="ke-toast" class="ke-toast" style="display:none;" role="status" aria-live="polite"></div>

<?php if ( isset( $_GET['profile_saved'] ) ) : ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var t = document.getElementById('ke-toast');
        if (!t) return;
        t.textContent = '<?php echo esc_js( __( 'Profile saved.', 'kiwi-events' ) ); ?>';
        t.style.display = 'block';
        // Force reflow before adding the visible class so the transition fires.
        void t.offsetHeight;
        t.classList.add('is-visible');
        setTimeout(function() { t.classList.remove('is-visible'); }, 3500);
    });
    </script>
<?php endif; ?>

<style>
/* ── Scanner password modal ── */
.ke-pwd-overlay {
    position: fixed; inset: 0;
    background: rgba(15,23,42,.45);
    z-index: 99998;
    backdrop-filter: blur(3px);
    -webkit-backdrop-filter: blur(3px);
}
.ke-pwd-modal {
    position: fixed; top: 50%; left: 50%;
    transform: translate(-50%,-50%);
    width: 460px; max-width: 92vw;
    background: #fff;
    z-index: 99999;
    border-radius: 14px;
    box-shadow: 0 20px 60px rgba(15,23,42,.28);
    overflow: hidden;
}
.ke-pwd-modal-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 18px 20px;
    border-bottom: 1px solid #f1f5f9;
}
.ke-pwd-modal-header h2 { margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; }
.ke-pwd-close {
    background: #f1f5f9; border: none; border-radius: 8px;
    padding: 4px; cursor: pointer; color: #64748b;
}
.ke-pwd-close:hover { background: #e2e8f0; color: #0f172a; }
.ke-pwd-modal-body { padding: 18px 20px; }
.ke-pwd-org-name { font-size: 13px; color: #64748b; margin: 0 0 14px; }
.ke-pwd-modal-footer {
    display: flex; justify-content: flex-end; gap: 8px;
    padding: 14px 20px;
    border-top: 1px solid #f1f5f9;
    background: #f8fafc;
}
.ke-pwd-input-wrap { position: relative; display: flex; }
.ke-pwd-input-wrap input { flex: 1; padding-right: 40px; }
.ke-pwd-toggle {
    position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
    background: transparent; border: none; cursor: pointer; color: #64748b;
    padding: 4px;
}
.ke-pwd-toggle:hover { color: #0f172a; }

.ke-pwd-error {
    padding: 10px 12px; border-radius: 8px; font-size: 13px; margin: 0 0 14px;
    background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca;
}
.ke-pwd-label {
    display: block; font-size: 12px; font-weight: 600; color: #475569;
    text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 8px;
}
.ke-pwd-display-row {
    display: flex; align-items: center; gap: 10px;
}
.ke-pwd-dots {
    flex: 1; min-height: 38px;
    padding: 8px 14px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    display: flex; align-items: center;
    font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
    font-size: 18px; line-height: 1;
    letter-spacing: 0.18em;
    color: #0f172a;
    word-break: break-all;
    overflow: hidden;
}
.ke-pwd-dots.is-empty {
    color: #94a3b8;
    font-style: italic;
    font-size: 13px;
    letter-spacing: 0;
    font-family: inherit;
}
.ke-pwd-loading { color: #94a3b8; font-size: 13px; letter-spacing: 0; font-family: inherit; }

.ke-pwd-edit-actions {
    display: flex; align-items: center; gap: 14px; margin-top: 10px;
}
.ke-pwd-cancel-link {
    background: transparent; border: none; cursor: pointer;
    color: #64748b; font-size: 13px; text-decoration: underline;
    padding: 4px 0;
}
.ke-pwd-cancel-link:hover { color: #0f172a; }

.ke-open-pwd-btn.ke-pwd-set { color: #047857; }

/* Top-right X delete on organizer cards */
.ke-org-card { position: relative; }
.ke-org-card-delete {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    color: #71717a;
    text-decoration: none;
    transition: background-color .15s ease, color .15s ease, transform .15s ease;
    z-index: 2;
}
.ke-org-card-delete:hover,
.ke-org-card-delete:focus-visible {
    background: rgba(239, 68, 68, 0.08);
    color: #ef4444;
    outline: none;
}
.ke-org-card-delete:focus-visible { box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.35); }

/* Slide-out animation on confirmed delete (driven by JS) */
.ke-org-card.is-removing {
    transition: opacity .25s ease, transform .25s ease;
    opacity: 0;
    transform: translateX(24px);
    pointer-events: none;
}

/* Editable logo button on organizer cards. Wraps the <img> (or emoji
   placeholder) so the whole circle is clickable and shows a camera-icon
   overlay on hover/focus. Click → wp.media → AJAX → in-place swap. */
.ke-org-logo-btn {
    position: relative;
    width: 48px;
    height: 48px;
    border-radius: 50%;
    border: 1px solid rgba(226, 232, 240, .6);
    background: #f8fafc;
    padding: 0;
    flex-shrink: 0;
    overflow: hidden;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: box-shadow .15s ease, transform .15s ease;
}
.ke-org-logo-btn:hover,
.ke-org-logo-btn:focus-visible {
    outline: none;
    box-shadow: 0 0 0 2px rgba(99, 102, 241, .35);
    transform: scale(1.03);
}
.ke-org-logo-btn.is-empty {
    background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
}
.ke-org-logo-btn .ke-org-logo-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.ke-org-logo-btn .ke-org-logo-placeholder {
    font-size: 20px;
    line-height: 1;
}
.ke-org-logo-btn .ke-org-logo-overlay {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, .55);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity .15s ease;
}
.ke-org-logo-btn:hover .ke-org-logo-overlay,
.ke-org-logo-btn:focus-visible .ke-org-logo-overlay {
    opacity: 1;
}
.ke-org-logo-btn.is-loading {
    pointer-events: none;
}
.ke-org-logo-btn.is-loading .ke-org-logo-overlay {
    opacity: 1;
    background: rgba(15, 23, 42, .7);
}

/* Toast */
.ke-toast {
    position: fixed; bottom: 24px; right: 24px;
    background: #0f172a; color: #fff;
    padding: 12px 18px;
    border-radius: 10px;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.25);
    font-size: 13px; font-weight: 500;
    z-index: 100000;
    max-width: 420px;
    opacity: 0;
    transform: translateY(8px);
    transition: opacity .2s ease, transform .2s ease;
}
.ke-toast.is-visible { opacity: 1; transform: translateY(0); }
.ke-toast.is-error   { background: #b91c1c; }

/* ── Public profile modal ── */
.ke-prof-overlay {
    position: fixed; inset: 0;
    background: rgba(15,23,42,.45);
    z-index: 99998;
    backdrop-filter: blur(3px);
    -webkit-backdrop-filter: blur(3px);
}
.ke-prof-modal {
    position: fixed; top: 50%; left: 50%;
    transform: translate(-50%,-50%);
    width: 580px; max-width: 92vw;
    max-height: 88vh;
    background: #fff;
    z-index: 99999;
    border-radius: 14px;
    box-shadow: 0 20px 60px rgba(15,23,42,.28);
    overflow: hidden;
    display: flex; flex-direction: column;
}
.ke-prof-modal form { display: flex; flex-direction: column; min-height: 0; flex: 1; }
.ke-prof-modal-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    padding: 18px 20px;
    border-bottom: 1px solid #f1f5f9;
    flex-shrink: 0;
}
.ke-prof-modal-header h2 { margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; }
.ke-prof-org-name { margin: 2px 0 0; font-size: 13px; color: #64748b; }
.ke-prof-close {
    background: #f1f5f9; border: none; border-radius: 8px;
    padding: 4px; cursor: pointer; color: #64748b;
}
.ke-prof-close:hover { background: #e2e8f0; color: #0f172a; }
.ke-prof-modal-body { padding: 18px 20px; overflow-y: auto; flex: 1; }
.ke-prof-modal-footer {
    display: flex; justify-content: flex-end; gap: 8px;
    padding: 14px 20px;
    border-top: 1px solid #f1f5f9;
    background: #f8fafc;
    flex-shrink: 0;
}
.ke-prof-field { margin-bottom: 18px; }
.ke-prof-field:last-child { margin-bottom: 0; }
.ke-prof-label {
    display: block; font-size: 12px; font-weight: 600; color: #475569;
    text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 4px;
}
.ke-prof-help { margin: 0 0 8px; font-size: 12.5px; color: #64748b; }
.ke-prof-field input[type="text"] {
    width: 100%; padding: 9px 12px;
    border: 1px solid #e2e8f0; border-radius: 8px;
    font-size: 14px;
}
.ke-prof-field input[type="text"]:focus {
    outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.15);
}

.ke-prof-hero-uploader {
    border: 2px dashed #e2e8f0;
    border-radius: 10px;
    padding: 16px;
    background: #f8fafc;
    text-align: center;
}
.ke-prof-hero-preview {
    width: 100%;
    aspect-ratio: 16 / 6;
    background: #fff;
    border-radius: 8px;
    background-size: cover;
    background-position: center;
    margin-bottom: 10px;
    border: 1px solid #f1f5f9;
}
.ke-prof-hero-preview.is-empty {
    background-image: linear-gradient(135deg,#f1f5f9 25%,transparent 25%,transparent 50%,#f1f5f9 50%,#f1f5f9 75%,transparent 75%,transparent);
    background-size: 12px 12px;
    background-color: #fafafa;
    display: flex; align-items: center; justify-content: center;
    color: #94a3b8; font-size: 13px;
}
.ke-prof-hero-actions { display: flex; gap: 8px; justify-content: center; }

.ke-prof-gallery {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    min-height: 80px;
}
.ke-prof-gallery-item {
    position: relative;
    aspect-ratio: 1 / 1;
    border-radius: 8px;
    overflow: hidden;
    background: #f1f5f9;
    cursor: pointer;
    transition: transform .12s ease;
}
.ke-prof-gallery-item:hover { transform: scale(1.02); }
.ke-prof-gallery-item img {
    width: 100%; height: 100%; object-fit: cover; display: block;
}
.ke-prof-gallery-item::after {
    content: '✕';
    position: absolute; inset: 0;
    background: rgba(15,23,42,.6);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    opacity: 0;
    transition: opacity .12s ease;
    font-size: 22px;
    font-weight: 700;
}
.ke-prof-gallery-item:hover::after { opacity: 1; }
.ke-prof-gallery-empty {
    grid-column: 1 / -1;
    text-align: center;
    color: #94a3b8;
    font-size: 13px;
    padding: 24px 0;
    border: 1px dashed #e2e8f0;
    border-radius: 8px;
}

/* ── Template manager panel ── */
.ke-tpl-overlay {
    position: fixed; inset: 0;
    background: rgba(15,23,42,.45);
    z-index: 99998;
    backdrop-filter: blur(3px);
    -webkit-backdrop-filter: blur(3px);
}
.ke-tpl-panel {
    position: fixed; top: 32px; right: 0; bottom: 0;
    width: 560px; max-width: 96vw;
    background: #fff;
    z-index: 99999;
    display: flex; flex-direction: column;
    box-shadow: -8px 0 40px rgba(15,23,42,.18);
    transition: transform .3s cubic-bezier(.4,0,.2,1);
}
.ke-tpl-panel.open { transform: translateX(0) !important; }

.ke-tpl-panel-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    padding: 22px 24px 18px;
    border-bottom: 1px solid #f1f5f9;
    flex-shrink: 0;
}
.ke-tpl-panel-title { font-size: 18px; font-weight: 800; color: #0f172a; margin: 0 0 2px; }
.ke-tpl-panel-org   { font-size: 13px; color: #64748b; margin: 0; }
.ke-tpl-panel-close {
    background: #f1f5f9; border: none; border-radius: 8px;
    padding: 6px; cursor: pointer; color: #64748b;
    transition: background .15s;
}
.ke-tpl-panel-close:hover { background: #e2e8f0; color: #0f172a; }

.ke-tpl-panel-body   { flex: 1; overflow-y: auto; padding: 20px 24px; }

/* List view */
.ke-tpl-card {
    border: 1.5px solid #e2e8f0; border-radius: 14px;
    margin-bottom: 12px; overflow: hidden;
    transition: box-shadow .2s;
}
.ke-tpl-card:hover { box-shadow: 0 4px 16px rgba(15,23,42,.08); }

.ke-tpl-card-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 14px 16px;
}
.ke-tpl-card-left  { display: flex; flex-direction: column; gap: 2px; }
.ke-tpl-card-name  { font-size: 15px; font-weight: 700; color: #0f172a; }
.ke-tpl-card-meta  { font-size: 12px; color: #94a3b8; }
.ke-tpl-card-right { display: flex; gap: 6px; }

.ke-tpl-card-tickets {
    padding: 0 16px 14px;
    display: flex; flex-wrap: wrap; gap: 6px;
}
.ke-tpl-ticket-chip {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 100px;
    font-size: 11px; font-weight: 600;
    background: #f8fafc; border: 1px solid #e2e8f0; color: #475569;
}

.ke-tpl-empty {
    text-align: center; padding: 48px 16px;
    color: #94a3b8; border: 2px dashed #e2e8f0; border-radius: 14px;
}
.ke-tpl-empty span { font-size: 32px; display: block; margin-bottom: 8px; }
.ke-tpl-empty p    { font-size: 14px; margin: 0; }

.ke-tpl-list-footer { padding-top: 16px; }

.ke-tpl-count-badge {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 18px; height: 18px; padding: 0 5px;
    background: #6366f1; color: #fff;
    border-radius: 100px; font-size: 10px; font-weight: 700;
    margin-left: 4px;
}

/* Editor view */
.ke-tpl-editor-nav {
    display: flex; align-items: center; gap: 12px; margin-bottom: 4px;
}
.ke-tpl-editor-nav h3 { font-size: 16px; font-weight: 700; margin: 0; color: #0f172a; }

.ke-tpl-tickets-header {
    display: flex; justify-content: space-between; align-items: center;
    margin: 20px 0 10px;
}
.ke-tpl-tickets-empty { font-size: 13px; color: #94a3b8; padding: 12px 0; }

.ke-tpl-editor-footer {
    display: flex; justify-content: flex-end; gap: 10px;
    padding-top: 20px; margin-top: 8px;
    border-top: 1px solid #f1f5f9;
    position: sticky; bottom: 0;
    background: #fff; padding-bottom: 4px;
}

/* Simplified ticket cards inside template editor */
.ke-tpl-tkt-card {
    border: 1.5px solid #e2e8f0; border-radius: 12px;
    margin-bottom: 10px; overflow: hidden;
}
.ke-tpl-tkt-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 10px 14px; background: #f8fafc;
    border-bottom: 1.5px solid #e2e8f0;
}
.ke-tpl-tkt-header-left { display: flex; align-items: center; gap: 8px; }
.ke-tpl-tkt-body  { padding: 14px; }
</style>

<script>
(function($) {
    var REST       = '<?php echo esc_js( rest_url('ke/v1/') ); ?>';
    var NONCE      = '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>';
    var AJAX_URL   = '<?php echo esc_js( admin_url('admin-ajax.php') ); ?>';
    var LOGO_NONCE = '<?php echo esc_js( wp_create_nonce('ke_update_organizer_logo') ); ?>';

    var currentTermId  = null;
    var currentTplId   = null;   // null = new, string = editing existing
    var tplTicketIdx   = 0;

    // ── Panel open/close ───────────────────────────────────────────────
    function openPanel(termId, orgName) {
        currentTermId = termId;
        $('#ke-tpl-panel-title').text('Ticket Templates');
        $('#ke-tpl-panel-org').text(orgName);
        $('#ke-tpl-overlay').fadeIn(180);
        $('#ke-tpl-panel').addClass('open');
        $('body').css('overflow', 'hidden');
        showListView();
        loadTemplates();
    }

    function closePanel() {
        $('#ke-tpl-panel').removeClass('open');
        $('#ke-tpl-overlay').fadeOut(180);
        $('body').css('overflow', '');
        currentTermId = null;
    }

    $('#ke-tpl-close, #ke-tpl-overlay').on('click', closePanel);
    $(document).on('keydown', function(e) { if (e.key === 'Escape') closePanel(); });

    $('.ke-open-tpl-btn').on('click', function() {
        openPanel($(this).data('term-id'), $(this).data('org-name'));
    });

    // ── Views ──────────────────────────────────────────────────────────
    function showListView() {
        $('#ke-tpl-list-view').show();
        $('#ke-tpl-editor-view').hide();
    }

    function showEditorView(tplData) {
        currentTplId = tplData ? tplData.id : null;
        tplTicketIdx = 0;
        $('#ke-tpl-editor-view').show();
        $('#ke-tpl-list-view').hide();
        $('#ke-tpl-editor-heading').text(tplData ? 'Edit Template' : 'New Template');
        $('#ke-tpl-name-input').val(tplData ? tplData.name : '');
        $('#ke-tpl-ticket-container').empty();
        if (tplData && tplData.tickets && tplData.tickets.length) {
            tplData.tickets.forEach(function(t) { addTplTicketCard(t); });
        }
        updateTplEmptyState();
    }

    $('#ke-tpl-new-btn').on('click', function() { showEditorView(null); });
    $('#ke-tpl-back-btn, #ke-tpl-cancel-btn').on('click', function() {
        showListView();
        loadTemplates();
    });

    // ── Load templates ─────────────────────────────────────────────────
    function loadTemplates() {
        if (!currentTermId) return;
        fetch(REST + 'organizers/' + currentTermId + '/templates', {
            headers: { 'X-WP-Nonce': NONCE }
        })
        .then(function(r) { return r.json(); })
        .then(function(list) { renderTemplateList(list); })
        .catch(function() { renderTemplateList([]); });
    }

    function renderTemplateList(list) {
        var $c = $('#ke-tpl-list-container');
        $c.empty();
        $('#ke-tpl-list-empty').hide();

        if (!list || !list.length) {
            $('#ke-tpl-list-empty').show().appendTo($c);
            // Update badge
            $('.ke-open-tpl-btn[data-term-id="' + currentTermId + '"] .ke-tpl-count-badge').text(0);
            return;
        }

        list.forEach(function(tpl) {
            var chips = (tpl.tickets || []).map(function(t) {
                var priceStr = parseFloat(t.price) > 0 ? '$' + parseFloat(t.price).toFixed(2) : 'Free';
                return '<span class="ke-tpl-ticket-chip">' + escH(t.name) + ' · ' + priceStr + '</span>';
            }).join('');

            var card = $([
                '<div class="ke-tpl-card" data-tpl-id="' + escA(tpl.id) + '">',
                '  <div class="ke-tpl-card-header">',
                '    <div class="ke-tpl-card-left">',
                '      <span class="ke-tpl-card-name">' + escH(tpl.name) + '</span>',
                '      <span class="ke-tpl-card-meta">' + (tpl.tickets || []).length + ' ticket type' + ((tpl.tickets || []).length !== 1 ? 's' : '') + '</span>',
                '    </div>',
                '    <div class="ke-tpl-card-right">',
                '      <button type="button" class="ke-btn ke-btn-ghost ke-small ke-tpl-edit-btn" data-tpl-id="' + escA(tpl.id) + '">Edit</button>',
                '      <button type="button" class="ke-btn ke-btn-danger ke-small ke-tpl-del-btn" style="padding:5px 10px;" data-tpl-id="' + escA(tpl.id) + '">Delete</button>',
                '    </div>',
                '  </div>',
                chips ? '<div class="ke-tpl-card-tickets">' + chips + '</div>' : '',
                '</div>',
            ].join(''));

            card.find('.ke-tpl-edit-btn').on('click', function() {
                showEditorView(tpl);
            });
            card.find('.ke-tpl-del-btn').on('click', function() {
                deleteTemplate(tpl.id, escH(tpl.name));
            });
            $c.append(card);
        });

        // Update badge on the organizer card button
        $('.ke-open-tpl-btn[data-term-id="' + currentTermId + '"] .ke-tpl-count-badge').text(list.length);
    }

    // ── Delete template ────────────────────────────────────────────────
    function deleteTemplate(tplId, tplName) {
        if (!confirm('Delete template "' + tplName + '"?')) return;
        fetch(REST + 'organizers/' + currentTermId + '/templates/' + tplId, {
            method: 'DELETE',
            headers: { 'X-WP-Nonce': NONCE }
        })
        .then(function() { loadTemplates(); })
        .catch(function() { alert('Error deleting template.'); });
    }

    // ── Save template ──────────────────────────────────────────────────
    $('#ke-tpl-save-btn').on('click', function() {
        var name = $('#ke-tpl-name-input').val().trim();
        if (!name) {
            alert('Please enter a template name.');
            $('#ke-tpl-name-input').focus();
            return;
        }

        var tickets = [];
        $('#ke-tpl-ticket-container .ke-tpl-tkt-card').each(function() {
            var $c = $(this);
            var tktName = $c.find('.ke-tpl-tkt-name').val().trim();
            if (!tktName) return;
            var capType = $c.find('.ke-tpl-cap-radio:checked').val() || 'limited';
            tickets.push({
                name:          tktName,
                description:   $c.find('.ke-tpl-tkt-desc').val().trim(),
                ticket_type:   $c.find('.ke-tpl-type-radio:checked').val() || 'free',
                price:         parseFloat($c.find('.ke-tpl-tkt-price').val()) || 0,
                capacity_type: capType,
                quantity:      parseInt($c.find('.ke-tpl-tkt-qty').val(), 10) || 0,
                min_per_order: parseInt($c.find('.ke-tpl-tkt-min').val(), 10) || 1,
                max_per_order: parseInt($c.find('.ke-tpl-tkt-max').val(), 10) || 10,
            });
        });

        var method = currentTplId ? 'PUT' : 'POST';
        var url    = REST + 'organizers/' + currentTermId + '/templates' + (currentTplId ? '/' + currentTplId : '');

        $(this).prop('disabled', true).text('Saving…');

        fetch(url, {
            method:  method,
            headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
            body:    JSON.stringify({ name: name, tickets: tickets }),
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data && data.id) {
                showListView();
                loadTemplates();
            } else {
                alert(data.message || 'Error saving template.');
            }
        })
        .catch(function() { alert('Network error.'); })
        .finally(function() {
            $('#ke-tpl-save-btn').prop('disabled', false).text('Save Template');
        });
    });

    // ── Template ticket cards ─────────────────────────────────────────
    function addTplTicketCard(data) {
        data = data || {};
        var idx    = tplTicketIdx++;
        var isPaid = (data.ticket_type || 'free') === 'paid';
        var isLtd  = (data.capacity_type || 'limited') === 'limited';

        var html = [
            '<div class="ke-tpl-tkt-card">',
            '  <div class="ke-tpl-tkt-header">',
            '    <div class="ke-tpl-tkt-header-left">',
            '      <span class="ke-tkt-badge ' + (isPaid ? 'paid' : 'free') + '">' + (isPaid ? 'PAID' : 'FREE') + '</span>',
            '      <span class="ke-tpl-tkt-title-preview">' + escH(data.name || 'New Ticket') + '</span>',
            '    </div>',
            '    <button type="button" class="ke-tkt-remove-btn ke-tpl-tkt-remove" title="Remove">✕</button>',
            '  </div>',
            '  <div class="ke-tpl-tkt-body">',
            '    <div class="ke-form-row">',
            '      <div class="ke-form-group ke-half">',
            '        <label class="ke-label">Name <span class="ke-required">*</span></label>',
            '        <input type="text" class="ke-input ke-tpl-tkt-name" placeholder="e.g. General" value="' + escA(data.name || '') + '">',
            '      </div>',
            '      <div class="ke-form-group ke-half">',
            '        <label class="ke-label">Type</label>',
            '        <div class="ke-radio-group ke-compact">',
            '          <label class="ke-radio-option ' + (!isPaid ? 'active' : '') + '"><input type="radio" class="ke-tpl-type-radio" name="ke_tpl_type_' + idx + '" value="free" ' + (!isPaid ? 'checked' : '') + '><span>Free</span></label>',
            '          <label class="ke-radio-option ' + (isPaid ? 'active' : '') + '"><input type="radio" class="ke-tpl-type-radio" name="ke_tpl_type_' + idx + '" value="paid" ' + (isPaid ? 'checked' : '') + '><span>Paid</span></label>',
            '        </div>',
            '      </div>',
            '    </div>',
            '    <div class="ke-tpl-price-row" ' + (!isPaid ? 'style="display:none"' : '') + '>',
            '      <div class="ke-form-group ke-third">',
            '        <label class="ke-label">Price ($)</label>',
            '        <input type="number" class="ke-input ke-tpl-tkt-price" min="0" step="0.01" placeholder="0.00" value="' + escA(String(data.price || '')) + '">',
            '      </div>',
            '    </div>',
            '    <div class="ke-form-group">',
            '      <label class="ke-label">Description</label>',
            '      <input type="text" class="ke-input ke-tpl-tkt-desc" placeholder="Optional" value="' + escA(data.description || '') + '">',
            '    </div>',
            '    <div class="ke-form-row">',
            '      <div class="ke-form-group ke-half">',
            '        <label class="ke-label">Capacity</label>',
            '        <div class="ke-radio-group ke-compact">',
            '          <label class="ke-radio-option ' + (isLtd ? 'active' : '') + '"><input type="radio" class="ke-tpl-cap-radio" name="ke_tpl_cap_' + idx + '" value="limited" ' + (isLtd ? 'checked' : '') + '><span>Limited</span></label>',
            '          <label class="ke-radio-option ' + (!isLtd ? 'active' : '') + '"><input type="radio" class="ke-tpl-cap-radio" name="ke_tpl_cap_' + idx + '" value="unlimited" ' + (!isLtd ? 'checked' : '') + '><span>Unlimited</span></label>',
            '        </div>',
            '      </div>',
            '      <div class="ke-form-group ke-half ke-tpl-qty-wrap" ' + (!isLtd ? 'style="display:none"' : '') + '>',
            '        <label class="ke-label">Quantity</label>',
            '        <input type="number" class="ke-input ke-tpl-tkt-qty" min="1" placeholder="100" value="' + escA(String(data.quantity || '')) + '">',
            '      </div>',
            '    </div>',
            '    <div class="ke-form-row">',
            '      <div class="ke-form-group ke-quarter">',
            '        <label class="ke-label">Min / Order</label>',
            '        <input type="number" class="ke-input ke-tpl-tkt-min" min="1" value="' + escA(String(data.min_per_order || 1)) + '">',
            '      </div>',
            '      <div class="ke-form-group ke-quarter">',
            '        <label class="ke-label">Max / Order</label>',
            '        <input type="number" class="ke-input ke-tpl-tkt-max" min="1" value="' + escA(String(data.max_per_order || 10)) + '">',
            '      </div>',
            '    </div>',
            '  </div>',
            '</div>',
        ].join('');

        var $card = $(html);

        // Bind events
        $card.find('.ke-tpl-tkt-name').on('input', function() {
            $card.find('.ke-tpl-tkt-title-preview').text($(this).val().trim() || 'New Ticket');
        });
        $card.find('.ke-tpl-type-radio').on('change', function() {
            var p = $(this).val() === 'paid';
            $card.find('.ke-tpl-price-row').toggle(p);
            $card.find('.ke-tkt-badge').removeClass('free paid').addClass(p ? 'paid' : 'free').text(p ? 'PAID' : 'FREE');
            $card.find('.ke-radio-option').removeClass('active');
            $(this).closest('.ke-radio-option').addClass('active');
        });
        $card.find('.ke-tpl-cap-radio').on('change', function() {
            $card.find('.ke-tpl-qty-wrap').toggle($(this).val() === 'limited');
            $card.find('.ke-radio-option').removeClass('active');
            $(this).closest('.ke-radio-option').addClass('active');
        });
        $card.find('.ke-tpl-tkt-remove').on('click', function() {
            $card.remove();
            updateTplEmptyState();
        });

        $('#ke-tpl-ticket-container').append($card);
        $('#ke-tpl-tickets-empty').hide();
    }

    function updateTplEmptyState() {
        var count = $('#ke-tpl-ticket-container .ke-tpl-tkt-card').length;
        $('#ke-tpl-tickets-empty').toggle(count === 0);
    }

    $('#ke-tpl-add-ticket-btn').on('click', function() { addTplTicketCard({}); });

    // ── Media uploader ────────────────────────────────────────────────
    var orgMediaUploader;
    $('#ke-org-logo-uploader .ke-btn').on('click', function(e) {
        e.preventDefault();
        if (orgMediaUploader) { orgMediaUploader.open(); return; }
        orgMediaUploader = wp.media({ title: 'Choose Organizer Logo', button: { text: 'Select Logo' }, multiple: false });
        orgMediaUploader.on('select', function() {
            var att = orgMediaUploader.state().get('selection').first().toJSON();
            $('#organizer_logo_id').val(att.id);
            var $p = $('#ke-org-logo-uploader .uploader-preview');
            var $img = $p.find('img');
            if (!$img.length) {
                $img = $('<img>').css({ width:'64px', height:'64px', borderRadius:'50%', objectFit:'cover', marginBottom:'12px' }).appendTo($p);
            }
            $img.attr('src', att.url).show();
            $('#ke-org-logo-uploader .ke-btn').text('Change Logo');
        });
        orgMediaUploader.open();
    });

    // ── Organizer password modal ──────────────────────────────────────
    // The plaintext password is never sent to the browser. The modal first
    // calls /password-meta to find out whether one is set and, if so, how
    // many characters it has (length only, never the value or the hash) so
    // we can render the right number of bullet dots as a visual placeholder.
    var pwdState = { termId: null, hasPwd: false, length: 0 };

    function renderPwdDots() {
        var $dots = $('#ke-pwd-dots');
        var $reset = $('#ke-pwd-reset-btn').prop('disabled', false);
        if (pwdState.hasPwd && pwdState.length > 0) {
            // Build N bullets so the admin sees roughly how long their
            // password was. Cap at 64 to avoid silly-long lines if data
            // ever drifts.
            var n = Math.min(64, pwdState.length);
            var dots = new Array(n + 1).join('•');
            $dots.removeClass('is-empty').text(dots);
            $reset.text('<?php echo esc_js( __( 'Reset', 'kiwi-events' ) ); ?>');
        } else {
            $dots.addClass('is-empty').text('<?php echo esc_js( __( 'No password set', 'kiwi-events' ) ); ?>');
            $reset.text('<?php echo esc_js( __( 'Set password', 'kiwi-events' ) ); ?>');
        }
    }

    function showPwdViewState() {
        $('#ke-pwd-edit').hide();
        $('#ke-pwd-view').show();
        $('#ke-pwd-error').hide().text('');
        $('#ke-pwd-input').val('').attr('type', 'password');
        $('#ke-pwd-toggle .dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility');
    }

    function showPwdEditState() {
        $('#ke-pwd-view').hide();
        $('#ke-pwd-edit').show();
        $('#ke-pwd-error').hide().text('');
        $('#ke-pwd-input').val('').attr('type', 'password');
        $('#ke-pwd-toggle .dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility');
        setTimeout(function() { $('#ke-pwd-input').trigger('focus'); }, 50);
    }

    function loadPwdMeta() {
        var $dots = $('#ke-pwd-dots');
        var $reset = $('#ke-pwd-reset-btn').prop('disabled', true);
        $dots.removeClass('is-empty').html('<span class="ke-pwd-loading"><?php echo esc_js( __( 'Loading…', 'kiwi-events' ) ); ?></span>');
        $reset.text('<?php echo esc_js( __( 'Reset', 'kiwi-events' ) ); ?>');

        return fetch(REST + 'organizers/' + pwdState.termId + '/password-meta', {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': NONCE }
        }).then(function(r) { return r.json(); }).then(function(data) {
            pwdState.hasPwd = !!(data && data.has_password);
            pwdState.length = parseInt((data && data.length) || 0, 10) || 0;
            renderPwdDots();
        }).catch(function() {
            $dots.removeClass('is-empty').addClass('is-empty')
                 .text('<?php echo esc_js( __( 'Could not load password info.', 'kiwi-events' ) ); ?>');
            $reset.prop('disabled', false).text('<?php echo esc_js( __( 'Retry', 'kiwi-events' ) ); ?>');
        });
    }

    function openPwdModal(termId, orgName) {
        pwdState = { termId: parseInt(termId, 10) || 0, hasPwd: false, length: 0 };
        $('#ke-pwd-term-id').val(pwdState.termId);
        $('#ke-pwd-org-name').text(orgName);
        showPwdViewState();
        $('#ke-pwd-overlay').fadeIn(150);
        $('#ke-pwd-modal').show();
        loadPwdMeta();
    }

    function closePwdModal() {
        $('#ke-pwd-modal').hide();
        $('#ke-pwd-overlay').fadeOut(150);
        pwdState = { termId: null, hasPwd: false, length: 0 };
    }

    $(document).on('click', '.ke-open-pwd-btn', function() {
        var $b = $(this);
        openPwdModal($b.data('term-id'), $b.data('org-name'));
    });

    // Top-right X on organizer cards. Confirms, plays a 250ms slide-out
    // animation on the card, then navigates to the existing delete URL —
    // so the server-side path (which deletes the term + scrubs sessions)
    // stays the source of truth and a hard reload reflects the result.
    $(document).on('click', '.ke-org-card-delete', function(e) {
        e.preventDefault();
        var $btn  = $(this);
        var url   = $btn.attr('href');
        var msg   = $btn.data('confirm') || 'Delete this organizer?';
        if ($btn.data('confirmed')) return; // re-entry guard while animating
        if (!window.confirm(msg)) return;
        $btn.data('confirmed', true);
        var $card = $btn.closest('.ke-org-card');
        $card.addClass('is-removing');
        setTimeout(function() { window.location.href = url; }, 260);
    });
    $('#ke-pwd-close, #ke-pwd-overlay').on('click', closePwdModal);
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('#ke-pwd-modal').is(':visible')) closePwdModal();
    });

    // Reset / Set password → switch into edit state.
    $(document).on('click', '#ke-pwd-reset-btn', function() {
        // If meta failed and the button is showing "Retry", try again instead
        // of dropping into edit mode with stale state.
        if ($(this).text() === '<?php echo esc_js( __( 'Retry', 'kiwi-events' ) ); ?>') {
            loadPwdMeta();
            return;
        }
        showPwdEditState();
    });

    // Cancel → discard input, return to view state. No save.
    $(document).on('click', '#ke-pwd-cancel-edit', function() {
        showPwdViewState();
    });

    // Show/hide password while editing.
    $(document).on('click', '#ke-pwd-toggle', function() {
        var $i = $('#ke-pwd-input');
        var $ic = $(this).find('.dashicons');
        if ($i.attr('type') === 'password') {
            $i.attr('type', 'text');
            $ic.removeClass('dashicons-visibility').addClass('dashicons-hidden');
        } else {
            $i.attr('type', 'password');
            $ic.removeClass('dashicons-hidden').addClass('dashicons-visibility');
        }
    });

    // Save: POST /update-password. On success update dots in place + toast.
    function savePwd() {
        var pw = $('#ke-pwd-input').val();
        var $err = $('#ke-pwd-error');
        $err.hide().text('');
        if (!pw || !pw.trim()) {
            $err.text('<?php echo esc_js( __( 'Please enter a password.', 'kiwi-events' ) ); ?>').show();
            return;
        }
        if (pw.length < 4) {
            $err.text('<?php echo esc_js( __( 'Password must be at least 4 characters.', 'kiwi-events' ) ); ?>').show();
            return;
        }

        var $btn = $('#ke-pwd-save-btn').prop('disabled', true).text('<?php echo esc_js( __( 'Saving…', 'kiwi-events' ) ); ?>');

        fetch(REST + 'organizers/' + pwdState.termId + '/update-password', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: pw })
        }).then(function(r) {
            return r.json().then(function(data) { return { ok: r.ok, data: data }; });
        }).then(function(res) {
            if (!res.ok || !res.data || res.data.success !== true) {
                var msg = (res.data && res.data.message) || '<?php echo esc_js( __( 'Could not update password.', 'kiwi-events' ) ); ?>';
                $err.text(msg).show();
                return;
            }
            pwdState.hasPwd = true;
            pwdState.length = parseInt(res.data.length, 10) || pw.length;
            renderPwdDots();
            showPwdViewState();
            updateOrgCardPwdState(pwdState.termId, true);
            showToast('<?php echo esc_js( __( 'Password updated. All active scanner sessions for this organizer have been invalidated.', 'kiwi-events' ) ); ?>');
        }).catch(function() {
            $err.text('<?php echo esc_js( __( 'Network error. Please try again.', 'kiwi-events' ) ); ?>').show();
        }).finally(function() {
            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Save', 'kiwi-events' ) ); ?>');
        });
    }
    $(document).on('click', '#ke-pwd-save-btn', savePwd);
    $(document).on('keydown', '#ke-pwd-input', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); savePwd(); }
    });

    // Reflect the new password state on the organizer card so the lock icon
    // matches without requiring a page reload, and the View Dashboard link
    // appears once a password is set for the first time.
    function updateOrgCardPwdState(termId, hasPwd) {
        var $btn = $('.ke-open-pwd-btn[data-term-id="' + termId + '"]');
        if (!$btn.length) return;
        $btn.attr('data-has-pwd', hasPwd ? '1' : '0').data('has-pwd', hasPwd ? '1' : '0');
        $btn.toggleClass('ke-pwd-set', !!hasPwd);
        $btn.contents().filter(function(){ return this.nodeType === 3; })
            .first().replaceWith(' ' + (hasPwd ? '🔒' : '🔓') + ' ');
    }

    // Toast helper. Auto-dismisses after 4s.
    var toastTimer = null;
    function showToast(msg, isError) {
        var $t = $('#ke-toast');
        $t.toggleClass('is-error', !!isError).text(msg).show();
        // Force reflow so the transition runs.
        $t[0].offsetHeight;
        $t.addClass('is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function() {
            $t.removeClass('is-visible');
            setTimeout(function() { $t.hide(); }, 250);
        }, 4000);
    }

    // ── Public profile modal ──────────────────────────────────────────
    // Three editable fields backing /organizers/{slug}: hero image, category
    // text, and a gallery (ordered list of attachment IDs). The form submits
    // via standard admin-post.php; the only async work here is the WP media
    // library pickers and the in-memory gallery state.
    var profState = { termId: null, galleryItems: [] }; // each item = { id, url }

    function renderProfGallery() {
        var $g = $('#ke-prof-gallery').empty();
        if (!profState.galleryItems.length) {
            $g.append('<div class="ke-prof-gallery-empty"><?php echo esc_js( __( 'No photos yet — use “Add photos” to pick from your media library.', 'kiwi-events' ) ); ?></div>');
        } else {
            profState.galleryItems.forEach(function(it) {
                var $cell = $('<div class="ke-prof-gallery-item" data-att-id="' + escA(String(it.id)) + '"><img src="' + escA(it.url) + '" alt=""></div>');
                $cell.on('click', function() {
                    profState.galleryItems = profState.galleryItems.filter(function(x) { return x.id !== it.id; });
                    syncProfGalleryInput();
                    renderProfGallery();
                });
                $g.append($cell);
            });
        }
        syncProfGalleryInput();
    }

    function syncProfGalleryInput() {
        $('#ke-prof-gallery-ids').val(profState.galleryItems.map(function(x){ return x.id; }).join(','));
    }

    function setProfHero(id, url) {
        $('#ke-prof-hero-id').val(id ? String(id) : '');
        var $p = $('#ke-prof-hero-preview');
        if (id && url) {
            $p.removeClass('is-empty').css('background-image', 'url("' + url.replace(/"/g, '\\"') + '")').text('');
            $('#ke-prof-hero-clear').show();
        } else {
            $p.addClass('is-empty').css('background-image', '').text('<?php echo esc_js( __( 'No hero image', 'kiwi-events' ) ); ?>');
            $('#ke-prof-hero-clear').hide();
        }
    }

    function openProfModal(termId, orgName, heroId, heroUrl, category, galleryJson) {
        profState.termId = parseInt(termId, 10) || 0;
        $('#ke-prof-term-id').val(profState.termId);
        $('#ke-prof-org-name').text(orgName || '');
        $('#ke-prof-category').val(category || '');
        setProfHero(heroId, heroUrl);

        // Hydrate the gallery: the data-gallery attr only carries IDs, so
        // resolve URLs through wp.media.attachment so the thumbs render
        // immediately without an extra REST round-trip.
        var ids = [];
        try { ids = JSON.parse(galleryJson || '[]') || []; } catch (e) { ids = []; }
        profState.galleryItems = [];
        renderProfGallery();
        ids.forEach(function(id) {
            var att = wp.media.attachment(id);
            att.fetch().then(function() {
                var data = att.toJSON();
                var url = (data.sizes && data.sizes.thumbnail && data.sizes.thumbnail.url) || data.url;
                // Preserve original order (ids array) — push in array order.
                profState.galleryItems.push({ id: parseInt(id, 10), url: url });
                profState.galleryItems.sort(function(a, b) {
                    return ids.indexOf(a.id) - ids.indexOf(b.id);
                });
                renderProfGallery();
            });
        });

        $('#ke-prof-overlay').fadeIn(150);
        $('#ke-prof-modal').show();
    }

    function closeProfModal() {
        $('#ke-prof-modal').hide();
        $('#ke-prof-overlay').fadeOut(150);
        profState = { termId: null, galleryItems: [] };
    }

    $(document).on('click', '.ke-open-profile-btn', function() {
        var $b = $(this);
        openProfModal(
            $b.data('term-id'),
            $b.data('org-name'),
            $b.data('hero-id'),
            $b.data('hero-url'),
            $b.data('category'),
            // data-gallery is JSON-encoded — jQuery .data() will auto-parse
            // it to an array, so re-stringify so openProfModal can parse
            // either shape (string or array).
            typeof $b.data('gallery') === 'string' ? $b.data('gallery') : JSON.stringify($b.data('gallery') || [])
        );
    });
    $('#ke-prof-close, #ke-prof-cancel, #ke-prof-overlay').on('click', closeProfModal);
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('#ke-prof-modal').is(':visible')) closeProfModal();
    });

    // Hero picker — single image.
    var profHeroFrame;
    $(document).on('click', '#ke-prof-hero-pick', function(e) {
        e.preventDefault();
        if (!profHeroFrame) {
            profHeroFrame = wp.media({
                title: '<?php echo esc_js( __( 'Choose hero image', 'kiwi-events' ) ); ?>',
                button: { text: '<?php echo esc_js( __( 'Use this image', 'kiwi-events' ) ); ?>' },
                library: { type: 'image' },
                multiple: false
            });
            profHeroFrame.on('select', function() {
                var att = profHeroFrame.state().get('selection').first().toJSON();
                var url = (att.sizes && att.sizes.medium_large && att.sizes.medium_large.url)
                       || (att.sizes && att.sizes.large && att.sizes.large.url)
                       || att.url;
                setProfHero(att.id, url);
            });
        }
        profHeroFrame.open();
    });
    $(document).on('click', '#ke-prof-hero-clear', function() { setProfHero(0, ''); });

    // Gallery picker — multi-select. Appends to the existing list rather
    // than replacing it, so the admin can build the gallery up over time.
    var profGalleryFrame;
    $(document).on('click', '#ke-prof-gallery-add', function(e) {
        e.preventDefault();
        if (!profGalleryFrame) {
            profGalleryFrame = wp.media({
                title: '<?php echo esc_js( __( 'Add photos to gallery', 'kiwi-events' ) ); ?>',
                button: { text: '<?php echo esc_js( __( 'Add to gallery', 'kiwi-events' ) ); ?>' },
                library: { type: 'image' },
                multiple: 'add'
            });
            profGalleryFrame.on('select', function() {
                var sel = profGalleryFrame.state().get('selection').toJSON();
                var existing = {};
                profState.galleryItems.forEach(function(x) { existing[x.id] = true; });
                sel.forEach(function(att) {
                    if (existing[att.id]) return;
                    var url = (att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url) || att.url;
                    profState.galleryItems.push({ id: att.id, url: url });
                });
                renderProfGallery();
            });
        }
        profGalleryFrame.open();
    });

    // ── Per-card organizer logo edit ──────────────────────────────────
    // Click any card's logo circle → wp.media picker → POST to admin-ajax
    // (action=ke_update_organizer_logo) with the dedicated nonce. On
    // success, swap the <img> in place; if the card was showing the emoji
    // placeholder, replace it with a real <img> and drop the .is-empty
    // class. The server is the source of truth for the stored attachment
    // ID — this just keeps the UI in sync without a full reload.
    var logoFrame, logoTermId = null;

    $(document).on('click', '.ke-org-logo-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.hasClass('is-loading')) return;
        logoTermId = parseInt($btn.data('term-id'), 10) || 0;
        if (!logoTermId) return;

        if (!logoFrame) {
            logoFrame = wp.media({
                title:   '<?php echo esc_js( __( 'Choose organizer logo', 'kiwi-events' ) ); ?>',
                button:  { text: '<?php echo esc_js( __( 'Use this image', 'kiwi-events' ) ); ?>' },
                library: { type: 'image' },
                multiple: false
            });
            logoFrame.on('select', function() {
                var att = logoFrame.state().get('selection').first().toJSON();
                var url = (att.sizes && att.sizes.medium && att.sizes.medium.url)
                       || (att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url)
                       || att.url;
                saveOrgLogo(logoTermId, att.id, url);
            });
        }
        logoFrame.open();
    });

    function saveOrgLogo(termId, attachmentId, urlForUi) {
        var $btn = $('.ke-org-logo-btn[data-term-id="' + termId + '"]');
        if (!$btn.length) return;
        $btn.addClass('is-loading');

        $.ajax({
            url:    AJAX_URL,
            method: 'POST',
            data:   {
                action:        'ke_update_organizer_logo',
                _wpnonce:      LOGO_NONCE,
                term_id:       termId,
                attachment_id: attachmentId
            }
        }).done(function(res) {
            if (!res || !res.success) {
                showToast((res && res.data && res.data.message) || '<?php echo esc_js( __( 'Could not update logo.', 'kiwi-events' ) ); ?>', true);
                return;
            }
            var finalUrl = urlForUi || (res.data && res.data.thumb_url) || '';
            var $img = $btn.find('.ke-org-logo-img');
            if ($img.length) {
                $img.attr('src', finalUrl);
            } else {
                $btn.find('.ke-org-logo-placeholder').remove();
                $btn.removeClass('is-empty')
                    .prepend('<img class="ke-org-logo-img" alt="Logo" src="' + escA(finalUrl) + '">');
            }
            // Re-append the overlay so it stays above the new <img>.
            var $overlay = $btn.find('.ke-org-logo-overlay').detach();
            $btn.append($overlay);
            showToast('<?php echo esc_js( __( 'Logo updated.', 'kiwi-events' ) ); ?>');
        }).fail(function() {
            showToast('<?php echo esc_js( __( 'Network error. Please try again.', 'kiwi-events' ) ); ?>', true);
        }).always(function() {
            $btn.removeClass('is-loading');
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────
    function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function escA(s) { return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

})(jQuery);
</script>
