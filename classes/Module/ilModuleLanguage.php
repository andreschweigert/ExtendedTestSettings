<?php

/**
 * Leichter Übersetzer für ExtendedTestSettings-Module (i18n-Variante B).
 *
 * ILIAS liest Sprachdateien nur aus dem lang/-Verzeichnis registrierter
 * Plugins ein. Die Module werden hier aber als eigenständige ZIP-Pakete
 * geladen und sind ILIAS nicht als Plugin bekannt. Damit jedes Modul seine
 * Texte selbst mitbringen kann, lädt diese Klasse die modul-eigene
 * Sprachdatei und löst Schlüssel auf.
 *
 * Erwartete Dateien im Modul:
 *   <modul>/lang/ilias_de.lang
 *   <modul>/lang/ilias_en.lang
 *
 * Format (wie bei ILIAS-Plugin-Sprachdateien):
 *   <!-- language file start -->
 *   key#:#Text
 *
 * Fallback-Kette: aktuelle ILIAS-Sprache → Englisch → übergebener Default → Schlüssel.
 */
class ilModuleLanguage
{
    private const FALLBACK_LANG = 'en';

    private string $lang_dir;
    private string $lang_key;

    /** @var array<string,string> */
    private array $map = [];
    private bool $loaded = false;

    /**
     * @param string      $module_dir Verzeichnis des Moduls (enthält lang/), i. d. R. __DIR__ im Handler.
     * @param string|null $lang_key   Sprachkürzel; null = aktuelle ILIAS-Sprache.
     */
    public function __construct(string $module_dir, ?string $lang_key = null)
    {
        $this->lang_dir = rtrim($module_dir, '/') . '/lang';

        if ($lang_key === null) {
            global $DIC;
            $lang_key = (isset($DIC) && $DIC->offsetExists('lng'))
                ? $DIC->language()->getLangKey()
                : self::FALLBACK_LANG;
        }
        $this->lang_key = $lang_key;
    }

    /**
     * Löst einen Sprachschlüssel auf.
     *
     * @param string      $key     Schlüssel ohne Prefix (z. B. "enable").
     * @param string|null $default Rückgabe, wenn der Schlüssel in keiner Datei steht.
     */
    public function txt(string $key, ?string $default = null): string
    {
        $this->load();
        return $this->map[$key] ?? $default ?? $key;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        // Erst Englisch als Fallback laden, dann ggf. die aktuelle Sprache drüberlegen.
        $this->readFile($this->lang_dir . '/ilias_' . self::FALLBACK_LANG . '.lang');
        if ($this->lang_key !== self::FALLBACK_LANG) {
            $this->readFile($this->lang_dir . '/ilias_' . $this->lang_key . '.lang');
        }
    }

    private function readFile(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            // Kopfzeile (<!-- ... -->) und Zeilen ohne Trennzeichen überspringen.
            if (!str_contains($line, '#:#') || str_starts_with(ltrim($line), '<!--')) {
                continue;
            }
            [$key, $value] = explode('#:#', $line, 2);
            $this->map[trim($key)] = $value;
        }
    }
}
