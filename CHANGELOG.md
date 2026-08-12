
# Changelog

## [1.11.0] - 2026-08-12 — Die Anfrage ist ein Anschreiben, kein Datenblock

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.
> Kein Storefront-Bau nötig.

### Neu

- **Das Kommentarfeld beginnt mit einem Anschreiben.** Bisher stand dort nur die Aufstellung des
  Warenkorbs — wer die Anfrage öffnete, sah eine Stückliste und musste sich zusammenreimen, was der
  Absender möchte. Der Text ist je Verkaufskanal überschreibbar; leer bedeutet: nur die Aufstellung.
- **Das Kontaktformular wird mit den Daten des Kunden vorbelegt** — Anrede, Vorname, Nachname,
  E-Mail und Telefon, soweit bekannt. Shopware füllt diese Felder aus der abgesendeten Eingabe,
  nicht aus dem Konto; ein angemeldeter Kunde bekam deshalb ein leeres Formular und musste alles
  neu tippen. Was er selbst eingibt, hat weiterhin Vorrang.
- **Die Aufstellung nennt die vollständige Lieferanschrift**, mit Firma. Bisher standen dort nur
  Land und Postleitzahl — ein Frachtpreis hängt aber an der Abladestelle, und die Firma entscheidet
  darüber, ob eine Rampe da ist oder eine Hebebühne gebraucht wird.

### Hinweis für den Betrieb

Der Anfrageweg berührt die Spam-Abwehr nicht: Deren Feld liegt in einem eigenen Block, wird nicht
überschrieben und muss leer bleiben. Ein Test hält das fest.

## [1.10.1] - 2026-08-11 — Die Schaltfläche sagt, wohin sie führt

> **Deployment:** `php bin/console cache:clear`.

### Behoben

- **Bleibt nur die Selbstabholung übrig, heißt die Schaltfläche jetzt „Lieferung anfragen"** statt
  „Angebot anfragen". Der Hinweis darüber bot die Lieferung schon an — die Beschriftung tat es
  nicht, und sie hätte genauso gut ein Angebot für die Abholung meinen können. Genau an dieser
  Stelle entscheidet sich, ob jemand klickt: Nicht jeder, der eine halbe Tonne bestellt, will
  einen LKW mieten.

## [1.10.0] - 2026-08-11 — Angebot anfragen auch dann, wenn nur die Abholung übrig bleibt

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.
> **Danach im Admin die Abhol-Versandarten eintragen** — ohne sie ändert sich nichts.

### Neu

- **Der Anfrageweg erscheint jetzt auch, wenn als einzige Möglichkeit die Selbstabholung übrig
  bleibt** — zusätzlich, nicht an ihrer Stelle. Wer abholen will, kann das weiterhin.

  Der Fall entsteht, weil die Abholung oft die einzige Versandart **ohne Gewichtsgrenze** ist. Sie
  bleibt deshalb übrig, wenn die Speditionsstaffel endet — nicht als Angebot, sondern als Rest.
  Der Kunde stand dann vor genau einer Möglichkeit: eine halbe Tonne selbst abholen. Wer das nicht
  kann, hatte keinen Weg außer dem Abbruch — und das ist der Kunde mit dem größten Warenkorb.

- **Neue Einstellung „Versandarten, die keine Lieferung sind".** Shopware kennt dafür kein
  Merkmal; eine Erkennung über den Namen wäre geraten und ginge bei der ersten Umbenennung schief.
  **Bleibt das Feld leer, ändert sich nichts** — dann erscheint der Hinweis wie bisher nur, wenn
  gar keine Versandart übrig ist.

- Eigener Hinweistext für diesen Fall: „Für diesen Warenkorb können wir nur die Selbstabholung
  anbieten. Wenn Sie eine Lieferung wünschen, erstellen wir Ihnen gern ein Angebot." Wie der erste
  Text als Textbaustein mit Überschreibung je Verkaufskanal.

## [1.9.0] - 2026-08-11 — Der Versandkostenrechner steht auch angemeldeten Kunden offen

> **Deployment:** `php bin/console cache:clear`.

### Geändert

- **Der Versandkostenrechner erscheint jetzt für alle Kunden, nicht nur für Gäste** — und ist für
  Angemeldete mit der Anschrift des Kontos vorbelegt.

  Bisher blieb er Gästen vorbehalten: Wer angemeldet ist, habe seine Adresse im Konto, und eine
  zweite Zahl daneben wäre irreführend. Das galt, solange der Bestellvorgang immer zu einem
  Ergebnis führte. **Er kann aber in eine Sackgasse laufen** — und der Rechner ist die einzige
  Stelle im Warenkorb, an der ein Kunde erfährt, dass es für seine Sendung gar keine Versandart
  gibt. Ausgerechnet der Stammkunde mit der großen Bestellung sah davon nichts.

  Die befürchtete zweite Zahl gibt es ohnehin nicht: Der Rechner benutzt dieselbe Berechnung wie
  der Bestellvorgang.

