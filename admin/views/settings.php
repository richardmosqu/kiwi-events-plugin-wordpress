<?php
if ( ! defined( 'ABSPATH' ) ) exit;

wp_enqueue_script(
    'ke-settings-js',
    KE_PLUGIN_URL . 'admin/js/ke-settings.js',
    array( 'jquery' ),
    KE_VERSION,
    true
);
wp_localize_script( 'ke-settings-js', 'keSettings', array(
    'restUrl' => esc_url_raw( rest_url( 'ke/v1/' ) ),
    'nonce'   => wp_create_nonce( 'wp_rest' ),
) );

$ui       = get_option( 'ke_ui_settings', array() );
$accent   = ! empty( $ui['accent_color'] )   ? esc_attr( $ui['accent_color'] )   : '#6366f1';
$subtitle = ! empty( $ui['subtitle_color'] ) ? esc_attr( $ui['subtitle_color'] ) : '#71717a';

$access                 = get_option( 'ke_access_settings', array() );
$access_require_login   = ! empty( $access['require_login'] );
$access_login_url       = isset( $access['login_url'] )       ? $access['login_url']       : wp_login_url();
$access_register_url    = isset( $access['register_url'] )    ? $access['register_url']    : wp_registration_url();
$access_default_message = __( 'You need an account to purchase tickets for this event.', 'kiwi-events' );
$access_message         = isset( $access['login_required_message'] ) && $access['login_required_message'] !== ''
    ? $access['login_required_message']
    : $access_default_message;

$ps_url = home_url( '/kiwi-scanner/' );

