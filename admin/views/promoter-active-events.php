<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Active events drawer for one promoter.
 *
 * Variables provided by KE_Admin_Promoters::render_active_events():
 *   $promoter — ke_promoters row
 *   $links    — array of objects { event_id, title, permalink, tracking_url,
 *                                  commission_type, commission_value,
 *                                  date_start, date_end }
 */

$base_url = admin_url( 'admin.php?page=ke-promoters' );
$currency = (string) get_option( 'ke_promoter_currency_label', '$' );

// Build a single block formatted for WhatsApp/email paste:
//   {Event name} — {date}
//   {tracking url}
//   (blank line)
$bulk_lines = array();
foreach ( $links as $L ) {
    $when = $L->date_start ? date_i18n( 'M j, Y', strtotime( $L->date_start ) ) : '';
    $bulk_lines[] = trim( $L->title . ( $when ? " — {$when}" : '' ) );
    $bulk_lines[] = $L->tracking_url;
    $bulk_lines[] = '';
}
$bulk_text = trim( implode( "\n", $bulk_lines ) );

$who = KE_Promoter_Attribution::display_for( $promoter );
$promoter_name = $who['name'] !== '' ? $who['name'] : (string) $promoter->slug;
?>
<div class="wrap ke-builder-wrap">

    <div class="ke-builder-header">
        <div class="ke-builder-title">
            <h1>Active events · <?php echo esc_html( $promoter_name ); ?></h1>
            <p style="margin:4px 0 0; color:var(--kiwi-legacy-text-muted); font-size:13px;">
                Upcoming events this promoter is assigned to, with their per-event tracking links.
            </p>
        </div>
        <div class="ke-builder-actions">
            <a href="<?php echo esc_url( $base_url ); ?>" class="ke-btn ke-btn-ghost">← All promoters</a>
            <a href="<?php echo esc_url( $base_url . '&action=edit&id=' . (int) $promoter->id ); ?>" class="ke-btn ke-btn-ghost">Edit promoter</a>
        </div>
    </div>

    <?php if ( empty( $links ) ) : ?>
        <div class="ke-card" style="padding:48px 24px; text-align:center; color:var(--kiwi-legacy-text-muted);">
            <p style="margin:0 0 8px; font-size:14px;">
                <?php echo esc_html( $promoter_name ); ?> isn't assigned to any upcoming events.
            </p>
            <p style="margin:0; font-size:12px;">
                Assign them from an event's Promoters tab or via a list bulk-assign.
            </p>
        </div>
    <?php else : ?>

        <!-- Copy-all-links card -->
        <div class="ke-card" style="padding:16px 18px; margin-bottom:18px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
                <div>
                    <h2 style="margin:0 0 4px; font-size:15px;">Copy all links (WhatsApp-ready)</h2>
                    <p style="margin:0; font-size:12px; color:var(--kiwi-legacy-text-muted);">
                        Paste straight into WhatsApp, SMS or email. Each event includes its name, date, and tracking link.
                    </p>
                </div>
                <button type="button" id="ke-promoter-copy-all" class="ke-btn ke-btn-primary" style="padding:8px 16px;">
                    📋 Copy all <?php echo count( $links ); ?> link<?php echo count( $links ) === 1 ? '' : 's'; ?>
                </button>
            </div>
            <textarea id="ke-promoter-bulk-text"
                      readonly
                      rows="<?php echo (int) min( 12, max( 4, count( $links ) * 3 ) ); ?>"
                      style="margin-top:12px; width:100%; padding:10px 12px; border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:8px; font-family:ui-monospace, SFMono-Regular, Menlo, monospace; font-size:12px; background:var(--kiwi-legacy-page-bg); resize:vertical;"><?php
                echo esc_textarea( $bulk_text );
            ?></textarea>
        </div>

        <!-- Per-event cards -->
        <div style="display:grid; gap:12px;">
            <?php foreach ( $links as $i => $L ) :
                $rate_label = $L->commission_type === 'fixed'
                    ? $currency . number_format( $L->commission_value, 2 ) . ' / ticket'
                    : number_format( $L->commission_value, 2 ) . '% of price';
                $when = $L->date_start ? date_i18n( 'M j, Y', strtotime( $L->date_start ) ) : '';
            ?>
                <div class="ke-card" style="padding:14px 16px;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap;">
                        <div style="min-width:0; flex:1 1 240px;">
                            <a href="<?php echo esc_url( get_edit_post_link( $L->event_id ) ); ?>"
                               style="font-weight:600; color:var(--kiwi-legacy-text-darkest); text-decoration:none; font-size:14px;">
                                🎟️ <?php echo esc_html( $L->title ); ?>
                            </a>
                            <div style="margin-top:3px; font-size:12px; color:var(--kiwi-legacy-text-muted);">
                                <?php if ( $when ) : ?>
                                    <?php echo esc_html( $when ); ?> ·
                                <?php endif; ?>
                                <span style="color:var(--kiwi-legacy-teal-700); font-weight:600;"><?php echo esc_html( $rate_label ); ?></span>
                            </div>
                        </div>
                        <a href="<?php echo esc_url( $L->permalink ); ?>" target="_blank" rel="noopener"
                           class="ke-btn ke-btn-ghost" style="font-size:12px; padding:5px 10px;">View event ↗</a>
                    </div>

                    <div style="margin-top:10px; display:flex; gap:8px; align-items:center;">
                        <input type="text"
                               readonly
                               value="<?php echo esc_attr( $L->tracking_url ); ?>"
                               class="ke-promoter-link-input"
                               style="flex:1; padding:7px 10px; border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:6px; font-size:12px; font-family:ui-monospace, SFMono-Regular, Menlo, monospace; background:white;">
                        <button type="button"
                                class="ke-btn ke-btn-ghost ke-promoter-link-copy"
                                data-index="<?php echo (int) $i; ?>"
                                style="padding:7px 12px; font-size:12px; white-space:nowrap;">
                            Copy
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</div>

<script>
(function () {
    function copyText( text, btn ) {
        var done = function () {
            var original = btn.textContent;
            btn.textContent = 'Copied!';
            setTimeout(function () { btn.textContent = original; }, 1400);
        };
        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            navigator.clipboard.writeText( text ).then( done, function () {
                var ta = document.createElement('textarea');
                ta.value = text; document.body.appendChild( ta );
                ta.select(); document.execCommand('copy');
                document.body.removeChild( ta ); done();
            } );
        } else {
            var ta = document.createElement('textarea');
            ta.value = text; document.body.appendChild( ta );
            ta.select(); document.execCommand('copy');
            document.body.removeChild( ta ); done();
        }
    }

    document.querySelectorAll('.ke-promoter-link-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.previousElementSibling;
            if ( row && row.value ) { copyText( row.value, btn ); }
        });
    });

    var allBtn = document.getElementById('ke-promoter-copy-all');
    var bulk   = document.getElementById('ke-promoter-bulk-text');
    if ( allBtn && bulk ) {
        allBtn.addEventListener('click', function () { copyText( bulk.value, allBtn ); });
    }
})();
</script>
