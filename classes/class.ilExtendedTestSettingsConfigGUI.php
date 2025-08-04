<?php

/**
 * Plugin Configuration GUI for ExtendedTestSettings
 * 
 * @ilCtrl_isCalledBy ilExtendedTestSettingsConfigGUI: ilObjComponentSettingsGUI
 */
class ilExtendedTestSettingsConfigGUI extends ilPluginConfigGUI
{
    protected ilCtrl $ctrl;
    protected ilGlobalTemplateInterface $tpl;
    protected ilLanguage $lng;
    protected ilTabsGUI $tabs;
    protected ilSetting $settings;
    
    public function __construct()
    {
        global $DIC;
        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC->ui()->mainTemplate();
        $this->lng = $DIC->language();
        $this->tabs = $DIC->tabs();
        
        // Plugin-spezifische Settings
        $this->settings = new ilSetting('extendedtestsettings');
    }

    public function performCommand(string $cmd): void
    {
        $this->setTabs();
        
        switch ($cmd) {
            case 'configure':
            case 'showGeneral':
                $this->showGeneral();
                break;
                
            case 'showStatistics': 
                $this->showStatistics();
                break;
                
            case 'showExamChecker':
                $this->showExamChecker();
                break;
                
            case 'saveExamChecker':
                $this->saveExamChecker();
                break;
                
            case 'cleanupOrphanedEntries':
                $this->cleanupOrphanedEntries();
                break;
                
            default:
                $this->showGeneral();
                break;
        }
    }

    /**
     * Setup Tabs
     */
    private function setTabs(): void
    {
        $this->tabs->addTab(
            'general',
            'Allgemein',
            $this->ctrl->getLinkTarget($this, 'showGeneral')
        );
        
        $this->tabs->addTab(
            'statistics', 
            'Statistiken',
            $this->ctrl->getLinkTarget($this, 'showStatistics')
        );
        
        $this->tabs->addTab(
            'examchecker',
            'ExamChecker',
            $this->ctrl->getLinkTarget($this, 'showExamChecker')
        );
    }

    /**
     * Tab 1: Allgemein (erstmal leer)
     */
    protected function showGeneral(): void
    {
        $this->tabs->activateTab('general');
        $this->tpl->setContent($this->renderGeneralTab());
    }

    private function renderGeneralTab(): string
    {
        return '
        <div style="padding: 2rem;">
            <div style="text-align: center; padding: 3rem; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 0.5rem;">
                <div style="font-size: 3rem; margin-bottom: 1rem; color: #6c757d;">⚙️</div>
                <h3 style="color: #495057; margin-bottom: 1rem;">Allgemeine Einstellungen</h3>
                <p style="color: #6c757d; margin: 0;">
                    Dieser Bereich ist für zukünftige globale Plugin-Einstellungen reserviert.
                </p>
            </div>
        </div>';
    }

    /**
     * Tab 2: Statistiken  
     */
    protected function showStatistics(): void
    {
        $this->tabs->activateTab('statistics');
        $this->tpl->setContent($this->renderStatisticsTab());
    }

    private function renderStatisticsTab(): string
    {
        // Plugin-Statistiken laden (ILIAS 9 kompatibel)
        $stats = $this->getPluginStatistics();

        // Modulstatus prüfen
        $module_status = $this->getModuleStatus();
        
        $stats_html = $this->renderStatisticsCards($stats);
        $modules_html = $this->renderModuleStatusCards($module_status);
        $cleanup_html = $this->renderCleanupSection();

        return "
        <div style='padding: 1.5rem;'>
            <div style='margin-bottom: 2rem;'>
                <h3 style='color: #495057; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;'>
                    📊 Plugin-Statistiken
                </h3>
                $stats_html
            </div>
            
            <div style='margin-bottom: 2rem;'>
                <h3 style='color: #495057; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;'>
                    🔧 Module-Status
                </h3>
                $modules_html
            </div>
            
            <div>
                <h3 style='color: #495057; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;'>
                    🧹 Wartung
                </h3>
                $cleanup_html
            </div>
        </div>";
    }

    /**
     * ILIAS 9 kompatible Plugin-Statistiken
     */
    private function getPluginStatistics(): array
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
            if ($db->tableExists('examcalc_settings')) {
                $result = $db->query("SELECT COUNT(*) as cnt FROM examcalc_settings WHERE enabled = 1");
                if ($row = $db->fetchAssoc($result)) {
                    $stats['examcalc_enabled_tests'] = (int) $row['cnt'];
                }
            }

