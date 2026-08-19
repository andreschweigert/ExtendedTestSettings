<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * UpdateChecker: URL-Ableitung und Versions-Parsing.
 *
 * Getestet wird ausschließlich die reine Logik — die Suite geht zur Laufzeit
 * nicht ins Netz. Die Download-Grenze aus C-10 wird in ilModuleZipLimitsTest
 * gegen das Upload-Limit gegengeprüft.
 */
final class ilModuleUpdateCheckerTest extends ilEtsTestCase
{
    private function apiUrl(string $repo, string $branch, string $module_id): string
    {
        $checker = new ilModuleUpdateChecker($repo, $branch);
        $method  = (new ReflectionClass(ilModuleUpdateChecker::class))->getMethod('apiUrl');

        return (string) $method->invoke($checker, $module_id);
    }

    public function testApiUrlWirdAusDerRepoUrlAbgeleitet(): void
    {
        $this->assertSame(
            'https://api.github.com/repos/user/Repo/contents/modules/examcalc?ref=ilias9',
            $this->apiUrl('https://github.com/user/Repo', 'ilias9', 'examcalc')
        );
    }

    public function testApiUrlIgnoriertAbschliessendenSchraegstrich(): void
    {
        $this->assertSame(
            $this->apiUrl('https://github.com/user/Repo', 'ilias9', 'examcalc'),
            $this->apiUrl('https://github.com/user/Repo/', 'ilias9', 'examcalc')
        );
    }

    public function testBranchnameWirdKodiert(): void
    {
        $this->assertStringEndsWith(
            '?ref=feature%2Ftests',
            $this->apiUrl('https://github.com/user/Repo', 'feature/tests', 'examcalc')
        );
    }

    // -------------------------------------------------------------------------
    // Versions-Parsing aus dem ZIP-Dateinamen
    // -------------------------------------------------------------------------

    #[DataProvider('dateinamen')]
    public function testVersionWirdAusDemDateinamenGelesen(string $filename, ?string $expected): void
    {
        $regex = (string) (new ReflectionClass(ilModuleUpdateChecker::class))->getConstant('VERSION_REGEX');

        $matched = preg_match($regex, $filename, $m) === 1;

        $this->assertSame($expected !== null, $matched, "Unerwartetes Ergebnis für '$filename'.");
        if ($expected !== null) {
            $this->assertSame($expected, $m[1]);
        }
    }

    public static function dateinamen(): array
    {
        return [
            'dreistellig'        => ['ExamCalc_v1.2.0.zip', '1.2.0'],
            'zweistellig'        => ['ExamCalc_v1.2.zip', '1.2'],
            'großes V'           => ['ExamCalc_V2.0.1.zip', '2.0.1'],
            'zweistellige Teile' => ['ExamLogger_v1.10.0.zip', '1.10.0'],
            'ohne Version'       => ['ExamCalc.zip', null],
            'falsche Endung'     => ['ExamCalc_v1.2.0.zip.bak', null],
            'kein Unterstrich'   => ['ExamCalcv1.2.0.zip', null],
            'Buchstabe drin'     => ['ExamCalc_v1.2.0b.zip', null],
        ];
    }

    /**
     * version_compare() vergleicht numerisch — 1.10.0 ist neuer als 1.9.0. Der
     * Test hält fest, dass der Vergleich nicht lexikografisch passieren darf.
     */
    public function testHoehereVersionGewinnt(): void
    {
        $this->assertTrue(version_compare('1.10.0', '1.9.0', '>'));
        $this->assertTrue(version_compare('1.3.2', '1.0.1', '>'));
        $this->assertFalse(version_compare('1.2.0', '1.2.0', '>'));
    }

    /**
     * Built-in-Module haben kein Repository — checkAll() darf sie unverändert
     * durchreichen und dabei nicht ins Netz gehen.
     */
    public function testBuiltinModuleWerdenNichtGeprueft(): void
    {
        $checker = new ilModuleUpdateChecker('https://github.com/user/Repo', 'ilias9');

        $result = $checker->checkAll([
            ['id' => 'eingebaut', 'version' => '1.0.0', 'builtin' => true],
        ]);

        $this->assertSame([['id' => 'eingebaut', 'version' => '1.0.0', 'builtin' => true]], $result);
    }
}
