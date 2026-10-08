# RcCheckoutEnhancer – Checkout verbessern für Shopware 6

Verbessert den Shopware-Standard-Checkout: Fortschrittsanzeige, Vertrauenssignale, Warenkorb-Leiste, Versandkostenfrei-Indikator und Versandkostenrechner. Jede Funktion ist einzeln abschaltbar.

## Features

- **Progress-Bar:** Schritt-für-Schritt-Anzeige mit klickbarer Zurück-Navigation
- **Vertrauenssignale:** Konfigurierbare Trust-Badges mit Icons (Schloss, LKW, Rückgabe). Der Platzhalter `%freeShippingThreshold%` zieht den Versandkostenfrei-Betrag aus derselben Quelle wie der Indikator, statt ihn als Freitext zu pflegen
- **Mini-Warenkorb:** Kompakte Warenkorbübersicht als Sidebar auf der Bestätigungsseite
- **Lieferzeitschätzung:** Optionaler Hinweis auf geschätzte Lieferzeit
- **Versandkostenfrei-Indikator:** Zeigt im Warenkorb und in der Warenkorb-Leiste, wie viel bis zur versandkostenfreien Lieferung fehlt — nur dort, wo Versandkostenfreiheit für den Lieferort auch gilt und der Warenkorb sich ausliefern lässt
- **Versandkostenrechner:** Kunden geben Land und Postleitzahl ein und sehen die Kosten je Versandart — und ob überhaupt eine angeboten wird. Für Angemeldete ist die Anschrift des Kontos vorbelegt. Die zuletzt berechnete Auskunft erscheint auch in der Warenkorb-Leiste, solange sie zum Warenkorb passt. Ohne Lieferadresse zeigt die Leiste keine Versandkosten von 0,00 €, sondern nur „zzgl. Versandkosten“ mit dem Rechner darunter
- **Anfrageweg bei nicht möglicher Lieferung:** Bleibt im Bestellvorgang keine Versandart übrig, erscheint statt der Sackgasse ein Hinweis mit einer Schaltfläche zum Kontaktformular — und der Warenkorb wird dorthin übernommen
- **Abhol-Hinweis mit Bestätigung:** Wer schwere oder lange Ware selbst abholen will, bekommt einen Dialog, der Gewicht und Länge des Warenkorbs nennt. Ohne Bestätigung ist die Bestellung gesperrt; die Zusage wird samt Wortlaut und Zeitpunkt an der Bestellung festgehalten. Abbrechen stellt die Versandart zurück, statt den Kunden in der Sperre stehen zu lassen
- **Vorauswahl der Versandart:** Ist die Standard-Versandart für den Warenkorb nicht verfügbar, wird die erste lieferfähige vorausgewählt, statt den Kunden vor einer Liste ohne Anhakung sitzen zu lassen. Das gilt schon im Warenkorb und in der Warenkorb-Leiste, nicht erst auf der Bestätigungsseite. Eine als „keine Lieferung" eingetragene Versandart bleibt nur stehen, wenn der Kunde sie selbst angeklickt hat. Bleibt ohne Lieferadresse keine Lieferart übrig, tritt eine eingestellte Platzhalter-Versandart an ihre Stelle; solange eine Lieferart verfügbar ist, steht der Platzhalter nicht in der Auswahl, und statt „0,00 €" steht, dass die Versandkosten nach Eingabe der Lieferadresse berechnet werden
- **Einseitiger Checkout:** Wahlweise stehen für Gäste und Neukunden oben drei gleichwertige Knöpfe (Gastbestellung, Bereits Kunde, Neues Kundenkonto), darunter das passende Formular und gleich danach Versandart, Zahlart und Summen. Ein Wechsel von Versand- oder Zahlart lädt nur diesen Teil neu, das Adressformular behält seinen Inhalt. Nach „Weiter" folgen Endpreis, AGB und Bestellknopf. Über RcAbTesting lässt sich die Darstellung gegen den geführten Checkout testen
- **Rabatte ohne Code als Zeile der Zusammenfassung:** Aktionen, die Shopware selbst anwendet, etwa ein Rabatt für Vorkasse, stehen im Warenkorb, in der Leiste und im Checkout unter der Zwischensumme statt als Position. Gutscheine bleiben Positionen; Bestellung und Belege bleiben unverändert
- **Kundenkonto nach der Bestellung:** Meldet Shopware Gäste nach der Bestellung ab („Gastkunden nach dem Bestellabschluss automatisch ausloggen"), fehlt das Konto-Angebot des Kerns. Die Erweiterung zeigt auf der Abschlussseite trotzdem ein Kennwortfeld; aus dem Gastkonto wird ein Kundenkonto. Die Abmeldung bleibt, das Angebot gilt 30 Minuten, nur in diesem Browser und einmal
- **Hinweis auf den Belegen:** Bei Abholbestellungen tragen Rechnung, Lieferschein, Storno und Gutschrift den Satz, dass Verladen und Transport beim Käufer liegen — mit Datum, wenn eine Bestätigung vorliegt
- **Alles optional:** Jede Funktion einzeln an-/abschaltbar im Admin

## Voraussetzungen

- Shopware 6.7 oder 6.8
- PHP 8.2+

## Installation

Das Plugin gehört als Ordner `custom/plugins/RcCheckoutEnhancer` in die Shopware-Installation. Übertragen wird er von Hand — per FTP in dieses Verzeichnis kopieren; ein Composer-Paket gibt es nicht. Danach registriert Shopware ihn über die folgenden Befehle:

Als Archiv geht es auch ohne FTP: In der Administration unter **Erweiterungen → Meine Erweiterungen → Erweiterung hochladen** nimmt Shopware eine ZIP-Datei entgegen und legt sie selbst an die richtige Stelle; auf der Konsole tut `plugin:zip-import` dasselbe. Danach folgen dieselben Schritte wie unten. Wer das Archiv unter macOS packt, entfernt vorher den Ordner `__MACOSX` — sonst weist Shopware die Datei ab.

```bash
bin/console plugin:refresh
bin/console plugin:install --activate RcCheckoutEnhancer
bin/console theme:compile
bin/console cache:clear
```

**Aktualisierungen laufen denselben Weg.** Den neuen Ordner per FTP über den alten legen, danach `plugin:refresh` und `plugin:update RcCheckoutEnhancer`. Eine Aktualisierung von selbst gibt es nicht — ohne Composer-Paket und ohne Shopware-Store bleibt sie Sache des Betreibers. Vor einem Sprung über eine Hauptversion gehört ein Datenbank-Abzug dazu.

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
| Vorauswahl der Versandart | An/Aus + Platzhalter-Versandart ohne Lieferadresse (leer = kein Platzhalter) |
| Darstellung des Checkouts | Geführt (Vorgabe) / Eine Seite / A/B-Test über RcAbTesting |
| Anfrageweg bei nicht möglicher Lieferung | An/Aus + **Zielseite mit dem Kontaktformular** + Versandarten, die keine Lieferung sind + eigener Hinweistext + eigenes Anschreiben für das Kommentarfeld |
| Hinweis bei Selbstabholung schwerer Ware | Gewichtsschwelle in kg + Längenschwelle in mm + eigener Hinweistext (beide Schwellen leer = aus) |

**Ohne ausgewählte Zielseite erscheint der Anfrageweg nicht.** Das ist Absicht: Welche Seite Ihr
Kontaktformular trägt, weiß nur Ihr Shop — und eine Schaltfläche, die ins Leere führt, ist
schlimmer als keine.

**Tragen Sie Ihre Abhol-Versandarten unter „Versandarten, die keine Lieferung sind" ein.** Bleibt
für einen Warenkorb nur noch eine davon übrig — was häufig passiert, weil Abholungen keine
Gewichtsgrenze tragen —, erscheint der Anfrageweg zusätzlich. Die Abholung bleibt wählbar. Ohne
Eintrag erscheint der Hinweis nur, wenn gar keine Versandart übrig ist.

**Der Abhol-Hinweis nutzt dieselbe Liste.** Wählt ein Kunde eine der dort eingetragenen
Versandarten und überschreitet sein Warenkorb eine der beiden Schwellen, steht unmittelbar unter
der Versandart-Auswahl ein Hinweis darauf, dass diese Ware sonst per Spedition geht. Gemessen wird
das Gesamtgewicht und die **längste einzelne Position** — zwanzig Handläufe zu sechs Metern sind
nicht 120 Meter lang, sondern zwanzigmal zu lang fürs Auto. Solange beide Schwellen leer sind,
erscheint nichts.

Im Kommentarfeld des Formulars steht dann ein **Anschreiben**, darunter alles, was der Vertrieb
für ein Frachtangebot braucht: Artikelnummer, Bezeichnung und Menge je Position, die
Kundeneingaben anderer Erweiterungen, Gewicht je Stück, Gesamtgewicht, längste Position,
Warenwert und die vollständige Lieferanschrift samt Firma. Der Text steht sichtbar im Feld — der
Kunde sieht, was er absendet, und kann ergänzen. Das Anschreiben lässt sich je Verkaufskanal
überschreiben; leer bedeutet: nur die Aufstellung.

**Die Felder des Formulars werden mit den Daten des Kunden vorbelegt** — Anrede, Vorname,
Nachname, E-Mail und Telefon, soweit bekannt. Ohne sie tippt der Kunde alles neu, ausgerechnet an
der Stelle, an der er ohnehin schon aufgehalten wurde; und was er neu tippt, muss nicht die
Adresse seines Kontos sein. Was der Kunde selbst eingibt, hat immer Vorrang.

Der Schwellenwert ist ausdrücklich nur ein **Rückfall**: Maßgeblich ist der Betrag aus der
Verfügbarkeitsregel der ausgewählten Versandarten. Damit steht die Zahl an einer Stelle statt an
dreien — und läuft nicht auseinander.

### Prüfen, ob alles eingerichtet ist

Drei Felder verweisen auf Datensätze des Shops und haben deshalb keinen Vorgabewert. Fehlt einer,
ruht eine Funktion, ohne dass ein Fehler erscheint. Der Shopware-Systemstatus zeigt das:

```bash
bin/console system:check --context=cli
```

Der Eintrag `RcCheckoutEnhancerConfiguration` warnt je Verkaufskanal, wenn der Anfrageweg keine
Seite mit Kontaktformular oder keine Versandart „keine Lieferung" kennt. Fehlen die
versandkostenfreien Versandarten, steht das als Hinweis in der Meldung.

## Deployment

| Änderung | Befehl |
|----------|--------|
| Nur PHP/Twig | `bin/console cache:clear` |
| SCSS geändert | `bin/console theme:compile` |
| JS geändert | `bin/build-storefront.sh` |
| Erstinstallation | `bin/console theme:compile` |

## Geschwindigkeit messen

Das Messprojekt unter `benchmarks/` läuft getrennt vom Testlauf und gehört in kein Ausrollpaket
(`export-ignore`). Ein Lauf vom Arbeitsplatz misst gegen eine Instanz der DevBox:

```bash
bash benchmarks/run.sh live-clone
```

Zuerst die Seiten, an denen die Erweiterung mitrechnet, über HTTP (Median aus 12 Aufrufen), dann
die Dienste mit PHPBench gegen den Bestand der Instanz: die Lieferbarkeits-Prüfung des
Versandkostenfrei-Hinweises mit bepreister und mit gesperrter Lieferung, eine einzelne
Neuberechnung als Maßstab und der Speditionshinweis mit und ohne Zwischenspeicher. Jeder Lauf hängt seine Werte mit Datum, Fassung und Rechner an
`benchmarks/results.csv` an. Verglichen wird die Reihe derselben Instanz; Zahlen von
verschiedenen Rechnern sind nicht vergleichbar.

Im normalen Testlauf hält ein Wächter fest, dass die Lieferbarkeits-Prüfung nicht öfter rechnet
als nötig.

## Lizenz

MIT

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
| Abschluss | `CheckoutFinishPageLoadedEvent` | `base_main_inner`; in `finish-details`: `page_checkout_finish_create_account` | ja / ja, wenn kein Angebot vorliegt |

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
