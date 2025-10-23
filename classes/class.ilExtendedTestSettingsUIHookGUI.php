<?php

/**
 * ExtendedTestSettings UI Hook GUI 
 * Handler-basierte Architektur für saubere Modularität
 */

class ilExtendedTestSettingsUIHookGUI extends ilUIHookPluginGUI
{
    private const DEBUG_ENABLED = false;

    /**
     * Modify GUI - Haupteinsprungspunkt
     */
    public function modifyGUI(string $component, string $part, array $params = []): void
    {
        try {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] modifyGUI: component='$component', part='$part'");
            }

            global $ilTabs, $ilCtrl, $DIC;

            // 1. Tab in Test-Einstellungen hinzufügen
            if ($part === 'sub_tabs') {
                $this->addSettingsTab($ilTabs, $ilCtrl, $part);
            }

            // 2. Module-Assets in Testplayer injizieren
            if ($DIC->offsetExists('tpl') && $DIC['tpl'] instanceof ilGlobalTemplateInterface) {
                $this->injectModuleAssets($DIC['tpl']);
            }
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] modifyGUI Fehler: " . $e->getMessage());
            }
            // Nicht weiterwerfen - ILIAS soll weiterlaufen
        }
    }

    /**
     * Fügt Tab in Test-Einstellungen hinzu
     */
    private function addSettingsTab($ilTabs, $ilCtrl, string $part): void
    {
        try {
            if ($part !== 'sub_tabs') {
                return;
            }

            $cmd_class = $ilCtrl->getCmdClass();
            if (!in_array($cmd_class, ['ilobjtestsettingsmaingui', 'iltestscoringgui'])) {
                return;
            }

            $ref_id = (int) ($_GET['ref_id'] ?? 0);
            if ($ref_id <= 0) {
                return;
            }

            // NEUE SICHTBARKEITSPRÜFUNG
            if (!$this->shouldShowTab($ref_id)) {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] Tab ausgeblendet für ref_id: $ref_id (Sichtbarkeitseinstellung)");
                }
                return;
            }

            // Tab-Link generieren
            $ilCtrl->setParameterByClass('ilUIPluginRouterGUI', 'ref_id', $ref_id);
            $ilCtrl->setParameterByClass('ilExtendedTestSettingsAdvancedGUI', 'ref_id', $ref_id);

            $link = $ilCtrl->getLinkTargetByClass(
                ['ilUIPluginRouterGUI', 'ilExtendedTestSettingsAdvancedGUI'],
                'show'
            );

            $ilTabs->addSubTab(
                'extended_test_settings',
                'Erweiterte Test-Einstellungen',
                $link
            );

            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] ✅ Tab hinzugefügt für ref_id: $ref_id");
            }
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] Tab-Fehler: " . $e->getMessage());
            }
        }
    }

    /**
     * Prüft ob der Tab angezeigt werden soll basierend auf Sichtbarkeitseinstellungen
     */
    private function shouldShowTab(int $ref_id): bool
    {
        global $DIC, $rbacsystem;

        $user_id = $DIC->user()->getId();

        // System-Admin sieht immer den Tab
        if ($rbacsystem->checkAccess("write", SYSTEM_FOLDER_ID)) {
            return true;
        }

        // Lade Sichtbarkeitseinstellung
        $settings = new ilSetting('extendedtestsettings');
        $visibility = $settings->get('tab_visibility', 'course_admins');

        // Nur System-Admins?
        if ($visibility === 'admins_only') {
            return false;
        }

        // Nur spezifische Benutzer?
        if ($visibility === 'specific_users') {
            return $this->isUserAuthorized($user_id);
        }

        // Standard: Kurs-Admins + System-Admins
        if ($rbacsystem->checkAccess('write', $ref_id)) {
            return true;
        }

        return $this->isUserCourseAdmin($ref_id);
    }

    /**
     * Prüft ob ein Benutzer explizit berechtigt ist
     */
    private function isUserAuthorized(int $user_id): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $result = $db->queryF(
                "SELECT user_id FROM extendedtest_users WHERE user_id = %s",
                ['integer'],
                [$user_id]
            );

            return $db->numRows($result) > 0;
        } catch (Exception $e) {
            error_log("[ExtendedTestSettings] Fehler bei Berechtigungsprüfung: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Prüft ob der Benutzer Kurs-Administrator des übergeordneten Kurses ist
     */
    private function isUserCourseAdmin(int $test_ref_id): bool
    {
        global $DIC;

        try {
            $tree = $DIC->repositoryTree();
            $user_id = $DIC->user()->getId();

            // Finde übergeordneten Kurs
            $path = $tree->getNodePath($test_ref_id);

            foreach ($path as $node) {
                if ($node['type'] === 'crs') {
                    // Prüfe ob User Kurs-Admin ist
                    include_once './Modules/Course/classes/class.ilCourseParticipants.php';
                    $obj_id = ilObject::_lookupObjectId($node['ref_id']);
                    $participants = ilCourseParticipants::_getInstanceByObjId($obj_id);

                    if ($participants->isAdmin($user_id) || $participants->isTutor($user_id)) {
                        return true;
                    }
                }
            }
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] Fehler bei Kurs-Admin-Prüfung: " . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * Injiziert Assets von aktiven Modulen über Handler
     */
    private function injectModuleAssets(ilGlobalTemplateInterface $tpl): void
    {
        try {
            $current_ref_id = (int) ($_GET["ref_id"] ?? 0);

            if ($current_ref_id <= 0) {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] Asset-Injection: Keine ref_id");
                }
                return;
            }

            // Prüfe Testplayer-Kontext
            if (!$this->isTestPlayerContext()) {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] Nicht im Testplayer-Kontext");
                }
                return;
            }

            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] ✅ Testplayer-Kontext erkannt - lade Module über Handler");

                // Debug-Box nur im Debug-Modus
                $tpl->addOnLoadCode("
                    console.log('[ExtendedTestSettings] Asset-Injection gestartet für ref_id: $current_ref_id');
                    
                    // Debug-Box
                    const debugDiv = document.createElement('div');
                    debugDiv.style.cssText = 'position:fixed;top:10px;right:10px;background:rgba(0,0,0,0.9);color:#0f0;padding:10px;z-index:99999;font-family:monospace;font-size:11px;max-width:300px;border-radius:5px;';
                    debugDiv.innerHTML = '[ExtendedTestSettings] Loading...';
                    document.body.appendChild(debugDiv);
                    window.etsDebug = debugDiv;
                ");
            }

            // ExamCalc über Handler laden
            $examcalc_handler = $this->loadExamCalcHandler();
            if ($examcalc_handler && $examcalc_handler->isEnabled($current_ref_id)) {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ✅ ExamCalc aktiviert - delegiere an Handler");
                    $tpl->addOnLoadCode("window.etsDebug.innerHTML += '<br>✅ ExamCalc: Handler aufgerufen';");
                }

                $examcalc_handler->injectAssets($tpl, $current_ref_id);
            } else {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ExamCalc nicht aktiviert oder Handler fehlt");
                    $tpl->addOnLoadCode("window.etsDebug && (window.etsDebug.innerHTML += '<br>❌ ExamCalc: Nicht aktiviert');");
                }
            }

            // ExamSymbols über Handler laden
            $examsymbols_handler = $this->loadExamSymbolsHandler();
            if ($examsymbols_handler && $examsymbols_handler->isEnabled($current_ref_id)) {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ✅ ExamSymbols aktiviert - delegiere an Handler");
                    $tpl->addOnLoadCode("window.etsDebug.innerHTML += '<br>✅ ExamSymbols: Handler aufgerufen';");
                }

                $examsymbols_handler->injectAssets($tpl, $current_ref_id);
            } else {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ExamSymbols nicht aktiviert oder Handler fehlt");
                    $tpl->addOnLoadCode("window.etsDebug && (window.etsDebug.innerHTML += '<br>❌ ExamSymbols: Nicht aktiviert');");
                }
            }

            // ExamGuard über Handler laden
            $examguard_handler = $this->loadExamGuardHandler();
            if ($examguard_handler && $examguard_handler->isEnabled($current_ref_id)) {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ✅ ExamGuard aktiviert - delegiere an Handler");
                    $tpl->addOnLoadCode("window.etsDebug.innerHTML += '<br>✅ ExamGuard: Handler aufgerufen';");
                }

                $examguard_handler->injectAssets($tpl, $current_ref_id);
            } else {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ExamGuard nicht aktiviert oder Handler fehlt");
                    $tpl->addOnLoadCode("window.etsDebug && (window.etsDebug.innerHTML += '<br>❌ ExamGuard: Nicht aktiviert');");
                }
            }

            // ExamAlert über Handler laden
            $examalert_handler = $this->loadExamAlertHandler();
            if ($examalert_handler && $examalert_handler->isEnabled($current_ref_id)) {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ✅ ExamAlert aktiviert - delegiere an Handler");
                    $tpl->addOnLoadCode("window.etsDebug.innerHTML += '<br>✅ ExamAlert: Handler aufgerufen';");
                }

                $examalert_handler->injectAssets($tpl, $current_ref_id);
            } else {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ExamAlert nicht aktiviert oder Handler fehlt");
                    $tpl->addOnLoadCode("window.etsDebug && (window.etsDebug.innerHTML += '<br>❌ ExamAlert: Nicht aktiviert');");
                }
            }

            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] Asset-Injection abgeschlossen");
                $tpl->addOnLoadCode("
                    window.etsDebug.innerHTML += '<br>✅ Alle Module verarbeitet';
                    setTimeout(() => {
                        if (window.etsDebug) {
                            window.etsDebug.style.opacity = '0.3';
                        }
                    }, 3000);
                ");
            }
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] Asset-Injection Fehler: " . $e->getMessage());
                $tpl->addOnLoadCode("window.etsDebug && (window.etsDebug.innerHTML += '<br>❌ FEHLER: " . $e->getMessage() . "');");
            }
        }
    }

    /**
     * Lädt ExamCalc Handler
     */
    private function loadExamCalcHandler(): ?ilExamCalcHandler
    {
        $handler_path = __DIR__ . "/ExamCalc/class.ilExamCalcHandler.php";

        if (!file_exists($handler_path)) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] ExamCalc Handler nicht gefunden: $handler_path");
            }
            return null;
        }

        require_once $handler_path;
        return new ilExamCalcHandler($this->getPluginObject()->getDirectory());
    }

    /**
     * Lädt ExamSymbols Handler
     */
    private function loadExamSymbolsHandler(): ?ilExamSymbolsHandler
    {
        $handler_path = __DIR__ . "/ExamSymbols/class.ilExamSymbolsHandler.php";
        $config_path = __DIR__ . "/ExamSymbols/class.ilExamSymbolsConfig.php";

        if (!file_exists($handler_path) || !file_exists($config_path)) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] ExamSymbols Handler oder Config nicht gefunden");
            }
            return null;
        }

        require_once $config_path;
        require_once $handler_path;
        return new ilExamSymbolsHandler($this->getPluginObject()->getDirectory());
    }

    /**
     * Lädt ExamGuard Handler
     */
    private function loadExamGuardHandler(): ?ilExamGuardHandler
    {
        $handler_path = __DIR__ . "/ExamGuard/class.ilExamGuardHandler.php";

        if (!file_exists($handler_path)) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] ExamGuard Handler nicht gefunden: $handler_path");
            }
            return null;
        }

        require_once $handler_path;
        return new ilExamGuardHandler($this->getPluginObject()->getDirectory());
    }

    /**
     * Lädt ExamAlert Handler
     */
    private function loadExamAlertHandler(): ?ilExamAlertHandler
    {
        $handler_path = __DIR__ . "/ExamAlert/class.ilExamAlertHandler.php";

        if (!file_exists($handler_path)) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] ExamAlert Handler nicht gefunden: $handler_path");
            }
            return null;
        }

        require_once $handler_path;
        return new ilExamAlertHandler($this->getPluginObject()->getDirectory());
    }

    /**
     * Prüft ob wir uns im Testplayer-Kontext befinden
     */
    private function isTestPlayerContext(): bool
    {
        $cmd_class = strtolower($_GET["cmdClass"] ?? "");
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        // Testplayer-Klassen
        $test_player_classes = [
            'iltestplayer',
            'iltestplayerfixedquestionsetgui',
            'iltestplayerrandquestionsetgui',
            'iltestplayergui',
            'iltestplayercommandsgui',
            'iltestoutputgui'
        ];

        foreach ($test_player_classes as $test_class) {
            if (str_contains($cmd_class, $test_class)) {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ✅ Testplayer-Match: '$cmd_class' enthält '$test_class'");
                }
                return true;
            }
        }

        // URL-Pattern prüfen
        $url_patterns = ['cmd=startPlayer', 'cmd=resumePlayer', 'cmd=showQuestion', 'cmd=nextQuestion'];

        foreach ($url_patterns as $pattern) {
            if (str_contains($request_uri, $pattern)) {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ✅ URL-Pattern-Match: '$pattern'");
                }
                return true;
            }
        }

        if (self::DEBUG_ENABLED) {
            error_log("[ExtendedTestSettings] ❌ Kein Testplayer-Kontext - cmdClass: '$cmd_class'");
        }
        return false;
    }

    // === METHODEN FÜR SETTINGS GUI ===

    public function getAvailableModules(): array
    {
        return ['examcalc', 'examsymbols', 'examguard', 'examalert'];
    }

    public function saveAllModuleSettings(int $ref_id, array $post_data): array
    {
        $results = [];

        // ExamCalc über Handler speichern
        $examcalc_handler = $this->loadExamCalcHandler();
        if ($examcalc_handler) {
            $results['examcalc'] = $examcalc_handler->saveSettings($ref_id, $post_data);
        }

        // ExamSymbols über Handler speichern
        $examsymbols_handler = $this->loadExamSymbolsHandler();
        if ($examsymbols_handler) {
            $results['examsymbols'] = $examsymbols_handler->saveSettings($ref_id, $post_data);
        }

        // ExamGuard über Handler speichern
        $examguard_handler = $this->loadExamGuardHandler();
        if ($examguard_handler) {
            $results['examguard'] = $examguard_handler->saveSettings($ref_id, $post_data);
        }

        // ExamAlert über Handler speichern
        $examalert_handler = $this->loadExamAlertHandler();
        if ($examalert_handler) {
            $results['examalert'] = $examalert_handler->saveSettings($ref_id, $post_data);
        }

        return $results;
    }
}
