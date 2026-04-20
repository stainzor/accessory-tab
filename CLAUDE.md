# Accessory Tab for WooCommerce — SIJAB

## Overview
WooCommerce plugin that displays product accessories on the single product page. Supports five layouts, popup-driven required-companion rules, and tight integration with Svea Checkout + Visma.net via Sharespine.

- **Current version:** 2.32.6
- **GitHub repo:** `stainzor/accessory-tab` (private)
- **Test server:** test.sijab.com
- **Production:** sijab.com
- **Test product (cards):** https://test.sijab.com/shop/adblue/adblue-1000-liter-ibc/
- **Test product (horizontal, with popup-companion config):** https://test.sijab.com/shop/tankar/bransletankar/mobil-dieseltank-300-liter-deso/
- **Theme:** Flatsome (requires aggressive `!important` CSS overrides)

## File Structure
```
accessory-tab/             ← plugin source (gets zipped)
  accessory-tab.php        ← main plugin file, all PHP logic
  assets/
    css/frontend.css       ← all frontend styles
    js/frontend.js         ← toggles, qty buttons, variant select, popup modal, stats tracking
  vendor/                  ← Plugin Update Checker (PUC) library
  readme.txt
  CLAUDE.md                ← this file
```

## Build & Deploy Workflow (CRITICAL)

