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

            // Plugin-Pfad einmal definieren
            $plugin_path = "./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExtendedTestSettings";

            // ExamCalc laden falls aktiviert
            if ($this->isExamCalcEnabledDirect($current_ref_id)) {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ✅ ExamCalc aktiviert - lade Assets");
                    $tpl->addOnLoadCode("window.etsDebug.innerHTML += '<br>✅ ExamCalc: Aktiviert';");
                }

                // CSS und JavaScript laden
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
            } else {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ExamCalc nicht aktiviert");
                    $tpl->addOnLoadCode("window.etsDebug && (window.etsDebug.innerHTML += '<br>❌ ExamCalc: Nicht aktiviert');");
                }
            }

            // ExamSymbols laden falls aktiviert
            $examsymbols_config = $this->getExamSymbolsConfigDirect($current_ref_id);
            if ($examsymbols_config['enabled']) {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ✅ ExamSymbols aktiviert - lade Assets");
                    $tpl->addOnLoadCode("window.etsDebug.innerHTML += '<br>✅ ExamSymbols: Aktiviert';");
                }

                // CSS und JavaScript laden
                $tpl->addCss($plugin_path . "/css/examsymbols.css");
                $tpl->addJavaScript($plugin_path . "/js/examsymbols/symbols-manager.js");
                $tpl->addJavaScript($plugin_path . "/js/examsymbols/examsymbols-loader.js");

                // Template-Pfad und Konfiguration bereitstellen
                $template_path = $plugin_path . "/templates/symbols_overlay.html";
                $symbols = $this->getSymbolsForTemplate($examsymbols_config['template']);
                $symbols_json = json_encode($symbols);

                $tpl->addOnLoadCode("
                    window.ExamSymbols = window.ExamSymbols || {};
                    window.ExamSymbols.templatePath = '$template_path';
                    window.ExamSymbols.config = {
                        template: '{$examsymbols_config['template']}',
                        refId: $current_ref_id,
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
            } else {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ExamSymbols nicht aktiviert");
                    $tpl->addOnLoadCode("window.etsDebug && (window.etsDebug.innerHTML += '<br>❌ ExamSymbols: Nicht aktiviert');");
                }
            }

            // ExamGuard laden falls aktiviert
            if ($this->isExamGuardEnabledDirect($current_ref_id)) {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ✅ ExamGuard aktiviert - lade Assets");
                    $tpl->addOnLoadCode("window.etsDebug.innerHTML += '<br>✅ ExamGuard: Aktiviert';");
                }

                // JavaScript laden (kein CSS nötig für ExamGuard)
                $tpl->addJavaScript($plugin_path . "/js/examguard/examguard.js");

                if (self::DEBUG_ENABLED) {
                    $tpl->addOnLoadCode("
                        console.log('[ExamGuard] Assets geladen');
                        window.etsDebug.innerHTML += '<br>✅ ExamGuard: Assets geladen';
                    ");
                }
            } else {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ExamGuard nicht aktiviert");
                    $tpl->addOnLoadCode("window.etsDebug && (window.etsDebug.innerHTML += '<br>❌ ExamGuard: Nicht aktiviert');");
                }
            }

            // ExamAlert laden falls aktiviert - NEU
            $examalert_config = $this->getExamAlertConfigDirect($current_ref_id);
            if ($examalert_config['enabled']) {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ✅ ExamAlert aktiviert - lade Assets");
                    $tpl->addOnLoadCode("window.etsDebug.innerHTML += '<br>✅ ExamAlert: Aktiviert';");
                }

                // CSS und JavaScript laden
                $tpl->addCss($plugin_path . "/css/examalert.css");
                $tpl->addJavaScript($plugin_path . "/js/examalert/examalert.js");

                // Konfiguration bereitstellen
                $config_json = json_encode($examalert_config);
                $tpl->addOnLoadCode("
                    window.ExamAlert = window.ExamAlert || {};
                    window.ExamAlert.config = $config_json;
                    window.ExamAlert.refId = $current_ref_id;
                ");

                if (self::DEBUG_ENABLED) {
                    $tpl->addOnLoadCode("
                        console.log('[ExamAlert] Assets geladen');
                        window.etsDebug.innerHTML += '<br>✅ ExamAlert: Assets geladen';
                    ");
                }
            } else {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExtendedTestSettings] ExamAlert nicht aktiviert");
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
     * Direkte ExamGuard Datenbankprüfung
     */
    private function isExamGuardEnabledDirect(int $ref_id): bool
    {
        try {
            global $DIC;
            $db = $DIC->database();
            $test_id = ilObject::_lookupObjectId($ref_id);

            if ($test_id <= 0) {
                return false;
            }

            $query = "SELECT enabled FROM examguard_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return (bool) $row['enabled'];
            }
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] ExamGuard DB-Check Fehler: " . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * Direkte ExamAlert Datenbankprüfung - NEU
     */
    private function getExamAlertConfigDirect(int $ref_id): array
    {
        try {
            global $DIC;
            $db = $DIC->database();
            $test_id = ilObject::_lookupObjectId($ref_id);

            if ($test_id <= 0) {
                return ['enabled' => false, 'alert_time' => 30, 'alert_message' => ''];
            }

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
                error_log("[ExtendedTestSettings] ExamAlert DB-Check Fehler: " . $e->getMessage());
            }
        }

        return ['enabled' => false, 'alert_time' => 30, 'alert_message' => 'Achtung: Der Test endet bald!'];
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
    // Reihe 1: Vokale groß mit Akzent (5 Tasten)
    "Á", "É", "Í", "Ó", "Ú",
    
    // Reihe 2: Vokale klein mit Akzent (5 Tasten)  
    "á", "é", "í", "ó", "ú",
    
    // Reihe 3: Ñ ñ ¿ ¡ (4 Tasten)
    "Ñ", "ñ", "¿", "¡"
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
        return ['examcalc', 'examsymbols', 'examguard', 'examalert']; // ExamAlert hinzugefügt
    }

    public function saveAllModuleSettings(int $ref_id, array $post_data): array
    {
        $results = [];

        // ExamCalc speichern
        $results['examcalc'] = $this->saveExamCalcDirect($ref_id, $post_data);

        // ExamSymbols speichern
        $results['examsymbols'] = $this->saveExamSymbolsDirect($ref_id, $post_data);

        // ExamGuard speichern
        $results['examguard'] = $this->saveExamGuardDirect($ref_id, $post_data);

        // ExamAlert speichern - NEU
        $results['examalert'] = $this->saveExamAlertDirect($ref_id, $post_data);

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

    private function saveExamGuardDirect(int $ref_id, array $post_data): bool
    {
        try {
            global $DIC;
            $db = $DIC->database();
            $obj_id = ilObject::_lookupObjectId($ref_id);
            $enabled = isset($post_data['examguard_enabled']) ? 1 : 0;

            $db->replace('examguard_settings', [
                'test_id' => ['integer', $obj_id]
            ], [
                'enabled' => ['integer', $enabled]
            ]);

            return true;
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] ExamGuard Speicher-Fehler: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * ExamAlert Speicher-Methode - NEU
     */
    private function saveExamAlertDirect(int $ref_id, array $post_data): bool
    {
        try {
            global $DIC;
            $db = $DIC->database();
            $obj_id = ilObject::_lookupObjectId($ref_id);
            
            $enabled = isset($post_data['examalert_enabled']) ? 1 : 0;
            $alert_time = (int) ($post_data['examalert_time'] ?? 30);
            $alert_message = trim($post_data['examalert_message'] ?? '');

            // Validierung
            if ($alert_time < 5) {
                $alert_time = 5;
            }
            if ($alert_time > 3600) {
                $alert_time = 3600;
            }
            if (empty($alert_message)) {
                $alert_message = 'Achtung: Der Test endet bald!';
            }

            $db->replace('examalert_settings', [
                'test_id' => ['integer', $obj_id]
            ], [
                'enabled' => ['integer', $enabled],
                'alert_time' => ['integer', $alert_time],
                'alert_message' => ['text', $alert_message],
                'updated_at' => ['timestamp', date('Y-m-d H:i:s')]
            ]);

            return true;
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExtendedTestSettings] ExamAlert Speicher-Fehler: " . $e->getMessage());
            }
            return false;
        }
    }
}