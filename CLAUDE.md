# Accessory Tab for WooCommerce — SIJAB

## Overview
WooCommerce plugin that displays product accessories on the single product page with a professional, Dustin.se-inspired card design. Blue buttons, pill-shaped qty selectors, light blue section background.

- **Current version:** 2.11.0
- **GitHub repo:** `stainzor/sijab-tillbehor-tab` (private)
- **Test server:** test.sijab.com
- **Test product:** https://test.sijab.com/shop/adblue/adblue-1000-liter-ibc/
- **Theme:** Flatsome (requires aggressive `!important` CSS overrides)

## File Structure
```
sijab-tillbehor-tab/
  CLAUDE.md              ← this file
  accessory-tab/         ← plugin source (this gets zipped)
    accessory-tab.php    ← main plugin file, all PHP logic
    assets/
      css/frontend.css   ← all frontend styles
      js/frontend.js     ← toggle, qty buttons, mobile toggle
    vendor/              ← Plugin Update Checker (PUC) library
    readme.txt
```

## Build & Deploy Workflow (CRITICAL)
Every change requires ALL these steps — the WordPress auto-updater won't see changes without a new version number.

1. **Edit code** in `accessory-tab/`
2. **Bump version** in BOTH places:
   - Plugin header: `* Version: X.X.X` (line ~6)
   - PHP constant: `const VERSION = 'X.X.X';` (line ~33)
3. **Build zip** with Python (not PowerShell):
   ```python
   python -c "
   import zipfile, os
   version = 'X.X.X'
   with zipfile.ZipFile(f'accessory-tab-{version}.zip', 'w', zipfile.ZIP_DEFLATED) as zf:
       for root, dirs, files in os.walk('accessory-tab'):
           for f in files:
               fp = os.path.join(root, f)
               zf.write(fp, fp)
   "
   ```
4. **Create GitHub release + upload asset** via Python urllib (gh CLI is NOT installed):
   ```python
   token = '*** stored in Claude memory, not in code ***'
   repo = 'stainzor/accessory-tab'
   # Create release, then upload zip as asset
   ```
5. **Upload to WordPress** at test.sijab.com/wp-admin/plugin-install.php → Ladda upp tillägg → Välj fil → Installera nu → Ersätt nuvarande

## Architecture

### PHP (accessory-tab.php)
- **Class:** `SIJAB_Tillbehor` — single class with all logic
- **Meta key:** `_sijab_accessories_ids` — array of product IDs on parent product
- **Settings:** `sijab_tillbehor_settings` option — layout, columns, max_visible, placement, title_format
- **Placement hooks:**
  - `before_tabs` → `woocommerce_after_single_product_summary` priority 7
  - `after_summary` → `woocommerce_single_product_summary` priority 35
- **Admin:** WooCommerce product data tab "Tillbehör" with:
  - Product search (wc-product-search)
  - SKU textarea (comma-separated)
  - Drag & drop sortable list (jQuery UI Sortable)
- **Auto-updater:** Plugin Update Checker (PUC) library, authenticates with GitHub token stored in `sijab_tillbehor_github_token` option

### CSS (assets/css/frontend.css)
- **Section:** `.sijab-accessories-section` — `background: #eef5fa`, `border-radius: 8px`
- **Buttons:** `.sijab-acc-atc-btn.button` — `var(--primary-color, #1e73be)`, `border-radius: 99px`
- **Qty selector:** `.sijab-acc-card__qty` — pill-shaped, 36px height, `max-width: 100px`
- **Stock text:** `.sijab-acc-card__stock` — `text-transform: uppercase`, no dot/icon
- **Right column:** `.sijab-acc-card__right` — `min-width: 210px` desktop, `unset` mobile
- **Mobile (≤600px):** Shows only 1st accessory, "Visa fler" toggle, names wrap instead of truncate

### JS (assets/js/frontend.js)
- Desktop "Visa alla tillbehör" toggle (class `sijab-show-all`)
- Mobile "Visa fler tillbehör" toggle (class `sijab-show-all-mobile`, injected via JS)
- Qty +/- buttons with data-quantity sync to add-to-cart link

## Flatsome Theme — Known Issues
- **Never use `single_add_to_cart_button` class** — causes layout side-effects (moves button left)
- Flatsome adds `margin-bottom: 13px` to `.button` — override with `margin: 0 !important`
- Flatsome overrides all input/button widths — always use `!important` on sizing
- CSS cache-busting depends on `const VERSION` matching plugin header version
- LiteSpeed Cache on server — sometimes needs manual purge after plugin update

## Variable Products
- Variable/grouped products show "Visa produkt" button instead of "Lägg till" + qty
- Same button styling (`.sijab-acc-atc-btn.button`), just links to product page
- May lack SKU (only variations have individual SKUs)

## Settings Page
Admin: WooCommerce → Tillbehör (or via `admin.php?page=sijab-tillbehor-settings`)
- Placement: before_tabs / after_summary
- Layout: horizontal (list) / grid
- Columns (grid): 2-5
- Max visible: how many before "Visa alla" link (desktop)
- Title format: with_name / simple / custom
