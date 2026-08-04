#!/usr/bin/env bash
#
# Startet die Kern-Testsuite und – falls das Modul-Repository vorhanden ist –
# anschließend die Modul-Suite aus modules-zip/tests/.
#
# Umgebungsvariablen:
#   PHP_BIN        PHP-Binary (Default: php8.2 — ILIAS 9 verlangt PHP < 8.3)
#   PHPUNIT_PHAR   Pfad zur PHPUnit-PHAR (Default: tests/tools/phpunit.phar)
#
# Aufruf aus dem Plugin-Verzeichnis:  tests/run-tests.sh
# Zusätzliche Argumente werden an PHPUnit durchgereicht, z.B.
#   tests/run-tests.sh --filter ilModuleInstallerTest

set -u

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$TESTS_DIR")"

PHP_BIN="${PHP_BIN:-php8.2}"
PHPUNIT_PHAR="${PHPUNIT_PHAR:-$TESTS_DIR/tools/phpunit.phar}"

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "FEHLER: PHP-Binary '$PHP_BIN' nicht gefunden." >&2
    echo "        ILIAS 9 verlangt PHP < 8.3; php8.4 ist zu neu." >&2
    echo "        Anderes Binary: PHP_BIN=/pfad/zu/php tests/run-tests.sh" >&2
    exit 2
fi

if [ ! -f "$PHPUNIT_PHAR" ]; then
    echo "FEHLER: PHPUnit-PHAR nicht gefunden: $PHPUNIT_PHAR" >&2
    echo "        Die PHAR ist bewusst nicht im Repo. Beschaffung und Prüfsumme:" >&2
    echo "        siehe tests/README.md, Abschnitt 'PHPUnit besorgen'." >&2
    echo "        Anderer Ablageort: PHPUNIT_PHAR=/pfad/zu/phpunit.phar tests/run-tests.sh" >&2
    exit 2
fi

status=0
core_suite="ohne Fehler"
module_suite="ohne Fehler"

echo "=== Kern-Suite (Schicht A) ==========================================="
"$PHP_BIN" "$PHPUNIT_PHAR" -c "$TESTS_DIR/phpunit.xml" --testsuite unit "$@" \
    || { status=1; core_suite="FEHLGESCHLAGEN"; }

MODULE_CONFIG="$PLUGIN_DIR/modules-zip/tests/phpunit.xml"

echo
if [ -f "$MODULE_CONFIG" ]; then
    echo "=== Modul-Suite (modules-zip, Quelle + Installation) ================="
    ETS_PLUGIN_PATH="${ETS_PLUGIN_PATH:-$PLUGIN_DIR}" \
    PHPUNIT_PHAR="$PHPUNIT_PHAR" \
    "$PHP_BIN" "$PHPUNIT_PHAR" -c "$MODULE_CONFIG" "$@" \
        || { status=1; module_suite="FEHLGESCHLAGEN"; }
else
    # Kein Fehler: modules-zip/ ist ein eigenes Repo und im Hauptrepo gitignored,
    # in einem frischen Clone fehlt es. Stillschweigend "grün" melden wäre aber
    # falsch — deshalb der Hinweis unten in der Zusammenfassung.
    module_suite="NICHT GELAUFEN (modules-zip/tests/phpunit.xml fehlt)"
    echo "=== Modul-Suite übersprungen ========================================="
    echo "$MODULE_CONFIG nicht gefunden."
fi

echo
echo "=== Zusammenfassung =================================================="
echo "Kern-Suite:  $core_suite"
echo "Modul-Suite: $module_suite"
echo "E2E (Schicht C) laufen getrennt: siehe tests/e2e/README.md"

exit $status
