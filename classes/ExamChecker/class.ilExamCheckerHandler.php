<?php

/**
 * ExamChecker Handler - Prüfung von Test-Einstellungen gegen Standards
 * Part of ExtendedTestSettings Plugin
 */


class ilExamCheckerHandler
{
    private string $plugin_path;
    private const DEBUG_ENABLED = true;
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

        // *** NEU: Standards aus Plugin-Konfiguration laden (ILIAS 9 kompatibel) ***
        $required_standards = $this->getConfiguredStandards($exam_type);

        // Alle möglichen Checks durchführen, aber nur die konfigurierten bewerten
        $all_checks = $this->performAllChecks($test_data, $exam_type);

        // Nur die konfigurierten Standards zurückgeben
        foreach ($required_standards as $standard) {
            if (isset($all_checks[$standard])) {
                $checks[$standard] = $all_checks[$standard];
            }
        }

        return $checks;
    }

    private function getConfiguredStandards(string $exam_type): array
    {
        try {
            $settings = new ilSetting('extendedtestsettings');
            $standards_string = $settings->get("examchecker_standards_$exam_type", '');

            if (!empty($standards_string)) {
                return explode(',', $standards_string);
            }
        } catch (Exception $e) {
            error_log("[ExamChecker] Fehler beim Laden der Standards: " . $e->getMessage());
        }

        // Fallback auf hardcodierte Defaults
        return $this->getHardcodedDefaults($exam_type);
    }


    private function getHardcodedDefaults(string $exam_type): array
    {
        $defaults = [
            'fragenpools_verwenden',
            'datenschutz',
            'online',
            'passwort',
            'nur_ausgewaehlte_teilnehmer',
            'testdurchlaeufe_begrenzen',
            'wartezeit_erzwingen',
            'pruefungsansicht',
            'automatisches_speichern',
            'direkte_rueckmeldung',
            'fragen_markieren',
            'bewertungssystem',
            'negative_punkte',
            'besten_durchlauf_bewerten',
            'keine_vorbedingungen',
            'direkte_notenuebermittlung',
            'zeitlich_begrenzte_verfuegbarkeit'
        ];

        if ($exam_type === 'fern') {
            $defaults[] = 'eigenstaendigkeitserklaerung';
            $defaults[] = 'bearbeitungsdauer';
        }

        if ($exam_type === 'praesenz') {
            $defaults[] = 'weiterleitung';
        }

        return $defaults;
    }

    /**
     * *** NEU: Führt alle möglichen Checks durch (ohne Filterung) ***
     */
    private function performAllChecks(array $test_data, string $exam_type): array
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

        // Check ob Vorbedingungen aktiv
        $checks['keine_vorbedingungen'] = $this->checkKeineVorbedingungen($test_data);

        // Direkte Notenübermittlung sollte AUS sein (0)
        $checks['direkte_notenuebermittlung'] = (int)$test_data['score_reporting'] === 0;

        // Zeitlich begrenzte Verfügbarkeit prüfen
        $checks['zeitlich_begrenzte_verfuegbarkeit'] = $this->checkZeitlichBegrenzteVerfuegbarkeit($test_data);

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

        // Bewertungssystem-Checks (für beide Typen relevant)
        $checks['bewertungssystem'] = (int)$test_data['count_system'] === 0;
        $checks['negative_punkte'] = (int)$test_data['score_cutting'] === 0;
        $checks['besten_durchlauf_bewerten'] = (int)$test_data['pass_scoring'] === 1;

        return $checks;
    }

    /**
     * Prüft ob zeitlich begrenzte Verfügbarkeit DEAKTIVIERT ist
     */
    private function checkZeitlichBegrenzteVerfuegbarkeit(array $test_data): bool
    {
        try {
            global $DIC;
            $db = $DIC->database();

            // obj_id des Tests aus den Testdaten
            $obj_id = $test_data['obj_fi'];

            // ref_id des Tests ermitteln
            $ref_query = "SELECT ref_id FROM object_reference WHERE obj_id = %s AND deleted IS NULL LIMIT 1";
            $ref_result = $db->queryF($ref_query, ['integer'], [$obj_id]);
            $ref_id = 0;
            if ($ref_row = $db->fetchAssoc($ref_result)) {
                $ref_id = (int)$ref_row['ref_id'];
            }

            if ($ref_id <= 0) {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExamChecker] Keine ref_id für obj_id $obj_id gefunden");
                }
                return true; // Bei Fehler als "OK" bewerten
            }

            // Prüfe crs_items Tabelle für zeitliche Begrenzung
            $timing_query = "SELECT timing_start, timing_end FROM crs_items WHERE obj_id = %s";
            $timing_result = $db->queryF($timing_query, ['integer'], [$ref_id]);

            if ($timing_row = $db->fetchAssoc($timing_result)) {
                $timing_start = (int)($timing_row['timing_start'] ?? 0);
                $timing_end = (int)($timing_row['timing_end'] ?? 0);

                // Beide Werte sollten 0 sein (keine zeitliche Begrenzung)
                $timing_disabled = ($timing_start === 0) && ($timing_end === 0);

                if (self::DEBUG_ENABLED) {
                    error_log("[ExamChecker] Test ref_id $ref_id - timing_start: $timing_start, timing_end: $timing_end");
                    error_log("[ExamChecker] Zeitlich begrenzte Verfügbarkeit: " .
                        ($timing_disabled ? "DEAKTIVIERT (✅)" : "AKTIVIERT (❌)"));
                }

                return $timing_disabled;
            } else {
                if (self::DEBUG_ENABLED) {
                    error_log("[ExamChecker] Kein crs_items Eintrag für ref_id $ref_id gefunden");
                }
                return true; // Kein Eintrag = keine Begrenzung = OK
            }
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExamChecker] Zeitlich begrenzte Verfügbarkeit Check Fehler: " . $e->getMessage());
            }
            return true; // Bei Fehler als "OK" bewerten
        }
    }


    /**
     * Prüft ob KEINE Vorbedingungen für den Test definiert sind
     * Liefert auch Details für Warnmeldung zurück
     */
    private function checkKeineVorbedingungen(array $test_data): bool
    {
        try {
            global $DIC;
            $db = $DIC->database();

            // obj_id des Tests aus den Testdaten
            $obj_id = $test_data['obj_fi'];

            // ref_id des Tests ermitteln (für Vollständigkeit)
            $ref_query = "SELECT ref_id FROM object_reference WHERE obj_id = %s AND deleted IS NULL LIMIT 1";
            $ref_result = $db->queryF($ref_query, ['integer'], [$obj_id]);
            $ref_id = 0;
            if ($ref_row = $db->fetchAssoc($ref_result)) {
                $ref_id = (int)$ref_row['ref_id'];
            }

            // Detaillierte Vorbedingungen abrufen für Warnung
            $details_query = "
            SELECT 
                c.trigger_obj_id,
                c.trigger_ref_id, 
                c.operator,
                c.value,
                od.title as trigger_title,
                od.type as trigger_type
            FROM conditions c
            LEFT JOIN object_data od ON c.trigger_obj_id = od.obj_id
            WHERE c.target_obj_id = %s OR c.target_ref_id = %s
        ";

            $details_result = $db->queryF($details_query, ['integer', 'integer'], [$obj_id, $ref_id]);
            $conditions = [];

            while ($row = $db->fetchAssoc($details_result)) {
                $conditions[] = [
                    'trigger_title' => $row['trigger_title'] ?? 'Unbekannt',
                    'trigger_type' => $row['trigger_type'] ?? '',
                    'operator' => $row['operator'] ?? '',
                    'value' => $row['value'] ?? '',
                    'description' => $this->formatConditionDescription($row)
                ];
            }

            $has_conditions = !empty($conditions);

            // Details für HTML-Generierung speichern (Hack über globale Variable)
            global $examchecker_conditions_details;
            $examchecker_conditions_details = [
                'has_conditions' => $has_conditions,
                'count' => count($conditions),
                'details' => $conditions,
                'obj_id' => $obj_id,
                'ref_id' => $ref_id
            ];

            if (self::DEBUG_ENABLED) {
                error_log("[ExamChecker] Test obj_id $obj_id (ref_id $ref_id) hat " .
                    ($has_conditions ? count($conditions) . " VORBEDINGUNGEN (❌)" : "keine Vorbedingungen (✅)"));
            }

            // Return TRUE wenn KEINE Vorbedingungen (das ist gut für Prüfungen)
            return !$has_conditions;
        } catch (Exception $e) {
            if (self::DEBUG_ENABLED) {
                error_log("[ExamChecker] Vorbedingungen-Check Fehler: " . $e->getMessage());
            }
            return true; // Bei Fehler als "OK" bewerten
        }
    }

    /**
     * Formatiert Vorbedingung für Anzeige
     */
    private function formatConditionDescription(array $condition): string
    {
        $type_names = [
            'crs' => 'Kurs',
            'tst' => 'Test',
            'lm' => 'Lernmodul',
            'file' => 'Datei',
            'exc' => 'Übung',
            'frm' => 'Forum',
            'grp' => 'Gruppe',
            'cat' => 'Kategorie'
        ];

        $type = $type_names[$condition['trigger_type']] ?? ucfirst($condition['trigger_type']);
        $title = $condition['trigger_title'] ?? 'Unbekannt';
        $operator = $condition['operator'];
        $value = $condition['value'];

        if ($operator === 'passed') {
            return "$type \"$title\" muss bestanden werden";
        } elseif ($operator === 'finished') {
            return "$type \"$title\" muss abgeschlossen werden";
        } elseif ($operator && $value) {
            return "$type \"$title\" $operator $value";
        } else {
            return "$type \"$title\" (Bedingung: $operator)";
        }
    }

    /**
     * Prüft die Auswertungseinstellungen
     */
    private function checkAuswertung(array $test_data, string $exam_type): array
    {
        // Diese Methode ist jetzt redundant, da alles in performAllChecks() gemacht wird
        // Aber wir behalten sie für Kompatibilität
        return []; // Leeres Array, da Auswertung bereits in checkEinstellungen() integriert ist
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
     * Generiert HTML für Check-Ergebnisse mit Vorbedingungen-Warnung
     */
    private function generateCheckResultsHTML(array $results, string $exam_type): string
    {
        $settings_descriptions = $this->getSettingDescriptions();

        // *** GEÄNDERT: Nur noch Einstellungen zählen (Auswertung ist integriert) ***
        $total = count($results['einstellungen']);
        $passed = count(array_filter($results['einstellungen']));
        $failed = $total - $passed;
        $percentage = $total > 0 ? round(($passed / $total) * 100) : 0;

        // Farbschema basierend auf Prozentsatz
        if ($percentage >= 90) {
            $summary_color = '#28a745';
            $summary_icon = '🎉';
        } elseif ($percentage >= 70) {
            $summary_color = '#fd7e14';
            $summary_icon = '⚠️';
        } else {
            $summary_color = '#dc3545';
            $summary_icon = '❌';
        }

        // *** NEU: Hinweis auf konfigurierbare Standards ***
        $config_hint = $total > 0 ? '' : '
    <div style="background: #fff3cd; border: 1px solid #ffeeba; border-radius: 0.375rem; padding: 1rem; margin-bottom: 1rem;">
        <div style="color: #856404; display: flex; align-items: center; gap: 0.5rem;">
            <span>⚙️</span>
            <strong>Keine Standards konfiguriert</strong>
        </div>
        <p style="margin: 0.5rem 0 0 0; color: #856404; font-size: 0.9rem;">
            Für diesen Prüfungstyp wurden noch keine Vorgaben in der Plugin-Konfiguration festgelegt.
            <br><a href="ilias.php?baseClass=ilAdministrationGUI&cmdClass=ilobjcomponentsettingsgui&cmdNode=lg:mk&cmd=configure&pluginname=ExtendedTestSettings" style="color: #856404; text-decoration: underline;">→ Zur Plugin-Konfiguration</a>
        </p>
    </div>';

        // HTML zusammenbauen
        $html = '<div class="check-results-container" style="margin-top: 1rem;">';

        $html .= $config_hint;

        if ($total > 0) {
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
        }

        // === NEU: Vorbedingungen-Warnung ===
        global $examchecker_conditions_details;
        if (isset($examchecker_conditions_details) && $examchecker_conditions_details['has_conditions']) {
            $details = $examchecker_conditions_details;

            $html .= '<div style="
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); 
            border: 2px solid #fd7e14; 
            border-radius: 0.75rem; 
            padding: 1.5rem; 
            margin: 2rem 0; 
            box-shadow: 0 4px 12px rgba(253, 126, 20, 0.15);
            position: relative;
            overflow: hidden;
        ">';

            // Warnung-Header mit Animation
            $html .= '<div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">';
            $html .= '<div style="
            font-size: 2.5rem; 
            animation: pulse 2s infinite;
            filter: drop-shadow(2px 2px 4px rgba(0,0,0,0.1));
        ">⚠️</div>';
            $html .= '<div>';
            $html .= '<h3 style="color: #dc6545; margin: 0; font-size: 1.3rem; font-weight: 700;">
                    VORBEDINGUNGEN GEFUNDEN!
                  </h3>';
            $html .= '<p style="color: #dc6545; margin: 0.25rem 0 0 0; font-weight: 500;">
                    Dieser Test hat ' . $details['count'] . ' Vorbedingung(en) - das kann bei Prüfungen problematisch sein!
                  </p>';
            $html .= '</div></div>';

            // Details der Vorbedingungen
            $html .= '<div style="
            background: rgba(255, 255, 255, 0.7); 
            border-radius: 0.5rem; 
            padding: 1rem; 
            margin-bottom: 1rem;
            backdrop-filter: blur(5px);
        ">';
            $html .= '<h4 style="color: #dc6545; margin: 0 0 0.75rem 0; font-size: 1rem;">
                    📋 Konfigurierte Vorbedingungen:
                  </h4>';

            $html .= '<ul style="margin: 0; padding-left: 1.5rem; color: #dc6545; font-weight: 500;">';
            foreach ($details['details'] as $condition) {
                $html .= '<li style="margin-bottom: 0.5rem; line-height: 1.4;">
                        🔗 ' . htmlspecialchars($condition['description']) . '
                      </li>';
            }
            $html .= '</ul></div>';

            // Handlungsempfehlung
            $html .= '<div style="
            background: rgba(220, 101, 69, 0.1); 
            border-left: 4px solid #dc6545; 
            padding: 1rem; 
            border-radius: 0 0.375rem 0.375rem 0;
        ">';
            $html .= '<h4 style="color: #dc6545; margin: 0 0 0.5rem 0; font-size: 0.95rem;">
                    💡 ACHTUNG:
                  </h4>';
            $html .= '<p style="color: #dc6545; margin: 0; font-size: 0.9rem; line-height: 1.4;">
                    Entfernen Sie alle Vorbedingungen für Prüfungs-Tests, um sicherzustellen, 
                    dass alle Teilnehmer gleichzeitig Zugang haben.!
                  </p>';
            $html .= '</div>';

            $html .= '</div>';

            // CSS für Animation hinzufügen
            $html .= '<style>
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.1); }
                100% { transform: scale(1); }
            }
        </style>';
        }

        // Manuelle Checks (bleiben unverändert)
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
            'keine_vorbedingungen' => 'Keine Vorbedingungen für Testzugang gesetzt',
            'direkte_notenuebermittlung' => 'Direkte Notenübermittlung deaktiviert',
            'zeitlich_begrenzte_verfuegbarkeit' => 'Zeitlich begrenzte Verfügbarkeit muss deaktiviert sein'
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
            'version' => '0.0.3',
            'features' => array()
        );
    }
}
