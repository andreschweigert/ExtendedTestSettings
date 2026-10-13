<?php
/**
 * SPDX-License-Identifier: AGPL-3.0-only
 * Original authors: André Schweigert and Cornel Musielak.
 * See LICENSE and NOTICE for license and attribution terms (AGPLv3, section 7(b)).
 */


/**
 * ExtendedTestSettings UI Hook GUI
 * Alle Module werden über die ilModuleRegistry geladen — kein hardcodierter Modul-Code hier.
 */
class ilExtendedTestSettingsUIHookGUI extends ilUIHookPluginGUI
{
    private const DEBUG_ENABLED = false;

    private ?ilModuleRegistry $registry = null;

    private function getRegistry(): ilModuleRegistry
    {
        if ($this->registry === null) {
            require_once __DIR__ . '/Module/ilModuleRegistry.php';
            $this->registry = ilModuleRegistry::forPlugin($this->getPluginObject());
        }
        return $this->registry;
    }

    /**
     * Modify GUI - Haupteinsprungspunkt
     */
    public function modifyGUI(string $component, string $part, array $params = []): void
    {
        try {
            global $ilTabs, $ilCtrl, $DIC;

            if ($part === 'sub_tabs') {
                $this->addSettingsTab($ilTabs, $ilCtrl, $part);
            }

            if ($DIC->offsetExists('tpl') && $DIC['tpl'] instanceof ilGlobalTemplateInterface) {
                $this->injectModuleAssets($DIC['tpl']);
            }

            $this->dispatchRequestToModules();
        } catch (\Throwable $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] modifyGUI Fehler: " . $e->getMessage());
            }
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

            // Nur im Haupt-Tab "Einstellungen" — unabhängig vom konkreten Sub-Tab/CmdClass.
            // Ersetzt die frühere CmdClass-Liste: in 10 sind die Settings-GUIs namespaced
            // und getCmdClass() liefert beim Tab-Rendering ggf. den Parent (ilObjTestGUI).
            // Tab-ID 'settings' ist in 9 und 10 identisch (TabsManager::TAB_ID_SETTINGS).
            $active_tab = $ilTabs->getActiveTab();

            if ($active_tab === '') {
                // Kein Tab explizit aktiviert: In ILIAS 10 ruft ilObjTestGUI im Case
                // SettingsScoringGUI kein activateTab(TAB_ID_SETTINGS) auf (anders als bei
                // SettingsMainGUI/MarkSchemaGUI) — der Haupt-Tab wird dort nur über das
                // cmd-Matching von addTarget() hervorgehoben, getActiveTab() bleibt leer.
                // Fallback: Klassenkette gegen die GUIs prüfen, für die der Core selbst
                // die Settings-Sub-Tabs baut (TabsManager::setupTabsGuiConfig).
                if (!$this->isTestSettingsClassPath($ilCtrl)) {
                    return;
                }
            } elseif ($active_tab !== 'settings') {
                return;
            }

            $ref_id = (int) ($_GET['ref_id'] ?? 0);
            if ($ref_id <= 0) {
                return;
            }

            // ... und nur an Test-Objekten (andere Objekttypen haben ebenfalls einen Tab "settings")
            if (ilObject::_lookupType($ref_id, true) !== 'tst') {
                return;
            }

            if (!$this->shouldShowTab($ref_id)) {
                return;
            }

            $ilCtrl->setParameterByClass('ilUIPluginRouterGUI', 'ref_id', $ref_id);
            $ilCtrl->setParameterByClass('ilExtendedTestSettingsAdvancedGUI', 'ref_id', $ref_id);

            $link = $ilCtrl->getLinkTargetByClass(
                ['ilUIPluginRouterGUI', 'ilExtendedTestSettingsAdvancedGUI'],
                'show'
            );

