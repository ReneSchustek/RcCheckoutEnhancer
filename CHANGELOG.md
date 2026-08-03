
# Changelog

## [1.4.0] - 2026-08-03 — Die Fortschrittsleiste ist wieder lesbar

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console theme:compile && php bin/console cache:clear`. Der `theme:compile` ist nötig: Die Änderungen liegen im Stylesheet.

### Behoben

- **Die Ziffer des noch nicht erreichten Schritts war kaum lesbar.** Gemessen gegen die tatsächlichen Farbwerte des Themes: **2,10:1** — die Barrierefreiheits-Norm verlangt 4,5:1. Auch die Beschriftung lag mit 3,81:1 darunter. Beide stehen jetzt auf einem dunkleren Grauton und erreichen 4,51:1 und 8,18:1. Die Leiste wirkt dadurch etwas weniger gedämpft — lesbar schlägt dezent.
- **Der Umschalter der Warenkorb-Leiste war für Vorlesehilfen unvollständig beschrieben.** Er sagte, dass etwas auf- und zuklappt, aber nicht was. Zusätzlich wurde das Pfeilzeichen mitgelesen, obwohl es nur Schmuck ist.
- **Wer Bewegung im System abbestellt hat, bekommt jetzt keine.** Der Umschalter dreht sein Zeichen nicht mehr.

### Sonstiges

- Der Smoke-Test prüft jetzt am echten Checkout, dass die Warenkorb-Leiste die Positionsangaben anderer Erweiterungen mitträgt — etwa die gewählte Farbe. Diese Angabe war Ende Juli einmal verschwunden; sie kann es jetzt nicht mehr unbemerkt.
- README-Abschnitt „Plugin-Interaktion": welche Seiten und Blöcke das Plugin erweitert, was von anderen Erweiterungen in der Leiste ankommt, und die Zusage, auf die sie sich verlassen können.

## [1.3.2] - 2026-07-30 — Zweitpreise stehen wieder in der Seitenleiste

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.

### Behoben
- **Der Netto-Zweitpreis fehlte auf der Bestätigungsseite.** Die Warenkorb-Leiste zeigte die Positionen ohne Positionspreis — und genau daran hängt RcDualPrice seinen zweiten Preis. Gemessen: Im Warenkorb stand er, auf der Bestätigungsseite nicht mehr. Für einen Kunden, der nach Netto kalkuliert, fehlte die Angabe damit ausgerechnet im letzten Schritt vor dem Kauf. Die Leiste zeigt die Positionspreise jetzt mit.
- Das ist derselbe Fehler wie bei der Farbe in 1.3.1, eine Ebene tiefer: Dort umging eigenes Markup den Erweiterungspunkt, hier war der Block schlicht abgeschaltet.

## [1.3.1] - 2026-07-29 — Warenkorb steht auf dem Handy wieder oben

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer`, danach `theme:compile`. Reine Darstellungsänderung.

### Behoben

- **Auf schmalen Bildschirmen rutschte der Warenkorb ans Seitenende.** Seit 1.3.0 zeigt die Seitenleiste den Warenkorb als einzige Stelle — auf dem Handy stand sie aber hinter Widerrufsbelehrung, Adressen, Zahlungs- und Versandart, also ganz unten. Der Kunde sah beim Bestellen zuerst alles andere und seine Bestellung zuletzt. Die Leiste steht dort jetzt an erster Stelle; am Rechner bleibt sie unverändert rechts daneben.

## [1.3.0] - 2026-07-29 — Keine doppelten Angaben mehr im Checkout

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`, danach `theme:compile`.

### Entfernt

- **Die Bestellübersicht in der Seitenleiste ist ersatzlos entfallen.** Sie wiederholte Lieferadresse, Versandart, Zahlungsart und Gesamtbetrag — alles Angaben, die der Hauptbereich der Seite bereits vollständig zeigt, dort mit funktionierenden Schaltflächen zum Ändern. Ihre eigenen drei „Ändern"-Schaltflächen führten zudem ins Leere: Sie zeigten auf Sprungmarken, die es auf der Seite nicht gibt, ein Klick bewirkte nichts. Die zugehörige Einstellung „Bestellzusammenfassung anzeigen" entfällt damit ebenfalls.

### Geändert

- **Die Seitenleiste zeigt nur noch den Warenkorb** — und solange sie das tut, blendet der Hauptbereich seine eigene Positionstabelle aus. Vorher stand dieselbe Bestellung zweimal auf einer Seite. Damit gibt es zwei klare Darstellungen, die sich gegeneinander vergleichen lassen: ohne Leiste die Tabelle im Hauptbereich, mit Leiste der Warenkorb daneben.

## [1.2.5] - 2026-07-20

> **Deployment:** `php bin/console cache:clear`.

### Behoben

- **Trust-Badge-Icons erscheinen wieder:** Die Standard-Icons `lock` und `undo` gibt es im Shopware-6.7-Icon-Pack nicht — die Vertrauenssignale wurden out-of-the-box teilweise ohne Icon (nur Text) angezeigt. Die Namen werden jetzt intern auf real existierende Core-Icons abgebildet (`lock-closed` bzw. `arrow-360-left`); bestehende Konfigurationen mit `lock`/`undo` funktionieren unverändert.

### Geändert

- **Regressions-Sperre:** Der Contract-Test deckt jetzt auch die Checkout-Overrides für Warenkorb-, Adress- und Abschluss-Seite (`base_main_inner`) gegen die Phantom-Block-Klasse ab.

## [1.2.4] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`. **Vor Live-Deploy Confirm-Seite im Browser prüfen** (Mini-Cart + Order-Summary sichtbar).

