<?php
/**
 * ExamChecker Handler - Prüfung von Test-Einstellungen gegen Standards
 * Part of ExtendedTestSettings Plugin
 */

class ilExamCheckerHandler
{
    private string $plugin_path;
    
    public function __construct(string $plugin_path)
    {
        $this->plugin_path = $plugin_path;
    }

    /**
     * ExamChecker ist immer "enabled" - es ist ein Tool, kein Feature
     */
    public function isEnabled(int $ref_id): bool
    {
        return true; // Immer verfügbar
    }

    /**
     * Generiert HTML für Einstellungs-Formular (mit Check-Button)
     */
    public function getSettingsHTML(int $ref_id): string
    {
        $html = '<div class="examchecker-container">
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label for="exam_type" style="display: block; font-weight: 500; margin-bottom: 0.5rem; color: #495057;">
                    Prüfungstyp:
                </label>
                <select name="exam_type" id="exam_type" style="width: 200px; padding: 0.5rem; border: 1px solid #ced4da; border-radius: 0.375rem;">
                    <option value="praesenz">📍 Präsenzprüfung</option>
                    <option value="fern">🏠 Fernprüfung</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <button type="button" id="start-exam-check" class="btn btn-primary" style="
                    background: #28a745; 
                    color: white; 
                    padding: 0.75rem 1.5rem; 
                    border: none; 
                    border-radius: 0.375rem; 
                    font-weight: 500; 
                    cursor: pointer;
                    transition: all 0.2s;
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                ">
                    <span>🔍</span>
                    <span>Exam-Prüfungs-Check starten</span>
                </button>
            </div>

            <div id="check-results" style="display: none; margin-top: 1.5rem;">
                <!-- Ergebnisse werden hier per AJAX eingefügt -->
            </div>

            <div id="check-loading" style="display: none; text-align: center; padding: 2rem; color: #6c757d;">
                <div style="font-size: 2rem; margin-bottom: 1rem;">⏳</div>
                <div>Prüfungseinstellungen werden überprüft...</div>
            </div>
        </div>';

        // JavaScript separat hinzufügen
        $html .= $this->getJavaScriptCode($ref_id);
        $html .= $this->getCSSCode();

        return $html;
    }