- Die Warenkorb-Leiste zeigt die zuletzt errechnete Auskunft aus demselben Grund ebenfalls
  Angemeldeten.

## [1.8.1] - 2026-08-11 — Die Warenkorb-Leiste zeigt wieder nur den Warenkorb

> **Deployment:** `bin/console theme:compile`.

### Behoben

- **In der Warenkorb-Leiste der Bestätigungsseite standen eine bedienbare Mengen-Auswahl und die
  Produktnummer.** Beides sollte dort nie erscheinen — auf der Bestätigungsseite wird nicht mehr
  geändert. Die Regeln, die es ausblenden sollten, zeigten auf Klassennamen, die es in Shopware 6.7
  nicht gibt, und liefen deshalb ins Leere.

  Die Mengen-Auswahl hat eine feste Mindestbreite und drückte in der schmalen Spalte alles
  zusammen; ihre Zahl war zweistellig sogar abgeschnitten. **Das war die eigentliche Ursache
  dafür, dass die Leiste gedrängt aussah.**

- **Lange Bezeichnungen brachen mitten im Wort** — aus „Vordachsystem" wurde „Vordachsys tem".

### Hinweis

Ein Test hält jetzt fest, dass jede Klasse, die das Stilblatt ausblendet, im Core-Template
tatsächlich vorkommt. Eine Regel auf einen Namen, den es nicht gibt, bricht nichts und wird nicht
rot — sie sieht nur falsch aus, und zwar auf der Seite, auf der der Kunde bestätigt.

## [1.8.0] - 2026-08-11 — Anfrage statt Sackgasse, wenn keine Versandart übrig bleibt

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.
> **Danach im Admin die Zielseite einstellen** — ohne sie erscheint der Anfrageweg nicht.

### Neu

- **Bleibt im Bestellvorgang keine Versandart übrig, erscheint ein Hinweis mit einer Schaltfläche
  zum Kontaktformular — und der Warenkorb wird dorthin übernommen.** Bisher nannte der Shop bis zum
  letzten Schritt einen Versandpreis für Sendungen, die er gar nicht ausliefern kann; der Kunde
  hatte nirgends einen Anlass zu zweifeln und brach ab.

  Übergeben wird, was der Vertrieb für ein Frachtangebot braucht: Artikelnummer, Bezeichnung und
  Menge je Position, **die Kundeneingaben anderer Erweiterungen** (etwa eine gewählte Farbe),
  Gewicht je Stück, Gesamtgewicht, längste Position, Warenwert und das Lieferziel.

  Der Text landet **sichtbar** im Kommentarfeld des Formulars — der Kunde sieht, was er absendet,
  und kann ergänzen.

- **Drei Einstellungen dazu:** ein Schalter, die Zielseite mit dem Kontaktformular und ein
  eigener Hinweistext. **Ohne ausgewählte Zielseite erscheint nichts** — eine Schaltfläche ins
  Leere wäre schlimmer als keine.

### Hinweise

- Der Weg kommt **ohne JavaScript** aus. Er ist der letzte, der einem Kunden bleibt, bevor er
  abbricht, und darf nicht daran scheitern, dass ein Skript klemmt.
- Warenkörbe mit verfügbarer Versandart sehen den Hinweis nie. Der Smoke-Test hält das fest.

## [1.7.1] - 2026-08-11 — Keine Zusage „versandkostenfrei", wenn der Warenkorb Versand berechnet

> **Deployment:** `php bin/console cache:clear`.

### Behoben

- **Der Warenkorb versprach versandkostenfreie Lieferung und berechnete gleichzeitig Versandkosten.**
  Gemessen an einer Sendung über der obersten Gewichtsstufe: Der Hinweis meldete „Glückwunsch — Ihre
  Bestellung wird versandkostenfrei geliefert!", die Zusammenfassung daneben wies 8,93 € aus. Grund
  war, dass der Hinweis nur den Warenwert gegen den Schwellenwert rechnete — die versandkostenfreie
  Versandart war für dieses Gewicht gesperrt, geliefert hätte ein Paketdienst zum Normaltarif.

  Ist der Schwellenwert erreicht, der Warenkorb trägt aber Versandkosten, erscheint der Hinweis
  jetzt **gar nicht**. Auch kein „noch X € bis zur versandkostenfreien Lieferung" — der
  Schwellenwert ist ja überschritten, das wäre die zweite falsche Aussage.

  **Unverändert bleibt der Normalfall:** Solange der Schwellenwert noch nicht erreicht ist, steht
  „noch X € bis zur versandkostenfreien Lieferung" wie bisher — auch dann, wenn der Versand aktuell
  etwas kostet. Genau dafür gibt es den Hinweis.

## [1.7.0] - 2026-08-11 — Ein Plugin für den Bestellvorgang, jede Funktion einzeln abschaltbar

