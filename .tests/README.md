# Testsuite — ExtendedTestSettings (Kern)

Automatisierte Tests für den Plugin-Kern. Sie laufen **ohne ILIAS-Bootstrap**,
ohne Datenbank und ohne Netzzugriff; die wenigen ILIAS-Bausteine, die der
getestete Code berührt, sind in `bootstrap.php` als Stubs definiert.

Die **Modul**-Prüfungen liegen im eigenen Repository unter `modules-zip/tests/`,
die Browser-Tests unter `.tests/e2e/`.

> **Warum `.tests/` mit Punkt?** Der Ordner liegt innerhalb von
> `Customizing/global/plugins`, und dieses Verzeichnis steht in ILIAS'
> `composer.json` in der `classmap` — noch **vor** `./Services/`. Composers
> ClassMapGenerator würde `bootstrap.php` mitscannen und die dortigen Stubs
> (`ilSetting`, `ilUserInterfaceHookPlugin`, `ilPluginConfigGUI`,
> `ilCtrlSecurityInterface`) als Gewinner in die Klassenmap eintragen, weil bei
> doppelten Klassennamen der zuerst gefundene Pfad gewinnt. ILIAS lädt dann die
> Test-Stubs statt der echten Core-Klassen — `setup/cli.php build-artifacts`
> bricht mit einem Fatal Error ab (`ILIAS\Modules\File\Settings\General
> contains 5 abstract methods`), und im laufenden Betrieb wäre jedes
> UIHook-Plugin kaputt.
>
> Composer nutzt Symfony Finder, und der überspringt per Default alles, was mit
> `.` beginnt (`IGNORE_DOT_FILES`). Der Punkt hält die Suite also aus der
> Klassenmap heraus — genau wie bei `.dev/`. **Nicht in `tests/` umbenennen.**

---

## Aufruf

```bash
# aus dem Plugin-Verzeichnis
.tests/run-tests.sh                       # Kern-Suite, danach Modul-Suite (falls vorhanden)
.tests/run-tests.sh --filter ilModuleInstallerTest

# nur die Kern-Suite
php8.2 .tests/tools/phpunit.phar -c .tests/phpunit.xml --testsuite unit

# ohne die langsamen ZIP-Tests (rund 10 der 13 Sekunden Laufzeit)
php8.2 .tests/tools/phpunit.phar -c .tests/phpunit.xml --testsuite unit --exclude-group slow
```

`php8.2` ist das passende Binary — ILIAS 9 verlangt PHP < 8.3, das ebenfalls
installierte `php8.4` ist zu neu. Über `PHP_BIN` lässt sich ein anderer Pfad
setzen.

## PHPUnit besorgen

Die PHAR liegt **nicht** im Repository (Binärdatei, eigener Lebenszyklus). Sie
wird unter `.tests/tools/phpunit.phar` erwartet; ein anderer Ablageort geht über
`PHPUNIT_PHAR`. Fehlt sie, meldet `run-tests.sh` das im Klartext.

```bash
curl -sSL -o .tests/tools/phpunit.phar https://phar.phpunit.de/phpunit-11.phar
chmod +x .tests/tools/phpunit.phar
sha256sum .tests/tools/phpunit.phar
```

| | |
|---|---|
| Verwendete Fassung | PHPUnit 11.5.56 (setzt PHP ≥ 8.2 voraus) |
| SHA-256 | `915fa161f496dc04a45cd6032855879bca0bab644048cd0516982dffe678e9f1` |

Die Composer-Installation der Plattform wird dafür **nicht** angefasst.

---

## Aufbau

```
.tests/
├── bootstrap.php          ILIAS-Stubs (ilSetting, $DIC, Elternklassen) + require der Kern-Klassen
├── phpunit.xml            Suite "unit"
├── run-tests.sh           Kern-Suite, danach Modul-Suite aus modules-zip/
├── support/               Hilfsklassen für die Tests
│   ├── ilEtsTestCase.php      Basisklasse: Temp-Verzeichnis, frischer $DIC, leerer ilSetting-Store
│   ├── ilEtsZipBuilder.php    ZIP-Archive inkl. Zip-Bomben-Fällen (dünn besetzte Dateien)
│   └── ilEtsModuleFixture.php Manifeste, Handler-Code, Modul-ZIPs und -Ordner
├── tools/                 Ablage der PHPUnit-PHAR (gitignored)
└── unit/                  die Tests
```

Kein Test fasst das echte `modules/`-Verzeichnis an: Installer und Registry
bekommen den Plugin-Pfad injiziert und arbeiten gegen ein Temp-Verzeichnis, das
nach jedem Test wieder verschwindet.

---

## Was wo geprüft wird

