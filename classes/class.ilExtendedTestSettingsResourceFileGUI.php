<?php

/**
 * Liefert das Hilfsmittelblatt (PDF/HTML) für autorisierte Test-Teilnehmer aus.
 * Die Datei liegt außerhalb des Web-Roots und wird über diesen Endpoint ausgeliefert.
 *
 * Zugriffslogik:
 *   - Eingeloggt + aktive Test-Session ODER Schreibrecht auf den Test (Dozent)
 *   - Token-Validierung gegen stored_name in DB (Capability URL)
 *
 * @ilCtrl_isCalledBy ilExtendedTestSettingsResourceFileGUI: ilUIPluginRouterGUI
 */
class ilExtendedTestSettingsResourceFileGUI
{
    public function executeCommand(): void
    {
        global $DIC;

        // Eingeloggt?
        if (!$DIC->user()->getId() || $DIC->user()->isAnonymous()) {
            http_response_code(403);
            exit;
        }

        $ref_id = (int) ($_GET['ref_id'] ?? 0);
        // Token darf nur aus Hex-Zeichen bestehen (32 Chars)
        $token  = preg_replace('/[^a-f0-9]/', '', strtolower($_GET['token'] ?? ''));

        if ($ref_id <= 0 || strlen($token) !== 32) {
            http_response_code(400);
            exit;
        }

        $test_id = ilObject::_lookupObjectId($ref_id);
        if ($test_id <= 0) {
            http_response_code(404);
            exit;
        }

        // Zugriff: aktive Test-Session ODER Schreibrecht (Dozent/Admin)
        global $rbacsystem;
        $has_access = self::hasActiveTestSession($DIC, $test_id)
            || $rbacsystem->checkAccess('write', $ref_id);

        if (!$has_access) {
            http_response_code(403);
            exit;
        }

        // Datei-Infos aus DB laden
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

        // Token gegen stored_name validieren:
        // stored_name = "{test_id}_{token}.{ext}"
        $stored_name = $row['stored_name'];
        $ext         = pathinfo($stored_name, PATHINFO_EXTENSION);
        $expected    = $test_id . '_' . $token . '.' . $ext;

        if ($stored_name !== $expected) {
            http_response_code(403);
            exit;
        }

        // Physischen Dateipfad aufbauen
        $base      = defined('CLIENT_DATA_DIR') ? CLIENT_DATA_DIR : (ILIAS_DATA_DIR . '/' . CLIENT_ID);
        $file_path = rtrim($base, '/') . '/plugins/ExtendedTestSettings/resourcesheets/' . $stored_name;

        if (!is_file($file_path)) {
            http_response_code(404);
            exit;
        }

        // MIME-Type absichern — nur erlaubte Typen durchlassen
        $mime = $row['mimetype'];
        if (!in_array($mime, ['application/pdf', 'text/html'], true)) {
            http_response_code(403);
            exit;
        }

        // Datei ausliefern
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . addslashes(basename($row['filename'])) . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($file_path);
        exit;
    }

    private static function hasActiveTestSession($DIC, int $test_id): bool
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
