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
        if (
            str_contains($request_uri, 'cmd=startPlayer') ||
            str_contains($request_uri, 'cmd=resumePlayer') ||
            str_contains($request_uri, 'cmdClass=ilTestPlayer')
        ) {
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

        $calculator_mode = $this->getCalculatorMode($ref_id);

        $css_path = $this->plugin_path . "/css/examcalc.css";
        $tpl->addCss($css_path);

        $template_filename = ($calculator_mode === 'simple')
            ? 'calculator_simple.html'
            : 'calculator_overlay.html';

        if ($calculator_mode === 'simple') {
            $js_calc_path = $this->plugin_path . "/js/examcalc/calculator-simple.js";
        } else {
            $js_calc_path = $this->plugin_path . "/js/examcalc/calculator.js";
        }

        $js_loader_path = $this->plugin_path . "/js/examcalc/examcalc-loader.js";

        $tpl->addJavaScript($js_calc_path);
        $tpl->addJavaScript($js_loader_path);

        $template_path = $this->plugin_path . "/templates/$template_filename";

        $tpl->addOnLoadCode("
            window.ExamCalc = window.ExamCalc || {};
            window.ExamCalc.templatePath = '$template_path';
            window.ExamCalc.calculatorMode = '$calculator_mode';
        ");
    }

    /**
     * Prüft welcher Modus aktiv ist
     */
    public function getCalculatorMode(int $ref_id): string
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);

            if ($test_id <= 0) {
                return 'simple';
            }

            $query = "SELECT calculator_mode FROM examcalc_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return $row['calculator_mode'] ?? 'simple';
            }

            return 'simple';
        } catch (Exception $e) {
            error_log("[ExamCalc] Fehler beim Laden des Modus: " . $e->getMessage());
            return 'simple';
        }
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
            $calculator_mode = $post_data['examcalc_mode'] ?? 'simple';

            $db->replace('examcalc_settings', [
                'test_id' => ['integer', $obj_id]
            ], [
                'enabled' => ['integer', $enabled],
                'calculator_mode' => ['text', $calculator_mode]
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
        $current_mode = $this->getCalculatorMode($ref_id);

        $simple_selected = ($current_mode === 'simple') ? 'selected' : '';
        $advanced_selected = ($current_mode === 'advanced') ? 'selected' : '';

        return <<<HTML
        <div class="form-group">
            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                <input type="checkbox" name="examcalc_enabled" value="1" $enabled style="transform: scale(1.2);">
                <strong>Taschenrechner aktivieren</strong>
            </label>
            <p style="margin: 0.5rem 0 1rem 1.7rem; color: #666; font-size: 0.9rem;">
                Stellt einen Taschenrechner während der Prüfung zur Verfügung.
                <br><em>Shortcut: Alt + R</em>
            </p>

            <label for="examcalc_mode" style="display: block; font-weight: bold; margin-bottom: 0.5rem;">
                Taschenrechner-Typ:
            </label>
            <select name="examcalc_mode" id="examcalc_mode"
                    style="width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px;">
                <option value="simple" $simple_selected>🔢 Einfach (Grundrechenarten) - Standard</option>
                <option value="advanced" $advanced_selected>🧮 Wissenschaftlich (🚧 Work in Progress)</option>
            </select>
            <p style="margin: 0.5rem 0 0 0; color: #666; font-size: 0.9rem;">
                <strong>Einfach:</strong> Nur + − × ÷ (empfohlen)<br>
                <strong>Wissenschaftlich:</strong> sin, cos, tan, √, ^, log, ln, π, e, !, %, etc. 
                <span style="background: #fff3cd; padding: 0.15rem 0.4rem; border-radius: 3px; color: #856404; font-size: 0.8rem;">
                    ⚠️ Noch in Entwicklung - kann Fehler enthalten
                </span>
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
            'description' => 'Taschenrechner für Prüfungen mit zwei Modi: Einfach (Grundrechenarten) und Wissenschaftlich',
            'version' => '1.0.0',
            'features' => [
                'Floating Button (Alt + R)',
                'Einfacher Modus (Grundrechenarten)',
                'Wissenschaftlicher Modus (Beta)',
                'Drag & Drop',
                'Verlaufspeicher (nur wissenschaftlich)'
            ]
        ];
    }
}