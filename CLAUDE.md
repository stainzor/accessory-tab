# Accessory Tab for WooCommerce — Claude context

## Repo
https://github.com/stainzor/accessory-tab
Lokalt: `G:/Min enhet/Claude-projekt/accessory-tab-repo`

## Nuvarande version
2.16.0 — Author: HB

## Konstanter (accessory-tab.php)
```php
const META_KEY    = '_sijab_accessories_ids';   // manuella tillbehörs-IDs
const BUNDLE_META = '_sijab_bundle_items';       // paketinnehåll
const BUNDLE_FLAG = '_sijab_is_bundle';          // om produkten är ett paket
const VERSION     = '2.16.0';
const OPTION      = 'sijab_tillbehor_settings';  // layoutinställningar
```

## Features
- **Tillbehörssektion** — hämtar från META_KEY + WC korsförsäljning (`get_cross_sell_ids()`)
- **Variabla tillbehör** — dropdown med varianter, pris/lager uppdateras via JS, AJAX add-to-cart
- **Paketprodukter** — "Paket"-flik i produktredigeraren, frontend under add-to-cart (priority 35)
- **AI-generering** — OpenAI gpt-4o genererar titel/beskrivning/kollage för paket
- **Inställningar** — tre flikar: Visning / API-inställningar / Verktyg
- **Auto-updater** — Plugin Update Checker v5p6 mot detta repo

## Releaseprocess
```bash
# 1. Bumpa version på TVÅ ställen i accessory-tab.php:
#      * Version: X.Y.Z   (plugin header)
#      const VERSION = 'X.Y.Z';

# 2. Commit + tag + push
git add -A
git commit -m "Beskrivning (vX.Y.Z)"
git tag vX.Y.Z
git push origin main --tags

# 3. Skapa zip — ALLTID git archive, aldrig PowerShell Compress-Archive
#    (PowerShell ger backslash-sökvägar som kraschar på Linux/WordPress)
git archive --format=zip --prefix=accessory-tab/ HEAD -o /tmp/accessory-tab-X.Y.Z.zip

# 4. GitHub release
gh release create vX.Y.Z /tmp/accessory-tab-X.Y.Z.zip --title "vX.Y.Z - Beskrivning" --notes "..."
```

## Filer
```
accessory-tab.php       Allt PHP (klassen SIJAB_Tillbehor)
assets/css/frontend.css Frontend-stilar
assets/js/frontend.js   Frontend-JS (toggle, qty, varianter, AJAX)
vendor/                 Plugin Update Checker v5p6
```

## WordPress-alternativ som används
- `wc_get_product()`, `get_cross_sell_ids()`, `get_available_variations()`
- `wp_ajax_*` hooks för AJAX
- `wp_remote_post()` för OpenAI API-anrop
- `wp_insert_attachment()` + `wp_generate_attachment_metadata()` för GD-kollage
- Plugin Update Checker mot GitHub releases med token-auth
