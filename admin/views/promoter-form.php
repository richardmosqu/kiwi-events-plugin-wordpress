<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Promoter create/edit form.
 *
 * Promoters are linked WP users (1:1). The form picks a WP user via the
 * type-ahead REST endpoint ke/v1/users/search and stores user_id on the row.
 * Display name / email are pulled live from wp_users.
 *
 * Variables provided by KE_Admin_Promoters::render_form():
 *   $row    — promoter row (object) or null when creating
 *   $is_new — bool
 *   $flash  — [ 'type'=>'success|error', 'message'=>'...' ] | null
 */

$id      = $row ? (int) $row->id : 0;
$user_id = $row ? (int) $row->user_id : 0;
$slug    = $row ? (string) $row->slug   : '';
$phone   = $row ? (string) $row->phone  : '';
$status  = $row ? (string) $row->status : 'pending';

$linked_user = $user_id ? get_userdata( $user_id ) : null;
$name        = $linked_user ? ( $linked_user->display_name ?: $linked_user->user_login ) : '';
$email       = $linked_user ? (string) $linked_user->user_email : '';
$avatar_url  = $linked_user ? get_avatar_url( $user_id, array( 'size' => 56 ) ) : '';

$list_url     = admin_url( 'admin.php?page=ke-promoters' );
$portal_url_p = home_url( '/promoter/' . rawurlencode( $slug ?: '{slug}' ) . '/' );

