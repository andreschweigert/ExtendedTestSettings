<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

require_once ETS_PLUGIN_ROOT . '/classes/class.ilExtendedTestSettingsModuleProxyGUI.php';
require_once __DIR__ . '/../support/ilEtsResourceAccessFixtures.php';

final class ilResourceSheetAccessTest extends ilEtsTestCase
{
    private ilEtsResourceDic $dic;
    private ilObjTest $test;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dic = new ilEtsResourceDic();
        $this->test = new ilObjTest();
        ilObjectFactory::$test_object = $this->test;
        ilObjectFactory::$lookups = [];
    }

    protected function tearDown(): void
    {
        ilObjectFactory::$test_object = null;
        ilObjectFactory::$lookups = [];
        parent::tearDown();
    }

    private function allowed(): bool
    {
        $proxy = new ilExtendedTestSettingsModuleProxyGUI();
        return (bool) (new ReflectionMethod($proxy, 'hasActiveTestSession'))->invoke($proxy, $this->dic, 77, 123);
    }

    public function testAktuellerOffenerDurchlaufMitLeserechtIstErlaubt(): void
    {
        $this->assertTrue($this->allowed());
        $this->assertSame([['read', '', 77]], $this->dic->test_access->calls);
        $this->assertSame([123, 6, 0], $this->dic->test_db->queries[0][2]);
        $this->assertSame([77], ilObjectFactory::$lookups);
        $this->assertSame(7, $this->test->timer_checks[0][1]);
    }

    public function testEntzogenesLeserechtVerhindertSchonDieSessionAbfrage(): void
    {
        $this->dic->test_access->read = false;
        $this->assertFalse($this->allowed());
        $this->assertSame([], $this->dic->test_db->queries);
    }

    #[DataProvider('alteSessions')]
    public function testAlteUndUngueltigeSessionsSindGesperrt(?array $changes): void
    {
        $this->dic->test_db->active = $changes === null ? null : array_replace($this->dic->test_db->active, $changes);
        $this->assertFalse($this->allowed());
    }

    public static function alteSessions(): array
    {
        return [
            'keine Session' => [null],
            'abgegeben' => [['submitted' => 1]],
            'nie gestartet' => [['last_started_pass' => null]],
            'abgeschlossener Durchlauf' => [['last_finished_pass' => 0]],
            'nächster Durchlauf noch nicht gestartet' => [['tries' => 1, 'last_started_pass' => 0]],
            'keine Startzeit' => [['pass_started' => null]],
            'ungültiges Datum' => [['pass_started' => '2026-02-30 12:00:00']],
            'zukünftige Startzeit' => [['pass_started' => '2099-01-01 12:00:00']],
            'ungültige active_id' => [['active_id' => 0]],
        ];
    }

    #[DataProvider('sperren')]
    public function testAktuelleTestsperrenWerdenBeruecksichtigt(string $property, mixed $value): void
    {
        $this->test->$property = $value;
        $this->assertFalse($this->allowed());
    }

    public static function sperren(): array
    {
        return ['offline' => ['online', false], 'noch nicht begonnen' => ['start_reached', false],
            'Prüfungsende' => ['end_reached', true], 'falsches Testobjekt' => ['id', 456]];
    }

    public function testAbgelaufeneBearbeitungszeitIstGesperrt(): void
    {
        $this->dic->test_db->active['pass_started'] = date('Y-m-d H:i:s', time() - 900);
        $this->assertFalse($this->allowed());
    }

    public function testIndividuelleZeitverlaengerungBleibtWirksam(): void
    {
        $this->dic->test_db->active['pass_started'] = date('Y-m-d H:i:s', time() - 650);
        $this->test->extra_seconds = 120;
        $this->assertTrue($this->allowed());
    }

    public function testBearbeitungszeitOhneResetBeginntBeimErstenDurchlauf(): void
    {
        $this->dic->test_db->active['tries'] = 1;
        $this->dic->test_db->active['last_started_pass'] = 1;
        $this->test->reset_processing = false;
        $this->dic->test_db->first_pass_started = date('Y-m-d H:i:s', time() - 900);
        $this->assertFalse($this->allowed());
        $this->assertSame([7, 0], $this->dic->test_db->queries[1][2]);
    }

    public function testResetVerwendetDieZeitDesAktuellenDurchlaufs(): void
    {
        $this->dic->test_db->active['tries'] = 1;
        $this->dic->test_db->active['last_started_pass'] = 1;
        $this->dic->test_db->first_pass_started = date('Y-m-d H:i:s', time() - 900);
        $this->assertTrue($this->allowed());
        $this->assertCount(1, $this->dic->test_db->queries);
    }

    public function testUnbegrenzterTestVerlangtKeinenAltenTimer(): void
    {
        $this->dic->test_db->active['tries'] = 1;
        $this->dic->test_db->active['last_started_pass'] = 1;
        $this->test->processing_enabled = false;
        $this->test->reset_processing = false;
        $this->dic->test_db->first_pass_started = null;
        $this->assertTrue($this->allowed());
    }

    public function testDatenbankfehlerFuehrtZuKeinemZugriff(): void
    {
        $this->dic->test_db->fail = true;
        $this->assertFalse($this->allowed());
    }
}
