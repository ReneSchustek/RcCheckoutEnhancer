# Changelog

## [1.34.1] - 2026-10-08 — Die Symbole der Vertrauenssignale sitzen auf Höhe des Textes

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer`, `php bin/console theme:compile`,
> `php bin/console cache:clear`.

### Behoben

- **Schloss, Lieferwagen und Pfeil in den Vertrauenssignalen saßen rund 4 px unter dem Text.** Shopware
  senkt Symbole für den Fließtext ab; in dieser Zeile richtet schon die Flexbox mittig aus, der Versatz
  kam obendrauf. Er gilt dort jetzt nicht mehr, andere Symbole des Shops bleiben, wie sie sind.

## [1.34.0] - 2026-10-08 — Die Leiste verspricht keinen kostenlosen Versand

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer`, `php bin/console cache:clear`.

### Geändert

- **Ohne Lieferadresse steht in der Warenkorb-Leiste keine Zeile „Versandkosten + 0,00 €“ mehr.**
  Solange der Shop keinen Kunden und damit keine Adresse kennt, rechnet die vorgewählte Versandart oft
  0,00 €, weil ihre Preise erst mit Land und Postleitzahl greifen. Die Zeile fällt dann weg; unter der
  Zwischensumme bleiben „zzgl. Versandkosten“ und darunter „Versandkosten berechnen“. Ein Betrag über
  null, eine gewählte Abholung und erreichte Versandkostenfreiheit bleiben sichtbar. Warenkorbseite
  und Kasse ändern sich nicht.

## [1.33.0] - 2026-10-05 — Warenkorb und Leiste ohne eigene Neuberechnung

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer`, `php bin/console cache:clear`.

### Geändert

- **Der Versandkostenfrei-Hinweis rechnet den Warenkorb nicht mehr eigens nach.** Ob sich der
  Warenkorb an den Lieferort schicken lässt, steht nach der Vorauswahl schon im Warenkorb der Seite:
  eine Lieferung ohne Fehler zur Versandart. Neu gerechnet wird nur noch, wenn die gewählte
  Versandart gesperrt ist, etwa über ihrem obersten Gewichtsband. Die Prüfung dauert damit im
  Normalfall 0,001 ms statt 48,8 ms (PHPBench, live-clone); die Aussage des Hinweises bleibt gleich,
  an 22 Fällen gegen die volle Prüfung verglichen.

### Intern

- **Messprojekt `benchmarks/`** mit PHPBench und einer Seitenmessung über HTTP, Ablage der Werte je
  Lauf in `benchmarks/results.csv`; kommt in kein Ausrollpaket. Ausgangswerte vom 05.10. auf
  live-clone: Lieferbarkeits-Prüfung des Versandkostenfrei-Hinweises 47 ms je Aufruf von Warenkorb
  und Leiste, eine Neuberechnung 8 ms, Speditionshinweis 368 ms ohne und 6 ms mit Zwischenspeicher.
- Wächter im Testlauf: Mit bepreister Lieferung rechnet die Lieferbarkeits-Prüfung gar nicht, sonst
  bei bepreister erster Versandart genau zweimal.
- PHPStan und PHP CS Fixer prüfen auch `benchmarks/`.

## [1.32.0] - 2026-10-05 — Ruhende Funktionen melden sich im Systemstatus

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer`, `php bin/console cache:clear`.

### Neu

- **Eintrag im Shopware-Systemstatus** (`RcCheckoutEnhancerConfiguration`). Er warnt je aktivem
  Storefront-Kanal, wenn der Anfrageweg eingeschaltet ist, aber die Seite mit dem Kontaktformular
  oder die Liste der Versandarten „keine Lieferung" fehlt; dann ruht die Funktion ganz oder im Fall
  „nur Abholung". Fehlen die versandkostenfreien Versandarten, steht ein Hinweis in der Meldung,
  ohne Warnung: Der Versandkostenfrei-Hinweis erscheint dann für jedes Lieferland.
- Sichtbar über `bin/console system:check`, die Schnittstelle `/api/_info/system-health-check`
  und vor einem Rollout. Die Prüfung meldet immer „gesund"; eine ruhende Funktion ist kein Ausfall.

## [1.31.0] - 2026-10-05 — Anfrageweg dicht, Beträge in der Währung des Warenkorbs

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer`, `php bin/console theme:compile`,
> `php bin/console cache:clear`. Neues Storefront-Skript (fertig gebaut im Paket).

### Behoben

- **Der Anfrageweg lässt sich nicht mehr umgehen.** Bleibt nur die Abholung übrig, lehnt der
  Endpunkt der Abhol-Bestätigung auch einen direkten Aufruf ab; bestellt wird dann über die Anfrage.
- **Anfragetext:** Nur für den Warenkorb freigegebene Zusatzfelder des Produkts kommen hinein, keine
  internen. Beträge stehen in der Währung des Warenkorbs statt fest in Euro.
- **Vertrauensleiste:** Die Versandkostenfrei-Schwelle wird in die Währung des Besuchers umgerechnet,
  wie im Versandkostenfrei-Hinweis.
- **Versandkostenfrei-Hinweis:** Eine versandkostenfreie Versandart ohne Länder-Bedingung gilt
  überall; die Länderliste wird dadurch nicht mehr zu eng.
- **Rechner und Speditionshinweis:** Eine Fehlerantwort (Begrenzung, Serverfehler) erscheint nicht
  mehr als Seite im Kasten; der Rechner zeigt seinen Fehlersatz, der Hinweis bleibt weg.
- **Vorauswahl:** Sperrt die neue Versandart die gewählte Zahlart, wird auf die Standard-Zahlart des
  Kanals umgestellt, sonst auf die erste verfügbare.
- Einstellungen und die Liste freigegebener Zusatzfelder werden zwischen zwei Anfragen geleert; ein
  langlebiger Prozess sieht Änderungen ohne Neustart.

### Intern

- Eigene Tests für Vorauswahl, Speditionshinweis samt Endpunkten, Abhol-Bestätigung, Beleghinweis
  und die Storefront-Skripte (JavaScript); lange Methoden aufgeteilt.

## [1.30.0] - 2026-10-05 — Aus der Gastbestellung ein Kundenkonto

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer`, `php bin/console theme:compile`,
> `php bin/console cache:clear`. `theme:compile` ist nötig: Die neue Vorlage für die Abschlussseite
> greift erst danach, weil das Theme dieselbe Vorlage überschreibt. Die Funktion ist ausgeschaltet,
> bis „Kundenkonto nach der Bestellung" eingeschaltet wird.

