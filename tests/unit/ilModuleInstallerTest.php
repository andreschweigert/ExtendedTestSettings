<?php

declare(strict_types=1);

/**
 * Installation, Update und Deinstallation von Modulen.
 *
 * Regressionen zu:
 *   C-05  Update war destruktiv und nicht atomar (erst löschen, dann entpacken)
 *   C-06  Deinstallation meldete Erfolg, obwohl das Uninstall-SQL fehlschlug
 *   C-09  Update war nicht an Modul-ID und Version des Artefakts gebunden
 *   C-11  Manifest-Prüfung auch im Upload-Pfad
 *
 * Alle Tests arbeiten gegen ein Temp-Verzeichnis; das echte modules/-Verzeichnis
 * der Installation wird nie angefasst (der Pfad wird injiziert).
 */
final class ilModuleInstallerTest extends ilEtsTestCase
{
    private string $plugin_path;
    private string $modules_dir;
    private ilModuleInstaller $installer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin_path = $this->pluginPath();
        $this->modules_dir = $this->plugin_path . '/modules';
        $this->installer   = new ilModuleInstaller($this->plugin_path);
    }

    // -------------------------------------------------------------------------
    // Installation (C-05)
    // -------------------------------------------------------------------------

    public function testErstinstallationLegtModulAn(): void
    {
        $manifest = ilEtsModuleFixture::manifest();
        $zip      = ilEtsModuleFixture::zip($this->tempDir() . '/modul.zip', $manifest);

        $info = $this->installer->installFromZip($zip);

        $this->assertSame('testmodul', $info['id']);
        $this->assertFileExists($this->modules_dir . '/testmodul/module.json');
        $this->assertFileExists($this->modules_dir . '/testmodul/' . $manifest['handler_file']);
        $this->assertNoLeftovers();
    }

    public function testInstallSqlWirdAusgefuehrt(): void
    {
        $zip = ilEtsModuleFixture::zip(
            $this->tempDir() . '/modul.zip',
            ilEtsModuleFixture::manifest(),
            ['sql/install.php' => '<?php $db->createTable("testmodul_settings", []);']
        );

        $this->installer->installFromZip($zip);

        $this->assertContains('createTable:testmodul_settings', $this->db()->calls);
    }

    public function testWrapperOrdnerImZipWirdEntfernt(): void
    {
        $manifest = ilEtsModuleFixture::manifest();
        $zip      = ilEtsModuleFixture::zip(
            $this->tempDir() . '/modul.zip',
            $manifest,
            [],
            'ExamTest_v1.0.0/'
        );

        $this->installer->installFromZip($zip);

        $this->assertFileExists($this->modules_dir . '/testmodul/module.json');
        $this->assertDirectoryDoesNotExist($this->modules_dir . '/testmodul/ExamTest_v1.0.0');
    }

    public function testUpdateErsetztDenAltenStandVollstaendig(): void
    {
        $v1 = ilEtsModuleFixture::manifest(['version' => '1.0.0']);
        $this->installer->installFromZip(ilEtsModuleFixture::zip(
            $this->tempDir() . '/v1.zip',
            $v1,
            ['nur-in-v1.php' => '<?php // fällt beim Update weg']
        ));

        $v2 = ilEtsModuleFixture::manifest(['version' => '2.0.0']);
        $this->installer->installFromZip(ilEtsModuleFixture::zip($this->tempDir() . '/v2.zip', $v2));

        $installed = json_decode((string) file_get_contents($this->modules_dir . '/testmodul/module.json'), true);

        $this->assertSame('2.0.0', $installed['version']);
        $this->assertFileDoesNotExist(
            $this->modules_dir . '/testmodul/nur-in-v1.php',
            'Beim Update darf kein Mischbestand aus alten und neuen Dateien entstehen.'
        );
        $this->assertNoLeftovers();
    }

    /**
     * Kernfall C-05: Bricht die DB-Migration ab, muss der alte Stand vollständig
     * zurückkommen. Vor dem Fix war das Modul an dieser Stelle bereits gelöscht.
     */
    public function testFehlerInInstallSqlStelltAltenStandWiederHer(): void
    {
        $v1 = ilEtsModuleFixture::manifest(['version' => '1.0.0']);
        $this->installer->installFromZip(ilEtsModuleFixture::zip(
            $this->tempDir() . '/v1.zip',
            $v1,
            ['nur-in-v1.php' => '<?php // muss den Fehlversuch überleben']
        ));

        $v2  = ilEtsModuleFixture::manifest(['version' => '2.0.0']);
        $zip = ilEtsModuleFixture::zip(
            $this->tempDir() . '/v2.zip',
            $v2,
            ['sql/install.php' => '<?php throw new RuntimeException("Migration fehlgeschlagen");']
        );

        try {
            $this->installer->installFromZip($zip);
            $this->fail('Fehlgeschlagene DB-Migration muss eine Exception auslösen.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('DB-Installation fehlgeschlagen', $e->getMessage());
        }

        $installed = json_decode((string) file_get_contents($this->modules_dir . '/testmodul/module.json'), true);

        $this->assertSame('1.0.0', $installed['version'], 'Der alte Stand muss wiederhergestellt sein.');
        $this->assertFileExists($this->modules_dir . '/testmodul/nur-in-v1.php');
        $this->assertFileDoesNotExist($this->modules_dir . '/testmodul/sql/install.php');
        $this->assertNoLeftovers();
    }

    /**
     * Dasselbe für den Fall, dass schon das Entpacken unvollständig ist: das
     * Manifest nennt eine Datei, die im ZIP fehlt.
     */
    public function testUnvollstaendigesUpdateLaesstAltenStandUnberuehrt(): void
    {
        $v1 = ilEtsModuleFixture::manifest(['version' => '1.0.0']);
        $this->installer->installFromZip(ilEtsModuleFixture::zip($this->tempDir() . '/v1.zip', $v1));

        // ZIP von Hand bauen: nur module.json, ohne die im Manifest genannte Handler-Datei
        $v2  = ilEtsModuleFixture::manifest(['version' => '2.0.0']);
        $zip = (new ilEtsZipBuilder($this->tempDir() . '/v2.zip'))
            ->add('module.json', (string) json_encode($v2))
            ->close();

        try {
            $this->installer->installFromZip($zip);
            $this->fail('Ein unvollständiges ZIP muss abgelehnt werden.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('unvollständig', $e->getMessage());
        }

        $installed = json_decode((string) file_get_contents($this->modules_dir . '/testmodul/module.json'), true);

        $this->assertSame('1.0.0', $installed['version']);
        $this->assertNoLeftovers();
    }

    public function testKeineStagingResteNachErfolgreicherInstallation(): void
    {
        $this->installer->installFromZip(
            ilEtsModuleFixture::zip($this->tempDir() . '/modul.zip', ilEtsModuleFixture::manifest())
        );

        $this->assertNoLeftovers();
    }

    /**
     * Staging- und Backup-Verzeichnisse beginnen mit einem Punkt. Selbst wenn
     * eines liegen bleibt, darf die Registry-Discovery es nicht als Modul sehen.
     */
    public function testPunktVerzeichnisseWerdenNichtAlsModulEntdeckt(): void
    {
        $manifest = ilEtsModuleFixture::manifest();
        $this->installer->installFromZip(
            ilEtsModuleFixture::zip($this->tempDir() . '/modul.zip', $manifest)
        );

        // Rest eines abgebrochenen Laufs von Hand nachstellen
        $leftover = $this->modules_dir . '/.ets-backup-testmodul-deadbeef';
        $this->writeFile($leftover . '/module.json', (string) json_encode($manifest));

        $found = glob($this->modules_dir . '/*/module.json') ?: [];

        $this->assertSame(
            [$this->modules_dir . '/testmodul/module.json'],
            $found,
            "glob('modules/*/module.json') darf Punkt-Verzeichnisse nicht erfassen."
        );

        $registry = new ilModuleRegistry($this->plugin_path);
        $this->assertCount(1, $registry->getAllModuleInfos());
    }

    // -------------------------------------------------------------------------
    // ZIP-Validierung (C-11 im Upload-Pfad)
    // -------------------------------------------------------------------------

    public function testPathTraversalImZipWirdAbgelehnt(): void
    {
        $manifest = ilEtsModuleFixture::manifest();
        $zip      = (new ilEtsZipBuilder($this->tempDir() . '/evil.zip'))
            ->add('module.json', (string) json_encode($manifest))
            ->add($manifest['handler_file'], '<?php')
            ->add('../evil.php', '<?php // ausserhalb')
            ->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ungültiger Dateipfad im ZIP');

        $this->installer->installFromZip($zip);
    }

    public function testNichtErlaubteDateiendungWirdAbgelehnt(): void
    {
        $manifest = ilEtsModuleFixture::manifest();
        $zip      = (new ilEtsZipBuilder($this->tempDir() . '/evil.zip'))
            ->add('module.json', (string) json_encode($manifest))
            ->add($manifest['handler_file'], '<?php')
            ->add('shell.phtml', '<?php // umgeht die php-Endung')
            ->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Nicht erlaubte Dateiendung 'phtml'");

        $this->installer->installFromZip($zip);
    }

    public function testUngueltigeModulIdImZipWirdAbgelehnt(): void
    {
        $zip = ilEtsModuleFixture::zip(
            $this->tempDir() . '/evil.zip',
            ilEtsModuleFixture::manifest(['id' => '../../evil'])
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ungültige Modul-ID');

        $this->installer->installFromZip($zip);
    }

    public function testFehlendesManifestWirdAbgelehnt(): void
    {
        $zip = (new ilEtsZipBuilder($this->tempDir() . '/ohne-manifest.zip'))
            ->add('class.ilFoo.php', '<?php')
            ->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('module.json fehlt im ZIP-Archiv');

        $this->installer->installFromZip($zip);
    }

    public function testKeinZipArchivWirdAbgelehnt(): void
    {
        $file = $this->tempDir() . '/kein.zip';
        $this->writeFile($file, 'das ist kein ZIP');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('kein gültiges ZIP-Archiv');

        $this->installer->installFromZip($file);
    }

    // -------------------------------------------------------------------------
    // Manifest vor der Installation lesen (C-09)
    // -------------------------------------------------------------------------

    /**
     * C-09: Vor dem Fix wurde ein heruntergeladenes ZIP ungeprüft installiert und
     * anschließend die ID aus dem Request aktiviert. readManifestFromZip() macht
     * die Erwartungsprüfung möglich, ohne etwas zu schreiben.
     */
    public function testReadManifestFromZipSchreibtNichts(): void
    {
        $zip = ilEtsModuleFixture::zip(
            $this->tempDir() . '/fremd.zip',
            ilEtsModuleFixture::manifest(['id' => 'fremdesmodul', 'version' => '9.9.9'])
        );

        $info = $this->installer->readManifestFromZip($zip);

        $this->assertSame('fremdesmodul', $info['id']);
        $this->assertSame('9.9.9', $info['version']);
        $this->assertSame([], glob($this->modules_dir . '/*') ?: [], 'Es darf nichts installiert worden sein.');
        $this->assertNoLeftovers();
    }

    /**
     * Die ZIP-Einträge selbst sind hier unauffällig — der Traversal steckt nur im
     * Manifest. Ohne die gemeinsame Manifest-Prüfung (C-11) käme das durch.
     */
    public function testReadManifestFromZipValidiertDasManifest(): void
    {
        $manifest = ilEtsModuleFixture::manifest(['handler_file' => '/etc/passwd.php']);
        $zip      = (new ilEtsZipBuilder($this->tempDir() . '/evil.zip'))
            ->add('module.json', (string) json_encode($manifest))
            ->add('class.ilHarmlos.php', '<?php')
            ->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Ungültiger Dateipfad in 'handler_file'");

        $this->installer->readManifestFromZip($zip);
    }

    public function testReadManifestFromZipMeldetFehlendeDatei(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ZIP-Datei nicht gefunden');

        $this->installer->readManifestFromZip($this->tempDir() . '/gibtesnicht.zip');
    }

    // -------------------------------------------------------------------------
    // Deinstallation (C-06)
    // -------------------------------------------------------------------------

    public function testErfolgreicheDeinstallationEntferntOrdnerUndFuehrtSqlAus(): void
    {
        $manifest = ilEtsModuleFixture::manifest();
        $this->installer->installFromZip(ilEtsModuleFixture::zip(
            $this->tempDir() . '/modul.zip',
            $manifest,
            ['sql/uninstall.php' => '<?php $db->dropTable("testmodul_settings");']
        ));

        $this->installer->uninstallModule('testmodul', $manifest + ['builtin' => false]);

        $this->assertContains('dropTable:testmodul_settings', $this->db()->calls);
        $this->assertDirectoryDoesNotExist($this->modules_dir . '/testmodul');
    }

    /**
     * Kernfall C-06: Scheitert das Uninstall-SQL, darf der Ordner NICHT gelöscht
     * werden — sonst verschwindet der einzige Deinstallationscode, während die
     * Tabellen samt Daten stehen bleiben.
     */
    public function testFehlerInUninstallSqlPropagiertUndModulBleibtErhalten(): void
    {
        $manifest = ilEtsModuleFixture::manifest();
        $this->installer->installFromZip(ilEtsModuleFixture::zip(
            $this->tempDir() . '/modul.zip',
            $manifest,
            ['sql/uninstall.php' => '<?php throw new RuntimeException("DROP fehlgeschlagen");']
        ));

        try {
            $this->installer->uninstallModule('testmodul', $manifest + ['builtin' => false]);
            $this->fail('Ein Fehler im Uninstall-SQL muss propagiert werden.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Datenbank-Deinstallation fehlgeschlagen', $e->getMessage());
            $this->assertStringContainsString('NICHT entfernt', $e->getMessage());
        }

        $this->assertDirectoryExists(
            $this->modules_dir . '/testmodul',
            'Das Modul muss für einen erneuten Versuch erhalten bleiben.'
        );
        $this->assertFileExists($this->modules_dir . '/testmodul/sql/uninstall.php');
    }

    public function testBuiltinModulKannNichtDeinstalliertWerden(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Built-in Module können nicht deinstalliert werden');

        $this->installer->uninstallModule('testmodul', ilEtsModuleFixture::manifest() + ['builtin' => true]);
    }

    // -------------------------------------------------------------------------
    // Hilfsmittel
    // -------------------------------------------------------------------------

    /** Nach jedem Lauf darf kein .ets-staging-* oder .ets-backup-* übrig sein. */
    private function assertNoLeftovers(): void
    {
        $leftovers = glob($this->modules_dir . '/.ets-*') ?: [];

        $this->assertSame([], $leftovers, 'Staging-/Backup-Reste in modules/: ' . implode(', ', $leftovers));
    }
}
