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

// Dark-mode toggle uses admin-ajax (not REST). Localize the dedicated nonce
// and ajaxurl onto the same ke-settings-js handle so the inline toggle
// handler below can fire the wp_ajax_ke_save_color_mode endpoint.
wp_localize_script( 'ke-settings-js', 'keColorMode', array(
    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
    'nonce'   => wp_create_nonce( KE_Admin_Color_Mode::NONCE_ACTION ),
    'current' => KE_Admin_Color_Mode::get_user_mode(),
) );
$ke_current_color_mode = KE_Admin_Color_Mode::get_user_mode();

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

// Promoters settings — read once, used inside the Promoters tab.
$prevent_self    = get_option( 'ke_promoter_prevent_self_attribution', '1' ) === '1';
$notify_on_earn  = get_option( 'ke_promoter_notify_on_earn', '1' ) === '1';
$refund_policy   = get_option( 'ke_promoter_refund_policy', 'keep' );
$def_comm_type   = get_option( 'ke_promoter_default_commission_type', 'percentage' );
$def_comm_value  = (float) get_option( 'ke_promoter_default_commission_value', 0 );
$currency_label  = (string) get_option( 'ke_promoter_currency_label', '$' );
$global_terms    = (string) get_option( 'ke_promoter_global_terms', '' );

$promo_flash = get_transient( 'ke_promoter_flash_' . get_current_user_id() );
if ( $promo_flash ) delete_transient( 'ke_promoter_flash_' . get_current_user_id() );

