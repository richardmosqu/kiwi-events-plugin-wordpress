<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Promoter Lists view (both index + edit).
 *
 * Variables provided by KE_Admin_Promoters::render_lists():
 *   $sub_action    : 'lists' | 'list_new' | 'list_edit'
 *   $editing       : bool
 *   $is_new        : bool
 *   $list          : object|null
 *   $lists         : array (all lists with member_count)
 *   $members       : array of promoter rows in this list (when editing)
 *   $all_promoters : all active/pending promoters
 *   $flash         : array|null
 */

$base_url = admin_url( 'admin.php?page=ke-promoters' );
?>
<div class="wrap ke-builder-wrap">

    <?php if ( $flash ) : ?>
        <div class="notice notice-<?php echo esc_attr( $flash['type'] === 'success' ? 'success' : 'error' ); ?> is-dismissible">
            <p><?php echo esc_html( $flash['message'] ); ?></p>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="ke-builder-header">
        <div class="ke-builder-title">
            <h1>Promoter Lists</h1>
            <p style="margin:4px 0 0; color:var(--kiwi-legacy-text-muted); font-size:13px;">
                Group promoters so you can assign them to events in bulk.
            </p>
        </div>
        <div class="ke-builder-actions">
            <a href="<?php echo esc_url( $base_url ); ?>" class="ke-btn ke-btn-ghost">All promoters</a>
            <a href="<?php echo esc_url( $base_url . '&action=lists' ); ?>" class="ke-btn ke-btn-ghost">Lists</a>
            <a href="<?php echo esc_url( $base_url . '&action=list_new' ); ?>" class="ke-btn ke-btn-primary">+ New list</a>
        </div>
    </div>

    <?php if ( $editing ) :
        $form_action = $is_new ? '' : 'edit';
        $id_val      = $list ? (int) $list->id : 0;
        $name_val    = $list ? (string) $list->name : '';
        $desc_val    = $list ? (string) $list->description : '';
        $member_ids  = array_map( 'intval', wp_list_pluck( $members, 'id' ) );
    ?>
        <!-- List details -->
        <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              class="ke-card" style="padding:20px; max-width:720px; margin-bottom:20px;">
            <input type="hidden" name="action" value="ke_save_promoter_list">
            <input type="hidden" name="id"     value="<?php echo (int) $id_val; ?>">
            <?php wp_nonce_field( 'ke_save_promoter_list' ); ?>

            <h2 style="margin:0 0 14px; font-size:16px;"><?php echo $is_new ? 'New list' : 'Edit list'; ?></h2>

            <div class="ke-field" style="margin-bottom:14px;">
                <label style="display:block; font-weight:600; font-size:13px; color:var(--kiwi-legacy-text-strong); margin-bottom:6px;">Name *</label>
                <input type="text" name="name" value="<?php echo esc_attr( $name_val ); ?>" required
                       style="width:100%; padding:9px 12px; border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:8px; font-size:14px;">
            </div>

            <div class="ke-field" style="margin-bottom:14px;">
                <label style="display:block; font-weight:600; font-size:13px; color:var(--kiwi-legacy-text-strong); margin-bottom:6px;">Description</label>
                <textarea name="description" rows="2"
                          style="width:100%; padding:9px 12px; border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:8px; font-size:13px;"><?php echo esc_textarea( $desc_val ); ?></textarea>
            </div>

            <button type="submit" class="ke-btn ke-btn-primary"><?php echo $is_new ? 'Create list' : 'Save details'; ?></button>
        </form>

        <?php if ( ! $is_new && $list ) : ?>
            <!-- Members -->
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                  class="ke-card" style="padding:20px;">
                <input type="hidden" name="action"  value="ke_save_promoter_list_members">
                <input type="hidden" name="list_id" value="<?php echo (int) $list->id; ?>">
                <?php wp_nonce_field( 'ke_save_promoter_list_members_' . (int) $list->id ); ?>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
                    <h2 style="margin:0; font-size:16px;">Members (<?php echo count( $member_ids ); ?>)</h2>
                    <?php
                        $del_url = wp_nonce_url(
                            admin_url( 'admin-post.php?action=ke_delete_promoter_list&list_id=' . (int) $list->id ),
                            'ke_delete_promoter_list_' . (int) $list->id
                        );
                    ?>
                    <a href="<?php echo esc_url( $del_url ); ?>"
                       onclick="return confirm('Delete this list? Promoters already assigned to events will keep those assignments.');"
                       class="ke-btn ke-btn-danger" style="font-size:12px; padding:6px 12px;">Delete list</a>
                </div>

                <?php if ( empty( $all_promoters ) ) : ?>
                    <p class="ke-form-hint">No active or pending promoters exist yet.</p>
                <?php else : ?>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:8px; max-height:420px; overflow:auto; padding:8px; border:1px solid var(--kiwi-legacy-row-bg-alt); border-radius:8px;">
                        <?php foreach ( $all_promoters as $p ) :
                            $is_member = in_array( (int) $p->id, $member_ids, true );
                        ?>
                            <label style="display:flex; align-items:center; gap:8px; padding:6px 10px; border-radius:6px; <?php echo $is_member ? 'background:var(--kiwi-legacy-indigo-50);' : ''; ?>">
                                <input type="checkbox" name="promoter_ids[]" value="<?php echo (int) $p->id; ?>" <?php checked( $is_member ); ?>>
                                <span style="flex:1; min-width:0;">
                                    <strong style="font-size:13px;"><?php echo esc_html( $p->name ); ?></strong>
                                    <span style="display:block; font-size:11px; color:var(--kiwi-legacy-text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                        <?php echo esc_html( $p->email ); ?>
                                    </span>
                                </span>
                                <?php if ( $p->status !== 'active' ) : ?>
                                    <span style="font-size:10px; background:var(--kiwi-legacy-yellow-pill-bg); color:var(--kiwi-legacy-yellow-pill-text); padding:1px 6px; border-radius:8px;"><?php echo esc_html( $p->status ); ?></span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div style="margin-top:14px; display:flex; gap:8px; align-items:center;">
                        <button type="submit" class="ke-btn ke-btn-primary">Save members</button>
                        <a href="<?php echo esc_url( $base_url . '&action=lists' ); ?>" class="ke-btn ke-btn-ghost">Back to all lists</a>
                    </div>
                <?php endif; ?>
            </form>

            <?php
                $upcoming_events = get_posts( array(
                    'post_type'      => 'ke_event',
                    'post_status'    => array( 'publish', 'draft', 'private', 'future' ),
                    'posts_per_page' => 200,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                ) );
                $def_type  = get_option( 'ke_promoter_default_commission_type',  'percentage' );
                $def_value = (float) get_option( 'ke_promoter_default_commission_value', 0 );
            ?>
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                  class="ke-card" style="padding:20px; margin-top:20px; background:var(--kiwi-legacy-page-bg);">
                <input type="hidden" name="action"  value="ke_assign_list_to_event">
                <input type="hidden" name="list_id" value="<?php echo (int) $list->id; ?>">
                <?php wp_nonce_field( 'ke_assign_list_to_event_' . (int) $list->id ); ?>

                <h2 style="margin:0 0 4px; font-size:16px;">Bulk-assign this list to an event</h2>
                <p class="ke-form-hint" style="margin:0 0 14px;">
                    Adds every member of this list to the chosen event with the rate below. Promoters already assigned to the event keep their existing rate.
                </p>

                <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                    <select name="event_id" required class="ke-input" style="flex:1 1 260px; min-width:0;">
                        <option value="">— Choose an event —</option>
                        <?php foreach ( $upcoming_events as $ev ) : ?>
                            <option value="<?php echo (int) $ev->ID; ?>">
                                <?php echo esc_html( $ev->post_title ); ?>
                                <?php
                                    $start = get_post_meta( $ev->ID, '_ke_event_date_start', true );
                                    if ( $start ) echo ' — ' . esc_html( date( 'M j, Y', strtotime( $start ) ) );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="commission_type" class="ke-input" style="width:130px;">
                        <option value="percentage" <?php selected( $def_type, 'percentage' ); ?>>% of price</option>
                        <option value="fixed"      <?php selected( $def_type, 'fixed' ); ?>>$ fixed</option>
                    </select>

                    <input type="number"
                           name="commission_value"
                           class="ke-input"
                           min="0" step="0.01"
                           value="<?php echo esc_attr( number_format( $def_value, 2, '.', '' ) ); ?>"
                           style="width:100px;">

                    <button type="submit" class="ke-btn ke-btn-primary">Apply list</button>
                </div>
            </form>
        <?php endif; ?>

    <?php else : ?>
        <!-- Index -->
        <?php if ( empty( $lists ) ) : ?>
            <div class="ke-card" style="padding:60px 24px; text-align:center;">
                <div style="font-size:32px; margin-bottom:8px;">📋</div>
                <h3 style="margin:0 0 6px;">No lists yet</h3>
                <p style="margin:0 0 18px; color:var(--kiwi-legacy-text-muted);">Lists let you bulk-assign a group of promoters to an event.</p>
                <a href="<?php echo esc_url( $base_url . '&action=list_new' ); ?>" class="ke-btn ke-btn-primary">Create your first list</a>
            </div>
        <?php else : ?>
            <div class="ke-card" style="padding:0; overflow:hidden;">
                <table class="ke-table" style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:var(--kiwi-legacy-page-bg); text-align:left;">
                            <th style="padding:12px 16px; color:var(--kiwi-legacy-text-mid);">Name</th>
                            <th style="padding:12px 16px; color:var(--kiwi-legacy-text-mid);">Description</th>
                            <th style="padding:12px 16px; color:var(--kiwi-legacy-text-mid); text-align:right;">Members</th>
                            <th style="padding:12px 16px; color:var(--kiwi-legacy-text-mid); text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $lists as $l ) :
                            $edit = $base_url . '&action=list_edit&list_id=' . (int) $l->id;
                            $del  = wp_nonce_url(
                                admin_url( 'admin-post.php?action=ke_delete_promoter_list&list_id=' . (int) $l->id ),
                                'ke_delete_promoter_list_' . (int) $l->id
                            );
                        ?>
                            <tr style="border-top:1px solid var(--kiwi-legacy-row-bg-alt);">
                                <td style="padding:12px 16px;">
                                    <a href="<?php echo esc_url( $edit ); ?>" style="color:var(--kiwi-legacy-text-darkest); font-weight:600; text-decoration:none;">
                                        <?php echo esc_html( $l->name ); ?>
                                    </a>
                                </td>
                                <td style="padding:12px 16px; color:var(--kiwi-legacy-text-mid); max-width:520px; overflow:hidden; text-overflow:ellipsis;">
                                    <?php echo esc_html( $l->description ?: '—' ); ?>
                                </td>
                                <td style="padding:12px 16px; text-align:right; font-variant-numeric:tabular-nums;">
                                    <?php echo (int) $l->member_count; ?>
                                </td>
                                <td style="padding:12px 16px; text-align:right; white-space:nowrap;">
                                    <a href="<?php echo esc_url( $edit ); ?>" class="ke-btn ke-btn-ghost" style="font-size:12px; padding:5px 10px;">Edit</a>
                                    <a href="<?php echo esc_url( $del ); ?>"
                                       onclick="return confirm('Delete this list?');"
                                       class="ke-btn ke-btn-danger" style="font-size:12px; padding:5px 10px; margin-left:4px;">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