            // ExamSymbols  
            if ($db->tableExists('examsymbols_settings')) {
                $result = $db->query("SELECT COUNT(*) as cnt FROM examsymbols_settings WHERE enabled = 1");
                if ($row = $db->fetchAssoc($result)) {
                    $stats['examsymbols_enabled_tests'] = (int) $row['cnt'];
                }
            }

            // ExamGuard
            if ($db->tableExists('examguard_settings')) {
                $result = $db->query("SELECT COUNT(*) as cnt FROM examguard_settings WHERE enabled = 1");
                if ($row = $db->fetchAssoc($result)) {
                    $stats['examguard_enabled_tests'] = (int) $row['cnt'];
                }
            }

            // ExamAlert
            if ($db->tableExists('examalert_settings')) {
                $result = $db->query("SELECT COUNT(*) as cnt FROM examalert_settings WHERE enabled = 1");
                if ($row = $db->fetchAssoc($result)) {
                    $stats['examalert_enabled_tests'] = (int) $row['cnt'];
                }
            }

            // Totale konfigurierte Tests (mindestens ein Feature aktiviert)
            $union_queries = [];
            if ($db->tableExists('examcalc_settings')) {
                $union_queries[] = "SELECT test_id FROM examcalc_settings WHERE enabled = 1";
            }
            if ($db->tableExists('examsymbols_settings')) {
                $union_queries[] = "SELECT test_id FROM examsymbols_settings WHERE enabled = 1";
            }
            if ($db->tableExists('examguard_settings')) {
                $union_queries[] = "SELECT test_id FROM examguard_settings WHERE enabled = 1";
            }
            if ($db->tableExists('examalert_settings')) {
                $union_queries[] = "SELECT test_id FROM examalert_settings WHERE enabled = 1";
            }