// The 8-tab nav. Order matters — `general` is the default landing tab when
// no URL hash is present. Icon is rendered as plain text in a span so we
// can hide labels on narrow screens without losing the visual marker.
$ke_settings_tabs = array(
    array( 'id' => 'general',    'icon' => '⚙️', 'label' => __( 'General',    'kiwi-events' ) ),
    array( 'id' => 'payments',   'icon' => '💳', 'label' => __( 'Payments',   'kiwi-events' ) ),
    array( 'id' => 'emails',     'icon' => '✉️', 'label' => __( 'Emails',     'kiwi-events' ) ),
    array( 'id' => 'events',     'icon' => '🎫', 'label' => __( 'Events',     'kiwi-events' ) ),
    array( 'id' => 'organizers', 'icon' => '👥', 'label' => __( 'Organizers', 'kiwi-events' ) ),
    array( 'id' => 'promoters',  'icon' => '🎯', 'label' => __( 'Promoters',  'kiwi-events' ) ),
    array( 'id' => 'categories', 'icon' => '🏷️', 'label' => __( 'Categories', 'kiwi-events' ) ),
    array( 'id' => 'advanced',   'icon' => '🔧', 'label' => __( 'Advanced',   'kiwi-events' ) ),
);
?>
<div class="wrap ke-wrap">

    <!-- ── Page header — own white section card ── -->
    <div class="ke-section-card ke-section-card--compact">
        <div class="ke-page-header" style="margin:0;">
            <div class="ke-page-header-left">
                <h1>System Settings</h1>
                <p>Configure global behavior, payments, emails, events, and more from one place.</p>
            </div>
        </div>
    </div>

    <!-- ── Tab navigation ── -->
    <nav class="ke-settings-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'kiwi-events' ); ?>">
        <?php foreach ( $ke_settings_tabs as $i => $tab ) :
            $is_active = $i === 0; // 'general' is the default active tab; JS may override on load via hash.
        ?>
            <button type="button"
                    class="ke-settings-tab<?php echo $is_active ? ' is-active' : ''; ?>"
                    data-tab-id="<?php echo esc_attr( $tab['id'] ); ?>"
                    role="tab"
                    aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
                    aria-controls="ke-tab-content-<?php echo esc_attr( $tab['id'] ); ?>">
                <span class="ke-settings-tab-icon" aria-hidden="true"><?php echo esc_html( $tab['icon'] ); ?></span>
                <span class="ke-settings-tab-label"><?php echo esc_html( $tab['label'] ); ?></span>
            </button>
        <?php endforeach; ?>
    </nav>

    <!-- ─────────────────────────────────────────────────────────────
         TAB 1 — GENERAL
         ───────────────────────────────────────────────────────────── -->
    <div class="ke-settings-content is-active"
         id="ke-tab-content-general"
         data-content-id="general"
         role="tabpanel"
         aria-labelledby="ke-tab-general">

        <!-- Branding (was: UI Customization) -->
        <div class="ke-section-card ke-settings-card">
            <div class="ke-settings-card-header">
                <div>
                    <h2 class="ke-settings-title"><?php esc_html_e( 'Branding', 'kiwi-events' ); ?></h2>
                    <p class="ke-settings-desc"><?php esc_html_e( 'Brand colors applied globally across all public event pages', 'kiwi-events' ); ?></p>
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

        <!-- Appearance (light/dark mode for the Kiwi admin chrome) -->
        <div class="ke-section-card ke-settings-card">
            <div class="ke-settings-card-header">
                <div>
                    <h2 class="ke-settings-title"><?php esc_html_e( 'Appearance', 'kiwi-events' ); ?></h2>
                    <p class="ke-settings-desc"><?php esc_html_e( 'Color mode for the Kiwi Events admin. Saved per user — your choice does not affect other users or the public site.', 'kiwi-events' ); ?></p>
                </div>
            </div>

            <div class="ke-color-mode-toggle" role="radiogroup" aria-label="<?php esc_attr_e( 'Color mode', 'kiwi-events' ); ?>">
                <label class="ke-color-mode-option<?php echo $ke_current_color_mode === 'light' ? ' is-active' : ''; ?>">
                    <input type="radio" name="ke_admin_color_mode" value="light" <?php checked( $ke_current_color_mode, 'light' ); ?>>
                    <div class="ke-color-mode-preview ke-color-mode-preview-light" aria-hidden="true">
                        <div class="ke-color-mode-preview-bar"></div>
                        <div class="ke-color-mode-preview-card"></div>
                        <div class="ke-color-mode-preview-card ke-color-mode-preview-card--alt"></div>
                    </div>
                    <span class="ke-color-mode-label">
                        <span class="ke-color-mode-icon" aria-hidden="true">☀</span>
                        <?php esc_html_e( 'Light', 'kiwi-events' ); ?>
                    </span>
                </label>

                <label class="ke-color-mode-option<?php echo $ke_current_color_mode === 'dark' ? ' is-active' : ''; ?>">
                    <input type="radio" name="ke_admin_color_mode" value="dark" <?php checked( $ke_current_color_mode, 'dark' ); ?>>
                    <div class="ke-color-mode-preview ke-color-mode-preview-dark" aria-hidden="true">
                        <div class="ke-color-mode-preview-bar"></div>
                        <div class="ke-color-mode-preview-card"></div>
                        <div class="ke-color-mode-preview-card ke-color-mode-preview-card--alt"></div>
                    </div>
                    <span class="ke-color-mode-label">
                        <span class="ke-color-mode-icon" aria-hidden="true">☾</span>
                        <?php esc_html_e( 'Dark', 'kiwi-events' ); ?>
                    </span>
                </label>
            </div>

            <p id="ke-color-mode-msg" class="ke-settings-msg" style="display:none;"></p>
        </div>

        <style>
            /* ── Appearance toggle (settings.php local) ─────────────────────
               Local because this UI only exists on the General tab. Consumes
               admin tokens so light + dark modes both render correctly. The
               INSIDES of the two preview cards intentionally hard-code their
               literal light/dark colors — they are mode previews, not mode-
               aware surfaces (documented exception). */
            .ke-color-mode-toggle {
                display: flex;
                gap: 16px;
                margin-top: 8px;
                flex-wrap: wrap;
            }
            .ke-color-mode-option {
                flex: 1 1 220px;
                cursor: pointer;
                border-radius: var(--kiwi-radius-lg);
                padding: 14px;
                border: 2px solid var(--kiwi-border);
                background: var(--kiwi-surface);
                transition: border-color .15s var(--kiwi-ease),
                            background .15s var(--kiwi-ease),
                            box-shadow .15s var(--kiwi-ease);
                display: flex;
                flex-direction: column;
                gap: 12px;
                position: relative;
            }
            .ke-color-mode-option:hover:not(.is-active) {
                border-color: var(--kiwi-border-strong);
                background: var(--kiwi-surface-muted);
            }
            .ke-color-mode-option.is-active {
                border-color: var(--kiwi-green);
                background: var(--kiwi-green-tint);
                box-shadow: 0 0 0 3px var(--kiwi-green-glow-soft);
            }
            .ke-color-mode-option input[type="radio"] {
                position: absolute;
                opacity: 0;
                pointer-events: none;
            }
            .ke-color-mode-option input[type="radio"]:focus-visible + .ke-color-mode-preview {
                outline: 2px solid var(--kiwi-green);
                outline-offset: 2px;
            }
            .ke-color-mode-preview {
                width: 100%;
                aspect-ratio: 16 / 10;
                border-radius: var(--kiwi-radius-md);
                overflow: hidden;
                position: relative;
                border: 1px solid var(--kiwi-border-hairline);
                padding: 8px;
                box-sizing: border-box;
            }
            /* HARDCODED MODE PREVIEW COLORS — intentional. These two blocks
               must look like Light/Dark mode regardless of which mode the
               user is currently in. Do NOT tokenize. */
            .ke-color-mode-preview-light {
                background: #f8f5ed;
            }
            .ke-color-mode-preview-light .ke-color-mode-preview-bar {
                height: 8px;
                background: #ffffff;
                border: 1px solid rgba(0, 0, 0, 0.06);
                border-radius: 4px;
            }
            .ke-color-mode-preview-light .ke-color-mode-preview-card {
                margin-top: 6px;
                height: 22px;
                background: #ffffff;
                border: 1px solid rgba(0, 0, 0, 0.06);
                border-radius: 6px;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            }
            .ke-color-mode-preview-light .ke-color-mode-preview-card--alt {
                margin-top: 4px;
                height: 14px;
                opacity: 0.65;
            }
            .ke-color-mode-preview-dark {
                background: #0f0f10;
            }
            .ke-color-mode-preview-dark .ke-color-mode-preview-bar {
                height: 8px;
                background: #1a1a1c;
                border: 1px solid rgba(255, 255, 255, 0.06);
                border-radius: 4px;
            }
            .ke-color-mode-preview-dark .ke-color-mode-preview-card {
                margin-top: 6px;
                height: 22px;
                background: #1f1f22;
                border: 1px solid rgba(255, 255, 255, 0.06);
                border-radius: 6px;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
            }
            .ke-color-mode-preview-dark .ke-color-mode-preview-card--alt {
                margin-top: 4px;
                height: 14px;
                opacity: 0.65;
            }
            .ke-color-mode-label {
                display: flex;
                align-items: center;
                gap: 8px;
                font-weight: 600;
                color: var(--kiwi-text);
                font-size: 14px;
            }
            .ke-color-mode-icon {
                font-size: 16px;
                line-height: 1;
            }
            #ke-color-mode-msg.is-success {
                color: var(--kiwi-green-text);
            }
            #ke-color-mode-msg.is-error {
                color: var(--kiwi-red-text);
            }
        </style>

        <script>
            // Color-mode toggle. Deferred to DOMContentLoaded because
            // wp_localize_script with $in_footer=true emits window.keColorMode
            // AFTER this inline <script> in the body. Reading it at parse time
            // yielded undefined and the listeners never bound (silent failure).
            document.addEventListener('DOMContentLoaded', function () {
                var TAG = '[Kiwi Color Mode]';
                var cfg = window.keColorMode;
                if (!cfg || !cfg.ajaxUrl || !cfg.nonce) {
                    console.warn(TAG, 'config missing', cfg);
                    return;
                }
                console.log(TAG, 'init', { current: cfg.current });

                var msg = document.getElementById('ke-color-mode-msg');
                var toggle = document.querySelector('.ke-color-mode-toggle');
                if (!toggle) {
                    console.warn(TAG, 'toggle container not found');
                    return;
                }

                function setMsg(text, kind) {
                    if (!msg) return;
                    msg.textContent = text;
                    msg.classList.remove('is-success', 'is-error');
                    if (kind) msg.classList.add('is-' + kind);
                    msg.style.display = text ? '' : 'none';
                }

                function applyTheme(mode) {
                    if (mode === 'dark') {
                        document.documentElement.setAttribute('data-theme', 'dark');
                    } else {
                        document.documentElement.removeAttribute('data-theme');
                    }
                    document.querySelectorAll('.ke-color-mode-option').forEach(function (el) {
                        el.classList.remove('is-active');
                    });
                    var checkedInput = toggle.querySelector('input[type="radio"]:checked');
                    if (checkedInput) {
                        var parent = checkedInput.closest('.ke-color-mode-option');
                        if (parent) parent.classList.add('is-active');
                    }
                }

                function save(mode) {
                    console.log(TAG, 'save', mode);
                    setMsg('', null);
                    applyTheme(mode);

                    var body = new URLSearchParams();
                    body.append('action', 'ke_save_color_mode');
                    body.append('mode', mode);
                    body.append('nonce', cfg.nonce);

                    fetch(cfg.ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString()
                    })
                        .then(function (r) { return r.json().catch(function () { return null; }); })
                        .then(function (data) {
                            console.log(TAG, 'response', data);
                            if (data && data.success) {
                                cfg.current = mode;
                                setMsg('<?php echo esc_js( __( 'Saved.', 'kiwi-events' ) ); ?>', 'success');
                                setTimeout(function () { setMsg('', null); }, 2000);
                            } else {
                                applyTheme(cfg.current);
                                var other = toggle.querySelector('input[value="' + cfg.current + '"]');
                                if (other) other.checked = true;
                                setMsg('<?php echo esc_js( __( 'Could not save preference.', 'kiwi-events' ) ); ?>', 'error');
                            }
                        })
                        .catch(function (err) {
                            console.error(TAG, 'network error', err);
                            applyTheme(cfg.current);
                            var other = toggle.querySelector('input[value="' + cfg.current + '"]');
                            if (other) other.checked = true;
                            setMsg('<?php echo esc_js( __( 'Network error — preference not saved.', 'kiwi-events' ) ); ?>', 'error');
                        });
                }

                // Event delegation on the toggle container so clicks on the
                // label / preview card / icon all route through one handler.
                toggle.addEventListener('change', function (e) {
                    var input = e.target && e.target.matches && e.target.matches('input[type="radio"][name="ke_admin_color_mode"]')
                        ? e.target
                        : null;
                    if (!input) return;
                    save(input.value);
                });

                // Safety net: some browsers fire `click` on the wrapping
                // <label> without re-firing `change` if the input was already
                // checked. Catch that case explicitly so a re-click still
                // re-applies the theme (cheap, idempotent).
                toggle.addEventListener('click', function (e) {
                    var option = e.target && e.target.closest ? e.target.closest('.ke-color-mode-option') : null;
                    if (!option) return;
                    var input = option.querySelector('input[type="radio"][name="ke_admin_color_mode"]');
                    if (!input) return;
                    if (input.checked && input.value !== cfg.current) {
                        save(input.value);
                    }
                });
            });
        </script>

        <!-- Placeholder: Site display -->
        <div class="ke-settings-placeholder">
            <div class="ke-settings-placeholder-title">
                <span class="ke-settings-placeholder-icon">🚧</span>
                <?php esc_html_e( 'Site display', 'kiwi-events' ); ?>
            </div>
            <div class="ke-settings-placeholder-body">
                <?php esc_html_e( 'More general settings coming soon (timezone, currency, date formats, plugin display name).', 'kiwi-events' ); ?>
            </div>
        </div>
    </div>

    <!-- ─────────────────────────────────────────────────────────────
         TAB 2 — PAYMENTS
         ───────────────────────────────────────────────────────────── -->
    <div class="ke-settings-content"
         id="ke-tab-content-payments"
         data-content-id="payments"
         role="tabpanel"
         aria-labelledby="ke-tab-payments">

        <!-- Service Fees -->
        <div class="ke-section-card ke-settings-card">
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

        <!-- Placeholder: Gateways -->
        <div class="ke-settings-placeholder">
            <div class="ke-settings-placeholder-title">
                <span class="ke-settings-placeholder-icon">🚧</span>
                <?php esc_html_e( 'Gateways', 'kiwi-events' ); ?>
            </div>
            <div class="ke-settings-placeholder-body">
                <?php esc_html_e( 'Coming soon — manage payment gateways (Yappy, PowerTranz, Stripe, etc.) from a single place.', 'kiwi-events' ); ?>
            </div>
        </div>

        <!-- Placeholder: Refunds -->
        <div class="ke-settings-placeholder">
            <div class="ke-settings-placeholder-title">
                <span class="ke-settings-placeholder-icon">🚧</span>
                <?php esc_html_e( 'Refunds', 'kiwi-events' ); ?>
            </div>
            <div class="ke-settings-placeholder-body">
                <?php esc_html_e( 'Coming soon — configure refund policies and auto-refund windows.', 'kiwi-events' ); ?>
            </div>
        </div>
    </div>

    <!-- ─────────────────────────────────────────────────────────────
         TAB 3 — EMAILS
         ───────────────────────────────────────────────────────────── -->
    <div class="ke-settings-content"
         id="ke-tab-content-emails"
         data-content-id="emails"
         role="tabpanel"
         aria-labelledby="ke-tab-emails">

        <!-- Notifications -->
        <div class="ke-section-card ke-settings-card">
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

        <!-- Placeholder: Sender info -->
        <div class="ke-settings-placeholder">
            <div class="ke-settings-placeholder-title">
                <span class="ke-settings-placeholder-icon">🚧</span>
                <?php esc_html_e( 'Sender info', 'kiwi-events' ); ?>
            </div>
            <div class="ke-settings-placeholder-body">
                <?php esc_html_e( 'Coming soon — configure From name, From email, and Reply-to address for all emails.', 'kiwi-events' ); ?>
            </div>
        </div>

        <!-- Placeholder: Templates -->
        <div class="ke-settings-placeholder">
            <div class="ke-settings-placeholder-title">
                <span class="ke-settings-placeholder-icon">🚧</span>
                <?php esc_html_e( 'Templates', 'kiwi-events' ); ?>
            </div>
            <div class="ke-settings-placeholder-body">
                <?php esc_html_e( 'Coming soon — customize email templates for ticket confirmations, promoter welcomes, refunds, and reminders.', 'kiwi-events' ); ?>
            </div>
        </div>

        <!-- Placeholder: Footer -->
        <div class="ke-settings-placeholder">
            <div class="ke-settings-placeholder-title">
                <span class="ke-settings-placeholder-icon">🚧</span>
                <?php esc_html_e( 'Footer', 'kiwi-events' ); ?>
            </div>
            <div class="ke-settings-placeholder-body">
                <?php esc_html_e( 'Coming soon — add custom footer text and social links to all emails.', 'kiwi-events' ); ?>
            </div>
        </div>
    </div>

    <!-- ─────────────────────────────────────────────────────────────
         TAB 4 — EVENTS
         ───────────────────────────────────────────────────────────── -->
    <div class="ke-settings-content"
         id="ke-tab-content-events"
         data-content-id="events"
         role="tabpanel"
         aria-labelledby="ke-tab-events">

        <!-- Access Control -->
        <div class="ke-section-card ke-settings-card">
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

        <!-- Check-in & Scanner -->
        <div class="ke-section-card ke-settings-card">
            <div class="ke-settings-card-header">
                <div>
                    <h2 class="ke-settings-title"><?php esc_html_e( 'Check-in & Scanner', 'kiwi-events' ); ?></h2>
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
                <div class="ke-form-group" style="background:var(--kiwi-legacy-orange-50);border:1px solid var(--kiwi-legacy-orange-200);border-radius:10px;padding:14px;">
                    <strong style="color:var(--kiwi-legacy-orange-800);display:block;margin-bottom:6px;">⚠ <?php esc_html_e( 'Organizers without a scanner password', 'kiwi-events' ); ?></strong>
                    <p class="ke-form-hint" style="margin:0 0 8px;color:var(--kiwi-legacy-orange-800);"><?php esc_html_e( 'These organizers have no password set. Their events cannot be scanned until you set one — the password is the door-staff access control on a public URL.', 'kiwi-events' ); ?></p>
                    <ul style="margin:0 0 0 18px;color:var(--kiwi-legacy-orange-900);">
                        <?php foreach ( $ps_orgs_missing as $term ) : ?>
                            <li>
                                <a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=ke_organizer&action=edit&tag_ID=' . $term->term_id ) ); ?>"
                                   style="color:var(--kiwi-legacy-orange-900);"><?php echo esc_html( $term->name ); ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Placeholder: Defaults -->
        <div class="ke-settings-placeholder">
            <div class="ke-settings-placeholder-title">
                <span class="ke-settings-placeholder-icon">🚧</span>
                <?php esc_html_e( 'Defaults', 'kiwi-events' ); ?>
            </div>
            <div class="ke-settings-placeholder-body">
                <?php esc_html_e( 'Coming soon — default banner placeholder, default event duration, default location type, default category.', 'kiwi-events' ); ?>
            </div>
        </div>

        <!-- Placeholder: URLs -->
        <div class="ke-settings-placeholder">
            <div class="ke-settings-placeholder-title">
                <span class="ke-settings-placeholder-icon">🚧</span>
                <?php esc_html_e( 'URLs', 'kiwi-events' ); ?>
            </div>
            <div class="ke-settings-placeholder-body">
                <?php esc_html_e( 'Coming soon — event URL prefix, slug rules, 301 redirects configuration.', 'kiwi-events' ); ?>
            </div>
        </div>

        <!-- Placeholder: Features -->
        <div class="ke-settings-placeholder">
            <div class="ke-settings-placeholder-title">
                <span class="ke-settings-placeholder-icon">🚧</span>
                <?php esc_html_e( 'Features', 'kiwi-events' ); ?>
            </div>
            <div class="ke-settings-placeholder-body">
                <?php esc_html_e( 'Coming soon — enable/disable reservations system, courtesy attendees, public check-in stats.', 'kiwi-events' ); ?>
            </div>
        </div>
    </div>

    <!-- ─────────────────────────────────────────────────────────────
         TAB 5 — ORGANIZERS
         ───────────────────────────────────────────────────────────── -->
    <div class="ke-settings-content"
         id="ke-tab-content-organizers"
         data-content-id="organizers"
         role="tabpanel"
         aria-labelledby="ke-tab-organizers">

        <!-- Placeholder: System -->
        <div class="ke-settings-placeholder">
            <div class="ke-settings-placeholder-title">
                <span class="ke-settings-placeholder-icon">🚧</span>
                <?php esc_html_e( 'System', 'kiwi-events' ); ?>
            </div>
            <div class="ke-settings-placeholder-body">
                <?php esc_html_e( 'Coming soon — enable/disable organizers, dashboard URL pattern, allow organizers to create events.', 'kiwi-events' ); ?>
            </div>
        </div>

        <!-- Placeholder: Defaults -->
        <div class="ke-settings-placeholder">
            <div class="ke-settings-placeholder-title">
                <span class="ke-settings-placeholder-icon">🚧</span>
                <?php esc_html_e( 'Defaults', 'kiwi-events' ); ?>
            </div>
            <div class="ke-settings-placeholder-body">
                <?php esc_html_e( 'Coming soon — default permissions for new organizers, default Kiwi scanner password.', 'kiwi-events' ); ?>
            </div>
        </div>
    </div>

    <!-- ─────────────────────────────────────────────────────────────
         TAB 6 — PROMOTERS
         ───────────────────────────────────────────────────────────── -->
    <div class="ke-settings-content"
         id="ke-tab-content-promoters"
         data-content-id="promoters"
         role="tabpanel"
         aria-labelledby="ke-tab-promoters">

        <div class="ke-section-card ke-settings-card" id="ke-promoter-settings">
            <?php if ( $promo_flash ) : ?>
                <div class="notice notice-<?php echo esc_attr( $promo_flash['type'] === 'success' ? 'success' : 'error' ); ?>" style="margin-bottom:14px;">
                    <p><?php echo esc_html( $promo_flash['message'] ); ?></p>
                </div>
            <?php endif; ?>

            <div class="ke-settings-card-header">
                <div>
                    <h2 class="ke-settings-title"><?php esc_html_e( 'Defaults & policies', 'kiwi-events' ); ?></h2>
                    <p class="ke-settings-desc"><?php esc_html_e( 'Defaults and policies for the promoter commission system.', 'kiwi-events' ); ?></p>
                </div>
            </div>

            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ke_save_promoter_settings">
                <?php wp_nonce_field( 'ke_save_promoter_settings' ); ?>

                <!-- System sub-section -->
                <h3 class="ke-settings-subhead"><?php esc_html_e( 'System', 'kiwi-events' ); ?></h3>
                <div class="ke-form-group" style="background:var(--kiwi-legacy-page-bg); border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:8px; padding:12px 14px; margin-bottom:14px;">
                    <label class="ke-form-label" style="display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" checked disabled>
                        <strong><?php esc_html_e( 'Require user account for promoters', 'kiwi-events' ); ?></strong>
                    </label>
                    <p class="ke-form-hint" style="margin-top:6px;">
                        <?php esc_html_e( 'Promoters sign in to the portal with their WordPress account. Every promoter must be linked to an existing WP user.', 'kiwi-events' ); ?>
                    </p>
                    <p class="ke-form-hint" style="margin-top:4px;">
                        <?php
                        printf(
                            /* translators: %s = link to WP Settings → General */
                            wp_kses(
                                __( 'To let promoters self-register, enable <strong>Anyone can register</strong> in <a href="%s">Settings → General</a>.', 'kiwi-events' ),
                                array( 'a' => array( 'href' => array() ), 'strong' => array() )
                            ),
                            esc_url( admin_url( 'options-general.php' ) )
                        );
                        ?>
                    </p>
                </div>

                <!-- Behavior sub-section -->
                <h3 class="ke-settings-subhead"><?php esc_html_e( 'Behavior', 'kiwi-events' ); ?></h3>
                <div class="ke-settings-grid">
                    <div class="ke-form-group">
                        <label class="ke-form-label">
                            <input type="checkbox" name="prevent_self" value="1" <?php checked( $prevent_self ); ?>>
                            <?php esc_html_e( 'Prevent self-attribution', 'kiwi-events' ); ?>
                        </label>
                        <p class="ke-form-hint"><?php esc_html_e( 'Block a promoter from earning commission on a sale where the buyer email matches their own.', 'kiwi-events' ); ?></p>
                    </div>

                    <div class="ke-form-group">
                        <label class="ke-form-label">
                            <input type="checkbox" name="notify_on_earn" value="1" <?php checked( $notify_on_earn ); ?>>
                            <?php esc_html_e( 'Email promoters when they earn a commission', 'kiwi-events' ); ?>
                        </label>
                        <p class="ke-form-hint"><?php esc_html_e( 'One summary email is sent per attributed order. Disable to go silent.', 'kiwi-events' ); ?></p>
                    </div>
                </div>

                <div class="ke-settings-grid">
                    <div class="ke-form-group">
                        <label class="ke-form-label" for="ke-promo-refund-policy"><?php esc_html_e( 'Refund policy', 'kiwi-events' ); ?></label>
                        <select id="ke-promo-refund-policy" name="refund_policy" class="ke-input">
                            <option value="keep" <?php selected( $refund_policy, 'keep' ); ?>><?php esc_html_e( 'Keep the commission (refunded — kept)', 'kiwi-events' ); ?></option>
                            <option value="void" <?php selected( $refund_policy, 'void' ); ?>><?php esc_html_e( 'Void the commission (refunded — voided)', 'kiwi-events' ); ?></option>
                        </select>
                        <p class="ke-form-hint"><?php esc_html_e( 'What happens to commissions when a WooCommerce order is refunded. Already-paid commissions are never auto-changed.', 'kiwi-events' ); ?></p>
                    </div>
                </div>

                <!-- Commissions sub-section -->
                <h3 class="ke-settings-subhead"><?php esc_html_e( 'Commissions', 'kiwi-events' ); ?></h3>
                <div class="ke-settings-grid">
                    <div class="ke-form-group">
                        <label class="ke-form-label" for="ke-promo-currency"><?php esc_html_e( 'Currency symbol', 'kiwi-events' ); ?></label>
                        <input type="text"
                               id="ke-promo-currency"
                               name="currency_label"
                               class="ke-input"
                               maxlength="4"
                               value="<?php echo esc_attr( $currency_label ); ?>">
                        <p class="ke-form-hint"><?php esc_html_e( 'Shown in admin and the promoter portal next to commission amounts.', 'kiwi-events' ); ?></p>
                    </div>

                    <div class="ke-form-group">
                        <label class="ke-form-label" for="ke-promo-def-type"><?php esc_html_e( 'Default commission type', 'kiwi-events' ); ?></label>
                        <select id="ke-promo-def-type" name="default_commission_type" class="ke-input">
                            <option value="percentage" <?php selected( $def_comm_type, 'percentage' ); ?>><?php esc_html_e( 'Percentage of ticket price', 'kiwi-events' ); ?></option>
                            <option value="fixed"      <?php selected( $def_comm_type, 'fixed' ); ?>><?php esc_html_e( 'Fixed amount per ticket', 'kiwi-events' ); ?></option>
                        </select>
                        <p class="ke-form-hint"><?php esc_html_e( 'Used when assigning a promoter to an event if no specific override is provided.', 'kiwi-events' ); ?></p>
                    </div>

                    <div class="ke-form-group">
                        <label class="ke-form-label" for="ke-promo-def-value"><?php esc_html_e( 'Default commission value', 'kiwi-events' ); ?></label>
                        <input type="number"
                               step="0.01"
                               min="0"
                               id="ke-promo-def-value"
                               name="default_commission_value"
                               class="ke-input"
                               value="<?php echo esc_attr( number_format( $def_comm_value, 2, '.', '' ) ); ?>">
                        <p class="ke-form-hint"><?php esc_html_e( 'A number — 10 means 10% (or $10 per ticket if Fixed is selected).', 'kiwi-events' ); ?></p>
                    </div>
                </div>

                <!-- Communication sub-section -->
                <h3 class="ke-settings-subhead"><?php esc_html_e( 'Communication', 'kiwi-events' ); ?></h3>
                <div class="ke-form-group" style="margin-top:6px;">
                    <label class="ke-form-label" for="ke_promoter_global_terms">
                        <?php esc_html_e( 'Global terms (shown in every assignment email)', 'kiwi-events' ); ?>
                    </label>
                    <?php
                    wp_editor( $global_terms, 'ke_promoter_global_terms', array(
                        'textarea_name' => 'global_terms',
                        'textarea_rows' => 8,
                        'media_buttons' => false,
                        'teeny'         => true,
                        'quicktags'     => true,
                    ) );
                    ?>
                    <p class="ke-form-hint">
                        <?php esc_html_e( 'Rich text. Supports placeholders:', 'kiwi-events' ); ?>
                        <code>{commission_rate}</code>, <code>{event_name}</code>, <code>{organizer_name}</code>
                    </p>
                </div>

                <div style="display:flex; justify-content:flex-end; padding-top:8px;">
                    <button type="submit" class="ke-btn ke-btn-primary"><?php esc_html_e( 'Save Promoter Settings', 'kiwi-events' ); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- ─────────────────────────────────────────────────────────────
         TAB 7 — CATEGORIES
         ───────────────────────────────────────────────────────────── -->
    <div class="ke-settings-content"
         id="ke-tab-content-categories"
         data-content-id="categories"
         role="tabpanel"
         aria-labelledby="ke-tab-categories">

        <div class="ke-settings-placeholder">
            <div class="ke-settings-placeholder-title">
                <span class="ke-settings-placeholder-icon">🚧</span>
                <?php esc_html_e( 'Categories', 'kiwi-events' ); ?>
            </div>
            <div class="ke-settings-placeholder-body">
                <?php esc_html_e( 'Coming soon — manage event categories, shortcodes per category, default category for new events. For now, categories can be managed via Posts → Categories of WordPress (if applicable to your setup).', 'kiwi-events' ); ?>
            </div>
        </div>
    </div>

    <!-- ─────────────────────────────────────────────────────────────
         TAB 8 — ADVANCED
         ───────────────────────────────────────────────────────────── -->
    <div class="ke-settings-content"
         id="ke-tab-content-advanced"
         data-content-id="advanced"
         role="tabpanel"
         aria-labelledby="ke-tab-advanced">

        <!-- Placeholder: Debug -->
        <div class="ke-settings-placeholder">
            <div class="ke-settings-placeholder-title">
                <span class="ke-settings-placeholder-icon">🚧</span>
                <?php esc_html_e( 'Debug', 'kiwi-events' ); ?>
            </div>
            <div class="ke-settings-placeholder-body">
                <?php esc_html_e( 'Coming soon — enable debug logging, retention settings, test mode toggle.', 'kiwi-events' ); ?>
            </div>
        </div>

        <!-- Placeholder: Cache -->
        <div class="ke-settings-placeholder">
            <div class="ke-settings-placeholder-title">
                <span class="ke-settings-placeholder-icon">🚧</span>
                <?php esc_html_e( 'Cache', 'kiwi-events' ); ?>
            </div>
            <div class="ke-settings-placeholder-body">
                <?php esc_html_e( 'Coming soon — cache TTL for event pages, organizer dashboards, manual cache flush.', 'kiwi-events' ); ?>
            </div>
        </div>

        <!-- Placeholder: Performance -->
        <div class="ke-settings-placeholder">
            <div class="ke-settings-placeholder-title">
                <span class="ke-settings-placeholder-icon">🚧</span>
                <?php esc_html_e( 'Performance', 'kiwi-events' ); ?>
            </div>
            <div class="ke-settings-placeholder-body">
                <?php esc_html_e( 'Coming soon — lazy load images, CDN configuration.', 'kiwi-events' ); ?>
            </div>
        </div>

        <!-- Placeholder: API & Webhooks -->
        <div class="ke-settings-placeholder">
            <div class="ke-settings-placeholder-title">
                <span class="ke-settings-placeholder-icon">🚧</span>
                <?php esc_html_e( 'API & Webhooks', 'kiwi-events' ); ?>
            </div>
            <div class="ke-settings-placeholder-body">
                <?php esc_html_e( 'Coming soon — REST API toggle, API keys, webhook URLs.', 'kiwi-events' ); ?>
            </div>
        </div>
    </div>