### Behoben

- **Mini-Cart & Order-Summary erscheinen wieder auf der Bestätigungsseite:** Das Plugin überschrieb `page_checkout_confirm_container` — einen Block, den der Storefront-Core in keiner unterstützten Version kennt → der Sidebar-Override war ein stiller No-Op, die zwei Features renderten nie. Jetzt wird der real existierende Core-Block `page_checkout_confirm` umschlossen (`{{ parent() }}` erhalten). 4 Pinning-Tests sichern gegen Rückfall.
- **BFSG/WCAG 2.2 AA:** Der aktive Fortschritts-Schritt trägt jetzt `aria-current="step"`, einen visually-hidden Status („aktueller Schritt"/„abgeschlossen") und ein `aria-hidden`-Glyph — Screenreader-Nutzer erfahren ihren Standort.
- **Order-Summary-Sichtbarkeit entkoppelt:** Die Sidebar erscheint bei `miniCartEnabled` **oder** `orderSummaryEnabled`; jede Komponente prüft weiter ihr eigenes Flag (vorher schaltete das Mini-Cart-Flag die Bestellübersicht mit ab).

## [1.2.3] - 2026-05-13 — Build-Hygiene

> **Deployment:** Kein Live-Eingriff. Reines Repo-Cleanup.

### Geändert
- `composer.json`: kosmetischer `extra.audit.ignore`-Block entfernt. Composer liest Ignore-Regeln aus `config.audit.ignore`, nicht aus `extra` — der Block hatte nie eine Wirkung und war Müll.
- `.gitignore`: `composer.lock` ergänzt — Library-Plugins liefern keinen Lock mit. Der Lock war hier zwar nie eingecheckt, der Eintrag schließt die Lücke vorbeugend.

## [1.2.2] - 2026-05-13 — Hotfix

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`

### Behoben (kritisch)
- **ERR_TOO_MANY_REDIRECTS für Gast-Sessions in v1.2.1.** Die in v1.2.1 eingeführte Verlinkung von Step 2 auf `frontend.account.address.page` funktioniert nur für echte Kunden. Gäste haben in Shopware keinen Zugriff auf das Konto -- Shopware leitet sie zur Login-Page, die sie als "schon eingeloggt" zurück zu `/account/address` schickt -> Redirect-Loop.
- Korrigiert: Step 2 wird bei Gast-Sessions NICHT mehr verlinkt (Span statt Anchor). Der Gast ändert seine Adresse weiter über die Inline-Edit-Buttons auf der Confirm-Page. Echte Kunden (eingeloggt, `customer.guest = false`) bekommen weiterhin `frontend.account.address.page`.

## [1.2.1] - 2026-05-13 — zurückgezogen

> **Hinweis:** Diese Version wurde durch v1.2.2 ersetzt, weil der Link bei Gästen eine Redirect-Schleife erzeugte. Nicht einsetzen.

### Behoben
- **Step 2 der Progressbar leitete eingeloggte Sessions ins Leere.** Der Link zeigte auf `frontend.checkout.register.page`. Diese Route leitet aber bei jeder aktiven Session weiter -- bei eingeloggten Kunden zum Confirm, bei Gästen auf die "Gastsitzung beenden"-Seite. Damit konnte ein Kunde aus dem Confirm-Step nicht mehr zurück zu seinen Adressen, um sich z.B. zu vertippen.
- Ab v1.2.1 erkennt das Template eingeloggte Sessions (Gast + Kunde) und linkt Step 2 stattdessen auf `frontend.account.address.page`.

## [1.2.0] - 2026-05-12

> **Deployment:** `composer install && php bin/console cache:clear`

### Behoben (kritische Latent-Bugs)
- **PHPStan deckte fehlende `shopware/storefront`-Composer-Dep auf** — Plugin nutzt `CheckoutCart/Register/Confirm/FinishPageLoadedEvent` aus dem Storefront-Bundle, hatte es aber nicht als Composer-`require` deklariert. PHPStan zeigte 21 `class.notFound`-Errors. Behoben durch Aufnahme von `shopware/storefront: ~6.7.0 || ~6.8.0` in `require`.
- **`match`-Expression hatte unreachable `default`-Case** — alle 4 Event-Klassen sind im `event`-Type-Hint enthalten, daher ist `default => 1` unerreichbar. Entfernt.
- **`ConfigService` war `final` deklariert, aber Test mockt die Klasse** — `PHPUnit\Framework\MockObject\Generator\ClassIsFinalException` in 7 Tests. `final` entfernt; eigene Fakes statt Attrappen bleiben das Ziel, dies war der schnelle Weg.

### Geändert (composer.json)
- Version 1.1.0 → 1.2.0
- `php >=8.2`-Constraint expliziert
- `shopware/storefront`-Dep ergänzt
- `config.allow-plugins` mit `symfony/runtime: true` (Voraussetzung für non-interactive `composer install`)
- `scripts.quality` als Aggregat (cs-check + phpstan + test) ergänzt
- Skripte verwenden `vendor/bin/...` (Windows-portabel)

### Suite-Vorbereitung (Phase 4.4)
- Plugin ist Vorbild für das `Module/ProgressBar`-, `Module/TrustBadges`- und `Module/OrderSummary`-Sub-Modul der `RcCheckoutSuite` v1.0.0. Code wird per Namespace-Patch in die Suite übertragen.

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
