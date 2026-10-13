<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

final class ilModuleArchiveManifestSecurityTest extends ilEtsTestCase
{
    public function testZweitesManifestKannModulIdUndVersionNichtUmschreiben(): void
    {
        $manifest = ilEtsModuleFixture::manifest();
        $old = ilEtsModuleFixture::installedModule($this->pluginPath() . '/modules', $manifest);
        $other = $manifest;
        $other['id'] = 'othermodule';
        $other['version'] = '99.0.0';
        $zip = ilEtsModuleFixture::zip($this->tempDir() . '/ambiguous.zip', $manifest, [
            'extra/module.json' => json_encode($other),
            'sql/install.php' => '<?php $db->createTable("must_not_run", []);',
        ]);
        try {
            (new ilModuleInstaller($this->pluginPath()))->installFromZip($zip);
            $this->fail('Mehrere Paket-Manifeste wurden akzeptiert.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('uneindeutiges module.json', $e->getMessage());
        }
        $this->assertSame($manifest, json_decode(file_get_contents($old . '/module.json'), true));
        $this->assertDirectoryDoesNotExist($this->pluginPath() . '/modules/othermodule');
        $this->assertSame([], $this->db()->calls);
        $this->assertSame([], glob($this->pluginPath() . '/modules/.ets-*') ?: []);
    }

    public function testDoppelteZipEintraegeWerdenAbgewiesen(): void
    {
        $manifest = ilEtsModuleFixture::manifest();
        $zip = ilEtsModuleFixture::zip($this->tempDir() . '/duplicates.zip', $manifest,
            ['shadow.json' => json_encode(array_replace($manifest, ['version' => '99.0.0']))]);
        // ZipArchive ersetzt beim Erzeugen gleichnamige Einträge. Gleich lange
        // Namen in beiden ZIP-Verzeichnissen umschreiben, um echte Duplikate zu erzeugen.
        $contents = str_replace('shadow.json', 'module.json', file_get_contents($zip), $replaced);
        $this->assertSame(2, $replaced);
        file_put_contents($zip, $contents);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Doppelter Dateipfad');
        (new ilModuleInstaller($this->pluginPath()))->readManifestFromZip($zip);
    }

    #[DataProvider('mehrdeutigePfade')]
    public function testPfadAliaseKoennenDasManifestNichtUeberschreiben(string $path): void
    {
        $manifest = ilEtsModuleFixture::manifest();
        $zip = ilEtsModuleFixture::zip($this->tempDir() . '/alias.zip', $manifest,
            [$path => json_encode(array_replace($manifest, ['version' => '99.0.0']))]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ungültiger Dateipfad');
        (new ilModuleInstaller($this->pluginPath()))->readManifestFromZip($zip);
    }

    public static function mehrdeutigePfade(): array
    {
        return ['Punktsegment' => ['./module.json'], 'doppelter Slash' => ['extra//module.json'],
            'Backslash' => ['extra\\module.json']];
    }

    public function testManifestOhneGemeinsamenWrapperWirdAbgewiesen(): void
    {
        $manifest = ilEtsModuleFixture::manifest();
        $zip = ilEtsModuleFixture::zip($this->tempDir() . '/mixed.zip', $manifest, [], 'wrapper/');
        $archive = new ZipArchive();
        $this->assertTrue($archive->open($zip));
        $archive->addFromString('README.md', 'außerhalb des Wrappers');
        $archive->close();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('uneindeutiges module.json');
        (new ilModuleInstaller($this->pluginPath()))->readManifestFromZip($zip);
    }

    #[DataProvider('manifestManipulationen')]
    public function testEntpacktesManifestMussVollstaendigMitDemGeprueftenUebereinstimmen(array $changes): void
    {
        $manifest = ilEtsModuleFixture::manifest();
        $dir = ilEtsModuleFixture::installedModule($this->pluginPath() . '/modules', $manifest);
        file_put_contents($dir . '/module.json', json_encode(array_replace($manifest, $changes)));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stimmt nicht mit dem geprüften Manifest überein');
        $installer = new ilModuleInstaller($this->pluginPath());
        (new ReflectionMethod($installer, 'verifyExtraction'))->invoke($installer, $dir, $manifest);
    }

    public static function manifestManipulationen(): array
    {
        return ['ID' => [['id' => 'othermodule']], 'Version' => [['version' => '99.0.0']],
            'Handler' => [['handler_file' => 'other.php']], 'Metadaten' => [['label' => 'Other label']]];
    }

    public function testRootUndGemeinsamerWrapperBleibenInstallierbar(): void
    {
        $installer = new ilModuleInstaller($this->pluginPath());
        foreach (['', 'wrapper/'] as $index => $prefix) {
            $manifest = ilEtsModuleFixture::manifest(['version' => '1.0.' . $index]);
            $zip = ilEtsModuleFixture::zip($this->tempDir() . '/valid-' . $index . '.zip', $manifest, [], $prefix);
            $this->assertSame($manifest, $installer->installFromZip($zip));
            $this->assertSame($manifest, json_decode(file_get_contents(
                $this->pluginPath() . '/modules/testmodul/module.json'), true));
        }
    }
}
