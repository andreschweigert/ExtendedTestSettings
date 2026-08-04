<?php

declare(strict_types=1);

/**
 * Registry: Discovery, Aktivstatus und Kill-Switch.
 *
 * Regressionen zu:
 *   C-01 / R3  "Deaktiviert" muss ein echter Kill-Switch sein — kein Modulcode
 *              wird geladen, getActiveModuleInfo() liefert null
 *   C-11       auch manuell abgelegte Module durchlaufen die Manifest-Validierung
 *   C-14       Lebenszyklus des Aktivstatus: Update behält ihn, Deinstallation
 *              vergisst ihn, frisch installierter Code ist nie automatisch aktiv
 */
final class ilModuleRegistryTest extends ilEtsTestCase
{
    private string $plugin_path;
    private string $modules_dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin_path = $this->pluginPath();
        $this->modules_dir = $this->plugin_path . '/modules';
    }

    // -------------------------------------------------------------------------
    // Kill-Switch (C-01 / R3)
    // -------------------------------------------------------------------------

    public function testInstalliertesModulIstStandardmaessigInaktiv(): void
    {
        ilEtsModuleFixture::installedModule($this->modules_dir, ilEtsModuleFixture::manifest());

        $registry = new ilModuleRegistry($this->plugin_path);

        $this->assertTrue($registry->moduleExists('testmodul'));
        $this->assertFalse($registry->isModuleActive('testmodul'));
    }

    public function testGetActiveModuleInfoLiefertBeiInaktivemModulNull(): void
    {
        ilEtsModuleFixture::installedModule($this->modules_dir, ilEtsModuleFixture::manifest());

        $registry = new ilModuleRegistry($this->plugin_path);

        $this->assertNull(
            $registry->getActiveModuleInfo('testmodul'),
            'Der Proxy löst Module ausschließlich hierüber auf — bei inaktiv muss null kommen.'
        );
        $this->assertNotNull(
            $registry->getModuleInfo('testmodul'),
            'Für die Verwaltungsansicht bleibt das Modul sichtbar.'
        );
    }

    public function testGetHandlerLiefertBeiInaktivemModulNull(): void
    {
        ilEtsModuleFixture::installedModule($this->modules_dir, ilEtsModuleFixture::manifest());

        $registry = new ilModuleRegistry($this->plugin_path);

        $this->assertNull($registry->getHandler('testmodul'));
        $this->assertSame([], $registry->getActiveHandlers());
    }

    /**
     * Der eigentliche Nachweis: Bei einem inaktiven Modul darf der Handler-Code
     * gar nicht erst in den PHP-Prozess gelangen.
     */
    public function testHandlerCodeWirdNurBeiAktivemModulGeladen(): void
    {
        $manifest = ilEtsModuleFixture::manifest();
        $class    = $manifest['handler_class'];
        ilEtsModuleFixture::installedModule($this->modules_dir, $manifest);

        $inaktiv = new ilModuleRegistry($this->plugin_path);
        $inaktiv->getActiveHandlers();

        $this->assertFalse(class_exists($class, false), "Handler-Klasse $class wurde trotz inaktivem Modul geladen.");

        $inaktiv->setModuleActive('testmodul', true);
        $handlers = (new ilModuleRegistry($this->plugin_path))->getActiveHandlers();

        $this->assertArrayHasKey('testmodul', $handlers);
        $this->assertInstanceOf($class, $handlers['testmodul']);
    }

    /**
     * C-11 in der Registry: handler_file wird per realpath() in den Modulordner
     * gezwungen. Ein Symlink nach außen darf keinen Code laden.
     */
    public function testHandlerAusserhalbDesModulordnersWirdNichtGeladen(): void
    {
        $manifest = ilEtsModuleFixture::manifest(['handler_file' => 'link.php']);
        $dir      = ilEtsModuleFixture::installedModule($this->modules_dir, $manifest);

        $outside = $this->tempDir() . '/aussen.php';
        $this->writeFile($outside, '<?php class ' . $manifest['handler_class'] . ' {}');

        // Die Fixture hat link.php als echte Datei angelegt — hier soll stattdessen
        // ein Symlink nach außen stehen.
        @unlink($dir . '/link.php');

        if (!@symlink($outside, $dir . '/link.php')) {
            $this->markTestSkipped('Symlinks sind in diesem Dateisystem nicht anlegbar — '
                . 'der Containment-Test für handler_file kann hier nicht laufen.');
        }

        $registry = new ilModuleRegistry($this->plugin_path);
        $registry->setModuleActive('testmodul', true);

        $this->assertSame([], $registry->getActiveHandlers());
        $this->assertFalse(class_exists($manifest['handler_class'], false));
    }

    // -------------------------------------------------------------------------
    // Manifest-Validierung bei manueller Ablage (C-11)
    // -------------------------------------------------------------------------

    public function testModulMitUngueltigerIdWirdUebersprungen(): void
    {
        $dir = $this->modules_dir . '/Evil';
        $this->writeFile($dir . '/module.json', (string) json_encode(
            ilEtsModuleFixture::manifest(['id' => 'Evil'])
        ));

        $registry = new ilModuleRegistry($this->plugin_path);

        $this->assertSame([], $registry->getAllModuleInfos());
    }

    public function testModulMitTraversalImManifestWirdUebersprungen(): void
    {
        $dir = $this->modules_dir . '/testmodul';
        $this->writeFile($dir . '/module.json', (string) json_encode(
            ilEtsModuleFixture::manifest(['handler_file' => '../../../evil.php'])
        ));

        $registry = new ilModuleRegistry($this->plugin_path);

        $this->assertSame([], $registry->getAllModuleInfos());
    }

    public function testUnleserlichesJsonWirdUebersprungen(): void
    {
        $this->writeFile($this->modules_dir . '/testmodul/module.json', '{ kein json');

        $this->assertSame([], (new ilModuleRegistry($this->plugin_path))->getAllModuleInfos());
    }

    // -------------------------------------------------------------------------
    // Lebenszyklus des Aktivstatus (C-14)
    // -------------------------------------------------------------------------

    public function testAktivstatusUeberlebtEineNeueRegistryInstanz(): void
    {
        ilEtsModuleFixture::installedModule($this->modules_dir, ilEtsModuleFixture::manifest());

        (new ilModuleRegistry($this->plugin_path))->setModuleActive('testmodul', true);

        $this->assertTrue((new ilModuleRegistry($this->plugin_path))->isModuleActive('testmodul'));
    }

    /** Update über ein aktives Modul: der Status bleibt absichtlich erhalten. */
    public function testUpdateBehaeltDenAktivstatus(): void
    {
        $installer = new ilModuleInstaller($this->plugin_path);
        $installer->installFromZip(ilEtsModuleFixture::zip(
            $this->tempDir() . '/v1.zip',
            ilEtsModuleFixture::manifest(['version' => '1.0.0'])
        ));

        (new ilModuleRegistry($this->plugin_path))->setModuleActive('testmodul', true);

        $installer->installFromZip(ilEtsModuleFixture::zip(
            $this->tempDir() . '/v2.zip',
            ilEtsModuleFixture::manifest(['version' => '2.0.0'])
        ));

        $this->assertTrue((new ilModuleRegistry($this->plugin_path))->isModuleActive('testmodul'));
    }

    /**
     * Kernfall C-14: aktiv → deinstallieren → erneut installieren ⇒ inaktiv.
     * Vorher überlebte der settings-Eintrag die Deinstallation, frisch
     * hochgeladener Handler-Code war dadurch sofort wieder aktiv.
     */
    public function testNachDeinstallationIstEineNeuinstallationInaktiv(): void
    {
        $installer = new ilModuleInstaller($this->plugin_path);
        $manifest  = ilEtsModuleFixture::manifest();

        $installer->installFromZip(ilEtsModuleFixture::zip($this->tempDir() . '/v1.zip', $manifest));

        $registry = new ilModuleRegistry($this->plugin_path);
        $registry->setModuleActive('testmodul', true);
        $this->assertTrue($registry->isModuleActive('testmodul'));

        // Deinstallation, wie die ConfigGUI sie ausführt
        $installer->uninstallModule('testmodul', $manifest + ['builtin' => false]);
        $registry->forgetModuleState('testmodul');

        // Erneuter Upload derselben ID
        $installer->installFromZip(ilEtsModuleFixture::zip($this->tempDir() . '/v2.zip', $manifest));

        $this->assertFalse(
            (new ilModuleRegistry($this->plugin_path))->isModuleActive('testmodul'),
            'Frisch installierter Handler-Code darf nicht automatisch aktiv sein.'
        );
    }

    public function testForgetModuleStateEntferntDasModulAusDerInstanz(): void
    {
        ilEtsModuleFixture::installedModule($this->modules_dir, ilEtsModuleFixture::manifest());

        $registry = new ilModuleRegistry($this->plugin_path);
        $registry->setModuleActive('testmodul', true);
        $registry->forgetModuleState('testmodul');

        $this->assertFalse($registry->moduleExists('testmodul'));
        $this->assertNull($registry->getActiveModuleInfo('testmodul'));
    }

    public function testSetModuleActiveIgnoriertUnbekannteId(): void
    {
        $registry = new ilModuleRegistry($this->plugin_path);
        $registry->setModuleActive('gibtesnicht', true);

        $this->assertFalse($registry->isModuleActive('gibtesnicht'));
        $this->assertNull($registry->getModuleInfo('gibtesnicht'));
    }

    // -------------------------------------------------------------------------
    // Tabellen
    // -------------------------------------------------------------------------

    public function testTabellenWerdenAusAllenModulenGesammelt(): void
    {
        ilEtsModuleFixture::installedModule($this->modules_dir, ilEtsModuleFixture::manifest([
            'id'     => 'modul_eins',
            'tables' => ['modul_eins_daten', 'gemeinsame_tabelle'],
        ]));
        ilEtsModuleFixture::installedModule($this->modules_dir, ilEtsModuleFixture::manifest([
            'id'     => 'modul_zwei',
            'tables' => ['modul_zwei_daten', 'gemeinsame_tabelle'],
        ]));

        $registry = new ilModuleRegistry($this->plugin_path);
        $tables   = $registry->getAllTables();
        sort($tables);

        $this->assertSame(['gemeinsame_tabelle', 'modul_eins_daten', 'modul_zwei_daten'], $tables);
        $this->assertSame(['modul_eins_daten', 'gemeinsame_tabelle'], $registry->getModuleTables('modul_eins'));
        $this->assertSame([], $registry->getModuleTables('gibtesnicht'));
    }

    public function testModulOhneTabellenLiefertLeereListe(): void
    {
        ilEtsModuleFixture::installedModule($this->modules_dir, ilEtsModuleFixture::manifest(['tables' => null]));

        $registry = new ilModuleRegistry($this->plugin_path);

        $this->assertSame([], $registry->getModuleTables('testmodul'));
        $this->assertSame([], $registry->getAllTables());
    }
}