| Testklasse | Inhalt |
|---|---|
| `ilModuleManifestTest` | `validate()`, `resolveFile()`, Traversal, Null-Byte, ID-/Klassen-/Tabellen-Regex; alle echten `module.json` bleiben gültig |
| `ilModuleInstallerTest` | Erstinstallation, Update, Rollback nach DB-Fehler, Staging-Reste, Deinstallation, Manifest vor der Installation lesen |
| `ilModuleZipLimitsTest` | Einträge, Einzeldateigröße, entpackte Gesamtgröße, Kompressionsverhältnis, Archivgröße (Gruppe `slow`) |
| `ilModuleRegistryTest` | Kill-Switch für inaktive Module, Lebenszyklus des Aktivstatus, Manifest-Validierung bei manueller Ablage, Tabellen |
| `ilModuleUpdateCheckerTest` | API-URL, Versions-Parsing aus dem ZIP-Namen, Built-ins ohne Netzzugriff |
| `ilModuleLanguageTest` | Fallback-Kette Sprache → Englisch → Default → Schlüssel |
| `ilCoreTablesTest` | jede in `sql/dbupdate.php` angelegte Tabelle steht in `CORE_TABLES`; Step-Marker am Zeilenanfang |
| `ilDownloadUrlAllowlistTest` | `isAllowedDownloadUrl()`: Schema, Host, Repository-Pfad-Präfix |
| `ilModuleDownloadSecurityTest` | Downloader: Traversal vor dem Abruf abweisen, jedes Redirect-Ziel prüfen, Redirect-Schleifen begrenzen; HTTP-Antworten ohne Netzwerk simuliert |
| `ilAdvancedGuiCsrfTest` | Speichern nur mit POST und dem gültigen Session-Token; ungültige Requests brechen vor dem Datenzugriff ab |
| `ilAdminHtmlSecurityTest` | Manipulierte Modulmetadaten bleiben in Properties, Bestätigungsdialogen und Update-Protokoll inert |
| `ilReleaseZipRegressionTest` | alle 18 echten Release-ZIPs werden weiterhin akzeptiert |
| `ilKernRegelnTest` | statische Regelprüfungen R3–R10 über `classes/` |
| `ilPluginIntegritaetTest` | PHP-Syntax, Klassen-/Dateinamen, Versionen (R15), Command-Liste, Modul-Interface, Sprachdateien |

---

## Finding → Test

Grundlage: `.dev/security/REVIEW_VERIFIKATION_2026-08-03.md`.
Schicht A = PHPUnit hier, Schicht C = Playwright unter `.tests/e2e/`.

