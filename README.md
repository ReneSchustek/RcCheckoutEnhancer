# RcCheckoutEnhancer – Checkout verbessern für Shopware 6

Verbessert den Shopware-Standard-Checkout mit Fortschrittsanzeige, Vertrauenssignalen, Mini-Warenkorb und Bestellzusammenfassung. Alle Features per Admin konfigurierbar.

## Features

- **Progress-Bar:** Schritt-für-Schritt-Anzeige mit klickbarer Zurück-Navigation
- **Vertrauenssignale:** Konfigurierbare Trust-Badges mit Icons (Schloss, LKW, Rückgabe)
- **Mini-Warenkorb:** Kompakte Warenkorbübersicht als Sidebar auf der Bestätigungsseite
- **Bestellzusammenfassung:** Adresse, Versand, Zahlung und Gesamtbetrag auf einen Blick
- **Lieferzeitschätzung:** Optionaler Hinweis auf geschätzte Lieferzeit
- **Alles optional:** Jedes Feature einzeln an-/abschaltbar im Admin

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
| Bestellzusammenfassung | An/Aus |
| Lieferzeit | An/Aus + Freitext |

## Deployment

| Änderung | Befehl |
|----------|--------|
| Nur PHP/Twig | `bin/console cache:clear` |
| SCSS geändert | `bin/console theme:compile` |
| Erstinstallation | `bin/console theme:compile` |

## Lizenz

MIT