### Neu

- **Kundenkonto nach der Bestellung.** Ist „Gastkunden nach dem Bestellabschluss automatisch
  ausloggen" eingeschaltet, zeigt Shopware Gästen kein Konto-Angebot mehr. Die Erweiterung zeigt auf
  der Abschlussseite stattdessen ein Kennwortfeld; aus dem Gastkonto der Bestellung wird ein
  Kundenkonto, danach meldet sich der Kunde mit Mailadresse und neuem Kennwort an. Die Abmeldung
  bleibt. Das Angebot gilt 30 Minuten, nur in diesem Browser und nur einmal; für wen es gilt, steht
  in der Sitzung und nie in der Anfrage.

## [1.29.0] - 2026-10-05 — Der Rabatt steht als Zeile, der Platzhalter nur, wenn er gilt

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.
> Nur Vorlagen und Dienste, kein Storefront-Bau.

### Geändert

- **Automatische Aktionen stehen in der Zusammenfassung, nicht als Position.** Ein Rabatt ohne
  Code, den Shopware selbst anwendet, etwa für Vorkasse, sah als Position aus wie ein Artikel mit
  Löschen-Knopf. Im Warenkorb, in der Leiste, in der Seitenleiste der Bestätigung und im
  einseitigen Checkout steht er jetzt als Zeile unter der Zwischensumme; die Zwischensumme zeigt
  den Warenwert davor. Gutscheine mit Code bleiben Positionen. Bestellung, Mail und Rechnung
  führen die Aktion unverändert als Position.
- **Der Platzhalter steht nur in der Liste, wenn er gilt.** Ist eine Lieferart verfügbar, fehlt
  „Versand – Kosten nach Eingabe der Lieferadresse" in der Auswahl. Vorher ließ er sich anklicken,
  und die Vorauswahl schaltete sofort zurück.
- Der Hilfetext der Einstellung „Darstellung des Checkouts" beschreibt die Bestätigungsseite so,
  wie sie ist.

### Behoben

- **Bestellungen über die Store-API und aus dem Verwaltungsbereich werden nicht mehr gesperrt.**
  Telefonnummer-Pflicht und Abhol-Bestätigung gelten nur in der Storefront, wo sich beides
  nachholen lässt. Vorher scheiterte eine im Verwaltungsbereich angelegte Bestellung für einen
  Kunden ohne Telefonnummer am eingeschalteten Schalter.
- **Die Adressprüfung der Bestätigungsseite bleibt bestehen, wenn die Vorauswahl umschaltet.**
  Mit ungültiger Adresse war der Bestellknopf in diesem Fall frei. Der neu gerechnete Warenkorb
  berücksichtigt externe Steueranbieter wie der Kern.
- Eine automatische Aktion auf die Versandkosten erscheint nicht mehr als Zeile „− 0,00 €"; sie
  bleibt Position wie im Kern.
- Die Leiste nennt nach einer Versandkosten-Abfrage den günstigsten Versand, nicht die Abholung
  zu 0,00 €. Bleibt nur die Abholung, zeigt sie keine Zeile.
- Der Platzhalter bleibt in der Liste, solange er eingestellt ist und die Vorauswahl ausgeschaltet
  ist; sonst wäre nichts angehakt. Der Abhol-Dialog stellt beim Schließen nie auf den Platzhalter
  zurück.
- Ohne Schwelle aus Regel oder Einstellung schweigt der Versandkostenfrei-Hinweis wie die
  Vertrauensleiste, statt gegen 50 € zu rechnen.
- Die Ausschnitt-Route des einseitigen Checkouts gibt 404, wenn er nicht erscheinen kann; beim
  A/B-Test ohne RcAbTesting lädt die Kasse die Auswahl nicht mehr umsonst.
- Das Formular für die Telefonnummer schreibt bei ausgeschaltetem Schalter nichts.

