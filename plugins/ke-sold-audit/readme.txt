=== Kiwi Events — Sold vs Attendees Diagnostic ===
Contributors: campuslifepa
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

READ-ONLY diagnostic. Nothing on this page writes, updates, deletes, or repairs
data — it runs SELECT queries only.

== What it does ==

Reconciles, for any single event, the two counts that disagree:

  * Organizer dashboard "Sold"  = SUM(ke_ticket_types.quantity_sold), non-archived
  * Admin attendee list         = COUNT of live ke_tickets rows

and attributes every missing ticket to a cause:

  * cancelled / refunded rows        (legitimately excluded from "sold")
  * archived ticket types w/ sales   (real sales wrongly dropped)
  * orphan / foreign ticket-type IDs (real sales wrongly dropped)
  * counter drift (qty_sold != rows) (over- or under-count)

It also shows rows-by-status, the per-ticket-type table, the order
payment_status breakdown, and the dashboard net-revenue figure, so you can see
exactly which tickets fall into which bucket before deciding any fix.

== Install ==

1. Plugins → Add New → Upload Plugin → choose ke-sold-audit.zip → Install → Activate.
2. Open the "KE Sold Audit" item in the left admin menu (chart icon).
3. Pick an event (e.g. "WHITE PARTY ULAT 69-70") and click Run Diagnostic.

Requires the Kiwi Events core plugin to be active (it reads its ke_tickets /
ke_ticket_types / ke_orders tables). Access is limited to manage_options.

== Notes ==

* Standard plugin (not an mu-plugin), so it works on WordPress.com Business.
* Borrows the Kiwi Events admin design tokens and dark-mode preference so the
  page matches the rest of wp-admin.
* Safe to leave installed; safe to delete when done. It changes nothing.
