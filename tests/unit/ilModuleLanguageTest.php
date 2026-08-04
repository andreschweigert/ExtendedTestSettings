<?php

declare(strict_types=1);

/**
 * Modul-Sprachdateien: Fallback-Kette aktuelle Sprache → Englisch → Default → Schlüssel.
 *
 * ILIAS liest die lang/-Dateien der Module nicht selbst ein; die Kette ist
 * deshalb die einzige Stelle, an der Modultexte aufgelöst werden.
 */
final class ilModuleLanguageTest extends ilEtsTestCase
{
    /** Legt ein Modulverzeichnis mit den übergebenen Sprachdateien an. */
    private function moduleWithLang(array $files): string
    {
        $dir = $this->tempDir() . '/modul';

        foreach ($files as $lang => $content) {
            $this->writeFile($dir . '/lang/ilias_' . $lang . '.lang', $content);
        }

        return $dir;
    }

    public function testTextDerAktuellenSpracheGewinnt(): void
    {
        $dir = $this->moduleWithLang([
            'de' => "<!-- language file start -->\nenable#:#Aktivieren\n",
            'en' => "<!-- language file start -->\nenable#:#Enable\n",
        ]);

        $this->assertSame('Aktivieren', (new ilModuleLanguage($dir, 'de'))->txt('enable'));
        $this->assertSame('Enable', (new ilModuleLanguage($dir, 'en'))->txt('enable'));
    }

    public function testFehlenderSchluesselFaelltAufEnglischZurueck(): void
    {
        $dir = $this->moduleWithLang([
            'de' => "enable#:#Aktivieren\n",
            'en' => "enable#:#Enable\nonly_en#:#English only\n",
        ]);

        $this->assertSame('English only', (new ilModuleLanguage($dir, 'de'))->txt('only_en'));
    }

    public function testUnbekannteSpracheNutztEnglisch(): void
    {
        $dir = $this->moduleWithLang([
            'de' => "enable#:#Aktivieren\n",
            'en' => "enable#:#Enable\n",
        ]);

        $this->assertSame('Enable', (new ilModuleLanguage($dir, 'fr'))->txt('enable'));
    }

    public function testUnbekannterSchluesselLiefertDefault(): void
    {
        $dir = $this->moduleWithLang(['en' => "enable#:#Enable\n"]);

        $this->assertSame('Ersatztext', (new ilModuleLanguage($dir, 'de'))->txt('fehlt', 'Ersatztext'));
    }

    public function testOhneDefaultKommtDerSchluesselZurueck(): void
    {
        $dir = $this->moduleWithLang(['en' => "enable#:#Enable\n"]);

        $this->assertSame('fehlt', (new ilModuleLanguage($dir, 'de'))->txt('fehlt'));
    }

    public function testFehlendesLangVerzeichnisIstUnkritisch(): void
    {
        $dir = $this->tempDir() . '/modul-ohne-lang';
        $this->writeFile($dir . '/module.json', '{}');

        $this->assertSame('enable', (new ilModuleLanguage($dir, 'de'))->txt('enable'));
    }

    public function testKopfzeileUndZeilenOhneTrennerWerdenUebersprungen(): void
    {
        $dir = $this->moduleWithLang([
            'en' => "<!-- language file start -->\n"
                . "# Kommentarzeile ohne Trenner\n"
                . "enable#:#Enable\n",
        ]);

        $lang = new ilModuleLanguage($dir, 'en');

        $this->assertSame('Enable', $lang->txt('enable'));
        $this->assertSame('# Kommentarzeile ohne Trenner', $lang->txt('# Kommentarzeile ohne Trenner'));
    }

    public function testTrennerImTextBleibtErhalten(): void
    {
        $dir = $this->moduleWithLang(['en' => "hint#:#a#:#b\n"]);

        $this->assertSame('a#:#b', (new ilModuleLanguage($dir, 'en'))->txt('hint'));
    }

    public function testLeerraumImSchluesselWirdIgnoriert(): void
    {
        $dir = $this->moduleWithLang(['en' => "  enable  #:#Enable\n"]);

        $this->assertSame('Enable', (new ilModuleLanguage($dir, 'en'))->txt('enable'));
    }
}
