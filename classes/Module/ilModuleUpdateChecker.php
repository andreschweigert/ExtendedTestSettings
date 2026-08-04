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
    private string $proxy;

    /** Regex um Version aus ZIP-Dateinamen zu extrahieren: Foo_v1.2.3.zip */
    private const VERSION_REGEX = '/_v(\d+\.\d+(?:\.\d+)?)\.zip$/i';

    /** Harte Obergrenze für Downloads — deckungsgleich mit dem Upload-Limit des Installers. */
    private const MAX_DOWNLOAD_BYTES = 20 * 1024 * 1024;

    public function __construct(string $github_repo_url, string $branch, int $timeout_seconds = 8, string $proxy = '')
    {
        $this->repo_url = rtrim($github_repo_url, '/');
        $this->branch   = $branch;
        $this->timeout  = $timeout_seconds;
        $this->proxy    = $proxy;
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
     * Nutzt cURL für zuverlässiges HTTPS-über-Proxy-Tunneling (CONNECT).
     */
    private function fetchUrl(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            return $this->fetchUrlFallback($url);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_USERAGENT      => 'ILIAS-ExtendedTestSettings-UpdateChecker/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);

        if ($this->proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        }

        $content  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($content === false || $http_code >= 400) {
            return null;
        }

        return (string) $content;
    }

    /**
     * Fallback ohne cURL (kein Proxy-Support für HTTPS).
     */
    private function fetchUrlFallback(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
                'user_agent'    => 'ILIAS-ExtendedTestSettings-UpdateChecker/1.0',
                'header'        => "Accept: application/vnd.github+json\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $prev    = ini_set('default_socket_timeout', (string) $this->timeout);
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
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL ist nicht verfügbar.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ilias_module_update_');
        if ($tmp === false) {
            throw new RuntimeException('Temporäre Datei konnte nicht angelegt werden.');
        }

        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            @unlink($tmp);
            throw new RuntimeException('Temporäre Datei konnte nicht geöffnet werden.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            // Direkt in die Datei streamen statt die gesamte Antwort im RAM zu
            // puffern; zusätzlich hart bei MAX_DOWNLOAD_BYTES abbrechen (Regel R11).
            CURLOPT_FILE           => $fh,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT      => 'ILIAS-ExtendedTestSettings-UpdateChecker/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_MAXFILESIZE    => self::MAX_DOWNLOAD_BYTES,
            CURLOPT_NOPROGRESS     => false,
            // Greift auch, wenn der Server keine Content-Length schickt —
            // CURLOPT_MAXFILESIZE allein prüft nur den angekündigten Wert.
            CURLOPT_PROGRESSFUNCTION => static function ($res, $download_size, $downloaded) {
                return ($downloaded > self::MAX_DOWNLOAD_BYTES) ? 1 : 0;
            },
        ]);

        if ($this->proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        }

        $ok        = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        $curl_no   = curl_errno($ch);
        curl_close($ch);
        fclose($fh);

        if ($curl_no === CURLE_ABORTED_BY_CALLBACK || $curl_no === CURLE_FILESIZE_EXCEEDED) {
            @unlink($tmp);
            throw new RuntimeException(sprintf(
                'Download abgebrochen: Datei überschreitet %d MB.',
                (int) (self::MAX_DOWNLOAD_BYTES / 1048576)
            ));
        }

        if ($ok === false || $http_code >= 400) {
            @unlink($tmp);
            throw new RuntimeException("ZIP konnte nicht heruntergeladen werden: $url (HTTP $http_code, $curl_err)");
        }

        if (filesize($tmp) < 100) {
            @unlink($tmp);
            throw new RuntimeException("ZIP konnte nicht heruntergeladen werden: $url");
        }

        return $tmp;
    }
}
