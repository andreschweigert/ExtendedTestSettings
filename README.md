# ExtendedTestSettings Plugin für ILIAS

![ILIAS](https://img.shields.io/badge/ILIAS-10.x-blue)
![Version](https://img.shields.io/badge/version-10.0.0-success)
![License](https://img.shields.io/badge/license-TBA-lightgrey)

**Version:** 10.0.0  
**Für ILIAS:** 10.0 – 10.999 (Branch `ilias10` — für ILIAS 9 den Branch `ilias9` verwenden)

Versionsschema der ILIAS-10-Linie: Major = ILIAS-Version (10.x), Versionssprünge +0.0.1.

Das **ExtendedTestSettings** Plugin erweitert ILIAS-Tests um Funktionen für Online-Prüfungen. Es basiert auf einem **modularen System**: Funktionalität wird in eigenständigen Modulen gebündelt, die über die Plugin-Administration als ZIP hochgeladen, aktiviert und deaktiviert werden können – ohne den Plugin-Kern zu ändern.

---

## Features

- **Modulare Architektur** – Funktionalität liegt vollständig in eigenständigen Modulen, die als ZIP-Pakete installiert, aktualisiert, aktiviert und deaktiviert werden
- **Eigene Module** – über ZIP installierbar, beliebig erweiterbar

Alle Module sind im [Module-Repository](https://github.com/andreschweigert/ExtendedTestSettings-Modules) verfügbar.

---

## Quick Start

```bash
# 1. Plugin klonen (Branch ilias10!)
cd <pathtoplugin>
git clone -b ilias10 https://github.com/andreschweigert/ExtendedTestSettings ExtendedTestSettings

# 2. ACL setzen: modules/-Verzeichnis für den Web-User beschreibbar machen
setfacl -R -m u:www-data:rwx <pathtoplugin>/ExtendedTestSettings/modules
setfacl -dm u:www-data:rwx <pathtoplugin>/ExtendedTestSettings/modules

# 3. In ILIAS installieren und aktivieren
#    Administration → Plugins → ExtendedTestSettings → Installieren → Aktivieren

# 4. Einmalig Classmap + ilCtrl-Artefakte regenerieren
cd /pfad/zu/ilias
composer dump-autoload
```

> `<pathtoplugin>` = `.../public/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/` — in ILIAS 10 ist der Webroot `public/`, das Plugin liegt also unterhalb von `public/Customizing/…`.

Nach der Aktivierung erscheint in jedem ILIAS-Test ein neuer Sub-Tab **"Erweiterte Test-Einstellungen"**, in dem die Module einzeln konfiguriert werden können.

---

## Installation

### Schritt 1: Plugin-Dateien herunterladen

```bash
cd <pathtoplugin>
git clone -b ilias10 https://github.com/andreschweigert/ExtendedTestSettings ExtendedTestSettings
```

### Schritt 2: ACL-Rechte setzen

Das `modules/`-Verzeichnis muss vom Web-Server beschreibbar sein, damit Admins Module über die Oberfläche hochladen können:

```bash
setfacl -R -m u:www-data:rwx <pathtoplugin>/ExtendedTestSettings/modules
setfacl -dm u:www-data:rwx <pathtoplugin>/ExtendedTestSettings/modules
```

> Die Default-ACL (`-dm`) sorgt dafür, dass auch zukünftig angelegte Dateien und Ordner innerhalb von `modules/` automatisch die richtigen Rechte erhalten.

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
3. **Administration → Plugins → ExtendedTestSettings → Module** – die Modulliste sollte erscheinen

### Schritt 6: Module installieren

Alle Module sind extern und müssen separat installiert werden — **immer per ZIP-Upload** (nur dabei wird `sql/install.php` ausgeführt und die Modul-Tabellen angelegt):

```
Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen
```

Die ZIPs (`<Name>_v10.x.y.zip`) sind im [Module-Repository](https://github.com/andreschweigert/ExtendedTestSettings-Modules) auf dem **Branch `ilias10`** verfügbar. Reihenfolge beachten: erst das Plugin installieren/aktualisieren, dann die Module.

---

## Modulares System

### Konzept

Jedes Modul ist ein eigenständiges Paket, das folgendes liefert:
- **PHP-Handler** der das `ilExamModuleInterface` implementiert
- **Assets** (CSS, JS, HTML-Templates) für den Testplayer
- **Datenbanktabellen** die beim Install angelegt und beim Deinstall entfernt werden
- **Optional:** eine eigene GUI-Seite (z.B. für Verwaltungsoberflächen)

Der Plugin-Kern lädt den Code eines Moduls **nur wenn es aktiv ist**. Deaktivierte oder nicht installierte Module sind für PHP vollständig unsichtbar.

### Externe Module

Externe Module (`modules/<id>/`) werden vom Admin hochgeladen:
- Standardmäßig inaktiv nach Installation
- Können installiert, deinstalliert, aktiviert und deaktiviert werden
- Update durch erneutes Hochladen eines neuen ZIP
- Verfügbar im [Module-Repository](https://github.com/andreschweigert/ExtendedTestSettings-Modules)

### Modul installieren

```
Administration → Plugins → ExtendedTestSettings → Module → ZIP hochladen
```

Nach dem Upload das Modul in der Liste aktivieren.

### ZIP-Paket Struktur

```
MeinModul_v10.0.0.zip
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
    "version": "10.0.0",
    "ilias_min": "10",
    "ilias_max": "10",
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

## Module

Alle Module werden separat im [Module-Repository](https://github.com/andreschweigert/ExtendedTestSettings-Modules) gepflegt und versioniert (Branch `ilias10`). Dort finden sich die vollständige Modulübersicht, ZIP-Downloads, Dokumentation und Changelogs der einzelnen Module.

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

## Projektstruktur

```
ExtendedTestSettings/
├── classes/
│   ├── Contracts/
│   │   └── ilExamModuleInterface.php          ← Interface für alle Handler
│   ├── Module/
│   │   ├── ilModuleRegistry.php               ← Discovery + Active-State
│   │   └── ilModuleInstaller.php              ← ZIP-Upload, DB-Setup
│   ├── class.ilExtendedTestSettingsPlugin.php
│   ├── class.ilExtendedTestSettingsUIHookGUI.php
│   ├── class.ilExtendedTestSettingsAdvancedGUI.php  ← Test-Settings GUI
│   ├── class.ilExtendedTestSettingsConfigGUI.php    ← Admin-Konfiguration
│   └── class.ilExtendedTestSettingsModuleProxyGUI.php  ← Proxy-Router für externe GUIs
├── modules/                                   ← Externe installierte Module (nicht versioniert)
├── sql/
│   └── dbupdate.php                           ← ILIAS DB-Migrationen
└── plugin.php                                 ← Plugin-Metadaten
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

### Version 10.0.0 (2026-07-07)

Start der ILIAS-10-Linie (Branch `ilias10`, Versionsschema: Major = ILIAS-Version, Sprünge +0.0.1).
Port des 2.0.1-Stands auf ILIAS 10:

- plugin.php: ILIAS-Range 10.0–10.999
- Erweiterte-Einstellungen-Tab: namespaced 10er-cmdClasses (9er-Strings bleiben für Kompatibilität), zusätzlich Prüfung des URL-Parameters `cmdClass` (getCmdClass() liefert in 10 beim Tab-Rendering ggf. die Parent-Klasse)
- `ilModuleRegistry::forPlugin()`: zentraler Compat-Layer — Dateisystem-Pfad (`getDirectory()`, in 10 absolut) und Web-Pfad (`getRelativeDirectory()`, in 9 per Fallback) getrennt; Modul-Handler erhalten den Web-Pfad für addJavaScript/addCss
- `\Throwable` statt `Exception` in den Guards um Modul-Handler-Code
- Toter `include_once './Modules/Course/...'` entfernt (Autoloader)

### Version 2.0.1 (2026-07-03)

**Zentrales Geräte-Inventar:**
- Neue Tabelle `etest_hosts` (dbupdate Step 7): zentrale IP → Koffer/Gerät-Zuordnung als Plugin-Infrastruktur
- Geräte-Tab mit CSV-Import nativ in der ConfigGUI (immer sichtbar)
- Module greifen nur lesend zu (LEFT JOIN über IP, mit `tableExists()`-Guard) — keine Code-Abhängigkeit
- **Update-Reihenfolge beachten:** erst Plugin aktualisieren (legt Tabelle an), dann Modul-ZIPs (z.B. ExamLogger ≥ 1.4.0, ExamBattery ≥ 1.1.0)

**Mehrsprachigkeit (DE/EN):**
- Hauptplugin auf ILIAS-Plugin-Sprachdateien umgestellt (`lang/ilias_de|en.lang`)
- Modul-i18n-Infrastruktur: Module bringen eigene `lang/`-Dateien mit (`ilModuleLanguage`, Fallback-Kette Sprache → EN → Default)

**Admin-GUI:**
- Umstellung auf ILIAS-KitchenSink-Komponenten (Panels, MessageBox, Accordions)
- Modul-spezifische Tabs erscheinen nur bei aktivem Modul; neue Tabs für ExamNetQos, ExamKeymap und ExamArchiver
- Statistik-Tab dynamisch an installierte Module gekoppelt

**Fixes & Aufräumarbeiten:**
- ModuleProxyGUI: Debug-Logging entfernt (Header-/Session-Cookie-Leak in `serveResourceFile()`)
- `allow_student_access` aus `module.json` respektiert RBAC-Leserecht (ExamMessage)
- Ungenutzten Legacy-Endpoint `ResourceFileGUI` entfernt
- dbupdate: Step-Marker `<#7>` korrekt am Zeilenanfang (Step wurde sonst nicht erkannt)

### Version 2.0.0 (2026-04-14)

**Security Audit & Hardening:**
- Auth + RBAC-Check im ModuleProxyGUI (F-002)
- SQL-Injection-Schutz durch Tabellennamen-Validierung in Installer + Registry (F-003)
- Scope-Isolation für install.php/uninstall.php + Audit-Logging (F-004)
- SSRF-Fix: Download-URL-Validierung prüft Host + Path-Prefix (F-005)
- Objekttyp-Prüfung in saveSettings() (F-008)
- Auto-Insert in getFallbackExamAlertStatus() entfernt (F-009)
- Allowlist-Validierung für ExamChecker POST-Daten (F-010)
- toggleModule/uninstallModule von GET auf POST umgestellt (F-011)
- Dead Code entfernt: searchUsersAjax(), addUser() (F-012)
- Path-Traversal-Schutz für require_files (F-013)

**Breaking Changes:**
- Module werden nicht mehr als Built-in mitgeliefert, sondern ausschließlich über das [Module-Repository](https://github.com/andreschweigert/ExtendedTestSettings-Modules) installiert
- `modules/`-Verzeichnis benötigt ACL-Rechte (siehe Installation)

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
