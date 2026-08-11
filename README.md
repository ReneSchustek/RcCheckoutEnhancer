# RcCheckoutEnhancer – Checkout verbessern für Shopware 6

Verbessert den Shopware-Standard-Checkout: Fortschrittsanzeige, Vertrauenssignale, Warenkorb-Leiste, Versandkostenfrei-Indikator und Versandkostenrechner. Jede Funktion ist einzeln abschaltbar.

## Features

- **Progress-Bar:** Schritt-für-Schritt-Anzeige mit klickbarer Zurück-Navigation
- **Vertrauenssignale:** Konfigurierbare Trust-Badges mit Icons (Schloss, LKW, Rückgabe). Der Platzhalter `%freeShippingThreshold%` zieht den Versandkostenfrei-Betrag aus derselben Quelle wie der Indikator, statt ihn als Freitext zu pflegen
- **Mini-Warenkorb:** Kompakte Warenkorbübersicht als Sidebar auf der Bestätigungsseite
- **Lieferzeitschätzung:** Optionaler Hinweis auf geschätzte Lieferzeit
- **Versandkostenfrei-Indikator:** Zeigt im Warenkorb und in der Warenkorb-Leiste, wie viel bis zur versandkostenfreien Lieferung fehlt — nur dort, wo Versandkostenfreiheit für den Lieferort auch gilt und der Warenkorb sich ausliefern lässt
- **Versandkostenrechner:** Gäste geben Land und Postleitzahl ein und sehen die Kosten je Versandart. Die zuletzt berechnete Auskunft erscheint auch in der Warenkorb-Leiste, solange sie zum Warenkorb passt
- **Alles optional:** Jede Funktion einzeln an-/abschaltbar im Admin

## Voraussetzungen

- Shopware 6.7 oder 6.8
- PHP 8.2+

## Installation

```bash
bin/console plugin:refresh
bin/console plugin:install --activate RcCheckoutEnhancer
bin/console theme:compile
bin/console cache:clear
```

## Konfiguration

Im Admin unter **Einstellungen > System > Plugins > RC Checkout Enhancer**:

| Feature | Einstellungen |
|---------|-------------|
| Progress-Bar | An/Aus + 4 konfigurierbare Schritt-Bezeichnungen |
| Trust Badges | An/Aus + Texte mit optionalen Icons (lock, truck, undo, star) |
| Mini-Warenkorb | An/Aus |
| Lieferzeit | An/Aus + Freitext |
| Versandkostenfrei-Indikator | An/Aus + Rückfall-Schwellenwert + Auswahl der versandkostenfreien Versandarten |
| Versandkostenrechner | An/Aus (im Auslieferungszustand aus) |

Der Schwellenwert ist ausdrücklich nur ein **Rückfall**: Maßgeblich ist der Betrag aus der
Verfügbarkeitsregel der ausgewählten Versandarten. Damit steht die Zahl an einer Stelle statt an
dreien — und läuft nicht auseinander.

## Deployment

| Änderung | Befehl |
|----------|--------|
| Nur PHP/Twig | `bin/console cache:clear` |
| SCSS geändert | `bin/console theme:compile` |
| JS geändert | `bin/build-storefront.sh` |
| Erstinstallation | `bin/console theme:compile` |

## Lizenz

MIT

<!-- TRIAGE-WORKFLOW: auto-managed by triage-deploy.ps1 -->

## Plugin-Interaktion

Andere Ruhrcoder-Plugins erweitern dieselben Checkout-Seiten. Dieser Abschnitt sagt, wo dieses
Plugin eingreift und was daraus für die anderen folgt.

### Welche Seiten erweitert werden

| Seite | Ereignis | Überschriebener Block | `parent()` |
|---|---|---|---|
| Warenkorb | `CheckoutCartPageLoadedEvent` | `base_main_inner`, `page_checkout_cart_product_table` | ja / ja |
| Warenkorb-Leiste | `OffcanvasCartPageLoadedEvent` | `component_offcanvas_cart_actions` | ja |
| Adresse / Registrierung | `CheckoutRegisterPageLoadedEvent` | `base_main_inner` | ja |
| Bestätigung | `CheckoutConfirmPageLoadedEvent` | `base_main_inner`, `page_checkout_confirm`, `page_checkout_confirm_product_table` | ja / ja / **nein, siehe unten** |
| Abschluss | `CheckoutFinishPageLoadedEvent` | `base_main_inner` | ja |

