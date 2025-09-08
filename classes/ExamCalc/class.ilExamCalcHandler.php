<?php
/**
 * ExamCalc Handler - Komplette Logic für Taschenrechner-Integration
 * Part of ExtendedTestSettings Plugin
 */

class ilExamCalcHandler
{
    private string $plugin_path;
    
    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;
    }

    /**
     * Prüft ob ExamCalc für den Test aktiviert ist
     */
    public function isEnabled(int $ref_id): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);
            
            if ($test_id <= 0) {
                return false;
            }

            $query = "SELECT enabled FROM examcalc_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return (bool) $row['enabled'];
            }

            return false;

        } catch (Exception $e) {
            error_log("[ExamCalc] Fehler beim Prüfen der Aktivierung: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Prüft ob aktueller Kontext ein Testplayer ist
     */
    public function isTestPlayerContext(): bool
    {
        $cmd_class = strtolower($_GET["cmdClass"] ?? "");
        
        // Testplayer-Klassen
        $test_player_classes = [
            'iltestplayer',
            'iltestplayergui', 
            'iltestplayercommandsgui',
            'iltestoutputgui'
        ];

        foreach ($test_player_classes as $test_class) {
            if (str_contains($cmd_class, $test_class)) {
                return true;
            }
        }

        // URL-Pattern prüfen
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_contains($request_uri, 'cmd=startPlayer') || 
            str_contains($request_uri, 'cmd=resumePlayer') ||
            str_contains($request_uri, 'cmdClass=ilTestPlayer')) {
            return true;
        }

        return false;
    }

    /**
     * Injiziert alle nötigen Assets (CSS, JS, HTML) in Template
     */
    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
{
    if (!$this->isEnabled($ref_id) || !$this->isTestPlayerContext()) {
        return;
    }

    // Debug-Logging vorbereiten
    $tpl->addOnLoadCode("
        if (typeof window.ecLog === 'undefined') {
            window.ecLog = function(msg) { 
                console.log('[ExamCalc] ' + msg); 
            };
        }
        ecLog('🧮 ExamCalc wird geladen für ref_id: $ref_id');
    ");

    // CSS einbinden
    $tpl->addCss("./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExtendedTestSettings/css/examcalc.css");

    // JavaScript einbinden
    $tpl->addJavaScript("./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExtendedTestSettings/js/examcalc/calculator.js");
    $tpl->addJavaScript("./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExtendedTestSettings/js/examcalc/examcalc-loader.js");

    // Template-URL bereitstellen
    $template_path = "./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExtendedTestSettings/templates/calculator_overlay.html";
    $tpl->addOnLoadCode("
        window.ExamCalc = window.ExamCalc || {};
        window.ExamCalc.templatePath = '$template_path';
        ecLog('📦 Template-Pfad gesetzt: ' + window.ExamCalc.templatePath);
    ");
}



    /**
     * Speichert ExamCalc-Einstellungen
     */
    public function saveSettings(int $ref_id, array $post_data): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $obj_id = ilObject::_lookupObjectId($ref_id);
            $enabled = isset($post_data['examcalc_enabled']) ? 1 : 0;

            $db->replace('examcalc_settings', [
                'test_id' => ['integer', $obj_id]
            ], [
                'enabled' => ['integer', $enabled]
            ]);

            return true;

        } catch (Exception $e) {
            error_log("[ExamCalc] Fehler beim Speichern: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generiert HTML für Einstellungs-Formular
     */
    public function getSettingsHTML(int $ref_id): string
    {
        $enabled = $this->isEnabled($ref_id) ? 'checked' : '';
        
        return <<<HTML
            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" name="examcalc_enabled" value="1" $enabled style="transform: scale(1.2);">
                    <strong>Taschenrechner aktivieren</strong>
                </label>
                <p style="margin: 0.5rem 0 0 1.7rem; color: #666; font-size: 0.9rem;">
                    Stellt einen wissenschaftlichen Taschenrechner während der Prüfung zur Verfügung.
                    <br><em>Shortcut: Alt + R</em>
                </p>
            </div>
HTML;
    }

    /**
     * Gibt Plugin-Info zurück
     */
    public function getInfo(): array
    {
        return [
            'name' => 'ExamCalc',
            'title' => 'Wissenschaftlicher Taschenrechner',
            'icon' => '🧮',
            'description' => 'Wissenschaftlicher Taschenrechner für Prüfungen mit Trigonometrie, Wurzeln, Potenzen etc.',
            'version' => '0.0.6',
            'features' => [
                'Floating Button (Alt + R)',
                'Wissenschaftliche Funktionen',
                'Drag & Drop (geplant)',
                'Verlaufspeicher '
            ]
        ];
    }
}