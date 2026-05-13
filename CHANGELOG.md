

# Changelog

## [1.2.0] - 2026-05-12

> **Deployment:** `composer install && php bin/console cache:clear`

### Behoben (kritische Latent-Bugs)
- **PHPStan deckte fehlende `shopware/storefront`-Composer-Dep auf** — Plugin nutzt `CheckoutCart/Register/Confirm/FinishPageLoadedEvent` aus dem Storefront-Bundle, hatte es aber nicht als Composer-`require` deklariert. PHPStan zeigte 21 `class.notFound`-Errors. Behoben durch Aufnahme von `shopware/storefront: ~6.7.0 || ~6.8.0` in `require`.
- **`match`-Expression hatte unreachable `default`-Case** — alle 4 Event-Klassen sind im `event`-Type-Hint enthalten, daher ist `default => 1` unerreichbar. Entfernt.
- **`ConfigService` war `final` deklariert, aber Test mockt die Klasse** — `PHPUnit\Framework\MockObject\Generator\ClassIsFinalException` in 7 Tests. `final` entfernt; Konvention testing.md (eigene Fakes) bleibt erstrebenswert, aber pragmatischer Sofortfix.

### Geaendert (composer.json)
- Version 1.1.0 → 1.2.0
- `php >=8.2`-Constraint expliziert
- `shopware/storefront`-Dep ergaenzt
- `config.allow-plugins` mit `symfony/runtime: true` (Voraussetzung fuer non-interactive `composer install`)
- `scripts.quality` als Aggregat (cs-check + phpstan + test) ergaenzt
- Skripte verwenden `vendor/bin/...` (Windows-portabel)

### Quality-Gates verifiziert
- PHP CS Fixer: 0 Verstoesse
- PHPStan Level 8: 0 Errors (vorher 21)
- PHPUnit: 24 Tests, 63 Assertions (vorher 7 Errors)
- composer audit: 0 Advisories

### Suite-Vorbereitung (Phase 4.4)
- Plugin ist Vorbild fuer das `Module/ProgressBar`-, `Module/TrustBadges`- und `Module/OrderSummary`-Sub-Modul der `RcCheckoutSuite` v1.0.0. Code wird per Namespace-Patch in die Suite uebertragen.

## [1.1.0] - 2026-04-01

> **Deployment:** `bin/console theme:compile` erforderlich (SCSS-Änderungen)

### Hinzugefügt
- Unit-Tests für ConfigService und CheckoutSubscriber (23 Testfälle)
- PHPUnit-Konfiguration

### Behoben
- Admin-Konfiguration zeigt jetzt korrekt deutsche Texte bei deutscher Spracheinstellung
- Confirm-Template: Sidebar-Layout kollidiert nicht mehr mit anderen Plugins

### Verbessert
- Sidebar-Layout nutzt eigene BEM-Klassen statt Shopware-interne Klassenabhängigkeit
- Config-HelpTexte bei allen Progress-Steps konsistent ergänzt
- Icon "star" in Trust-Badges-HelpText dokumentiert

## [1.0.0] - 2026-03-31

> **Deployment:** `bin/console theme:compile` erforderlich (Erstinstallation)

### Hinzugefügt
- Checkout Progress-Bar mit 4 Schritten (Cart → Adresse → Bestellen → Fertig)
- Vertrauenssignale mit konfigurierbaren Texten und Icons
- Mini-Warenkorbübersicht als Sidebar auf der Confirm-Seite
- Bestellzusammenfassung (Adresse, Versand, Zahlung) mit "Ändern"-Links
- Optionale Lieferzeitschätzung
- Backend-Konfiguration: Alle Features einzeln an/aus
- Zweisprachig: de-DE + en-GB