Dazu ein eigener Storefront-Endpunkt für den Versandkostenrechner:
`POST /rc-checkout/shipping-estimate` (`frontend.rc-checkout.shipping-estimate`). Er ist ohne
Anmeldung erreichbar und deshalb doppelt begrenzt: eigene Rate-Limiter-Staffel und eine harte
Längengrenze auf der Postleitzahl.

Alle Abonnenten laufen mit **Vorrang 0**. Das Plugin rendert kein alternatives Checkout-Markup,
sondern ergänzt: Fortschrittsleiste, Vertrauenssignale, Warenkorb-Leiste. Es setzt **kein**
Suffix im Sinne des Interaktionsprotokolls, es ist reiner Konsument.

### Die eine Stelle, an der `parent()` bewusst ausbleibt

Auf der Bestätigungsseite wird `page_checkout_confirm_product_table` **unterdrückt**, solange die
Warenkorb-Leiste läuft — sonst stünde dieselbe Bestellübersicht zweimal auf einer Seite.

Daraus folgt eine Zusage an alle Plugins, die an den Positionszeilen hängen:

> **Die Leiste ist auf der Bestätigungsseite die einzige Darstellung des Warenkorbs. Sie rendert
> die Positionen deshalb über das Core-Template
> `component/line-item/type/product.html.twig` (`displayMode: 'offcanvas'`) und nicht über eigenes
> Markup.**

Das ist kein Stilfrage, sondern die Lehre aus einem Fehler: Am 2026-07-28 rendete die Leiste
eigenes Markup und umging damit den Erweiterungspunkt. Die von `RcColorPicker` gewählte RAL-Farbe
fehlte danach genau auf der Seite, auf der der Kunde bestätigt — bei lackierten Teilen ist eine
falsche Farbe kein Umtausch, sondern Ausschuss. Aufgefallen ist es dem Smoke-Test eines anderen
Plugins, keinem Review.

**Wer eine Checkout-Variante baut, die die Produkttabelle ersetzt, muss über das Core-Template
rendern.** Sonst wiederholt sich das je Variante.

### Was von anderen Plugins in der Leiste ankommt

Stand 2026-08-03. „Geprüft" heißt: am laufenden Shop gemessen, nicht aus dem Quelltext geschlossen.

| Plugin | Überschriebener Block | kommt in der Leiste an |
|---|---|---|
| `RcColorPicker` | `component_line_item_type_product_label` | **geprüft** — fester Bestandteil des Smoke-Tests |
| `RcCustomFields` | `component_line_item_type_product_details_container` | **geprüft** |
| `RcDualPrice` | `component_line_item_type_product_col_unit_price`, `…_col_total_price` | Blöcke liegen im gerenderten Pfad. Nicht gegengeprüft, weil im Prüfshop kein Artikel in einer Kategorie mit Zweitpreis liegt — dort zeigt ihn auch der Warenkorb nicht, das Verhalten ist also gleich |
| `RcCartSplitter` | `component_line_item_type_product_label` | derselbe Block wie `RcColorPicker`, damit belegt |
| `TmmsProductCustomerInputs` | `component_line_item_type_product`, `…_number` | Blöcke liegen im gerenderten Pfad |

Zwei Blockierungen sind bewusst gesetzt und betreffen alle:

- **`showRemoveButton: false`** — die Leiste zeigt den Warenkorb, sie bearbeitet ihn nicht. Auf der
  Bestätigungsseite wird nicht mehr geändert.
- **`showSubtotal: true`** — obwohl die Summe darunter noch einmal steht. Der Positionspreis ist der
  Block, an dem Preis-Erweiterungen hängen; mit `false` rendert der Kern ihn gar nicht, und
  `RcDualPrice` verlöre seinen Zweitpreis.

Mengen-Auswahl und Produktnummer werden per CSS ausgeblendet, nicht per Block — damit bleibt der
Erweiterungspunkt für andere Plugins erhalten.

### Warenkorb-Trennung, Meterpreise, Anhänge

- **`RcCartSplitter`:** Die Fortschrittsleiste hängt an der Seite, nicht an der Bestellung. Entstehen
  aus einem Warenkorb mehrere Bestellungen, sieht der Kunde sie trotzdem einmal — der Checkout
  bleibt ein Vorgang.
- **`RcDynamicPrice`:** Die Leiste zeigt die Positionspreise so, wie der Kern sie berechnet hat.
  Sie rechnet nicht selbst; Meterlängen kommen über dieselben Blöcke wie überall.
- **`RcOrderAttachment`:** Kunden-Uploads hängen an der **Bestellung**, nicht an der
  Warenkorb-Position. Die Leiste zeigt sie deshalb nicht — das ist richtig so, sie gehören nicht in
  eine Warenkorbübersicht.