            if (!empty($union_queries)) {
                $union_query = implode(" UNION ", $union_queries);
                $result = $db->query("SELECT COUNT(DISTINCT test_id) as cnt FROM ($union_query) as combined");
                if ($row = $db->fetchAssoc($result)) {
                    $stats['total_configured_tests'] = (int) $row['cnt'];
                }
            }

        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler bei Statistik-Abfrage: " . $e->getMessage());
        }

        return $stats;
    }

    private function renderStatisticsCards(array $stats): string
    {
        if (empty($stats)) {
            return '<div style="text-align: center; padding: 2rem; background: #f8f9fa; border-radius: 0.5rem; color: #6c757d;">
                Keine Statistiken verfügbar.
            </div>';
        }

        $cards = '';
        $stat_configs = [
            'examcalc_enabled_tests' => ['title' => 'Taschenrechner', 'icon' => '🧮', 'color' => '#007bff'],
            'examsymbols_enabled_tests' => ['title' => 'Sonderzeichen', 'icon' => '📝', 'color' => '#28a745'],
            'examguard_enabled_tests' => ['title' => 'Prüfungsschutz', 'icon' => '🛡️', 'color' => '#dc3545'],
            'examalert_enabled_tests' => ['title' => 'Zeit-Warnung', 'icon' => '⏰', 'color' => '#fd7e14'],
            'total_configured_tests' => ['title' => 'Gesamt konfiguriert', 'icon' => '📋', 'color' => '#6f42c1']
        ];

        foreach ($stat_configs as $key => $config) {
            $value = $stats[$key] ?? 0;
            $cards .= "
            <div style='background: white; border: 1px solid #e9ecef; border-radius: 0.5rem; padding: 1.5rem; text-align: center; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);'>
                <div style='font-size: 2.5rem; margin-bottom: 0.5rem;'>{$config['icon']}</div>
                <div style='font-size: 2rem; font-weight: bold; color: {$config['color']}; margin-bottom: 0.25rem;'>$value</div>
                <div style='color: #6c757d; font-size: 0.9rem;'>{$config['title']}</div>
            </div>";
        }

        return "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;'>$cards</div>";
    }

    private function renderModuleStatusCards(array $module_status): string
    {
        $cards = '';
        
        foreach ($module_status as $module => $data) {
            $status_icon = $data['exists'] ? '✅' : '❌';
            $status_text = $data['exists'] ? 'Verfügbar' : 'Nicht gefunden';
            $status_color = $data['exists'] ? '#28a745' : '#dc3545';
            
            $cards .= "
            <div style='background: white; border: 1px solid #e9ecef; border-radius: 0.5rem; padding: 1rem; display: flex; align-items: center; gap: 1rem;'>
                <div style='font-size: 1.5rem;'>{$data['icon']}</div>
                <div style='flex: 1;'>
                    <div style='font-weight: 500; color: #495057;'>{$data['title']}</div>
                    <div style='font-size: 0.85rem; color: #6c757d;'>{$data['class']}</div>
                </div>
                <div style='display: flex; align-items: center; gap: 0.5rem; color: $status_color; font-weight: 500;'>
                    <span>$status_icon</span>
                    <span>$status_text</span>
                </div>
            </div>";
        }

        return "<div style='display: grid; gap: 0.75rem;'>$cards</div>";
    }

    private function renderCleanupSection(): string
    {
        $cleanup_url = $this->ctrl->getLinkTarget($this, 'cleanupOrphanedEntries');
        
        return "
        <div style='background: #fff3cd; border: 1px solid #ffeeba; border-radius: 0.5rem; padding: 1.5rem;'>
            <div style='display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;'>
                <div style='font-size: 1.5rem;'>🧹</div>
                <div>
                    <div style='font-weight: 500; color: #856404;'>Verwaiste Einträge bereinigen</div>
                    <div style='font-size: 0.9rem; color: #856404;'>Entfernt Einstellungen von Tests, die nicht mehr existieren.</div>
                </div>
            </div>
            <button onclick=\"if(confirm('Wirklich verwaiste Einträge löschen?')) { window.location.href='$cleanup_url'; }\" 
                    style='background: #856404; color: white; border: none; padding: 0.5rem 1rem; border-radius: 0.375rem; cursor: pointer;'>
                Jetzt bereinigen
            </button>
        </div>";
    }

    private function getModuleStatus(): array
    {
        $modules = [
            'examcalc' => [
                'title' => 'Wissenschaftlicher Taschenrechner',
                'icon' => '🧮',
                'class' => 'ilExamCalcHandler'
            ],
            'examsymbols' => [
                'title' => 'Sonderzeichen-Palette',
                'icon' => '📝', 
                'class' => 'ilExamSymbolsHandler'
            ],
            'examguard' => [
                'title' => 'Prüfungsschutz',
                'icon' => '🛡️',
                'class' => 'ilExamGuardHandler'
            ],
            'examalert' => [
                'title' => 'Zeit-Warnung',
                'icon' => '⏰',
                'class' => 'ilExamAlertHandler'
            ],
            'examchecker' => [
                'title' => 'Prüfungs-Checker',
                'icon' => '🔍',
                'class' => 'ilExamCheckerHandler'
            ]
        ];

        $plugin_dir = "./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExtendedTestSettings/classes";
        
        foreach ($modules as $key => &$module) {
            $subdir = ucfirst($key);
            if ($key === 'examchecker') $subdir = 'ExamChecker';
            if ($key === 'examcalc') $subdir = 'ExamCalc';
            if ($key === 'examsymbols') $subdir = 'ExamSymbols';
            if ($key === 'examguard') $subdir = 'ExamGuard';
            if ($key === 'examalert') $subdir = 'ExamAlert';
            
            $handler_file = "$plugin_dir/$subdir/class.{$module['class']}.php";
            $module['exists'] = file_exists($handler_file);
        }

        return $modules;
    }

    /**
     * Tab 3: ExamChecker
     */
    protected function showExamChecker(): void
    {
        $this->tabs->activateTab('examchecker');
        $this->tpl->setContent($this->renderExamCheckerTab());
    }

    private function renderExamCheckerTab(): string
    {
        $form_action = $this->ctrl->getLinkTarget($this, 'saveExamChecker');
        
        // Aktuelle Einstellungen laden
        $praesenz_settings = $this->getExamCheckerSettings('praesenz');
        $fern_settings = $this->getExamCheckerSettings('fern');
        
        $praesenz_html = $this->renderCheckboxGroup('praesenz', $praesenz_settings);
        $fern_html = $this->renderCheckboxGroup('fern', $fern_settings);

        return "
        <div style='padding: 1.5rem;'>
            <div style='margin-bottom: 2rem; padding: 1rem; background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 0.5rem;'>
                <div style='display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;'>
                    <span style='font-size: 1.25rem;'>🔍</span>
                    <strong style='color: #0056b3;'>ExamChecker-Vorgaben konfigurieren</strong>
                </div>
                <p style='margin: 0; color: #0056b3; font-size: 0.9rem;'>
                    Bestimmen Sie, welche Einstellungen bei der Prüfung von Tests als verpflichtend gelten sollen.
                </p>
            </div>

            <form method='post' action='$form_action'>
                <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;'>
                    
                    <div style='background: white; border: 1px solid #e9ecef; border-radius: 0.5rem; padding: 1.5rem;'>
                        <h3 style='color: #495057; margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;'>
                            <span>📍</span> Präsenzprüfungen
                        </h3>
                        <p style='color: #6c757d; font-size: 0.9rem; margin-bottom: 1.25rem;'>
                            Verpflichtende Checks für Prüfungen vor Ort:
                        </p>
                        $praesenz_html
                    </div>

                    <div style='background: white; border: 1px solid #e9ecef; border-radius: 0.5rem; padding: 1.5rem;'>
                        <h3 style='color: #495057; margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;'>
                            <span>🏠</span> Fernprüfungen
                        </h3>
                        <p style='color: #6c757d; font-size: 0.9rem; margin-bottom: 1.25rem;'>
                            Verpflichtende Checks für Online-Prüfungen:
                        </p>
                        $fern_html
                    </div>
                </div>

                <div style='text-align: right; padding-top: 1rem; border-top: 1px solid #e9ecef;'>
                    <button type='submit' style='background: #007bff; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 0.375rem; font-weight: 500; cursor: pointer;'>
                        💾 Vorgaben speichern
                    </button>
                </div>
            </form>
        </div>

        <style>
        button:hover {
            background: #0056b3 !important;
            transform: translateY(-1px);
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
        }
        </style>";
    }

    private function renderCheckboxGroup(string $exam_type, array $current_settings): string
    {
        $checkboxes = '';
        $check_definitions = $this->getCheckDefinitions();
        
        foreach ($check_definitions as $key => $definition) {
            // Prüfen ob dieser Check für den Prüfungstyp relevant ist
            $relevant_for_type = in_array($exam_type, $definition['relevant_for']);
            if (!$relevant_for_type) continue;
            
            $checked = in_array($key, $current_settings) ? 'checked' : '';
            $name = "checks_{$exam_type}[]";
            
            $checkboxes .= "
            <div style='margin-bottom: 0.75rem;'>
                <label style='display: flex; align-items: flex-start; gap: 0.5rem; cursor: pointer; padding: 0.5rem; border-radius: 0.25rem; transition: background-color 0.15s;' 
                       onmouseover='this.style.backgroundColor=\"#f8f9fa\"' 
                       onmouseout='this.style.backgroundColor=\"transparent\"'>
                    <input type='checkbox' name='$name' value='$key' $checked style='margin-top: 0.125rem; transform: scale(1.1);'>
                    <div>
                        <div style='font-weight: 500; color: #495057; font-size: 0.9rem;'>{$definition['title']}</div>
                        <div style='color: #6c757d; font-size: 0.8rem; margin-top: 0.25rem;'>{$definition['description']}</div>
                    </div>
                </label>
            </div>";
        }

        return $checkboxes ?: '<p style="color: #6c757d; font-style: italic;">Keine relevanten Checks für diesen Prüfungstyp.</p>';
    }

    private function getCheckDefinitions(): array
    {
        return [
            'fragenpools_verwenden' => [
                'title' => 'Fragenpools verwenden',
                'description' => 'Test muss Fragenpools nutzen',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'datenschutz' => [
                'title' => 'Datenschutz (Testergebnisse mit Namen)', 
                'description' => 'Anonymität muss deaktiviert sein',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'online' => [
                'title' => 'Test ist online geschaltet',
                'description' => 'Test muss online verfügbar sein',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'passwort' => [
                'title' => 'Testpasswort gesetzt',
                'description' => 'Ein Passwort muss konfiguriert sein',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'nur_ausgewaehlte_teilnehmer' => [
                'title' => 'Nur ausgewählte Teilnehmer (deaktiviert)',
                'description' => 'Offener Zugang für alle Teilnehmer',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'testdurchlaeufe_begrenzen' => [
                'title' => 'Testdurchläufe auf 1 begrenzt',
                'description' => 'Anzahl der Versuche muss 1 sein',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'wartezeit_erzwingen' => [
                'title' => 'Wartezeit zwischen Durchläufen (deaktiviert)',
                'description' => 'Keine Wartezeit zwischen Versuchen',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'pruefungsansicht' => [
                'title' => 'Prüfungsansicht (Kiosk-Modus)',
                'description' => 'Kiosk-Modus muss aktiviert sein',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'automatisches_speichern' => [
                'title' => 'Automatisches Speichern (30s)',
                'description' => 'Auto-Save alle 30 Sekunden',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'direkte_rueckmeldung' => [
                'title' => 'Direkte Rückmeldung (deaktiviert)',
                'description' => 'Sofortige Antwortbewertung ausgeschaltet',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'fragen_markieren' => [
                'title' => 'Fragen markieren aktiviert',
                'description' => 'Teilnehmer können Fragen markieren',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'eigenstaendigkeitserklaerung' => [
                'title' => 'Eigenständigkeitserklärung',
                'description' => 'Nur für Fernprüfungen erforderlich',
                'relevant_for' => ['fern']
            ],
            'bearbeitungsdauer' => [
                'title' => 'Bearbeitungsdauer begrenzen',
                'description' => 'Zeitlimit muss aktiviert sein',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'weiterleitung' => [
                'title' => 'Weiterleitung zu logout.php',
                'description' => 'Nur bei Präsenzprüfungen mit Kiosk',
                'relevant_for' => ['praesenz']
            ],
            'bewertungssystem' => [
                'title' => 'Teilweise richtige Antworten bewerten',
                'description' => 'Teilpunkte für unvollständige Antworten',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'negative_punkte' => [
                'title' => 'Negative Punkte (deaktiviert)',
                'description' => 'Keine Punktabzüge für falsche Antworten',
                'relevant_for' => ['praesenz', 'fern']
            ],
            'besten_durchlauf_bewerten' => [
                'title' => 'Besten Testdurchlauf bewerten',
                'description' => 'Beste Leistung zählt',
                'relevant_for' => ['praesenz', 'fern']
            ]
        ];
    }

    private function getExamCheckerSettings(string $exam_type): array
    {
        $settings_string = $this->settings->get("examchecker_standards_$exam_type", '');
        return empty($settings_string) ? $this->getDefaultSettings($exam_type) : explode(',', $settings_string);
    }

    private function getDefaultSettings(string $exam_type): array
    {
        // Standard-Aktivierungen basierend auf deinem ExamChecker-Code
        $defaults = [
            'fragenpools_verwenden',
            'datenschutz', 
            'online',
            'passwort',
            'nur_ausgewaehlte_teilnehmer',
            'testdurchlaeufe_begrenzen',
            'wartezeit_erzwingen',
            'pruefungsansicht',
            'automatisches_speichern',
            'direkte_rueckmeldung',
            'fragen_markieren',
            'bewertungssystem',
            'negative_punkte',
            'besten_durchlauf_bewerten'
        ];

        if ($exam_type === 'fern') {
            $defaults[] = 'eigenstaendigkeitserklaerung';
            $defaults[] = 'bearbeitungsdauer';
        }

        if ($exam_type === 'praesenz') {
            $defaults[] = 'weiterleitung';
        }

        return $defaults;
    }

    /**
     * ExamChecker Settings speichern
     */
    protected function saveExamChecker(): void
    {
        try {
            $post = $_POST;
            
            // Präsenz-Einstellungen
            $praesenz_checks = $post['checks_praesenz'] ?? [];
            $this->settings->set('examchecker_standards_praesenz', implode(',', $praesenz_checks));
            
            // Fernprüfungs-Einstellungen  
            $fern_checks = $post['checks_fern'] ?? [];
            $this->settings->set('examchecker_standards_fern', implode(',', $fern_checks));
            
            $this->tpl->setOnScreenMessage('success', '✅ ExamChecker-Vorgaben erfolgreich gespeichert.');
            
        } catch (Exception $e) {
            $this->tpl->setOnScreenMessage('failure', '❌ Fehler beim Speichern: ' . $e->getMessage());
        }
        
        $this->showExamChecker();
    }

    /**
     * Verwaiste Einträge bereinigen (ILIAS 9 kompatibel)
     */
    protected function cleanupOrphanedEntries(): void
    {
        try {
            $cleaned = $this->performCleanup();
            $this->tpl->setOnScreenMessage('success', "✅ $cleaned verwaiste Einträge wurden bereinigt.");
            
        } catch (Exception $e) {
            $this->tpl->setOnScreenMessage('failure', '❌ Fehler bei der Bereinigung: ' . $e->getMessage());
        }
        
        $this->showStatistics();
    }

    /**
     * Führt die eigentliche Bereinigung durch
     */
    private function performCleanup(): int
    {
        global $DIC;
        $db = $DIC->database();
        $cleaned = 0;

        try {
            $tables = ['examcalc_settings', 'examsymbols_settings', 'examguard_settings', 'examalert_settings'];

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
            throw $e;
        }

        return $cleaned;
    }
}