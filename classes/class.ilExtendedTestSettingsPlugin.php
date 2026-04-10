<?php

class ilExtendedTestSettingsPlugin extends ilUserInterfaceHookPlugin
{
    private ?ilModuleRegistry $registry = null;

    public function getPluginName(): string
    {
        return "ExtendedTestSettings";
    }

    public function hasConfiguration(): bool
    {
        return true;
    }

    public function getConfigurationClass(): string
    {
        return ilExtendedTestSettingsConfigGUI::class;
    }

    public function getUIHookClass(): string
    {
        return ilExtendedTestSettingsUIHookGUI::class;
    }

    public function getRegistry(): ilModuleRegistry
    {
        if ($this->registry === null) {
            require_once __DIR__ . '/Module/ilModuleRegistry.php';
            $this->registry = new ilModuleRegistry($this->getDirectory());
        }
        return $this->registry;
    }

    protected function afterActivation(): void
    {
        error_log("[ExtendedTestSettings] Plugin erfolgreich aktiviert");

        global $DIC;
        if ($DIC->offsetExists('user')) {
            error_log("[ExtendedTestSettings] Aktiviert von: " . $DIC->user()->getLogin());
        }
    }

    protected function afterUpdate(): void
    {
        error_log("[ExtendedTestSettings] Plugin erfolgreich aktualisiert");
    }

    protected function beforeUninstall(): bool
    {
        global $DIC;
        error_log("[ExtendedTestSettings] Deinstallation gestartet von: " . $DIC->user()->getLogin());

        $this->dropTables();

        error_log("[ExtendedTestSettings] Alle Tabellen erfolgreich gelöscht");
        return true;
    }

    /**
     * Löscht alle Modul-Tabellen — Liste kommt aus der Registry (nicht hardcodiert)
     */
    private function dropTables(): void
    {
        global $DIC;
        $db = $DIC->database();

        foreach ($this->getRegistry()->getAllTables() as $table) {
            if ($db->tableExists($table)) {
                $db->dropTable($table);
                error_log("[ExtendedTestSettings] Tabelle '$table' gelöscht");
            }
        }

        // Plugin-eigene Tabelle (kein Modul)
        if ($db->tableExists('extendedtest_users')) {
            $db->dropTable('extendedtest_users');
        }
    }

    /**
     * Statistiken über alle Module — generisch über Registry
     */
    public function getStatistics(): array
    {
        global $DIC;
        $db   = $DIC->database();
        $stats = ['total_configured_tests' => 0];

        $enabled_test_ids = [];

        foreach ($this->getRegistry()->getAllModuleInfos() as $info) {
            $id    = $info['id'];
            $count = 0;

            foreach ($info['tables'] ?? [] as $table) {
                if (!$db->tableExists($table)) {
                    continue;
                }

                try {
                    $result = $db->query("SELECT COUNT(*) as cnt FROM $table WHERE enabled = 1");
                    if ($row = $db->fetchAssoc($result)) {
                        $count = (int) $row['cnt'];
                    }

                    // test_ids für Gesamtzahl sammeln
                    $r2 = $db->query("SELECT test_id FROM $table WHERE enabled = 1");
                    while ($row2 = $db->fetchAssoc($r2)) {
                        $enabled_test_ids[] = $row2['test_id'];
                    }
                } catch (Exception $e) {
                    error_log("[ExtendedTestSettings] Statistik-Fehler ($table): " . $e->getMessage());
                }
            }

            $stats[$id . '_enabled_tests'] = $count;
        }

        $stats['total_configured_tests'] = count(array_unique($enabled_test_ids));

        return $stats;
    }

    /**
     * Prüft ob alle Modul-Tabellen existieren
     */
    public function checkDatabaseTables(): array
    {
        global $DIC;
        $db     = $DIC->database();
        $status = [];

        foreach ($this->getRegistry()->getAllTables() as $table) {
            $status[$table] = $db->tableExists($table);
        }

        return $status;
    }

    /**
     * Bereinigt verwaiste Einträge in allen Modul-Tabellen
     */
    public function cleanupOrphanedEntries(): int
    {
        global $DIC;
        $db      = $DIC->database();
        $cleaned = 0;

        foreach ($this->getRegistry()->getAllTables() as $table) {
            if (!$db->tableExists($table)) {
                continue;
            }

            try {
                $affected = $db->manipulate("
                    DELETE FROM $table
                    WHERE test_id NOT IN (
                        SELECT DISTINCT od.obj_id
                        FROM object_data od
                        JOIN object_reference ref ON od.obj_id = ref.obj_id
                        WHERE od.type = 'tst' AND ref.deleted IS NULL
                    )
                ");
                $cleaned += $affected;

                if ($affected > 0) {
                    error_log("[ExtendedTestSettings] $affected verwaiste Einträge aus $table entfernt");
                }
            } catch (Exception $e) {
                error_log("[ExtendedTestSettings] Cleanup-Fehler ($table): " . $e->getMessage());
            }
        }

        return $cleaned;
    }

    /**
     * Fallback-Übersetzungen
     */
    public function txt(string $key): string
    {
        $translations = [
            'advanced_settings'  => 'Erweiterte Einstellungen',
            'plugin_configuration' => 'Plugin-Konfiguration',
            'save_settings'      => 'Einstellungen speichern',
            'settings_saved'     => 'Einstellungen gespeichert',
            'error_occurred'     => 'Ein Fehler ist aufgetreten',
            'tab_visibility'     => 'Tab-Sichtbarkeit',
            'statistics'         => 'Statistiken',
            'general'            => 'Allgemein',
            'examchecker'        => 'ExamChecker',
            'modules'            => 'Module',
        ];

        $translated = parent::txt($key);

        if ($translated === '-' . $key . '-') {
            return $translations[$key] ?? $key;
        }

        return $translated;
    }

    public function getVersion(): string
    {
        return "1.0.0";
    }
}
