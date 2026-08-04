<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Basisklasse aller Kern-Tests.
 *
 * Sorgt für einen sauberen Zustand je Test: frischer $DIC-Stub, leerer
 * ilSetting-Store und ein eigenes Temp-Verzeichnis, das hinterher wieder
 * verschwindet. Das echte modules/-Verzeichnis der Installation wird von
 * keinem Test angefasst — Installer und Registry bekommen den Pfad injiziert.
 */
abstract class ilEtsTestCase extends TestCase
{
    /** Wurzel des Temp-Verzeichnisses dieses Tests. */
    private ?string $temp_root = null;

    protected function setUp(): void
    {
        parent::setUp();

        ilSetting::_resetTestStore();
        $GLOBALS['DIC'] = new ilEtsDicStub();
    }

    protected function tearDown(): void
    {
        if ($this->temp_root !== null && is_dir($this->temp_root)) {
            self::removeTree($this->temp_root);
        }
        $this->temp_root = null;

        unset($GLOBALS['DIC']);
        ilSetting::_resetTestStore();

        parent::tearDown();
    }

    /**
     * Temp-Verzeichnis dieses Tests (wird beim ersten Aufruf angelegt).
     * Ablageort überschreibbar per ETS_TEST_TMPDIR.
     */
    protected function tempDir(): string
    {
        if ($this->temp_root === null) {
            $base = getenv('ETS_TEST_TMPDIR') ?: sys_get_temp_dir();
            $dir  = rtrim($base, '/') . '/ets-tests-' . bin2hex(random_bytes(8));

            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                self::fail("Temp-Verzeichnis konnte nicht angelegt werden: $dir");
            }

            $this->temp_root = $dir;
        }

        return $this->temp_root;
    }

    /**
     * Legt ein leeres Plugin-Verzeichnis mit modules/-Unterordner an — der Pfad,
     * den Installer und Registry injiziert bekommen.
     */
    protected function pluginPath(): string
    {
        $path = $this->tempDir() . '/plugin';

        if (!is_dir($path . '/modules') && !mkdir($path . '/modules', 0777, true)) {
            self::fail("Plugin-Verzeichnis konnte nicht angelegt werden: $path");
        }

        return $path;
    }

    /** Schreibt eine Datei und legt fehlende Verzeichnisse an. */
    protected function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            self::fail("Verzeichnis konnte nicht angelegt werden: $dir");
        }
        if (file_put_contents($path, $content) === false) {
            self::fail("Datei konnte nicht geschrieben werden: $path");
        }
    }

    /** Der Datenbank-Stub aus dem aktuellen $DIC. */
    protected function db(): ilEtsDatabaseStub
    {
        return $GLOBALS['DIC']->database();
    }

    /** Rekursiv löschen — nur für Temp-Verzeichnisse der Tests. */
    protected static function removeTree(string $dir): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
