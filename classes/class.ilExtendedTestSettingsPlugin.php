<?php
/**
 * SPDX-License-Identifier: AGPL-3.0-only
 * Original authors: André Schweigert and Cornel Musielak.
 * See LICENSE and NOTICE for license and attribution terms (AGPLv3, section 7(b)).
 */


class ilExtendedTestSettingsPlugin extends ilUserInterfaceHookPlugin
{
    public const PLUGIN_ID = 'extendedtestsettings';

    private ?ilModuleRegistry $registry = null;

    /**
     * Liefert die Plugin-Instanz für GUI-Klassen, die nicht von einer
     * Plugin-Basisklasse erben (z. B. AdvancedGUI, ModuleProxyGUI) und daher
     * keinen direkten Zugriff über getPluginObject() haben. So lassen sich
     * Sprachvariablen einheitlich über ->txt('key') auflösen.
     */
    public static function getInstance(): ilExtendedTestSettingsPlugin
    {
        global $DIC;

        /** @var ilComponentFactory $component_factory */
        $component_factory = $DIC['component.factory'];

        /** @var ilExtendedTestSettingsPlugin $plugin */
        $plugin = $component_factory->getPlugin(self::PLUGIN_ID);

        return $plugin;
    }

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
            $this->registry = ilModuleRegistry::forPlugin($this);
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

    /**
     * Vom Kern selbst angelegte Tabellen (sql/dbupdate.php).
     *
     * Diese Liste ist unabhängig davon, ob das jeweilige Modul bei der
     * Deinstallation noch installiert ist — die Registry kennt nur Tabellen aus
     * aktuell vorhandenen module.json-Dateien. Ohne diese Liste blieben die in
     * den Migrationen 1-5 und 7 angelegten Tabellen samt Daten zurück.
     *
     * WICHTIG (Regel R14): Wird in sql/dbupdate.php eine Tabelle angelegt, gehört
     * sie im selben Commit hier hinein.
     */
    private const CORE_TABLES = [
        'examcalc_settings',      // dbupdate <#1>
        'examguard_settings',     // dbupdate <#2>
        'examsymbols_settings',   // dbupdate <#3>
        'examalert_settings',     // dbupdate <#4>
        'extendedtest_users',     // dbupdate <#6>
        'etest_hosts',            // dbupdate <#7>
    ];

    protected function beforeUninstall(): bool
    {
        global $DIC;
        error_log("[ExtendedTestSettings] Deinstallation gestartet von: " . $DIC->user()->getLogin());

        [$dropped, $failed] = $this->dropTables();

        if (empty($failed)) {
            error_log('[ExtendedTestSettings] Deinstallation abgeschlossen, ' . count($dropped) . ' Tabellen gelöscht'
                . ($dropped ? ': ' . implode(', ', $dropped) : ''));
            return true;
        }

        // Wahrheitsgemäß melden statt pauschal Erfolg zu loggen
        error_log('[ExtendedTestSettings] Deinstallation UNVOLLSTÄNDIG — nicht gelöscht: ' . implode(', ', $failed));

        return true;
    }

    /**
     * Löscht alle Tabellen des Plugins: die vom Kern angelegten (feste Liste)
     * plus alle in Modul-Manifesten deklarierten.
     *
     * @return array{0: string[], 1: string[]} [gelöscht, fehlgeschlagen]
     */
    private function dropTables(): array
    {
        global $DIC;
        $db = $DIC->database();

        $tables = array_unique(array_merge(
            self::CORE_TABLES,
            $this->getRegistry()->getAllTables()
        ));

        $dropped = [];
        $failed  = [];

        foreach ($tables as $table) {
            // Sicherheitsnetz: Tabellennamen werden zwar validiert, hier wird
            // aber ohne Bindung an die DB-API gearbeitet.
            if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $table)) {
                $failed[] = $table;
                continue;
            }

            try {
                if (!$db->tableExists($table)) {
                    continue;
                }

                $db->dropTable($table);

                // Ergebnis je Tabelle prüfen statt Erfolg zu unterstellen
                if ($db->tableExists($table)) {
                    $failed[] = $table;
                    continue;
                }

                $dropped[] = $table;
                error_log("[ExtendedTestSettings] Tabelle '$table' gelöscht");
            } catch (Throwable $e) {
                error_log("[ExtendedTestSettings] Tabelle '$table' konnte nicht gelöscht werden: " . $e->getMessage());
                $failed[] = $table;
            }
        }

        return [$dropped, $failed];
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
}
