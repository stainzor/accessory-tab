# Accessory Tab for WooCommerce — SIJAB

## Overview
WooCommerce plugin that displays product accessories on the single product page with a professional, Dustin.se-inspired card design. Blue buttons, pill-shaped qty selectors, light blue section background.

- **Current version:** 2.27.0
- **GitHub repo:** `stainzor/accessory-tab` (private)
- **Test server:** test.sijab.com
- **Test product:** https://test.sijab.com/shop/adblue/adblue-1000-liter-ibc/
- **Theme:** Flatsome (requires aggressive `!important` CSS overrides)

## File Structure
```
accessory-tab/             ← plugin source (this gets zipped)
  accessory-tab.php        ← main plugin file, all PHP logic
  assets/
    css/frontend.css       ← all frontend styles
    js/frontend.js         ← toggle, qty buttons, variant select, mobile toggle, stats tracking
  vendor/                  ← Plugin Update Checker (PUC) library
  readme.txt
```

## Build & Deploy Workflow (CRITICAL)
Every change requires ALL these steps — the WordPress auto-updater won't see changes without a new version number.

1. **Edit code** in `accessory-tab/`
2. **Bump version** in BOTH places:
   - Plugin header: `* Version: X.X.X` (line ~6)
   - PHP constant: `const VERSION = 'X.X.X';` (line ~35)
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
4. **Push code + tag to GitHub:**
   ```bash
   cd /tmp/accessory-tab-push
   # Copy updated files, commit, tag vX.X.X, push
   ```
5. **Create GitHub release + upload asset** via Python urllib (gh CLI is NOT installed)
6. **Auto-updater (PUC)** picks up the new release — or manual upload via wp-admin

## Architecture

### PHP (accessory-tab.php)
- **Class:** `SIJAB_Tillbehor` — single class with all logic
- **Meta keys:**
  - `_sijab_accessories_ids` — array of product IDs on parent product
  - `_sijab_bundle_items` / `_sijab_is_bundle` — bundle support
  - `_sijab_acc_category_id` — optional category link
- **Settings:** `sijab_tillbehor_settings` option — layout, columns, max_visible, placement, title_format
- **Stats table:** `wp_sijab_acc_stats` — tracks add_to_cart, view_product, product_click events
- **Placement hooks:**
  - `before_tabs` → `woocommerce_after_single_product_summary` priority 7
  - `after_summary` → `woocommerce_single_product_summary` priority 35
- **Admin:**
  - Settings page: WooCommerce → Tillbehör (`admin.php?page=sijab-tillbehor`)
  - Product data tab "Tillbehör" with product search, SKU textarea, drag & drop sortable
  - Tabs: Statistik | Visning | API-inställningar | Verktyg | Om
- **Auto-updater:** Plugin Update Checker (PUC) library, authenticates with GitHub token stored in `sijab_tillbehor_github_token` option
- **Migration tool:** Under Verktyg tab — migrates from WooCommerce Product Bundles to `_sijab_accessories_ids`

### Variable Products (v2.25.x)
- Dropdown `<select class="sijab-var-select">` renders in `.sijab-acc-card__details` (after price)
- Each `<option>` carries `data-price-html`, `data-stock`, `data-stock-label`, `data-sku`, `data-purchasable`, `data-attributes`
- JS updates price, stock badge, SKU, and button state on variant change
- Custom AJAX handler `sijab_add_to_cart` for add-to-cart (with proper WC fragments via `woocommerce_mini_cart()`)
- Empty `<span class="sijab-acc-card__sku"></span>` always rendered for variable products (filled by JS)

### CSS (assets/css/frontend.css)
- **Section:** `.sijab-accessories-section` — `background: #eef5fa`, `border-radius: 8px`
- **Buttons:** `.sijab-acc-atc-btn.button` — `var(--primary-color, #1e73be)`, `border-radius: 99px`
- **Qty selector:** `.sijab-acc-card__qty` — pill-shaped, 36px height, `max-width: 100px`
- **Stock text:** `.sijab-acc-card__stock` — `text-transform: uppercase`, no dot/icon
- **Right column:** `.sijab-acc-card__right` — `min-width: 210px` desktop, `unset` mobile
- **Variant select:** `max-width: 200px`, `height: 30px`, `font-size: 12px`
- **Mobile (≤600px):** Shows only 1st accessory, "Visa fler" toggle, names wrap instead of truncate

### JS (assets/js/frontend.js)
- Desktop "Visa alla tillbehör" toggle (class `sijab-show-all`)
- Mobile "Visa fler tillbehör" toggle (class `sijab-show-all-mobile`, injected via JS)
- Qty +/- buttons with data-quantity sync to add-to-cart link
- Variable product: variant change → update price, stock, SKU, button state
- Variable product: AJAX add-to-cart with WC fragment refresh
- Statistics tracking via sendBeacon (add_to_cart, view_product, product_click)

## Flatsome Theme — Known Issues
- **Never use `single_add_to_cart_button` class** — causes layout side-effects (moves button left)
- Flatsome adds `margin-bottom: 13px` to `.button` — override with `margin: 0 !important`
- Flatsome overrides all input/button widths — always use `!important` on sizing
- CSS cache-busting depends on `const VERSION` matching plugin header version
- LiteSpeed Cache on server — sometimes needs manual purge after plugin update

## Known TODO
- **Variable products in bundles** — not yet implemented
- **Bundle pricing calculator** — added in v2.25.x but needs testing
