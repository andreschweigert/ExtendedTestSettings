<?php

declare(strict_types=1);

/**
 * Erzeugt Modul-Fixtures: Manifeste, Handler-Code, ZIP-Pakete und fertig
 * "installierte" Modulordner.
 *
 * Jede Fixture bekommt eine eigene Handler-Klasse mit laufender Nummer. Das ist
 * wichtig, weil PHP eine einmal geladene Klasse im selben Prozess nicht wieder
 * vergisst — nur mit eindeutigen Namen lässt sich prüfen, ob die Registry den
 * Handler-Code eines inaktiven Moduls tatsächlich nicht lädt (C-01/R3).
 */
class ilEtsModuleFixture
{
    private static int $counter = 0;

    /** Liefert bei jedem Aufruf einen neuen, noch nie geladenen Klassennamen. */
    public static function nextHandlerClass(): string
    {
        self::$counter++;
        return 'ilEtsFixtureHandler' . self::$counter;
    }

    /**
     * Gültiges Manifest. $overrides ersetzt einzelne Felder; null als Wert
     * entfernt ein Feld (für die Pflichtfeld-Tests).
     */
    public static function manifest(array $overrides = []): array
    {
        $class = $overrides['handler_class'] ?? self::nextHandlerClass();

        $manifest = [
            'id'            => 'testmodul',
            'label'         => 'Testmodul',
            'description'   => 'Fixture für die Kern-Testsuite',
            'version'       => '1.0.0',
            'handler_class' => $class,
            'handler_file'  => 'class.' . $class . '.php',
            'tables'        => ['testmodul_settings'],
        ];

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($manifest[$key]);
                continue;
            }
            $manifest[$key] = $value;
        }

        return $manifest;
    }

    /** Handler-Klasse, die ilExamModuleInterface erfüllt. */
    public static function handlerCode(string $class): string
    {
        return <<<PHP
        <?php

        class {$class} implements ilExamModuleInterface
        {
            public function __construct(string \$plugin_path = '')
            {
            }

            public function isEnabled(int \$ref_id): bool
            {
                return true;
            }

            public function injectAssets(ilGlobalTemplateInterface \$tpl, int \$ref_id): void
            {
            }

            public function saveSettings(int \$ref_id, array \$post_data): bool
            {
                return true;
            }

            public function getFormItems(int \$ref_id): array
            {
                return [];
            }
        }
        PHP;
    }

    /**
     * Baut ein Modul-ZIP aus Manifest + Handler. $extra_files ergänzt oder
     * überschreibt einzelne Dateien (z. B. sql/install.php).
     *
     * @param string $prefix Wrapper-Ordner im ZIP (GitHub-Konvention), z. B. 'ExamTest_v1.0.0/'
     */
    public static function zip(string $zip_path, array $manifest, array $extra_files = [], string $prefix = ''): string
    {
        $files = self::files($manifest, $extra_files);
        $zip   = new ilEtsZipBuilder($zip_path);

        foreach ($files as $name => $content) {
            $zip->add($prefix . $name, $content);
        }

        return $zip->close();
    }

    /**
     * Legt ein Modul direkt als Verzeichnis ab (ohne Installer) — für
     * Registry-Tests und für den Nachweis, dass manuell abgelegte Module
     * dieselbe Manifest-Validierung durchlaufen.
     */
    public static function installedModule(string $modules_dir, array $manifest, array $extra_files = []): string
    {
        $id  = (string) ($manifest['id'] ?? 'testmodul');
        $dir = rtrim($modules_dir, '/') . '/' . $id;

        foreach (self::files($manifest, $extra_files) as $name => $content) {
            $target = $dir . '/' . $name;
            $parent = dirname($target);

            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new RuntimeException("Verzeichnis konnte nicht angelegt werden: $parent");
            }
            if (file_put_contents($target, $content) === false) {
                throw new RuntimeException("Datei konnte nicht geschrieben werden: $target");
            }
        }

        return $dir;
    }

    /**
     * Dateisatz eines Moduls: module.json + Handler + Ergänzungen.
     *
     * @return array<string,string>
     */
    private static function files(array $manifest, array $extra_files): array
    {
        $files = ['module.json' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)];

        $handler_file = (string) ($manifest['handler_file'] ?? '');
        if ($handler_file !== '' && !isset($extra_files[$handler_file])) {
            $files[$handler_file] = self::handlerCode((string) ($manifest['handler_class'] ?? 'ilEtsFixtureHandler'));
        }

        return array_merge($files, $extra_files);
    }
}
