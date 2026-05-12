<?php

/**
 * Prüft ob für installierte externe Module neuere Versionen im GitHub-Repository
 * verfügbar sind. Nutzt die GitHub Contents API — kein extra Manifest-File nötig.
 *
 * Namenskonvention im Repo: <ModulName>_v<version>.zip  (z.B. ExamGrouping_v1.0.1.zip)
 *
 * Konfiguration (ilSetting 'extendedtestsettings'):
 *   module_repo_url    → GitHub Repo-URL, z.B. https://github.com/user/Repo
 *   module_repo_branch → Branch-Name,     z.B. ilias9
 */
class ilModuleUpdateChecker
{
    private string $repo_url;
    private string $branch;
    private int    $timeout;

    /** Regex um Version aus ZIP-Dateinamen zu extrahieren: Foo_v1.2.3.zip */
    private const VERSION_REGEX = '/_v(\d+\.\d+(?:\.\d+)?)\.zip$/i';

    public function __construct(string $github_repo_url, string $branch, int $timeout_seconds = 8)
    {
        $this->repo_url = rtrim($github_repo_url, '/');
        $this->branch   = $branch;
        $this->timeout  = $timeout_seconds;
    }

    /**
     * Leitet aus der GitHub-Repo-URL die Contents-API-URL für ein Modul ab.
     * https://github.com/user/Repo  →  https://api.github.com/repos/user/Repo/contents/modules/<id>
     */
    private function apiUrl(string $module_id): string
    {
        // "https://github.com/user/Repo" → ["user", "Repo"]
        $path = parse_url($this->repo_url, PHP_URL_PATH);
        $path = trim($path ?? '', '/');

        return 'https://api.github.com/repos/' . $path
            . '/contents/modules/' . $module_id
            . '?ref=' . urlencode($this->branch);
    }

    /**
     * Prüft alle nicht-builtin Module auf Updates.
     *
     * @param  array[] $modules  Ergebnis von ilModuleRegistry::getAllModuleInfos()
     * @return array[]           Gleiche Liste, externe Module ggf. erweitert um:
     *                           'update_available' => bool
     *                           'latest_version'   => string
     *                           'download_url'     => string  (direkter ZIP-Link)
     *                           'update_error'     => string  (nur bei Fehler)
     */
    public function checkAll(array $modules): array
    {
        $result = [];
        foreach ($modules as $info) {
            if ($info['builtin'] ?? true) {
                $result[] = $info;
                continue;
            }
            $result[] = $this->checkOne($info);
        }
        return $result;
    }

    /**
     * Prüft ein einzelnes Modul: holt Dateiliste per API, sucht neueste ZIP-Version.
     */
    public function checkOne(array $info): array
    {
        $url = $this->apiUrl($info['id']);

        try {
            $raw = $this->fetchUrl($url);

            if ($raw === null) {
                $info['update_error'] = 'Repository nicht erreichbar';
                return $info;
            }

            $files = json_decode($raw, true);

            if (!is_array($files)) {
                $info['update_error'] = 'Ungültige API-Antwort';
                return $info;
            }

            // Alle ZIPs finden und höchste Version ermitteln
            $latest_version = null;
            $download_url   = null;

            foreach ($files as $file) {
                $name = $file['name'] ?? '';
                if (!preg_match(self::VERSION_REGEX, $name, $m)) {
                    continue;
                }
                $v = $m[1];
                if ($latest_version === null || version_compare($v, $latest_version, '>')) {
                    $latest_version = $v;
                    $download_url   = $file['download_url'] ?? null;
                }
            }

            if ($latest_version === null) {
                $info['update_error'] = 'Keine ZIP-Datei im Repository gefunden';
                return $info;
            }

            $installed = $info['version'] ?? '0.0.0';

            $info['latest_version']   = $latest_version;
            $info['update_available'] = version_compare($latest_version, $installed, '>');
            if ($info['update_available'] && $download_url) {
                $info['download_url'] = $download_url;
            }

        } catch (Throwable $e) {
            $info['update_error'] = $e->getMessage();
        }

        return $info;
    }

    /**
     * Lädt den Inhalt einer URL (text/JSON).
     */
    private function fetchUrl(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
                'user_agent'    => 'ILIAS-ExtendedTestSettings-UpdateChecker/1.0',
                'header'        => "Accept: application/vnd.github+json\r\n",
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        // default_socket_timeout steuert den Connect-Timeout; stream-context timeout greift erst beim Lesen
        $prev = ini_set('default_socket_timeout', (string) $this->timeout);
        $content = @file_get_contents($url, false, $context);
        if ($prev !== false) {
            ini_set('default_socket_timeout', $prev);
        }

        return $content !== false ? $content : null;
    }

    /**
     * Lädt eine ZIP-Datei von einer URL in eine temporäre Datei.
     *
     * @throws RuntimeException
     */
    public function downloadZip(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout'       => 60,
                'ignore_errors' => true,
                'user_agent'    => 'ILIAS-ExtendedTestSettings-UpdateChecker/1.0',
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false || strlen($content) < 100) {
            throw new RuntimeException("ZIP konnte nicht heruntergeladen werden: $url");
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ilias_module_update_');
        if (!$tmp || file_put_contents($tmp, $content) === false) {
            throw new RuntimeException('Temporäre Datei konnte nicht geschrieben werden.');
        }

        return $tmp;
    }
}
