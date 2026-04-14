<?php

require_once __DIR__ . '/../Contracts/ilExamModuleInterface.php';

/**
 * Entdeckt und verwaltet alle verfügbaren Module (built-in + uploaded).
 *
 * Built-in Module:  classes/Exam[*]/module.json  (im Git-Repo, read-only)
 * Uploaded Module:  modules/[*]/module.json      (www-data-writable)
 *
 * Aktiv/Inaktiv-Status wird in ilSetting gespeichert (key: module_active_<id>).
 * Built-in Module sind standardmäßig aktiv, uploaded Module standardmäßig inaktiv
 * bis der Admin sie explizit aktiviert.
 */
class ilModuleRegistry
{
    private const SETTINGS_NS = 'extendedtestsettings';

    private string $plugin_path;
    private ilSetting $settings;

    /** @var array[] Alle entdeckten module.json-Inhalte, key = module-id */
    private array $discovered = [];

    public function __construct(string $plugin_path)
    {
        $this->plugin_path = rtrim($plugin_path, '/');
        $this->settings    = new ilSetting(self::SETTINGS_NS);
        $this->discover();
    }

    // -------------------------------------------------------------------------
    // Öffentliche API
    // -------------------------------------------------------------------------

    /**
     * Gibt Infos zu allen entdeckten Modulen zurück (für ConfigGUI-Anzeige).
     * Beinhaltet: module.json-Daten + 'active' + 'builtin' + 'path'.
     *
     * @return array[]
     */
    public function getAllModuleInfos(): array
    {
        return array_values($this->discovered);
    }

    /**
     * Gibt Handler-Instanzen aller aktuell aktiven Module zurück.
     * PHP lädt den Handler-Code nur wenn das Modul aktiv ist.
     *
     * @return ilExamModuleInterface[]
     */
    public function getActiveHandlers(): array
    {
        $handlers = [];

        foreach ($this->discovered as $id => $info) {
            if (!$info['active']) {
                continue;
            }

            $handler = $this->loadHandler($info);
            if ($handler !== null) {
                $handlers[$id] = $handler;
            }
        }

        return $handlers;
    }

    /**
     * Gibt einen einzelnen aktiven Handler zurück oder null.
     */
    public function getHandler(string $module_id): ?ilExamModuleInterface
    {
        $info = $this->discovered[$module_id] ?? null;

        if ($info === null || !$info['active']) {
            return null;
        }

        return $this->loadHandler($info);
    }

    public function isModuleActive(string $module_id): bool
    {
        return $this->discovered[$module_id]['active'] ?? false;
    }

    /**
     * Aktiviert oder deaktiviert ein Modul global (kein PHP-Code wird geladen wenn inaktiv).
     */
    public function setModuleActive(string $module_id, bool $active): void
    {
        if (!isset($this->discovered[$module_id])) {
            return;
        }

        $this->settings->set('module_active_' . $module_id, $active ? '1' : '0');
        $this->discovered[$module_id]['active'] = $active;
    }

    /**
     * Gibt die Tabellennamen eines Moduls zurück (aus module.json).
     */
    public function getModuleTables(string $module_id): array
    {
        return $this->discovered[$module_id]['tables'] ?? [];
    }

    /**
     * Gibt alle Tabellennamen aller entdeckten Module zurück.
     */
    public function getAllTables(): array
    {
        $tables = [];
        foreach ($this->discovered as $info) {
            foreach ($info['tables'] ?? [] as $table) {
                $tables[] = $table;
            }
        }
        return array_unique($tables);
    }

    /**
     * Prüft ob ein Modul-Ordner vorhanden ist (entdeckt wurde).
     */
    public function moduleExists(string $module_id): bool
    {
        return isset($this->discovered[$module_id]);
    }

    // -------------------------------------------------------------------------
    // Discovery
    // -------------------------------------------------------------------------

    private function discover(): void
    {
        $this->discovered = [];

        // 1. Built-in Module: classes/Exam*/module.json
        foreach (glob($this->plugin_path . '/classes/Exam*/module.json') ?: [] as $json_file) {
            $this->registerFromJson($json_file, builtin: true);
        }

        // 2. Uploaded/installed Module: modules/*/module.json
        foreach (glob($this->plugin_path . '/modules/*/module.json') ?: [] as $json_file) {
            $this->registerFromJson($json_file, builtin: false);
        }
    }

    private function registerFromJson(string $json_file, bool $builtin): void
    {
        $raw = @file_get_contents($json_file);
        if ($raw === false) {
            return;
        }

        $info = json_decode($raw, true);
        if (!is_array($info) || empty($info['id']) || empty($info['handler_file'])) {
            error_log("[ModuleRegistry] Ungültige module.json: $json_file");
            return;
        }

        $id = $info['id'];

        // Tabellennamen validieren (SQL-Injection-Schutz)
        foreach ($info['tables'] ?? [] as $table) {
            if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $table)) {
                error_log("[ModuleRegistry] Ungültiger Tabellenname '$table' in $json_file -- Modul wird übersprungen");
                return;
            }
        }

        // Standard-Aktivierung: built-in = an, uploaded = aus (bis Admin aktiviert)
        $default_active = $builtin ? '1' : '0';
        $active         = (bool) $this->settings->get('module_active_' . $id, $default_active);

        $module_dir = dirname($json_file);

        $this->discovered[$id] = array_merge($info, [
            'active'     => $active,
            'builtin'    => $builtin,
            'path'       => $module_dir,
            'tables'     => $info['tables'] ?? [],
        ]);
    }

    // -------------------------------------------------------------------------
    // Handler laden (PHP-Code nur hier)
    // -------------------------------------------------------------------------

    private function loadHandler(array $info): ?ilExamModuleInterface
    {
        $handler_file = $info['path'] . '/' . $info['handler_file'];

        if (!file_exists($handler_file)) {
            error_log("[ModuleRegistry] Handler nicht gefunden: $handler_file");
            return null;
        }

        // Abhängigkeiten laden (Config-Klassen etc.), falls angegeben
        foreach ($info['require_files'] ?? [] as $rel_path) {
            $dep_file = $info['path'] . '/' . $rel_path;
            if (file_exists($dep_file)) {
                require_once $dep_file;
            }
        }

        require_once $handler_file;

        $class = $info['handler_class'] ?? '';
        if (!class_exists($class)) {
            error_log("[ModuleRegistry] Handler-Klasse '$class' nicht gefunden in $handler_file");
            return null;
        }

        try {
            return new $class($this->plugin_path);
        } catch (Throwable $e) {
            error_log("[ModuleRegistry] Fehler beim Instanzieren von $class: " . $e->getMessage());
            return null;
        }
    }
}
