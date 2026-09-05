# Ticket-count fix — verification checklist (v2.5.3)

Covers the cart-limit bypass fix (Bug 1), the multi-ticket / one-QR fix (Bug 2),
the read-only audit, the email fixes, and the dormant re-issue handler.

**How to read this:** each item is marked
- ✅ **CODE** — verified by an automated harness and/or a live WP bootstrap on the
  kiwi-test site (real WooCommerce, HPOS enabled). No human action needed to trust it.
- 🟡 **PROD** — cannot be proven without a rendered cart, a real purchase, or a real
  scan at the door. A person must do these on the live site. Steps are given.

Automated coverage that backs the ✅ items: `evaluate` core (16), integration
(61), audit (24+), email render (13), re-issue safety (31), plus a live bootstrap
against the HPOS kiwi-test DB. None of this touches production data.

---

## A. Bug 1 — per-person limit (no bypass)

1. ✅ **CODE** Central rule `KE_Ticket_Limits::can_user_take()` sums issued tickets
   (by email) + all cart lines for the event, vs the event limit. Dynamic Spanish
   message states the limit and how many the buyer already holds.
2. 🟡 **PROD — rendered cart** Limited event (set _ke max tickets per person = 1).
   On the **classic** cart, add 1 ticket, then press the **+** stepper. Expect: the
   quantity is **fixed text** ("Cambiar en el evento" link), not an editable stepper.
   If your Cart page is the **Block** cart, the stepper must be **locked** at the
   line's quantity (min == max). Either way it cannot be raised.
3. 🟡 **PROD — rendered cart** With the limit at 1 and one ticket already in cart,
   try to add the same event again from the event page. Expect the dynamic Spanish
   block: *"Solo se permite 1 boleto por persona para el evento «…». Ya tienes 1."*
4. 🟡 **PROD — crafted request** Hit `?add-to-cart={hidden_product_id}` directly for
   a ticket product (no attendee data). Expect rejection + notice pointing to the
   event page. (✅ CODE: `validate_add_to_cart` F-branch resolves the event from
   product meta and blocks.)
5. ✅ **CODE** `max_per_order` is enforced server-side and **aggregated across cart
   lines** of the same ticket type (the merge fix made lines non-mergeable, so a
   per-line check would have been bypassable).
6. ✅ **CODE** REST `/checkout` returns **400** when `count(attendees) != quantity`.
7. 🟡 **PROD — HPOS** Confirm the ticket-generation idempotency flag holds under
   HPOS: place a paid order, let it complete, then move it to processing→completed
   again in wp-admin. Expect **no duplicate tickets** (✅ CODE: flag read/written/
   deleted via the order object; verified HPOS-enabled on kiwi-test).

## B. Cart-type / UI mechanism — **run the log check on production**

8. 🟡 **PROD — cart-path log** The plugin logs which cart UI actually rendered, once
   per request. On the live site, open the **Cart page with a ticket in it**, then
   check `wp-content/debug.log` (or the host log) for **one** line:
   - `KiwiEvents: cart ticket-quantity UI path = classic (…)` → classic template
     path is active; the fixed-text quantity applies.
   - `KiwiEvents: cart ticket-quantity UI path = store_api (…)` → Block cart is
     active; the stepper-lock (min==max) applies.
   This confirms the active mechanism on production instead of assuming it. Whichever
   fires, the per-person limit is still enforced server-side at checkout regardless.

## C. Bug 2 — one ticket per unit, all QRs

9. 🟡 **PROD — real purchase + scan** Buy **3** tickets of a high-limit event in one
   order. Expect: **3 distinct ticket rows, 3 distinct codes, 3 QR images** in the
   buyer email; each QR **scans independently** at check-in and **each redeems
   once** (a second scan of the same code is rejected).
10. ✅ **CODE** Generation mints one row per attendee; the reconciliation safety net
    in `generate_for_order` makes minted count = min(paid, limit) with the attendee
    blob padded/trimmed, flagging any divergence on the order for review.
