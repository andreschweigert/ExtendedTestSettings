<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Manifest-Validierung — Regression zu C-11.
 *
 * C-11: handler_file und gui_file wurden ohne realpath()-Containment aufgelöst,
 * und die strenge ID-Regex galt nur im Upload-Pfad. Seit 2.1.0 prüft
 * ilModuleManifest für Installer, Registry und Proxy nach denselben Regeln.
 */
final class ilModuleManifestTest extends ilEtsTestCase
{
    // -------------------------------------------------------------------------
    // validate() — Pflichtfelder
    // -------------------------------------------------------------------------

    public function testGueltigesManifestPassiert(): void
    {
        $manifest = ilEtsModuleFixture::manifest();

        $this->assertSame($manifest, ilModuleManifest::validate($manifest));
    }

    #[DataProvider('pflichtfelder')]
    public function testFehlendesPflichtfeldWirdAbgelehnt(string $field): void
    {
        $manifest = ilEtsModuleFixture::manifest([$field => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Pflichtfeld '$field'");

        ilModuleManifest::validate($manifest);
    }

    #[DataProvider('pflichtfelder')]
    public function testLeeresPflichtfeldWirdAbgelehnt(string $field): void
    {
        $manifest = ilEtsModuleFixture::manifest([$field => '']);

        $this->expectException(RuntimeException::class);

        ilModuleManifest::validate($manifest);
    }

    public static function pflichtfelder(): array
    {
        return [
            'id'            => ['id'],
            'handler_file'  => ['handler_file'],
            'handler_class' => ['handler_class'],
            'label'         => ['label'],
        ];
    }

    // -------------------------------------------------------------------------
    // validate() — Modul-ID (galt vor C-11 nur beim Upload)
    // -------------------------------------------------------------------------

    #[DataProvider('ungueltigeIds')]
    public function testUngueltigeModulIdWirdAbgelehnt(string $id): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ungültige Modul-ID');

        ilModuleManifest::validate(ilEtsModuleFixture::manifest(['id' => $id]));
    }

    public static function ungueltigeIds(): array
    {
        return [
            'Großbuchstaben'  => ['ExamCalc'],
            'Bindestrich'     => ['exam-calc'],
            'Ziffer am Start' => ['1examcalc'],
            'zu kurz'         => ['ab'],
            'zu lang'         => [str_repeat('a', 41)],
            'Punkt'           => ['exam.calc'],
            'Slash'           => ['exam/calc'],
            'Leerzeichen'     => ['exam calc'],
            'Umlaut'          => ['examkalkül'],
        ];
    }

    #[DataProvider('gueltigeIds')]
    public function testGueltigeModulIdPassiert(string $id): void
    {
        $manifest = ilModuleManifest::validate(ilEtsModuleFixture::manifest(['id' => $id]));

        $this->assertSame($id, $manifest['id']);
    }

    public static function gueltigeIds(): array
    {
        return [
            'kurz'             => ['abc'],
            'mit Unterstrich'  => ['exam_calc'],
            'mit Ziffer'       => ['examcalc2'],
            'maximale Länge'   => [str_repeat('a', 40)],
        ];
    }

    // -------------------------------------------------------------------------
    // validate() — Klassen- und Tabellennamen
    // -------------------------------------------------------------------------

    #[DataProvider('ungueltigeKlassennamen')]
    public function testUngueltigerKlassennameWirdAbgelehnt(string $class): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ungültiger Klassenname');

        ilModuleManifest::validate(ilEtsModuleFixture::manifest(['handler_class' => $class]));
    }

    public static function ungueltigeKlassennamen(): array
    {
        return [
            'ohne il-Präfix' => ['ExamCalcHandler'],
            'zu kurz'        => ['il'],
            'mit Bindestrich' => ['ilExam-Calc'],
            'mit Backslash'  => ['ilExam\\Calc'],
            'mit Leerzeichen' => ['ilExam Calc'],
            'mit Klammer'    => ['ilExamCalc()'],
        ];
    }

    public function testGuiClassWirdNurWennGesetztGeprueft(): void
    {
        $ok = ilModuleManifest::validate(ilEtsModuleFixture::manifest(['gui_class' => 'ilExamCalcGUI']));
        $this->assertSame('ilExamCalcGUI', $ok['gui_class']);

        $this->expectException(RuntimeException::class);
        ilModuleManifest::validate(ilEtsModuleFixture::manifest(['gui_class' => 'ExamCalcGUI']));
    }

