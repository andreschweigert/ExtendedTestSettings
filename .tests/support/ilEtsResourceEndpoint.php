<?php

declare(strict_types=1);

/** Router nur für isolierte HTTP-Tests mit explizitem Temp-Verzeichnis. */
$fixture_root = getenv('ETS_RESOURCE_FIXTURE_DIR');
if (PHP_SAPI !== 'cli-server' || !$fixture_root || !is_dir($fixture_root)) {
    http_response_code(403);
    exit;
}
define('CLIENT_DATA_DIR', $fixture_root);
$mode = $_GET['scenario'] ?? 'allowed';

class ilUserInterfaceHookPlugin {}
interface ilGlobalTemplateInterface {}
class ilSetting
{
    public function __construct(string $namespace) {}
    public function get(string $key, ?string $default = null): ?string
    {
        global $mode;
        return str_starts_with($key, 'module_active_')
            ? (in_array($mode, ['disabled', 'teacher_disabled'], true) ? '0' : '1') : $default;
    }
}

$plugin_root = dirname(__DIR__, 2);
require_once $plugin_root . '/classes/class.ilExtendedTestSettingsPlugin.php';
require_once $plugin_root . '/classes/Module/ilModuleRegistry.php';
require_once __DIR__ . '/ilEtsResourceAccessFixtures.php';
require_once $plugin_root . '/classes/class.ilExtendedTestSettingsModuleProxyGUI.php';

class ilEtsEndpointPlugin extends ilExtendedTestSettingsPlugin
{
    public function __construct(private ilModuleRegistry $fixture_registry) {}
    public function getRegistry(): ilModuleRegistry { return $this->fixture_registry; }
}

class ilEtsEndpointDic extends ilEtsResourceDic implements ArrayAccess
{
    public ilEtsEndpointPlugin $plugin;
    public function offsetExists(mixed $offset): bool { return $offset === 'component.factory'; }
    public function offsetGet(mixed $offset): mixed
    {
        return new class($this->plugin) {
            public function __construct(private ilEtsEndpointPlugin $plugin) {}
            public function getPlugin(string $id): ilEtsEndpointPlugin { return $this->plugin; }
        };
    }
    public function offsetSet(mixed $offset, mixed $value): void {}
    public function offsetUnset(mixed $offset): void {}
}

$DIC = new ilEtsEndpointDic();
$fixture_plugin = $fixture_root . '/' . ($mode === 'missing' ? 'empty' : ($mode === 'ambiguous' ? 'ambiguous' : 'plugin'));
$DIC->plugin = new ilEtsEndpointPlugin(new ilModuleRegistry($fixture_plugin));
$test = new ilObjTest();
ilObjectFactory::$test_object = $test;
$rbacsystem = new class(in_array($mode, ['teacher', 'teacher_disabled'], true)) {
    public function __construct(private bool $write) {}
    public function checkAccess(string $permission, int $ref_id): bool { return $this->write; }
};

if ($mode === 'read_revoked') $DIC->test_access->read = false;
if ($mode === 'submitted') $DIC->test_db->active['submitted'] = 1;
if ($mode === 'finished') $DIC->test_db->active['last_finished_pass'] = 0;
if ($mode === 'not_started') $DIC->test_db->active['pass_started'] = null;
if ($mode === 'expired' || $mode === 'extra_time') {
    $DIC->test_db->active['pass_started'] = date('Y-m-d H:i:s', time() - 650);
}
if ($mode === 'extra_time') $test->extra_seconds = 120;
if ($mode === 'end_reached') $test->end_reached = true;
if ($mode === 'offline') $test->online = false;
if ($mode === 'not_test') ilObject::$test_type = 'crs';
if ($mode === 'db_error') $DIC->test_db->fail = true;
if ($mode === 'teacher') {
    $DIC->test_access->read = false;
    $DIC->test_db->active = null;
}
if ($mode === 'html') {
    $DIC->test_db->resource_row = ['stored_name' => '123_' . str_repeat('a', 32) . '.html',
        'filename' => 'fixture.html', 'mimetype' => 'text/html'];
}
if (!in_array($mode, ['allowed', 'extra_time', 'teacher', 'html'], true)) {
    $DIC->test_db->forbid_resource_query = true;
}

(new ilExtendedTestSettingsModuleProxyGUI())->executeCommand();
