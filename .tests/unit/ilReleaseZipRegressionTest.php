<?php

declare(strict_types=1);

/**
 * Regressionsschutz gegen zu strenge Grenzen (C-10) und zu strenge
 * Manifestregeln (C-11): Jedes echte Release-ZIP aus modules-zip/ muss die
 * Validierung des Installers weiterhin bestehen.
 *
 * Die Fixtures liegen im Modul-Repo, das aus Sicht des Hauptrepos gitignored
 * ist. Fehlt es (frischer Clone), überspringt sich der Test mit Begründung —
 * er meldet dann ausdrücklich nicht "grün".
 */
final class ilReleaseZipRegressionTest extends ilEtsTestCase
{
    private const MODULES_ZIP = ETS_PLUGIN_ROOT . '/modules-zip/modules';

    /** @return array<string,string> id => ZIP-Pfad */
    private function releaseZips(): array
    {
        $zips = [];

        foreach (glob(self::MODULES_ZIP . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id    = basename($dir);
            $found = glob($dir . '/*.zip') ?: [];

            $this->assertCount(
                1,
                $found,
                "Modul '$id' hat " . count($found) . " ZIP-Dateien im Modulordner — erwartet wird genau eine "
                . '(ältere Stände gehören nach archive/).'
            );

            $zips[$id] = $found[0];
        }

        return $zips;
    }

    public function testAlleReleaseZipsWerdenWeiterhinAkzeptiert(): void
    {
        if (!is_dir(self::MODULES_ZIP)) {
            $this->markTestSkipped(
                'Modul-Repository nicht vorhanden (' . self::MODULES_ZIP . '). '
                . 'Die Regression gegen die echten Release-ZIPs kann in diesem Checkout nicht laufen — '
                . 'modules-zip/ ist ein eigenes Git-Repo und im Hauptrepo gitignored.'
            );
        }

        $installer = new ilModuleInstaller($this->pluginPath());
        $zips      = $this->releaseZips();

        $this->assertNotEmpty($zips, 'Kein einziges Release-ZIP gefunden.');

        foreach ($zips as $id => $zip) {
            try {
                $info = $installer->readManifestFromZip($zip);
            } catch (RuntimeException $e) {
                $this->fail("Release-ZIP wird abgelehnt: $zip — " . $e->getMessage());
            }

            $this->assertSame(
                $id,
                $info['id'],
                "Das ZIP in modules/$id/ installiert unter der ID '{$info['id']}' — "
                . 'Ordnername und Modul-ID müssen übereinstimmen.'
            );
        }
    }

    /**
     * Nichts von alledem darf etwas geschrieben haben: readManifestFromZip()
     * ist der Prüfschritt vor der Installation (C-09).
     */
    public function testDieRegressionInstalliertNichts(): void
    {
        if (!is_dir(self::MODULES_ZIP)) {
            $this->markTestSkipped('Modul-Repository nicht vorhanden — siehe vorheriger Test.');
        }

        $plugin_path = $this->pluginPath();
        $installer   = new ilModuleInstaller($plugin_path);

        foreach ($this->releaseZips() as $zip) {
            $installer->readManifestFromZip($zip);
        }

        $this->assertSame([], glob($plugin_path . '/modules/*') ?: []);
    }
}
