<?php

/**
 * Zentrale Validierung von module.json.
 *
 * Wird von Installer, Registry und Proxy gemeinsam genutzt, damit ein Manifest
 * überall nach denselben Regeln geprüft wird — vorher galten beim ZIP-Upload
 * strengere Regeln als bei der Discovery bereits installierter Module.
 *
 * Sicherheitsregeln (siehe .dev/security/SICHERHEITSREGELN.md, R11/R13):
 * - IDs, Klassen- und Tabellennamen gegen enge Allowlists
 * - ALLE Dateifelder (handler_file, gui_file, require_files) werden per
 *   realpath() in den Modulordner gezwungen — nicht nur require_files
 */
class ilModuleManifest
{
    /** Modul-ID: a-z, 0-9, _ — beginnt mit Buchstabe, 3-40 Zeichen */
    public const ID_REGEX = '/^[a-z][a-z0-9_]{2,39}$/';

    /** Tabellenname: wird teilweise in SQL interpoliert, daher eng */
    public const TABLE_REGEX = '/^[a-z][a-z0-9_]{2,63}$/';

    /** PHP-Klassenname nach ILIAS-Konvention */
    public const CLASS_REGEX = '/^il[A-Za-z0-9_]{2,63}$/';

    private const REQUIRED_FIELDS = ['id', 'handler_file', 'handler_class', 'label'];

    private const MAX_PATH_LENGTH = 255;

    /**
     * Prüft ein dekodiertes module.json vollständig.
     *
     * @param  array $info Inhalt der module.json
     * @return array       dasselbe Manifest (unverändert, zur Weiterverwendung)
     * @throws RuntimeException bei jedem Regelverstoß
     */
    public static function validate(array $info): array
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (empty($info[$field]) || !is_string($info[$field])) {
                throw new RuntimeException("Pflichtfeld '$field' fehlt oder ist kein String in module.json.");
            }
        }

        if (!preg_match(self::ID_REGEX, $info['id'])) {
            throw new RuntimeException(
                "Ungültige Modul-ID '{$info['id']}'. Erlaubt: a-z, 0-9, _ (3-40 Zeichen, beginnt mit Buchstabe)."
            );
        }

        // Klassennamen — gui_class nur wenn gesetzt (Module ohne GUI sind zulässig)
        foreach (['handler_class', 'gui_class'] as $field) {
            if (!empty($info[$field])) {
                if (!is_string($info[$field]) || !preg_match(self::CLASS_REGEX, $info[$field])) {
                    throw new RuntimeException("Ungültiger Klassenname in '$field'.");
                }
            }
        }

        // Dateifelder — Pfadform hier, Containment beim Auflösen (resolveFile)
        foreach (['handler_file', 'gui_file'] as $field) {
            if (!empty($info[$field]) && !self::isSafeRelativePath((string) $info[$field])) {
                throw new RuntimeException("Ungültiger Dateipfad in '$field': '{$info[$field]}'.");
            }
        }

        if (isset($info['require_files'])) {
            if (!is_array($info['require_files'])) {
                throw new RuntimeException("'require_files' muss eine Liste sein.");
            }
            foreach ($info['require_files'] as $rel_path) {
                if (!is_string($rel_path) || !self::isSafeRelativePath($rel_path)) {
                    throw new RuntimeException("Ungültiger Dateipfad in 'require_files'.");
                }
            }
        }

        if (isset($info['tables'])) {
            if (!is_array($info['tables'])) {
                throw new RuntimeException("'tables' muss eine Liste sein.");
            }
            foreach ($info['tables'] as $table) {
                if (!is_string($table) || !preg_match(self::TABLE_REGEX, $table)) {
                    throw new RuntimeException(
                        "Ungültiger Tabellenname in module.json. Erlaubt: a-z, 0-9, _ (3-64 Zeichen)."
                    );
                }
            }
        }

        return $info;
    }

    /**
     * Löst einen Manifest-Dateipfad gegen das Modulverzeichnis auf und stellt
     * sicher, dass die Datei tatsächlich darin liegt (kein Symlink-Ausbruch,
     * kein ../). Gibt den absoluten Pfad zurück oder null.
     */
    public static function resolveFile(string $module_dir, string $rel_path): ?string
    {
        if (!self::isSafeRelativePath($rel_path)) {
            return null;
        }

        $base = realpath($module_dir);
        $real = realpath($module_dir . '/' . $rel_path);

        if ($base === false || $real === false) {
            return null;
        }

        // Containment: die aufgelöste Datei muss echt unterhalb des Modulordners liegen
        if (!str_starts_with($real, rtrim($base, '/') . '/')) {
            return null;
        }

        return is_file($real) ? $real : null;
    }

    /**
     * Erlaubt ausschließlich relative PHP-Pfade ohne Traversal-Bestandteile.
     */
    private static function isSafeRelativePath(string $rel_path): bool
    {
        if ($rel_path === '' || strlen($rel_path) > self::MAX_PATH_LENGTH) {
            return false;
        }

        // Null-Byte, Backslash und absolute Pfade ausschließen
        if (strpos($rel_path, "\0") !== false || strpos($rel_path, '\\') !== false) {
            return false;
        }
        if ($rel_path[0] === '/') {
            return false;
        }

        if (!preg_match('#^[A-Za-z0-9._/-]+$#', $rel_path)) {
            return false;
        }

        foreach (explode('/', $rel_path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return strtolower(substr($rel_path, -4)) === '.php';
    }
}
