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
 * Sicherheitsgrenze (siehe .dev/security/SICHERHEITSREGELN.md):
 * - R3: Module werden ausschließlich über die Registry aufgelöst; deaktivierte
 *       Module sind nicht erreichbar.
 * - R2: ref_id muss auf ein Testobjekt zeigen.
 * - R1: Der Proxy autorisiert NUR ref_id. Welche Daten die Modul-GUI danach über
 *       eigene Parameter selektiert, muss sie selbst autorisieren.
 *
 * @ilCtrl_isCalledBy ilExtendedTestSettingsModuleProxyGUI: ilUIPluginRouterGUI
 */
class ilExtendedTestSettingsModuleProxyGUI
{
    public function __construct()
    {
        require_once __DIR__ . '/Module/ilModuleManifest.php';
    }

    /**
     * Löst eine Sprachvariable des Plugins auf. Die Klasse erbt nicht von einer
     * Plugin-Basisklasse, daher wird die Instanz über getInstance() bezogen.
     */
    private function txt(string $key): string
    {
        return ilExtendedTestSettingsPlugin::getInstance()->txt($key);
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
        if ($cmd === 'serveresourcefile') {
            $this->serveResourceFile();
            return;
        }

        $ref_id    = (int) ($_GET['ref_id'] ?? 0);
        $module_id = preg_replace('/[^a-z0-9_]/', '', strtolower($_GET['module_id'] ?? ''));

        if ($module_id === '') {
            $this->fail($this->txt('no_module_specified'));
            return;
        }

        // 2. Modul ausschließlich über die Registry auflösen.
        //    Damit ist der Aktivstatus ein echter Kill-Switch: deaktivierte Module
        //    sind über keinen Proxy-Aufruf mehr erreichbar.
        $registry    = ilExtendedTestSettingsPlugin::getInstance()->getRegistry();
        $module_info = $registry->getActiveModuleInfo($module_id);

        if ($module_info === null) {
            $exists = $registry->moduleExists($module_id);
            error_log("[ModuleProxyGUI] Dispatch abgelehnt für '$module_id' ("
                . ($exists ? 'inaktiv' : 'unbekannt') . ')');
            $this->fail(sprintf(
                $this->txt($exists ? 'module_not_active' : 'module_not_found'),
                $module_id
            ));
            return;
        }

        // 3. Objekttyp prüfen — checkAccess() sagt nur "darf schreiben",
        //    nicht "ist ein Test".
        if ($ref_id <= 0 || ilObject::_lookupType($ref_id, true) !== 'tst') {
            $this->fail($this->txt('not_a_test'));
            return;
        }

        // 4. Zugriffsprüfung: Schreibrecht oder (bei allow_student_access) Leserecht
        if (!empty($module_info['allow_student_access'])) {
            $has_write = $rbacsystem->checkAccess('write', $ref_id);
            $has_read  = $rbacsystem->checkAccess('read',  $ref_id);
            if (!$has_write && !$has_read) {
                while (ob_get_level()) {
                    ob_end_clean();
                }
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'forbidden']);
                exit;
            }
        } else {
            if (!$rbacsystem->checkAccess('write', $ref_id)) {
                $this->fail($this->txt('access_denied'));
                return;
            }
        }

        $module_dir = $module_info['path'];
        $gui_file   = $module_info['gui_file']  ?? null;
        $gui_class  = $module_info['gui_class'] ?? null;

        if (!$gui_file || !$gui_class) {
            $this->fail(sprintf($this->txt('module_no_gui_class'), $module_id));
            return;
        }

        // Abhängigkeiten laden — Pfade sind bereits validiert, werden aber
        // zusätzlich per realpath() in den Modulordner gezwungen.
        foreach ($module_info['require_files'] ?? [] as $rel_file) {
            $dep_path = ilModuleManifest::resolveFile($module_dir, (string) $rel_file);
            if ($dep_path === null) {
                error_log("[ModuleProxyGUI] require_files-Eintrag abgelehnt: $rel_file");
                continue;
            }
            require_once $dep_path;
        }

        $handler_path = ilModuleManifest::resolveFile($module_dir, (string) $gui_file);
        if ($handler_path === null) {
            $this->fail(sprintf($this->txt('gui_file_not_found'), $gui_file));
            return;
        }

        require_once $handler_path;

        if (!class_exists($gui_class)) {
            $this->fail(sprintf($this->txt('gui_class_not_found'), $gui_class));
            return;
        }

        $gui = new $gui_class();
        $gui->executeCommand();
    }

    /**
     * Bricht den Dispatch mit einer Meldung ab.
     */
    private function fail(string $message): void
    {
        global $DIC;

        $DIC->ui()->mainTemplate()->setOnScreenMessage('failure', $message);
        $DIC->ui()->mainTemplate()->printToStdout();
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


        if ($ref_id <= 0 || strlen($token) !== 32) {
            http_response_code(400);
            exit;
        }

        // Auch der Datei-Endpunkt muss die globale Modulsperre respektieren.
        // Den Besitzer über die deklarierte Tabelle auflösen: keine Annahme
        // über Modul-ID, Verzeichnisnamen oder einen module_id-Requestparameter.
        $registry = ilExtendedTestSettingsPlugin::getInstance()->getRegistry();
        if (!$this->isResourceModuleActive($registry)) {
            http_response_code(403);
            exit;
        }

        if (ilObject::_lookupType($ref_id, true) !== 'tst') {
            http_response_code(404);
            exit;
        }

        // obj_id des Tests — so heißt die Spalte test_id in den Modultabellen.
        // NICHT zu verwechseln mit tst_tests.test_id (siehe hasActiveTestSession).
        $test_id = ilObject::_lookupObjectId($ref_id);
        if ($test_id <= 0) {
            http_response_code(404);
            exit;
        }

        $has_write  = $rbacsystem->checkAccess('write', $ref_id);
        $has_session = $this->hasActiveTestSession($DIC, $test_id);
        $has_access = $has_session || $has_write;

        if (!$has_access) {
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

        $is_html = ($mime === 'text/html');

        // Diese URL ist ein Endpunkt für den Player (fetch), keine Seite.
        // Der Browser markiert Top-Level-Navigation selbst; Seiten-JS kann die
        // Sec-Fetch-*-Header nicht fälschen (forbidden header names).
        // Fehlt der Header (Safari < 16.4), greift unten Content-Disposition.
        if ($is_html && ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'document') {
            http_response_code(403);
            exit;
        }

        // ILIAS bufferiert für jeden ilias.php-Request HTML-Output und setzt eigene
        // Sicherheits-Header. Beides muss verworfen werden bevor wir die Datei senden —
        // an ihre Stelle tritt eine EIGENE, engere Policy (nie ersatzlos entfernen).
        while (ob_get_level()) {
            ob_end_clean();
        }
        header_remove('X-Frame-Options');
        header_remove('Content-Security-Policy');
        header_remove('X-Content-Security-Policy');
        header_remove('X-WebKit-CSP');

        // sandbox ohne allow-scripts: der Inhalt läuft in einer opaken Origin
        // und führt kein JavaScript aus, auch nicht als Top-Level-Dokument.
        header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:");
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');

        // Hochgeladenes HTML wird nie gerendert — nur PDF darf inline.
        $disposition = $is_html ? 'attachment' : 'inline';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition . '; filename="' . addslashes(basename($row['filename'])) . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($file_path);
        exit;
    }

    /**
     * Prüft, ob der Benutzer einen LAUFENDEN Testdurchlauf hat.
     *
     * Ein tst_active-Eintrag bleibt nach der Abgabe bestehen und beweist nur, dass
     * jemand den Test irgendwann begonnen hat — ohne submitted = 0 behielten
     * ehemalige Teilnehmer den Zugriff dauerhaft (Regel R7).
     *
     * Achtung: tst_active.test_fi referenziert tst_tests.test_id, nicht die obj_id.
     */
    private function hasActiveTestSession($DIC, int $test_obj_id): bool
    {
        $user_id = $DIC->user()->getId();
        $db      = $DIC->database();

        if ($user_id <= 0) {
            return false;
        }

        try {
            $result = $db->queryF(
                "SELECT a.active_id
                   FROM tst_active a
                   JOIN tst_tests t ON t.test_id = a.test_fi
                  WHERE t.obj_fi = %s AND a.user_fi = %s AND a.submitted = %s",
                ['integer', 'integer', 'integer'],
                [$test_obj_id, $user_id, 0]
            );
            return (bool) $db->fetchAssoc($result);
        } catch (Exception $e) {
            error_log("[ModuleProxyGUI] hasActiveTestSession Fehler: " . $e->getMessage());
            return false;
        }
    }

    /** Nur ein eindeutig zugeordnetes, global aktives Modul darf Dateien liefern. */
    private function isResourceModuleActive(ilModuleRegistry $registry): bool
    {
        $owners = [];
        foreach ($registry->getAllModuleInfos() as $info) {
            if (in_array('examressheet_settings', $info['tables'] ?? [], true)) {
                $owners[] = $info['id'];
            }
        }

        return count($owners) === 1 && $registry->getActiveModuleInfo($owners[0]) !== null;
    }
}
