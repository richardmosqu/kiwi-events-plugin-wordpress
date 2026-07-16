<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Promoter CSV import view.
 *
 * Variables provided by KE_Admin_Promoters::render_import():
 *   $flash  : array|null
 *   $report : array|null  ([created, skipped, errors[]] when an import just ran)
 */

$base_url = admin_url( 'admin.php?page=ke-promoters' );
?>
<div class="wrap ke-builder-wrap">

    <?php if ( $flash ) : ?>
        <div class="notice notice-<?php echo esc_attr( $flash['type'] === 'success' ? 'success' : 'error' ); ?> is-dismissible">
            <p><?php echo esc_html( $flash['message'] ); ?></p>
        </div>
    <?php endif; ?>

    <div class="ke-builder-header">
        <div class="ke-builder-title">
            <h1>Import Promoters</h1>
            <p style="margin:4px 0 0; color:var(--kiwi-legacy-text-muted); font-size:13px;">
                Bulk-create promoter accounts from a CSV file.
            </p>
        </div>
        <div class="ke-builder-actions">
            <a href="<?php echo esc_url( $base_url ); ?>" class="ke-btn ke-btn-ghost">← Back to all promoters</a>
        </div>
    </div>

    <form method="POST"
          action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
          enctype="multipart/form-data"
          class="ke-card" style="padding:24px; max-width:720px;">
        <input type="hidden" name="action" value="ke_import_promoters_csv">
        <?php wp_nonce_field( 'ke_import_promoters_csv' ); ?>

        <h2 style="margin:0 0 8px; font-size:16px;">Upload CSV</h2>
        <p class="ke-form-hint" style="margin:0 0 12px;">
            File must include columns <code>name</code> and <code>email</code>.
            Optional columns: <code>slug</code>, <code>phone</code>, <code>status</code>
            (active / pending / inactive — defaults to pending).
        </p>

        <input type="file" name="csv_file" accept=".csv,text/csv" required
               style="margin-bottom:16px; display:block;">

        <details style="margin:8px 0 14px;">
            <summary style="cursor:pointer; font-size:13px; color:var(--kiwi-legacy-text-mid);">See an example row</summary>
            <pre style="background:var(--kiwi-legacy-page-bg); padding:10px 12px; border-radius:8px; font-size:12px; margin:8px 0 0; overflow:auto;">name,email,slug,phone,status
Jane Doe,jane@example.com,jane-doe,+1-555-0100,active
Mike Promoter,mike@example.com,,,</pre>
        </details>

        <button type="submit" class="ke-btn ke-btn-primary">Run import</button>
        <a href="<?php echo esc_url( $base_url ); ?>" class="ke-btn ke-btn-ghost" style="margin-left:8px;">Cancel</a>
    </form>

    <?php if ( $report && is_array( $report ) ) : ?>
        <div class="ke-card" style="padding:20px; margin-top:20px; max-width:720px;">
            <h2 style="margin:0 0 10px; font-size:16px;">Last import result</h2>
            <div style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
                <div style="flex:1; min-width:120px; padding:10px 14px; background:var(--kiwi-legacy-green-pill-bg); border-radius:8px;">
                    <div style="font-size:11px; color:var(--kiwi-legacy-emerald-800); font-weight:600; text-transform:uppercase;">Created</div>
                    <div style="font-size:22px; font-weight:700; color:var(--kiwi-legacy-emerald-800);"><?php echo (int) $report['created']; ?></div>
                </div>
                <div style="flex:1; min-width:120px; padding:10px 14px; background:var(--kiwi-legacy-row-bg); border-radius:8px;">
                    <div style="font-size:11px; color:var(--kiwi-legacy-text-mid); font-weight:600; text-transform:uppercase;">Skipped (dupes)</div>
                    <div style="font-size:22px; font-weight:700; color:var(--kiwi-legacy-text-mid);"><?php echo (int) $report['skipped']; ?></div>
                </div>
                <div style="flex:1; min-width:120px; padding:10px 14px; background:<?php echo empty( $report['errors'] ) ? 'var(--kiwi-legacy-row-bg)' : 'var(--kiwi-legacy-red-50)'; ?>; border-radius:8px;">
                    <div style="font-size:11px; color:var(--kiwi-legacy-red-800); font-weight:600; text-transform:uppercase;">Errors</div>
                    <div style="font-size:22px; font-weight:700; color:<?php echo empty( $report['errors'] ) ? 'var(--kiwi-legacy-text-mid)' : 'var(--kiwi-legacy-red-800)'; ?>;"><?php echo (int) count( $report['errors'] ); ?></div>
                </div>
            </div>

            <?php if ( ! empty( $report['errors'] ) ) : ?>
                <details open>
                    <summary style="cursor:pointer; font-size:13px; color:var(--kiwi-legacy-text-mid); margin-bottom:8px;">View errors</summary>
                    <table style="width:100%; border-collapse:collapse; font-size:13px;">
                        <thead>
                            <tr style="background:var(--kiwi-legacy-page-bg); text-align:left;">
                                <th style="padding:8px 12px; color:var(--kiwi-legacy-text-mid); width:80px;">Row</th>
                                <th style="padding:8px 12px; color:var(--kiwi-legacy-text-mid);">Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $report['errors'] as $err ) :
                                $row_n = isset( $err[0] ) ? (int) $err[0] : 0;
                                $msg   = isset( $err[1] ) ? (string) $err[1] : '';
                            ?>
                                <tr style="border-top:1px solid var(--kiwi-legacy-row-bg-alt);">
                                    <td style="padding:8px 12px; color:var(--kiwi-legacy-text-darkest); font-variant-numeric:tabular-nums;"><?php echo $row_n; ?></td>
                                    <td style="padding:8px 12px; color:var(--kiwi-legacy-text-mid);"><?php echo esc_html( $msg ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
