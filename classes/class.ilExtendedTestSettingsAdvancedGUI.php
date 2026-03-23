<?php

/**
 * @ilCtrl_isCalledBy ilExtendedTestSettingsAdvancedGUI: ilUIPluginRouterGUI
 */
class ilExtendedTestSettingsAdvancedGUI
{
    private const DEBUG_ENABLED = false;

    protected ilCtrl $ctrl;
    protected ilGlobalTemplateInterface $tpl;
    protected ilLanguage $lng;

    public function __construct()
    {
        global $DIC;
        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC->ui()->mainTemplate();
        $this->lng = $DIC->language();
    }

    public function executeCommand(): void
    {
        $cmd = $this->ctrl->getCmd("show");
        $this->performCommand($cmd);
    }

    public function performCommand(string $cmd): void
    {
        global $DIC, $rbacsystem;

        // Hole ref_id
        $ref_id = (int) ($_GET["ref_id"] ?? 0);

        // Prüfe ob Benutzer eingeloggt ist
        if (!$DIC->user()->getId() || $DIC->user()->isAnonymous()) {
            $this->tpl->setOnScreenMessage(
                'failure',
                'Zugriff verweigert: Sie müssen angemeldet sein.'
            );
            $DIC->ctrl()->redirectToURL('./login.php');
            return;
        }

        // Prüfe ob ref_id vorhanden
        if ($ref_id <= 0) {
            $this->tpl->setOnScreenMessage(
                'failure',
                'Kein gültiger Test ausgewählt.'
            );
            $this->tpl->printToStdout();
            return;
        }

        // NEU: Erweiterte Sichtbarkeits- und Berechtigungsprüfung
        $settings = new ilSetting('extendedtestsettings');
        $visibility = $settings->get('tab_visibility', 'course_admins');
        $user_id = $DIC->user()->getId();
        $is_system_admin = $rbacsystem->checkAccess("write", SYSTEM_FOLDER_ID);

        // Zugriffsprüfung basierend auf Sichtbarkeitseinstellung
        $access_granted = false;

        if ($is_system_admin) {
            // System-Admins haben immer Zugriff
            $access_granted = true;
        } elseif ($visibility === 'admins_only') {
            // Nur System-Admins erlaubt
            $access_granted = false;
        } elseif ($visibility === 'specific_users') {
            // Nur spezifische Benutzer erlaubt
            $access_granted = $this->isUserAuthorized($user_id);
        } else {
            // Standard: Kurs-Admins + System-Admins
            $access_granted = $this->hasTestSettingsAccess($ref_id);
        }

        // Zugriff verweigern falls nicht berechtigt
        if (!$access_granted) {
            $error_messages = [
                'admins_only' => 'Diese Funktion ist nur für System-Administratoren verfügbar.',
                'specific_users' => 'Sie haben keine Berechtigung für diese Funktion.',
                'course_admins' => 'Sie haben keine Berechtigung, diese Test-Einstellungen zu ändern.'
            ];

            $this->tpl->setOnScreenMessage(
                'failure',
                'Zugriff verweigert: ' . ($error_messages[$visibility] ?? $error_messages['course_admins'])
            );
            $DIC->ctrl()->redirectToURL("ilias.php?ref_id=$ref_id&baseClass=ilRepositoryGUI");
            return;
        }

        // Befehl ausführen
        switch ($cmd) {
            case "show":
                $this->show();
                break;
            case "saveSettings":
                $this->saveSettings();
                break;
            default:
                $this->show();
                break;
        }

        $this->tpl->printToStdout();
    }