### Where to edit
- **Canonical working tree:** `/tmp/acc-push/accessory-tab/` (a clean clone of `stainzor/accessory-tab` on main). Edit here, commit, push directly from this directory.
- **Do NOT edit `G:\Min enhet\N8N\Cursor-projekt\sijab-tillbehor-tab\`** — that folder's git state is confused (outer repo's origin points at accessory-tab but tree layout doesn't match remote). Pushing from there breaks.
- The Zip file goes to `G:\Min enhet\N8N\Cursor-projekt\sijab-tillbehor-tab\accessory-tab-X.X.X.zip` (for upload via wp-admin).

### Every change requires ALL these steps
1. Edit code in `/tmp/acc-push/accessory-tab/`
2. Bump version in BOTH places:
   - Plugin header: `* Version: X.X.X` (line ~6)
   - PHP constant: `const VERSION = 'X.X.X';` (line ~36)
3. Commit to main + push:
   ```bash
   cd /tmp/acc-push/accessory-tab
   git -c user.email="..." -c user.name="..." commit -am "vX.X.X: ..."
   git push origin main
   ```
4. Build zip (Python, NOT PowerShell) to the windows zip path:
   ```python
   import zipfile, os
   version = 'X.X.X'
   zname = f'G:/Min enhet/N8N/Cursor-projekt/sijab-tillbehor-tab/accessory-tab-{version}.zip'
   with zipfile.ZipFile(zname, 'w', zipfile.ZIP_DEFLATED) as zf:
       for root, dirs, files in os.walk('accessory-tab'):
           if '.git' in root.split(os.sep): continue
           for f in files:
               fp = os.path.join(root, f)
               zf.write(fp, fp)
   ```
   (cwd should be `/tmp/acc-push` so the zip contains `accessory-tab/…` paths)
5. Create GitHub release + upload asset via Python `urllib` (gh CLI NOT installed):
   - `tag_name: f'v{version}'`, `draft: False`, `prerelease: False`
6. Upload zip to wp-admin → plugin-install.php → "Ersätt nuvarande"

### GitHub token (API releases + auth)
Stored in user's Claude memory file (`MEMORY.md`), NOT in source code. Never commit the token — GitHub push-protection will reject the push.

### Auto-updater behavior (PUC)
- Plugin Update Checker reads latest non-draft release from GitHub
- Downloads the attached asset (zip) for installation
- On private repo: token MUST be configured at **WooCommerce → Tillbehör → Verktyg** (field `sijab_tillbehor_github_token`). If empty, PUC 404s silently and reports "no update".
- 12h cache: force re-check via `wp-admin/update-core.php?force-check=1` or per-plugin "Sök efter uppdateringar" link

## Architecture

### PHP (accessory-tab.php)
- **Class:** `SIJAB_Tillbehor` — single class
- **Key constants:**
  - `META_KEY = '_sijab_accessories_ids'`
  - `BUNDLE_META = '_sijab_bundle_items'`, `BUNDLE_FLAG = '_sijab_is_bundle'`
  - `REQ_META = '_sijab_accessory_requirements'` (v2.32.0+)
  - `STATS_TABLE = 'sijab_acc_stats'` (`wp_sijab_acc_stats`)
- **Settings:** `sijab_tillbehor_settings` option — layout, columns, max_visible, placement, title_format

### Stock display helper (v2.31.8+)
`get_stock_display(WC_Product $p): [status, label]` — uses `$p->get_availability()` which runs `woocommerce_get_availability_text` + `woocommerce_get_availability` filters. Third-party plugins that override WC's default "Beställningsvara" to e.g. "Leveranstid 1-3 dagar" now flow through to accessory rows. Fallback keeps Swedish defaults if filter returns empty. Use `get_variation_stock_label($v)` for variant dropdown options.

### Popup-companions (v2.32.x)
Feature: admin configures `(accessory → required product)` rules per main product. When customer checks/clicks the accessory, a modal appears asking them to also add the required product.

- **Meta on MAIN product:** `_sijab_accessory_requirements = [['accessory_id'=>X, 'requires'=>[['product_id'=>Y, 'qty'=>1]]]]`
- **Admin UI:** "Tillbehörs-kombinationer som kräver mer" section in product-data Tillbehör tab
- **Frontend:** `emit_companion_meta($main)` outputs `window.sijabCompanions[mainId]` and `window.sijabMainProductInfo[mainId]` globals
- **Checkbox markup:** `data-has-companions="1"` + `data-main-product` attributes on accessory checkboxes and `.sijab-acc-atc-btn` buttons that have configured rules
- **JS popup:** `buildCompanionModal(accessoryName, mainName, companions, onAccept, onReject, opts={selfInfo, mainInfo})` — renders rows with role badges (Huvudprodukt/Ditt val/Krävs för att passa)
- **Cards/checklist flow:** popup commits companions to `sijabPendingCompanions[accId]` → bundle CTA adds main+accessory+companions via `sijab_bundle_add_to_cart`
- **Horizontal/grid/compact flow:** popup directly triggers `sendHorizontalBundleAdd()` which posts items to `sijab_bundle_add_to_cart`. Main product included with `skip_if_in_cart: true` flag — server dedups against current cart.

### Bundle → Order: component lines (v2.31.11+)
**CRITICAL** — previous destructive remove+re-add pattern broke Svea/Sharespine callbacks and left paid orders stuck in "Behandlas". Now:

- Hook ONLY on `woocommerce_order_status_completed` priority 20 (not processing, not payment_complete — those race with Svea)
- Non-destructive: only **append** component lines; existing items + their meta (`_svea_co_cart_key`, etc.) untouched
- Idempotent via `_sijab_bundle_components_added` flag
- Stock-protect: `woocommerce_order_item_quantity` returns 0 for items with `_sijab_bundled_by` meta

### Hidden internal meta (v2.31.11+)
`_sijab_acc_parent`, `_sijab_bundled_by`, `_sijab_bundle_components_added` are hidden from admin order UI + customer emails via `woocommerce_hidden_order_itemmeta` filter.

### Admin panel (`SIJAB_Tillbehor::render_admin_page`)
Single unified page at `admin.php?page=sijab-tillbehor` with JS tabs (no reloads):
- **Statistik** (default) — purchases/ATC/views/clicks grouped per parent product. Right-aligned numeric columns with tabular-nums (v2.31.12+).
- **Visning** — layout radio, columns, max_visible, placement, title_format
- **API-inställningar** — OpenAI key (for AI-suggest), GitHub token
- **Verktyg** — Bulk-set, Migration tool, Bundle products
- **Om**

### Product-data admin tab (Tillbehör)
In product edit page, under WooCommerce product data panel:
- Product search (wc-product-search, Select2 + json_search_products_and_variations)
- SKU textarea (comma-separated)
- Drag & drop sortable list of added accessories
- **NEW v2.32.0:** "Tillbehörs-kombinationer som kräver mer" section for popup-companions
- Category link
- AI-suggest button (if OpenAI key configured)

### Admin "Lägg till" flow (v2.31.9+)
When admin adds accessory via product search, we AJAX-fetch the real thumbnail via `sijab_get_product_thumb` endpoint so the image appears instantly (instead of placeholder stuck until page save).

## Layout Options
Configured in **WooCommerce → Tillbehör → Visning → Layout**:

| Layout | Value | Description | Popup support |
|--------|-------|-------------|:---:|
| Horisontell | `horizontal` | Dustin-stil, rad per tillbehör med bild/pris/qty/LÄGG TILL | ✅ |
| Bildrutnät | `grid` | Stora kort, kolumner | ✅ |
| Kompakt lista | `compact` | En rad per tillbehör, utan qty-väljare | ✅ |
| Checklista | `checklist` | Kryssrutor, kategorigrupper, ovanför huvud-LÄGG TILL | ✅ |
| Bundle Cards | `cards` | "Bygg ditt paket" med 'Lägg paket i varukorgen'-CTA | ✅ |

**Body class:** `body.sijab-layout-<value>` (v2.31.6+) — used for layout-scoped CSS (e.g. hiding default WC add-to-cart button in cards layout).

### Cards layout (v2.31.6+) specifics
- Default WC add-to-cart button + qty selector hidden (`form.cart > .single_add_to_cart_button, form.cart > .quantity { display: none }`)
- Single `.kr-bundle-cta` button replaces them
- Dynamic label: 0 accessories → "Lägg i varukorgen"; 1+ → "Lägg paket i varukorgen (N produkter)"
- `.kr-bundle-summary` with `[data-empty]` attribute: hides breakdown when nothing checked

### "Visa alla tillbehör" link (v2.31.10+)
Outline pill button matching the filled LÄGG TILL style — pill shape, primary color border/text, transparent fill, inverts on hover.

## AJAX endpoints
- `sijab_add_to_cart` — single item (used by horizontal/grid/compact direct LÄGG TILL)
- `sijab_bundle_add_to_cart` (v2.31.6+) — atomic batch: `items: [{product_id, quantity, variation_id, attributes, parent_id, skip_if_in_cart}]`
- `sijab_suggest_accessories` — OpenAI AI-suggest
- `sijab_save_acc_category` — quick category save
- `sijab_get_product_prices` — bulk price fetch
- `sijab_bulk_set_accessories` — bulk tool
- `sijab_get_product_thumb` (v2.31.9) — real thumbnail for admin add-flow
- `sijab_acc_track` — stats tracking beacon

## Flatsome Theme — Known Issues
- **Never use `single_add_to_cart_button` class** — causes layout side-effects (moves button left)
- Flatsome adds `margin-bottom: 13px` to `.button` — override with `margin: 0 !important`
- Flatsome overrides all input/button widths — always use `!important` on sizing
- CSS cache-busting depends on `const VERSION` matching plugin header version
- LiteSpeed Cache on server — sometimes needs manual purge after plugin update
- WC panel CSS force-floats all `<label>` elements (`float:left; width:150px`). Use `<p class="form-field sijab-ff"><label></label><span class="sijab-field-wrap">...</span></p>` pattern or labels escape your custom containers.

## Recent version history (session ending 2026-04-20)
- **2.31.6** — Cards layout: hide default WC button + qty
- **2.31.7** — Cards: dynamic CTA label, hide summary when 0 accessories
- **2.31.8** — Filter-aware stock text via `get_availability()`
- **2.31.9** — Admin: real thumb fetch on add
- **2.31.10** — "Visa alla tillbehör" outline pill button
- **2.31.11** — 🔴 **Critical Svea/Visma fix:** stop destructive order mutation (see "Bundle → Order" above)
- **2.31.12** — Stats table: right-align numeric columns
- **2.32.0** — Popup-companions feature (cards/checklist initial)
- **2.32.1** — Admin UI label alignment (form-field pattern)
- **2.32.2** — Popup support on horizontal/grid/compact
- **2.32.3** — Horizontal popup auto-includes main product (with skip_if_in_cart dedup)
- **2.32.4** — Popup transparency: show all items with role badges (Huvudprodukt / Ditt val / Krävs för att passa)
- **2.32.5** — Fix: variable main products silently dropped from popup batch-add (missing variation_id). Now pass variation_id + attributes from form.cart state, or skip main if no variation chosen.
- **2.32.6** — Popup row shows variation details when variable main (name appended, exact variant price incl. rea, variant stock, variant image). Data from `form.variations_form[data-product_variations]`.

## Pending / Future
- Reservdelar (spare parts) list — designed but not built (would be v2.33.0)
- Cards-layout CTA counter doesn't include pending companions (shows "2 produkter" when actually 3 going in)
- Variable products in bundles — not yet implemented
- Local Cursor-projekt git structure still confused (use /tmp/acc-push/accessory-tab/ as canonical)
- test.sijab.com fortfarande v2.32.4 — prod (sijab.com) är v2.32.6

## Continuation guide (for resumption from a fresh session)
1. Read this file + user's memory at `C:\Users\<user>\.claude\projects\G--Min-enhet-N8N-Cursor-projekt\memory\accessory-tab-plugin.md`
2. Canonical working tree is `/tmp/acc-push/accessory-tab/` — edit, commit, push from there only
3. Token `ghp_…` lives in `MEMORY.md` — never commit to source (GitHub push-protection blocks)
4. Current focus: popup-companions feature is complete across all 5 layouts incl. variable main products
5. Next likely feature: reservdelar list (v2.33.0) or resolve pending TODO items above