</div>

<style>
/* ── Settings tab navigation ────────────────────────────────────────
   Inline because this UI only exists on the Settings page; no need to
   bloat the global admin stylesheet. Tokens fall back to literal hex
   so the tabs render correctly even if ke-admin-tokens.css fails to
   load (e.g. plugin update mid-page-load). */
.ke-settings-tabs {
    display: flex;
    gap: 4px;
    padding: 4px;
    background: var(--kiwi-surface-muted, #f3f3f3);
    border-radius: 16px;
    margin-bottom: 32px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.ke-settings-tab {
    flex: 1;
    min-width: max-content;
    padding: 10px 16px;
    border-radius: 12px;
    border: none;
    background: transparent;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    color: var(--kiwi-text, #1d1d1f);
    display: flex;
    align-items: center;
    gap: 8px;
    transition: background 0.15s ease, color 0.15s ease;
    white-space: nowrap;
}
.ke-settings-tab:hover:not(.is-active) {
    background: var(--kiwi-shadow-2);
}
.ke-settings-tab.is-active {
    background: var(--kiwi-green, #bbdb23);
    color: var(--kiwi-text-darker);
    font-weight: 600;
}
.ke-settings-tab:focus-visible {
    outline: 2px solid var(--kiwi-green, #bbdb23);
    outline-offset: 2px;
}
.ke-settings-tab-icon { font-size: 16px; line-height: 1; }

/* ── Tab content panels ──
   display:none by default; .is-active wins. Set on exactly one panel
   at any time by the tab-switching JS below. */
.ke-settings-content { display: none; }
.ke-settings-content.is-active { display: block; }

/* ── Coming-soon placeholder card ── */
.ke-settings-placeholder {
    background: var(--kiwi-surface, #ffffff);
    border: 1px dashed var(--kiwi-border-strong, rgba(0, 0, 0, 0.15));
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 16px;
    opacity: 0.7;
}
.ke-settings-placeholder-title {
    font-weight: 600;
    color: var(--kiwi-text-muted, #6e6e73);
    margin-bottom: 8px;
    font-size: 15px;
}
.ke-settings-placeholder-body {
    color: var(--kiwi-text-muted, #6e6e73);
    font-size: 14px;
    line-height: 1.5;
}
.ke-settings-placeholder-icon { margin-right: 8px; }

/* ── Promoter sub-section headings ──
   Used inside the Promoters tab to break the long form into logical
   groups (System / Behavior / Commissions / Communication) without
   spawning new section cards (which would each need their own Save). */
.ke-settings-subhead {
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--kiwi-text-muted, #6e6e73);
    margin: 22px 0 10px;
    padding-bottom: 6px;
    border-bottom: 1px solid var(--kiwi-border-hairline, rgba(0, 0, 0, 0.06));
}
.ke-settings-subhead:first-child { margin-top: 4px; }

/* Mobile: collapse labels except for the active tab so the bar fits
   without a horizontal scrollbar on the most-common phone widths. */
@media (max-width: 767px) {
    .ke-settings-tab { padding: 10px 12px; }
    .ke-settings-tab-label { display: none; }
    .ke-settings-tab.is-active .ke-settings-tab-label { display: inline; }
}
</style>

<script>
(function(){
    var tabs     = document.querySelectorAll( '.ke-settings-tab' );
    var contents = document.querySelectorAll( '.ke-settings-content' );
    if ( ! tabs.length ) return;

    function activate( tabId ) {
        var found = false;
        tabs.forEach( function( t ) {
            var match = t.getAttribute( 'data-tab-id' ) === tabId;
            t.classList.toggle( 'is-active', match );
            t.setAttribute( 'aria-selected', match ? 'true' : 'false' );
            if ( match ) found = true;
        } );
        contents.forEach( function( c ) {
            c.classList.toggle( 'is-active', c.getAttribute( 'data-content-id' ) === tabId );
        } );
        // TinyMCE (teeny editor in the Promoters tab) computes its iframe
        // size at init time. When its parent is display:none, that size is
        // 0 and the editor renders as a sliver. Dispatching `resize` after
        // the tab becomes visible nudges TinyMCE to recompute. Cheap no-op
        // for tabs without an editor.
        if ( window.dispatchEvent ) {
            window.dispatchEvent( new Event( 'resize' ) );
        }
        return found;
    }

    // Pick initial tab from URL hash; fall back to 'general' if missing
    // or pointing at an unknown id (so a stale bookmark doesn't show a
    // blank page).
    var initial = ( window.location.hash || '' ).replace( '#', '' );
    if ( ! initial || ! activate( initial ) ) {
        activate( 'general' );
    }

    tabs.forEach( function( tab ) {
        tab.addEventListener( 'click', function( e ) {
            e.preventDefault();
            var id = tab.getAttribute( 'data-tab-id' );
            if ( ! id ) return;
            // replaceState (not pushState) — switching tabs shouldn't pollute
            // browser history. Users hitting Back expect to leave Settings
            // entirely, not undo a tab click.
            if ( window.history && window.history.replaceState ) {
                window.history.replaceState( null, '', '#' + id );
            } else {
                window.location.hash = '#' + id;
            }
            activate( id );
        } );
    } );

    // Support browser back/forward when the user manually edits the hash
    // or uses an external #-linked button.
    window.addEventListener( 'hashchange', function() {
        var id = ( window.location.hash || '' ).replace( '#', '' );
        if ( id ) activate( id );
    } );
})();
</script>