    /**
     * Prüft ob ein Benutzer explizit berechtigt ist
     * @param int $user_id Die Benutzer-ID
     * @return bool True wenn berechtigt
     */
    private function isUserAuthorized(int $user_id): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            // Prüfe ob Tabelle existiert
            if (!$db->tableExists('extendedtest_users')) {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] Tabelle 'extendedtest_users' existiert nicht");
                }
                return false;
            }

            // Prüfe ob User in der Berechtigungstabelle ist
            $result = $db->queryF(
                "SELECT user_id FROM extendedtest_users WHERE user_id = %s",
                ['integer'],
                [$user_id]
            );

            $is_authorized = $db->numRows($result) > 0;

            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] User $user_id Berechtigung: " . ($is_authorized ? 'JA' : 'NEIN'));
            }

            return $is_authorized;
        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler bei Berechtigungsprüfung für User $user_id: " . $e->getMessage());
            return false;
        }
    }

    protected function show(): void
    {
        $ref_id = (int) ($_GET["ref_id"] ?? 0);
        if ($ref_id <= 0) {
            $this->tpl->setOnScreenMessage('failure', 'Kein gültiger Test ausgewählt.');
            return;
        }

        // Prüfe ob es ein Test-Objekt ist
        $obj_id = ilObject::_lookupObjectId($ref_id);
        if (ilObject::_lookupType($obj_id) !== 'tst') {
            $this->tpl->setOnScreenMessage('failure', 'Das ausgewählte Objekt ist kein Test.');
            return;
        }

        // Debug-Informationen (optional ein/ausblenden)
        $show_debug = isset($_GET['debug']) && $_GET['debug'] === '1';
        $debug_info = $show_debug ? $this->generateDebugInfo($ref_id, $obj_id) : '';

        // Navigation einrichten
        $this->setupNavigation($ref_id);

        // Formular generieren
        $form = $this->buildModuleForm($ref_id);
        $debug_link = !$show_debug
            ? '<p class="text-end mt-2"><a href="' . $_SERVER['REQUEST_URI'] . '&debug=1" class="text-muted small">Debug-Informationen anzeigen</a></p>'
            : '';

        $this->tpl->setTitle('Erweiterte Test-Einstellungen');
        $this->tpl->setDescription('Konfiguration für alle verfügbaren Prüfungs-Features');
        $this->tpl->setContent($debug_info . $form->getHTML() . $debug_link);
    }


    /**
     * Prüft ob der Benutzer Zugriff auf die Test-Einstellungen hat
     * MAXIMALE KOMPATIBILITÄT - funktioniert mit verschiedenen ILIAS-Versionen
     * @param int $test_ref_id Die Referenz-ID des Tests
     * @return bool True wenn Zugriff erlaubt
     */
    private function hasTestSettingsAccess(int $test_ref_id): bool
    {
        global $DIC, $rbacsystem;

        // 1. Check: Direkte Schreibrechte auf den Test
        if ($rbacsystem->checkAccess("write", $test_ref_id)) {
            return true;
        }

        // 2. Check: Ist System-Admin?
        if ($rbacsystem->checkAccess("write", SYSTEM_FOLDER_ID)) {
            return true;
        }

        // 3. Check: Vereinfachte Prüfung über Parent-Objekte
        try {
            // Verschiedene Tree-Methoden probieren
            $tree = $DIC->repositoryTree();
            if (!$tree) {
                $tree = $GLOBALS['tree'] ?? null;
            }

            if ($tree) {
                $parent_ref_id = null;

                // Versuche verschiedene Methoden
                if (method_exists($tree, 'getParentId')) {
                    $parent_ref_id = $tree->getParentId($test_ref_id);
                } elseif (method_exists($tree, 'getParentNodeData')) {
                    $parent_data = $tree->getParentNodeData($test_ref_id);
                    $parent_ref_id = $parent_data['ref_id'] ?? null;
                } elseif (method_exists($tree, 'getNodeData')) {
                    $node_data = $tree->getNodeData($test_ref_id);
                    $parent_ref_id = $node_data['parent'] ?? null;
                }

                // Falls wir einen Parent gefunden haben
                if ($parent_ref_id && $parent_ref_id > 0) {
                    // Prüfe bis zu 5 Ebenen nach oben
                    $levels_checked = 0;
                    $current_ref_id = $parent_ref_id;

                    while ($current_ref_id > 0 && $levels_checked < 5) {
                        $parent_type = ilObject::_lookupType($current_ref_id, true);

                        // Ist es ein Kurs oder eine Gruppe?
                        if (in_array($parent_type, ['crs', 'grp'])) {
                            // Hat der User Schreibrechte?
                            if ($rbacsystem->checkAccess("write", $current_ref_id)) {
                                return true;
                            }
                        }

                        // Nächste Ebene
                        if (method_exists($tree, 'getParentId')) {
                            $current_ref_id = $tree->getParentId($current_ref_id);
                        } else {
                            break; // Keine weitere Navigation möglich
                        }

                        $levels_checked++;
                    }
                }
            }
        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Tree-Navigation fehlgeschlagen: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Generiert Debug-Informationen
     */
    private function generateDebugInfo(int $ref_id, int $obj_id): string
    {
        global $DIC;
        $db = $DIC->database();

        $debug_lines = [];
        $debug_lines[] = "🔍 <strong>Debug-Informationen:</strong>";
        $debug_lines[] = "ref_id: $ref_id";
        $debug_lines[] = "obj_id (test_id): $obj_id";
        $debug_lines[] = "Aktueller User: " . ($DIC->user()->getLogin() ?? 'Unbekannt');

        // Prüfe alle Module-Tabellen
        $tables = [
            'examcalc_settings' => ['enabled'],
            'examguard_settings' => ['enabled'],
            'examsymbols_settings' => ['enabled', 'template'],
            'examalert_settings' => ['enabled', 'alert_time', 'alert_message'] // NEU
        ];

        foreach ($tables as $table => $fields) {
            try {
                if (count($fields) > 1) {
                    // Mehrere Felder (z.B. ExamSymbols, ExamAlert)
                    $field_list = implode(', ', $fields);
                    $query = "SELECT $field_list FROM $table WHERE test_id = %s";
                    $result = $db->queryF($query, ['integer'], [$obj_id]);

                    if ($row = $db->fetchAssoc($result)) {
                        $status_parts = [];
                        foreach ($fields as $field) {
                            $value = $row[$field] ?? 'NULL';
                            if ($field === 'enabled') {
                                $value = $value ? '✅ aktiviert' : '❌ deaktiviert';
                            }
                            $status_parts[] = "$field: $value";
                        }
                        $debug_lines[] = "$table: " . implode(', ', $status_parts);
                    } else {
                        $debug_lines[] = "$table: ❌ kein Eintrag";
                    }
                } else {
                    // Ein Feld (z.B. ExamCalc, ExamGuard)
                    $field = $fields[0];
                    $query = "SELECT $field FROM $table WHERE test_id = %s";
                    $result = $db->queryF($query, ['integer'], [$obj_id]);

                    if ($row = $db->fetchAssoc($result)) {
                        $status = $row[$field] ? '✅ aktiviert' : '❌ deaktiviert';
                        $debug_lines[] = "$table: $status";
                    } else {
                        $debug_lines[] = "$table: ❌ kein Eintrag";
                    }
                }
            } catch (Exception $e) {
                $debug_lines[] = "$table: ❌ Fehler - " . $e->getMessage();
            }
        }

        // Module-Handler-Status prüfen
        try {
            $module_handlers = $this->loadModuleHandlersDirect();
            $available_modules = array_keys($module_handlers);
            $debug_lines[] = "Verfügbare Module: " . (empty($available_modules) ? 'Keine' : implode(', ', $available_modules));

            foreach ($module_handlers as $name => $handler) {
                $class = get_class($handler);
                $methods = method_exists($handler, 'getInfo') && method_exists($handler, 'saveSettings') ? '✅' : '❌';
                $debug_lines[] = "- $name: $class $methods";
            }
        } catch (Exception $e) {
            $debug_lines[] = "Module-Handler: ❌ Fehler - " . $e->getMessage();
        }

        $debug_text = implode("<br>", $debug_lines);

        return <<<HTML
<div class="alert alert-warning" style="margin-bottom: 2rem; font-family: monospace; font-size: 12px;">
    $debug_text
    <hr>
    <a href="{$_SERVER['REQUEST_URI']}&debug=0" style="color: #856404;">Debug ausblenden</a>
</div>
HTML;
    }

    /**
     * Navigation einrichten - Breadcrumb und Zurück-Button (Robust)
     */
    private function setupNavigation(int $ref_id): void
    {
        global $DIC, $ilLocator, $ilTabs;

        try {
            $test_title = ilObject::_lookupTitle(ilObject::_lookupObjectId($ref_id));

            // 1. BREADCRUMB / LOCATOR (mehrere Varianten probieren)
            $locator = null;
            if (isset($ilLocator)) {
                $locator = $ilLocator;
            } elseif ($DIC->offsetExists('ilLocator')) {
                $locator = $DIC['ilLocator'];
            } elseif ($DIC->offsetExists('locator')) {
                $locator = $DIC['locator'];
            }

            if ($locator) {
                // Repository-Items hinzufügen (Pfad bis zum Test)
                if (method_exists($locator, 'addRepositoryItems')) {
                    $locator->addRepositoryItems($ref_id);
                } else {
                    // Fallback: Manuell hinzufügen
                    $locator->addItem(
                        "Repository",
                        "ilias.php?baseClass=ilRepositoryGUI"
                    );
                    $locator->addItem(
                        $test_title,
                        "ilias.php?ref_id=" . $ref_id . "&baseClass=ilRepositoryGUI"
                    );
                }

                // Aktueller Bereich
                $locator->addItem("Erweiterte Einstellungen", "");
            }

            // 2. TABS UND ZURÜCK-BUTTON (mehrere Varianten)
            $tabs = null;
            if (isset($ilTabs)) {
                $tabs = $ilTabs;
            } elseif ($DIC->offsetExists('ilTabs')) {
                $tabs = $DIC['ilTabs'];
            } elseif ($DIC->offsetExists('tabs')) {
                $tabs = $DIC['tabs'];
            }

            if ($tabs) {
                // ZURÜCK-BUTTON (sehr prominent)
                $back_url = "ilias.php?ref_id=" . $ref_id . "&baseClass=ilRepositoryGUI";
                $tabs->setBackTarget("←  " . $test_title, $back_url);

                // Main-Tab für diese Seite
                $this->ctrl->setParameter($this, "ref_id", $ref_id);
                $tabs->addTab(
                    "extended_settings",
                    "Erweiterte Einstellungen",
                    $this->ctrl->getLinkTarget($this, "show")
                );
                $tabs->activateTab("extended_settings");
            }

            // 3. TITEL UND BESCHREIBUNG (Template-Ebene)
            $this->tpl->setTitle("Erweiterte Test-Einstellungen");
            $this->tpl->setDescription("Konfiguration für spezielle Prüfungs-Features");
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] Navigation-Setup Fehler: " . $e->getMessage());
            }
        }
    }

    /**
     * Baut das ILIAS-Standard-Formular mit allen Modulen auf
     */
    private function buildModuleForm(int $ref_id): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();

        $this->ctrl->setParameter($this, 'ref_id', $ref_id);
        $form->setFormAction($this->ctrl->getFormAction($this, 'saveSettings'));
        $form->setTitle('Erweiterte Test-Einstellungen');

        try {
            $module_handlers = $this->loadModuleHandlersDirect();

            if (empty($module_handlers)) {
                $no_modules = new ilCustomInputGUI();
                $no_modules->setHtml('<div class="alert alert-warning">Keine Prüfungs-Module gefunden. Bitte sicherstellen, dass die Handler-Klassen installiert sind.</div>');
                $form->addItem($no_modules);
            } else {
                foreach ($module_handlers as $moduleName => $handler) {
                    if (!method_exists($handler, 'getInfo') || !method_exists($handler, 'getSettingsHTML')) {
                        continue;
                    }
                    try {
                        $info = $handler->getInfo();

                        $section = new ilFormSectionHeaderGUI();
                        $section->setTitle(htmlspecialchars($info['title'] ?? $moduleName));
                        $form->addItem($section);

                        $custom = new ilCustomInputGUI(
                            htmlspecialchars($info['description'] ?? ''),
                            ''
                        );
                        $custom->setHtml($handler->getSettingsHTML($ref_id));
                        $form->addItem($custom);
                    } catch (Exception $e) {
                        error_log("[ExtendedTestSettings] Fehler beim Laden von Modul '$moduleName': " . $e->getMessage());
                    }
                }
            }
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] Fehler beim Generieren der Module: " . $e->getMessage());
            }
            $this->addFallbackFormItems($form, $ref_id);
        }

        $ref_hidden = new ilHiddenInputGUI('ref_id');
        $ref_hidden->setValue((string) $ref_id);
        $form->addItem($ref_hidden);

        $form->addCommandButton('saveSettings', 'Einstellungen speichern');

        return $form;
    }

    /**
     * Module-Handler direkt laden (ohne UIHookGUI-Abhängigkeit)
     */
    private function loadModuleHandlersDirect(): array
    {
        $plugin_path = "./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExtendedTestSettings";
        $module_handlers = [];

        // ExamCalc Handler laden
        $examcalc_path = __DIR__ . "/ExamCalc/class.ilExamCalcHandler.php";
        if (file_exists($examcalc_path)) {
            require_once $examcalc_path;
            $module_handlers['examcalc'] = new ilExamCalcHandler($plugin_path);
        }

        // ExamSymbols Handler laden (mit Config)
        $examsymbols_path = __DIR__ . "/ExamSymbols/class.ilExamSymbolsHandler.php";
        $examsymbols_config_path = __DIR__ . "/ExamSymbols/class.ilExamSymbolsConfig.php";

        if (file_exists($examsymbols_path) && file_exists($examsymbols_config_path)) {
            require_once $examsymbols_config_path;
            require_once $examsymbols_path;
            $module_handlers['examsymbols'] = new ilExamSymbolsHandler($plugin_path);
        }

        // ExamGuard Handler laden (falls vorhanden)
        $examguard_path = __DIR__ . "/ExamGuard/class.ilExamGuardHandler.php";
        if (file_exists($examguard_path)) {
            require_once $examguard_path;
            $module_handlers['examguard'] = new ilExamGuardHandler($plugin_path);
        }

        // ExamAlert Handler laden - NEU
        $examalert_path = __DIR__ . "/ExamAlert/class.ilExamAlertHandler.php";
        if (file_exists($examalert_path)) {
            require_once $examalert_path;
            $module_handlers['examalert'] = new ilExamAlertHandler($plugin_path);
        }

        // ExamChecker Handler laden
        $examchecker_path = __DIR__ . "/ExamChecker/class.ilExamCheckerHandler.php";
        $examchecker_config_path = __DIR__ . "/ExamChecker/class.ilExamCheckerConfig.php";

        if (file_exists($examchecker_path) && file_exists($examchecker_config_path)) {
            require_once $examchecker_config_path;
            require_once $examchecker_path;
            $module_handlers['examchecker'] = new ilExamCheckerHandler($plugin_path);
        }

        if (self::DEBUG_ENABLED) {
            error_log("[ExtendedTestSettings] AdvancedGUI - Module direkt geladen: " . implode(', ', array_keys($module_handlers)));
        }
        return $module_handlers;
    }

    /**
     * Fügt Fallback-Formularfelder hinzu wenn Module-Handler nicht geladen werden konnten
     */
    private function addFallbackFormItems(ilPropertyFormGUI $form, int $ref_id): void
    {
        // ExamCalc Fallback
        $examcalc_data = $this->getFallbackExamCalcStatus($ref_id);
        $simple_selected = ($examcalc_data['mode'] === 'simple') ? 'selected' : '';
        $advanced_selected = ($examcalc_data['mode'] === 'advanced') ? 'selected' : '';
        $examcalc_checked = $examcalc_data['enabled'] ? 'checked' : '';

        $section_calc = new ilFormSectionHeaderGUI();
        $section_calc->setTitle('Taschenrechner');
        $form->addItem($section_calc);

        $custom_calc = new ilCustomInputGUI('Taschenrechner für Prüfungen', '');
        $custom_calc->setHtml('<div class="mb-3"><div class="form-check">
            <input class="form-check-input" type="checkbox" name="examcalc_enabled" value="1" id="examcalc_enabled" ' . $examcalc_checked . '>
            <label class="form-check-label fw-bold" for="examcalc_enabled">Taschenrechner aktivieren</label>
        </div></div>
        <div class="mb-3">
            <label for="examcalc_mode" class="form-label fw-semibold">Taschenrechner-Typ:</label>
            <select name="examcalc_mode" id="examcalc_mode" class="form-select">
                <option value="simple" ' . $simple_selected . '>Einfach (Grundrechenarten) – Standard</option>
                <option value="advanced" ' . $advanced_selected . '>Wissenschaftlich (Work in Progress)</option>
            </select>
        </div>');
        $form->addItem($custom_calc);

        // ExamSymbols Fallback
        $examsymbols_data = $this->getFallbackExamSymbolsStatus($ref_id);
        $examsymbols_checked = $examsymbols_data['enabled'] ? 'checked' : '';
        $math_sel = ($examsymbols_data['template'] === 'math') ? 'selected' : '';
        $german_sel = ($examsymbols_data['template'] === 'german') ? 'selected' : '';
        $spanish_sel = ($examsymbols_data['template'] === 'spanish') ? 'selected' : '';

        $section_sym = new ilFormSectionHeaderGUI();
        $section_sym->setTitle('Sonderzeichen-Palette');
        $form->addItem($section_sym);

        $custom_sym = new ilCustomInputGUI('Palette mit Sonderzeichen', '');
        $custom_sym->setHtml('<div class="mb-3"><div class="form-check">
            <input class="form-check-input" type="checkbox" name="examsymbols_enabled" value="1" id="examsymbols_enabled" ' . $examsymbols_checked . '>
            <label class="form-check-label fw-bold" for="examsymbols_enabled">Sonderzeichen aktivieren</label>
        </div></div>
        <div class="mb-3">
            <label for="examsymbols_template" class="form-label fw-semibold">Vorlage:</label>
            <select name="examsymbols_template" id="examsymbols_template" class="form-select">
                <option value="math" ' . $math_sel . '>Mathematik/Wissenschaft</option>
                <option value="german" ' . $german_sel . '>Deutsch</option>
                <option value="spanish" ' . $spanish_sel . '>Spanisch</option>
            </select>
        </div>');
        $form->addItem($custom_sym);

        // ExamAlert Fallback
        $examalert_data = $this->getFallbackExamAlertStatus($ref_id);
        $examalert_checked = $examalert_data['enabled'] ? 'checked' : '';
        $examalert_time = (int) $examalert_data['alert_time'];
        $examalert_message = htmlspecialchars($examalert_data['alert_message'], ENT_QUOTES);

        $section_alert = new ilFormSectionHeaderGUI();
        $section_alert->setTitle('Zeit-Warnung');
        $form->addItem($section_alert);

        $custom_alert = new ilCustomInputGUI('Zeitbasierte Warnungen', '');
        $custom_alert->setHtml('<div class="mb-3"><div class="form-check">
            <input class="form-check-input" type="checkbox" name="examalert_enabled" value="1" id="examalert_enabled" ' . $examalert_checked . '>
            <label class="form-check-label fw-bold" for="examalert_enabled">Zeit-Warnung aktivieren</label>
        </div></div>
        <div class="mb-3">
            <label for="examalert_time" class="form-label fw-semibold">Warnung anzeigen:</label>
            <div class="input-group" style="max-width:300px;">
                <input type="number" class="form-control" name="examalert_time" id="examalert_time" value="' . $examalert_time . '" min="5" max="3600">
                <span class="input-group-text">Sekunden vor Testende</span>
            </div>
        </div>
        <div class="mb-3">
            <label for="examalert_message" class="form-label fw-semibold">Nachricht:</label>
            <textarea name="examalert_message" id="examalert_message" rows="3" class="form-control">' . $examalert_message . '</textarea>
        </div>');
        $form->addItem($custom_alert);
    }

    /**
     * Fallback ExamCalc Status
     */
    private function getFallbackExamCalcStatus(int $ref_id): array
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);
            $query = "SELECT enabled, calculator_mode FROM examcalc_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return [
                    'enabled' => (bool) $row['enabled'],
                    'mode' => $row['calculator_mode'] ?? 'simple'
                ];
            }
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] Fallback ExamCalc Status Fehler: " . $e->getMessage());
            }
        }

        return ['enabled' => false, 'mode' => 'simple'];
    }

    /**
     * Fallback ExamSymbols Status
     */
    private function getFallbackExamSymbolsStatus(int $ref_id): array
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);
            $query = "SELECT enabled, template FROM examsymbols_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return [
                    'enabled' => (bool) $row['enabled'],
                    'template' => $row['template'] ?? 'math'
                ];
            }
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] Fallback ExamSymbols Status Fehler: " . $e->getMessage());
            }
        }

        return ['enabled' => false, 'template' => 'math'];
    }

    /**
     * Fallback ExamAlert Status - NEU
     */
    private function getFallbackExamAlertStatus(int $ref_id): array
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);
            $query = "SELECT enabled, alert_time, alert_message FROM examalert_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return [
                    'enabled' => (bool) $row['enabled'],
                    'alert_time' => (int) ($row['alert_time'] ?? 30),
                    'alert_message' => $row['alert_message'] ?? 'Achtung: Der Test endet bald!'
                ];
            }

            // Kein Eintrag: Default anlegen — enabled=1 als fester Default
            $db->insert('examalert_settings', [
                'test_id'       => ['integer',   $test_id],
                'enabled'       => ['integer',   1],
                'alert_time'    => ['integer',   30],
                'alert_message' => ['text',      'Achtung: Der Test endet bald!'],
                'updated_at'    => ['timestamp', date('Y-m-d H:i:s')]
            ]);

            error_log("[ExamAlert] Fallback: Default-Einstellungen erstellt für Test-ID: $test_id (enabled=1)");

            return [
                'enabled'       => true,
                'alert_time'    => 30,
                'alert_message' => 'Achtung: Der Test endet bald!'
            ];
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] Fallback ExamAlert Status Fehler: " . $e->getMessage());
            }
        }

        return ['enabled' => false, 'alert_time' => 30, 'alert_message' => 'Achtung: Der Test endet bald!'];
    }

    /**
     * Einstellungen speichern
     */
    /**
     * Einstellungen speichern
     */
    public function saveSettings(): void
    {
        global $DIC, $rbacsystem;

        $ref_id = (int) ($_POST["ref_id"] ?? $_GET["ref_id"] ?? 0);

        // Prüfe nochmals die Berechtigung beim Speichern
        if (!$DIC->user()->getId() || $DIC->user()->isAnonymous()) {
            $this->tpl->setOnScreenMessage('failure', 'Nicht autorisiert.');
            $DIC->ctrl()->redirectToURL('./login.php');
            return;
        }

        // NEU: Erweiterte Berechtigungsprüfung beim Speichern
        $settings = new ilSetting('extendedtestsettings');
        $visibility = $settings->get('tab_visibility', 'course_admins');
        $user_id = $DIC->user()->getId();
        $is_system_admin = $rbacsystem->checkAccess("write", SYSTEM_FOLDER_ID);

        $access_granted = false;

        if ($is_system_admin) {
            $access_granted = true;
        } elseif ($visibility === 'admins_only') {
            $access_granted = false;
        } elseif ($visibility === 'specific_users') {
            $access_granted = $this->isUserAuthorized($user_id);
        } else {
            $access_granted = $this->hasTestSettingsAccess($ref_id);
        }

        if (!$access_granted) {
            $this->tpl->setOnScreenMessage(
                'failure',
                'Zugriff verweigert: Keine Berechtigung zum Speichern.'
            );
            $this->ctrl->redirect($this, "show");
            return;
        }

        if ($ref_id <= 0) {
            $this->tpl->setOnScreenMessage('failure', '❌ Kein gültiger Test ausgewählt.');
            $this->ctrl->redirect($this, "show");
            return;
        }

        try {
            // Module-Handler direkt laden und speichern
            $module_handlers = $this->loadModuleHandlersDirect();
            $results = [];

            global $DIC;
            $post_data = $DIC->http()->request()->getParsedBody(); // ILIAS 9 kompatibel

            foreach ($module_handlers as $moduleName => $handler) {
                try {
                    if (method_exists($handler, 'saveSettings')) {
                        $success = $handler->saveSettings($ref_id, $post_data);
                        $results[$moduleName] = $success;
                    } else {
                        $results[$moduleName] = false;
                        error_log("[ExtendedTestSettings] Handler '$moduleName' hat keine saveSettings() Methode");
                    }
                } catch (Exception $e) {
                    $results[$moduleName] = false;
                    error_log("[ExtendedTestSettings] Fehler beim Speichern von '$moduleName': " . $e->getMessage());
                }
            }

            $success_modules = array_filter($results);
            $failed_modules = array_diff_key($results, $success_modules);

            $this->ctrl->setParameter($this, "ref_id", $ref_id);

            // Success-Message zusammenstellen
            if (!empty($success_modules)) {
                $success_names = array_keys($success_modules);
                $success_text = $this->formatModuleNames($success_names);
                $this->tpl->setOnScreenMessage('success', "✅ Einstellungen erfolgreich gespeichert für: $success_text");
            }

            // Fehler-Message zusammenstellen
            if (!empty($failed_modules)) {
                $failed_names = array_keys($failed_modules);
                $failed_text = $this->formatModuleNames($failed_names);
                $this->tpl->setOnScreenMessage('failure', "❌ Fehler beim Speichern von: $failed_text");
            }

            // Keine Module verarbeitet
            if (empty($results)) {
                $this->tpl->setOnScreenMessage('info', 'ℹ️ Keine Module zum Speichern gefunden.');
            }
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] Fehler beim Speichern: " . $e->getMessage());
            }
            $this->tpl->setOnScreenMessage('failure', '❌ Unerwarteter Fehler beim Speichern: ' . $e->getMessage());
        }

        $this->ctrl->redirect($this, "show");
    }

    /**
     * Modul-Namen für Ausgabe formatieren
     */
    private function formatModuleNames(array $module_names): string
    {
        $formatted = [];
        foreach ($module_names as $name) {
            switch (strtolower($name)) {
                case 'examcalc':
                    $formatted[] = 'Taschenrechner';
                    break;
                case 'examsymbols':
                    $formatted[] = 'Sonderzeichen';
                    break;
                case 'examguard':
                    $formatted[] = 'Prüfungsschutz';
                    break;
                case 'examalert':
                    $formatted[] = 'Zeit-Warnung';
                    break;
                case 'examchecker':
                    $formatted[] = 'Prüfungs-Checker';
                    break;

                default:
                    $formatted[] = ucfirst($name);
                    break;
            }
        }

        return implode(', ', $formatted);
    }
}
