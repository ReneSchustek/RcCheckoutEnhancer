
# Changelog

## [1.2.4] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`. **Vor Live-Deploy Confirm-Seite im Browser prüfen** (Mini-Cart + Order-Summary sichtbar).

### Behoben

- **Mini-Cart & Order-Summary erscheinen wieder auf der Bestätigungsseite:** Das Plugin überschrieb `page_checkout_confirm_container` — einen Block, den der Storefront-Core in keiner unterstützten Version kennt → der Sidebar-Override war ein stiller No-Op, die zwei Features renderten nie. Jetzt wird der real existierende Core-Block `page_checkout_confirm` umschlossen (`{{ parent() }}` erhalten). 4 Pinning-Tests sichern gegen Rückfall.
- **BFSG/WCAG 2.2 AA:** Der aktive Fortschritts-Schritt trägt jetzt `aria-current="step"`, einen visually-hidden Status („aktueller Schritt"/„abgeschlossen") und ein `aria-hidden`-Glyph — Screenreader-Nutzer erfahren ihren Standort.
- **Order-Summary-Sichtbarkeit entkoppelt:** Die Sidebar erscheint bei `miniCartEnabled` **oder** `orderSummaryEnabled`; jede Komponente prüft weiter ihr eigenes Flag (vorher schaltete das Mini-Cart-Flag die Bestellübersicht mit ab).

## [1.2.3] - 2026-05-13 — Build-Hygiene

> **Deployment:** Kein Live-Eingriff. Reines Repo-Cleanup.

### Geaendert
- `composer.json`: kosmetischer `extra.audit.ignore`-Block entfernt. Composer liest Ignore-Regeln aus `config.audit.ignore`, nicht aus `extra` — der Block hatte nie eine Wirkung und war Muell.
- `.gitignore`: `composer.lock` ergaenzt (Library-Plugins haben keinen committed Lock, `php.md:101`). Der Lock war hier zwar nie tracked, der Eintrag schliesst die Luecke praeventiv.

## [1.2.2] - 2026-05-13 — Hotfix

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`

### Behoben (kritisch)
- **ERR_TOO_MANY_REDIRECTS fuer Gast-Sessions in v1.2.1.** Die in v1.2.1 eingefuehrte Verlinkung von Step 2 auf `frontend.account.address.page` funktioniert nur fuer echte Kunden. Gaeste haben in Shopware keinen Zugriff auf das Konto -- Shopware leitet sie zur Login-Page, die sie als "schon eingeloggt" zurueck zu `/account/address` schickt -> Redirect-Loop.
- Korrigiert: Step 2 wird bei Gast-Sessions NICHT mehr verlinkt (Span statt Anchor). Der Gast aendert seine Adresse weiter ueber die Inline-Edit-Buttons auf der Confirm-Page. Echte Kunden (eingeloggt, `customer.guest = false`) bekommen weiterhin `frontend.account.address.page`.

## [1.2.1] - 2026-05-13 — zurueckgezogen

> **Hinweis:** Diese Version wurde durch v1.2.2 ersetzt, weil der Link bei Gaesten eine Redirect-Schleife erzeugte. Nicht einsetzen.

### Behoben
- **Step 2 der Progressbar leitete eingeloggte Sessions ins Leere.** Der Link zeigte auf `frontend.checkout.register.page`. Diese Route leitet aber bei jeder aktiven Session weiter -- bei eingeloggten Kunden zum Confirm, bei Gaesten auf die "Gastsitzung beenden"-Seite. Damit konnte ein Kunde aus dem Confirm-Step nicht mehr zurueck zu seinen Adressen, um sich z.B. zu vertippen.
- Ab v1.2.1 erkennt das Template eingeloggte Sessions (Gast + Kunde) und linkt Step 2 stattdessen auf `frontend.account.address.page`.

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
