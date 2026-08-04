<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;

/**
 * Ressourcengrenzen beim ZIP-Upload — Regression zu C-10.
 *
 * C-10: Es gab nur ein 20-MB-Limit auf das komprimierte Archiv. Das schützt
 * nicht gegen Dekompressions-DoS; seit 2.1.0 werden Einträge, Einzeldateigröße,
 * entpackte Gesamtgröße und Kompressionsverhältnis VOR dem Entpacken über
 * statIndex() geprüft.
 *
 * Die Testarchive werden über dünn besetzte Dateien erzeugt und belegen kaum
 * Plattenplatz; die Läufe dauern trotzdem etwas länger als der Rest der Suite.
 */
#[Group('slow')]
final class ilModuleZipLimitsTest extends ilEtsTestCase
{
    private const MB = 1024 * 1024;

    private ilModuleInstaller $installer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installer = new ilModuleInstaller($this->pluginPath());
    }

    // -------------------------------------------------------------------------
    // Grenzen greifen
    // -------------------------------------------------------------------------

    public function testEinzelneZuGrosseDateiWirdAbgelehnt(): void
    {
        $zip = $this->zipWith(static function (ilEtsZipBuilder $b): void {
            $b->addGenerated('daten.txt', 80 * self::MB);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Datei im ZIP ist zu groß');

        $this->installer->readManifestFromZip($zip);
    }

    public function testZuVieleEintraegeWerdenAbgelehnt(): void
    {
        $zip = $this->zipWith(static function (ilEtsZipBuilder $b): void {
            for ($i = 0; $i < 501; $i++) {
                $b->add("fuell-$i.txt", 'x');
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('zu viele Einträge');

        $this->installer->readManifestFromZip($zip);
    }

    public function testZuGrosserEntpackterGesamtinhaltWirdAbgelehnt(): void
    {
        // Jede Datei bleibt unter dem Einzellimit, in Summe reißen sie die Grenze.
        $zip = $this->zipWith(static function (ilEtsZipBuilder $b): void {
            for ($i = 0; $i < 4; $i++) {
                $b->addGenerated("teil-$i.txt", 16 * self::MB);
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Entpackter Inhalt ist zu groß');

        $this->installer->readManifestFromZip($zip);
    }

    /**
     * Zip-Bombe im Kleinen: Gesamtgröße und Einzeldateien bleiben unter den
     * Grenzen, nur das Verhältnis komprimiert/entpackt ist auffällig.
     */
    public function testVerdaechtigesKompressionsverhaeltnisWirdAbgelehnt(): void
    {
        $zip = $this->zipWith(static function (ilEtsZipBuilder $b): void {
            for ($i = 0; $i < 3; $i++) {
                $b->addGenerated("teil-$i.txt", 17 * self::MB, 100 * 1024);
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Kompressionsverhältnis');

        $this->installer->readManifestFromZip($zip);
    }

    /**
     * Die Größenprüfung des Archivs selbst sitzt in installFromZip() — vor dem
     * Öffnen. readManifestFromZip() prüft sie nicht; dort ist die Grenze bereits
     * durch CURLOPT_MAXFILESIZE des Downloads gezogen.
     */
    public function testZuGrossesArchivWirdVorDemOeffnenAbgelehnt(): void
    {
        // Nicht komprimierbarer Inhalt: die Datei selbst überschreitet 20 MB.
        $zip = $this->zipWith(static function (ilEtsZipBuilder $b): void {
            $b->addGenerated('zufall.txt', 21 * self::MB, 21 * self::MB);
        });

        $this->assertGreaterThan(20 * self::MB, (int) filesize($zip));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ZIP-Datei ist zu groß');

        $this->installer->installFromZip($zip);
    }

    // -------------------------------------------------------------------------
    // Gegenprobe: die Grenzen dürfen normale Module nicht treffen
    // -------------------------------------------------------------------------

    public function testNormalesModulZipPassiert(): void
    {
        $zip = $this->zipWith(static function (ilEtsZipBuilder $b): void {
            $b->add('js/modul.js', str_repeat("// Kommentar\n", 5000));
            $b->add('css/modul.css', str_repeat(".a{color:red}\n", 5000));
            $b->addGenerated('img/logo.png', 300 * 1024, 300 * 1024);
        });

        $info = $this->installer->readManifestFromZip($zip);

        $this->assertSame('testmodul', $info['id']);
    }

    public function testEintragszahlKnappUnterDemLimitPassiert(): void
    {
        $zip = $this->zipWith(static function (ilEtsZipBuilder $b): void {
            for ($i = 0; $i < 400; $i++) {
                $b->add("lang/text-$i.txt", 'x');
            }
        });

        $this->assertSame('testmodul', $this->installer->readManifestFromZip($zip)['id']);
    }

    /**
     * Ein 20-MB-Download-Limit im UpdateChecker und ein 20-MB-Upload-Limit im
     * Installer müssen zusammenpassen — sonst lädt der Updatepfad Artefakte,
     * die der Installer anschließend ablehnt.
     */
    public function testDownloadLimitUndUploadLimitSindDeckungsgleich(): void
    {
        $installer_limit = (new ReflectionClass(ilModuleInstaller::class))
            ->getConstant('MAX_ZIP_SIZE_BYTES');
        $download_limit  = (new ReflectionClass(ilModuleUpdateChecker::class))
            ->getConstant('MAX_DOWNLOAD_BYTES');

        $this->assertSame(20 * self::MB, $installer_limit);
        $this->assertSame($installer_limit, $download_limit);
    }

    // -------------------------------------------------------------------------
    // Hilfsmittel
    // -------------------------------------------------------------------------

    /**
     * Baut ein ZIP mit gültigem Manifest plus dem, was $fill ergänzt.
     *
     * @param callable(ilEtsZipBuilder):void $fill
     */
    private function zipWith(callable $fill): string
    {
        $manifest = ilEtsModuleFixture::manifest();
        $builder  = new ilEtsZipBuilder($this->tempDir() . '/limits.zip');

        $builder->add('module.json', (string) json_encode($manifest));
        $builder->add($manifest['handler_file'], ilEtsModuleFixture::handlerCode($manifest['handler_class']));
        $fill($builder);

        return $builder->close();
    }
}