11. 🟡 **PROD — merge** Add the same ticket twice in two separate adds. Expect **two
    cart lines** (the `_ke_line_uid` merge fix), and paying for both yields the
    correct **total** ticket count. (✅ CODE: unique line uid verified.)
12. 🟡 **PROD — high-limit event** A normal multi-ticket purchase on an unlimited /
    high-limit event still completes end to end.

## D. Email (Phase 6)

13. ✅ **CODE** **Admin/organizer notification loops every ticket** — each attendee
    gets their own QR link (previously only `tickets[0]`). Verified: 2-ticket order
    renders both `/ticket/{code}` links; single-ticket keeps the button.
14. ✅ **CODE** **Buyer email** has a clip-resilient per-ticket link list at the top
    (survives Gmail's ~102KB message clipping on large multi-ticket orders) plus the
    per-ticket QR blocks below. Gmail threshold documented in a code comment.
15. ✅ **CODE** Dead template `templates/email/ticket-email.php` **deleted**
    (confirmed unreferenced).
16. 🟡 **PROD — resend** Resend a multi-ticket order's email (Attendees screen or
    `/tickets/{id}/resend-email`). Expect the **same codes**, **no new ticket rows**.
    (✅ CODE: resend re-reads existing rows; no regeneration.)
17. ✅ **CODE / SAFETY** **No bulk/scheduled/automatic send** was introduced. Every
    send is per-order (payment-complete, per-order/-ticket resend, or the explicit
    `/tickets/bulk` action which acts only on an admin-supplied `ids` array). No code
    path enumerates past orders and sends. **Re-verify before shipping any future
    email change.**

## E. Read-only audit (Phase 5) — **run on production before Phase decisions**

18. 🟡 **PROD — run the audit** `wp-admin/admin.php?page=ke-ticket-audit`. Set **Since**
    to before the first ticket sale (full history). Watch for the **row-cap** and
    **orphan-cap** truncation warnings — if either trips, run in windows and combine.
19. 🟡 **PROD — read the banner first** storage mode, KE orders, WC paid, scanned. A
    large **KE-vs-WC-paid gap** means the **orphan section** carries the real story.
20. 🟡 **PROD — export CSV** and record: orphan orders (actionable vs refunded),
    tickets missing, tickets over-issued, oversold events, capacity-unknown events,
    customers, revenue, date range. **This is the number that decides quiet-fix vs
    customer outreach.** (✅ CODE: both-direction detection, refund-aware split,
    oversell + capacity-unknown, orphan section, zero-guard, all harness-verified.)

## F. Re-issue handler — **built, ready, DO NOT FIRE until the audit is reviewed**

21. ✅ **CODE** `KE_Ticket_Reissue` + REST `/reissue/plan|execute|reverse` (admin-only).
    Verified: **per-order only** (no bulk), **plan-before-write** (execute needs
    `confirm=true`), **idempotent** (owed recomputed; re-run mints 0), **reversible**
    (`reverse` cancels exactly the batch), **capacity-capped** (no oversell),
    **refuses refunded + passed** unless a per-reason override, **no_ticket_type is
    not overridable**, **silent** unless `send_email=true`, **orphans** create the
    ke_order then mint through the same refusals.
22. 🟡 **PROD — dry run only, after the audit** For a specific recoverable order, call
    `/reissue/plan` and read exactly what it would create. **Do not** call
    `/reissue/execute` until the production audit is reviewed and the target order is
    confirmed to be actionable (not refunded, event upcoming). On kiwi-test the real
    affected orders correctly **refused with `no_ticket_type`** (free orders with no
    resolvable type) — a reminder that the handler declines rather than guessing.

---

### Not done / out of scope (by instruction)
- `total_amount` double-count on multi-line orders (audit revenue is a loose upper
  bound; disclosed in the audit's "Known limitations").
- HPOS **compatibility declaration** — deliberately NOT added; the generation path is
  HPOS-safe but a full-plugin order-meta audit is a separate ticket.
- No commit made — changes are local pending review.
