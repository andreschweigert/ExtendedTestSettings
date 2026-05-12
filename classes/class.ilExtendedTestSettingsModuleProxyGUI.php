<?php

/**
 * Zentraler Dispatcher für alle installierten Modul-GUIs.
 * Ermöglicht das Routing zu Modul-GUI-Klassen ohne Classmap-Neugenerierung.
 *
 * URL-Schema:
 *   ilias.php?...&cmdClass=ilextendedtestsettingsmoduleproxygui
 *           &cmd=<command>&module_id=<module_id>&ref_id=<ref_id>
 *
 * Jedes Modul das eine GUI benötigt, trägt in module.json ein:
 *   "gui_file": "class.ilMyModuleGUI.php"
 *   "gui_class": "ilMyModuleGUI"
 *
 * @ilCtrl_isCalledBy ilExtendedTestSettingsModuleProxyGUI: ilUIPluginRouterGUI
 */
class ilExtendedTestSettingsModuleProxyGUI
{
    private string $plugin_path;

    public function __construct()
    {
        $this->plugin_path = dirname(__DIR__);
    }

    public function executeCommand(): void
    {
        global $DIC, $rbacsystem;

        // 1. Authentifizierung prüfen
        if (!$DIC->user()->getId() || $DIC->user()->isAnonymous()) {
            $DIC->ctrl()->redirectToURL('./login.php');
            return;
        }

        // Datei-Auslieferung: eigene Auth-Prüfung (kein Schreibrecht erforderlich)
        $cmd = strtolower($_GET['cmd'] ?? '');
        error_log("[ExamResourceSheet] ModuleProxyGUI::executeCommand cmd='$cmd'");
        if ($cmd === 'serveresourcefile') {
            $this->serveResourceFile();
            return;
        }

        // 2. ref_id validieren und Schreibberechtigung prüfen
        $ref_id = (int) ($_GET['ref_id'] ?? 0);
        if ($ref_id <= 0 || !$rbacsystem->checkAccess('write', $ref_id)) {
            $DIC->ui()->mainTemplate()->setOnScreenMessage('failure', 'Zugriff verweigert.');
            $DIC->ui()->mainTemplate()->printToStdout();
            return;
        }

        $module_id = preg_replace('/[^a-z0-9_]/', '', strtolower($_GET['module_id'] ?? ''));

        if ($module_id === '') {
            $DIC->ui()->mainTemplate()->setOnScreenMessage('failure', 'Kein Modul angegeben.');
            $DIC->ui()->mainTemplate()->printToStdout();
            return;
        }

        $module_dir = $this->plugin_path . '/modules/' . $module_id;
        $json_file  = $module_dir . '/module.json';

        if (!file_exists($json_file)) {
            // Built-in Modul? Dann in classes/ suchen
            $builtin_dir = $this->plugin_path . '/classes/' . ucfirst($module_id);
            $json_file   = $builtin_dir . '/module.json';
            if (!file_exists($json_file)) {
                $DIC->ui()->mainTemplate()->setOnScreenMessage('failure', "Modul '$module_id' nicht gefunden.");
                $DIC->ui()->mainTemplate()->printToStdout();
                return;
            }
            $module_dir = $builtin_dir;
        }

        $module_info = json_decode((string) file_get_contents($json_file), true);
        $gui_file    = $module_info['gui_file']  ?? null;
        $gui_class   = $module_info['gui_class'] ?? null;

        if (!$gui_file || !$gui_class) {
            $DIC->ui()->mainTemplate()->setOnScreenMessage('failure', "Modul '$module_id' hat keine GUI-Klasse definiert.");
            $DIC->ui()->mainTemplate()->printToStdout();
            return;
        }

        // Abhängigkeiten laden (mit Path-Traversal-Schutz)
        $base_real = realpath($module_dir);
        foreach ($module_info['require_files'] ?? [] as $rel_file) {
            if (str_contains($rel_file, '..')) {
                error_log("[ModuleProxyGUI] Blocked path traversal in require_files: $rel_file");
                continue;
            }
            $path = $module_dir . '/' . $rel_file;
            $real = realpath($path);
            if ($real && $base_real && str_starts_with($real, $base_real . '/')) {
                require_once $real;
            }
        }

        $handler_path = $module_dir . '/' . $gui_file;
        if (!file_exists($handler_path)) {
            $DIC->ui()->mainTemplate()->setOnScreenMessage('failure', "GUI-Datei nicht gefunden: $gui_file");
            $DIC->ui()->mainTemplate()->printToStdout();
            return;
        }

        require_once $handler_path;

        if (!class_exists($gui_class)) {
            $DIC->ui()->mainTemplate()->setOnScreenMessage('failure', "GUI-Klasse nicht gefunden: $gui_class");
            $DIC->ui()->mainTemplate()->printToStdout();
            return;
        }

        $gui = new $gui_class();
        $gui->executeCommand();
    }

