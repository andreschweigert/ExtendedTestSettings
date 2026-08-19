<?php

declare(strict_types=1);

require_once ETS_PLUGIN_ROOT . '/classes/class.ilExtendedTestSettingsPlugin.php';

/**
 * Vollständige Datenbereinigung bei der Deinstallation — Regression zu C-12/R14.
 *
 * C-12: dropTables() entfernte nur Tabellen aus aktuell entdeckten module.json.
 * Die vom Kern selbst angelegten Tabellen (dbupdate-Steps 1-5 und 7) blieben
 * stehen, etest_hosts wurde nie gelöscht.
 *
 * Der Test liest beide Seiten aus den Dateien statt aus einer gepflegten Liste:
 * jede künftige Migration mit createTable() fällt damit automatisch auf.
 */
final class ilCoreTablesTest extends ilEtsTestCase
{
    private const DBUPDATE = ETS_PLUGIN_ROOT . '/sql/dbupdate.php';

    /** Tabellennamen, die sql/dbupdate.php per createTable() anlegt. */
    private function tablesFromDbUpdate(): array
    {
        $source = (string) file_get_contents(self::DBUPDATE);

        preg_match_all('/createTable\(\s*[\'"]([a-z][a-z0-9_]*)[\'"]/i', $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** Die im Plugin gepflegte Liste CORE_TABLES. */
    private function coreTables(): array
    {
        $constant = (new ReflectionClass(ilExtendedTestSettingsPlugin::class))->getConstant('CORE_TABLES');

        $this->assertIsArray($constant, 'CORE_TABLES fehlt in ilExtendedTestSettingsPlugin.');

        return $constant;
    }

    public function testDbUpdateLegtUeberhauptTabellenAn(): void
    {
        $this->assertFileExists(self::DBUPDATE);
        $this->assertNotEmpty(
            $this->tablesFromDbUpdate(),
            'In sql/dbupdate.php wurde kein createTable() gefunden — der Test greift dann ins Leere.'
        );
    }

    /**
     * Kernfall C-12: Jede in einer Migration angelegte Tabelle muss in
     * CORE_TABLES stehen, sonst bleibt sie bei der Deinstallation zurück.
     */
    public function testJedeAngelegteTabelleWirdBeiDeinstallationEntfernt(): void
    {
        $created = $this->tablesFromDbUpdate();
        $covered = $this->coreTables();

        $missing = array_diff($created, $covered);

        $this->assertSame(
            [],
            array_values($missing),
            'In sql/dbupdate.php angelegt, aber nicht in CORE_TABLES (Regel R14): ' . implode(', ', $missing)
        );
    }

    /**
     * Gegenrichtung: CORE_TABLES darf nichts enthalten, was gar nicht mehr
     * angelegt wird — bis auf die dokumentierten Alt-Tabellen aus Migrationen,
     * die inzwischen von Modulen verwaltet werden.
     */
    public function testCoreTablesEnthaeltKeineUnbekanntenTabellen(): void
    {
        $unexpected = array_diff($this->coreTables(), $this->tablesFromDbUpdate());

        $this->assertSame(
            [],
            array_values($unexpected),
            'In CORE_TABLES gelistet, aber in sql/dbupdate.php nicht angelegt: ' . implode(', ', $unexpected)
        );
    }

    public function testCoreTablesErfuellenDieTabellenRegex(): void
    {
        foreach ($this->coreTables() as $table) {
            $this->assertMatchesRegularExpression(
                ilModuleManifest::TABLE_REGEX,
                $table,
                "Tabellenname '$table' passiert die Allowlist nicht und würde beim Löschen übersprungen."
            );
        }
    }

    /**
     * Gotcha aus Commit 172f2b7: Ein Step-Marker, der am ?> des Vorgängers klebt,
     * wird von ILIAS nicht erkannt — die Migration läuft dann nie.
     */
    public function testStepMarkerStehenAmZeilenanfang(): void
    {
        $lines   = file(self::DBUPDATE, FILE_IGNORE_NEW_LINES) ?: [];
        $fehler  = [];
        $gesehen = 0;

        foreach ($lines as $no => $line) {
            if (!str_contains($line, '<#')) {
                continue;
            }
            $gesehen++;
            if (!preg_match('/^<#\d+>$/', trim($line)) || $line !== ltrim($line)) {
                $fehler[] = 'Zeile ' . ($no + 1) . ': ' . $line;
            }
        }

        $this->assertGreaterThan(0, $gesehen, 'Keine Step-Marker gefunden.');
        $this->assertSame([], $fehler, "Step-Marker müssen allein am Zeilenanfang stehen:\n" . implode("\n", $fehler));
    }
}