// Organizers (terms) without a scanner password — surfaced as an info block
// so admins know which organizers won't trigger the optional gate.
$ps_orgs_missing = array();
$all_terms = get_terms( array( 'taxonomy' => 'ke_organizer', 'hide_empty' => false ) );
if ( ! is_wp_error( $all_terms ) ) {
    foreach ( $all_terms as $term ) {
        if ( ! KE_Scanner_Password::organizer_has_password( $term->term_id ) ) {
            $ps_orgs_missing[] = $term;
        }
    }
}
?>
<div class="wrap ke-wrap">

    <div class="ke-page-header">
        <div class="ke-page-header-left">
            <h1>System Settings</h1>
            <p>Configure global UI behavior and reusable service fees</p>
        </div>
    </div>

    <!-- ── UI CUSTOMIZATION ──────────────────────────────── -->
    <div class="ke-card ke-settings-card">
        <div class="ke-settings-card-header">
            <div>
                <h2 class="ke-settings-title">UI Customization</h2>
                <p class="ke-settings-desc">Brand colors applied globally across all public event pages</p>
            </div>
            <button type="button" class="ke-btn ke-btn-primary" id="ke-save-ui-btn">Save Colors</button>
        </div>

        <div class="ke-colors-grid">
            <!-- Accent -->
            <div class="ke-color-field">
                <label class="ke-color-label">Button &amp; Accent Color</label>
                <p class="ke-color-hint">Applies to CTA buttons, badges, and interactive highlights</p>
                <div class="ke-color-row">
                    <label class="ke-swatch-wrap">
                        <span class="ke-swatch" id="ke-accent-swatch" style="background:<?php echo $accent; ?>;"></span>
                        <input type="color" id="ke-accent-color" value="<?php echo $accent; ?>" class="ke-native-picker">
                    </label>
                    <input type="text" id="ke-accent-hex" class="ke-hex-input" value="<?php echo $accent; ?>" maxlength="7" spellcheck="false">
                </div>
                <div class="ke-color-preview-row">
                    <span class="ke-preview-label">Preview:</span>
                    <button type="button" class="ke-preview-cta" id="ke-preview-btn"
                            style="background:<?php echo $accent; ?>; box-shadow:0 4px 16px <?php echo $accent; ?>59;">Get Tickets</button>
                    <span class="ke-preview-pill" id="ke-preview-pill"
                          style="background:<?php echo $accent; ?>14; color:<?php echo $accent; ?>; border:1px solid <?php echo $accent; ?>33;">Free</span>
                </div>
            </div>

            <!-- Subtitle -->
            <div class="ke-color-field">
                <label class="ke-color-label">Subtitle &amp; Secondary Text</label>
                <p class="ke-color-hint">Applies to descriptions, metadata, and secondary labels</p>
                <div class="ke-color-row">
                    <label class="ke-swatch-wrap">
                        <span class="ke-swatch" id="ke-subtitle-swatch" style="background:<?php echo $subtitle; ?>;"></span>
                        <input type="color" id="ke-subtitle-color" value="<?php echo $subtitle; ?>" class="ke-native-picker">
                    </label>
                    <input type="text" id="ke-subtitle-hex" class="ke-hex-input" value="<?php echo $subtitle; ?>" maxlength="7" spellcheck="false">
                </div>
                <div class="ke-color-preview-row">
                    <span class="ke-preview-label">Preview:</span>
                    <span class="ke-preview-secondary" id="ke-preview-text"
                          style="color:<?php echo $subtitle; ?>;">Saturday, 12 July · 8:00 PM EST</span>
                </div>
            </div>
        </div>

        <div id="ke-ui-msg" class="ke-settings-msg" style="display:none;"></div>
    </div>

    <!-- ── SERVICE FEES ──────────────────────────────────── -->
    <div class="ke-card ke-settings-card">
        <div class="ke-settings-card-header">
            <div>
                <h2 class="ke-settings-title">Service Fees</h2>
                <p class="ke-settings-desc">Reusable fee presets you can assign to individual events</p>
            </div>
            <button type="button" class="ke-btn ke-btn-primary" id="ke-open-fee-form">+ New Fee</button>
        </div>

        <!-- Add / Edit Form -->
        <div class="ke-fee-form-wrap" id="ke-fee-form-wrap" style="display:none;">
            <div class="ke-fee-form">
                <input type="hidden" id="ke-edit-fee-id">

                <div class="ke-fee-form-grid">
                    <div class="ke-fee-col ke-fee-col-wide">
                        <label class="ke-fee-form-label">Fee Name <span>*</span></label>
                        <input type="text" id="ke-fee-name" placeholder="e.g. Standard Fee" maxlength="80">
                    </div>

                    <div class="ke-fee-col">
                        <label class="ke-fee-form-label">Fee Type</label>
                        <div class="ke-fee-type-tabs">
                            <button type="button" class="ke-fee-tab ke-tab-active" data-type="formula">
                                Formula
                            </button>
                            <button type="button" class="ke-fee-tab" data-type="fixed">
                                Fixed
                            </button>
                        </div>
                        <input type="hidden" id="ke-fee-type" value="formula">
                    </div>
                </div>

                <!-- Formula fields -->
                <div class="ke-fee-form-grid" id="ke-formula-fields">
                    <div class="ke-fee-col">
                        <label class="ke-fee-form-label">Percentage</label>
                        <div class="ke-input-with-affix">
                            <input type="number" id="ke-fee-pct" step="0.01" min="0" max="100" placeholder="3.5">
                            <span class="ke-affix-suffix">%</span>
                        </div>
                    </div>
                    <div class="ke-fee-col">
                        <label class="ke-fee-form-label">Fixed Amount</label>
                        <div class="ke-input-with-affix">
                            <span class="ke-affix-prefix">$</span>
                            <input type="number" id="ke-fee-fixed" step="0.01" min="0" placeholder="0.50">
                        </div>
                    </div>
                    <div class="ke-fee-col">
                        <label class="ke-fee-form-label">Formula Preview</label>
                        <div class="ke-formula-chip" id="ke-formula-chip">(price × 0%) + $0.00</div>
                    </div>
                </div>

                <!-- Fixed only field -->
                <div class="ke-fee-form-grid" id="ke-fixed-only-fields" style="display:none;">
                    <div class="ke-fee-col">
                        <label class="ke-fee-form-label">Fixed Amount per Ticket</label>
                        <div class="ke-input-with-affix">
                            <span class="ke-affix-prefix">$</span>
                            <input type="number" id="ke-fee-fixed-only" step="0.01" min="0" placeholder="2.50">
                        </div>
                    </div>
                    <div class="ke-fee-col">
                        <label class="ke-fee-form-label">Preview</label>
                        <div class="ke-formula-chip" id="ke-fixed-chip">$0.00 per ticket</div>
                    </div>
                </div>

                <div class="ke-fee-form-footer">
                    <div id="ke-fee-form-msg" class="ke-settings-msg" style="display:none;"></div>
                    <div class="ke-fee-form-actions">
                        <button type="button" class="ke-btn ke-btn-ghost" id="ke-cancel-fee">Cancel</button>
                        <button type="button" class="ke-btn ke-btn-primary" id="ke-save-fee">Save Fee</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fee List -->
        <div id="ke-fees-list">
            <div class="ke-fees-empty" id="ke-fees-empty" style="display:none;">
                <p>No service fees yet. Click <strong>+ New Fee</strong> to create one.</p>
            </div>
        </div>
    </div>

    <!-- ── NOTIFICATIONS ──────────────────────────────────── -->
    <div class="ke-card ke-settings-card">
        <div class="ke-settings-card-header">
            <div>
                <h2 class="ke-settings-title">Notifications</h2>
                <p class="ke-settings-desc">Manage email alerts when tickets are purchased.</p>
            </div>
            <button type="button" class="ke-btn ke-btn-ghost" id="ke-test-notification-btn">Send Test Notification</button>
        </div>
        
        <div class="ke-settings-grid">
            <div class="ke-form-group ke-mb-4" style="display: flex; align-items: center; gap: 12px;">
                <label class="ke-toggle">
                    <input type="checkbox" id="ke-admin-email-enabled" checked>
                    <span class="ke-toggle-slider"></span>
                </label>
                <div>
                    <label class="ke-form-label" style="margin-bottom:2px; display:block;">Send email to admin on ticket purchase</label>
                    <p class="ke-form-hint" style="margin:0;">Notifies the event organizer (or site admin fallback) when an order is completed.</p>
                </div>
            </div>

            <div class="ke-form-group">
                <label class="ke-form-label" for="ke-global-bcc">Global BCC Email (Optional)</label>
                <input type="email" id="ke-global-bcc" class="ke-input" placeholder="e.g. tracking@example.com">
                <p class="ke-form-hint">A central address that receives a blind carbon copy of ALL admin notifications.</p>
            </div>
        </div>
        <div class="ke-settings-card-footer" style="margin-top: 24px; text-align: right;">
            <div id="ke-notifications-msg" class="ke-settings-msg" style="display:none; max-width: 400px; margin: 0 auto 16px auto; text-align: center;"></div>
            <button type="button" class="ke-btn ke-btn-primary" id="ke-save-notifications-btn">Save Notifications</button>
        </div>
    </div>

    <!-- ── ACCESS CONTROL ──────────────────────────────────── -->
    <div class="ke-card ke-settings-card">
        <div class="ke-settings-card-header">
            <div>
                <h2 class="ke-settings-title"><?php esc_html_e( 'Access Control', 'kiwi-events' ); ?></h2>
                <p class="ke-settings-desc"><?php esc_html_e( 'Require a WordPress account before buying tickets.', 'kiwi-events' ); ?></p>
            </div>
        </div>

        <div class="ke-settings-grid">
            <div class="ke-form-group ke-mb-4" style="display: flex; align-items: center; gap: 12px;">
                <label class="ke-toggle">
                    <input type="checkbox" id="ke-require-login" <?php checked( $access_require_login ); ?>>
                    <span class="ke-toggle-slider"></span>
                </label>
                <div>
                    <label class="ke-form-label" style="margin-bottom:2px; display:block;"><?php esc_html_e( 'Require login to purchase tickets', 'kiwi-events' ); ?></label>
                    <p class="ke-form-hint" style="margin:0;"><?php esc_html_e( 'When enabled, guests must log in before they can add tickets to cart or complete a free ticket reservation.', 'kiwi-events' ); ?></p>
                </div>
            </div>

            <div class="ke-form-group">
                <label class="ke-form-label" for="ke-login-url"><?php esc_html_e( 'Login page URL', 'kiwi-events' ); ?></label>
                <input type="text" id="ke-login-url" class="ke-input" value="<?php echo esc_attr( $access_login_url ); ?>" placeholder="/login">
                <p class="ke-form-hint"><?php esc_html_e( 'Where users are sent to sign in. A redirect_to parameter is appended automatically.', 'kiwi-events' ); ?></p>
            </div>

            <div class="ke-form-group">
                <label class="ke-form-label" for="ke-register-url"><?php esc_html_e( 'Register page URL', 'kiwi-events' ); ?></label>
                <input type="text" id="ke-register-url" class="ke-input" value="<?php echo esc_attr( $access_register_url ); ?>" placeholder="/register">
                <p class="ke-form-hint"><?php esc_html_e( 'Where users are sent to create an account.', 'kiwi-events' ); ?></p>
            </div>

            <div class="ke-form-group">
                <label class="ke-form-label" for="ke-login-required-message"><?php esc_html_e( 'Custom message when login required', 'kiwi-events' ); ?></label>
                <textarea id="ke-login-required-message" class="ke-textarea" rows="2" placeholder="<?php echo esc_attr( $access_default_message ); ?>"><?php echo esc_textarea( $access_message ); ?></textarea>
                <p class="ke-form-hint"><?php esc_html_e( 'Shown to guests in the ticket sheet before they log in.', 'kiwi-events' ); ?></p>
            </div>
        </div>

        <div class="ke-settings-card-footer" style="margin-top: 24px; text-align: right;">
            <div id="ke-access-msg" class="ke-settings-msg" style="display:none; max-width: 400px; margin: 0 auto 16px auto; text-align: center;"></div>
            <button type="button" class="ke-btn ke-btn-primary" id="ke-save-access-btn"><?php esc_html_e( 'Save Access Control', 'kiwi-events' ); ?></button>
        </div>
    </div>

    <!-- ── SCANNER ────────────────────────────────────────── -->
    <div class="ke-card ke-settings-card">
        <div class="ke-settings-card-header">
            <div>
                <h2 class="ke-settings-title"><?php esc_html_e( 'Scanner', 'kiwi-events' ); ?></h2>
                <p class="ke-settings-desc"><?php esc_html_e( 'Public URL for door staff. Anyone with the link reaches the event picker; the per-organizer password gates camera access for each event.', 'kiwi-events' ); ?></p>
            </div>
            <a href="<?php echo esc_url( $ps_url ); ?>" target="_blank" rel="noopener" class="ke-btn ke-btn-primary"><?php esc_html_e( 'Open Scanner', 'kiwi-events' ); ?></a>
        </div>

        <div class="ke-settings-grid">
            <div class="ke-form-group">
                <label class="ke-form-label" for="ke-public-scanner-url"><?php esc_html_e( 'Scanner URL', 'kiwi-events' ); ?></label>
                <div style="display:flex;gap:8px;align-items:stretch;">
                    <input type="text"
                           id="ke-public-scanner-url"
                           class="ke-input"
                           value="<?php echo esc_attr( $ps_url ); ?>"
                           readonly
                           style="flex:1;">
                    <button type="button" id="ke-public-scanner-copy" class="ke-btn ke-btn-secondary"><?php esc_html_e( 'Copy', 'kiwi-events' ); ?></button>
                </div>
                <p class="ke-form-hint"><?php esc_html_e( 'Share this link with staff. They pick the event and enter the organizer password to start scanning. Sessions last 4 hours.', 'kiwi-events' ); ?></p>
            </div>
        </div>

        <?php if ( ! empty( $ps_orgs_missing ) ) : ?>
        <div class="ke-settings-grid">
            <div class="ke-form-group" style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:14px;">
                <strong style="color:#9a3412;display:block;margin-bottom:6px;">⚠ <?php esc_html_e( 'Organizers without a scanner password', 'kiwi-events' ); ?></strong>
                <p class="ke-form-hint" style="margin:0 0 8px;color:#9a3412;"><?php esc_html_e( 'These organizers have no password set. Their events cannot be scanned until you set one — the password is the door-staff access control on a public URL.', 'kiwi-events' ); ?></p>
                <ul style="margin:0 0 0 18px;color:#7c2d12;">
                    <?php foreach ( $ps_orgs_missing as $term ) : ?>
                        <li>
                            <a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=ke_organizer&action=edit&tag_ID=' . $term->term_id ) ); ?>"
                               style="color:#7c2d12;"><?php echo esc_html( $term->name ); ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>