            $ilTabs->addSubTab('extended_test_settings', $this->getPluginObject()->txt('advanced_settings'), $link);

        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] Tab-Fehler: " . $e->getMessage());
            }
        }
    }

    /**
     * Fallback für Settings-GUIs, die den Haupt-Tab nicht explizit aktivieren.
     * Liste = die Klassen, für die der Core selbst die Settings-Sub-Tabs baut
     * (9: ilTestTabsManager, 10: TabsManager::setupTabsGuiConfig).
     */
    private function isTestSettingsClassPath($ilCtrl): bool
    {
        $settings_guis = [
            // ILIAS 9
            'ilobjtestsettingsmaingui',
            'ilobjtestsettingsscoringresultsgui',
            'ilmarkschemagui',
            // ILIAS 10 (namespaced)
            'ilias\test\settings\mainsettings\settingsmaingui',
            'ilias\test\settings\scorereporting\settingsscoringgui',
            'ilias\test\scoring\marks\markschemagui',
        ];

        $class_path = array_map('strtolower', $ilCtrl->getCurrentClassPath());

        return array_intersect($class_path, $settings_guis) !== [];
    }

    /**
     * Injiziert Assets aller aktiven Module — generische Schleife über Registry
     */
    private function injectModuleAssets(ilGlobalTemplateInterface $tpl): void
    {
        try {
            $current_ref_id = (int) ($_GET['ref_id'] ?? 0);

            if ($current_ref_id <= 0 || !$this->isTestPlayerContext()) {
                return;
            }

            foreach ($this->getRegistry()->getActiveHandlers() as $id => $handler) {
                try {
                    if ($handler->isEnabled($current_ref_id)) {
                        $handler->injectAssets($tpl, $current_ref_id);

                        if (self::DEBUG_ENABLED) {
                            error_log("[ExtendedTestSettings] Modul '$id' injiziert für ref_id $current_ref_id");
                        }
                    }
                } catch (\Throwable $e) {
                    error_log("[ExtendedTestSettings] Fehler in Modul '$id': " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] Asset-Injection Fehler: " . $e->getMessage());
            }
        }
    }

    /**
     * Speichert Einstellungen aller aktiven Module (für AdvancedGUI)
     */
    public function saveAllModuleSettings(int $ref_id, array $post_data): array
    {
        $results = [];

        foreach ($this->getRegistry()->getActiveHandlers() as $id => $handler) {
            try {
                $results[$id] = $handler->saveSettings($ref_id, $post_data);
            } catch (\Throwable $e) {
                error_log("[ExtendedTestSettings] Fehler beim Speichern von Modul '$id': " . $e->getMessage());
                $results[$id] = false;
            }
        }

        return $results;
    }

    /**
     * Gibt Formular-Elemente aller aktiven Module zurück (für AdvancedGUI)
     */
    public function getModuleFormItems(int $ref_id): array
    {
        $items = [];

        foreach ($this->getRegistry()->getActiveHandlers() as $id => $handler) {
            try {
                foreach ($handler->getFormItems($ref_id) as $item) {
                    $items[] = $item;
                }
            } catch (\Throwable $e) {
                error_log("[ExtendedTestSettings] Fehler beim Laden von Formular-Items für '$id': " . $e->getMessage());
            }
        }

        return $items;
    }

    /**
     * Gibt die IDs aller aktiven Module zurück
     */
    public function getAvailableModules(): array
    {
        return array_keys($this->getRegistry()->getActiveHandlers());
    }

    // -------------------------------------------------------------------------
    // Zugriffsprüfungen (unverändert)
    // -------------------------------------------------------------------------

    private function shouldShowTab(int $ref_id): bool
    {
        global $DIC, $rbacsystem;

        if ($rbacsystem->checkAccess('write', SYSTEM_FOLDER_ID)) {
            return true;
        }

        $settings   = new ilSetting('extendedtestsettings');
        $visibility = $settings->get('tab_visibility', 'course_admins');

        if ($visibility === 'admins_only') {
            return false;
        }

        if ($visibility === 'specific_users') {
            // Whitelist = Sichtbarkeit des Features, nicht Zugriff auf jeden Test.
            // Der Tab erscheint nur, wenn zusätzlich ein Recht am Test besteht (R1) —
            // konsistent zur Prüfung in der AdvancedGUI.
            return $this->isUserAuthorized($DIC->user()->getId())
                && ($rbacsystem->checkAccess('write', $ref_id) || $this->isUserCourseAdmin($ref_id));
        }

        if ($rbacsystem->checkAccess('write', $ref_id)) {
            return true;
        }

        return $this->isUserCourseAdmin($ref_id);
    }

    private function isUserAuthorized(int $user_id): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $result = $db->queryF(
                'SELECT user_id FROM extendedtest_users WHERE user_id = %s',
                ['integer'],
                [$user_id]
            );
            return $db->numRows($result) > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    private function isUserCourseAdmin(int $test_ref_id): bool
    {
        global $DIC;

        try {
            $tree    = $DIC->repositoryTree();
            $user_id = $DIC->user()->getId();
            $path    = $tree->getNodePath($test_ref_id);

            foreach ($path as $node) {
                if ($node['type'] === 'crs') {
                    $obj_id      = ilObject::_lookupObjectId($node['ref_id']);
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
     * Ruft onRequest() auf allen aktiven Modulen auf, die diese optionale Methode implementieren.
     * Eingeschränkt auf Test-relevante cmdClasses um unnötige Aufrufe zu vermeiden.
     */
    private function dispatchRequestToModules(): void
    {
        static $dispatched = false;
        if ($dispatched) {
            return;
        }
        $dispatched = true;

        $ref_id   = (int)($_GET['ref_id'] ?? 0);
        $cmdClass = strtolower((string)($_REQUEST['cmdClass'] ?? ''));

        if ($ref_id <= 0 || $cmdClass === '') {
            return;
        }

        $testContextFragments = [
            'iltestoutput', 'iltestplayer', 'ilobjtestsettings', 'iltestscoring', 'ilobjtest',
            'iltestparticipants',
        ];

        $isTestContext = false;
        foreach ($testContextFragments as $fragment) {
            if (str_contains($cmdClass, $fragment)) {
                $isTestContext = true;
                break;
            }
        }

        if (!$isTestContext) {
            return;
        }

        foreach ($this->getRegistry()->getActiveHandlers() as $handler) {
            if (method_exists($handler, 'onRequest')) {
                try {
                    $handler->onRequest($ref_id);
                } catch (\Throwable $e) {
                    error_log('[ExtendedTestSettings] onRequest-Fehler: ' . $e->getMessage());
                }
            }
        }
    }

    private function isTestPlayerContext(): bool
    {
        $cmd_class   = strtolower($_GET['cmdClass'] ?? '');
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        $player_classes = [
            'iltestplayer',
            'iltestplayerfixedquestionsetgui',
            'iltestplayerrandquestionsetgui',
            'iltestplayergui',
            'iltestplayercommandsgui',
            'iltestoutputgui',
        ];

        foreach ($player_classes as $cls) {
            if (str_contains($cmd_class, $cls)) {
                return true;
            }
        }

        $url_patterns = ['cmd=startPlayer', 'cmd=resumePlayer', 'cmd=showQuestion', 'cmd=nextQuestion'];

        foreach ($url_patterns as $pattern) {
            if (str_contains($request_uri, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
