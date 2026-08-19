<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

require_once ETS_PLUGIN_ROOT . '/classes/class.ilExtendedTestSettingsPlugin.php';
require_once ETS_PLUGIN_ROOT . '/classes/class.ilExtendedTestSettingsConfigGUI.php';

/**
 * Funktionale Integrität des Kerns — die Prüfungen, die anschlagen sollen, wenn
 * ein Update etwas kaputt gemacht hat, ohne dass es sofort auffällt:
 *
 *  - eine Datei ist syntaktisch kaputt (oder nutzt Syntax, die PHP 8.2 nicht kennt)
 *  - eine Klasse wurde umbenannt, die Datei aber nicht (ILIAS findet sie dann nicht)
 *  - ein Command steht in der Command-Liste, die Methode fehlt
 *  - die Version steht nur an einer von mehreren Stellen (R15)
 *  - eine Sprachvariable existiert nur auf Deutsch — im englischen UI erscheint
 *    dann der rohe Schlüssel
 *  - das Modul-Interface hat sich geändert, ohne dass die 18 Module davon wissen
 */
final class ilPluginIntegritaetTest extends ilEtsTestCase
{
    // -------------------------------------------------------------------------
    // Syntax
    // -------------------------------------------------------------------------

    /** @return array<string,array{string}> Alle ausgelieferten PHP-Dateien des Kerns. */
    public static function kernDateien(): array
    {
        $files = [ETS_PLUGIN_ROOT . '/plugin.php'];

        foreach (['/classes', '/sql'] as $dir) {
            if (!is_dir(ETS_PLUGIN_ROOT . $dir)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(ETS_PLUGIN_ROOT . $dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        $data = [];
        foreach ($files as $file) {
            $data[str_replace(ETS_PLUGIN_ROOT . '/', '', $file)] = [$file];
        }

        return $data;
    }

    /**
     * Syntaxprüfung mit demselben Binary, unter dem die Tests laufen. Fängt
     * unter anderem Sprachkonstrukte, die ILIAS 9 (PHP < 8.3) nicht mehr kennt.
     */
    #[DataProvider('kernDateien')]
    public function testDateiIstSyntaktischGueltig(string $file): void
    {
        $output = [];
        $code   = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
    }

    /**
     * ILIAS lädt Kernklassen über die Composer-Classmap; ein Klassenname, der
     * nicht zum Dateinamen passt, wird nach dem nächsten dump-autoload nicht
     * mehr gefunden.
     */
    public function testKlassennamenPassenZuDenDateinamen(): void
    {
        $abweichungen = [];

        foreach (self::kernDateien() as $relative => [$file]) {
            $name = basename($file);

            if (!str_starts_with($name, 'class.il') && !str_starts_with($name, 'il')) {
                continue;
            }

            $expected = str_starts_with($name, 'class.')
                ? substr($name, strlen('class.'), -strlen('.php'))
                : substr($name, 0, -strlen('.php'));

            $source = (string) file_get_contents($file);

            if (!preg_match('/^(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+(\w+)/m', $source, $m)) {
                $abweichungen[] = "$relative: keine Klassendeklaration gefunden";
                continue;
            }

            if ($m[1] !== $expected) {
                $abweichungen[] = "$relative: deklariert '{$m[1]}', erwartet '$expected'";
            }
        }

        $this->assertSame([], $abweichungen, implode("\n", $abweichungen));
    }

    // -------------------------------------------------------------------------
    // Versionen (R15)
    // -------------------------------------------------------------------------

    /** Die Variablen aus plugin.php. */
    private function pluginManifest(): array
    {
        return (static function (string $file): array {
            include $file;
            return get_defined_vars();
        })(ETS_PLUGIN_ROOT . '/plugin.php');
    }

    public function testPluginManifestIstVollstaendig(): void
    {
        $manifest = $this->pluginManifest();

        foreach (['plugin_name', 'id', 'version', 'ilias_min_version', 'ilias_max_version'] as $key) {
            $this->assertArrayHasKey($key, $manifest, "plugin.php definiert \$$key nicht.");
            $this->assertNotSame('', (string) $manifest[$key]);
        }

        $this->assertSame(
            ilExtendedTestSettingsPlugin::PLUGIN_ID,
            $manifest['id'],
            'plugin.php und PLUGIN_ID müssen dieselbe ID nennen — sonst findet getPlugin() das Plugin nicht.'
        );
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $manifest['version']);
    }

    /**
     * R15: plugin.php ist die einzige Versionsquelle, README und Architektur-Doku
     * werden bei jedem Release mitgezogen.
     */
    public function testVersionStehtUeberallGleich(): void
    {
        $version = (string) $this->pluginManifest()['version'];

        $readme = (string) file_get_contents(ETS_PLUGIN_ROOT . '/README.md');
        $this->assertStringContainsString(
            "**Version:** $version",
            $readme,
            "README.md nennt nicht die Version aus plugin.php ($version) — Regel R15."
        );
        $this->assertStringContainsString(
            "badge/version-$version-",
            $readme,
            "Das Versions-Badge in README.md steht nicht auf $version — Regel R15."
        );

        // .dev/ ist gitignored und in einem frischen Clone nicht vorhanden.
        $architektur = ETS_PLUGIN_ROOT . '/.dev/Architektur.md';
        if (is_file($architektur)) {
            $this->assertStringContainsString(
                "**Plugin-Version:** $version",
                (string) file_get_contents($architektur),
                ".dev/Architektur.md nennt eine andere Version als plugin.php ($version) — Regel R15."
            );
        }
    }

    // -------------------------------------------------------------------------
    // Command-Liste (Funktionsbruch bei Umbenennungen)
    // -------------------------------------------------------------------------

    public function testAlleSchreibendenCommandsExistierenAlsMethode(): void
    {
        $fehlend = [];

        foreach ((new ReflectionClass(ilExtendedTestSettingsConfigGUI::class))->getConstant('WRITE_COMMANDS') as $cmd) {
            if (!method_exists(ilExtendedTestSettingsConfigGUI::class, $cmd)) {
                $fehlend[] = $cmd;
            }
        }

        $this->assertSame([], $fehlend, 'In WRITE_COMMANDS gelistet, aber nicht implementiert: '
            . implode(', ', $fehlend));
    }

    public function testSchreibendeCommandsWerdenAlsUnsichereGetCommandsGemeldet(): void
    {
        $reflection = new ReflectionClass(ilExtendedTestSettingsConfigGUI::class);
        $gui        = $reflection->newInstanceWithoutConstructor();

        $this->assertSame(
            $reflection->getConstant('WRITE_COMMANDS'),
            $gui->getUnsafeGetCommands(),
            'ilCtrl muss jeden schreibenden Command als GET-unsicher gemeldet bekommen.'
        );
        $this->assertSame([], $gui->getSafePostCommands(), 'Kein POST-Command darf ohne Token durchgehen.');
    }

    public function testPluginNenntKonfigurationsUndUiHookKlasse(): void
    {
        $plugin = (new ReflectionClass(ilExtendedTestSettingsPlugin::class))->newInstanceWithoutConstructor();

        foreach ([$plugin->getConfigurationClass(), $plugin->getUIHookClass()] as $class) {
            $this->assertFileExists(
                ETS_PLUGIN_ROOT . '/classes/class.' . $class . '.php',
                "Die von der Plugin-Klasse genannte Klasse '$class' hat keine Datei."
            );
        }

        $this->assertTrue($plugin->hasConfiguration());
        $this->assertSame('ExtendedTestSettings', $plugin->getPluginName());
    }

    // -------------------------------------------------------------------------
    // Modul-Vertrag
    // -------------------------------------------------------------------------

    /**
     * Ändert sich dieses Interface, funktioniert kein einziges der 18 Module
     * mehr. Der Test hält den Vertrag fest, damit eine solche Änderung nicht
     * unbemerkt durchgeht.
     */
    public function testModulInterfaceIstUnveraendert(): void
    {
        $interface = new ReflectionClass(ilExamModuleInterface::class);
        $methoden  = [];

        foreach ($interface->getMethods() as $method) {
            $parameter = [];
            foreach ($method->getParameters() as $p) {
                $parameter[] = ((string) $p->getType()) . ' $' . $p->getName();
            }
            $methoden[$method->getName()] = implode(', ', $parameter) . ' : ' . (string) $method->getReturnType();
        }

        ksort($methoden);

        $this->assertSame([
            'getFormItems' => 'int $ref_id : array',
            'injectAssets' => 'ilGlobalTemplateInterface $tpl, int $ref_id : void',
            'isEnabled'    => 'int $ref_id : bool',
            'saveSettings' => 'int $ref_id, array $post_data : bool',
        ], $methoden, 'Der Modul-Vertrag hat sich geändert — alle 18 Module sind betroffen.');
    }

    // -------------------------------------------------------------------------
    // Sprachdateien
    // -------------------------------------------------------------------------

    /** @return array<string,string> Schlüssel => Text */
    private function langKeys(string $file): array
    {
        $keys = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (!str_contains($line, '#:#') || str_starts_with(ltrim($line), '<!--')) {
                continue;
            }
            [$key, $value] = explode('#:#', $line, 2);
            $keys[trim($key)] = $value;
        }

        return $keys;
    }

    public function testSprachdateienHabenDenselbenSchluesselsatz(): void
    {
        $de = ETS_PLUGIN_ROOT . '/lang/ilias_de.lang';
        $en = ETS_PLUGIN_ROOT . '/lang/ilias_en.lang';

        $this->assertFileExists($de);
        $this->assertFileExists($en);

        $de_keys = array_keys($this->langKeys($de));
        $en_keys = array_keys($this->langKeys($en));

        $this->assertNotEmpty($de_keys);

        $nur_de = array_diff($de_keys, $en_keys);
        $nur_en = array_diff($en_keys, $de_keys);

        $this->assertSame([], array_values($nur_de), 'Nur in ilias_de.lang: ' . implode(', ', $nur_de));
        $this->assertSame([], array_values($nur_en), 'Nur in ilias_en.lang: ' . implode(', ', $nur_en));
    }

    public function testKeineLeerenSprachtexte(): void
    {
        $leer = [];

        foreach (['de', 'en'] as $lang) {
            foreach ($this->langKeys(ETS_PLUGIN_ROOT . "/lang/ilias_$lang.lang") as $key => $value) {
                if (trim($value) === '') {
                    $leer[] = "$lang: $key";
                }
            }
        }

        $this->assertSame([], $leer, 'Leere Sprachtexte: ' . implode(', ', $leer));
    }

    public function testKeineDoppeltenSprachschluessel(): void
    {
        foreach (['de', 'en'] as $lang) {
            $file  = ETS_PLUGIN_ROOT . "/lang/ilias_$lang.lang";
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

            $seen   = [];
            $doppelt = [];

            foreach ($lines as $line) {
                if (!str_contains($line, '#:#') || str_starts_with(ltrim($line), '<!--')) {
                    continue;
                }
                $key = trim(explode('#:#', $line, 2)[0]);
                if (isset($seen[$key])) {
                    $doppelt[] = $key;
                }
                $seen[$key] = true;
            }

            $this->assertSame([], $doppelt, "Doppelte Schlüssel in ilias_$lang.lang: " . implode(', ', $doppelt));
        }
    }
}