| ID | Kurzfassung | Schicht | Test |
|---|---|---|---|
| C-01 | Deaktivierte Module über den Proxy ausführbar | A + C | `ilModuleRegistryTest::testGetActiveModuleInfoLiefertBeiInaktivemModulNull`, `…HandlerCodeWirdNurBeiAktivemModulGeladen`, `ilKernRegelnTest::testKeinModulcodeAmDateisystemVorbei` · E2E-Szenario 1 |
| C-02 | Admin-Aktionen ohne CSRF-Schutz | A + C | `ilKernRegelnTest::testKeinSchreibenderCommandHaengtAnEinemLinkZiel`, `…testSchreibenderCommandErzwingtPost`, `ilPluginIntegritaetTest::testSchreibendeCommandsWerdenAlsUnsichereGetCommandsGemeldet` · E2E-Szenarien 4–6, 12 |
| C-03 | Inline-HTML ohne CSP/X-Frame-Options | A + C | `ilKernRegelnTest::testEntfernteSecurityHeaderWerdenErsetzt`, `…testHtmlWirdAlsAttachmentAusgeliefert` · E2E-Szenarien 7, 8 |
| C-04 | Whitelist ohne testbezogene Autorisierung | C | E2E-Szenario 10 |
| C-05 | Modulupdate destruktiv, nicht atomar | A | `ilModuleInstallerTest::testFehlerInInstallSqlStelltAltenStandWiederHer`, `…testUnvollstaendigesUpdateLaesstAltenStandUnberuehrt`, `…testUpdateErsetztDenAltenStandVollstaendig`, `…testPunktVerzeichnisseWerdenNichtAlsModulEntdeckt` |
| C-06 | Deinstallation meldet Erfolg trotz SQL-Fehler | A | `ilModuleInstallerTest::testFehlerInUninstallSqlPropagiertUndModulBleibtErhalten` |
| C-07 | Ressourcenfreigabe ohne aktive Testsitzung | A + C | `ilKernRegelnTest::testAbfragenAufTstActivePruefenSubmitted` · E2E-Szenario 9 |
| C-08 | Proxy prüft den Objekttyp nicht | C | E2E-Szenario 3 |
| C-09 | Update nicht an Modul-ID/Version gebunden | A | `ilModuleInstallerTest::testReadManifestFromZipSchreibtNichts`, `…testReadManifestFromZipValidiertDasManifest`, `ilDownloadUrlAllowlistTest` |
| C-10 | Keine Dekompressions-/Download-Limits | A | `ilModuleZipLimitsTest` (alle), `ilReleaseZipRegressionTest` |
| C-11 | Manifest-Dateipfade ohne Containment | A | `ilModuleManifestTest` (alle), `ilModuleRegistryTest::testHandlerAusserhalbDesModulordnersWirdNichtGeladen` |
| C-12 | Deinstallation lässt Kerntabellen zurück | A | `ilCoreTablesTest::testJedeAngelegteTabelleWirdBeiDeinstallationEntfernt` |
| C-13 | Modulfehler nicht isoliert (`Exception` statt `Throwable`) | A | `ilKernRegelnTest::testModulaufrufeFangenThrowable` |
| C-14 | Versions-/Verhaltensdoku inkonsistent | A | `ilModuleRegistryTest::testNachDeinstallationIstEineNeuinstallationInaktiv`, `…testUpdateBehaeltDenAktivstatus`, `ilPluginIntegritaetTest::testVersionStehtUeberallGleich` |
| C-15 | Permanentes Debug-Panel im Updatepfad | A | `ilKernRegelnTest::testKeineDebugAusgabenImProduktivpfad` |
| C-16 | Keine automatisierten Tests | A | diese Suite |
| Z-01 | ConfigGUI lädt Modulcode an der Registry vorbei | A + C | `ilKernRegelnTest::testKeinModulcodeAmDateisystemVorbei` · E2E-Szenario 2 |
| Z-02 | `doUpdateModule` per CSRF auslösbar | A + C | `ilKernRegelnTest::testSchreibenderCommandErzwingtPost`, `ilDownloadUrlAllowlistTest` · E2E-Szenario 4 |
| Z-03 | Bedingte Autorisierung in ExamNetQos | — | Modul-Befund, siehe `modules-zip/tests/` |
| Z-04 | Update-Cache direkt in `$_SESSION` | — | offen, kein Test (Wartbarkeit) |
| Z-05 | Massenaktion ohne Wiederholbarkeitsgrenze | C | E2E-Szenario 5 |
| Z-06 | `hasActiveTestSession()` verglich die falsche ID | C | E2E-Szenario 9 |

---

## Bekannt offen

`markTestIncomplete()` wird nur für dokumentierte, offene Punkte verwendet — die
Erwartung im Test bleibt das Sollverhalten, sie wird nicht abgesenkt.

| Test | Grund |
|---|---|
| `ilKernRegelnTest::testSchreibenderCommandErzwingtPost` (`addSingleUser`) | Ruft `requirePost()` nicht auf; im Code als bewusste Ausnahme kommentiert (Aufruf als Link aus der Trefferliste, URL aus `getFormAction()` mit rtoken). Entscheidung steht aus. |
| `ilKernRegelnTest::testSchreibenderCommandErzwingtPost` (`checkModuleUpdates`) | Dito; löst einen Repository-Abruf aus und schreibt den Update-Cache in die Session. |

Beides ist in `.dev/testsuite/README.md` als Beobachtung vom 2026-08-04
festgehalten.

---

## Konventionen für neue Tests

Bei einem Kern-Review ohne Modulzugriff die beiden Regressionen gegen echte
Moduldateien ausdrücklich auslassen; `run-tests.sh` startet zusätzlich die
Modul-Suite und ist dafür nicht geeignet:

```bash
php8.2 .tests/tools/phpunit.phar -c .tests/phpunit.xml --testsuite unit \
  --do-not-cache-result \
  --filter '/^(?!.*(?:ilReleaseZipRegressionTest|testEchteManifesteBleibenGueltig)).*$/'
```

- Testklassen erben von `ilEtsTestCase`, heißen `il<Name>Test` und liegen in `unit/`.
- Kommentare deutsch, 4 Spaces; `declare(strict_types=1)` ist in Tests erlaubt
  (im Plugin-Code nicht).
- Datenprovider als PHP-Attribut (`#[DataProvider('name')]`), nicht als Doc-Block.
- Kein Netzzugriff, keine Datenbank, kein Zugriff auf `modules/` der Installation.
- Ein übersprungener Test bekommt immer eine Begründung, die im Ergebnis sichtbar
  ist — `markTestSkipped()` nie stillschweigend.
- Tests für **Module** gehören nicht hierher, sondern nach `modules-zip/tests/`;
  Anleitung: `modules-zip/docs/tests-schreiben.md`.
