<?php
/**
 * ExamAlert Handler - Zeit-basierte Benachrichtigungen während Tests
 * Part of ExtendedTestSettings Plugin
 */

class ilExamAlertHandler
{
    private string $plugin_path;
    
    // Standardnachricht mit Formatierung
    private const DEFAULT_MESSAGE = '<strong><u>Achtung:</u></strong> Ihr Test endet bald! Klicken Sie auf „Speichern und Test beenden", bevor die Zeit abläuft. Andernfalls besteht die Gefahr, dass Ihre letzte Eingabe nicht gespeichert wird.

<strong><u>Attention:</u></strong> Your test will end soon! Click on "Save Answers and Finish the Test" before the time runs out. Otherwise, there is a risk that your last entry will not be saved.';
    
    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;
    }

    /**
     * Prüft ob ExamAlert für den Test aktiviert ist
     * Standardmäßig aktiviert für alle Tests
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

            $query = "SELECT enabled FROM examalert_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return (bool) $row['enabled'];
            }

            // Keine Einstellungen vorhanden = Standard: AKTIVIERT
            // Erstelle automatisch Default-Einstellungen
            $this->ensureDefaultSettings($test_id);
            return true;

        } catch (Exception $e) {
            error_log("[ExamAlert] Fehler beim Prüfen der Aktivierung: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Lädt die Alert-Konfiguration für einen Test
     */
    public function getAlertConfig(int $ref_id): array
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $test_id = ilObject::_lookupObjectId($ref_id);
            
            $query = "SELECT alert_time, alert_message FROM examalert_settings WHERE test_id = %s AND enabled = 1";
            $result = $db->queryF($query, ['integer'], [$test_id]);

            if ($row = $db->fetchAssoc($result)) {
                return [
                    'enabled' => true,
                    'alert_time' => (int) $row['alert_time'],
                    'alert_message' => $row['alert_message'] ?? self::DEFAULT_MESSAGE
                ];
            }

            // Prüfe ob Test existiert aber deaktiviert ist
            $query2 = "SELECT enabled FROM examalert_settings WHERE test_id = %s";
            $result2 = $db->queryF($query2, ['integer'], [$test_id]);
            if ($db->fetchAssoc($result2)) {
                return ['enabled' => false, 'alert_time' => 30, 'alert_message' => ''];
            }

            // Keine Einstellungen vorhanden = erstelle Defaults und gebe sie zurück
            $this->ensureDefaultSettings($test_id);
            return [
                'enabled' => true,
                'alert_time' => 45,
                'alert_message' => self::DEFAULT_MESSAGE
            ];

        } catch (Exception $e) {
            error_log("[ExamAlert] Fehler beim Laden der Konfiguration: " . $e->getMessage());
        }

        return ['enabled' => false, 'alert_time' => 45, 'alert_message' => ''];
    }

    /**
     * Stellt sicher, dass Default-Einstellungen für einen Test existieren
     */
    private function ensureDefaultSettings(int $test_id): void
    {
        global $DIC;
        $db = $DIC->database();

        try {
            // Prüfe ob bereits Einstellungen existieren
            $query = "SELECT test_id FROM examalert_settings WHERE test_id = %s";
            $result = $db->queryF($query, ['integer'], [$test_id]);
            
            if (!$db->fetchAssoc($result)) {
                // Erstelle Default-Einstellungen
                $db->insert('examalert_settings', [
                    'test_id' => ['integer', $test_id],
                    'enabled' => ['integer', 1], // Standardmäßig AKTIVIERT
                    'alert_time' => ['integer', 45], // Standard: 45 Sekunden
                    'alert_message' => ['text', self::DEFAULT_MESSAGE],
                    'updated_at' => ['timestamp', date('Y-m-d H:i:s')]
                ]);
                
                error_log("[ExamAlert] Default-Einstellungen erstellt für Test-ID: $test_id");
            }
        } catch (Exception $e) {
            error_log("[ExamAlert] Fehler beim Erstellen der Default-Einstellungen: " . $e->getMessage());
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
     * Injiziert alle nötigen Assets (JS, CSS) in Template
     */
    public function injectAssets(ilGlobalTemplateInterface $tpl, int $ref_id): void
    {
        if (!$this->isEnabled($ref_id) || !$this->isTestPlayerContext()) {
            return;
        }

        // Alert-Konfiguration laden
        $config = $this->getAlertConfig($ref_id);
        
        if (!$config['enabled']) {
            return;
        }

        // Debug-Logging
        $tpl->addOnLoadCode("
            if (typeof window.eaLog === 'undefined') {
                window.eaLog = function(msg) { 
                    console.log('[ExamAlert] ' + msg); 
                };
            }
            eaLog('⏰ ExamAlert wird geladen für ref_id: $ref_id');
        ");

        // CSS laden
        $css_path = $this->plugin_path . "/css/examalert.css";
        $tpl->addCss($css_path);

        // JavaScript laden
        $js_path = $this->plugin_path . "/js/examalert/examalert.js";
        $tpl->addJavaScript($js_path);

        // Konfiguration als JavaScript-Variable bereitstellen
        $config_json = json_encode($config);
        
        $tpl->addOnLoadCode("
            window.ExamAlert = window.ExamAlert || {};
            window.ExamAlert.config = $config_json;
            window.ExamAlert.refId = $ref_id;
            eaLog('✅ ExamAlert konfiguriert - Warnung bei ' + {$config['alert_time']} + ' Sekunden vor Ende');
        ");
    }

    /**
     * Speichert ExamAlert-Einstellungen
     */
    public function saveSettings(int $ref_id, array $post_data): bool
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $obj_id = ilObject::_lookupObjectId($ref_id);
            $enabled = isset($post_data['examalert_enabled']) ? 1 : 0;
            $alert_time = (int) ($post_data['examalert_time'] ?? 45);
            $alert_message = trim($post_data['examalert_message'] ?? '');

            // Validierung
            if ($alert_time < 5) {
                $alert_time = 5; // Minimum 5 Sekunden
            }
            if ($alert_time > 3600) {
                $alert_time = 3600; // Maximum 1 Stunde
            }
            
            if (empty($alert_message)) {
                $alert_message = self::DEFAULT_MESSAGE;
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
            error_log("[ExamAlert] Fehler beim Speichern: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generiert HTML für Einstellungs-Formular
     */
    public function getSettingsHTML(int $ref_id): string
    {
        $enabled = $this->isEnabled($ref_id) ? 'checked' : '';
        $config = $this->getAlertConfig($ref_id);
        $alert_time = $config['alert_time'] ?? 30;
        $alert_message = htmlspecialchars($config['alert_message'] ?? self::DEFAULT_MESSAGE, ENT_QUOTES);
        
        return <<<HTML
            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" name="examalert_enabled" value="1" $enabled style="transform: scale(1.2);">
                    <strong>Zeit-Warnung aktivieren</strong>
                </label>
                <p style="margin: 0.5rem 0 1rem 1.7rem; color: #666; font-size: 0.9rem;">
                    Zeigt eine Warnung zu einer bestimmten Zeit vor Testende an.
                </p>

                <div style="margin-left: 1.7rem;">
                    <label for="examalert_time" style="display: block; font-weight: bold; margin-bottom: 0.5rem;">
                        Warnung anzeigen:
                    </label>
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
                        <input type="number" 
                               name="examalert_time" 
                               id="examalert_time" 
                               value="$alert_time" 
                               min="5" 
                               max="3600" 
                               style="width: 80px; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px;">
                        <span>Sekunden vor Testende</span>
                    </div>

                    <label for="examalert_message" style="display: block; font-weight: bold; margin-bottom: 0.5rem;">
                        Nachricht:
                    </label>
                    <textarea name="examalert_message" 
                              id="examalert_message" 
                              rows="6"
                              placeholder="Nachricht..."
                              style="width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; resize: vertical; font-family: monospace;">$alert_message</textarea>
                    <p style="margin: 0.5rem 0 0 0; color: #666; font-size: 0.9rem;">
                        Diese Nachricht wird den Teilnehmern zur eingestellten Zeit angezeigt. HTML-Formatierung erlaubt: &lt;strong&gt;, &lt;u&gt;, &lt;br&gt;
                    </p>
                </div>
            </div>
HTML;
    }

    /**
     * Gibt Plugin-Info zurück
     */
    public function getInfo(): array
    {
        return [
            'name' => 'ExamAlert',
            'title' => 'Zeit-Warnung',
            'icon' => '⏰',
            'description' => 'Zeigt zeitbasierte Warnungen mit benutzerdefinierten Nachrichten während des Tests an',
            'version' => '0.1.1',
            'features' => [
                'Standardmäßig für alle Tests aktiviert',
                'Einstellbare Warnzeit (5 Sekunden bis 1 Stunde, Default: 45s)',
                'Benutzerdefinierte Warnnachrichten mit HTML-Formatierung', 
                'Zweisprachige Standardnachricht (Deutsch/Englisch)',
                'Automatische Zeitmessung im Test',
                'Elegante Popup-Anzeige mit Live-Countdown',
                'Persistente Anzeige bis manuelles Schließen',
                'Responsive Design'
            ]
        ];
    }
}