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
            $this->tpl->setContent('<div class="alert alert-danger">❌ Kein gültiger Test ausgewählt.</div>');
            return;
        }

        // Prüfe ob es ein Test-Objekt ist
        $obj_id = ilObject::_lookupObjectId($ref_id);
        if (ilObject::_lookupType($obj_id) !== 'tst') {
            $this->tpl->setContent('<div class="alert alert-danger">❌ Das ausgewählte Objekt ist kein Test.</div>');
            return;
        }

        // Debug-Informationen (optional ein/ausblenden)
        $show_debug = isset($_GET['debug']) && $_GET['debug'] === '1';
        $debug_info = $show_debug ? $this->generateDebugInfo($ref_id, $obj_id) : '';

        // Navigation einrichten
        $this->setupNavigation($ref_id);

        // Formular generieren
        $html = $debug_info . $this->generateCompleteForm($ref_id);

        $this->tpl->setTitle("Erweiterte Test-Einstellungen");
        $this->tpl->setDescription("Konfiguration für alle verfügbaren Prüfungs-Features");
        $this->tpl->setContent($html);
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
     * Generiert das komplette Formular mit allen Modulen
     */
    private function generateCompleteForm(int $ref_id): string
    {
        $form_action = "ilias.php?baseClass=iluipluginroutergui&cmdClass=ilExtendedTestSettingsAdvancedGUI&cmd=saveSettings&ref_id=$ref_id";

        // Header-Info
        $header_info = $this->generateHeaderInfo();

        // Module-Sektionen generieren
        $modules_html = $this->generateAllModuleSections($ref_id);

        // Debug-Link
        $debug_link = !isset($_GET['debug']) || $_GET['debug'] !== '1'
            ? "<p style='text-align: right; margin-top: 1rem;'><a href='{$_SERVER['REQUEST_URI']}&debug=1' style='color: #6c757d; font-size: 0.9em;'>🔍 Debug-Informationen anzeigen</a></p>"
            : '';

        return <<<HTML
<div class="exam-settings-container">
    $header_info

    <form method="post" action="$form_action" id="extended-test-settings-form">
        $modules_html
        
        <div class="form-actions" style="text-align: right; padding: 1.5rem 0; border-top: 1px solid #dee2e6; margin-top: 2rem;">
            <button type="submit" class="btn btn-primary" style="background: #0d6efd; color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 0.375rem; font-weight: 500; cursor: pointer; transition: all 0.2s;">
                💾 Einstellungen speichern
            </button>
        </div>
        
        <input type="hidden" name="ref_id" value="$ref_id">
    </form>
    
    $debug_link
</div>

<style>
.exam-settings-container {
    max-width: 1000px;
    margin: 0;
    padding: 0;
}

.btn:hover {
    background: #0b5ed7 !important;
    transform: translateY(-1px);
    box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075) !important;
}

.plugin-section {
    transition: all 0.15s ease;
    margin-bottom: 1.5rem;
    padding: 1.5rem;
    border: 1px solid #dee2e6;
    border-radius: 0.5rem;
    background: #ffffff;
}

.plugin-section:hover {
    transform: translateY(-1px);
    box-shadow: 0 0.125rem 0.5rem rgba(0,0,0,0.15);
    border-color: #0d6efd;
}
</style>

