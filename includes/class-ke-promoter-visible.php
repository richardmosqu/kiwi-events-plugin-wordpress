<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Visible promoter attribution. All rendering is EVENT-SCOPED — each surface
 * derives an event_id from its context and asks
 * KE_Promoter_Attribution::display_for_event() whether there's an active
 * promoter for that event right now. There is no site-wide fallback.
 *
 * Event-id derivation by surface:
 *   - Single-event template       → $post->ID (passed in by single-event.php)
 *   - WC cart / checkout           → first ke_event_id in WC()->cart items
 *   - WC thank-you / order email   → frozen `_ke_promoter_slug` on the order
 *                                    (no live attribution lookup; once an
 *                                    order is placed the slug is immutable)
 *   - WC admin order screen        → same as above
 *
 * Three rendering surfaces, all conditional on display_for_event() returning
 * a payload:
 *
 *   1) URL persistence — appends ?promo={slug} to WC cart/checkout/order-received
 *      URLs so the param survives the cart→checkout→thank-you transition. The
 *      param is intentionally NOT propagated to unrelated site URLs (homepage,
 *      account, etc.) — those filters aren't hooked.
 *
 *   2) On-screen badge — a small, dismissible "Comprando con X" pill rendered
 *      on event pages, WC cart/checkout, and the thank-you page. The dismiss
 *      button hides the badge for the current page render only; the underlying
 *      attribution stays intact.
 *
 *   3) Checkout summary line + email paragraph + WC admin meta box.
 */
class KE_Promoter_Visible {

