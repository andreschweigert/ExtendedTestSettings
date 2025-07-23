<?php

class ilExtendedTestSettingsPlugin extends ilUserInterfaceHookPlugin
{
    public function getPluginName(): string
    {
        return "ExtendedTestSettings";
    }

    public function hasConfiguration(): bool
    {
        return false; // Keine globale Konfiguration - alles per Test
    }

    public function getUIHookClass(): string
    {
        return ilExtendedTestSettingsUIHookGUI::class;
    }

    /**
     * Wird beim Aktivieren des Plugins ausgeführt
     */
    protected function afterActivation(): void
    {
        $this->createTables();
        $this->logActivation();
    }

    /**
     * Wird beim Deinstallieren ausgeführt
     */
    protected function beforeUninstall(): bool
    {
        // Sicherheitsabfrage - nur wenn explizit gewünscht
        if (!isset($_POST['confirm_uninstall']) || $_POST['confirm_uninstall'] !== '1') {
            // Warnung anzeigen
            global $DIC;
            $tpl = $DIC->ui()->mainTemplate();
            $tpl->setOnScreenMessage(
                'info',
                'WARNUNG: Das Deinstallieren löscht alle gespeicherten Einstellungen für alle Tests! ' .
                'Um fortzufahren, aktivieren Sie die Checkbox "Daten löschen" im Deinstallations-Dialog.'
            );
            return false;
        }

        $this->dropTables();
        return true;
    }

    /**
     * Erstellt die Plugin-Tabellen für alle Module
     */
    private function createTables(): void
    {
        global $DIC;
        $db = $DIC->database();

        // ExamCalc Tabelle
        if (!$db->tableExists('examcalc_settings')) {
            $fields = [
                'test_id' => [
                    'type' => 'integer',
                    'length' => 4,
                    'notnull' => true
                ],
                'enabled' => [
                    'type' => 'integer',
                    'length' => 1,
                    'notnull' => true,
                    'default' => 0
                ],
                'created_at' => [
                    'type' => 'timestamp',
                    'notnull' => false
                ],
                'updated_at' => [
                    'type' => 'timestamp',
                    'notnull' => false
                ]
            ];

            $db->createTable('examcalc_settings', $fields);
            $db->addPrimaryKey('examcalc_settings', ['test_id']);
        }

        // ExamGuard Tabelle
        if (!$db->tableExists('examguard_settings')) {
            $fields = [
                'test_id' => [
                    'type' => 'integer',
                    'length' => 4,
                    'notnull' => true
                ],
                'enabled' => [
                    'type' => 'integer',
                    'length' => 1,
                    'notnull' => true,
                    'default' => 0
                ],
                'exit_prevention' => [
                    'type' => 'integer',
                    'length' => 1,
                    'notnull' => true,
                    'default' => 1
                ],
                'created_at' => [
                    'type' => 'timestamp',
                    'notnull' => false
                ],
                'updated_at' => [
                    'type' => 'timestamp',
                    'notnull' => false
                ]
            ];

            $db->createTable('examguard_settings', $fields);
            $db->addPrimaryKey('examguard_settings', ['test_id']);
        }

        // ExamSymbols Tabelle
        if (!$db->tableExists('examsymbols_settings')) {
            $fields = [
                'test_id' => [
                    'type' => 'integer',
                    'length' => 4,
                    'notnull' => true
                ],
                'enabled' => [
                    'type' => 'integer',
                    'length' => 1,
                    'notnull' => true,
                    'default' => 0
                ],
                'template' => [
                    'type' => 'text',
                    'length' => 255,
                    'notnull' => false,
                    'default' => 'math'
                ],
                'created_at' => [
                    'type' => 'timestamp',
                    'notnull' => false
                ],
                'updated_at' => [
                    'type' => 'timestamp',
                    'notnull' => false
                ]
            ];

            $db->createTable('examsymbols_settings', $fields);
            $db->addPrimaryKey('examsymbols_settings', ['test_id']);
        }
        // ExamAlert Tabelle
        if (!$db->tableExists('examalert_settings')) {
            $fields = [
                'test_id' => [
                    'type' => 'integer',
                    'length' => 4,
                    'notnull' => true
                ],
                'enabled' => [
                    'type' => 'integer',
                    'length' => 1,
                    'notnull' => true,
                    'default' => 0
                ],
                'alert_time' => [
                    'type' => 'integer',
                    'length' => 4,
                    'notnull' => true,
                    'default' => 30
                ],
                'alert_message' => [
                    'type' => 'text',
                    'length' => 1000,
                    'notnull' => false,
                    'default' => 'Achtung: Der Test endet bald!'
                ],
                'created_at' => [
                    'type' => 'timestamp',
                    'notnull' => false
                ],
                'updated_at' => [
                    'type' => 'timestamp',
                    'notnull' => false
                ]
            ];

            $db->createTable('examalert_settings', $fields);
            $db->addPrimaryKey('examalert_settings', ['test_id']);
        }
    }

    /**
     * Löscht die Plugin-Tabellen
     */
    private function dropTables(): void
    {
        global $DIC;
        $db = $DIC->database();

        $tables = ['examcalc_settings', 'examguard_settings', 'examsymbols_settings', 'examalert_settings'];

        foreach ($tables as $table) {
            if ($db->tableExists($table)) {
                $db->dropTable($table);
            }
        }
    }

    /**
     * Aktivierung protokollieren
     */
    private function logActivation(): void
    {
        error_log("[ExtendedTestSettings] Plugin erfolgreich aktiviert - Tabellen erstellt");

        // Optional: Statistik sammeln
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
            'save_settings' => 'Einstellungen speichern',
            'settings_saved' => 'Einstellungen gespeichert',
            'error_occurred' => 'Ein Fehler ist aufgetreten'
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
     */
    public function cleanupOrphanedEntries(): int
    {
        global $DIC;
        $db = $DIC->database();
        $cleaned = 0;

        try {
            $tables = ['examcalc_settings', 'examsymbols_settings', 'examguard_settings'];

            foreach ($tables as $table) {
                if (!$db->tableExists($table))
                    continue;

                // Finde verwaiste Einträge
                $query = "
                    DELETE FROM $table 
                    WHERE test_id NOT IN (
                        SELECT obj_id FROM object_data WHERE type = 'tst'
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
}