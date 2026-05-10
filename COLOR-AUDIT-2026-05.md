# Color audit — May 2026 (BUG 2)

User report: pink/rose tinted buttons appearing throughout the plugin's
public UI despite a configured accent color in `ke_ui_settings`.

## Method

```
grep -ri "#ec4899|#f43f5e|#be185d|#db2777|#e11d48|pink|rose|fuchsia|crimson|magenta" .
grep -ri "#8b5cf6|#a855f7|#c084fc|#d946ef" .         # purple → reads pink against warm accents
grep -rn "rgba\(99,\s*102,\s*241" public/             # hardcoded indigo glow
```

## Root cause

The plugin defines a **two-stop accent gradient** as a CSS default:
`--kep-accent-grad: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%)`
(indigo → violet). When the user picks a warm accent (red, orange, yellow),
`KE_Public::inject_color_vars()` *does* override this gradient to a flat
single-color version, but only on the selectors `:root, .ke-event-page,
.ke-sheet, .ke-sheet-overlay`. Any element that ALSO had a literal purple
or indigo color baked into its CSS rule (not via a `var()` fallback)
escaped the override — and the violet next to a warm accent reads as
pink/rose.

A separate class of leak: `box-shadow: ... rgba(99, 102, 241, 0.45)` —
hardcoded indigo glow that, layered over a warm element, looks rosy.

## Findings & fixes

| # | File:line | Before | After | Notes |
|---|-----------|--------|-------|-------|
| 1 | `public/css/ke-public.css:2118` | `background: #6366f1;` | `background: var(--kep-accent-1, #6366f1);` | `.ke-soldout-badge--live` |
| 2 | `public/css/ke-public.css:2122` | `--color: #6366f1;` | `--color: var(--kep-accent-1, #6366f1);` | `.ke-soldout-circle` ring |
| 3 | `public/css/ke-public.css:2215` | `… 0 10px 22px rgba(99, 102, 241, 0.25);` | `… rgba(var(--kep-accent-rgb, 99, 102, 241), 0.25);` | lineup hover glow |
| 4 | `public/css/ke-public.css:2221` | `background: linear-gradient(135deg, #818cf8, #c084fc);` | `background: var(--kep-accent-grad, linear-gradient(135deg, #6366f1, #6366f1));` | lineup placeholder — was always purple, never followed accent |
| 5 | `public/css/ke-events-carousel.css:139` | `box-shadow: 0 4px 14px rgba(99, 102, 241, 0.45);` | `box-shadow: 0 4px 14px rgba(var(--kep-accent-rgb, 99, 102, 241), 0.45);` | `.ke-promo-pill` |

## Findings reviewed and intentionally left unchanged

| File:line | Value | Reason |
|-----------|-------|--------|
| `public/css/ke-public.css:30-32` | `--kep-accent-1: #6366f1; --kep-accent-2: #8b5cf6; --kep-accent-grad: …` | These are the **fallback defaults** at `:root`. The inline override from `KE_Public::inject_color_vars()` (also targeting `:root`) wins because it's loaded after via `wp_add_inline_style`. The defaults only apply if the admin has configured no accent at all. |
| `public/views/ticket-view.php:95,111` | hardcoded `linear-gradient(135deg,#6366f1,#8b5cf6)` | Lines 161-164 of the same file inject `!important` overrides with the user's configured accent, beating the hardcoded values. |
| `templates/email/admin-notification.php:22`, `includes/class-ke-email.php:337` | hardcoded `linear-gradient(135deg,#6366f1 0%,#8b5cf6 100%)` | Email styles. Many email clients strip CSS variables — keeping a literal hex is correct. |
| `admin/js/ke-event-builder.js:628` | `colors: ['#6366f1', '#8b5cf6', '#ec4899', '#10b981', '#f59e0b']` | Confetti palette for celebration animation. Not a button color. |
| `admin/css/ke-admin.css:36` | `--ke-rose: rgba(244, 63, 94, 0.10)` | Defined but unused (greppable with `var(--ke-rose)`). Harmless dead code. |
| `includes/class-ke-organizer-dashboard.php:136,153` | comments | Documentation, no color value. |

## Verification

1. Set `Kiwi Events → Settings → UI Settings → Accent color` to a warm hue, e.g. `#ef4444` (red).
2. Save, hard-refresh a page that uses `[kiwi_events]` (carousel) and an event extras page with a lineup or sold-out tracker.
3. Confirm the carousel promo pill shadow is now red-tinted (not indigo), the lineup placeholder gradient is solid red (not purple), and sold-out badge / ring are red.

## Inline override scope

`KE_Public::inject_color_vars()` injects `--kep-accent-*` on `:root,
.ke-event-page, .ke-sheet, .ke-sheet-overlay`. The two-stop gradient is
written as both stops = the chosen accent (flat color), so any element
using `var(--kep-accent-grad)` gets a flat color that follows the
configured accent.

The new `KE_Organizer_Public` and existing `KE_Organizer_Dashboard`
inject their own scoped overrides at `.ke-org-public` and `.ke-org-dash`
respectively, so those pages also follow the accent.

## Note re: Elementor / Hello theme

Per the spec: do **not** change colors in Elementor. The plugin's UI is
controlled entirely by `ke_ui_settings.accent_color`. Confirmed by
auditing every CSS file under `public/css/` and `admin/css/` — all
plugin UI follows that one source of truth via the `--kep-accent-*`
token chain.