    #[DataProvider('ungueltigeTabellennamen')]
    public function testUngueltigerTabellennameWirdAbgelehnt(mixed $table): void
    {
        $this->expectException(RuntimeException::class);

        ilModuleManifest::validate(ilEtsModuleFixture::manifest(['tables' => [$table]]));
    }

    public static function ungueltigeTabellennamen(): array
    {
        return [
            'SQL-Fragment'    => ['tab; DROP TABLE x'],
            'Backtick'        => ['`tab`'],
            'Großbuchstaben'  => ['ExamCalc'],
            'zu kurz'         => ['ab'],
            'kein String'     => [42],
        ];
    }

    public function testTablesMussListeSein(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'tables' muss eine Liste sein");

        ilModuleManifest::validate(ilEtsModuleFixture::manifest(['tables' => 'examcalc_settings']));
    }

    public function testRequireFilesMussListeSein(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'require_files' muss eine Liste sein");

        ilModuleManifest::validate(ilEtsModuleFixture::manifest(['require_files' => 'class.ilFoo.php']));
    }

    // -------------------------------------------------------------------------
    // validate() — Dateipfade (C-11)
    // -------------------------------------------------------------------------

    #[DataProvider('unsicherePfade')]
    public function testUnsichererHandlerPfadWirdAbgelehnt(string $path): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Ungültiger Dateipfad in 'handler_file'");

