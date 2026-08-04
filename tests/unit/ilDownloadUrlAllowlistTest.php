<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

require_once ETS_PLUGIN_ROOT . '/classes/class.ilExtendedTestSettingsConfigGUI.php';

/**
 * URL-Allowlist des Modul-Downloads (ConfigGUI::isAllowedDownloadUrl).
 *
 * Die Allowlist ist die letzte Schranke der Update-Kette aus Z-02: Sie begrenzt,
 * woher ein Artefakt überhaupt stammen darf. Fällt sie, ist der Weg zu direkter
 * Codeausführung auf dem Server offen — deshalb steht sie hier als reine Logik
 * unter Test, ohne ILIAS-Bootstrap.
 *
 * Ein früherer Bypass über str_contains() ist behoben; der Pfad-Präfix-Test hält
 * das fest.
 */
final class ilDownloadUrlAllowlistTest extends ilEtsTestCase
{
    private const REPO = 'https://github.com/andreschweigert/ExtendedTestSettings';

    /** Ruft die private Methode mit gesetzter Repo-URL auf. */
    private function isAllowed(string $url, string $repo_url = self::REPO): bool
    {
        $reflection = new ReflectionClass(ilExtendedTestSettingsConfigGUI::class);
        $gui        = $reflection->newInstanceWithoutConstructor();

        $settings = new ilSetting('extendedtestsettings');
        if ($repo_url !== '') {
            $settings->set('module_repo_url', $repo_url);
        }

        $reflection->getProperty('settings')->setValue($gui, $settings);

        return (bool) $reflection->getMethod('isAllowedDownloadUrl')->invoke($gui, $url);
    }

    #[DataProvider('erlaubteUrls')]
    public function testErlaubteUrlWirdAkzeptiert(string $url): void
    {
        $this->assertTrue($this->isAllowed($url), "Diese URL muss zulässig sein: $url");
    }

    public static function erlaubteUrls(): array
    {
        return [
            'raw.githubusercontent.com' => [
                'https://raw.githubusercontent.com/andreschweigert/ExtendedTestSettings/ilias9/modules/examcalc/ExamCalc_v1.2.0.zip',
            ],
            'github.com direkt' => [
                'https://github.com/andreschweigert/ExtendedTestSettings/raw/ilias9/modules/examcalc/ExamCalc_v1.2.0.zip',
            ],
        ];
    }

    #[DataProvider('abgelehnteUrls')]
    public function testUnzulaessigeUrlWirdAbgelehnt(string $url): void
    {
        $this->assertFalse($this->isAllowed($url), "Diese URL darf nicht zulässig sein: $url");
    }

    public static function abgelehnteUrls(): array
    {
        return [
            'kein HTTPS' => [
                'http://raw.githubusercontent.com/andreschweigert/ExtendedTestSettings/ilias9/x.zip',
            ],
            'fremder Host' => [
                'https://evil.example.org/andreschweigert/ExtendedTestSettings/ilias9/x.zip',
            ],
            'Host als Präfix' => [
                'https://raw.githubusercontent.com.evil.example.org/andreschweigert/ExtendedTestSettings/x.zip',
            ],
            'Host im Pfad' => [
                'https://evil.example.org/raw.githubusercontent.com/andreschweigert/ExtendedTestSettings/x.zip',
            ],
            'Userinfo-Trick' => [
                'https://raw.githubusercontent.com@evil.example.org/andreschweigert/ExtendedTestSettings/x.zip',
            ],
            'fremdes Repo mit gleichem Präfix' => [
                'https://raw.githubusercontent.com/andreschweigert/ExtendedTestSettingsEvil/ilias9/x.zip',
            ],
            'anderer Benutzer' => [
                'https://raw.githubusercontent.com/jemand-anders/ExtendedTestSettings/ilias9/x.zip',
            ],
            'Repo-Pfad nur enthalten, nicht am Anfang' => [
                'https://raw.githubusercontent.com/evil/andreschweigert/ExtendedTestSettings/x.zip',
            ],
            'Repo-Wurzel ohne Unterpfad' => [
                'https://raw.githubusercontent.com/andreschweigert/ExtendedTestSettings',
            ],
            'file-Schema' => [
                'file:///etc/passwd',
            ],
            'leer' => [
                '',
            ],
        ];
    }

    public function testOhneKonfiguriertesRepositoryIstNichtsErlaubt(): void
    {
        $this->assertFalse($this->isAllowed(
            'https://raw.githubusercontent.com/andreschweigert/ExtendedTestSettings/ilias9/x.zip',
            ''
        ));
    }

    /**
     * raw.githubusercontent.com ist nur zusätzlich erlaubt, wenn das Repository
     * tatsächlich auf github.com liegt.
     */
    public function testRawHostGiltNurFuerGithubRepositories(): void
    {
        $repo = 'https://gitlab.example.org/gruppe/Repo';

        $this->assertTrue($this->isAllowed('https://gitlab.example.org/gruppe/Repo/modules/x.zip', $repo));
        $this->assertFalse($this->isAllowed('https://raw.githubusercontent.com/gruppe/Repo/x.zip', $repo));
    }
}
