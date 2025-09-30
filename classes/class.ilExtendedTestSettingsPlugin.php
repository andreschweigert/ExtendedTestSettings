<?php

class ilExtendedTestSettingsPlugin extends ilUserInterfaceHookPlugin
{
    public function getPluginName(): string
    {
        return "ExtendedTestSettings";
    }

    /**
     * Konfiguration aktivieren
     */
    public function hasConfiguration(): bool
    {
        return true;
    }

    /**
     * Konfigurationsgui zurückgeben
     */
    public function getConfigurationClass(): string
    {
        return ilExtendedTestSettingsConfigGUI::class;
    }

    public function getUIHookClass(): string
    {
        return ilExtendedTestSettingsUIHookGUI::class;
    }

    /**
     * Wird beim Aktivieren des Plugins ausgeführt
     * Tabellen werden automatisch über sql/dbupdate.php erstellt
     */
    protected function afterActivation(): void
    {
        $this->logActivation();
    }

    /**
     * Wird beim Update des Plugins ausgeführt
     * ILIAS führt automatisch die sql/dbupdate.php aus
     */
    protected function afterUpdate(): void
    {
        // Nichts zu tun - ILIAS handled DB-Updates automatisch
        error_log("[ExtendedTestSettings] Plugin erfolgreich aktualisiert");
    }

    /**
     * Wird beim Deinstallieren ausgeführt
     */
    protected function beforeUninstall(): bool
    {   
        global $DIC;
        $user_login = $DIC->user()->getLogin();
        error_log("[ExtendedTestSettings] Deinstallation gestartet von: $user_login");
        
        // Tabellen löschen
        $this->dropTables();
        
        error_log("[ExtendedTestSettings] Alle Tabellen erfolgreich gelöscht");
        return true;
    }

    /**
     * Löscht die Plugin-Tabellen bei Deinstallation
     */
    private function dropTables(): void
    {
        global $DIC;
        $db = $DIC->database();

        $tables = [
            'examcalc_settings', 
            'examguard_settings', 
            'examsymbols_settings', 
            'examalert_settings'
        ];

        foreach ($tables as $table) {
            if ($db->tableExists($table)) {
                $db->dropTable($table);
                error_log("[ExtendedTestSettings] Tabelle '$table' gelöscht");
            }
        }
    }

    /**
     * Aktivierung protokollieren
     */
    private function logActivation(): void
    {
        error_log("[ExtendedTestSettings] Plugin erfolgreich aktiviert");

        global $DIC;
        if ($DIC->offsetExists('user')) {
            $user_login = $DIC->user()->getLogin();
            error_log("[ExtendedTestSettings] Aktiviert von Benutzer: $user_login");
        }
    }

    /**
     * Plugin-Sprachdateien (Fallback wenn keine Sprachdateien vorhanden)
     */
    public function txt(string $key): string
    {
        // Fallback-Übersetzungen
        $translations = [
            'advanced_settings' => 'Erweiterte Einstellungen',
            'plugin_configuration' => 'Plugin-Konfiguration',
            'examcalc_title' => 'Wissenschaftlicher Taschenrechner',
            'examsymbols_title' => 'Sonderzeichen-Palette',
            'examguard_title' => 'Prüfungsschutz',
            'examalert_title' => 'Zeit-Warnung',
            'save_settings' => 'Einstellungen speichern',
            'settings_saved' => 'Einstellungen gespeichert',
            'error_occurred' => 'Ein Fehler ist aufgetreten',
            'tab_visibility' => 'Tab-Sichtbarkeit',
            'statistics' => 'Statistiken',
            'general' => 'Allgemein',
            'examchecker' => 'ExamChecker'
        ];

        // Zuerst versuchen, echte Sprachdatei zu laden
        $translated = parent::txt($key);

        // Fallback wenn keine Übersetzung gefunden
        if ($translated === '-' . $key . '-') {
            return $translations[$key] ?? $key;
        }

        return $translated;
    }

    /**
     * Plugin-Version für Debugging
     */
    public function getVersion(): string
    {
        return "1.0.0";
    }