    /**
     * JavaScript-Code separat
     */
    private function getJavaScriptCode(int $ref_id): string
    {
        return '
        <script>
        document.addEventListener("DOMContentLoaded", function() {
            const checkButton = document.getElementById("start-exam-check");
            const resultsDiv = document.getElementById("check-results");
            const loadingDiv = document.getElementById("check-loading");
            const examTypeSelect = document.getElementById("exam_type");

            console.log("[ExamChecker] JavaScript geladen für ref_id: ' . $ref_id . '");

            if (checkButton) {
                checkButton.addEventListener("click", function() {
                    const examType = examTypeSelect.value;
                    const refId = ' . $ref_id . ';
                    
                    console.log("[ExamChecker] Button geklickt - examType:", examType, "refId:", refId);
                    
                    // UI-Status
                    checkButton.disabled = true;
                    checkButton.innerHTML = "<span>⏳</span><span>Wird geprüft...</span>";
                    resultsDiv.style.display = "none";
                    loadingDiv.style.display = "block";

                    const postData = "action=exam_check&ref_id=" + refId + "&exam_type=" + examType;
                    
                    // KORREKTE AJAX-URL: cmd=saveSettings (nicht cmd=show!)
                    let ajaxUrl = window.location.href;
                    ajaxUrl = ajaxUrl.replace("cmd=show", "cmd=saveSettings");
                    ajaxUrl = ajaxUrl.replace("&debug=1", ""); // Debug-Parameter entfernen für AJAX
                    
                    console.log("[ExamChecker] POST-Daten:", postData);
                    console.log("[ExamChecker] AJAX-URL:", ajaxUrl);

                    // AJAX-Request
                    fetch(ajaxUrl, {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/x-www-form-urlencoded",
                        },
                        body: postData
                    })
                    .then(response => {
                        console.log("[ExamChecker] Response Status:", response.status);
                        console.log("[ExamChecker] Response Headers:", response.headers);
                        
                        if (!response.ok) {
                            throw new Error("HTTP " + response.status + ": " + response.statusText);
                        }
                        
                        return response.text(); // Erstmal als Text um zu sehen was ankommt
                    })
                    .then(text => {
                        console.log("[ExamChecker] Raw Response:", text);
                        
                        try {
                            const data = JSON.parse(text);
                            console.log("[ExamChecker] Parsed JSON:", data);
                            
                            loadingDiv.style.display = "none";
                            resultsDiv.innerHTML = data.html;
                            resultsDiv.style.display = "block";
                            
                            // Button zurücksetzen
                            checkButton.disabled = false;
                            checkButton.innerHTML = "<span>🔍</span><span>Exam-Prüfungs-Check starten</span>";
                            
                        } catch (parseError) {
                            console.error("[ExamChecker] JSON Parse Error:", parseError);
                            throw new Error("Antwort ist kein gültiges JSON: " + text.substring(0, 200));
                        }
                    })
                    .catch(error => {
                        console.error("[ExamChecker] Fetch Error:", error);
                        loadingDiv.style.display = "none";
                        resultsDiv.innerHTML = "<div class=\"alert alert-danger\">❌ Fehler: " + error.message + "</div>";
                        resultsDiv.style.display = "block";
                        
                        checkButton.disabled = false;
                        checkButton.innerHTML = "<span>🔍</span><span>Exam-Prüfungs-Check starten</span>";
                    });
                });
            } else {
                console.error("[ExamChecker] Button nicht gefunden!");
            }
        });
        </script>';
    }

    /**
     * CSS-Code separat
     */
    private function getCSSCode(): string
    {
        return '
        <style>
        .examchecker-container .btn:hover {
            background: #218838 !important;
            transform: translateY(-1px);
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
        }
        
        .examchecker-container .btn:disabled {
            background: #6c757d !important;
            cursor: not-allowed;
            transform: none;
        }
        </style>';
    }

    /**
     * Speichert ExamChecker-Einstellungen und führt Check durch
     */
    public function saveSettings(int $ref_id, array $post_data): bool
    {
        // Debug-Logging
        error_log("[ExamChecker] saveSettings aufgerufen - ref_id: $ref_id");
        error_log("[ExamChecker] POST-Daten: " . print_r($post_data, true));
        
        // Alternative Methode: auch direkt aus $_POST prüfen
        if (empty($post_data)) {
            error_log("[ExamChecker] post_data leer, versuche $_POST");
            $post_data = $_POST;
        }
        
        // Prüfe ob es ein AJAX-Request für den Check ist
        if (isset($post_data['action']) && $post_data['action'] === 'exam_check') {
            error_log("[ExamChecker] AJAX-Request erkannt - starte handleExamCheck");
            $this->handleExamCheck($ref_id, $post_data);
            return true; // Wichtig: Nach AJAX-Response nicht weiter ausführen
        }

        // ExamChecker hat keine Settings zu speichern - es ist nur ein Tool
        error_log("[ExamChecker] Kein AJAX-Request, normale Settings-Speicherung");
        return true;
    }

    /**
     * Führt den eigentlichen Exam-Check durch
     */
    private function handleExamCheck(int $ref_id, array $post_data): void
    {
        try {
            error_log("[ExamChecker] handleExamCheck gestartet");
            
            $exam_type = $post_data['exam_type'] ?? 'praesenz';
            error_log("[ExamChecker] Exam Type: $exam_type");
            
            $results = $this->performExamCheck($ref_id, $exam_type);
            error_log("[ExamChecker] performExamCheck erfolgreich");
            
            $html = $this->generateCheckResultsHTML($results, $exam_type);
            error_log("[ExamChecker] HTML generiert, Länge: " . strlen($html));
            
            // JSON-Response senden
            header('Content-Type: application/json');
            $response = json_encode(array(
                'html' => $html, 
                'success' => true,
                'debug' => 'Check erfolgreich durchgeführt'
            ));
            
            error_log("[ExamChecker] JSON-Response wird gesendet");
            echo $response;
            exit;
            
        } catch (Exception $e) {
            error_log("[ExamChecker] Fehler in handleExamCheck: " . $e->getMessage());
            error_log("[ExamChecker] Stack Trace: " . $e->getTraceAsString());
            
            header('Content-Type: application/json');
            echo json_encode(array(
                'error' => $e->getMessage(), 
                'success' => false,
                'debug' => 'Fehler aufgetreten'
            ));
            exit;
        }
    }

    /**
     * Hauptfunktion: Führt alle Checks durch
     */
    private function performExamCheck(int $ref_id, string $exam_type): array
    {
        $test_data = $this->getTestDataFromDB($ref_id);
        if (!$test_data) {
            throw new Exception('Test-Daten nicht gefunden');
        }

        return array(
            'einstellungen' => $this->checkEinstellungen($test_data, $exam_type),
            'auswertung' => $this->checkAuswertung($test_data, $exam_type),
            'manuell_check' => $this->checkManuellePrüfungen($test_data, $exam_type)
        );
    }

    /**
     * Lädt Test-Daten direkt aus der Datenbank
     */
    private function getTestDataFromDB(int $ref_id): ?array
    {
        global $DIC;
        $db = $DIC->database();

        try {
            $obj_id = ilObject::_lookupObjectId($ref_id);
            if ($obj_id <= 0) {
                return null;
            }

            // Prüfe ob es wirklich ein Test ist
            $obj_type = ilObject::_lookupType($obj_id);
            if ($obj_type !== 'tst') {
                return null;
            }

            // KORRIGIERT: obj_fi statt test_id verwenden!
            $query = "SELECT * FROM tst_tests WHERE obj_fi = %s";
            $result = $db->queryF($query, array('integer'), array($obj_id));

            if ($row = $db->fetchAssoc($result)) {
                error_log("[ExamChecker] ✅ Test-Daten gefunden für obj_id: $obj_id");
                return $row;
            } else {
                error_log("[ExamChecker] ❌ Kein Test-Eintrag für obj_fi: $obj_id");
                return null;
            }

        } catch (Exception $e) {
            error_log("[ExamChecker] Exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Prüft die Grundeinstellungen des Tests
     */
    private function checkEinstellungen(array $test_data, string $exam_type): array
    {
        $checks = array();
        
        // Basis-Checks für alle Prüfungstypen
        $checks['fragenpools_verwenden'] = (int)$test_data['pool_usage'] === 1;
        $checks['datenschutz'] = (int)$test_data['anonymity'] === 0; // Mit Namen
        $checks['online'] = (int)$test_data['online_status'] === 1;
        $checks['passwort'] = (int)$test_data['password_enabled'] === 1;
        $checks['nur_ausgewaehlte_teilnehmer'] = (int)$test_data['fixed_participants'] === 0; // Negiert!
        
        // Testdurchläufe begrenzen (auf 1)
        $checks['testdurchlaeufe_begrenzen'] = (int)$test_data['nr_of_tries'] === 1;
        
        // Wartezeit zwischen Durchläufen erzwingen (sollte AUS sein)
        $checks['wartezeit_erzwingen'] = empty($test_data['pass_waiting']) || $test_data['pass_waiting'] === '0';
        
        // Prüfungsansicht (Kiosk-Modus)
        $checks['pruefungsansicht'] = (int)$test_data['kiosk'] === 1;
        
        // Automatisches Speichern (30s Intervall)
        $autosave_enabled = (int)$test_data['autosave'] === 1;
        $autosave_interval = (int)$test_data['autosave_ival'] === 30;
        $checks['automatisches_speichern'] = $autosave_enabled && $autosave_interval;
        
        // Weitere Standard-Einstellungen
        $checks['direkte_rueckmeldung'] = (int)$test_data['instant_verification'] === 0; // Sollte AUS sein
        $checks['fragen_markieren'] = (int)$test_data['show_marker'] === 1;
        
        // Spezifische Checks je nach Prüfungstyp
        if ($exam_type === 'fern') {
            // Für Fernprüfungen
            $checks['eigenstaendigkeitserklaerung'] = (int)$test_data['require_authorship_statement'] === 1;
            $checks['bearbeitungsdauer'] = (int)$test_data['enable_processing_time'] === 1;
        }
        
        if ($exam_type === 'praesenz') {
            // Weiterleitung bei Präsenzprüfungen (Mode 2 = nur bei Kiosk, logout.php)
            $redirection_mode = (int)$test_data['redirection_mode'] === 2;
            $redirection_url = trim($test_data['redirection_url']) === 'logout.php';
            $checks['weiterleitung'] = $redirection_mode && $redirection_url;
        }
        
        return $checks;
    }

    /**
     * Prüft die Auswertungseinstellungen
     */
    private function checkAuswertung(array $test_data, string $exam_type): array
    {
        $checks = array();
        
        // Bewertungssystem: Auch teilweise falsche Antworten erzielen Punkte (count_system = 0)
        $checks['bewertungssystem'] = (int)$test_data['count_system'] === 0;
        
        // Negative Punkte deaktiviert (score_cutting = 0)
        $checks['negative_punkte'] = (int)$test_data['score_cutting'] === 0;
        
        // Besten Testdurchlauf bewerten (pass_scoring = 1)
        $checks['besten_durchlauf_bewerten'] = (int)$test_data['pass_scoring'] === 1;
        
        return $checks;
    }

    /**
     * Einstellungen die manuell geprüft werden müssen
     */
    private function checkManuellePrüfungen(array $test_data, string $exam_type): array
    {
        $manual_checks = array();
        
        // Start- und Endzeiten prüfen
        $start_enabled = (int)$test_data['starting_time_enabled'] === 1;
        $end_enabled = (int)$test_data['ending_time_enabled'] === 1;
        $start_time = (int)$test_data['starting_time'];
        $end_time = (int)$test_data['ending_time'];
        
        $times_properly_set = $start_enabled && $end_enabled && 
                             $start_time > 0 && $end_time > 0 && 
                             !$this->isDefaultDateTime($start_time) && 
                             !$this->isDefaultDateTime($end_time);
        
        $manual_checks['start_und_ende'] = array(
            'status' => $times_properly_set,
            'current_start' => $start_time > 0 ? date('d.m.Y H:i', $start_time) : 'Nicht gesetzt',
            'current_end' => $end_time > 0 ? date('d.m.Y H:i', $end_time) : 'Nicht gesetzt',
            'message' => 'Start- und Endzeiten müssen manuell auf Plausibilität geprüft werden.'
        );
        
        // Bearbeitungsdauer
        $processing_enabled = (int)$test_data['enable_processing_time'] === 1;
        $processing_time = $test_data['processing_time'] ?? '';
        
        $manual_checks['bearbeitungsdauer'] = array(
            'status' => $processing_enabled && !empty($processing_time) && $processing_time > 0,
            'current_time' => $processing_enabled ? ($processing_time . ' Minuten') : 'Nicht aktiviert',
            'message' => 'Bearbeitungsdauer muss der tatsächlichen Prüfungszeit entsprechen.'
        );
        
        return $manual_checks;
    }

    /**
     * Generiert HTML für Check-Ergebnisse
     */
    private function generateCheckResultsHTML(array $results, string $exam_type): string
    {
        $settings_descriptions = $this->getSettingDescriptions();
        
        // Zusammenfassung berechnen
        $total = count($results['einstellungen']) + count($results['auswertung']);
        $passed = count(array_filter($results['einstellungen'])) + count(array_filter($results['auswertung']));
        $failed = $total - $passed;
        $percentage = $total > 0 ? round(($passed / $total) * 100) : 0;
        
        // Farbschema basierend auf Prozentsatz
        if ($percentage >= 90) {
            $summary_color = '#28a745'; $summary_icon = '🎉';
        } elseif ($percentage >= 70) {
            $summary_color = '#fd7e14'; $summary_icon = '⚠️';
        } else {
            $summary_color = '#dc3545'; $summary_icon = '❌';
        }

        // HTML zusammenbauen (ohne Heredoc)
        $html = '<div class="check-results-container" style="margin-top: 1rem;">';
        
        // Zusammenfassung
        $html .= '<div style="background: ' . $summary_color . '15; border: 2px solid ' . $summary_color . '; border-radius: 0.5rem; padding: 1.5rem; margin-bottom: 2rem; text-align: center;">';
        $html .= '<div style="font-size: 3rem; margin-bottom: 0.5rem;">' . $summary_icon . '</div>';
        $html .= '<h3 style="color: ' . $summary_color . '; margin: 0 0 0.5rem 0;">Check abgeschlossen: ' . $percentage . '%</h3>';
        $html .= '<p style="margin: 0; color: #6c757d;">';
        $html .= '<strong>' . $passed . '</strong> korrekt • <strong>' . $failed . '</strong> zu überprüfen • <strong>' . count($results['manuell_check']) . '</strong> manuell';
        $html .= '</p></div>';

        // Erfolgreich geprüfte Einstellungen
        $passed_items = array();
        foreach ($results['einstellungen'] as $key => $passed_check) {
            if ($passed_check) $passed_items[] = $settings_descriptions[$key] ?? $key;
        }
        foreach ($results['auswertung'] as $key => $passed_check) {
            if ($passed_check) $passed_items[] = $settings_descriptions[$key] ?? $key;
        }

        if (!empty($passed_items)) {
            $html .= '<div style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 0.375rem; padding: 1rem; margin-bottom: 1rem;">';
            $html .= '<h4 style="color: #155724; margin: 0 0 0.75rem 0; display: flex; align-items: center; gap: 0.5rem;">';
            $html .= '<span>✅</span> Korrekte Einstellungen (' . count($passed_items) . ')';
            $html .= '</h4>';
            $html .= '<ul style="margin: 0; padding-left: 1.5rem; color: #155724;">';
            foreach ($passed_items as $item) {
                $html .= '<li style="margin-bottom: 0.25rem;">' . htmlspecialchars($item) . '</li>';
            }
            $html .= '</ul></div>';
        }

        // Fehlerhafte Einstellungen
        $failed_items = array();
        foreach ($results['einstellungen'] as $key => $passed_check) {
            if (!$passed_check) $failed_items[] = $settings_descriptions[$key] ?? $key;
        }
        foreach ($results['auswertung'] as $key => $passed_check) {
            if (!$passed_check) $failed_items[] = $settings_descriptions[$key] ?? $key;
        }

        if (!empty($failed_items)) {
            $html .= '<div style="background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 0.375rem; padding: 1rem; margin-bottom: 1rem;">';
            $html .= '<h4 style="color: #721c24; margin: 0 0 0.75rem 0; display: flex; align-items: center; gap: 0.5rem;">';
            $html .= '<span>❌</span> Zu überprüfende Einstellungen (' . count($failed_items) . ')';
            $html .= '</h4>';
            $html .= '<ul style="margin: 0; padding-left: 1.5rem; color: #721c24;">';
            foreach ($failed_items as $item) {
                $html .= '<li style="margin-bottom: 0.25rem;"><strong>' . htmlspecialchars($item) . '</strong></li>';
            }
            $html .= '</ul></div>';
        }

        // Manuelle Checks
        if (!empty($results['manuell_check'])) {
            $html .= '<div style="background: #fff3cd; border: 1px solid #ffeeba; border-radius: 0.375rem; padding: 1rem; margin-bottom: 1rem;">';
            $html .= '<h4 style="color: #856404; margin: 0 0 0.75rem 0; display: flex; align-items: center; gap: 0.5rem;">';
            $html .= '<span>⚠️</span> Manuelle Überprüfung erforderlich';
            $html .= '</h4>';
            
            foreach ($results['manuell_check'] as $key => $data) {
                $name = $settings_descriptions[$key] ?? $key;
                $status_icon = $data['status'] ? '✅' : '⚠️';
                $status_text = $data['status'] ? 'OK' : 'Zu prüfen';
                
                $html .= '<div style="margin-bottom: 0.75rem; padding: 0.5rem; background: white; border-radius: 0.25rem;">';
                $html .= '<div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">';
                $html .= '<span>' . $status_icon . '</span>';
                $html .= '<strong style="color: #856404;">' . htmlspecialchars($name) . ': ' . $status_text . '</strong>';
                $html .= '</div>';
                $html .= '<p style="margin: 0; color: #6c757d; font-size: 0.9rem; padding-left: 1.5rem;">';
                $html .= htmlspecialchars($data['message']);
                $html .= '</p></div>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Deutsche Beschreibungen für Settings
     */
    private function getSettingDescriptions(): array
    {
        return array(
            'fragenpools_verwenden' => 'Fragenpools verwenden',
            'datenschutz' => 'Datenschutz (Testergebnisse mit Namen ausgeben)', 
            'online' => 'Test ist online geschaltet',
            'passwort' => 'Testpasswort gesetzt',
            'nur_ausgewaehlte_teilnehmer' => 'Nur ausgewählte Teilnehmer (deaktiviert)',
            'testdurchlaeufe_begrenzen' => 'Anzahl Testdurchläufe auf 1 begrenzt',
            'wartezeit_erzwingen' => 'Wartezeit zwischen Durchläufen (deaktiviert)',
            'pruefungsansicht' => 'Prüfungsansicht (Kiosk-Modus) aktiviert',
            'automatisches_speichern' => 'Automatisches Speichern alle 30 Sekunden',
            'direkte_rueckmeldung' => 'Direkte Rückmeldung deaktiviert',
            'fragen_markieren' => 'Fragen markieren aktiviert',
            'eigenstaendigkeitserklaerung' => 'Eigenständigkeitserklärung (Fernprüfung)',
            'bearbeitungsdauer' => 'Bearbeitungsdauer begrenzen aktiviert',
            'weiterleitung' => 'Weiterleitung zu logout.php (nur bei Kiosk)',
            'bewertungssystem' => 'Auch teilweise falsche Antworten erzielen Punkte',
            'negative_punkte' => 'Negative Punkte pro Frage deaktiviert',
            'besten_durchlauf_bewerten' => 'Besten Testdurchlauf bewerten aktiviert',
            'start_und_ende' => 'Start- und Endzeiten korrekt gesetzt',
        );
    }

    /**
     * Hilfsfunktion: Prüft ob DateTime ein Default-Wert ist
     */
    private function isDefaultDateTime($timestamp): bool
    {
        if (!$timestamp || $timestamp <= 0) {
            return true;
        }
        
        $date_str = date('d.m.Y', $timestamp);
        // Prüfe auf typische Default-Werte
        return in_array($date_str, array('01.04.2022', '01.12.2022', '01.01.1970'));
    }

    /**
     * Gibt Plugin-Info zurück
     */
    public function getInfo(): array
    {
        return array(
            'name' => 'ExamChecker - WIP',
            'title' => 'Prüfungs-Einstellungen Checker',
            'icon' => '🔍',
            'description' => 'Überprüft Test-Einstellungen gegen definierte Standards für Präsenz- und Fernprüfungen.',
            'version' => '0.0.1',
            'features' => array(

            )
        );
    }
}