    public function init() {
        // URL persistence — append ?promo= to WC's internal URLs so the
        // query param survives navigation through the cart/checkout flow.
        add_filter( 'woocommerce_get_checkout_url',        array( $this, 'append_promo_to_url' ) );
        add_filter( 'woocommerce_get_cart_url',            array( $this, 'append_promo_to_url' ) );
        add_filter( 'woocommerce_return_to_shop_redirect', array( $this, 'append_promo_to_url' ) );
        add_filter( 'woocommerce_get_checkout_payment_url',array( $this, 'append_promo_to_url' ) );
        add_filter( 'woocommerce_get_checkout_order_received_url', array( $this, 'append_promo_to_url' ) );

        // On-screen badge — render on event view, cart, checkout, thank-you.
        // Single-event view: rendered inline from the view file (see
        // single-event.php). The WC pages are reached via these hooks.
        add_action( 'woocommerce_before_cart',     array( $this, 'render_badge' ), 5 );
        add_action( 'woocommerce_before_checkout_form', array( $this, 'render_badge' ), 5 );
        add_action( 'woocommerce_thankyou',        array( $this, 'render_badge_for_order' ), 1, 1 );

        // Checkout order-review line ("Promotor: [Name]").
        add_action( 'woocommerce_review_order_after_order_total', array( $this, 'render_checkout_summary_line' ) );

        // WC admin order screen — show attributed promoter as visible meta.
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'render_admin_order_meta' ) );

        // Email paragraph — injected into the ticket confirmation
        // email via a filter that KE_Email::get_email_html() consults.
        add_filter( 'ke_email_extra_html_after_intro', array( $this, 'filter_ticket_email_paragraph' ), 10, 2 );

        // One-time client-side purge: expire any non-HttpOnly ke_promo* cookies
        // left in the browser from the prior persistence-based deploys.
        add_action( 'wp_footer', array( $this, 'render_legacy_cookie_purge_js' ) );
    }

    /**
     * Footer JS to expire any leftover ke_promo / ke_promo_<event_id> cookies
     * in the visitor's browser. PHP-side clear_legacy_storage() also wipes the
     * server-visible cookies + WC session keys; this snippet covers any that
     * might be readable only from the document (e.g. CDN domain quirks).
     *
     * Runs only on event singular pages so the snippet doesn't add weight to
     * every page on the site.
     */
    public function render_legacy_cookie_purge_js() {
        if ( ! is_singular( 'ke_event' ) ) return;
        ?>
        <script>
        (function () {
            try {
                document.cookie.split(';').forEach(function (c) {
                    var n = c.trim().split('=')[0];
                    if (n && n.indexOf('ke_promo') === 0) {
                        document.cookie = n + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
                    }
                });
            } catch (_) { /* swallow */ }
        })();
        </script>
        <?php
    }

    /**
     * Derive an event_id from the first ke_event_id-bearing cart item.
     * Used by all WC-cart-context callers (badge, summary line, URL append).
     * Multi-event cart: last-added wins per the spec — we walk in cart order
     * and the most recent add_to_cart() will be the final iteration's value.
     *
     * Returns 0 when the cart is empty or no items carry event_id (in which
     * case there is no context for attribution and callers should no-op).
     */
    public static function derive_event_id_from_cart() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return 0;
        $event_id = 0;
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( ! empty( $item['ke_event_id'] ) ) {
                $event_id = (int) $item['ke_event_id'];
            }
        }
        return $event_id;
    }

    /**
     * Display payload for a thank-you / admin order screen: pull the frozen
     * `_ke_promoter_slug` off the WC order rather than re-reading session.
     * Returns array{slug,name,id} or null.
     */
    private static function display_for_order( $order ) {
        if ( ! $order instanceof WC_Order ) return null;
        $slug = (string) $order->get_meta( '_ke_promoter_slug' );
        if ( $slug === '' ) return null;
        $promoter = KE_Promoter_Attribution::find_by_slug( $slug );
        if ( ! $promoter ) return null;
        $who  = KE_Promoter_Attribution::display_for( $promoter );
        $name = $who['name'] !== '' ? $who['name'] : $slug;
        return array(
            'slug' => $slug,
            'name' => $name,
            'id'   => (int) $promoter->id,
        );
    }

    /**
     * Propagate ?promo= from the CURRENT request URL onto any WC-emitted
     * cart/checkout URL. URL-only model: this is what carries the promoter
     * slug through the event → cart → checkout funnel so the referrer at
     * order-processed time still has it. No validation here — the badge and
     * the commission generator each validate independently; this filter is
     * pure URL plumbing.
     */
    public function append_promo_to_url( $url ) {
        if ( ! is_string( $url ) || $url === '' ) return $url;
        if ( ! class_exists( 'KE_Promoter_Attribution' ) ) return $url;

        $raw = '';
        if ( ! empty( $_GET[ KE_Promoter_Attribution::QUERY_PARAM ] ) ) {
            $raw = (string) $_GET[ KE_Promoter_Attribution::QUERY_PARAM ];
        } elseif ( ! empty( $_GET[ KE_Promoter_Attribution::QUERY_PARAM_LEGACY ] ) ) {
            $raw = (string) $_GET[ KE_Promoter_Attribution::QUERY_PARAM_LEGACY ];
        }
        $slug = sanitize_title( $raw );
        if ( $slug === '' ) return $url;
        return add_query_arg( KE_Promoter_Attribution::QUERY_PARAM, $slug, $url );
    }

    /**
     * WC cart/checkout hook target — derives event_id from cart and renders.
     */
    public function render_badge() {
        $event_id = self::derive_event_id_from_cart();
        echo self::badge_html( $event_id );
    }

    /**
     * woocommerce_thankyou hook target — receives $order_id, renders the
     * badge using the order's frozen promoter slug (NOT live session).
     */
    public function render_badge_for_order( $order_id ) {
        $order = $order_id ? wc_get_order( (int) $order_id ) : null;
        $cur   = self::display_for_order( $order );
        if ( ! $cur ) return;
        echo self::badge_markup( $cur['name'] );
    }

    /**
     * Return badge HTML (or empty string when no active promoter for the
     * given event). Public so view files (single-event.php) can include it
     * directly with their known $event_id.
     */
    public static function badge_html( $event_id ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) return '';
        if ( ! class_exists( 'KE_Promoter_Attribution' ) ) return '';
        $cur = KE_Promoter_Attribution::display_for_event( $event_id );
        if ( ! $cur ) return '';
        return self::badge_markup( $cur['name'] );
    }

    /**
     * Pure rendering — markup + scoped CSS. Used by both the live (event-page,
     * cart, checkout) and frozen (thank-you, post-order) paths.
     */
    private static function badge_markup( $name ) {
        $name = esc_html( (string) $name );
        $sub  = esc_html__( 'Tu compra apoya a este promotor', 'kiwi-events' );
        $lead = esc_html__( 'Comprando con', 'kiwi-events' );
        $aria = esc_attr__( 'Ocultar aviso de promotor', 'kiwi-events' );

        ob_start();
        ?>
        <div class="ke-promo-badge" role="region" aria-label="<?php echo esc_attr__( 'Atribución de promotor', 'kiwi-events' ); ?>">
          <div class="ke-promo-badge__inner">
            <span class="ke-promo-badge__icon" aria-hidden="true">🎟</span>
            <div class="ke-promo-badge__text">
              <div class="ke-promo-badge__lead"><?php echo $lead; ?> <strong><?php echo $name; ?></strong></div>
              <div class="ke-promo-badge__sub"><?php echo $sub; ?></div>
            </div>
            <button type="button" class="ke-promo-badge__close" aria-label="<?php echo $aria; ?>"
                    onclick="this.closest('.ke-promo-badge').style.display='none';">×</button>
          </div>
        </div>
        <style>
          .ke-promo-badge {
            margin: 12px auto;
            max-width: 600px;
            box-sizing: border-box;
          }
          .ke-promo-badge__inner {
            display: flex; align-items: center; gap: 12px;
            background: color-mix(in srgb, var(--kep-accent-1, #6366f1) 8%, white);
            border: 1px solid color-mix(in srgb, var(--kep-accent-1, #6366f1) 20%, white);
            border-radius: 12px;
            padding: 12px 16px;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
          }
          .ke-promo-badge__icon { font-size: 22px; line-height: 1; }
          .ke-promo-badge__text { flex: 1; min-width: 0; }
          .ke-promo-badge__lead {
            font-size: 14px; font-weight: 600; line-height: 1.3;
            color: #111827;
          }
          .ke-promo-badge__lead strong {
            color: var(--kep-accent-1, #6366f1);
            font-weight: 700;
          }
          .ke-promo-badge__sub {
            font-size: 12px; color: #6b7280; margin-top: 2px;
          }
          .ke-promo-badge__close {
            background: transparent; border: 0; cursor: pointer;
            font-size: 20px; line-height: 1; color: #9ca3af;
            padding: 4px 8px; border-radius: 6px;
          }
          .ke-promo-badge__close:hover { color: #374151; background: rgba(0,0,0,0.04); }
          @media (max-width: 480px) {
            .ke-promo-badge__inner { padding: 10px 12px; gap: 10px; }
            .ke-promo-badge__lead { font-size: 13px; }
            .ke-promo-badge__sub { font-size: 11px; }
          }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Render "Promotor: [Name]" inside the WC checkout order-review table.
     * Event-scoped: derives the event from the cart so a leftover promo for
     * a different event never appears here.
     */
    public function render_checkout_summary_line() {
        if ( ! class_exists( 'KE_Promoter_Attribution' ) ) return;
        $event_id = self::derive_event_id_from_cart();
        if ( $event_id <= 0 ) return;
        $cur = KE_Promoter_Attribution::display_for_event( $event_id );
        if ( ! $cur ) return;
        ?>
        <tr class="ke-checkout-promoter">
          <th><?php esc_html_e( 'Promotor', 'kiwi-events' ); ?></th>
          <td><?php echo esc_html( $cur['name'] ); ?></td>
        </tr>
        <?php
    }

    /**
     * Show the attributed promoter on the WC admin order screen. Reads the
     * _ke_promoter_slug meta that on_payment_complete() persisted on the order.
     */
    public function render_admin_order_meta( $order ) {
        if ( ! $order instanceof WC_Order ) return;
        $slug = (string) $order->get_meta( '_ke_promoter_slug' );
        if ( $slug === '' ) return;
        if ( ! class_exists( 'KE_Promoter_Attribution' ) ) return;

        $promoter = KE_Promoter_Attribution::find_by_slug( $slug );
        $who      = $promoter ? KE_Promoter_Attribution::display_for( $promoter ) : array( 'name' => '', 'email' => '' );
        $display  = $who['name'] !== '' ? $who['name'] : $slug;
        ?>
        <div class="ke-order-promoter" style="margin-top:12px;padding:10px 12px;background:#f0f9ff;border-left:3px solid #6366f1;">
          <strong><?php esc_html_e( 'Promotor:', 'kiwi-events' ); ?></strong>
          <?php echo esc_html( $display ); ?>
          <?php if ( $who['email'] ) : ?>
            <small style="display:block;color:#6b7280;margin-top:2px;"><?php echo esc_html( $who['email'] ); ?></small>
          <?php endif; ?>
          <small style="display:block;color:#6b7280;margin-top:2px;">
            <?php esc_html_e( 'Slug:', 'kiwi-events' ); ?> <code><?php echo esc_html( $slug ); ?></code>
          </small>
        </div>
        <?php
    }

    /**
     * Hook target: returns an HTML paragraph for the ticket confirmation
     * email when the order has an attributed promoter. KE_Email reads this
     * filter and injects the returned HTML below the "Hi [name]" intro.
     *
     * @param string $extra existing extra-html accumulated by other filters
     * @param int    $order_id KE order id
     */
    public function filter_ticket_email_paragraph( $extra, $order_id ) {
        $order_id = (int) $order_id;
        if ( ! $order_id ) return $extra;

        // Look up attributed promoter for this KE order. KE order links to
        // wc_order_id (if paid) — easiest is to look at the commission row.
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT promoter_id
               FROM {$wpdb->prefix}ke_promoter_commissions
              WHERE order_id = %d
              ORDER BY id ASC LIMIT 1",
            $order_id
        ) );
        if ( ! $row ) return $extra;

        $promoter = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ke_promoters WHERE id = %d LIMIT 1",
            (int) $row->promoter_id
        ) );
        if ( ! $promoter ) return $extra;
        $who = KE_Promoter_Attribution::display_for( $promoter );
        $name = $who['name'] !== '' ? $who['name'] : $promoter->slug;

        $paragraph = '<p style="font-size:14px;color:#4b5563;background:#f9fafb;padding:12px 14px;border-radius:8px;border-left:3px solid #6366f1;margin:16px 0;">'
                   . esc_html( sprintf(
                       __( 'Esta compra fue hecha a través del promotor %s. ¡Gracias por apoyarlo!', 'kiwi-events' ),
                       $name
                     ) )
                   . '</p>';
        return $extra . $paragraph;
    }
}
