<?php
/**
 * ExamSymbols Handler - Komplette Logic für Sonderzeichen-Integration
 * Part of ExtendedTestSettings Plugin
 */

class ilExamSymbolsHandler
{
    private string $plugin_path;
    
    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;
    }

    /**
     * Prüft ob ExamSymbols für den Test aktiviert ist
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

            $query = "SELECT enabled FROM examsymbols_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return (bool) $row['enabled'];
            }

            return false;

        } catch (Exception $e) {
            error_log("[ExamSymbols] Fehler beim Prüfen der Aktivierung: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Prüft ob aktueller Kontext ein Testplayer ist
     */
    public function isTestPlayerContext(): bool
    {
        $cmd_class = strtolower($_GET["cmdClass"] ?? "");
        
        // Testplayer-Klassen (identisch zu ExamCalc)
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

        // Debug-Logging
        $tpl->addOnLoadCode("
            if (typeof window.esLog === 'undefined') {
                window.esLog = function(msg) { 
                    console.log('[ExamSymbols] ' + msg); 
                };
            }
            esLog('📝 ExamSymbols wird geladen für ref_id: $ref_id');
        ");

        // Template und Symbole laden
        $template = $this->getSymbolTemplate($ref_id);
        $symbols = $this->getSymbolsForTemplate($template);

        // CSS laden
        $css_path = $this->plugin_path . "/css/examsymbols.css";
        $tpl->addCss($css_path);

        // JavaScript laden
        $js_symbols_path = $this->plugin_path . "/js/examsymbols/symbols-manager.js";
        $js_loader_path = $this->plugin_path . "/js/examsymbols/examsymbols-loader.js";
        
        $tpl->addJavaScript($js_symbols_path);
        $tpl->addJavaScript($js_loader_path);

        // Template-Pfad und Konfiguration als JavaScript-Variable bereitstellen
        $template_path = ilExamSymbolsPlugin::getInstance()->getPluginWebPath() . "/templates/symbols_overlay.html";
        $symbols_json = json_encode($symbols);
        $templates_json = json_encode($this->getAvailableTemplates());
        
        $tpl->addOnLoadCode("
            window.ExamSymbols = window.ExamSymbols || {};
            window.ExamSymbols.templatePath = '$template_path';
            window.ExamSymbols.config = {
                template: '$template',
                refId: $ref_id,
                symbols: $symbols_json,
                availableTemplates: $templates_json
            };
            esLog('✅ ExamSymbols Assets geladen - Template: $template mit ' + " . count($symbols) . " + ' Symbolen');
        ");
    }

    /**
     * Speichert ExamSymbols-Einstellungen
     */
    public function saveSettings(int $ref_id, array $post_data): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $obj_id = ilObject::_lookupObjectId($ref_id);
            $enabled = isset($post_data['examsymbols_enabled']) ? 1 : 0;
            $template = $post_data['examsymbols_template'] ?? 'math';

            $db->replace('examsymbols_settings', [
                'test_id' => ['integer', $obj_id]
            ], [
                'enabled' => ['integer', $enabled],
                'template' => ['text', $template]
            ]);

            return true;

        } catch (Exception $e) {
            error_log("[ExamSymbols] Fehler beim Speichern: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generiert HTML für Einstellungs-Formular
     */
    public function getSettingsHTML(int $ref_id): string
    {
        $enabled = $this->isEnabled($ref_id) ? 'checked' : '';
        $current_template = $this->getSymbolTemplate($ref_id);
        $options = '';

        foreach ($this->getAvailableTemplates() as $key => $data) {
            $selected = $key === $current_template ? 'selected' : '';
            $label = htmlspecialchars($data['label'], ENT_QUOTES);
            $options .= "<option value=\"$key\" $selected>$label</option>";
        }
        
        return <<<HTML
            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" name="examsymbols_enabled" value="1" $enabled style="transform: scale(1.2);">
                    <strong>Sonderzeichen-Palette aktivieren</strong>
                </label>
                <p style="margin: 0.5rem 0 1rem 1.7rem; color: #666; font-size: 0.9rem;">
                    Aktiviert die Spezialzeichen-Auswahl für diesen Test.
                    <br><em>Shortcut: Alt + S</em>
                </p>

                <label for="examsymbols_template" style="display: block; font-weight: bold; margin-bottom: 0.5rem;">
                    Symbol-Vorlage wählen:
                </label>
                <select name="examsymbols_template" id="examsymbols_template"
                        style="width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px;">
                    $options
                </select>
                <p style="margin: 0.5rem 0 0 0; color: #666; font-size: 0.9rem;">
                    Wähle eine Vorlage für die Spezialzeichen, die im Test eingeblendet werden sollen.
                </p>
            </div>
HTML;
    }

    /**
     * Lädt das Symbol-Template für einen Test
     */
    private function getSymbolTemplate(int $ref_id): string
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);
            $query = "SELECT template FROM examsymbols_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return $row['template'] ?? 'math';
            }

        } catch (Exception $e) {
            error_log("[ExamSymbols] Fehler beim Laden des Templates: " . $e->getMessage());
        }

        return 'math'; // Default
    }

    /**
     * Verfügbare Symbol-Templates
     */
    public function getAvailableTemplates(): array
    {
        return [
            "math" => [
                "label" => "Mathematik/Wissenschaft",
                "symbols" => [
                    "π", "√", "∑", "∞", "≈", "≠", "≤", "≥", "±", "∆", 
                    "α", "β", "γ", "δ", "ε", "θ", "λ", "μ", "σ", "ω",
                    "²", "³", "°", "∫", "∂", "→", "↔", "∈", "∉", "∅"
                ]
            ],
            "spanish" => [
                "label" => "Spanisch", 
                "symbols" => [
                    // Reihe 1: Vokale groß mit Akzent (5 Tasten)
                    "Á", "É", "Í", "Ó", "Ú",
                    
                    // Reihe 2: Vokale klein mit Akzent (5 Tasten)  
                    "á", "é", "í", "ó", "ú",
                    
                    // Reihe 3: Ñ ñ ¿ ¡ (4 Tasten)
                    "Ñ", "ñ", "¿", "¡"
                ]
            ],
            "italian" => [
                "label" => "Italienisch",
                "symbols" => [
                    "à", "è", "ì", "ò", "ù", "é", "í", "ó", 
                    "À", "È", "Ì", "Ò", "Ù", "É", "Í", "Ó"
                ]
            ],
            "german" => [
                "label" => "Deutsch",
                "symbols" => ["ä", "ö", "ü", "ß", "Ä", "Ö", "Ü"]
            ],
            "greek" => [
                "label" => "Griechisch",
                "symbols" => [
                    "α", "β", "γ", "δ", "ε", "θ", "λ", "μ", "σ", "ω",
                    "Α", "Β", "Γ", "Δ", "Ε", "Θ", "Λ", "Μ", "Σ", "Ω"
                ]
            ]
        ];
    }

    /**
     * Symbole für ein bestimmtes Template laden
     */
    private function getSymbolsForTemplate(string $template): array
    {
        $templates = $this->getAvailableTemplates();
        return $templates[$template]["symbols"] ?? [];
    }

    /**
     * Gibt Plugin-Info zurück
     */
    public function getInfo(): array
    {
        return [
            'name' => 'ExamSymbols',
            'title' => 'Sonderzeichen-Palette',
            'icon' => '📝',
            'description' => 'Palette mit Sonderzeichen für verschiedene Fachbereiche (Mathematik, Sprachen, etc.)',
            'version' => '0.0.2',
            'features' => [
                'Floating Button (Alt + S)',
                'Multiple Templates (Math, Sprachen)', 
                'Rich-Text-Editor Support',
                'Focus-Tracking System',
                'Mobile Responsive'
            ]
        ];
    }
}