<?php

/**
 * Installiert, deinstalliert und verwaltet Module aus ZIP-Paketen.
 *
 * Sicherheit:
 * - Nur ILIAS-Admins dürfen die Methoden aufrufen (Prüfung obliegt dem Aufrufer / ConfigGUI)
 * - ZIP-Inhalt wird vor dem Entpacken validiert (module.json, erlaubte Endungen, kein Path-Traversal)
 * - Dateien werden in modules/<id>/ entpackt (außerhalb des classes/-Autoloaders)
 */
require_once __DIR__ . '/ilModuleManifest.php';

class ilModuleInstaller
{
    private const ALLOWED_EXTENSIONS = ['php', 'js', 'css', 'sql', 'json', 'html', 'tpl', 'md', 'txt', 'lang', 'png', 'svg', 'gif', 'jpg', 'jpeg', 'woff', 'woff2', 'ttf'];

    /**
     * Ressourcengrenzen gegen Dekompressions-DoS (Regel R11).
     *
     * Ein Limit auf die komprimierte Datei allein schützt nicht: ein 20-MB-Archiv
     * kann sich auf ein Vielfaches aufblasen. Deshalb werden Einträge, entpackte
     * Gesamtgröße, Einzeldateigröße und Kompressionsverhältnis VOR dem Entpacken
     * über statIndex() summiert.
     *
     * Referenzwerte der 18 produktiven Module (Stand 2026-08): max. 24 Dateien,
     * 1,4 MB entpackt, größte Einzeldatei 1,0 MB, Verhältnis 4,7.
     */
    private const MAX_ZIP_SIZE_BYTES        = 20 * 1024 * 1024;  // 20 MB komprimiert
    private const MAX_ZIP_ENTRIES           = 500;
    private const MAX_UNCOMPRESSED_BYTES    = 60 * 1024 * 1024;  // 60 MB entpackt
    private const MAX_SINGLE_FILE_BYTES     = 20 * 1024 * 1024;  // 20 MB je Datei
    private const MAX_COMPRESSION_RATIO     = 50;
    /** Unterhalb dieser Größe ist das Verhältnis nicht aussagekräftig. */
    private const RATIO_CHECK_MIN_BYTES     = 64 * 1024;

    private string $modules_dir;
    private string $plugin_path;

    public function __construct(string $plugin_path)
    {
        $this->plugin_path  = rtrim($plugin_path, '/');
        $this->modules_dir  = $this->plugin_path . '/modules';
    }

    // -------------------------------------------------------------------------
    // Installieren
    // -------------------------------------------------------------------------

    /**
     * Installiert ein Modul aus einer hochgeladenen ZIP-Datei.
     * Gibt bei Erfolg die module.json-Daten zurück, bei Fehler eine Exception.
     *
     * @throws RuntimeException Bei Validierungs- oder Dateisystem-Fehlern
     */
    public function installFromZip(string $tmp_file_path): array
    {
        if (!file_exists($tmp_file_path)) {
            throw new RuntimeException('Hochgeladene Datei nicht gefunden.');
        }

        if (filesize($tmp_file_path) > self::MAX_ZIP_SIZE_BYTES) {
            throw new RuntimeException('ZIP-Datei ist zu groß (max. 20 MB).');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp_file_path) !== true) {
            throw new RuntimeException('Datei ist kein gültiges ZIP-Archiv.');
        }

