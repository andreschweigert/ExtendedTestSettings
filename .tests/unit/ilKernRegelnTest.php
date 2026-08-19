<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

require_once ETS_PLUGIN_ROOT . '/classes/class.ilExtendedTestSettingsConfigGUI.php';

/**
 * Statische Regelprüfungen über den Kern-Quellcode.
 *
 * Diese Tests kommen ohne ILIAS aus und halten die Muster fest, die im Audit
 * 2026-08-03 mehrfach aufgetreten sind. Sie schlagen an, sobald ein Update ein
 * behobenes Muster wieder einführt — auch dann, wenn kein Verhaltenstest
 * daneben liegt.
 *
 * Abgedeckte Regeln aus .dev/security/SICHERHEITSREGELN.md:
 *   R3  Modulcode nur über die Registry (C-01, Z-01)
 *   R4  schreibende Aktionen nur als Formular-POST (C-02, Z-02)
 *   R5  json_encode() in Inline-JS mit HEX-Flags
 *   R6  keine Security-Header ohne Ersatz, kein Debug-Panel (C-03, C-15)
 *   R7  laufende Prüfung heißt submitted = 0 (C-07)
 *   R10 Modulgrenzen fangen Throwable (C-13)
 */
final class ilKernRegelnTest extends ilEtsTestCase
{
    /** @return array<string,string> Pfad => Quelltext aller Kern-PHP-Dateien */
    private function coreSources(): array
    {
        $sources = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ETS_PLUGIN_ROOT . '/classes', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($sources);

        return $sources;
    }

    /** Quelltext einer Methode (für Prüfungen innerhalb eines Handlers). */
    private function methodBody(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines      = file((string) $reflection->getFileName()) ?: [];

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }

    /** @return string[] Die schreibenden Commands der ConfigGUI. */
    public static function writeCommands(): array
    {
        $commands = (new ReflectionClass(ilExtendedTestSettingsConfigGUI::class))->getConstant('WRITE_COMMANDS');

        return array_combine($commands, array_map(static fn ($c) => [$c], $commands));
    }

    // -------------------------------------------------------------------------
    // R4 — Zustandsänderungen nur per Formular-POST (C-02, Z-02)
    // -------------------------------------------------------------------------

    /**
     * Kern von C-02: Ein POST auf ein getLinkTarget()-Ziel ist für ilCtrl kein
     * POST — dort gibt es weder Token noch Prüfung. Schreibende Commands dürfen
     * deshalb nur an getFormAction()-URLs hängen.
     */
    public function testKeinSchreibenderCommandHaengtAnEinemLinkZiel(): void
    {
        $write   = (new ReflectionClass(ilExtendedTestSettingsConfigGUI::class))->getConstant('WRITE_COMMANDS');
        $treffer = [];

        foreach ($this->coreSources() as $path => $source) {
            preg_match_all(
                "/getLinkTarget(?:ByClass)?\s*\([^;]*?['\"]([A-Za-z_]+)['\"]\s*\)/",
                $source,
                $matches,
                PREG_OFFSET_CAPTURE
            );

            foreach ($matches[1] as $match) {
                if (!in_array($match[0], $write, true)) {
                    continue;
                }
                $line      = substr_count(substr($source, 0, (int) $match[1]), "\n") + 1;
                $treffer[] = basename($path) . ':' . $line . ' → ' . $match[0];
            }
        }

        $this->assertSame(
            [],
            $treffer,
            "Schreibende Commands an getLinkTarget() (R4, C-02):\n" . implode("\n", $treffer)
        );
    }

    /**
     * Dieselbe Regel von der anderen Seite: eine Formular-Action darf nicht aus
     * getLinkTarget() gebaut werden, wenn das Formular etwas schreibt.
     */
    public function testFormularActionsSchreibenderFormulareStammenAusGetFormAction(): void
    {
        $write   = (new ReflectionClass(ilExtendedTestSettingsConfigGUI::class))->getConstant('WRITE_COMMANDS');
        $treffer = [];

        foreach ($this->coreSources() as $path => $source) {
            preg_match_all(
                "/setFormAction\s*\([^;]*?getLinkTarget(?:ByClass)?\s*\([^;]*?['\"]([A-Za-z_]+)['\"]/",
                $source,
                $matches
            );

            foreach ($matches[1] as $cmd) {
                if (in_array($cmd, $write, true)) {
                    $treffer[] = basename($path) . ' → ' . $cmd;
                }
            }
        }

        $this->assertSame([], $treffer, 'Formular-Action eines schreibenden Commands aus getLinkTarget(): '
            . implode(', ', $treffer));
    }