> **Wichtig für den Umstieg:** Dieses Plugin übernimmt den Versandkostenfrei-Indikator und den
> Versandkostenrechner. Reihenfolge beim Aktualisieren: **erst dieses Plugin aktualisieren**
> (`php bin/console plugin:update RcCheckoutEnhancer`), **dann das bisherige Indikator-Plugin
> deinstallieren**. Andersherum sind die Einstellungen verschwunden, bevor sie übernommen werden
> konnten. Danach `php bin/console cache:clear` und `bin/build-storefront.sh`.

### Neu

- **Versandkostenfrei-Indikator.** Zeigt im Warenkorb und in der Warenkorb-Leiste, wie viel bis
  zur versandkostenfreien Lieferung fehlt — samt Prüfung, ob Versandkostenfreiheit für das
  Lieferland überhaupt gilt und ob der Warenkorb sich ausliefern lässt.
- **Versandkostenrechner im Warenkorb.** Gäste geben Land und Postleitzahl ein und sehen die
  Kosten je Versandart. Die zuletzt berechnete Auskunft erscheint auch in der Warenkorb-Leiste,
  solange sie zum Warenkorb passt.
- **Ein Schalter je Funktion**, alle im Verkaufskanal-Bereich der Einstellungen:
  Fortschrittsanzeige, Vertrauenssignale, Warenkorbübersicht, Lieferzeit,
  Versandkostenfrei-Indikator, Versandkostenrechner. Abgeschaltet erscheint nichts davon auf
  der Seite.
- **Bestehende Einstellungen werden übernommen.** Beim Aktualisieren wandern Schwellenwert,
  ausgewählte Versandarten und die Ein/Aus-Zustände des bisherigen Indikator-Plugins mit — je
  Verkaufskanal getrennt. Wiederholtes Aktualisieren überschreibt nichts, was danach im Admin
  geändert wurde.

### Geändert

- Die Vertrauenssignale holen den Betrag für `%freeShippingThreshold%` jetzt direkt aus diesem
  Plugin statt über die Suche nach einem anderen. Am Verhalten ändert das nichts; die Zeile
  fällt weiterhin weg, wenn sich kein Betrag ermitteln lässt.
- Alle Einstellungen werden an einer Stelle gelesen. Vorher stand derselbe Schlüssel in
  mehreren Klassen.

## [1.6.1] - 2026-08-10 — Kein Platzhalter mehr im Bestellvorgang

### Behoben

- **Die Vertrauenszeile zeigte einen unausgefüllten Platzhalter.** Ist der Versandkostenfrei-Betrag nicht ermittelbar, stand dort wörtlich `%freeShippingThreshold%`. Diese Zeile wird jetzt weggelassen — ein Vertrauenssignal ohne Zahl wirbt mit einer Zusage und zeigt an ihrer Stelle Technik. Zeilen ohne Platzhalter bleiben unverändert stehen.

## [1.6.0] - 2026-08-04 — Die Vertrauensleiste kann den Versandkostenfrei-Betrag nachschlagen

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.

### Neu

- **Platzhalter `%freeShippingThreshold%` in den Vertrauenssignalen.** Statt
  `truck;Kostenloser Versand ab 50 €` lässt sich jetzt
  `truck;Kostenloser Versand ab %freeShippingThreshold%` hinterlegen; der Betrag kommt dann
  aus der Verfügbarkeitsregel der versandkostenfreien Versandart und ist damit dieselbe Zahl,
  die der Warenkorb nennt. Wer lieber eine feste Zahl schreibt, kann das weiterhin tun.
- Die Kopplung an RcCheckout ist lose: Fehlt das Plugin, bleibt der Platzhalter stehen und
  nichts bricht.

### Behoben

- **Eine tote Abhängigkeit in der Dienst-Definition.** Dem Subscriber wurde ein
  `request_stack` übergeben, das sein Konstruktor gar nicht mehr entgegennahm — PHP verschluckt
  überzählige Argumente stillschweigend, deshalb ist es nie aufgefallen. Beim nächsten neuen
  Konstruktor-Argument wäre daraus ein Typfehler geworden.

## [1.5.0] - 2026-08-03 — Die Checkout-Optimierung lässt sich gegen den Standard testen

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`. Ohne konfiguriertes Experiment ändert sich nichts.

### Hinzugefügt

- **Die Checkout-Optimierung kann sich für eine Vergleichsgruppe zurückhalten.** Damit lässt sich messen, ob Fortschrittsleiste, Vertrauenssignale und Warenkorb-Leiste tatsächlich mehr Bestellungen bringen als der Standard-Checkout. Zwei neue Einstellungsfelder legen fest, an welchem Experiment das Plugin teilnimmt und bei welcher Gruppe es sich zurückhält. Beide leer — die Vorgabe — heißt: Es ändert sich nichts.
- **Hält sich die Warenkorb-Leiste zurück, kommt die gewohnte Bestelltabelle zurück.** Sonst sähe die Vergleichsgruppe auf der Bestätigungsseite gar keinen Warenkorb mehr.

### Sonstiges

- Ohne das A/B-Plugin bleibt alles unverändert, auch wenn in den Einstellungen noch ein Experiment steht.

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