        ilModuleManifest::validate(ilEtsModuleFixture::manifest(['handler_file' => $path]));
    }

    #[DataProvider('unsicherePfade')]
    public function testUnsichererGuiPfadWirdAbgelehnt(string $path): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Ungültiger Dateipfad in 'gui_file'");

        ilModuleManifest::validate(ilEtsModuleFixture::manifest(['gui_file' => $path]));
    }

    #[DataProvider('unsicherePfade')]
    public function testUnsichererRequirePfadWirdAbgelehnt(string $path): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Ungültiger Dateipfad in 'require_files'");

        ilModuleManifest::validate(ilEtsModuleFixture::manifest(['require_files' => [$path]]));
    }

    public static function unsicherePfade(): array
    {
        return [
            'Traversal'            => ['../../evil.php'],
            'absoluter Pfad'       => ['/etc/passwd.php'],
            'eingebettetes ..'     => ['sub/../../evil.php'],
            'Null-Byte'            => ["class.ilEvil.php\0.png"],
            'Null-Byte am Ende'    => ["class.ilEvil.php\0"],
            'Nicht-PHP-Endung'     => ['evil.phtml'],
            'ohne Endung'          => ['evil'],
            'Backslash'            => ['sub\\evil.php'],
            'nur Punkt'            => ['./evil.php'],
            'doppelter Slash'      => ['sub//evil.php'],
            'zu lang'              => [str_repeat('a', 252) . '.php'],
            'URL'                  => ['http://evil.example.org/x.php'],
        ];
    }

    public function testLeererRequireFilesEintragWirdAbgelehnt(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Ungültiger Dateipfad in 'require_files'");

        ilModuleManifest::validate(ilEtsModuleFixture::manifest(['require_files' => ['']]));
    }

    /**
     * gui_file ist optional — ein leerer Wert bedeutet "Modul ohne GUI" und ist
     * kein Regelverstoß. Der leere handler_file fällt dagegen schon durch die
     * Pflichtfeldprüfung.
     */
    public function testLeeresGuiFileIstZulaessig(): void
    {
        $manifest = ilModuleManifest::validate(ilEtsModuleFixture::manifest(['gui_file' => '']));

        $this->assertSame('', $manifest['gui_file']);
    }

    #[DataProvider('sicherePfade')]
    public function testSichererPfadPassiert(string $path): void
    {
        $manifest = ilModuleManifest::validate(ilEtsModuleFixture::manifest(['handler_file' => $path]));

        $this->assertSame($path, $manifest['handler_file']);
    }

    public static function sicherePfade(): array
    {
        return [
            'flach'          => ['class.ilExamCalcHandler.php'],
            'Unterordner'    => ['classes/class.ilExamCalcHandler.php'],
            'mit Bindestrich' => ['exam-calc/handler.php'],
            'Großschreibung der Endung' => ['class.ilExamCalcHandler.PHP'],
        ];
    }

    // -------------------------------------------------------------------------
    // resolveFile() — Containment (C-11)
    // -------------------------------------------------------------------------

    public function testResolveFileLiefertAbsolutenPfadInnerhalbDesModuls(): void
    {
        $dir = $this->tempDir() . '/modul';
        $this->writeFile($dir . '/class.ilFoo.php', '<?php');

        $resolved = ilModuleManifest::resolveFile($dir, 'class.ilFoo.php');

        $this->assertNotNull($resolved);
        $this->assertSame(realpath($dir . '/class.ilFoo.php'), $resolved);
    }

    public function testResolveFileLehntTraversalAb(): void
    {
        $dir = $this->tempDir() . '/modul';
        $this->writeFile($dir . '/class.ilFoo.php', '<?php');
        $this->writeFile($this->tempDir() . '/aussen.php', '<?php');

        $this->assertNull(ilModuleManifest::resolveFile($dir, '../aussen.php'));
    }

    public function testResolveFileLehntSymlinkAusbruchAb(): void
    {
        $dir = $this->tempDir() . '/modul';
        $this->writeFile($dir . '/class.ilFoo.php', '<?php');
        $this->writeFile($this->tempDir() . '/aussen.php', '<?php beliebiger Code');

        if (!@symlink($this->tempDir() . '/aussen.php', $dir . '/link.php')) {
            $this->markTestSkipped('Symlinks sind in diesem Dateisystem nicht anlegbar — '
                . 'der realpath()-Ausbruchstest kann hier nicht laufen.');
        }

        // Der Pfad ist formal harmlos, zeigt aber aus dem Modulordner heraus.
        $this->assertNull(ilModuleManifest::resolveFile($dir, 'link.php'));
    }

    public function testResolveFileLiefertNullBeiFehlenderDatei(): void
    {
        $dir = $this->tempDir() . '/modul';
        $this->writeFile($dir . '/class.ilFoo.php', '<?php');

        $this->assertNull(ilModuleManifest::resolveFile($dir, 'class.ilFehlt.php'));
    }

    public function testResolveFileLiefertNullFuerVerzeichnis(): void
    {
        $dir = $this->tempDir() . '/modul';
        $this->writeFile($dir . '/unterordner.php/datei.php', '<?php');

        $this->assertNull(ilModuleManifest::resolveFile($dir, 'unterordner.php'));
    }

    public function testResolveFileLiefertNullBeiFehlendemModulverzeichnis(): void
    {
        $this->assertNull(ilModuleManifest::resolveFile($this->tempDir() . '/gibtesnicht', 'class.ilFoo.php'));
    }

    // -------------------------------------------------------------------------
    // Regression: die echten Manifeste bleiben gültig
    // -------------------------------------------------------------------------

    /**
     * Schutz gegen zu strenge Regeln: jedes real ausgelieferte module.json muss
     * die Validierung weiterhin bestehen — installierte Module wie Modulquellen.
     */
    public function testEchteManifesteBleibenGueltig(): void
    {
        $files = array_merge(
            glob(ETS_PLUGIN_ROOT . '/modules/*/module.json') ?: [],
            glob(ETS_PLUGIN_ROOT . '/modules-zip/modules/*/module.json') ?: [],
            glob(ETS_PLUGIN_ROOT . '/modules-zip/modules/*/*/module.json') ?: []
        );

        if ($files === []) {
            $this->markTestSkipped(
                'Weder installierte Module (' . ETS_PLUGIN_ROOT . '/modules/) noch Modulquellen '
                . '(modules-zip/) vorhanden — die Regression gegen echte Manifeste entfällt in diesem Checkout.'
            );
        }

        foreach ($files as $file) {
            $info = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($info, "Kein gültiges JSON: $file");

            try {
                ilModuleManifest::validate($info);
            } catch (RuntimeException $e) {
                $this->fail("Echtes Manifest wird abgelehnt: $file — " . $e->getMessage());
            }
        }

        $this->assertGreaterThan(0, count($files));
    }
}
