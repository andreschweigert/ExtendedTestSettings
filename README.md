# ExtendedTestSettings Plugin für ILIAS

![ILIAS](https://img.shields.io/badge/ILIAS-9.x-blue)
![Version](https://img.shields.io/badge/version-1.1.2-success)
![License](https://img.shields.io/badge/license-TBA-lightgrey)

**Version:** 1.1.2  
**Für ILIAS:** 9.0 – 9.999

Das **ExtendedTestSettings** Plugin erweitert ILIAS-Tests um Funktionen für Online-Prüfungen. Es basiert auf einem **modularen System**: Funktionalität wird in eigenständigen Modulen gebündelt, die über die Plugin-Administration als ZIP hochgeladen, aktiviert und deaktiviert werden können – ohne den Plugin-Kern zu ändern.

---

## Features

Das Plugin liefert ein **built-in Modul** (direkt im Plugin enthalten) sowie eine **Infrastruktur für externe Module**, die unabhängig installiert und versioniert werden können:

| Modul | Typ | Beschreibung |
|---|---|---|
| **ExamGuard** | Built-in | Prüfungsschutz durch Eingabeeinschränkungen |
| **ExamChecker** | Sonderstatus | Prüfung der Testeinstellungen (eigene Admin-Integration) |
| **ExamCalc** | Extern | Taschenrechner für Prüfungen (einfach oder wissenschaftlich) |
| **ExamSymbols** | Extern | Sonderzeichen-Paletten für Fachbereiche |
| **ExamAlert** | Extern | Zeitbasierte Warnungen vor Prüfungsende |
| *Eigene Module* | Extern | Über ZIP installierbar, beliebig erweiterbar |

---

## Quick Start

```bash
# 1. Plugin klonen
cd /pfad/zu/ilias/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/
git clone https://github.com/andreschweigert/ExtendedTestSettings ExtendedTestSettings

# 2. modules/-Verzeichnis für den Web-User beschreibbar machen
chown www-data: ExtendedTestSettings/modules/

# 3. In ILIAS installieren und aktivieren
#    Administration → Plugins → ExtendedTestSettings → Installieren → Aktivieren

# 4. Einmalig Classmap + ilCtrl-Artefakte regenerieren
cd /pfad/zu/ilias
composer dump-autoload
```

Nach der Aktivierung erscheint in jedem ILIAS-Test ein neuer Sub-Tab **"Erweiterte Test-Einstellungen"**, in dem die Module einzeln konfiguriert werden können.

---

## Installation

### Schritt 1: Plugin-Dateien herunterladen

```bash
cd /pfad/zu/ilias/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/
git clone https://github.com/andreschweigert/ExtendedTestSettings ExtendedTestSettings
```

### Schritt 2: Verzeichnis-Rechte setzen

Das `modules/`-Verzeichnis muss vom Web-Server beschreibbar sein, damit Admins Module über die Oberfläche hochladen können:

```bash
chown www-data: ExtendedTestSettings/modules/
```

### Schritt 3: Plugin in ILIAS aktivieren

1. Als Administrator einloggen
2. **Administration → Plugins → ExtendedTestSettings → Installieren → Aktivieren**

### Schritt 4: Classmap regenerieren

Damit der Proxy-Router für externe Modul-GUIs von ILIAS erkannt wird, muss einmalig die Composer-Classmap regeneriert werden:

```bash
cd /pfad/zu/ilias
composer dump-autoload
```

> Dieser Schritt ist nur einmalig nach der Erstinstallation nötig. Bei späteren Modul-Installationen über ZIP ist er **nicht** erforderlich.

### Schritt 5: Installation verifizieren

1. Einen beliebigen Test öffnen → **Einstellungen**
2. Sub-Tab **"Erweiterte Test-Einstellungen"** muss sichtbar sein
3. **Administration → Plugins → ExtendedTestSettings → Module** – ExamGuard sollte als aktiv angezeigt werden

### Schritt 6: Externe Module installieren

ExamCalc, ExamSymbols und ExamAlert sind externe Module und müssen separat installiert werden:

```
Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen
```

Die ZIPs sind im [Module-Repository](https://github.com/andreschweigert/ExtendedTestSettings-Modules) verfügbar.

---

## Modulares System

### Konzept

Jedes Modul ist ein eigenständiges Paket, das folgendes liefert:
- **PHP-Handler** der das `ilExamModuleInterface` implementiert
- **Assets** (CSS, JS, HTML-Templates) für den Testplayer
- **Datenbanktabellen** die beim Install angelegt und beim Deinstall entfernt werden
- **Optional:** eine eigene GUI-Seite (z.B. für Verwaltungsoberflächen)

Der Plugin-Kern lädt den Code eines Moduls **nur wenn es aktiv ist**. Deaktivierte oder nicht installierte Module sind für PHP vollständig unsichtbar.

### Built-in vs. Externe Module

**Built-in Module** (`classes/ExamXxx/`) sind im Git-Repo des Plugins enthalten:
- Standardmäßig aktiv
- Können nicht deinstalliert werden, nur deaktiviert
- Update über Plugin-Update (Git)
- Aktuell: nur **ExamGuard**

**Externe Module** (`modules/<id>/`) werden vom Admin hochgeladen:
- Standardmäßig inaktiv nach Installation
- Können installiert, deinstalliert, aktiviert und deaktiviert werden
- Update durch erneutes Hochladen eines neuen ZIP
- Aktuell: **ExamCalc**, **ExamSymbols**, **ExamAlert**, **ExamGrouping** (und weitere)
- Verfügbar im [Module-Repository](https://github.com/andreschweigert/ExtendedTestSettings-Modules)

### Modul installieren (extern)

```
Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen
```

Nach dem Upload das Modul in der Liste aktivieren.

### ZIP-Paket Struktur

```
MeinModul_v1.0.0.zip
├── module.json              ← Pflicht: Metadaten + Handler-Referenz
├── class.ilMeinModulHandler.php
├── sql/
│   ├── install.php          ← DB-Tabellen anlegen
│   └── uninstall.php        ← DB-Tabellen löschen
├── css/                     ← Optional
├── js/                      ← Optional
└── templates/               ← Optional
```

Das ZIP darf einen Wrapper-Ordner enthalten (wie beim GitHub-Download) – der Installer erkennt und überspringt ihn automatisch.

### module.json

```json
{
    "id": "meinmodul",
    "label": "Mein Modul",
    "description": "Kurze Beschreibung.",
    "version": "1.0.0",
    "ilias_min": "9",
    "ilias_max": "12",
    "handler_class": "ilMeinModulHandler",
    "handler_file": "class.ilMeinModulHandler.php",
    "gui_file": "class.ilMeinModulGUI.php",
    "gui_class": "ilMeinModulGUI",
    "require_files": [],
    "tables": ["meinmodul_settings"]
}
```

`gui_file` und `gui_class` sind nur nötig wenn das Modul eine eigene Verwaltungsseite hat.

---

## Module im Detail

### ExamCalc — Taschenrechner

Stellt einen Taschenrechner während der Prüfung bereit.

**Modi:**
- **Einfach:** Grundrechenarten (+ − × ÷), ideal für alle Prüfungen
- **Wissenschaftlich:** Vollständiger wissenschaftlicher Rechner auf Basis von math.js

**Wissenschaftlicher Modus:**
- Trigonometrie: `sin`, `cos`, `tan` + Umkehrfunktionen, DEG/RAD-umschaltbar
- Logarithmen: `ln`, `log` (Basis 10)
- Exponentialfunktionen: `exp(x)`, `10^x`, `√`, `x²`, `x^y`
- Konstanten: `π`, `e` (Eulersche Zahl)
- Speicher: `M+`, `M−`, `MR`, `MC`
- Verlauf: Letzte 10 Rechnungen, Σ-Summe, Klick zum Übernehmen
- `2nd`-Ebene: `sin/cos/tan` → `asin/acos/atan`, `10^x` → `EE` (wissenschaftliche Notation)

**Bedienung:** Tastenkombination `Alt + R`, verschiebbar per Drag & Drop

---

### ExamSymbols — Sonderzeichen

Bietet fachspezifische Sonderzeichen-Paletten für Prüfungen.

**Verfügbare Vorlagen:**
- Mathematik/Wissenschaft: π, √, ∑, ∞, ≈, ≠, ≤, ≥, ±, griechische Buchstaben
- Sprachen: Spanisch, Italienisch, Deutsch, Französisch, Griechisch
- Naturwissenschaften: Chemie (Reaktionspfeile), Physik (Vektoren, Differentiale)

**Bedienung:** Tastenkombination `Alt + S`, kompatibel mit TinyMCE und CKEditor

---

### ExamGuard — Prüfungsschutz

Blockiert kritische Eingaben zum Schutz der Prüfungsintegrität (client-seitig).

**Blockiert:** Rechtsklick, Copy/Paste/Cut, Alles-markieren, Entwickler-Tools (F12)

> Hinweis: ExamGuard ist eine zusätzliche Barriere, kein Ersatz für serverseitige Sicherheitsmaßnahmen.

---

### ExamAlert — Zeitwarnung

Warnt Teilnehmer rechtzeitig vor Prüfungsende.

**Funktionen:**
- Konfigurierbare Warnzeit (5 Sekunden bis 1 Stunde vor Ende)
- Anpassbare Warnnachricht (HTML-Formatierung möglich)
- Live-Countdown der verbleibenden Zeit
- Auto-Aktivierung für neue Tests (konfigurierbar)

---

### ExamChecker — Einstellungsprüfung

Überprüft Testeinstellungen gegen definierte Vorgaben (Präsenz- oder Fernprüfung).

**Konfiguration:** Administration → Plugins → ExtendedTestSettings → Konfigurieren

---

## Konfiguration

### Global (Admin)

**Administration → Plugins → ExtendedTestSettings → Konfigurieren:**

- **Tab-Sichtbarkeit:** Wer sieht den "Erweiterte Test-Einstellungen"-Sub-Tab?
  - System-Administratoren
  - Kurs-Administratoren (Standard)
  - Spezifische Benutzer (Whitelist)
- **ExamAlert Auto-Aktivierung:** ExamAlert für neue Tests automatisch einschalten

### Pro Test

**Test → Einstellungen → Erweiterte Test-Einstellungen:**

Jedes aktive Modul zeigt hier seine eigenen Einstellungsfelder. Änderungen gelten nur für den jeweiligen Test.

---

## Datenbank

Das Plugin erstellt folgende Tabellen bei der Installation:

| Tabelle | Modul | Inhalt |
|---|---|---|
| `examcalc_settings` | ExamCalc | Taschenrechner-Konfiguration je Test |
| `examsymbols_settings` | ExamSymbols | Symbol-Vorlage je Test |
| `examguard_settings` | ExamGuard | Aktivierungsstatus je Test |
| `examalert_settings` | ExamAlert | Warnzeit + Nachricht je Test |
| `extendedtest_users` | Kern | Benutzer-Whitelist für Tab-Sichtbarkeit |

Externe Module legen eigene Tabellen über ihre `sql/install.php` an und entfernen sie über `sql/uninstall.php`.

Bei Deinstallation des Plugins werden alle Plugin-Tabellen automatisch entfernt.

---

## Projektstruktur

```
ExtendedTestSettings/
├── classes/
│   ├── Contracts/
│   │   └── ilExamModuleInterface.php          ← Interface für alle Handler
│   ├── Module/
│   │   ├── ilModuleRegistry.php               ← Discovery + Active-State
│   │   └── ilModuleInstaller.php              ← ZIP-Upload, DB-Setup
│   ├── ExamGuard/                             ← Built-in Modul
│   ├── ExamChecker/                           ← Admin-Integration (Sonderstatus)
│   ├── class.ilExtendedTestSettingsPlugin.php
│   ├── class.ilExtendedTestSettingsUIHookGUI.php
│   ├── class.ilExtendedTestSettingsAdvancedGUI.php
│   ├── class.ilExtendedTestSettingsConfigGUI.php
│   └── class.ilExtendedTestSettingsModuleProxyGUI.php  ← Proxy-Router für externe GUIs
├── modules/                                   ← Externe installierte Module (nicht versioniert)
│   ├── examcalc/                              ← Extern: Taschenrechner
│   ├── examsymbols/                           ← Extern: Sonderzeichen-Palette
│   ├── examalert/                             ← Extern: Zeit-Warnung
│   └── examgrouping/                          ← Extern: Fragen-Gruppierung
└── sql/
    └── dbupdate.php                           ← ILIAS DB-Migrationen
```

---

## Eigene Module entwickeln

Das Plugin ist als Plattform konzipiert. Eigene Module können als ZIP-Pakete entwickelt und über die Admin-Oberfläche installiert werden.

Ein Modul besteht mindestens aus:

```php
class ilMeinModulHandler implements ilExamModuleInterface
{
    public function isEnabled(int $ref_id): bool { ... }
    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void { ... }
    public function saveSettings(int $ref_id, array $post_data): bool { ... }
    public function getFormItems(int $ref_id): array { ... }
}
```

Detaillierte Entwicklerdokumentation (inkl. Modul mit eigener GUI-Seite, Proxy-Routing, Checkliste) liegt im Repository unter `.dev/ModuleEntwicklung.md`.

---

## Support & Kontakt

**Entwickler:**
- André Schweigert (Andre.Schweigert@fau.de)
- Cornel Musielak (Cornel.Musielak@fau.de)

**Institution:** Friedrich-Alexander-Universität Erlangen-Nürnberg (FAU)

**Repository:** https://github.com/andreschweigert/ExtendedTestSettings

---

## Lizenz

**Status:** TBA (To Be Announced)

> Das Plugin wird "wie gesehen" bereitgestellt. Die Entwickler übernehmen keine Haftung für Datenverlust, Prüfungsausfälle oder technische Probleme. Vor dem Einsatz in Produktiv-Prüfungen ausführlich in einer Testumgebung prüfen.

---

## Changelog

### Version 1.1.3 (2026-04-10)

**Migration Built-in → Externe Module:**
- ExamCalc, ExamSymbols und ExamAlert aus `classes/` in `modules/` ausgelagert
- Asset-Pfade je Modul auf `modules/<id>/...` aktualisiert, Versionen auf 1.1.0
- ZIPs im [Module-Repository](https://github.com/andreschweigert/ExtendedTestSettings-Modules) verfügbar
- Built-in verbleibend: nur ExamGuard

### Version 1.1.2 (2026-03-24)

**ExamCalc – 2nd-Funktionsebene & EE-Notation:**
- `2nd`-Taste schaltet `10^x` auf `EE` (wissenschaftliche Notation) um: `2 EE 3` = 2000
- Visuelles Feedback: `2nd`-Badge in der Status-Leiste, Button-Label wechselt
- Shift-Modus setzt sich nach jedem Klick automatisch zurück
- **Bugfix:** `e` ist jetzt strikt die Eulersche Konstante — für wissenschaftliche Notation muss `EE` verwendet werden

### Version 1.1.0 (2026-03-23)

**Modulares System:**
- `ilExamModuleInterface` als gemeinsames Interface für alle Handler
- `ilModuleRegistry`: Auto-Discovery + Lazy-Loading (Code nur bei aktivem Modul)
- `ilModuleInstaller`: ZIP-Upload mit Validierung, Entpacken, DB-Setup
- `ilExtendedTestSettingsModuleProxyGUI`: Proxy-Router für externe Modul-GUIs
- Admin-UI: neuer "Module"-Tab mit Aktivieren/Deaktivieren + ZIP-Upload
- `modules/`-Verzeichnis für externe Module

**GUI-Refactoring:**
- ConfigGUI und AdvancedGUI auf ILIAS KitchenSink UI-Komponenten umgestellt
- Statistik-Seite: Panel/Report-Layout mit Modul-Details

### Version 1.0.2 (2024-12-04)

- ExamAlert Auto-Aktivierung für neue Tests
- ExamChecker: Konfigurierbare Standards
- Tab-Sichtbarkeit: Modus "Spezifische Benutzer"
- ExamSymbols: 8 Templates (neu: Physik, Chemie)

### Version 1.0.1 (2024-11-11)

- Initial Release: ExamCalc, ExamSymbols, ExamGuard, ExamAlert, ExamChecker
