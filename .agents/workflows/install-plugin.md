---
description: How to build, install, and configure the KiwiEvents WordPress plugin
---

# KiwiEvents — Install & Setup Workflow

## Prerequisites

1. A running WordPress site (local or remote)
2. WooCommerce plugin installed and activated (required for paid tickets)
3. PHP 7.4+ and MySQL 5.7+

---

## Step 1: Install Composer Dependencies

If the plugin uses Composer libraries (e.g., for PDF/QR generation), install them first:

// turbo
```bash
cd /Users/richardmosqu/Desktop/kiwi-events && composer install --no-dev
```

> If `composer` is not installed, run: `brew install composer`

---

## Step 2: Create the Plugin ZIP

// turbo
```bash
cd /Users/richardmosqu/Desktop && zip -r kiwi-events.zip kiwi-events/ -x "*.DS_Store" -x "*/.git/*" -x "*/.agents/*"
```

This creates `kiwi-events.zip` on your Desktop, excluding unnecessary files.

---

## Step 3: Upload to WordPress

**Option A — Via WordPress Admin (recommended):**
1. Go to `https://yoursite.com/wp-admin`
2. Navigate to **Plugins → Add New → Upload Plugin**
3. Click **Choose File** and select `~/Desktop/kiwi-events.zip`
4. Click **Install Now**
5. Click **Activate**

**Option B — Via FTP / File Manager:**
1. Upload the entire `kiwi-events/` folder to `/wp-content/plugins/`
2. Go to **Plugins** in WordPress admin
3. Find **KiwiEvents** and click **Activate**

**Option C — Local development (symlink):**
```bash
ln -s /Users/richardmosqu/Desktop/kiwi-events /path/to/wordpress/wp-content/plugins/kiwi-events
```
Then activate from WP admin. Changes to your Desktop folder reflect immediately.

---

## Step 4: Verify Activation

After activation, confirm:
- [ ] **KiwiEvents** menu appears in the WordPress admin sidebar
- [ ] Database tables are created (check `wp_kiwi_tickets`, `wp_kiwi_attendees` etc.)
- [ ] No errors in **Tools → Site Health** or PHP error logs

---

## Step 5: Configure WooCommerce (for paid tickets)

1. Go to **WooCommerce → Settings → General** and set your currency
2. Configure a payment gateway (Stripe, PayPal, etc.) under **WooCommerce → Settings → Payments**
3. KiwiEvents will automatically create WooCommerce products when you add paid ticket types

---

## Step 6: Create Your First Event

1. Go to **KiwiEvents → Add New Event**
2. Fill in event details: title, date/time, location, description, featured image
3. Add ticket types (free or paid) in the **Ticket Types** meta box
4. Set category via **Event Categories** taxonomy
5. Click **Publish**

---

## Step 7: Verify the Frontend

1. Visit the single event page on your site
2. Confirm the Luma-inspired design loads correctly (hero, ticket cards, checkout modal)
3. Test a ticket purchase flow end-to-end
4. Check that the confirmation email with QR code is sent

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Plugin won't activate | Check PHP version ≥ 7.4 and WooCommerce is active |
| Styles not loading | Flush permalinks: **Settings → Permalinks → Save** |
| QR codes not generating | Ensure GD or Imagick PHP extension is enabled |
| Emails not sending | Install an SMTP plugin (e.g., WP Mail SMTP) |
| 404 on event pages | Flush permalinks: **Settings → Permalinks → Save** |