$search_endpoint = esc_url_raw( rest_url( 'ke/v1/users/search' ) );
$rest_nonce      = wp_create_nonce( 'wp_rest' );
?>
<div class="wrap ke-builder-wrap">

    <?php if ( $flash ) : ?>
        <div class="notice notice-<?php echo esc_attr( $flash['type'] === 'success' ? 'success' : 'error' ); ?> is-dismissible">
            <p><?php echo esc_html( $flash['message'] ); ?></p>
        </div>
    <?php endif; ?>

    <!-- ── Header ── -->
    <div class="ke-builder-header">
        <div class="ke-builder-title">
            <h1><?php echo $is_new ? 'New Promoter' : 'Edit Promoter'; ?></h1>
            <p style="margin:4px 0 0; color:var(--kiwi-legacy-text-muted); font-size:13px;">
                <?php echo $is_new
                    ? 'Pick a WordPress user — they\'ll earn a commission on sales attributed to their tracking link.'
                    : 'Update promoter details. Slug changes won\'t affect previously-recorded commissions.'; ?>
            </p>
        </div>
        <div class="ke-builder-actions">
            <a href="<?php echo esc_url( $list_url ); ?>" class="ke-btn ke-btn-ghost">← Back to list</a>
        </div>
    </div>

    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ke-card" style="padding:24px; max-width:720px;">
        <input type="hidden" name="action" value="ke_save_promoter">
        <input type="hidden" name="id"     value="<?php echo (int) $id; ?>">
        <input type="hidden" name="user_id" id="ke-promoter-user-id" value="<?php echo (int) $user_id; ?>">
        <?php wp_nonce_field( 'ke_save_promoter_nonce' ); ?>

        <!-- WP user picker -->
        <div class="ke-field" id="ke-user-picker-block" style="margin-bottom:20px;">
            <label style="display:block; font-weight:600; font-size:13px; margin-bottom:6px; color:var(--kiwi-legacy-text-strong);">
                WordPress user <span style="color:var(--kiwi-legacy-red-500);">*</span>
            </label>

            <!-- Confirmation card (visible when a user is linked) -->
            <div id="ke-user-confirmation" style="<?php echo $linked_user ? '' : 'display:none;'; ?> background:var(--kiwi-legacy-page-bg); border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:10px; padding:12px; display:flex; gap:12px; align-items:center;">
                <img id="ke-user-avatar" src="<?php echo esc_attr( $avatar_url ); ?>" alt="" style="width:48px; height:48px; border-radius:50%; flex-shrink:0;">
                <div style="flex:1; min-width:0;">
                    <div id="ke-user-name" style="font-weight:600; font-size:14px; color:var(--kiwi-legacy-text-darkest); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo esc_html( $name ?: '(unlinked)' ); ?></div>
                    <div id="ke-user-email" style="color:var(--kiwi-legacy-text-muted); font-size:12px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo esc_html( $email ); ?></div>
                </div>
                <button type="button" id="ke-user-change" class="ke-btn ke-btn-ghost" style="padding:6px 12px; font-size:12px; white-space:nowrap;">Change user</button>
            </div>

            <!-- Search input (visible when no user is selected) -->
            <div id="ke-user-search-wrap" style="<?php echo $linked_user ? 'display:none;' : ''; ?> position:relative;">
                <input type="text"
                       id="ke-user-search"
                       autocomplete="off"
                       placeholder="Find user by email, name, or username"
                       style="width:100%; padding:9px 12px; border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:8px; font-size:14px;">
                <div id="ke-user-results" style="display:none; position:absolute; left:0; right:0; top:100%; margin-top:4px; background:var(--kiwi-surface); border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:8px; box-shadow:0 4px 12px var(--kiwi-shadow-5); max-height:280px; overflow-y:auto; z-index:50;"></div>
                <p id="ke-user-help" style="margin:6px 0 0; font-size:12px; color:var(--kiwi-legacy-text-muted);">
                    The user must already have a WordPress account on this site.
                </p>
                <p id="ke-user-noresult" style="display:none; margin:8px 0 0; padding:10px 12px; background:var(--kiwi-legacy-amber-50); border:1px solid var(--kiwi-legacy-amber-500); border-radius:8px; font-size:12px; color:var(--kiwi-legacy-amber-900);">
                    No user found. They must <a href="<?php echo esc_url( wp_registration_url() ); ?>" target="_blank" rel="noopener">register on the site</a> first, then come back here.
                </p>
            </div>
        </div>

        <div class="ke-field" style="margin-bottom:16px;">
            <label style="display:block; font-weight:600; font-size:13px; margin-bottom:6px; color:var(--kiwi-legacy-text-strong);">
                Slug
            </label>
            <input type="text"
                   id="ke-promoter-slug"
                   name="slug"
                   value="<?php echo esc_attr( $slug ); ?>"
                   placeholder="auto-generated from user's display name"
                   style="width:100%; padding:9px 12px; border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:8px; font-size:14px; font-family:ui-monospace, SFMono-Regular, Menlo, monospace;">
            <p style="margin:6px 0 0; font-size:12px; color:var(--kiwi-legacy-text-muted);">
                URL-safe identifier used in tracking links and the portal URL. Auto-generates if blank.
            </p>
        </div>

        <!-- Portal URL preview -->
        <div style="margin-bottom:20px; padding:12px 14px; background:var(--kiwi-legacy-page-bg); border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:8px;">
            <div style="font-size:12px; font-weight:600; color:var(--kiwi-legacy-text-mid); margin-bottom:6px;">Portal URL</div>
            <div style="display:flex; gap:8px; align-items:center;">
                <input type="text"
                       id="ke-promoter-portal-url"
                       value="<?php echo esc_attr( $portal_url_p ); ?>"
                       readonly
                       style="flex:1; padding:7px 10px; border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:6px; font-size:12px; font-family:ui-monospace, SFMono-Regular, Menlo, monospace; background:var(--kiwi-surface);">
                <button type="button" id="ke-promoter-copy-portal-url" class="ke-btn ke-btn-ghost" style="padding:7px 12px; font-size:12px; white-space:nowrap;">
                    Copy
                </button>
                <button type="button" id="ke-promoter-toggle-qr" class="ke-btn ke-btn-ghost" style="padding:7px 12px; font-size:12px; white-space:nowrap;" aria-expanded="false" aria-controls="ke-promoter-qr-panel">
                    Show QR
                </button>
            </div>
            <p style="margin:8px 0 0; font-size:11px; color:var(--kiwi-legacy-text-muted);">
                The promoter signs in here (with their WordPress account) to view sales, copy tracking links, and download QR codes.
            </p>
            <div id="ke-promoter-qr-panel" hidden style="margin-top:12px; padding:12px; background:var(--kiwi-surface); border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:8px; text-align:center;">
                <img id="ke-promoter-qr-img" alt="Portal URL QR code" style="width:200px; height:200px; display:inline-block;">
                <div style="margin-top:8px;">
                    <button type="button" id="ke-promoter-download-qr" class="ke-btn ke-btn-ghost" style="padding:7px 12px; font-size:12px;">
                        Download PNG
                    </button>
                </div>
            </div>
        </div>

        <div class="ke-field" style="margin-bottom:16px;">
            <label style="display:block; font-weight:600; font-size:13px; margin-bottom:6px; color:var(--kiwi-legacy-text-strong);">
                Phone
            </label>
            <input type="text"
                   name="phone"
                   value="<?php echo esc_attr( $phone ); ?>"
                   placeholder="Optional"
                   style="width:100%; padding:9px 12px; border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:8px; font-size:14px;">
        </div>

        <div class="ke-field" style="margin-bottom:24px;">
            <label style="display:block; font-weight:600; font-size:13px; margin-bottom:6px; color:var(--kiwi-legacy-text-strong);">
                Status
            </label>
            <select name="status" style="width:100%; padding:9px 12px; border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:8px; font-size:14px; background:var(--kiwi-surface);">
                <option value="active"   <?php selected( $status, 'active' ); ?>>Active — earns commissions on attributed sales</option>
                <option value="pending"  <?php selected( $status, 'pending' ); ?>>Pending — accepted into the program but not yet active</option>
                <option value="inactive" <?php selected( $status, 'inactive' ); ?>>Inactive — links work but no commissions are written</option>
                <?php if ( $status === 'orphaned' ) : ?>
                    <option value="orphaned" selected>Orphaned — WP user link is missing</option>
                <?php endif; ?>
            </select>
        </div>

        <div style="display:flex; gap:10px; padding-top:16px; border-top:1px solid var(--kiwi-legacy-row-bg-alt); flex-wrap:wrap; align-items:center;">
            <button type="submit" class="ke-btn ke-btn-primary" style="padding:10px 20px;">
                <?php echo $is_new ? 'Create Promoter' : 'Save Changes'; ?>
            </button>
            <a href="<?php echo esc_url( $list_url ); ?>" class="ke-btn ke-btn-ghost" style="padding:10px 20px;">Cancel</a>

            <?php if ( ! $is_new ) :
                $commissions_url  = admin_url( 'admin.php?page=ke-promoters&action=commissions&id=' . (int) $row->id );
                $portal_url       = home_url( '/promoter/' . rawurlencode( $row->slug ) . '/' );
                $preview_dash_url = class_exists( 'KE_Promoter_Admin_Preview' )
                    ? KE_Promoter_Admin_Preview::build_preview_url( (int) $row->id )
                    : '';
                $unlink_url = wp_nonce_url(
                    admin_url( 'admin-post.php?action=ke_unlink_promoter_user&id=' . (int) $row->id ),
                    'ke_unlink_promoter_user_' . (int) $row->id
                );
            ?>
                <span style="flex:1;"></span>
                <a href="<?php echo esc_url( $commissions_url ); ?>"
                   class="ke-btn ke-btn-ghost" style="padding:10px 16px;"
                   title="View this promoter's commissions">
                    💰 Commissions
                </a>
                <a href="<?php echo esc_url( $portal_url ); ?>" target="_blank" rel="noopener"
                   class="ke-btn ke-btn-ghost" style="padding:10px 16px;"
                   title="Open the promoter's portal page">
                    🔗 View portal
                </a>
                <?php if ( $preview_dash_url ) : ?>
                    <a href="<?php echo esc_url( $preview_dash_url ); ?>" target="_blank" rel="noopener"
                       class="ke-btn ke-btn-ghost" style="padding:10px 16px;"
                       title="Open this promoter's dashboard as a read-only admin preview (15 min token)">
                        👁 Preview dashboard
                    </a>
                <?php endif; ?>
                <?php if ( $user_id ) : ?>
                    <a href="<?php echo esc_url( $unlink_url ); ?>"
                       onclick="return confirm('Unlink the WP user from this promoter? The slug + commission history are kept; the row becomes orphaned until re-linked. Advanced.');"
                       class="ke-btn ke-btn-ghost"
                       style="padding:10px 16px; color:var(--kiwi-legacy-red-800);"
                       title="Detach the WP user — advanced">
                        🔓 Unlink user
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </form>

    <script>
    (function () {
        var slugInput      = document.getElementById('ke-promoter-slug');
        var portalInput    = document.getElementById('ke-promoter-portal-url');
        var portalCopyBtn  = document.getElementById('ke-promoter-copy-portal-url');
        var qrToggleBtn    = document.getElementById('ke-promoter-toggle-qr');
        var qrPanel        = document.getElementById('ke-promoter-qr-panel');
        var qrImg          = document.getElementById('ke-promoter-qr-img');
        var qrDownloadBtn  = document.getElementById('ke-promoter-download-qr');

        var userIdInput    = document.getElementById('ke-promoter-user-id');
        var userSearch     = document.getElementById('ke-user-search');
        var userResults    = document.getElementById('ke-user-results');
        var userConfirm    = document.getElementById('ke-user-confirmation');
        var userSearchWrap = document.getElementById('ke-user-search-wrap');
        var userChangeBtn  = document.getElementById('ke-user-change');
        var userAvatar     = document.getElementById('ke-user-avatar');
        var userName       = document.getElementById('ke-user-name');
        var userEmail      = document.getElementById('ke-user-email');
        var userNoResult   = document.getElementById('ke-user-noresult');

        var siteRoot = <?php echo wp_json_encode( home_url( '/' ) ); ?>;
        var endpoint = <?php echo wp_json_encode( $search_endpoint ); ?>;
        var nonce    = <?php echo wp_json_encode( $rest_nonce ); ?>;

        function slugify( s ) {
            return ( s || '' )
                .toString()
                .toLowerCase()
                .replace(/[^a-z0-9\s-]/g, '')
                .trim()
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-');
        }

        function currentSlug() {
            return slugInput.value.trim() || '{slug}';
        }

        function refreshUrl() {
            var s = currentSlug();
            portalInput.value = siteRoot + 'promoter/' + encodeURIComponent( s ) + '/';
            if ( qrPanel && ! qrPanel.hasAttribute('hidden') ) renderQr();
        }

        function renderQr() {
            var size = 240;
            qrImg.src = 'https://api.qrserver.com/v1/create-qr-code/?size=' + size + 'x' + size + '&data=' + encodeURIComponent( portalInput.value );
        }

        function flashCopied( btn, originalLabel ) {
            btn.textContent = 'Copied!';
            setTimeout(function () { btn.textContent = originalLabel; }, 1500);
        }
        function copyToClipboard( input, btn, originalLabel ) {
            input.select();
            if ( navigator.clipboard && navigator.clipboard.writeText ) {
                navigator.clipboard.writeText( input.value ).then(function () {
                    flashCopied( btn, originalLabel );
                }, function () {
                    document.execCommand('copy');
                    flashCopied( btn, originalLabel );
                });
            } else {
                document.execCommand('copy');
                flashCopied( btn, originalLabel );
            }
        }

        slugInput.addEventListener('input', refreshUrl);

        portalCopyBtn.addEventListener('click', function () {
            copyToClipboard( portalInput, portalCopyBtn, 'Copy' );
        });

        qrToggleBtn.addEventListener('click', function () {
            var isOpen = ! qrPanel.hasAttribute('hidden');
            if ( isOpen ) {
                qrPanel.setAttribute('hidden', '');
                qrToggleBtn.setAttribute('aria-expanded', 'false');
                qrToggleBtn.textContent = 'Show QR';
            } else {
                renderQr();
                qrPanel.removeAttribute('hidden');
                qrToggleBtn.setAttribute('aria-expanded', 'true');
                qrToggleBtn.textContent = 'Hide QR';
            }
        });

        qrDownloadBtn.addEventListener('click', function () {
            var src   = qrImg.src;
            var fname = 'promoter-' + ( currentSlug().replace(/[^a-z0-9\-]/gi, '') || 'qr' ) + '-portal.png';
            var original = qrDownloadBtn.textContent;
            qrDownloadBtn.textContent = 'Preparing…';
            fetch( src ).then(function ( res ) {
                if ( ! res.ok ) throw new Error('fetch failed');
                return res.blob();
            }).then(function ( blob ) {
                var href = URL.createObjectURL( blob );
                var a = document.createElement('a');
                a.href = href;
                a.download = fname;
                document.body.appendChild( a );
                a.click();
                a.remove();
                setTimeout(function () { URL.revokeObjectURL( href ); }, 1000);
                qrDownloadBtn.textContent = original;
            }).catch(function () {
                window.open( src, '_blank', 'noopener' );
                qrDownloadBtn.textContent = original;
            });
        });

        // ── User picker ─────────────────────────────────────────────
        var searchTimer = null;
        var slugIsAuto  = slugInput.value.trim() === '';

        function showResults( items ) {
            userResults.innerHTML = '';
            if ( ! items.length ) {
                userResults.style.display = 'none';
                userNoResult.style.display = '';
                return;
            }
            userNoResult.style.display = 'none';
            items.forEach(function ( u ) {
                var row = document.createElement('button');
                row.type = 'button';
                row.style.cssText = 'display:flex; gap:10px; align-items:center; padding:10px 12px; width:100%; background:var(--kiwi-surface); border:0; border-bottom:1px solid var(--kiwi-legacy-row-bg); cursor:pointer; text-align:left;';
                row.innerHTML =
                    '<img src="' + u.avatar_url + '" alt="" style="width:32px; height:32px; border-radius:50%; flex-shrink:0;">' +
                    '<div style="flex:1; min-width:0;">' +
                        '<div style="font-weight:600; font-size:13px; color:var(--kiwi-legacy-text-darkest); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"></div>' +
                        '<div style="font-size:12px; color:var(--kiwi-legacy-text-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"></div>' +
                    '</div>';
                row.children[1].children[0].textContent = u.display_name;
                row.children[1].children[1].textContent = u.email;
                row.addEventListener('mouseenter', function () { row.style.background = 'var(--kiwi-legacy-page-bg)'; });
                row.addEventListener('mouseleave', function () { row.style.background = 'var(--kiwi-surface)'; });
                row.addEventListener('click', function () { selectUser( u ); });
                userResults.appendChild( row );
            });
            userResults.style.display = '';
        }

        function selectUser( u ) {
            userIdInput.value   = u.id;
            userAvatar.src      = u.avatar_url;
            userName.textContent  = u.display_name;
            userEmail.textContent = u.email;
            userConfirm.style.display    = '';
            userSearchWrap.style.display = 'none';
            userResults.style.display    = 'none';
            userNoResult.style.display   = 'none';

            // Auto-fill slug from display name when blank.
            if ( slugIsAuto && slugInput.value.trim() === '' ) {
                slugInput.value = slugify( u.display_name );
                refreshUrl();
            }
        }

        function clearUser() {
            userIdInput.value             = '';
            userConfirm.style.display     = 'none';
            userSearchWrap.style.display  = '';
            userSearch.value              = '';
            userResults.innerHTML         = '';
            userResults.style.display     = 'none';
            userNoResult.style.display    = 'none';
            userSearch.focus();
        }

        userChangeBtn.addEventListener('click', clearUser);

        slugInput.addEventListener('input', function () {
            slugIsAuto = slugInput.value.trim() === '';
        });

        userSearch.addEventListener('input', function () {
            var q = userSearch.value.trim();
            clearTimeout( searchTimer );
            if ( q.length < 2 ) {
                userResults.style.display = 'none';
                userNoResult.style.display = 'none';
                return;
            }
            searchTimer = setTimeout(function () {
                var url = endpoint + ( endpoint.indexOf('?') === -1 ? '?' : '&' ) + 'q=' + encodeURIComponent( q ) + '&exclude_existing_promoters=1';
                fetch( url, { headers: { 'X-WP-Nonce': nonce }, credentials: 'same-origin' } )
                    .then(function ( r ) { return r.ok ? r.json() : []; })
                    .then( showResults )
                    .catch(function () { userResults.style.display = 'none'; });
            }, 220);
        });

        document.addEventListener('click', function ( e ) {
            if ( ! userResults.contains( e.target ) && e.target !== userSearch ) {
                userResults.style.display = 'none';
            }
        });

        refreshUrl();
    })();
    </script>
</div>
