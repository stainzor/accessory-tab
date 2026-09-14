Accessory Tab for WooCommerce

v2.34.0
- Fix: montering (radioknappar + ARB-rad) fungerade inte i Bundle Cards-layouten
- Fix: monteringsrad kunde laggas till utan sitt tillbehor (t.ex. slut i lager)
- Fix: "Paket forst"-sortering dolde produkter utan paket-flagga
- Fix: masslagg per kategori / migrering / aterstallning tar nu backup och skriver via WC-produktobjektet
- Nytt: montering inkluderas aven pa horisontell/grid/kompakt utan popup-regler; popup-flodet tar med antal och vald variant; variabla tillbehor stods
- Fix: JS-fel i produktredigeraren, fel nonce vid "Lagg till montering", SKU-falt accepterar radbrytning/semikolon
- Fix: statistik-tidsfonster i ratt tidszon, monteringsrader raknas inte som tillbehorskop, spamskydd pa sparnings-endpointen
- Fix: companion-antal bevaras, monteringspris visas inkl/exkl moms enligt butiksinstallning
- HPOS-kompatibilitet deklarerad, dod kod borttagen

v2.4.0
- Nytt mappnamn: accessory-tab (ersatter sijab-tillbehor-tab-1.2.0)
- Nytt pluginnamn: Accessory Tab for WooCommerce
- GitHub-token-falt i installningar for automatiska uppdateringar fran privat repo
- Pluginversion visas pa installningssidan

v2.3.0
- GitHub auto-updater via plugin-update-checker
- Automatiska uppdateringar fran GitHub releases

v2.0.0
- Tillbehor visas nu direkt pa produktsidan (ovanfor flikarna) istallet for i en dold flik
- Dustin-inspirerad kortlayout med bild, namn, SKU, pris, lagerstatus och "Lagg till"-knapp
- AJAX add-to-cart (via WooCommerce inbyggda ajax_add_to_cart)
- Extern CSS-fil istallet for inline styles
- Responsiv design (4 kort desktop, 2 kort mobil)
- Installningar: placering, rubrikformat, antal kolumner
- Kvantitetsväljare (+/- knappar) for enkla produkter
- Befintliga tillbehorskopplingar behalls (samma meta-nyckel)
