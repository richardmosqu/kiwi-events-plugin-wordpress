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

</div>