    /**
     * Liefert das Hilfsmittelblatt (PDF/HTML) an autorisierte Nutzer aus.
     * Zugriff: eingeloggt + (aktive Test-Session ODER Schreibrecht auf Test)
     * Token-Validierung gegen stored_name in DB (Capability URL).
     */
    private function serveResourceFile(): void
    {
        global $DIC, $rbacsystem;

        $ref_id = (int) ($_GET['ref_id'] ?? 0);
        $token  = preg_replace('/[^a-f0-9]/', '', strtolower($_GET['token'] ?? ''));

        error_log("[ExamResourceSheet] serveResourceFile reached: ref_id=$ref_id token_len=" . strlen($token));

        if ($ref_id <= 0 || strlen($token) !== 32) {
            error_log("[ExamResourceSheet] serveResourceFile 400: bad ref_id or token");
            http_response_code(400);
            exit;
        }

        $test_id = ilObject::_lookupObjectId($ref_id);
        if ($test_id <= 0) {
            error_log("[ExamResourceSheet] serveResourceFile 404: test_id not found for ref_id=$ref_id");
            http_response_code(404);
            exit;
        }

        $has_write  = $rbacsystem->checkAccess('write', $ref_id);
        $has_session = $this->hasActiveTestSession($DIC, $test_id);
        $has_access = $has_session || $has_write;
        error_log("[ExamResourceSheet] serveResourceFile auth: test_id=$test_id write=$has_write session=$has_session");

        if (!$has_access) {
            error_log("[ExamResourceSheet] serveResourceFile 403: access denied");
            http_response_code(403);
            exit;
        }

        $db     = $DIC->database();
        $result = $db->queryF(
            "SELECT stored_name, filename, mimetype FROM examressheet_settings
             WHERE test_id = %s AND enabled = 1",
            ['integer'],
            [$test_id]
        );
        $row = $db->fetchAssoc($result);

        if (!$row || empty($row['stored_name'])) {
            http_response_code(404);
            exit;
        }

        $stored_name = $row['stored_name'];
        $ext         = pathinfo($stored_name, PATHINFO_EXTENSION);
        $expected    = $test_id . '_' . $token . '.' . $ext;

        if ($stored_name !== $expected) {
            http_response_code(403);
            exit;
        }

        $base      = defined('CLIENT_DATA_DIR') ? CLIENT_DATA_DIR : (ILIAS_DATA_DIR . '/' . CLIENT_ID);
        $file_path = rtrim($base, '/') . '/plugins/ExtendedTestSettings/resourcesheets/' . $stored_name;

        if (!is_file($file_path)) {
            http_response_code(404);
            exit;
        }

        $mime = $row['mimetype'];
        if (!in_array($mime, ['application/pdf', 'text/html'], true)) {
            http_response_code(403);
            exit;
        }

        // ILIAS bufferiert für jeden ilias.php-Request HTML-Output und setzt eigene
        // Sicherheits-Header. Beides muss verworfen werden bevor wir die Datei senden.
        error_log("[ExamResourceSheet] headers BEFORE remove: " . implode(' | ', headers_list()));
        while (ob_get_level()) {
            ob_end_clean();
        }
        header_remove('X-Frame-Options');
        header_remove('Content-Security-Policy');
        header_remove('X-Content-Security-Policy');
        header_remove('X-WebKit-CSP');
        error_log("[ExamResourceSheet] headers AFTER remove: " . implode(' | ', headers_list()));

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . addslashes(basename($row['filename'])) . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($file_path);
        exit;
    }

    private function hasActiveTestSession($DIC, int $test_id): bool
    {
        $user_id = $DIC->user()->getId();
        $db      = $DIC->database();

        try {
            $result = $db->queryF(
                "SELECT active_id FROM tst_active
                 WHERE test_fi = %s AND user_fi = %s AND last_finished_pass IS NULL
                 LIMIT 1",
                ['integer', 'integer'],
                [$test_id, $user_id]
            );
            return (bool) $db->fetchAssoc($result);
        } catch (Exception $e) {
            error_log("[ExamResourceSheet] hasActiveTestSession Fehler: " . $e->getMessage());
            return false;
        }
    }
}