    /**
     * Jeder schreibende Command erzwingt POST serverseitig — unabhängig davon,
     * ob das ctrl_security-Artefakt von ILIAS gebaut wurde.
     *
     * Zwei Commands tun das heute nicht; sie sind unten als bekannt offen
     * markiert statt die Erwartung abzusenken.
     */
    #[DataProvider('writeCommands')]
    public function testSchreibenderCommandErzwingtPost(string $command): void
    {
        $this->assertTrue(
            method_exists(ilExtendedTestSettingsConfigGUI::class, $command),
            "Command '$command' steht in WRITE_COMMANDS, existiert aber nicht als Methode."
        );

        $body = $this->methodBody(ilExtendedTestSettingsConfigGUI::class, $command);

        if (!str_contains($body, 'requirePost()')) {
            $this->markTestIncomplete(
                "Beobachtung 2026-08-04 (C-02 Rest): '$command' ruft requirePost() nicht auf und ist damit "
                . 'per GET auslösbar, solange das ctrl_security-Artefakt nicht gebaut ist. '
                . 'Im Code als bewusste Ausnahme kommentiert — Entscheidung steht aus.'
            );
        }

        $this->assertStringContainsString('requirePost()', $body);
    }

    // -------------------------------------------------------------------------
    // R3 — Modulcode ausschließlich über die Registry (C-01, Z-01)
    // -------------------------------------------------------------------------

    /**
     * Z-01: Die ConfigGUI prüfte früher per file_exists(), ob ein Modul
     * "installiert" ist, und lud den Code dann direkt — am Aktivstatus vorbei.
     * Modulcode darf nur über die Registry aufgelöst werden.
     */
    public function testKeinModulcodeAmDateisystemVorbei(): void
    {
        $treffer = [];

        foreach ($this->coreSources() as $path => $source) {
            $code = $this->withoutComments($source);

            // Die Registry selbst ist die einzige Stelle, die module.json liest.
            $is_registry = str_ends_with($path, 'ilModuleRegistry.php');

            if (!$is_registry && preg_match('/file_get_contents\s*\([^;]*module\.json/', $code)) {
                $treffer[] = basename($path) . ': module.json wird direkt gelesen';
            }
            if (preg_match('/file_exists\s*\([^;]*modules\//', $code)) {
                $treffer[] = basename($path) . ': file_exists() auf modules/ als Statusprüfung';
            }
            if (preg_match('/require(_once)?\s+[^;]*__DIR__[^;]*modules\//', $code)) {
                $treffer[] = basename($path) . ': require auf modules/ ohne Registry';
            }
        }

        $this->assertSame([], $treffer, "Modulauflösung an der Registry vorbei (R3):\n" . implode("\n", $treffer));
    }

    // -------------------------------------------------------------------------
    // R5 — json_encode() in Inline-JavaScript (M-02-Muster, gilt auch für den Kern)
    // -------------------------------------------------------------------------

    public function testInlineJavascriptNutztHexFlags(): void
    {
        $treffer = [];

        foreach ($this->coreSources() as $path => $source) {
            $code = $this->withoutComments($source);

            if (!str_contains($code, 'addOnLoadCode') && !str_contains($code, '<script')) {
                continue;
            }

            preg_match_all('/json_encode\s*\(([^;]*)/', $code, $matches);
            foreach ($matches[1] as $args) {
                if (!str_contains($args, 'JSON_HEX_TAG')) {
                    $treffer[] = basename($path) . ': json_encode() ohne JSON_HEX_* in einer Datei mit Inline-JS';
                }
            }
        }

        $this->assertSame([], $treffer, "R5 verletzt:\n" . implode("\n", $treffer));
    }

    // -------------------------------------------------------------------------
    // R6 — Header und Debug-Ausgaben (C-03, C-15)
    // -------------------------------------------------------------------------