<script>
// Form-Verbesserungen
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('extended-test-settings-form');
    if (form) {
        // Loading-State für Submit-Button
        form.addEventListener('submit', function(e) {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerHTML = '⏳ Speichere...';
                submitBtn.style.background = '#6c757d';
                submitBtn.disabled = true;
            }
        });
        
        // Änderungen visuell markieren
        const inputs = form.querySelectorAll('input[type="checkbox"], select');
        inputs.forEach(input => {
            input.addEventListener('change', function() {
                const section = this.closest('.plugin-section');
                if (section && !section.classList.contains('changed')) {
                    section.style.borderLeftColor = '#ffc107';
                    section.style.borderLeftWidth = '4px';
                    section.classList.add('changed');
                }
            });
        });
    }
});
</script>
HTML;
    }

    /**
     * Header-Info generieren - Dezentes Design
     */
    private function generateHeaderInfo(): string
    {
        $feature_count = 0;
        try {
            $module_handlers = $this->loadModuleHandlersDirect();
            $feature_count = count($module_handlers);
        } catch (Exception $e) {
            // Fallback - jetzt sind es 4 Module
            $feature_count = 4; // ExamCalc + ExamSymbols + ExamGuard + ExamAlert
        }

        return <<<HTML
<div style="margin-bottom: 2rem; padding: 1.5rem; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
        <h4 style="margin: 0; color: #495057; font-size: 1.1rem;">⚙️ Erweiterte Test-Einstellungen</h4>
        <small style="color: #6c757d; background: #e9ecef; padding: 0.25rem 0.5rem; border-radius: 3px; font-weight: 500;">
            ExtendedTestSettings v1.0
        </small>
    </div>
    <p style="color: #6c757d; margin: 0; font-size: 0.9rem;">
        Hier können Sie spezielle Funktionen für diese Prüfung aktivieren. 
        <strong>$feature_count Module</strong> sind verfügbar und die Einstellungen gelten nur für diesen Test.
    </p>
</div>
HTML;
    }

    /**
     * Alle Modul-Sektionen generieren
     */
    private function generateAllModuleSections(int $ref_id): string
    {
        $html_sections = '';

        try {
            // Module-Handler direkt laden (ohne UIHookGUI)
            $module_handlers = $this->loadModuleHandlersDirect();

            if (empty($module_handlers)) {
                return $this->generateNoModulesMessage();
            }

            // Jedes verfügbare Modul durchgehen
            foreach ($module_handlers as $moduleName => $handler) {
                if (!$handler || !method_exists($handler, 'getInfo') || !method_exists($handler, 'getSettingsHTML')) {
                    continue;
                }

                $info = $handler->getInfo();
                $settings_html = $handler->getSettingsHTML($ref_id);

                $html_sections .= $this->generateModuleSection($info, $settings_html);
            }
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] Fehler beim Generieren der Module: " . $e->getMessage());
            }

            // Fallback: Manuelle Module
            $html_sections .= $this->generateFallbackModules($ref_id);
        }

        return $html_sections ?: $this->generateNoModulesMessage();
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
     * Einzelne Modul-Sektion generieren
     */
    private function generateModuleSection(array $info, string $settings_html): string
    {
        $title = htmlspecialchars($info['title'] ?? 'Unbekanntes Modul');
        $icon = $info['icon'] ?? '⚙️';
        $description = htmlspecialchars($info['description'] ?? '');
        $version = $info['version'] ?? '1.0';

        // Features-Liste (falls vorhanden)
        $features_html = '';
        if (!empty($info['features']) && is_array($info['features'])) {
            $features_html = '<div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e9ecef;"><small style="color: #6c757d; font-weight: 500;">Verfügbare Features:</small><ul style="margin: 0.5rem 0 0 1rem; color: #6c757d; font-size: 0.85rem;">';
            foreach ($info['features'] as $feature) {
                $features_html .= '<li style="margin-bottom: 0.15rem;">✓ ' . htmlspecialchars($feature) . '</li>';
            }
            $features_html .= '</ul></div>';
        }

        return <<<HTML
        <div class="plugin-section">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                <h3 style="margin: 0; color: #495057; display: flex; align-items: center; gap: 0.5rem; font-size: 1.1rem; font-weight: 600;">
                    <span style="font-size: 1.25rem;">$icon</span> 
                    $title
                </h3>
                <span style="background: #f8f9fa; color: #6c757d; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 500;">
                    $version
                </span>
            </div>
            
            <p style="color: #6c757d; margin-bottom: 1.25rem; font-size: 0.9rem; line-height: 1.4;">
                $description
            </p>
            
            <div style="background: #f8f9fa; padding: 1.25rem; border-radius: 0.375rem; border-left: 3px solid #0d6efd;">
                $settings_html
            </div>
            
            $features_html
        </div>
HTML;
    }

    /**
     * Fallback wenn keine Module gefunden
     */
    private function generateNoModulesMessage(): string
    {
        return <<<HTML
        <div style="text-align: center; padding: 3rem 2rem; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 0.5rem; color: #6c757d;">
            <h4 style="color: #495057; margin-bottom: 1rem;">⚠️ Keine Module verfügbar</h4>
            <p style="margin-bottom: 1.5rem;">Es wurden keine Prüfungs-Module gefunden. Stellen Sie sicher, dass die Handler-Klassen korrekt installiert sind:</p>
            <div style="text-align: left; display: inline-block; background: white; padding: 1rem; border-radius: 0.375rem; border: 1px solid #e9ecef;">
                <code style="display: block; margin-bottom: 0.5rem;">classes/ExamCalc/class.ilExamCalcHandler.php</code>
                <code style="display: block; margin-bottom: 0.5rem;">classes/ExamSymbols/class.ilExamSymbolsHandler.php</code>
                <code style="display: block; margin-bottom: 0.5rem;">classes/ExamGuard/class.ilExamGuardHandler.php</code>
                <code style="display: block;">classes/ExamAlert/class.ilExamAlertHandler.php</code>
            </div>
        </div>
HTML;
    }

    /**
     * Fallback Module (falls UIHookGUI nicht funktioniert)
     */
    /**
     * Fallback Module (falls UIHookGUI nicht funktioniert)
     */
    private function generateFallbackModules(int $ref_id): string
    {
        // ExamCalc Fallback
        $examcalc_data = $this->getFallbackExamCalcStatus($ref_id);
        $examcalc_checked = $examcalc_data['enabled'] ? 'checked' : '';
        $examcalc_mode = $examcalc_data['mode'];

        $simple_selected = ($examcalc_mode === 'simple') ? 'selected' : '';
        $advanced_selected = ($examcalc_mode === 'advanced') ? 'selected' : '';

        // ExamSymbols Fallback
        $examsymbols_data = $this->getFallbackExamSymbolsStatus($ref_id);
        $examsymbols_checked = $examsymbols_data['enabled'] ? 'checked' : '';
        $examsymbols_template = $examsymbols_data['template'];

        // ExamAlert Fallback - NEU
        $examalert_data = $this->getFallbackExamAlertStatus($ref_id);
        $examalert_checked = $examalert_data['enabled'] ? 'checked' : '';
        $examalert_time = $examalert_data['alert_time'];
        $examalert_message = htmlspecialchars($examalert_data['alert_message'], ENT_QUOTES);

        // Template-Optionen für ExamSymbols generieren
        $math_selected = ($examsymbols_template === 'math') ? 'selected' : '';
        $german_selected = ($examsymbols_template === 'german') ? 'selected' : '';
        $spanish_selected = ($examsymbols_template === 'spanish') ? 'selected' : '';

        return <<<HTML
        <div class="plugin-section">
            <h3 style="margin-top: 0; color: #495057; font-size: 1.1rem; font-weight: 600;">🧮 Taschenrechner</h3>
            <p style="color: #6c757d; margin-bottom: 1rem; font-size: 0.9rem;">Taschenrechner für Prüfungen</p>
            <div style="background: #f8f9fa; padding: 1.25rem; border-radius: 0.375rem;">
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="examcalc_enabled" value="1" $examcalc_checked style="transform: scale(1.1);">
                        <strong>Taschenrechner aktivieren</strong>
                    </label>
                </div>
                <div class="form-group">
                    <label for="examcalc_mode" style="display: block; font-weight: 500; margin-bottom: 0.5rem; color: #495057;">Taschenrechner-Typ:</label>
                    <select name="examcalc_mode" id="examcalc_mode" style="width: 100%; padding: 0.5rem; border: 1px solid #ced4da; border-radius: 0.375rem;">
                        <option value="simple" $simple_selected>🔢 Einfach (Grundrechenarten) - Standard</option>
                        <option value="advanced" $advanced_selected>🧮 Wissenschaftlich (🚧 WIP)</option>
                    </select>
                    <p style="margin: 0.5rem 0 0 0; color: #6c757d; font-size: 0.85rem;">
                        <strong>Einfach:</strong> Nur + − × ÷<br>
                        <strong>Wissenschaftlich:</strong> 
                        <span style="background: #fff3cd; padding: 0.15rem 0.4rem; border-radius: 3px; color: #856404;">
                            ⚠️ Noch in Entwicklung
                        </span>
                    </p>
                </div>
            </div>
        </div>

        <div class="plugin-section">
            <h3 style="margin-top: 0; color: #495057; font-size: 1.1rem; font-weight: 600;">📝 Sonderzeichen-Palette</h3>
            <p style="color: #6c757d; margin-bottom: 1rem; font-size: 0.9rem;">Palette mit Sonderzeichen für verschiedene Fachbereiche</p>
            <div style="background: #f8f9fa; padding: 1.25rem; border-radius: 0.375rem;">
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="examsymbols_enabled" value="1" $examsymbols_checked style="transform: scale(1.1);">
                        <strong>Sonderzeichen aktivieren</strong>
                    </label>
                </div>
                <div class="form-group">
                    <label for="examsymbols_template" style="display: block; font-weight: 500; margin-bottom: 0.5rem; color: #495057;">Vorlage wählen:</label>
                    <select name="examsymbols_template" id="examsymbols_template" style="width: 100%; padding: 0.5rem; border: 1px solid #ced4da; border-radius: 0.375rem;">
                        <option value="math" $math_selected>Mathematik/Wissenschaft</option>
                        <option value="german" $german_selected>Deutsch</option>
                        <option value="spanish" $spanish_selected>Spanisch</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="plugin-section">
            <h3 style="margin-top: 0; color: #495057; font-size: 1.1rem; font-weight: 600;">⏰ Zeit-Warnung</h3>
            <p style="color: #6c757d; margin-bottom: 1rem; font-size: 0.9rem;">Zeigt zeitbasierte Warnungen mit benutzerdefinierten Nachrichten während des Tests an</p>
            <div style="background: #f8f9fa; padding: 1.25rem; border-radius: 0.375rem;">
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="examalert_enabled" value="1" $examalert_checked style="transform: scale(1.1);">
                        <strong>Zeit-Warnung aktivieren</strong>
                    </label>
                </div>
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label for="examalert_time" style="display: block; font-weight: 500; margin-bottom: 0.5rem; color: #495057;">Warnung anzeigen:</label>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <input type="number" name="examalert_time" id="examalert_time" value="$examalert_time" min="5" max="3600" style="width: 80px; padding: 0.5rem; border: 1px solid #ced4da; border-radius: 0.375rem;">
                        <span>Sekunden vor Testende</span>
                    </div>
                </div>
                <div class="form-group">
                    <label for="examalert_message" style="display: block; font-weight: 500; margin-bottom: 0.5rem; color: #495057;">Nachricht:</label>
                    <textarea name="examalert_message" id="examalert_message" rows="3" placeholder="Achtung: Der Test endet bald!" style="width: 100%; padding: 0.5rem; border: 1px solid #ced4da; border-radius: 0.375rem; resize: vertical;">$examalert_message</textarea>
                </div>
            </div>
        </div>
HTML;
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
