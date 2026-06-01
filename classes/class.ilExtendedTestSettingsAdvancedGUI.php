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
    protected ilExtendedTestSettingsPlugin $plugin;

    public function __construct()
    {
        global $DIC;
        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC->ui()->mainTemplate();
        $this->lng = $DIC->language();
        $this->plugin = ilExtendedTestSettingsPlugin::getInstance();
    }

    /**
     * Löst eine Sprachvariable des Plugins auf.
     */
    private function txt(string $key): string
    {
        return $this->plugin->txt($key);
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
                $this->txt('access_denied_login')
            );
            $DIC->ctrl()->redirectToURL('./login.php');
            return;
        }

        // Prüfe ob ref_id vorhanden
        if ($ref_id <= 0) {
            $this->tpl->setOnScreenMessage(
                'failure',
                $this->txt('no_valid_test')
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
                'admins_only' => $this->txt('access_admins_only'),
                'specific_users' => $this->txt('access_no_permission'),
                'course_admins' => $this->txt('access_no_test_permission')
            ];

            $this->tpl->setOnScreenMessage(
                'failure',
                sprintf(
                    $this->txt('access_denied_reason'),
                    $error_messages[$visibility] ?? $error_messages['course_admins']
                )
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
            $this->tpl->setOnScreenMessage('failure', $this->txt('no_valid_test'));
            return;
        }

        // Prüfe ob es ein Test-Objekt ist
        $obj_id = ilObject::_lookupObjectId($ref_id);
        if (ilObject::_lookupType($obj_id) !== 'tst') {
            $this->tpl->setOnScreenMessage('failure', $this->txt('not_a_test'));
            return;
        }

        // Navigation einrichten
        $this->setupNavigation($ref_id);

        // Formular generieren
        $form = $this->buildModuleForm($ref_id);

        $this->tpl->setTitle($this->txt('advanced_settings'));
        $this->tpl->setDescription($this->txt('advanced_settings_info'));
        $this->tpl->setContent($form->getHTML());
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
     * Navigation einrichten - Breadcrumb und Zurück-Button (Robust)
     */
    private function setupNavigation(int $ref_id, string $active_tab = 'extended_settings'): void
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
                        $this->txt('locator_repository'),
                        "ilias.php?baseClass=ilRepositoryGUI"
                    );
                    $locator->addItem(
                        $test_title,
                        "ilias.php?ref_id=" . $ref_id . "&baseClass=ilRepositoryGUI"
                    );
                }

                // Aktueller Bereich
                $locator->addItem($this->txt('advanced_settings_short'), "");
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

                $this->ctrl->setParameter($this, "ref_id", $ref_id);
                $tabs->addTab(
                    "extended_settings",
                    $this->txt('advanced_settings_short'),
                    $this->ctrl->getLinkTarget($this, "show")
                );


                $tabs->activateTab($active_tab);
            }

            // 3. TITEL UND BESCHREIBUNG (Template-Ebene)
            $this->tpl->setTitle($this->txt('advanced_settings'));
            $this->tpl->setDescription($this->txt('advanced_settings_nav_info'));
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
        $form->setTitle($this->txt('advanced_settings'));

        try {
            $module_handlers = $this->loadModuleHandlersDirect();

            if (empty($module_handlers)) {
                $msg = new ilCustomInputGUI();
                $msg->setHtml('<p>' . $this->txt('no_exam_modules') . '</p>');
                $form->addItem($msg);
            } else {
                foreach ($module_handlers as $moduleName => $handler) {
                    if (!method_exists($handler, 'getInfo')) {
                        continue;
                    }
                    try {
                        $info = $handler->getInfo();

                        $section = new ilFormSectionHeaderGUI();
                        $section->setTitle(($info['icon'] ?? '') . ' ' . htmlspecialchars($info['title'] ?? $moduleName));
                        $form->addItem($section);

                        if (method_exists($handler, 'getFormItems')) {
                            foreach ($handler->getFormItems($ref_id) as $item) {
                                $form->addItem($item);
                            }
                        } elseif (method_exists($handler, 'getSettingsHTML')) {
                            $custom = new ilCustomInputGUI(
                                htmlspecialchars($info['description'] ?? ''),
                                ''
                            );
                            $custom->setHtml($handler->getSettingsHTML($ref_id));
                            $form->addItem($custom);
                        }
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

        $form->addCommandButton('saveSettings', $this->txt('save_settings'));

        return $form;
    }

    /**
     * Module-Handler über Registry laden (built-in + externe Module)
     */
    private function loadModuleHandlersDirect(): array
    {
        $plugin_path = __DIR__ . '/..';
        require_once __DIR__ . '/Module/ilModuleRegistry.php';
        $registry = new ilModuleRegistry($plugin_path);
        $module_handlers = $registry->getActiveHandlers();

        if (self::DEBUG_ENABLED) {
            error_log("[ExtendedTestSettings] AdvancedGUI - Module via Registry geladen: " . implode(', ', array_keys($module_handlers)));
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

        $section_calc = new ilFormSectionHeaderGUI();
        $section_calc->setTitle('🧮 ' . $this->txt('fb_calc_section'));
        $form->addItem($section_calc);

        $cb_calc = new ilCheckboxInputGUI($this->txt('fb_calc_enable'), 'examcalc_enabled');
        $cb_calc->setChecked($examcalc_data['enabled']);
        $sel_calc = new ilSelectInputGUI($this->txt('fb_calc_type'), 'examcalc_mode');
        $sel_calc->setOptions([
            'simple'   => $this->txt('fb_calc_simple'),
            'advanced' => $this->txt('fb_calc_advanced'),
        ]);
        $sel_calc->setValue($examcalc_data['mode']);
        $cb_calc->addSubItem($sel_calc);
        $form->addItem($cb_calc);

        // ExamSymbols Fallback
        $examsymbols_data = $this->getFallbackExamSymbolsStatus($ref_id);

        $section_sym = new ilFormSectionHeaderGUI();
        $section_sym->setTitle('📝 ' . $this->txt('fb_sym_section'));
        $form->addItem($section_sym);

        $cb_sym = new ilCheckboxInputGUI($this->txt('fb_sym_enable'), 'examsymbols_enabled');
        $cb_sym->setChecked($examsymbols_data['enabled']);
        $sel_sym = new ilSelectInputGUI($this->txt('fb_sym_template'), 'examsymbols_template');
        $sel_sym->setOptions([
            'math'    => $this->txt('fb_sym_math'),
            'german'  => $this->txt('fb_sym_german'),
            'spanish' => $this->txt('fb_sym_spanish'),
        ]);
        $sel_sym->setValue($examsymbols_data['template']);
        $cb_sym->addSubItem($sel_sym);
        $form->addItem($cb_sym);

        // ExamAlert Fallback
        $examalert_data = $this->getFallbackExamAlertStatus($ref_id);

        $section_alert = new ilFormSectionHeaderGUI();
        $section_alert->setTitle('⏰ ' . $this->txt('fb_alert_section'));
        $form->addItem($section_alert);

        $cb_alert = new ilCheckboxInputGUI($this->txt('fb_alert_enable'), 'examalert_enabled');
        $cb_alert->setChecked($examalert_data['enabled']);
        $num_alert = new ilNumberInputGUI($this->txt('fb_alert_show'), 'examalert_time');
        $num_alert->setValue((string) (int) $examalert_data['alert_time']);
        $num_alert->setMinValue(5);
        $num_alert->setMaxValue(3600);
        $num_alert->setSuffix($this->txt('fb_alert_suffix'));
        $cb_alert->addSubItem($num_alert);
        $ta_alert = new ilTextAreaInputGUI($this->txt('fb_alert_message'), 'examalert_message');
        $ta_alert->setValue($examalert_data['alert_message']);
        $ta_alert->setRows(6);
        $cb_alert->addSubItem($ta_alert);
        $form->addItem($cb_alert);
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
                    'alert_message' => $row['alert_message'] ?? $this->txt('fb_alert_default_message')
                ];
            }

            // Kein Eintrag: konfigurierbaren Default zurückgeben ohne DB-Schreibzugriff
            $plugin_settings = new ilSetting('extendedtestsettings');
            $default_enabled = (bool) $plugin_settings->get('examalert_auto_activate', '1');
            return [
                'enabled'       => $default_enabled,
                'alert_time'    => 30,
                'alert_message' => $this->txt('fb_alert_default_message')
            ];
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] Fallback ExamAlert Status Fehler: " . $e->getMessage());
            }
        }

        return ['enabled' => false, 'alert_time' => 30, 'alert_message' => $this->txt('fb_alert_default_message')];
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
            $this->tpl->setOnScreenMessage('failure', $this->txt('not_authorized'));
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
                $this->txt('access_denied_save')
            );
            $this->ctrl->redirect($this, "show");
            return;
        }

        if ($ref_id <= 0) {
            $this->tpl->setOnScreenMessage('failure', '❌ ' . $this->txt('no_valid_test'));
            $this->ctrl->redirect($this, "show");
            return;
        }

        // Objekttyp-Prüfung: nur Tests erlaubt
        $obj_id = ilObject::_lookupObjectId($ref_id);
        if (ilObject::_lookupType($obj_id) !== 'tst') {
            $this->tpl->setOnScreenMessage('failure', $this->txt('not_a_test'));
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
                $this->tpl->setOnScreenMessage('success', '✅ ' . sprintf($this->txt('save_success_for'), $success_text));
            }

            // Fehler-Message zusammenstellen
            if (!empty($failed_modules)) {
                $failed_names = array_keys($failed_modules);
                $failed_text = $this->formatModuleNames($failed_names);
                $this->tpl->setOnScreenMessage('failure', '❌ ' . sprintf($this->txt('save_failed_for'), $failed_text));
            }

            // Keine Module verarbeitet
            if (empty($results)) {
                $this->tpl->setOnScreenMessage('info', 'ℹ️ ' . $this->txt('no_modules_to_save'));
            }
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] Fehler beim Speichern: " . $e->getMessage());
            }
            $this->tpl->setOnScreenMessage('failure', '❌ ' . sprintf($this->txt('error_save_unexpected'), $e->getMessage()));
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
                    $formatted[] = $this->txt('modname_examcalc');
                    break;
                case 'examsymbols':
                    $formatted[] = $this->txt('modname_examsymbols');
                    break;
                case 'examguard':
                    $formatted[] = $this->txt('modname_examguard');
                    break;
                case 'examalert':
                    $formatted[] = $this->txt('modname_examalert');
                    break;
                case 'exammessage':
                    $formatted[] = $this->txt('modname_exammessage');
                    break;
                case 'examchecker':
                    $formatted[] = $this->txt('modname_examchecker');
                    break;

                default:
                    $formatted[] = ucfirst($name);
                    break;
            }
        }

        return implode(', ', $formatted);
    }
}
