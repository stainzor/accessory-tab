Accessory Tab for WooCommerce

v2.17.0
- Ny "Statistik"-flik under Tillbehör-inställningar
- Spårar tillbehörsklick: Lägg i varukorg, Visa produkt, Produktklick (namn/bild)
- Daglig trendgraf, topplistor för tillbehör och produkter
- Periodfilter: 7 dagar, 30 dagar, 90 dagar, 1 år
- navigator.sendBeacon tracking (non-blocking, påverkar ej sidladdning)
- Custom DB-tabell med daglig cleanup (1 års datalagring)
- Automatisk DB-uppgradering utan omaktivering

v2.16.0
- Variabla produkter: dropdown för att välja variant direkt i tillbehörskortet
- AJAX add-to-cart för varianter (utan sidladdning)
- Pris och lagerstatus uppdateras dynamiskt vid variantval

v2.15.0 - v2.15.6
- AI-generering av paketproduktinnehåll (OpenAI-integration)
- Inställningssida med flikar: Visning, API-inställningar, Verktyg
- Paketprodukter (bundle) - visa ingående produkter på produktsidan
- Collage-bildgenerering med WebP-stöd
- Diverse buggfixar för layout och CSS

v2.12.0 - v2.12.2
- Automatisk hämtning av korsförsäljningsprodukter som tillbehör
- Migrering inbyggd i inställningssidan
- Stöd för variabla produkters korsförsäljning

v2.11.0 - v2.11.1
- Ljusblå sektionsdesign (Dustin-inspirerad)
- Drag and drop sortering i admin
- Mobil "Visa fler"-toggle (visar 1 tillbehör, resten dolda)
- Dämpad prisfont på tillbehörskort

v2.4.0
- Nytt mappnamn: accessory-tab (ersätter sijab-tillbehor-tab-1.2.0)
- Nytt pluginnamn: Accessory Tab for WooCommerce
- GitHub-token-fält i inställningar för automatiska uppdateringar från privat repo
- Pluginversion visas på inställningssidan

v2.3.0
- GitHub auto-updater via plugin-update-checker
- Automatiska uppdateringar från GitHub releases

v2.0.0
- Tillbehör visas nu direkt på produktsidan (ovanför flikarna) istället för i en dold flik
- Dustin-inspirerad kortlayout med bild, namn, SKU, pris, lagerstatus och "Lägg till"-knapp
- AJAX add-to-cart (via WooCommerce inbyggda ajax_add_to_cart)
- Extern CSS-fil istället för inline styles
- Responsiv design (4 kort desktop, 2 kort mobil)
- Inställningar: placering, rubrikformat, antal kolumner
- Kvantitetsväljare (+/- knappar) för enkla produkter
- Befintliga tillbehörskopplingar behålls (samma meta-nyckel)
