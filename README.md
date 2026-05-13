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

<!-- TRIAGE-WORKFLOW: auto-managed by triage-deploy.ps1 -->
## Triage und Reviews

- **Watcher starten:** `.\triage-watch.ps1` (bzw. `.\triage-watch-php.ps1` / `.\triage-watch-shopware.ps1`) im Projekt-Root
- **Review on-demand:** `.\triage-review.ps1` -- laedt Projekt-Regeln aus `.ai/rules/` und uebergibt sie an Ollama
- **Enterprise-Review (ERP-2026):** in Claude Code anfragen -- Claude orchestriert, Ollama macht mechanische Sub-Tasks
- **Status-Dateien:** `.ai/triage-status.json`, `.ai/triage-escalation.md`, `.ai/reviews/*.md`, `.ai/erp/*.md`

Volle Doku: `F:\Entwicklung\_Anleitungen\allgemein\triage-workflow.md`
Routing-Regeln: `.ai/rules/ollama-delegation.md` und `.ai/rules/enterprise-review.md`
<!-- /TRIAGE-WORKFLOW -->
