<?php
/**
 * ExamAlert Handler - Zeit-basierte Benachrichtigungen während Tests
 * Part of ExtendedTestSettings Plugin
 */

class ilExamAlertHandler
{
    private string $plugin_path;
    
    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;
    }

    /**
     * Prüft ob ExamAlert für den Test aktiviert ist
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

            return false;

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
                    'alert_message' => $row['alert_message'] ?? 'Achtung: Der Test endet bald!'
                ];
            }

        } catch (Exception $e) {
            error_log("[ExamAlert] Fehler beim Laden der Konfiguration: " . $e->getMessage());
        }

        return ['enabled' => false, 'alert_time' => 30, 'alert_message' => ''];
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
            $alert_time = (int) ($post_data['examalert_time'] ?? 30);
            $alert_message = trim($post_data['examalert_message'] ?? '');

            // Validierung
            if ($alert_time < 5) {
                $alert_time = 5; // Minimum 5 Sekunden
            }
            if ($alert_time > 3600) {
                $alert_time = 3600; // Maximum 1 Stunde
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
        $alert_message = htmlspecialchars($config['alert_message'] ?? 'Achtung: Der Test endet bald!', ENT_QUOTES);
        
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
                              rows="3"
                              placeholder="Achtung: Der Test endet bald!"
                              style="width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; resize: vertical;">$alert_message</textarea>
                    <p style="margin: 0.5rem 0 0 0; color: #666; font-size: 0.9rem;">
                        Diese Nachricht wird den Teilnehmern zur eingestellten Zeit angezeigt.
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
            'version' => '0.0.1',
            'features' => [
                'Einstellbare Warnzeit (5 Sekunden bis 1 Stunde)',
                'Benutzerdefinierte Warnnachrichten', 
                'Automatische Zeitmessung im Test',
                'Elegante Popup-Anzeige',
                'Responsive Design'
            ]
        ];
    }
}