## [1.28.0] - 2026-10-05 — Der Checkout auf einer Seite

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer`, `php bin/console theme:compile`,
> `php bin/console cache:clear`. Neue Stile und ein neues Storefront-Skript (fertig gebaut im Paket).
> Ohne Einstellung bleibt der Checkout geführt wie bisher.

### Neu

- **Einseitiger Checkout für Gäste und Neukunden** (neue Einstellung „Darstellung des Checkouts").
  Oben stehen drei gleichwertige Knöpfe: Gastbestellung, Bereits Kunde, Neues Kundenkonto.
  Darunter das passende Formular, gleich danach Versandart, Zahlart und die Summen. Ein Wechsel
  von Versand- oder Zahlart lädt nur diesen Teil neu; was schon im Adressformular steht, bleibt
  stehen. „Weiter" steht am Ende und übernimmt die Adresse; danach folgen Endpreis, AGB und
  Bestellknopf.
- **Ein Klick bis zur Bestellung ist bewusst nicht dabei.** Der Endpreis hängt bei Speditionsware
  an der Adresse, und PayPal zeigt seine Knöpfe erst auf der Bestätigungsseite.
- **A/B-Test:** Mit der Einstellung „A/B-Test" entscheidet der Schalter „Checkout-Darstellung"
  aus RcAbTesting je Besucher. Ohne RcAbTesting oder ohne laufenden Test gilt die geführte
  Darstellung.
- Die Fortschrittsanzeige zählt im einseitigen Checkout drei Schritte: Warenkorb, Kasse, Fertig.

### Geändert

- Die Vorauswahl der Versandart ist eine eigene Stelle, die Warenkorbseite, Leiste,
  Bestätigungsseite und der einseitige Checkout gemeinsam nutzen. Ihr Verhalten ist unverändert.

## [1.27.0] - 2026-10-02 — Die Vorauswahl greift schon im Warenkorb

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.
> Neue Vorlagen, aber keine Stile und kein Skript; ein Storefront-Bau ist nicht nötig. Danach im
> Verwaltungsbereich unter „Vorauswahl der Versandart" die Platzhalter-Versandart wählen — ohne
> Auswahl gibt es keinen Platzhalter.

### Neu

- **Vorauswahl auf Warenkorbseite und Warenkorb-Leiste.** Ohne Lieferadresse sperrt Shopware die
  Standard-Versandart und mit ihr jede gleichnamige; übrig blieb oft die Selbstabholung, angehakt,
  bevor der Kunde eine Adresse eingegeben hatte. Die Vorauswahl entscheidet jetzt schon dort wie
  auf der Bestätigungsseite: Standard-Versandart, sonst die erste Lieferart nach Position, nie
  eine Abholung, die niemand angeklickt hat.
- **Platzhalter-Versandart ohne Lieferadresse** (neue Einstellung). Bleibt keine Lieferart übrig,
  etwa bei Speditionsware vor der Adresseingabe, wird statt der Abholung der Platzhalter
  vorausgewählt; die Abholung bleibt darunter wählbar. Ist eine Lieferart verfügbar, wird der
  Platzhalter nie gewählt und wieder verlassen.
- **Statt „0,00 €" ein Satz.** Steht der Platzhalter im Warenkorb, zeigen Zusammenfassung und
  Leiste „wird nach Eingabe der Lieferadresse berechnet".

### Behoben

- Auf der Warenkorbseite galt eine nicht verfügbare Versandart als verfügbar, weil Shopware sie
  dort an die Liste anhängt. Die Vorauswahl prüft die Verfügbarkeitsregel jetzt selbst.

## [1.26.0] - 2026-09-23 — Die Produktseite sagt, wenn ein Artikel per Spedition kommt

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer`, **`bin/build-storefront.sh`**
> (neues Storefront-Skript, auf der DevBox als `ddev exec "bin/build-storefront.sh"`),
> `php bin/console cache:clear`. Danach im Verwaltungsbereich die Karte „Speditionshinweis auf
> der Produktseite" einstellen — ohne Einstellung bleibt alles wie bisher.

### Neu

