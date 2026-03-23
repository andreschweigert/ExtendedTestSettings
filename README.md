# ExtendedTestSettings Plugin für ILIAS

![ILIAS](https://img.shields.io/badge/ILIAS-9.x-blue)
![Version](https://img.shields.io/badge/version-1.1.0-success)
![License](https://img.shields.io/badge/license-TBA-lightgrey)

**Version:** 1.1.0
**Für ILIAS:** 9.0 - 9.999

Das **ExtendedTestSettings** Plugin erweitert ILIAS-Tests um Funktionen für Online-Prüfungen. Es bietet ein modulares System mit fünf spezialisierten Tools, die individuell pro Test aktiviert werden können.

---

## Features

Das Plugin bietet fünf spezialisierte Module für Online-Prüfungen:

- **ExamCalc** - Taschenrechner für Prüfungen (einfach oder wissenschaftlich)
- **ExamSymbols** - Sonderzeichen-Paletten für Fachbereiche
- **ExamGuard** - Prüfungsschutz durch Eingabeeinschränkungen
- **ExamAlert** - Zeitbasierte Warnungen vor Prüfungsende
- **ExamChecker** - Automatisierte Prüfung der Testeinstellungen

---

## Quick Start

```bash
# 1. Plugin klonen
cd /pfad/zu/ilias/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/
git clone https://github.com/andreschweigert/ExtendedTestSettings ExtendedTestSettings

# 2. Abhängigkeiten installieren
cd /pfad/zu/ilias
composer install --no-dev

# 3. In ILIAS aktivieren
# Administration → Plugins → ExtendedTestSettings → Installieren & Aktivieren
```

Nach der Aktivierung erscheint in jedem ILIAS-Test ein neuer Tab **"Erweiterte Test-Einstellungen"**, in dem die Module individuell konfiguriert werden können.

---

## Installation

### Schritt 1: Plugin-Dateien herunterladen

```bash
cd /pfad/zu/ilias/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/
git clone https://github.com/andreschweigert/ExtendedTestSettings ExtendedTestSettings
```

### Schritt 2: Abhängigkeiten installieren

```bash
cd /pfad/zu/ilias
composer install --no-dev
```

### Schritt 3: Plugin in ILIAS aktivieren

1. Als Administrator in ILIAS einloggen
2. Navigieren zu: **Administration → Plugins**
3. Plugin **ExtendedTestSettings** in der Liste suchen
4. Auf **Installieren** klicken
5. Nach erfolgreicher Installation auf **Aktivieren** klicken

### Schritt 4: Installation verifizieren

1. Navigieren Sie zu einem beliebigen Test
2. Öffnen Sie **Einstellungen**
3. Sie sollten einen neuen Tab **"Erweiterte Test-Einstellungen"** sehen

**Installation erfolgreich!**

---

## Module im Detail

### ExamCalc - Taschenrechner

Stellt einen Taschenrechner während der Prüfung bereit.

**Modi:**
- **Einfach**: Grundrechenarten (+ − × ÷) 
- **Wissenschaftlich**: Erweiterte Funktionen (sin, cos, tan, √, ^, log, ln, π, e) - noch in Entwicklung

**Hinweis:** Der wissenschaftliche Modus ist noch in der Entwicklung und nicht für den Produktivbetrieb geeignet.

**Bedienung:**
- Tastenkombination: `Alt + R`
- Verschiebbar per Drag & Drop
- Automatische Bereinigung bei Prüfungsende

---

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

---

### ExamGuard - Prüfungsschutz

Blockiert kritische Eingaben zum Schutz der Prüfungsintegrität.

**Blockierte Aktionen:**
- Rechtsklick und Kontextmenü
- Copy/Paste/Cut (Strg+C/V/X)
- Alles markieren (Strg+A)
- Entwickler-Tools (F12, Strg+Shift+I/J/C)
- Textauswahl (außer in Eingabefeldern)

**Hinweis:** ExamGuard bietet client-seitige Sicherheit und sollte als zusätzliche Barriere, nicht als alleinige Sicherheitsmaßnahme verstanden werden. 

---

### ExamAlert - Zeit-Warnung

Warnt Teilnehmer rechtzeitig vor Prüfungsende.

**Funktionen:**
- Konfigurierbare Warnzeit: 5 Sekunden bis 1 Stunde vor Ende
- Anpassbare Warnnachrichten (HTML-Formatierung möglich)
- Zweisprachige Standard-Nachricht (Deutsch/Englisch)
- Live-Countdown der verbleibenden Zeit
- Dezente Benachrichtigung von oben
- Auto-Aktivierung für neue Tests (konfigurierbar)

**Tipp:** Die Standard-Warnzeit beträgt 30 Sekunden. Die Warnung wird nur einmal pro Test-Session angezeigt.

---

### ExamChecker - Einstellungs-Prüfung

Überprüft Testeinstellungen gegen definierte Vorgaben.

**Funktionen:**
- Auswahl: Präsenz- oder Fernprüfung
- Detaillierter Report mit Ampel-System
- Prozentuale Bewertung der Konfiguration
- Prüfung auf Vorbedingungen und zeitliche Verfügbarkeit
- Hinweise auf kritische Einstellungen

**Konfiguration:** Standards können in der Plugin-Konfiguration (Administration → Plugins → Konfigurieren) angepasst werden.

---

## Datenbankstruktur

Das Plugin erstellt folgende Tabellen automatisch bei der Installation:

| Tabelle | Beschreibung |
|---------|-------------|
| `examcalc_settings` | Taschenrechner-Konfiguration pro Test |
| `examsymbols_settings` | Symbol-Vorlagen pro Test |
| `examguard_settings` | Eingabeschutz-Einstellungen pro Test |
| `examalert_settings` | Zeit-Warnungen pro Test |
| `extendedtest_users` | Berechtigte Benutzer für Tab-Sichtbarkeit |

Bei Deinstallation werden alle Tabellen automatisch entfernt.

---

## Konfiguration

### Plugin-Konfiguration (Global)

Unter **Administration → Plugins → ExtendedTestSettings → Konfigurieren** können Sie folgende Einstellungen vornehmen:

- **Tab-Sichtbarkeit**: Wer darf den "Erweiterte Test-Einstellungen" Tab sehen?
  - System-Administratoren
  - Kurs-Administratoren (Standard)
  - Spezifische Benutzer

- **ExamAlert Auto-Aktivierung**: Soll ExamAlert für neue Tests automatisch aktiviert werden?

- **ExamChecker Standards**: Definieren Sie die Prüfkriterien für Präsenz- und Fernprüfungen.

### Test-Konfiguration (Pro Test)

In jedem Test unter **Einstellungen → Erweiterte Test-Einstellungen** können die Module einzeln aktiviert und konfiguriert werden.

---

## Projektstruktur

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
│       └── class.ilExamCheckerHandler.php
├── js/          # JavaScript-Module
├── css/         # Stylesheets
├── templates/   # HTML-Templates
└── sql/         # Datenbank-Migrations
```

---

## Support & Kontakt

**Entwickler:**
- André Schweigert (Andre.Schweigert@fau.de)
- Cornel Musielak (Cornel.Musielak@fau.de)

**Institution:**
Friedrich-Alexander-Universität Erlangen-Nürnberg (FAU)

**Repository:**
https://github.com/andreschweigert/ExtendedTestSettings

---

## Contributing

Wir freuen uns über Beiträge! Das Plugin ist Open Source.

**Möglichkeiten:**
- Bugs melden (Issues)
- Features vorschlagen
- Code beisteuern (Pull Requests)
- Dokumentation verbessern
- Übersetzungen hinzufügen

---

## Lizenz

**Status:** TBA (To Be Announced)

**Haftungsausschluss:**
Das ExtendedTestSettings Plugin wird "wie gesehen" bereitgestellt, ohne jegliche ausdrückliche oder stillschweigende Gewährleistung. Die Entwickler übernehmen keine Haftung für Datenverlust, Prüfungsausfälle, Sicherheitsvorfälle oder technische Probleme.

**Empfehlung:** Testen Sie das Plugin ausführlich in einer Test-Umgebung, bevor Sie es in Produktiv-Prüfungen einsetzen.

---

## Changelog

### Version 1.1.0 (2026-03-23)

**GUI-Refactoring:**
- ConfigGUI und AdvancedGUI vollständig auf ILIAS KitchenSink UI-Komponenten umgestellt (kein rohes HTML mehr)
- Statistik-Seite: Panel/Report-Layout mit Modul-Details und klickbaren Test-Links
- Benutzer-Verwaltung: Item-Listen mit Avataren und Interruptive-Modals
- Test-Einstellungen: ILIAS-Standard-Formularelemente mit Checkbox-Sub-Items

**Neue Features:**
- ExamAlert Auto-Aktivierungs-Einstellung in der globalen Konfiguration

**Verbesserungen:**
- Statistiken ohne systemweite Gesamtanzahl (Performance)
- Modul-Sektionen mit Icons in den Test-Einstellungen

---

### Version 1.0.2 (2024-12-04)

**Neue Features:**
- ExamAlert Auto-Aktivierung für neue Tests
- ExamChecker: Konfigurierbare Standards
- Tab-Sichtbarkeit: Modus "Spezifische Benutzer"

**Verbesserungen:**
- ExamAlert: Bessere Timer-Erkennung (3 Methoden)
- ExamSymbols: 8 Templates (neu: Physik, Chemie)
- Performance: Optimierte DB-Queries

**Bugfixes:**
- ExamCalc: Drag & Drop auf Mobile-Geräten
- ExamGuard: Text-Selection in Eingabefeldern erlaubt
- ExamAlert: Warnung wird nur noch einmal pro Test angezeigt

### Version 1.0.1 (2024-11-11)

**Initial Release:**
- ExamCalc: Taschenrechner (einfach/wissenschaftlich)
- ExamSymbols: 6 Templates
- ExamGuard: Prüfungsschutz
- ExamAlert: Zeit-Warnungen
- ExamChecker: Einstellungsprüfung

---

**Hinweis:** Dieses Plugin wird aktiv weiterentwickelt. Feedback und Verbesserungsvorschläge sind willkommen!

**Stand:** März/2026
