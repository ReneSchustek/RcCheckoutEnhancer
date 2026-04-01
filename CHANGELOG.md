# Changelog

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
