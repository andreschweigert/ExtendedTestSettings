# ExtendedTestSettings Plugin für ILIAS

**Version 1.0.0** | ILIAS 9.x

Das **ExtendedTestSettings** Plugin erweitert ILIAS-Tests um Funktionen für Online-Prüfungen. Es bietet ein modulares System mit fünf spezialisierten Tools, die individuell pro Test aktiviert werden können.

## Features

- **ExamCalc** - Taschenrechner für Prüfungen (einfach oder wissenschaftlich)
- **ExamSymbols** - Sonderzeichen-Paletten für Fachbereiche
- **ExamGuard** - Prüfungsschutz durch Eingabeeinschränkungen
- **ExamAlert** - Zeitbasierte Warnungen vor Prüfungsende
- **ExamChecker** - Automatisierte Prüfung der Testeinstellungen

## Installation

### 1. Plugin installieren

```bash
cd /path/to/ilias/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/
git clone https://github.com/andreschweigert/ExtendedTestSettings ExtendedTestSettings

Im ILIAS Verzeichnis:
composer install --no-dev
```

### 2. Plugin aktivieren

- Navigieren zu: **Administration → Plugins**
- Plugin **ExtendedTestSettings** suchen und installieren
- Plugin aktivieren

### 3. Verwendung

Nach der Aktivierung erscheint in jedem ILIAS-Test ein neuer Tab **"Erweiterte Test-Einstellungen"**, in dem die Module individuell konfiguriert werden können.

## Module im Detail

### ExamCalc - Taschenrechner

Stellt einen Taschenrechner während der Prüfung bereit.

**Modi:**
- **Einfach**: Grundrechenarten (+ − × ÷) - 
- **Wissenschaftlich**: Erweiterte Funktionen (sin, cos, tan, √, ^, log, ln, π, e) - Alpha-Version

**Bedienung:**
- Tastenkombination: `Alt + R`
- Verschiebbar per Drag & Drop
- Automatische Bereinigung bei Prüfungsende

### ExamSymbols - Sonderzeichen

Bietet fachspezifische Sonderzeichen-Paletten für Prüfungen.

**Verfügbare Vorlagen:**
- **Mathematik/Wissenschaft**: π, √, ∑, ∞, ≈, ≠, ≤, ≥, ±, ∆, ∫, ∂, griechische Buchstaben
- **Sprachen**: Spanisch, Italienisch, Deutsch, Französisch, Griechisch
- **Naturwissenschaften**: Chemie (Reaktionspfeile, Ladungen), Physik (Vektoren, Differentiale)

**Bedienung:**
- Tastenkombination: `Alt + S`
- Kompatibel mit TinyMCE und CKEditor
- Responsive Design für alle Geräte

**Erweiterung:** Neue Vorlagen können in `classes/ExamSymbols/class.ilExamSymbolsConfig.php` hinzugefügt werden.

### ExamGuard - Prüfungsschutz

Blockiert kritische Eingaben zum Schutz der Prüfungsintegrität.

**Blockierte Aktionen:**
- Rechtsklick und Kontextmenü
- Copy/Paste/Cut (Strg+C/V/X)
- Alles markieren (Strg+A)
- Entwickler-Tools (F12, Strg+Shift+I/J/C)
- Textauswahl (außer in Eingabefeldern)

**Hinweis:** ExamGuard bietet client-seitige Sicherheit und sollte als zusätzliche Barriere, nicht als alleinige Sicherheitsmaßnahme verstanden werden.

### ExamAlert - Zeit-Warnung

Warnt Teilnehmer rechtzeitig vor Prüfungsende.

**Funktionen:**
- Konfigurierbare Warnzeit: 5 Sekunden bis 1 Stunde vor Ende
- Anpassbare Warnnachrichten (HTML-Formatierung möglich)
- Zweisprachige Standard-Nachricht (DE/EN)
- Live-Countdown der verbleibenden Zeit
- Dezente Benachrichtigung von oben

**Tipp:** Die Standard-Warnzeit beträgt 30 Sekunden.

### ExamChecker - Einstellungs-Prüfung

Überprüft Testeinstellungen gegen definierte Vorgaben.

**Funktionen:**
- Auswahl: Präsenz- oder Fernprüfung
- Detaillierter Report mit Ampel-System
- Prozentuale Bewertung der Konfiguration
- Prüfung auf Vorbedingungen und zeitliche Verfügbarkeit
- Hinweise auf kritische Einstellungen

**Konfiguration:** Standards können in der Plugin-Konfiguration (Administration → Plugins) angepasst werden.

## Datenbankstruktur

Das Plugin erstellt folgende Tabellen automatisch bei der Installation:

| Tabelle | Beschreibung |
|---------|-------------|
| `examcalc_settings` | Taschenrechner-Konfiguration pro Test |
| `examsymbols_settings` | Symbol-Vorlagen pro Test |
| `examguard_settings` | Eingabeschutz-Einstellungen pro Test |
| `examalert_settings` | Zeit-Warnungen pro Test |

Bei Deinstallation werden alle Tabellen automatisch entfernt.

## Konfiguration

### Plugin-Konfiguration

Unter **Administration → Plugins → ExtendedTestSettings → Konfigurieren** können Sie folgende Einstellungen vornehmen:

- **Tab-Sichtbarkeit**: Wer darf den "Erweiterte Test-Einstellungen" Tab sehen?
  - System-Administratoren
  - Kurs-Administratoren (Standard)
  - Spezifische Benutzer

- **ExamAlert Auto-Aktivierung**: Soll ExamAlert für neue Tests automatisch aktiviert werden?

- **ExamChecker Standards**: Definieren Sie die Prüfkriterien für Präsenz- und Fernprüfungen.

### Test-Konfiguration

In jedem Test unter **Einstellungen → Erweiterte Test-Einstellungen** können die Module einzeln aktiviert und konfiguriert werden.

### Struktur

Das Plugin folgt einer Handler-basierten Architektur:

```
ExtendedTestSettings/
├── classes/
│   ├── class.ilExtendedTestSettingsPlugin.php      # Plugin-Hauptklasse
│   ├── class.ilExtendedTestSettingsUIHookGUI.php   # UI-Hook Integration
│   ├── class.ilExtendedTestSettingsAdvancedGUI.php # Einstellungs-GUI
│   ├── ExamCalc/
│   │   ├── class.ilExamCalcHandler.php
│   │   └── class.ilExamCalcConfig.php
│   ├── ExamSymbols/
│   │   ├── class.ilExamSymbolsHandler.php
│   │   └── class.ilExamSymbolsConfig.php
│   ├── ExamGuard/
│   │   └── class.ilExamGuardHandler.php
│   ├── ExamAlert/
│   │   └── class.ilExamAlertHandler.php
│   └── ExamChecker/
│       ├── class.ilExamCheckerHandler.php
│       └── class.ilExamCheckerConfig.php
├── js/          # JavaScript-Module
├── css/         # Stylesheets
├── templates/   # HTML-Templates
└── sql/         # Datenbank-Migrations
```
## Support & Kontakt

**Entwickler:**
- André Schweigert (Andre.Schweigert@fau.de)
- Cornel Musielak (Cornel.Musielak@fau.de)

**Institution:**
Friedrich-Alexander-Universität Erlangen-Nürnberg (FAU)

## Lizenz

tba


## Changelog

### Version 1.0.0 (2025)

---

**Hinweis:** Dieses Plugin wird aktiv weiterentwickelt. Feedback und Verbesserungsvorschläge sind willkommen!