        try {
            $module_info = $this->validateZip($zip);
            $module_id   = $module_info['id'];

            $target_dir = $this->modules_dir . '/' . $module_id;

            // Sicherstellen dass modules/ existiert
            if (!is_dir($this->modules_dir) && !mkdir($this->modules_dir, 0755, true)) {
                throw new RuntimeException('modules/-Verzeichnis konnte nicht erstellt werden. Bitte Schreibrechte prüfen.');
            }

            // Altes Modul entfernen falls vorhanden (Update)
            if (is_dir($target_dir)) {
                $this->removeDirectory($target_dir);
            }

            // Entpacken
            $this->extractZip($zip, $target_dir);

            // DB-Tabellen anlegen
            $this->runInstallSql($target_dir);

            // Audit-Log mit User-Details
            global $DIC;
            $user_info = $DIC->user()->getId() . '/' . $DIC->user()->getLogin();
            error_log("[ModuleInstaller] Modul '{$module_id}' installiert von User $user_info in $target_dir");

            return $module_info;

        } finally {
            $zip->close();
        }
    }

    /**
     * Liest und validiert das Manifest eines ZIPs, OHNE etwas zu installieren.
     *
     * Damit lässt sich vor dem Schreiben prüfen, ob das heruntergeladene Artefakt
     * wirklich das angeforderte Modul in der erwarteten Version ist (Regel R13).
     *
     * @throws RuntimeException
     */
    public function readManifestFromZip(string $zip_file_path): array
    {
        if (!file_exists($zip_file_path)) {
            throw new RuntimeException('ZIP-Datei nicht gefunden.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_file_path) !== true) {
            throw new RuntimeException('Datei ist kein gültiges ZIP-Archiv.');
        }

        try {
            return $this->validateZip($zip);
        } finally {
            $zip->close();
        }
    }

    // -------------------------------------------------------------------------
    // Deinstallieren
    // -------------------------------------------------------------------------

    /**
     * Deinstalliert ein Modul: Tabellen löschen + Ordner entfernen.
     * Nur für uploaded Module (nicht für built-in).
     *
     * @throws RuntimeException
     */
    public function uninstallModule(string $module_id, array $module_info): void
    {
        if ($module_info['builtin'] ?? false) {
            throw new RuntimeException("Built-in Module können nicht deinstalliert werden (nur deaktivieren).");
        }

        $module_dir = $this->modules_dir . '/' . $module_id;

        // 1. Uninstall-SQL ausführen (Tabellen löschen).
        //    Schlägt das fehl, wird NICHT weitergemacht: sonst verschwände der
        //    einzige mitgelieferte Deinstallationscode, während die Tabellen samt
        //    personenbezogener Daten stehen bleiben (Regel R10/R14).
        $this->runUninstallSql($module_dir);

        // 2. Modul-Ordner entfernen — erst nach erfolgreicher Datenbereinigung
        if (is_dir($module_dir)) {
            $this->removeDirectory($module_dir);
        }

        // Audit-Log mit User-Details
        global $DIC;
        $user_info = $DIC->user()->getId() . '/' . $DIC->user()->getLogin();
        error_log("[ModuleInstaller] Modul '$module_id' deinstalliert von User $user_info");
    }

    // -------------------------------------------------------------------------
    // Validierung
    // -------------------------------------------------------------------------

    /**
     * Prüft ZIP-Inhalt vor dem Entpacken.
     * Gibt module.json-Daten zurück oder wirft eine Exception.
     */
    private function validateZip(ZipArchive $zip): array
    {
        $module_json_content = null;

        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
            throw new RuntimeException(
                'ZIP enthält zu viele Einträge (' . $zip->numFiles . ', max. ' . self::MAX_ZIP_ENTRIES . ').'
            );
        }

        $total_uncompressed = 0;
        $total_compressed   = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Path-Traversal verhindern
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                throw new RuntimeException("Ungültiger Dateipfad im ZIP: '$name'");
            }

            // Verzeichnis-Einträge überspringen (haben keine Dateiendung)
            if (str_ends_with($name, '/')) {
                continue;
            }

            // Nur erlaubte Dateiendungen
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext !== '' && !in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                throw new RuntimeException("Nicht erlaubte Dateiendung '$ext' in: $name");
            }

            // Größen VOR dem Entpacken aus dem Zentralverzeichnis summieren
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new RuntimeException("ZIP-Eintrag nicht lesbar: $name");
            }

            $size = (int) ($stat['size'] ?? 0);
            if ($size > self::MAX_SINGLE_FILE_BYTES) {
                throw new RuntimeException(sprintf(
                    'Datei im ZIP ist zu groß: %s (%d MB, max. %d MB).',
                    $name,
                    (int) round($size / 1048576),
                    (int) (self::MAX_SINGLE_FILE_BYTES / 1048576)
                ));
            }

            $total_uncompressed += $size;
            $total_compressed   += (int) ($stat['comp_size'] ?? 0);

            if ($total_uncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                throw new RuntimeException(sprintf(
                    'Entpackter Inhalt ist zu groß (max. %d MB).',
                    (int) (self::MAX_UNCOMPRESSED_BYTES / 1048576)
                ));
            }

            // module.json einlesen (darf in keinem Unterordner liegen)
            if ($name === 'module.json' || preg_match('#^[^/]+/module\.json$#', $name)) {
                $module_json_content = $zip->getFromIndex($i);
            }
        }

        // Kompressionsverhältnis — nur bei nennenswerter Größe aussagekräftig
        if ($total_compressed >= self::RATIO_CHECK_MIN_BYTES
            && $total_uncompressed / $total_compressed > self::MAX_COMPRESSION_RATIO) {
            throw new RuntimeException(
                'Verdächtiges Kompressionsverhältnis — das Archiv wird abgelehnt.'
            );
        }

        if ($module_json_content === null) {
            throw new RuntimeException('module.json fehlt im ZIP-Archiv.');
        }

        $info = json_decode($module_json_content, true);
        if (!is_array($info)) {
            throw new RuntimeException('module.json ist kein gültiges JSON.');
        }

        // Gemeinsame Validierung mit Registry und Proxy (ilModuleManifest)
        return ilModuleManifest::validate($info);
    }

    // -------------------------------------------------------------------------
    // Entpacken
    // -------------------------------------------------------------------------

    /**
     * Entpackt ZIP nach $target_dir.
     * Wenn das ZIP einen Root-Ordner hat (z.B. ExamCalc/...), wird dieser übersprungen.
     */
    private function extractZip(ZipArchive $zip, string $target_dir): void
    {
        if (!mkdir($target_dir, 0755, true) && !is_dir($target_dir)) {
            throw new RuntimeException("Konnte Zielverzeichnis nicht anlegen: $target_dir");
        }

        // Root-Prefix erkennen (falls ZIP einen Wrapper-Ordner hat)
        $prefix = $this->detectZipPrefix($zip);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Root-Prefix entfernen
            $relative = ($prefix !== '' && str_starts_with($name, $prefix))
                ? substr($name, strlen($prefix))
                : $name;

            if ($relative === '' || str_ends_with($relative, '/')) {
                // Verzeichnis-Eintrag
                $dir = $target_dir . '/' . $relative;
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                continue;
            }

            $target_file = $target_dir . '/' . $relative;
            $parent      = dirname($target_file);

            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
            }

            $content = $zip->getFromIndex($i);
            if ($content === false) {
                throw new RuntimeException("Konnte Datei nicht lesen: $name");
            }

            file_put_contents($target_file, $content);
        }
    }

    /**
     * Erkennt ob alle Dateien im ZIP unter einem gemeinsamen Root-Ordner liegen.
     */
    private function detectZipPrefix(ZipArchive $zip): string
    {
        if ($zip->numFiles === 0) {
            return '';
        }

        $first = $zip->getNameIndex(0);
        $slash = strpos($first, '/');

        if ($slash === false) {
            return '';
        }

        $candidate = substr($first, 0, $slash + 1);

        for ($i = 1; $i < $zip->numFiles; $i++) {
            if (!str_starts_with($zip->getNameIndex($i), $candidate)) {
                return '';
            }
        }

        return $candidate;
    }

    // -------------------------------------------------------------------------
    // SQL
    // -------------------------------------------------------------------------

    private function runInstallSql(string $module_dir): void
    {
        $install_file = $module_dir . '/sql/install.php';
        if (file_exists($install_file)) {
            try {
                global $DIC;
                $db = $DIC->database();
                // Scope-Isolation: install.php hat nur Zugriff auf $db
                (function () use ($install_file, $db): void {
                    require $install_file;
                })();
            } catch (Throwable $e) {
                error_log("[ModuleInstaller] Fehler in install.php: " . $e->getMessage());
                throw new RuntimeException("DB-Installation fehlgeschlagen: " . $e->getMessage());
            }
        }
    }

    /**
     * Führt sql/uninstall.php aus. Fehler werden an den Aufrufer propagiert —
     * ein stillschweigend verschluckter Fehler hätte zur Folge, dass die GUI
     * "erfolgreich deinstalliert" meldet, während Tabellen und Daten bleiben.
     *
     * @throws RuntimeException
     */
    private function runUninstallSql(string $module_dir): void
    {
        $uninstall_file = $module_dir . '/sql/uninstall.php';
        if (file_exists($uninstall_file)) {
            try {
                global $DIC;
                $db = $DIC->database();
                // Scope-Isolation: uninstall.php hat nur Zugriff auf $db
                (function () use ($uninstall_file, $db): void {
                    require $uninstall_file;
                })();
            } catch (Throwable $e) {
                error_log("[ModuleInstaller] Fehler in uninstall.php: " . $e->getMessage());
                throw new RuntimeException(
                    'Datenbank-Deinstallation fehlgeschlagen, das Modul wurde NICHT entfernt: '
                    . $e->getMessage()
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Hilfsmethoden
    // -------------------------------------------------------------------------

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }

        rmdir($dir);
    }
}