- **Speditionshinweis auf der Produktseite** (BRIEF039, Rene 2026-09-23: *„es geht ja um
  Versandkosten, die nicht deutlich sind, also Spedition"*). Ein sechs Meter langes Rohr zeigte
  bisher nur „zzgl. Versandkosten" — dass es per Spedition für gut hundert Euro kommt, stand
  erst im Warenkorb. Jetzt steht darunter ein Kasten: *Versand per Spedition*, der Grund
  (Länge oder Gewicht), die Spanne im Heimatland je nach Postleitzahl und ein Rechner für die
  eigene Postleitzahl.
- **Gerechnet wird wie im Checkout.** Der Artikel kommt in einen Wegwerf-Warenkorb, und den
  rechnet derselbe Dienst durch wie der Versandrechner — dieselben Regeln, Zonen und Bänder.
  Maßgeblich ist die günstigste Lieferung: Steht auch der Paketdienst zur Wahl, gibt es keinen
  Hinweis; die Abholung zählt nicht als Lieferung.
- **Nachgeladen, nicht mitgerendert.** Die Produktseite wartet nicht auf die Berechnung; die
  Spanne liegt danach sechs Stunden im Zwischenspeicher. Beide Endpunkte sind begrenzt.
- Neue Einstellungen, **Vorgabe aus**: Hinweis an/aus, welche Versandarten eine Spedition sind,
  eine Postleitzahl je Zone.

## [1.25.0] - 2026-09-23 — In der Leiste bleibt der Weg zur Kasse sichtbar

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console theme:compile && php bin/console cache:clear`.
> Neue Stile — die Storefront muss neu übersetzt werden.

### Geändert

- **Der Versandkostenrechner in der Leiste ist zugeklappt** — eine Zeile „Versandkosten
  berechnen", ein Klick klappt ihn auf. Aufgeklappt war er rund 280 Pixel hoch und schob die
  Schaltfläche zur Kasse aus dem Fenster: bei 800 px Fensterhöhe auf 1175 px (BRIEF036, Weg A,
  Entscheidung Rene 2026-09-23).
- **Die Schaltfläche zur Kasse klebt am unteren Rand der Leiste.** Artikel, Summe und
  Versandhinweis füllen das Fenster schon allein; ohne das stünde die Schaltfläche auch mit
  zugeklapptem Rechner am Rand. Jetzt ist sie immer zu sehen, gleich wie lang der Warenkorb ist.
- Die zuletzt errechnete Auskunft steht weiterhin offen über dem Rechner.

## [1.24.0] - 2026-09-07 — Ohne Telefonnummer keine Bestellung, wenn der Betreiber es so will

### Neu

- **Fehlt an der Rechnungsadresse die Telefonnummer, hält der Shop die Bestellung an und fragt
  sie auf der Bestätigungsseite ab** (BRIEF038). Neuer Schalter im Verwaltungsbereich, Karte
  „Telefonnummer", **Vorgabe aus** — ohne ihn merkt niemand von diesem Teil etwas.

  **Warum es diesen Teil gibt:** Shopware verlangt die Nummer nur im **Adressformular**. Der
  PayPal-Express-Knopf zeigt dieses Formular nie: PayPal liefert die Anschrift, der Shop legt die
  Adresse damit an — ohne Nummer, wenn PayPal keine mitschickt. Gemessen auf Live über die
  letzten 500 Bestellungen: **5 von 46 Express-Bestellungen ohne Nummer (11 %)**, auf allen
  anderen Wegen zusammen 2 von 452.

  **Der Verstärker, der die Zahl wachsen ließ:** Shopware prüft das Pflichtfeld nur beim Anlegen
  und Bearbeiten einer Adresse, **nicht beim Verwenden einer gespeicherten**. Eine einmal ohne
  Nummer angelegte Adresse blieb dauerhaft ohne und wurde bei jeder Folgebestellung wieder
  gewählt — daher stammen die zwei Fälle, die gar nicht über den Express-Knopf hereinkamen.
  **Deshalb schreibt das Feld die Nummer in die gespeicherte Kundenadresse, nicht nur an die
  Bestellung.** Sonst stünde derselbe Kunde beim nächsten Mal wieder davor.

### Wie es gebaut ist

- **Die Sperre sitzt im Warenkorb, nicht im Formular.** Ein Pflichtfeld auf einer Seite, die nie
  erscheint, verlangt nichts. Der Warenkorb wird auf **jedem** Weg geprüft, auch auf dem, der das
  Formular überspringt.
- **Auf der Warenkorbseite und im eingeblendeten Warenkorb bleibt es still.** Dort gibt es kein
  Feld für die Nummer; der Kunde sähe eine Sperre, die er an dieser Stelle nicht auflösen kann.
- **Ohne Storefront greift sie nicht** — Store-API, Warteschlange, Verwaltungsbereich. Dort kann
  nie ein Feld erscheinen, und eine Sperre wäre eine lautlose Dauerblockade.
- **Die Kennung der Adresse kommt vom Server, nie aus der Anfrage.** Sonst ließe sich über den
  Endpunkt die Adresse eines fremden Kunden beschreiben.
- **Kein Javascript** — ein gewöhnliches Formular. Der Kunde steht kurz vor dem Abschluss; das
  darf nicht daran scheitern, dass ein Skript klemmt.

### Was bei PayPal nicht geht

**PayPal gibt die Nummer nur heraus, wenn sie angefordert wird** — über
`experience_context.contact_preference` in der Bestellanlage. **Weder SwagPayPal 10.8.3 (die
neueste Fassung) noch das aktuelle `shopware/paypal-sdk` kennen dieses Feld**; nachgesehen am
2026-09-07 in beiden Quellen. Ohne Fremd-Code anzufassen ist dieser Weg nicht erreichbar, und auf
den Hersteller zu warten war kein Plan. **Der Shop nimmt eine mitgelieferte Nummer aber bereits
an** — kommt sie eines Tages, greift die Sperre einfach seltener.

## [1.23.0] - 2026-08-26 — In der Leiste steht der Knopf unter den Feldern

### Geändert

- **Im eingeblendeten Warenkorb stehen Land, Postleitzahl und Knopf jetzt untereinander.** Bisher
  richteten sich die Spalten nach der **Fensterbreite** — auf einem breiten Bildschirm stand der
  Knopf deshalb neben den Feldern, obwohl die Leiste nur rund 315 Pixel breit ist, und war zu
  schmal zum Lesen.
- **Auf der Warenkorbseite bleibt die Reihe, wie sie war.** Dort ist der Platz da, und drei Zeilen
  wären dort Verschwendung. Die Vorlage bekommt die Aufteilung von der Stelle, die sie einbindet.

## [1.22.0] - 2026-08-26 — Der Rechner in der Leiste steht dort, wo man ihn sieht

### Geändert

- **Der Versandkostenrechner steht jetzt oberhalb der Schaltfläche zur Kasse.** In 1.21.0 stand er
  darunter, damit der Weg zur Kasse nicht nach unten rutscht — in der schmalen Leiste war er damit
  ohne Scrollen nicht zu sehen, und wer wissen wollte, was der Versand kostet, ging doch wieder auf
  die Warenkorbseite und verließ den Artikel.
- **Der Verweis auf die Warenkorbseite entfällt**, er hat keine Aufgabe mehr: Gerechnet wird an
  Ort und Stelle.
- Die zuletzt errechnete Auskunft steht weiterhin zuoberst, der Rechner darunter zum Neurechnen.

## [1.21.0] - 2026-08-26 — Der Versandkostenrechner steht in der eingeblendeten Leiste

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.
> Kein Neubau der Storefront nötig.

### Neu

- **Legt ein Kunde etwas in den Warenkorb, kann er die Versandkosten sofort in der Leiste
  ausrechnen** — Land und Postleitzahl, Ergebnis an Ort und Stelle. Bisher stand dort nur ein
  Verweis auf die Warenkorbseite. Der Moment, in dem jemand etwas hineinlegt, ist der Moment, in
  dem er nach den Versandkosten fragt (Wunsch Rene, 2026-08-26).
- **Der Rechner steht unter der Schaltfläche zur Kasse**, nicht darüber: Der Weg zur Kasse darf
  nicht nach unten rutschen, nur weil jemand rechnen möchte. Bei 375 px nachgemessen — die
  Schaltfläche liegt unverändert dort, wo sie vorher lag.
- **Es ist derselbe Baustein wie auf der Warenkorbseite.** Dieselbe Berechnung, dasselbe Skript,
  kein zweiter Rechenweg. Die Länderliste kommt aus einem gemeinsamen Dienst, damit nicht die eine
  Stelle ein Land anbietet, das die andere nicht kennt.

### Unverändert

- Wer schon gerechnet hat, sieht in der Leiste weiterhin zuerst seine letzte Auskunft; der Rechner
  steht darunter zum Neurechnen.
- Auf der Warenkorbseite ändert sich nichts.

## [1.20.0] - 2026-08-26 — Die Vorauswahl entscheidet vor dem Abhol-Hinweis

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.
> Kein Neubau der Storefront nötig.

### Behoben

- **Der Abhol-Dialog sprang auf, obwohl auf der fertigen Seite eine Spedition angehakt war.**
  Beide Zuhörer hängen am selben Ereignis; ihre Reihenfolge ergab sich aus der Stelle in der
  Dienstliste. Der Hinweis kam zuerst, las die Abholung aus dem Kontext und hängte den Dialog an
  — Zehntel einer Millisekunde bevor die Vorauswahl umschaltete.
- **Auf Live gemessen, nicht an einer Nachbildung.** Dort war der Fehler nicht zu sehen: Die
  Umschaltung hatte schon eine Anfrage früher stattgefunden, und die sichtbare Seite war bereits
  die zweite. Zwei Fassungen sind deshalb ausgerollt worden, ohne dass sich etwas änderte.
- **Die Reihenfolge ist jetzt festgelegt** — Vorauswahl auf Rang 100, Hinweis auf Rang −100 —
  und ein Test hält den Abstand fest, damit er beim nächsten Umsortieren nicht kippt.

### Entfernt

- **Der Hinweiskasten über der Versandart-Auswahl entfällt.** Er nannte die Versandart, von der
  umgeschaltet wurde, „die sonst übliche" — und das war sie gerade nicht: Sie war der Rückfall,
  auf dem Shopware zurückgeblieben war, und der Kunde hatte sie nie gesehen. Was zählt, steht
  ohnehin in der Auswahl darunter: die angehakte Versandart.

## [1.19.0] - 2026-08-26 — Bleibt nur die Abholung, gehört die Seite dem Kontaktformular

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.
> Kein Neubau der Storefront nötig.

### Geändert

- **Ist für einen Warenkorb keine einzige Lieferart verfügbar, erscheint kein Abhol-Dialog mehr.**
  Bisher stand er neben dem Anfrageweg, und der Kunde bekam zwei Botschaften auf einmal:
  „bestätige, dass du das selbst transportieren kannst" und „für diese Ware ist keine Lieferung
  möglich". Die erste ist in dieser Lage die falsche.
- **Der Hinweistext für diesen Fall sagt jetzt, was Sache ist:** „Für diesen Warenkorb ist kein
  Versand möglich. Bitte sprechen Sie uns an — wir klären Abholung oder Lieferung mit Ihnen
  persönlich." Die Schaltfläche heißt „Kontakt aufnehmen" statt „Lieferung anfragen".
- **Die Bestellung bleibt in dieser Lage gesperrt**, und die Sperrmeldung sagt den Grund: keine
  Bestellung möglich, sondern eine Anfrage. Bisher stand dort „bitte bestätigen Sie den Hinweis"
  und verwies damit auf einen Dialog, den es nicht mehr gibt.

### Unverändert

- **Der häufige Fall ist nicht berührt:** Steht neben der Abholung eine Lieferart zur Wahl, wird
  diese vorausgewählt; wer die Abholung selbst anklickt, bekommt Dialog und Bestätigung wie bisher.
- Schwellen, Wortlaut des Abhol-Hinweises und die Sperre bei bewusst gewählter Abholung sind nicht
  angefasst.

### Wo das gemessen ist

An einer Nachbildung des Live-Standes, mit den Versandarten und Regeln des Shops:

| Warenkorb | Ergebnis |
|---|---|
| 5,5-m-Handlauf, 12,4 kg, Anschrift im Speditionsgebiet | **Spedition vorausgewählt**, kein Dialog, Bestellung frei |
| 960 kg, keine Lieferart verfügbar | kein Dialog, Anfrageweg mit „Kontakt aufnehmen", Bestellung gesperrt |

**Nicht gemessen ist der Livebetrieb selbst.** Welche Versandarten dort für einen bestimmten
Warenkorb übrig bleiben, hängt an Regeln und Anschrift und kann von der Nachbildung abweichen.

## [1.18.0] - 2026-08-26 — Die Belege nennen, dass der Transport Sache des Käufers ist

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.
> Kein Neubau der Storefront nötig.

### Neu

- **Auf Rechnung, Lieferschein, Storno und Gutschrift steht bei Abholbestellungen jetzt der
  Hinweis**, dass Verladen und Transport beim Käufer liegen — unmittelbar unter der Versandart:

  > Selbstabholung: Verladen und Transport der Ware liegen beim Käufer.
  > Bestätigt am 26.08.2026.

- **Maßgeblich ist die Versandart**, nicht die Bestätigung im Bestellvorgang. So tragen auch
  Bestellungen den Hinweis, die ohne Dialog zustande kamen.
- **Die Zeile „Bestätigt am …" erscheint nur, wenn eine Bestätigung vorliegt.** Ein Datum ohne
  Bestätigung sähe auf einem Beleg aus wie ein Nachweis und wäre keiner.
- Der Wortlaut steht in den Textbausteinen `rcCheckout.pickupDocumentNote` und
  `rcCheckout.pickupDocumentConfirmedAt` und lässt sich im Verwaltungsbereich überschreiben.

### Unverändert

- **Bereits erzeugte Belege bleiben, wie sie sind.** Ein Dokument wird nicht neu gerendert; der
  Hinweis erscheint auf allem, was ab der Aktualisierung erzeugt wird.
- Keine Änderung an Dialog, Sperre, Schwellen oder Hinweistext im Bestellvorgang.

## [1.17.1] - 2026-08-26 — Der Rückfall trug sich selbst als Wunsch des Kunden ein

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.
> Kein Neubau der Storefront nötig.

### Behoben

- **Der Befund aus 1.17.0 war nicht behoben.** Auf der Bestätigungsseite stand die Selbstabholung
  weiterhin angehakt, ohne dass jemand sie angeklickt hatte, und der Dialog sprang beim Betreten
  der Seite auf. Nachgemessen am 2026-08-26 an einer Nachbildung des Live-Standes.
- **Die Annahme, auf der 1.17.0 beruhte, stimmt nicht.** Sie ging davon aus, dass nur ein Klick des
  Kunden das Umschalt-Ereignis auslöst und Shopwares eigener Rückfall nicht. Tatsächlich ruft der
  Rückfall denselben Umschaltweg auf — er trug sich damit selbst als eigene Wahl des Kunden ein,
  und die Vorauswahl ließ die Abholung genau deshalb stehen.
- **Unterschieden wird jetzt an der Anfrage, nicht am Ereignis.** Ein Klick in der Auswahl ist ein
  eigener Aufruf mit der Kennung der Versandart in dessen Formulardaten; der Rückfall geschieht
  beim Aufbau einer Seite. Nur das Erste gilt als Wahl.
- **Nachgewiesen, alle fünf Fälle:** Das Betreten der Seite zeigt den Paketdienst und keinen
  Dialog; ein Klick auf die Abholung öffnet den Dialog, und sie bleibt stehen; ein Neuladen ändert
  daran nichts; Escape stellt den Paketdienst zurück; nach dem Bestätigen fällt die Sperre.

### Unverändert

- Über die Store-API gesetzte Versandarten gelten nicht mehr als eigene Wahl. Das ist folgenlos:
  Die Vorauswahl hängt an der Bestätigungsseite der Storefront und läuft nur dort.
- Schwellen, Hinweistext, Dialog und Sperre sind nicht angefasst.

## [1.17.0] - 2026-08-25 — Die Abholung bleibt nur stehen, wenn der Kunde sie gewählt hat

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.
> Kein Neubau der Storefront nötig.

### Behoben

- **Auf der Bestätigungsseite stand die Selbstabholung angehakt, ohne dass jemand sie angeklickt
  hatte** (auf Live gemessen, 2026-08-25). Weil sie angehakt war, sprang auch der
  Bestätigungsdialog schon beim Betreten der Seite auf.
- **Die Ursache liegt nicht in einer Fehleinstellung.** Die Standard-Versandart des Kanals ist der
  Paketdienst. Der greift für einen 2-m-Handlauf nicht, und die Abholung ist die Einzige, die nie
  durchfällt — ihre Verfügbarkeitsregel ist „unter 500 kg und Land Deutschland", also praktisch
  immer. Shopware landet dort von selbst.
- **Die Vorauswahl hielt sich absichtlich heraus**, weil sie nur einsprang, wenn die gewählte
  Versandart *nicht verfügbar* ist. Die Abholung war verfügbar. Richtig gedacht für „der Kunde
  will wirklich abholen", falsch für „Shopware ist dort gelandet".
- **Jetzt wird beides unterschieden.** Ein eigener Klick läuft über `/checkout/configure` und
  feuert ein Ereignis; ein Rückfall feuert nichts. Der neue `ShippingChoiceStore` merkt sich die
  eigene Wahl für die Sitzung. Eine Versandart aus der Liste „keine Lieferung" bleibt nur stehen,
  wenn dieser Vermerk auf sie zeigt.
- **Der Dialog kommt damit erst nach dem Klick** — die ursprüngliche Vorgabe. Ohne eigenen Klick
  steht die Abholung gar nicht mehr da.

> **Nachtrag:** Der Satz „ein Rückfall feuert nichts" trifft nicht zu — deshalb hat diese Fassung
> den Befund nicht behoben. Siehe 1.17.1.

### Unverändert

- Bleibt keine lieferfähige Versandart übrig, wird **nicht** umgeschaltet: Dann ist die Abholung
  die einzige Möglichkeit, und der Anfrageweg zeigt das Kontaktformular.
- Ohne Sitzung (Store-API, Headless) gilt jede Versandart als eigene Wahl. Ein stilles Umschalten
  wäre dort der schwerere Fehler — es bemerkt niemand, und niemand kann es rückgängig machen.
- Schwellen, Hinweistext, Dialog und Sperre sind nicht angefasst.

## [1.16.0] - 2026-08-25 — Der Abhol-Hinweis nennt Gewicht und Länge

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.
> Kein Neubau der Storefront nötig.

### Geändert

- **Der Hinweis sagt jetzt, worum es geht** (Wunsch Rene, 2026-08-25): „Sie haben Selbstabholung
  gewählt. Bitte stellen Sie sicher, dass Sie die gekaufte Ware transportieren können. Die Ware in
  Ihrem Warenkorb wiegt insgesamt 4,6 kg und hat eine maximale Länge von 2,00 m." Bisher stand dort
  nur „wegen Gewicht oder Länge" — der Kunde erfuhr nicht, wie viel, und musste raten, ob es ins
  Auto passt.
- **Platzhalter `{Gewicht}` und `{Länge}`** (auch `{weight}` / `{length}`) wirken **im eigenen Text
  des Betreibers genauso wie im mitgelieferten**. Sie stehen samt Beispiel in der Feldbeschreibung
  im Verwaltungsbereich.
- **Fehlt eines der beiden Maße, wird es nicht genannt.** Ein Warenkorb, in dem kein Produkt eine
  Länge trägt, ergäbe sonst „maximale Länge von 0,00 m" — eine falsche Aussage in genau dem Satz,
  mit dem der Kunde entscheiden soll. Es gibt deshalb drei Textbausteine statt einem.
- **Folge, die ausdrücklich gewollt ist:** Die Bestätigung wird zum Wortlaut gespeichert. Stehen
  die Maße darin, verfällt sie, sobald sich der Warenkorb ändert — wer „4,6 kg" bestätigt und
  danach zwei Bleche dazulegt, bekommt den Dialog erneut. Zugestimmt wurde dem Transport *dieser*
  Ware.

## [1.15.1] - 2026-08-25 — Die Abhol-Sperre erscheint nicht mehr im Warenkorb

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.
> Kein Neubau der Storefront nötig — es ändert sich nur PHP.

### Behoben

- **Die rote Sperrmeldung stand auf der Warenkorbseite**, wo es weder Versandartenauswahl noch
  Dialog gibt (auf Live gemessen, 2026-08-25). Der Kunde konnte sie dort nicht auflösen — und er
  hatte die Abholung oft gar nicht selbst gewählt, sie war bei langen oder schweren Warenkörben
  schlicht die einzige verbliebene Versandart. Getroffen hat es damit ausgerechnet den Kunden mit
  dem größten Warenkorb, auf der ersten Seite des Bestellvorgangs.
- Die Meldung bleibt jetzt in den Warenkorb-Ansichten still (`frontend.checkout.cart.page`,
  `frontend.cart.offcanvas`, `frontend.checkout.info`). **Die Sperre selbst ist unverändert:** Wer
  `/checkout/order` ohne Bestätigung aufruft, wird weiterhin abgewiesen.
- Bewusst eine **Ausschlussliste statt einer Erlaubnisliste**: Eine Erlaubnisliste fiele still ins
  Offene, sobald Shopware eine Route umbenennt, und eine unbestätigte Bestellung ginge durch. Die
  Ausschlussliste fällt in die harmlose Richtung.

## [1.15.0] - 2026-08-25 — Abbrechen stellt die Versandart zurück

> **Deployment:** `bin/build-storefront.sh`, dann
> `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.

### Geändert

- **Wer den Dialog schließt, will nicht abholen — also wird die Versandart zurückgestellt**
  (Entscheidung Rene, 2026-08-25). Escape und „Abbrechen — doch liefern lassen" schalten auf die
  Versandart um, die auch die Vorauswahl nähme. Danach trifft die Bedingung nicht mehr zu, der
  Hinweis verschwindet, und der Warenkorb ist wieder in einem gültigen Zustand.
- **Damit gibt es die Sackgasse nicht mehr**, und Escape bleibt trotzdem **keine** Zustimmung: Die
  Abholung ist danach schlicht nicht mehr gewählt. Wer sie erneut wählt, bekommt den Dialog wieder
  — so lange, bis er bestätigt hat. Die Zusage entsteht ausschließlich über „Verstanden".
- Der Rückweg läuft über **Shopwares eigenen Umschaltweg als Formular**, nicht per Skript: So
  wirkt der Abbruch auch ohne JavaScript, und die Umschaltung durchläuft dieselbe Prüfung wie jede
  andere Wahl der Versandart.
- Zurückgestellt wird auf die Versandart der **Vorauswahl**, nicht auf die Standard-Versandart des
  Kanals. Die ist bei schwerer Ware oft gar nicht verfügbar — genau deshalb gibt es die Vorauswahl.
  Zwei Logiken wären zwei Ergebnisse.

### Grenze

- Bleibt außer der Abholung **keine** Lieferart übrig, gibt es nichts, worauf zurückgestellt
  werden könnte. Dieser Fall gehört dem Anfrageweg mit dem Kontaktformular; dort bleibt es beim
  bloßen Schließen, und der Kasten trägt den Weg zurück in den Dialog.

## [1.14.2] - 2026-08-25 — Kein Weg in die Sackgasse, und Escape wirkt überall

> **Deployment:** `bin/build-storefront.sh`, dann
> `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.

### Neu

- **Der Hinweiskasten trägt jetzt „Hinweis lesen und bestätigen"** und holt den Dialog zurück.
  Vorher war das Schließen eine Sackgasse: Die Bestellung blieb gesperrt, und der einzige Ausweg
  war ein Neuladen der Seite.

### Behoben

- **Escape schloss den Dialog nicht mehr, sobald der Fokus auf der abgedunkelten Fläche lag.** Die
  Fläche ist der Dialog selbst, aber nicht fokussierbar — wer darauf klickt, schiebt den Fokus auf
  den Seitenkörper, und von dort steigt ein Tastendruck nicht in den Dialog hinab. Der Hänger sitzt
  jetzt am Dokument, solange der Dialog offen ist, und wird beim Schließen wieder abgeräumt.

### Bewusst nicht geändert

- **Escape gilt weiterhin nicht als Bestätigung.** Die Zusage wird samt Wortlaut und Zeitpunkt an
  der Bestellung festgehalten; käme sie durch Wegklicken zustande, wäre der Nachweis nicht schwach,
  sondern falsch — er behauptete etwas, das nicht stattgefunden hat.

## [1.14.1] - 2026-08-25 — Zwei Mängel, die erst die Sichtprüfung auf Staging gezeigt hat

> **Deployment:** `bin/build-storefront.sh`, dann
> `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.

### Behoben

- **Die Sperrmeldung zeigte ihren rohen Schlüssel** (`checkout.rc-checkout-pickup-acknowledgement-required`)
  statt eines Satzes. Shopware sucht Warenkorb-Fehler unter `checkout.<Schlüssel>`; der Textbaustein
  fehlte in beiden Sprachen. Der Kunde las damit Maschinensprache an der Stelle, an der ihm erklärt
  werden sollte, warum er nicht bestellen kann.
- **Nach dem Schließen mit Escape blieb der Fokus im Dialog** — auf einer Schaltfläche, die in
  diesem Moment `hidden` ist. Für Tastaturbedienung und Vorlesewerkzeuge eine Sackgasse. Der Fokus
  geht jetzt auf den Hinweiskasten, der die Sperre weiterhin erklärt.

## [1.14.0] - 2026-08-25 — Der Abhol-Hinweis wird ein Dialog, der bestätigt werden muss

> **Deployment:** `bin/build-storefront.sh`, dann
> `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.
> **Storefront-Bau nötig** — es kommt erstmals Storefront-JavaScript für diesen Teil dazu.

### Neu

- Wer schwere oder lange Ware zur Selbstabholung wählt, sieht den Hinweis jetzt als **Dialog, der
  bestätigt werden muss**. Ohne Bestätigung lässt sich die Bestellung nicht abschließen.
- **Zeitpunkt und Wortlaut der Bestätigung stehen an der Bestellung** (Zusatzfeld
  `rc_checkout_pickup_acknowledgement`). Der Hinweis sagt zu, dass Verladen und Transport beim
  Kunden liegen — wer das später bestreitet, tut es Wochen danach. Ein Nachweis, den es dann nicht
  mehr gibt, ist keiner.
- Der Wortlaut wird **mitgeschrieben, nicht nur ein Häkchen**: Ändert sich der Hinweistext in der
  Konfiguration, sagt ein bloßes „bestätigt" nichts mehr darüber aus, was bestätigt wurde. Aus
  demselben Grund gilt eine Bestätigung nur für genau den Satz, der bestätigt wurde.

### Behoben

- Der Hinweiskasten klebte an der Versandartenliste — er bekommt seinen Abstand nach oben.

### Innen

- Die Bedingung „ist der Hinweis fällig?" liegt jetzt in einem eigenen Dienst und wird von
  Dialog **und** Sperre gelesen. Zwei Kopien wären der sichere Weg zu dem Tag, an dem die eine
  greift und die andere nicht.
- Die Sperre ist **serverseitig**. Eine im Browser deaktivierte Schaltfläche ist keine Sperre; wer
  den Bestellaufruf von Hand absetzt, käme daran vorbei.
- **Ohne Storefront-Sitzung greift die Pflicht nicht** — Store-API, Headless und von Hand angelegte
  Bestellungen haben keine Bestellseite und damit keine Möglichkeit zu bestätigen. Griffe sie dort,
  wären solche Bestellungen dauerhaft blockiert.
- Der Hinweistext wird jetzt in PHP aufgelöst statt in der Vorlage: Festgehalten werden muss der
  Satz, den der Kunde gelesen hat — auch dann, wenn er aus dem Textbaustein stammt und nicht aus
  der Einstellung.

## [1.13.0] - 2026-08-24 — Vorauswahl der Versandart, wenn die eingestellte nicht verfügbar ist

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.
> Kein Storefront-Bau nötig.
>
> **Vor dem Einschalten prüfen:** Unter „Anfrage bei nicht möglicher Lieferung“ muss die
> Selbstabholung in „Versandarten, die keine Lieferung sind“ eingetragen sein. Sonst kann die
> Vorauswahl auf sie fallen, sobald sie die erste verfügbare nach Position ist.

### Neu

- **Ist die eingestellte Versandart für einen Warenkorb nicht verfügbar, wird eine passende vorausgewählt.** Bisher hakte Shopware in diesem Fall nichts an: Der Kunde stand vor einer Liste ohne Auswahl. Wer sich auskannte, wählte die kostenlose Selbstabholung — am 21.08.2026 kamen so zwei Abholbestellungen aus Bremen und Ötigheim; wer sich nicht auskannte, rief an. Vorausgewählt wird die **Standard-Versandart des Verkaufskanals**, wenn sie für diesen Warenkorb möglich ist (bei uns der Paketversand) — sonst die erste verfügbare nach **Position**, also der Reihenfolge, in der die Storefront die Liste ohnehin zeigt. Versandarten, die als „keine Lieferung“ eingetragen sind, bleiben außen vor — auch dann, wenn nur sie übrig bleiben; dort greift weiterhin der Anfrageweg.
- **Ein ruhiger Hinweis sagt, warum.** Wird umgeschaltet, steht unmittelbar über der Versandart-Auswahl ein Hinweis mit beiden Namen — dort, wo der Kunde die Änderung sieht, und nicht bei den Seitenhinweisen ganz oben. Er hält die Bestellung nicht auf und löst keine Weiterleitung aus.
- **Schalter „Versandart vorauswählen“**, ab Werk an, je Verkaufskanal einstellbar.


## [1.12.1] - 2026-08-24 — Die Bestellseite nutzt die volle Breite, die Karten stehen bündig

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console theme:compile && php bin/console cache:clear`.
> **Der Storefront-Bau ist nötig** — die Änderung sitzt im Stilblatt.

### Behoben

- **Mit der Warenkorb-Leiste blieb der Bestellseite ein knappes Drittel der Fensterbreite.** Shopware zieht die Bestellseite bewusst schmal zusammen — richtig für eine einspaltige Seite, aber sobald die Leiste dazukommt, wird diese schmale Spalte noch einmal geteilt. Auf einem 1920 Pixel breiten Bildschirm blieben dem Hauptbereich 572 Pixel und den Karten für Zahlungs- und Versandart je 266; dort brach schon „Zahlung bei Erhalt der Ware“ auf zwei Zeilen um, während links und rechts je rund 500 Pixel leer standen. Solange die Leiste läuft, nutzt die Seite jetzt die volle Breite: Der Hauptbereich wächst auf 867 Pixel, die Leiste auf 453. **Ohne Leiste bleibt alles wie im Standard** — auch in der Vergleichsgruppe eines A/B-Tests.
- **Die untere Reihe stand nicht unter dem, was über ihr steht.** Zusatzangaben und Bestellübersicht rechneten im Raster des Kerns, der Bereich darüber nach der Leiste — vier Blöcke mit vier verschiedenen Kanten und einer Lücke am rechten Rand. Beide Reihen rechnen jetzt gleich: Die Zusatzangaben stehen exakt unter dem Hauptbereich, die Bestellübersicht exakt unter der Leiste. Der Zwischenraum kommt aus dem Raster des Themes, gilt also auch für Themes, die anders rechnen als der Standard.


## [1.12.0] - 2026-08-20 — Hinweis bei Selbstabholung schwerer oder langer Ware

> **Deployment:** `php bin/console plugin:update RcCheckoutEnhancer && php bin/console cache:clear`.
> Kein Storefront-Bau nötig.

### Neu

- **Wer schwere oder lange Ware selbst abholen will, wird gewarnt.** Wählt ein Kunde auf der
  Bestätigungsseite eine Versandart, die als „keine Lieferung" eingetragen ist, und überschreitet
  sein Warenkorb dabei eine Gewichts- oder Längenschwelle, steht unmittelbar unter der Auswahl ein
  Hinweis. Damit fährt niemand mit dem Kombi vor, um sechs Meter Handlauf und 300 kg mitzunehmen.
- **Zwei neue Schwellen je Verkaufskanal:** Gewicht in kg und Länge in mm. Ein Treffer genügt,
  **beide leer bedeutet: der Hinweis ist aus** — dann ändert sich gegenüber 1.11.0 nichts.
  Gemessen wird das Gesamtgewicht, aber die längste **einzelne** Position: Zwanzig Handläufe zu
  sechs Metern sind nicht 120 Meter lang, sondern zwanzigmal zu lang fürs Auto.
- **Der Hinweistext ist überschreibbar**, mit mitgeliefertem Standardtext auf Deutsch und Englisch.

### Warum ohne Javascript

Der Hinweis hängt an der **gewählten** Versandart und erscheint trotzdem serverseitig: Shopware
lädt die Bestätigungsseite bei jedem Wechsel der Versandart neu, die Auswahl steht also im Kontext,
bevor gerendert wird. Ein Skript, das auf den Klick lauert, wäre eine zweite Wahrheit daneben — und
der Anfrageweg ist aus demselben Grund ohne Javascript gebaut.

### Intern

Die Rechnung für Gesamtgewicht und längste Position stand bisher in `ShippingEnquirySummary`. Sie
liegt jetzt in `CartMeasurements` und wird von beiden Stellen benutzt.

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
