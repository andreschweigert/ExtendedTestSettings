<?php

declare(strict_types=1);

/**
 * Bootstrap der Kern-Testsuite (Schicht A).
 *
 * Die getesteten Kern-Klassen hängen nur an wenigen ILIAS-Bausteinen. Statt einen
 * kompletten ILIAS-Bootstrap zu fahren (Datenbank, Session, $DIC), werden diese
 * Bausteine hier als minimale Stubs definiert; danach lassen sich die
 * Plugin-Klassen ganz normal per require_once laden.
 *
 * Bewusst NICHT enthalten: eval(), Datenbank, Webserver, Netzzugriff.
 *
 * Was die Tests brauchen:
 *   ilSetting                  In-Memory-Store (Registry-Aktivstatus)
 *   $DIC                       Minimalcontainer mit user()/database()/language()
 *   ilUserInterfaceHookPlugin  Elternklasse der Plugin-Hauptklasse (für C-12)
 *   ilPluginConfigGUI          Elternklasse der ConfigGUI (für isAllowedDownloadUrl)
 *   ilCtrlSecurityInterface    von der ConfigGUI implementiert
 */

// error_log() der Plugin-Klassen ist im Testlauf reines Rauschen (die Fehlerpfade
// werden absichtlich provoziert). Die Assertions prüfen das Verhalten, nicht das Log.
// display_errors bleibt an, damit echte PHP-Fehler trotzdem sichtbar sind.
ini_set('error_log', '/dev/null');
ini_set('display_errors', '1');

define('ETS_PLUGIN_ROOT', dirname(__DIR__));

// -----------------------------------------------------------------------------
// ILIAS-Stubs
// -----------------------------------------------------------------------------

/**
 * In-Memory-Ersatz für ilSetting. Der Store ist statisch und nach Namespace
 * getrennt — genau wie die settings-Tabelle bleiben Werte über mehrere
 * Registry-Instanzen hinweg erhalten. ilEtsTestCase räumt ihn je Test auf.
 */
class ilSetting
{
    /** @var array<string,array<string,string>> */
    private static array $store = [];

    private string $module;

    public function __construct(string $a_module = 'common')
    {
        $this->module = $a_module;
    }

    public function get(string $a_keyword, ?string $a_default_value = null): ?string
    {
        return self::$store[$this->module][$a_keyword] ?? $a_default_value;
    }

    public function set(string $a_key, string $a_val): void
    {
        self::$store[$this->module][$a_key] = $a_val;
    }

    public function delete(string $a_key): void
    {
        unset(self::$store[$this->module][$a_key]);
    }

    /** Nur für Tests: kompletten Store leeren. */
    public static function _resetTestStore(): void
    {
        self::$store = [];
    }
}

/** Minimaler Ersatz für den ILIAS-Benutzer (nur für die Audit-Log-Zeilen des Installers). */
class ilEtsUserStub
{
    public function getId(): int
    {
        return 6;
    }

    public function getLogin(): string
    {
        return 'phpunit';
    }
}

/**
 * Minimaler Datenbank-Stub. Die Fixture-Skripte sql/install.php und
 * sql/uninstall.php bekommen ihn als $db übergeben und protokollieren darüber,
 * dass sie gelaufen sind.
 */
class ilEtsDatabaseStub
{
    /** @var string[] */
    public array $calls = [];

    /** @var array<string,bool> */
    private array $tables = [];

    public function tableExists(string $table): bool
    {
        return $this->tables[$table] ?? false;
    }

    public function createTable(string $table, array $fields = [], bool $drop = false): void
    {
        $this->calls[]        = 'createTable:' . $table;
        $this->tables[$table] = true;
    }

    public function dropTable(string $table, bool $error_if_not_existing = true): void
    {
        $this->calls[] = 'dropTable:' . $table;
        unset($this->tables[$table]);
    }

    public function manipulate(string $query): int
    {
        $this->calls[] = 'manipulate';
        return 0;
    }
}

/** Minimaler $DIC — nur die Zugriffe, die der getestete Kern tatsächlich macht. */
class ilEtsDicStub implements ArrayAccess
{
    private ilEtsUserStub $user;
    private ilEtsDatabaseStub $db;

    public function __construct()
    {
        $this->user = new ilEtsUserStub();
        $this->db   = new ilEtsDatabaseStub();
    }

    public function user(): ilEtsUserStub
    {
        return $this->user;
    }

    public function database(): ilEtsDatabaseStub
    {
        return $this->db;
    }

    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, ['user', 'ilDB'], true);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'user'  => $this->user,
            'ilDB'  => $this->db,
            default => null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
    }

    public function offsetUnset(mixed $offset): void
    {
    }
}

/** Elternklasse der Plugin-Hauptklasse. Absichtlich leer bis auf die genutzten Methoden. */
class ilUserInterfaceHookPlugin
{
    public function getDirectory(): string
    {
        return ETS_PLUGIN_ROOT;
    }

    public function txt(string $key): string
    {
        return '-' . $key . '-';
    }
}

/** Elternklasse der ConfigGUI. */
class ilPluginConfigGUI
{
}

/** Von der ConfigGUI implementiert; die Methoden deklariert sie selbst. */
interface ilCtrlSecurityInterface
{
}

// -----------------------------------------------------------------------------
// Getestete Plugin-Klassen
// -----------------------------------------------------------------------------

require_once ETS_PLUGIN_ROOT . '/classes/Module/ilModuleManifest.php';
require_once ETS_PLUGIN_ROOT . '/classes/Module/ilModuleInstaller.php';
require_once ETS_PLUGIN_ROOT . '/classes/Module/ilModuleRegistry.php';
require_once ETS_PLUGIN_ROOT . '/classes/Module/ilModuleUpdateChecker.php';
require_once ETS_PLUGIN_ROOT . '/classes/Module/ilModuleLanguage.php';

// -----------------------------------------------------------------------------
// Test-Hilfsklassen
// -----------------------------------------------------------------------------

require_once __DIR__ . '/support/ilEtsTestCase.php';
require_once __DIR__ . '/support/ilEtsZipBuilder.php';
require_once __DIR__ . '/support/ilEtsModuleFixture.php';
