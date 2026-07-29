# KiwiEvents 🥝

A complete event management and ticketing plugin for WordPress. Create events, sell tickets (free or paid via WooCommerce), issue QR-coded tickets, scan them at the door, manage attendees, group reservations and promoters, and read sales dashboards — all inside wp-admin, with self-service portals for organizers and promoters on the front end.

| | |
|---|---|
| **Plugin version** | 2.2.0 — the `Version:` header and `KE_VERSION` must always match |
| **Schema version** | 2.6.0 (`KE_DB_VERSION`) — migrations run automatically on load |
| **Requires** | WordPress 6.0+, PHP 8.0+, MySQL 5.7+ |
| **Optional** | WooCommerce (required only for paid tickets) |
| **License** | GPL-2.0+ |
| **Text domain** | `kiwi-events` |
| **REST namespace** | `ke/v1` |

---

## Table of contents

1. [Features](#features)
2. [Installation](#installation)
3. [Quick start](#quick-start)
4. [Shortcodes](#shortcodes)
5. [Front-end URLs](#front-end-urls)
6. [Settings reference](#settings-reference)
7. [Data model](#data-model)
8. [REST API](#rest-api)
9. [Project structure](#project-structure)
10. [Asset cache-busting constants](#asset-cache-busting-constants)
11. [Deployment](#deployment)
12. [Development notes](#development-notes)
13. [Troubleshooting](#troubleshooting)
14. [Companion plugins](#companion-plugins)
15. [Changelog](#changelog)

---

## Features

### Events
Custom post type `ke_event` with start/end dates, venue and address, organizer, categories and tags, status (`active`, `cancelled`, `postponed`, …), a per-event hero background image, "extras" widgets, and an editable slug that keeps 301 redirects from every previous URL (`wp_ke_event_slug_history`).

Events are authored through a **multi-step event builder** in wp-admin rather than the default post editor, and can be **duplicated** in one click.

### Tickets
Ticket types carry a price, a quota and an active/inactive flag. Every individual ticket is its own row with a unique `sha256` code and a generated QR image. Tickets are issued automatically when a WooCommerce order completes, or through the plugin's own free-checkout path when the event has no paid types.

- Per-person purchase limits, enforced server-side.
- Configurable service fee (flat, percentage, or per-event override).
- Ticket PDFs built with TCPDF, with the QR embedded.
- Resend, cancel, or bulk-update tickets from the attendees screen.

### Customer ticket wallet
The `[kiwi_tickets_purchase]` shortcode renders a logged-in user's tickets grouped by event across **Upcoming / Past / Cancelled** tabs, with a QR modal and a per-ticket PDF download. The PDF endpoint re-verifies ownership against `wp_ke_orders.user_id` on the server — the nonce establishes identity, never authorization.

### Door scanner
A public scanning page with live camera capture (jsQR) and REST validation. Access is gated by a per-organizer password, and the UI moves through a strict sequential state flow (pick event → authenticate → scan → result) so a scanner left open can't drift into an ambiguous state. The camera stream persists between scans instead of being torn down each time.

### Group reservations
Table/group reservations per event, running in parallel with ticket sales. Each event can be **tickets-only, reservations-only, or hybrid**. Reservations are either auto-confirmed or held for venue approval, with availability checks, a no-show cron, and a wp-admin page that exports to CSV and PDF.

### Organizers
Taxonomy-backed organizers get:
- A self-service dashboard at `/organizer/{slug}` (password-authenticated, no WordPress account needed) with sales, attendees, reservations, highlights and email templates.
- A public profile at `/organizers/{slug}`.
- Statistics and a downloadable PDF sales report.

### Promoters
Full referral system: URL attribution via `?ke_promo`, click tracking, commissions with a configurable refund policy, a promoter portal at `/promoter/{slug}`, promoter lists with CSV import, notification emails, an admin audit trail, and a visible attribution surface (badge, checkout summary line, email paragraph, WooCommerce order meta).

### Highlights (event stories)
CPT `ke_highlight` — Instagram-style stories attached to an event. Organizers manage them from their dashboard through a REST CRUD that enforces ownership, with hardened image uploads. They render as a hero row on the public event page and open in a full-screen viewer.

### Community board
CPT `ke_board_event` — activities submitted by logged-in users, with no ticketing attached. Submissions go through admin moderation; approved items appear in a public carousel with likes (`wp_ke_board_likes`, unique index + `GET_LOCK` rate limiting), native comments restricted to logged-in users, and a configurable "Trending" label.

### Testimonials
Per-event testimonials built on native WordPress comments, with a freshly-fetched REST nonce so submissions survive full-page caching.

### Emails
Confirmation emails with ticket PDFs attached, a send queue (`wp_ke_email_log`), per-organizer templates, admin notifications, and a test-send button in Settings.

### Dashboards
Sales, revenue and attendee charts in wp-admin, plus an opt-in **dark mode** for Kiwi admin pages driven by `--kiwi-*` design tokens.

---

## Installation

1. Copy the plugin folder to `wp-content/plugins/kiwi-events/` — **including the `vendor/` directory** (see the warning below).
2. Activate **KiwiEvents** in wp-admin → Plugins. All tables (`wp_ke_orders`, `wp_ke_tickets`, `wp_ke_reservations`, the promoter tables, …) are created and migrated automatically, keyed on `KE_DB_VERSION`.
3. *(Optional)* Activate **WooCommerce** for paid tickets — the integration registers itself whenever WooCommerce is active.
4. Configure **Events → Settings** (see [Settings reference](#settings-reference)).

### ⚠️ `vendor/` is mandatory in production

The Composer dependencies — **TCPDF** (ticket and report PDFs) and **chillerlan/php-qrcode** — are committed to the repository on purpose. Managed hosts such as WordPress.com **do not run Composer**, so the plugin must be deployed with `vendor/` bundled. Without it, every ticket PDF (wallet download or email attachment) fails with `tcpdf_missing`.

To regenerate them in development:

```bash
composer install --no-dev
```

`composer.json` must **not** reintroduce a classmap over `includes/`, `admin/` or `public/`. The plugin loads its own classes with `require_once`, and a self-classmap causes "class already in use" fatals against the `class_exists()` guards in the FPDF-based reports.

### ⚠️ Deploy by *Replace*, never by *Delete*

Do not delete the plugin to reinstall it. An earlier `uninstall.php` dropped every table and metadata row on deletion; it has been removed and must never come back, but the habit still matters on hosts that run uninstall routines. Upload the new ZIP and choose **Replace current with uploaded**.

---

## Quick start

1. **Events → Add New Event.** Fill in title, dates, venue, description and featured image in the builder.
2. Add **ticket types** (free or paid) with prices and quotas.
3. Assign an **organizer** and an **event category**.
4. **Publish**, then place `[kiwi_events]` on a page to list events and `[kiwi_tickets_purchase]` on a "My tickets" page.
5. Set the organizer's **scanner password** (Organizers screen) and open the scanner page on a phone at the door.
6. Buy one test ticket end-to-end and confirm the confirmation email arrives with the PDF attached.

---

## Shortcodes

| Shortcode | Description |
|---|---|
| `[kiwi_events]` | Main event listing / carousel |
| `[kiwi_event]` | A single event |
| `[kiwi_checkout]` | Ticket checkout |
| `[kiwi_events_carousel]` | Event carousel, filterable by category |
| `[kiwi_events_list]` | Plain event list |
| `[kiwi_events_calendar]` | Month calendar of events |
| `[kiwi_organizers]` | Organizer directory |
| `[kiwi_tickets_purchase]` | Customer "My tickets" wallet (login required) |
| `[kiwi_board]` | Public community-board carousel (approved activities) |
| `[kiwi_create_board]` | Board submission form (login required; moderated) |
| `[kiwi-promoter-dashboard]` | Promoter mini-dashboard |

## Front-end URLs

| Path | Purpose |
|---|---|
| `/organizer/{slug}` | Organizer self-service dashboard (password) |
| `/organizers/{slug}` | Public organizer profile |
| `/promoter/{slug}` | Promoter portal |
| `/board/{slug}` | Single community-board activity |
| `?ke_promo={code}` | Promoter attribution, persisted in session |

These rely on rewrite rules. `register_post_type()` only adds rules in memory — WordPress persists them on an explicit flush, which normally happens only on **activation**, and plugin *updates* never re-run activation. Bump `KE_REWRITE_VERSION` whenever you add a CPT or change a slug; the `init` priority-99 guard then flushes exactly once per version (and self-heals if the board rules go missing despite the version saying otherwise).

## Settings reference

**Events → Settings** has nine tabs:

| Tab | What lives there |
|---|---|
| **General** | Core behaviour and access control (login/register URLs, logged-out message) |
| **Payments** | Service fee, currency handling, WooCommerce wiring |
| **Emails** | Templates, sender, admin notifications, test send |
| **Events** | Per-person ticket limits, expiry behaviour, extras |
| **Organizers** | Organizer defaults and scanner passwords |
| **Promoters** | Commission rates and refund policy |
| **Categories** | Event category management |
| **Board** | Community board on/off, moderation, "Trending" label |
| **Advanced** | Diagnostics and maintenance |

---

## Data model

### Post types & taxonomies

| Object | Type | Notes |
|---|---|---|
| `ke_event` | CPT | The event itself |
| `ke_highlight` | CPT | Event stories, owned by an organizer |
| `ke_board_event` | CPT | Community activity, moderated, no ticketing |
| `ke_organizer` | Taxonomy | Organizers |
| `ke_event_category` | Taxonomy | Event categories |
| `ke_event_tag` | Taxonomy | Event tags |

### Tables

| Table | Contents |
|---|---|
| `wp_ke_ticket_types` | Ticket types per event (price, quota, active flag) |
| `wp_ke_orders` | Orders, including `user_id` — the authorization source of truth |
| `wp_ke_tickets` | One row per individual ticket (unique code, status, check-in) |
| `wp_ke_reservations` | Group/table reservations |
| `wp_ke_event_slug_history` | Previous slugs, powering 301 redirects |
| `wp_ke_email_log` | Email queue and send log |
| `wp_ke_board_likes` | Board likes (unique index per user+post) |
| `wp_ke_promoters` | Promoter records |
| `wp_ke_event_promoters` | Promoter ↔ event assignments |
| `wp_ke_promoter_clicks` | Referral click tracking |
| `wp_ke_promoter_commissions` | Earned commissions and refund state |
| `wp_ke_promoter_lists` / `_list_members` | Promoter lists and membership |
| `wp_ke_promoter_admin_audit` | Audit trail for admin actions on promoters |

Migrations are driven by `KE_DB_VERSION` and run from `KE_Activator::maybe_upgrade()` on `plugins_loaded`, so existing installs pick up new columns without a manual reactivate. A failed migration surfaces as an admin notice.

---

## REST API

Namespace `ke/v1` (one legacy promoter route lives under `kiwi-events/v1`). Grouped by area:

**Events & tickets**
```
GET    /events
GET    /events/{id}
POST   /events/save
GET    /events/check-slug
POST   /events/{id}/duplicate
GET    /events/{id}/attendees
POST   /events/{id}/attendees/add
GET    /events/{id}/checkin-stats
POST   /events/{id}/ticket-types/{type_id}/toggle-active
GET    /calendar-events
POST   /checkout
GET    /tickets/{id}
POST   /tickets/{id}/update-status
POST   /tickets/{id}/resend-email
POST   /tickets/bulk
GET    /tickets/validate/{code}
POST   /orders/{id}/resend-admin-notification
GET    /my-tickets/{id}/pdf          # ownership-verified server-side
```

**Reservations**
```
GET    /reservations
GET    /events/{id}/reservations/availability
POST   /admin/reservations/{id}/{approve|decline|check-in|cancel}
```

**Organizers**
```
POST   /organizer/auth
POST   /organizer/{slug}/logout
GET    /organizer/{slug}/stats
GET    /organizer/{slug}/activity
GET    /organizer/{slug}/attendees
GET    /organizer/{slug}/last-sale
GET    /organizer/{slug}/reservations
POST   /organizer/{slug}/reservations/{id}/{approve|decline|check-in|cancel}
GET    /organizer/{slug}/export/csv
GET    /organizer/{slug}/export/pdf
GET    /organizer/{slug}/highlights
*      /organizer/{slug}/highlights/{id}
GET    /organizers/{id}/password-meta
POST   /organizers/{id}/update-password
*      /organizers/{id}/templates[/{tpl_id}]
```

**Scanner**
```
POST   /scanner/auth
GET    /scanner/active-events
```

**Board & testimonials**
```
POST   /board/submit
POST   /board/like/{id}
GET    /events/{id}/testimonials
DELETE /events/{id}/testimonials/{comment_id}
```

**Dashboard, settings & promoters**
```
GET    /dashboard/stats
GET    /dashboard/chart-data
*      /settings[/access|/board|/fees|/fees/{id}|/notifications|/ui]
POST   /settings/test-notification
POST   /promoter-login
GET    /users/search
GET    kiwi-events/v1/promoters/event/{id}/activity
```

---

## Project structure

```
kiwi-events.php          Bootstrap: constants, requires, boot sequence
includes/                Core: post types, tickets, orders, REST API (ke/v1),
                         WooCommerce, QR, PDF, email, promoters, reservations,
                         board, highlights
admin/                   wp-admin pages (dashboard, attendees, reservations,
                         promoters, board moderation, event builder, settings)
  admin/views/           One PHP view per admin screen
  admin/css|js/          Admin assets (--kiwi-* design tokens)
public/                  Front end: shortcodes, views, CSS/JS (--kep-* tokens)
templates/email|pdf/     Email and PDF templates
plugins/                 Optional companion plugins (see below)
vendor/                  Composer dependencies (TCPDF, php-qrcode) — deployed
```

## Asset cache-busting constants

`KE_VERSION` moves slowly, so fast-iterating areas get their own cache-bust constant. This matters on WordPress.com's edge cache: bump the constant **and** purge the cache when you deploy CSS/JS.

| Constant | Area |
|---|---|
| `KE_SCANNER_ASSETS_VER` | Door scanner |
| `KE_BUILDER_ASSETS_VER` | Event builder (admin) |
| `KE_TOKENS_ASSETS_VER` | Admin design tokens (`ke-admin-tokens.css`) |
| `KE_ADMIN_CSS_VER` / `KE_ADMIN_JS_VER` | General admin CSS / JS |
| `KE_PORTAL_ASSETS_VER` | Promoter portal |
| `KE_WALLET_ASSETS_VER` | Customer ticket wallet |
| `KE_BOARD_ASSETS_VER` | Community board |
| `KE_REWRITE_VERSION` | Rewrite rules (triggers a one-time flush) |

There is also `KE_PROMOTER_DEBUG` — diagnostic logging for promoter attribution. Flip it to `true` **only** while investigating a missing-commission report; while enabled it leaves a banner in wp-admin so it can't be forgotten.

## Deployment

```bash
composer install --no-dev
cd .. && zip -r kiwi-events.zip kiwi-events/ \
  -x "*.DS_Store" -x "*/.git/*" -x "*/.agents/*" -x "*/.claude/*" \
  -x "*/skills/*" -x "*/.vscode/*"
```

Then in wp-admin → Plugins → Add New → Upload Plugin, choose the ZIP and **Replace current with uploaded**. Bump the relevant asset constant and purge the host's edge cache if you changed CSS or JS.

## Development notes

- **PHP 8 + REST:** never pass a PHP internal function as a string in a route's `validate_callback` / `sanitize_callback` (`is_numeric`, `absint`, …). WordPress invokes them with three arguments and PHP 8 raises `ArgumentCountError`. Use a closure: `static function ( $value ) { return is_numeric( $value ); }`.
- **Front-end styling:** use the `--kep-*` tokens defined in `public/css/ke-public.css`; never hardcode Kiwi green on the front end. Every new public surface needs the WordPress.com button-override guard (follow the pattern in `ke-promoter-portal.css`).
- **Admin styling:** use the `--kiwi-*` tokens from `admin/css/ke-admin-tokens.css`, which also carries the `:root[data-theme="dark"]` remap for dark mode.
- **Event dates:** reuse `KE_Shortcodes::event_is_expired()` — it is fail-open and handles the mixed formats stored in `_ke_event_date_start/_end`. Do not write new date comparisons.
- **Authorization:** any endpoint serving ticket data must verify ownership against `wp_ke_orders.user_id` server-side. A nonce proves identity, not permission.
- **Event builder JS:** `#ke-resv-body` uses class-based show/hide plus an inline vanilla-JS fallback handler, so the reservations panel still toggles if the main builder bundle fails to load.
- **Extras:** stored in `_ke_event_extras`; the template mapping renders testimonials last, always.
- **Audit/backfill tooling:** ship **read-only reports**, never auto-charge or auto-delete buttons.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Plugin won't activate | Confirm PHP ≥ 8.0; check the PHP error log |
| `tcpdf_missing` on ticket PDFs | `vendor/` was not deployed — re-upload with it included |
| 404 on `/organizer/…`, `/board/…` | Bump `KE_REWRITE_VERSION`, or re-save Settings → Permalinks |
| CSS/JS changes not visible | Bump the area's asset constant and purge the host edge cache |
| QR codes not generating | Enable the GD or Imagick PHP extension |
| Emails not sending | Install an SMTP plugin (e.g. WP Mail SMTP) and use Settings → Emails → test send |
| Wrong version in the plugins list | The `Version:` header and `KE_VERSION` have drifted — set both |
| Testimonial comments rejected | Page cache served a stale nonce; the REST nonce refresh handles this — verify it is not stripped by an optimizer |

## Companion plugins

`plugins/` holds optional, separately-activated helpers:

- **`ke-sold-audit`** — read-only sold-vs-attendees diagnostic. Reports discrepancies; it never writes.
- **`ke-yappy-fee-fix`** — Yappy gateway fee reconciliation, with its own `KE_FEE_DEBUG` flag.

---

## Changelog

### 2.2.0
- **Birthday extras** reworked as an event extras widget (previously a step-3 CTA plus modal), rendering directly below the tickets.
- **Highlights** (event stories): new `ke_highlight` CPT, REST CRUD with organizer-ownership checks, hardened uploads, an event-builder selector, a public hero row and a full-screen story viewer.
- **Community board**: centralized and self-healing rewrite flush, email notifications, redesigned moderation queue.
- **Scanner**: public access with a per-organizer password, sequential state flow, persistent camera.
- **Testimonials**: fresh REST nonce so comments survive page caching.
- **Sold-audit tool** (`plugins/ke-sold-audit`) — read-only sold-vs-attendees diagnostic.
- **Removed `uninstall.php`**, which wiped every table and metadata row when the plugin was deleted.
- Aligned the `Version:` header with `KE_VERSION` — the header had been frozen at 1.0.0 while the code shipped 2.x.

### 2.1.x
- Attendees/Reservations exports stream on `admin_init` instead of during `render()`.
- Logged-out gates always resolve the login URL from Access Control settings.
- Per-event hero background image with a fixed darkening gradient.
- Customer ticket wallet `[kiwi_tickets_purchase]`, promoters admin, PDF/QR fixes.

### 2.0.x
- Community board `[kiwi_board]` / `[kiwi_create_board]` with moderation.
- Composer dependencies vendored: TCPDF 6.11.3 + chillerlan/php-qrcode 5.0.5.

## License

GPL-2.0+ — see <http://www.gnu.org/licenses/gpl-2.0.txt>.
