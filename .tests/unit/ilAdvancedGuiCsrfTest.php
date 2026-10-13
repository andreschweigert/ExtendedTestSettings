<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

require_once ETS_PLUGIN_ROOT . '/classes/class.ilExtendedTestSettingsPlugin.php';
require_once ETS_PLUGIN_ROOT . '/classes/class.ilExtendedTestSettingsAdvancedGUI.php';

final class ilEtsSecurityTemplate implements ilGlobalTemplateInterface
{
    public array $messages = [];

    public function setOnScreenMessage(string $type, string $message): void
    {
        $this->messages[] = [$type, $message];
    }
}

final class ilAdvancedGuiCsrfTest extends ilEtsTestCase
{
    #[DataProvider('ungueltigeRequests')]
    public function testUngueltigerRequestBrichtVorJederSpeicherungAb(string $method, mixed $token): void
    {
        $old_get = $_GET;
        $old_server = $_SERVER;
        try {
            $_SERVER['REQUEST_METHOD'] = $method;
            $_GET = $token === null ? [] : ['rtoken' => $token];
            ilCtrlTokenRepository::$test_token = 'session-token';

            $ref = new ReflectionClass(ilExtendedTestSettingsAdvancedGUI::class);
            $gui = $ref->newInstanceWithoutConstructor();
            $ctrl = new ilCtrl();
            $tpl = new ilEtsSecurityTemplate();
            $plugin = (new ReflectionClass(ilExtendedTestSettingsPlugin::class))->newInstanceWithoutConstructor();
            $ref->getProperty('ctrl')->setValue($gui, $ctrl);
            $ref->getProperty('tpl')->setValue($gui, $tpl);
            $ref->getProperty('plugin')->setValue($gui, $plugin);

            $gui->saveSettings();

            $this->assertSame(['show'], $ctrl->redirects);
            $this->assertSame('failure', $tpl->messages[0][0]);
            $this->assertSame([], $this->db()->calls);
        } finally {
            $_GET = $old_get;
            $_SERVER = $old_server;
            ilCtrlTokenRepository::$test_token = '';
        }
    }

    public static function ungueltigeRequests(): array
    {
        return [
            'GET mit gültigem Token' => ['GET', 'session-token'],
            'POST ohne Token' => ['POST', null],
            'POST mit leerem Token' => ['POST', ''],
            'POST mit fremdem Token' => ['POST', 'other-session-token'],
            'POST mit Array-Token' => ['POST', ['session-token']],
        ];
    }

    public function testPostMitSessionTokenPassiertDieRequestPruefung(): void
    {
        $old_get = $_GET;
        $old_server = $_SERVER;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_GET = ['rtoken' => 'session-token'];
            ilCtrlTokenRepository::$test_token = 'session-token';
            $ref = new ReflectionClass(ilExtendedTestSettingsAdvancedGUI::class);
            $gui = $ref->newInstanceWithoutConstructor();

            $this->assertTrue($ref->getMethod('isAuthorizedSaveRequest')->invoke($gui));
            $this->assertSame(['saveSettings'], $gui->getUnsafeGetCommands());
            $this->assertSame([], $gui->getSafePostCommands());
        } finally {
            $_GET = $old_get;
            $_SERVER = $old_server;
            ilCtrlTokenRepository::$test_token = '';
        }
    }
}