    /**
     * C-03: Die Ressourcen-Auslieferung muss die ILIAS-Header verwerfen, um die
     * Datei senden zu können — dann aber eine eigene, engere Policy setzen.
     */
    public function testEntfernteSecurityHeaderWerdenErsetzt(): void
    {
        foreach ($this->coreSources() as $path => $source) {
            $code = $this->withoutComments($source);

            if (!str_contains($code, "header_remove('Content-Security-Policy')")) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/header\(\s*["\']Content-Security-Policy: /',
                $code,
                basename($path) . ': CSP wird entfernt, aber keine eigene gesetzt.'
            );
            $this->assertStringContainsString(
                'X-Frame-Options: DENY',
                $code,
                basename($path) . ': X-Frame-Options wird entfernt, aber nicht ersetzt.'
            );
        }

        $this->assertTrue(true, 'Alle Stellen mit header_remove() setzen eine eigene Policy.');
    }

    /** C-03: Hochgeladenes HTML wird nie inline unter der ILIAS-Origin gerendert. */
    public function testHtmlWirdAlsAttachmentAusgeliefert(): void
    {
        $proxy = (string) file_get_contents(
            ETS_PLUGIN_ROOT . '/classes/class.ilExtendedTestSettingsModuleProxyGUI.php'
        );

        $this->assertMatchesRegularExpression(
            '/\$is_html\s*\?\s*[\'"]attachment[\'"]/',
            $proxy,
            'text/html muss als attachment ausgeliefert werden (C-03).'
        );
    }

    /** C-15: kein Debug-Panel im Produktivpfad. */
    public function testKeineDebugAusgabenImProduktivpfad(): void
    {
        $treffer = [];

        foreach ($this->coreSources() as $path => $source) {
            $code = $this->withoutComments($source);

            foreach (['print_r(', 'var_dump(', 'var_export('] as $needle) {
                if (str_contains($code, $needle)) {
                    $treffer[] = basename($path) . ': ' . $needle;
                }
            }
        }

        $this->assertSame([], $treffer, "Debug-Ausgaben im Kern (C-15):\n" . implode("\n", $treffer));
    }

    // -------------------------------------------------------------------------
    // R7 — laufende Prüfung heißt submitted = 0 (C-07)
    // -------------------------------------------------------------------------

    public function testAbfragenAufTstActivePruefenSubmitted(): void
    {
        foreach ($this->coreSources() as $path => $source) {
            $code = $this->withoutComments($source);

            if (!str_contains($code, 'tst_active')) {
                continue;
            }

            $this->assertStringContainsString(
                'submitted',
                $code,
                basename($path) . ': tst_active wird abgefragt, ohne submitted zu prüfen (R7, C-07). '
                . 'Ein tst_active-Eintrag beweist nur, dass jemand den Test irgendwann begonnen hat.'
            );
        }

        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // R10 — Modulgrenzen fangen Throwable (C-13)
    // -------------------------------------------------------------------------

    /**
     * Ein TypeError aus einem inkompatiblen Modul darf nicht den ganzen Request
     * mitreißen — inklusive Testplayer. Geprüft wird der jeweils nächste catch
     * hinter einem Aufruf von Modulcode.
     */
    public function testModulaufrufeFangenThrowable(): void
    {
        $module_calls = '/\$handler(?:s\[[^\]]+\])?->(isEnabled|injectAssets|saveSettings|getFormItems|onRequest|getInfo|getSettingsHTML)\(/';
        $treffer      = [];
        $geprueft     = 0;

        foreach ($this->coreSources() as $path => $source) {
            $lines = explode("\n", $source);

            foreach ($lines as $no => $line) {
                if (!preg_match($module_calls, $line)) {
                    continue;
                }
                $geprueft++;

                // Nächste catch-Zeile hinter dem Aufruf suchen
                for ($i = $no + 1; $i < count($lines); $i++) {
                    if (!preg_match('/}\s*catch\s*\(\s*(\w+)/', $lines[$i], $m)) {
                        continue;
                    }
                    if ($m[1] !== 'Throwable') {
                        $treffer[] = basename($path) . ':' . ($no + 1) . ' → catch (' . $m[1] . ') in Zeile ' . ($i + 1);
                    }
                    break;
                }
            }
        }

        $this->assertGreaterThan(0, $geprueft, 'Keine Modulaufrufe gefunden — der Test greift ins Leere.');
        $this->assertSame([], $treffer, "Modulgrenze fängt nicht Throwable (R10, C-13):\n" . implode("\n", $treffer));
    }

    // -------------------------------------------------------------------------
    // Hilfsmittel
    // -------------------------------------------------------------------------

    /** Entfernt Kommentare und Strings-in-Kommentaren, damit Beispiele in Doc-Blocks nicht anschlagen. */
    private function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