    /**
     * Gibt Plugin-Statistiken zurück
     */
    public function getStatistics(): array
    {
        global $DIC;
        $db = $DIC->database();

        $stats = [
            'examcalc_enabled_tests' => 0,
            'examsymbols_enabled_tests' => 0,
            'examguard_enabled_tests' => 0,
            'examalert_enabled_tests' => 0,
            'total_configured_tests' => 0
        ];

        try {
            // ExamCalc
            $result = $db->query("SELECT COUNT(*) as cnt FROM examcalc_settings WHERE enabled = 1");
            if ($row = $db->fetchAssoc($result)) {
                $stats['examcalc_enabled_tests'] = (int) $row['cnt'];
            }

            // ExamSymbols  
            $result = $db->query("SELECT COUNT(*) as cnt FROM examsymbols_settings WHERE enabled = 1");
            if ($row = $db->fetchAssoc($result)) {
                $stats['examsymbols_enabled_tests'] = (int) $row['cnt'];
            }

            // ExamGuard
            $result = $db->query("SELECT COUNT(*) as cnt FROM examguard_settings WHERE enabled = 1");
            if ($row = $db->fetchAssoc($result)) {
                $stats['examguard_enabled_tests'] = (int) $row['cnt'];
            }

            // ExamAlert
            $result = $db->query("SELECT COUNT(*) as cnt FROM examalert_settings WHERE enabled = 1");
            if ($row = $db->fetchAssoc($result)) {
                $stats['examalert_enabled_tests'] = (int) $row['cnt'];
            }

            // Totale konfigurierte Tests (mindestens ein Feature aktiviert)
            $result = $db->query("
                SELECT COUNT(DISTINCT test_id) as cnt 
                FROM (
                    SELECT test_id FROM examcalc_settings WHERE enabled = 1
                    UNION 
                    SELECT test_id FROM examsymbols_settings WHERE enabled = 1
                    UNION
                    SELECT test_id FROM examguard_settings WHERE enabled = 1
                    UNION
                    SELECT test_id FROM examalert_settings WHERE enabled = 1
                ) as combined
            ");
            if ($row = $db->fetchAssoc($result)) {
                $stats['total_configured_tests'] = (int) $row['cnt'];
            }

        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler bei Statistik-Abfrage: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Bereinigt verwaiste Einträge (Tests die nicht mehr existieren)
     * Kann von der ConfigGUI aufgerufen werden
     */
    public function cleanupOrphanedEntries(): int
    {
        global $DIC;
        $db = $DIC->database();
        $cleaned = 0;

        try {
            $tables = ['examcalc_settings', 'examsymbols_settings', 'examguard_settings', 'examalert_settings'];

            foreach ($tables as $table) {
                if (!$db->tableExists($table)) {
                    continue;
                }

                // Finde verwaiste Einträge
                $query = "
                    DELETE FROM $table 
                    WHERE test_id NOT IN (
                        SELECT DISTINCT od.obj_id 
                        FROM object_data od
                        JOIN object_reference ref ON od.obj_id = ref.obj_id 
                        WHERE od.type = 'tst' AND ref.deleted IS NULL
                    )
                ";

                $affected = $db->manipulate($query);
                $cleaned += $affected;

                if ($affected > 0) {
                    error_log("[ExtendedTestSettings] $affected verwaiste Einträge aus $table entfernt");
                }
            }

        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler bei Cleanup: " . $e->getMessage());
        }

        return $cleaned;
    }

    /**
     * Prüft ob alle benötigten Tabellen existieren
     * Hilfsmethode für Debugging
     */
    public function checkDatabaseTables(): array
    {
        global $DIC;
        $db = $DIC->database();
        
        $tables = [
            'examcalc_settings', 
            'examguard_settings', 
            'examsymbols_settings', 
            'examalert_settings'
        ];
        
        $status = [];
        
        foreach ($tables as $table) {
            $status[$table] = $db->tableExists($table);
        }
        
        return $status;
    }
}