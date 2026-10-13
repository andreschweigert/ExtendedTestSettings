<?php

declare(strict_types=1);

require_once ETS_PLUGIN_ROOT . '/classes/class.ilExtendedTestSettingsModuleProxyGUI.php';

final class ilResourceModuleActivationTest extends ilEtsTestCase
{
    private function allowed(ilModuleRegistry $registry): bool
    {
        $proxy = new ilExtendedTestSettingsModuleProxyGUI();
        return (bool) (new ReflectionMethod($proxy, 'isResourceModuleActive'))->invoke($proxy, $registry);
    }

    public function testNurAktiverBesitzerDerHilfsmitteltabelleIstErlaubt(): void
    {
        $manifest = ilEtsModuleFixture::manifest(['id' => 'resourceowner', 'tables' => ['examressheet_settings']]);
        ilEtsModuleFixture::installedModule($this->pluginPath() . '/modules', $manifest,
            [$manifest['handler_file'] => '<?php throw new RuntimeException("Handler darf nicht geladen werden");']);
        $registry = new ilModuleRegistry($this->pluginPath());

        $this->assertFalse($this->allowed($registry));
        $registry->setModuleActive('resourceowner', true);
        $this->assertTrue($this->allowed($registry));
        $registry->setModuleActive('resourceowner', false);
        $this->assertFalse($this->allowed($registry));
    }

    public function testFehlendesModulUndFremdesAktivesModulErlaubenKeineDateien(): void
    {
        $this->assertFalse($this->allowed(new ilModuleRegistry($this->pluginPath())));
        ilEtsModuleFixture::installedModule($this->pluginPath() . '/modules', ilEtsModuleFixture::manifest());
        $registry = new ilModuleRegistry($this->pluginPath());
        $registry->setModuleActive('testmodul', true);
        $this->assertFalse($this->allowed($registry));
    }

    public function testUneindeutigerTabellenbesitzerWirdAbgewiesen(): void
    {
        foreach (['resourceone', 'resourcetwo'] as $id) {
            ilEtsModuleFixture::installedModule($this->pluginPath() . '/modules',
                ilEtsModuleFixture::manifest(['id' => $id, 'tables' => ['examressheet_settings']]));
        }
        $registry = new ilModuleRegistry($this->pluginPath());
        $registry->setModuleActive('resourceone', true);
        $registry->setModuleActive('resourcetwo', true);
        $this->assertFalse($this->allowed($registry));
    }
}
