<?php
/**
 * ExtendedTestSettings UI Hook GUI 
 * 
 */

class ilExtendedTestSettingsUIHookGUI extends ilUIHookPluginGUI
{
    // DEBUG-SCHALTER 
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

            // Tab-Link generieren
            $ilCtrl->setParameterByClass('ilUIPluginRouterGUI', 'ref_id', $ref_id);
            $ilCtrl->setParameterByClass('ilExtendedTestSettingsAdvancedGUI', 'ref_id', $ref_id);

            $link = $ilCtrl->getLinkTargetByClass(
                ['ilUIPluginRouterGUI', 'ilExtendedTestSettingsAdvancedGUI'],
                'show'
            );

            $ilTabs->addSubTab(
                'extended_test_settings',
                'Erweiterte Test-Einstellungen', // Direkt als Text statt Sprachschlüssel
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
     * Injiziert Assets von aktiven Modulen
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
                error_log("[ExtendedTestSettings] ✅ Testplayer-Kontext erkannt - lade Module");

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

            // ExamCalc versuchen
            $this->tryLoadExamCalc($tpl, $current_ref_id);

            // ExamSymbols versuchen
            $this->tryLoadExamSymbols($tpl, $current_ref_id);

            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] Asset-Injection abgeschlossen");
            }

        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] Asset-Injection Fehler: " . $e->getMessage());
            }
        }
    }

    /**
     * Versucht ExamCalc zu laden
     */
    private function tryLoadExamCalc(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        try {
            // Direkte Datenbankprüfung
            $enabled = $this->isExamCalcEnabledDirect($ref_id);

            if (!$enabled) {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ExamCalc nicht aktiviert");
                    $tpl->addOnLoadCode("window.etsDebug.innerHTML += '<br>❌ ExamCalc: Nicht aktiviert';");
                }
                return;
            }

            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] ✅ ExamCalc aktiviert - lade Assets");
                $tpl->addOnLoadCode("window.etsDebug.innerHTML += '<br>✅ ExamCalc: Aktiviert';");
            }

            // CSS und JavaScript laden
            $plugin_path = "./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExtendedTestSettings";

            $tpl->addCss($plugin_path . "/css/examcalc.css");
            $tpl->addJavaScript($plugin_path . "/js/examcalc/calculator.js");
            $tpl->addJavaScript($plugin_path . "/js/examcalc/examcalc-loader.js");

            // Template-Pfad bereitstellen
            $template_path = $plugin_path . "/templates/calculator_overlay.html";
            $tpl->addOnLoadCode("
                window.ExamCalc = window.ExamCalc || {};
                window.ExamCalc.templatePath = '$template_path';
            ");

            if (self::DEBUG_ENABLED) {
                $tpl->addOnLoadCode("
                    console.log('[ExamCalc] Assets geladen');
                    window.etsDebug.innerHTML += '<br>✅ ExamCalc: Assets geladen';
                ");
            }

        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] ExamCalc Fehler: " . $e->getMessage());
                $tpl->addOnLoadCode("window.etsDebug.innerHTML += '<br>❌ ExamCalc: Fehler';");
            }
        }
    }

    /**
     * Versucht ExamSymbols zu laden
     */
    private function tryLoadExamSymbols(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        try {
            // Direkte Datenbankprüfung
            $config = $this->getExamSymbolsConfigDirect($ref_id);

            if (!$config['enabled']) {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ExamSymbols nicht aktiviert");
                    $tpl->addOnLoadCode("window.etsDebug.innerHTML += '<br>❌ ExamSymbols: Nicht aktiviert';");
                }
                return;
            }

            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] ✅ ExamSymbols aktiviert - lade Assets");
                $tpl->addOnLoadCode("window.etsDebug.innerHTML += '<br>✅ ExamSymbols: Aktiviert';");
            }

            // CSS und JavaScript laden
            $plugin_path = "./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExtendedTestSettings";

            $tpl->addCss($plugin_path . "/css/examsymbols.css");
            $tpl->addJavaScript($plugin_path . "/js/examsymbols/symbols-manager.js");
            $tpl->addJavaScript($plugin_path . "/js/examsymbols/examsymbols-loader.js");

            // Template-Pfad und Konfiguration bereitstellen
            $template_path = $plugin_path . "/templates/symbols_overlay.html";
            $symbols = $this->getSymbolsForTemplate($config['template']);
            $symbols_json = json_encode($symbols);

            $tpl->addOnLoadCode("
                window.ExamSymbols = window.ExamSymbols || {};
                window.ExamSymbols.templatePath = '$template_path';
                window.ExamSymbols.config = {
                    template: '{$config['template']}',
                    refId: $ref_id,
                    symbols: $symbols_json,
                    keepOpen: true
                };
            ");

            if (self::DEBUG_ENABLED) {
                $tpl->addOnLoadCode("
                    console.log('[ExamSymbols] Assets geladen');
                    window.etsDebug.innerHTML += '<br>✅ ExamSymbols: Assets geladen';
                ");
            }

        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] ExamSymbols Fehler: " . $e->getMessage());
                $tpl->addOnLoadCode("window.etsDebug.innerHTML += '<br>❌ ExamSymbols: Fehler';");
            }
        }
    }

    /**
     * Direkte ExamCalc Datenbankprüfung
     */
    private function isExamCalcEnabledDirect(int $ref_id): bool
    {
        try {
            global $DIC;
            $db = $DIC->database();
            $test_id = ilObject::_lookupObjectId($ref_id);

            $query = "SELECT enabled FROM examcalc_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return (bool) $row['enabled'];
            }
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] ExamCalc DB-Check Fehler: " . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * Direkte ExamSymbols Datenbankprüfung
     */
    private function getExamSymbolsConfigDirect(int $ref_id): array
    {
        try {
            global $DIC;
            $db = $DIC->database();
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
                error_log("[ExtendedTestSettings] ExamSymbols DB-Check Fehler: " . $e->getMessage());
            }
        }

        return ['enabled' => false, 'template' => 'math'];
    }

    /**
     * Symbole für Template holen (inline)
     */
    private function getSymbolsForTemplate(string $template): array
    {
        $templates = [
            "math" => [
                "π",
                "√",
                "∑",
                "∞",
                "≈",
                "≠",
                "≤",
                "≥",
                "±",
                "∆",
                "∫",
                "∂",
                "→",
                "↔",
                "∈",
                "∉",
                "∅",
                "²",
                "³",
                "°",
                "α",
                "β",
                "γ",
                "δ",
                "ε",
                "θ",
                "λ",
                "μ",
                "σ",
                "ω"
            ],
            "spanish" => [
                "á",
                "é",
                "í",
                "ó",
                "ú",
                "ñ",
                "¿",
                "¡",
                "Á",
                "É",
                "Í",
                "Ó",
                "Ú",
                "Ñ",
                "ü",
                "Ü"
            ],
            "german" => ["ä", "ö", "ü", "ß", "Ä", "Ö", "Ü"],
            "italian" => [
                "à",
                "è",
                "ì",
                "ò",
                "ù",
                "é",
                "í",
                "ó",
                "À",
                "È",
                "Ì",
                "Ò",
                "Ù",
                "É",
                "Í",
                "Ó"
            ],
            "greek" => [
                "α",
                "β",
                "γ",
                "δ",
                "ε",
                "ζ",
                "η",
                "θ",
                "ι",
                "κ",
                "λ",
                "μ",
                "ν",
                "ξ",
                "ο",
                "π",
                "ρ",
                "σ",
                "τ",
                "υ",
                "φ",
                "χ",
                "ψ",
                "ω",
                "Α",
                "Β",
                "Γ",
                "Δ",
                "Ε",
                "Θ",
                "Λ",
                "Μ",
                "Ν",
                "Ξ",
                "Ο",
                "Π",
                "Ρ",
                "Σ",
                "Τ",
                "Υ",
                "Φ",
                "Χ",
                "Ψ",
                "Ω"
            ],
            "french" => [
                "à",
                "â",
                "é",
                "è",
                "ê",
                "ë",
                "î",
                "ï",
                "ô",
                "ù",
                "û",
                "ü",
                "ÿ",
                "ç",
                "À",
                "Â",
                "É",
                "È",
                "Ê",
                "Ë",
                "Î",
                "Ï",
                "Ô",
                "Ù",
                "Û",
                "Ü",
                "Ÿ",
                "Ç",
                "æ",
                "œ",
                "Æ",
                "Œ"
            ]
        ];

        return $templates[$template] ?? $templates['math'];
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
        return ['examcalc', 'examsymbols'];
    }

    public function saveAllModuleSettings(int $ref_id, array $post_data): array
    {
        $results = [];

        // ExamCalc speichern
        $results['examcalc'] = $this->saveExamCalcDirect($ref_id, $post_data);

        // ExamSymbols speichern
        $results['examsymbols'] = $this->saveExamSymbolsDirect($ref_id, $post_data);

        return $results;
    }

    private function saveExamCalcDirect(int $ref_id, array $post_data): bool
    {
        try {
            global $DIC;
            $db = $DIC->database();
            $obj_id = ilObject::_lookupObjectId($ref_id);
            $enabled = isset($post_data['examcalc_enabled']) ? 1 : 0;

            $db->replace('examcalc_settings', [
                'test_id' => ['integer', $obj_id]
            ], [
                'enabled' => ['integer', $enabled]
            ]);

            return true;
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] ExamCalc Speicher-Fehler: " . $e->getMessage());
            }
            return false;
        }
    }

    private function saveExamSymbolsDirect(int $ref_id, array $post_data): bool
    {
        try {
            global $DIC;
            $db = $DIC->database();
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
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] ExamSymbols Speicher-Fehler: " . $e->getMessage());
            }
            return false;
        }
    }
}