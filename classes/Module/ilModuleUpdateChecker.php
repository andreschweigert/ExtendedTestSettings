<?php

/**
 * Prüft ob für installierte externe Module neuere Versionen im konfigurierten
 * Modul-Repository verfügbar sind.
 *
 * Das Repository stellt pro Modul eine module.json bereit:
 *   <repo_base_url>/modules/<id>/module.json
 *
 * Diese enthält mindestens:
 *   { "id": "...", "version": "1.0.2", "download": "ModulName_v1.0.2.zip" }
 *
 * Der Download-Link ergibt sich dann aus:
 *   <repo_base_url>/modules/<id>/<download>
 */
class ilModuleUpdateChecker
{
    private string $repo_base_url;
    private int    $timeout;

    public function __construct(string $repo_base_url, int $timeout_seconds = 5)
    {
        $this->repo_base_url = rtrim($repo_base_url, '/');
        $this->timeout       = $timeout_seconds;
    }

    /**
     * Prüft für eine Liste von Modul-Infos ob Updates verfügbar sind.
     *
     * @param  array[] $modules  Ergebnis von ilModuleRegistry::getAllModuleInfos()
     * @return array[]           Gleiche Liste, jedes Element ggf. erweitert um:
     *                           'update_available' => bool
     *                           'latest_version'   => string
     *                           'download_url'     => string
     *                           'update_error'     => string (nur bei Fehler)
     */
    public function checkAll(array $modules): array
    {
        $result = [];

        foreach ($modules as $info) {
            // Nur externe (installierte) Module prüfen — built-ins werden zentral gepflegt
            if ($info['builtin'] ?? true) {
                $result[] = $info;
                continue;
            }

            $result[] = $this->checkOne($info);
        }

        return $result;
    }

    /**
     * Prüft ein einzelnes Modul auf Updates.
     */
    public function checkOne(array $info): array
    {
        $id  = $info['id'];
        $url = $this->repo_base_url . '/modules/' . $id . '/module.json';

        try {
            $raw = $this->fetchUrl($url);

            if ($raw === null) {
                $info['update_error'] = 'Nicht erreichbar';
                return $info;
            }

            $remote = json_decode($raw, true);

            if (!is_array($remote) || empty($remote['version'])) {
                $info['update_error'] = 'Ungültige module.json im Repository';
                return $info;
            }

            $installed = $info['version'] ?? '0.0.0';
            $latest    = $remote['version'];

            $info['latest_version']   = $latest;
            $info['update_available'] = version_compare($latest, $installed, '>');

            if ($info['update_available'] && !empty($remote['download'])) {
                $info['download_url'] = $this->repo_base_url
                    . '/modules/' . $id . '/' . $remote['download'];
            }

        } catch (Throwable $e) {
            $info['update_error'] = $e->getMessage();
        }

        return $info;
    }

    /**
     * Lädt den Inhalt einer URL herunter und gibt ihn zurück, oder null bei Fehler.
     */
    private function fetchUrl(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout'        => $this->timeout,
                'ignore_errors'  => true,
                'user_agent'     => 'ILIAS-ExtendedTestSettings-UpdateChecker/1.0',
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);

        return $content !== false ? $content : null;
    }

    /**
     * Lädt eine ZIP-Datei von einer URL herunter und speichert sie in eine temporäre Datei.
     * Gibt den Pfad zur tmp-Datei zurück oder wirft eine Exception.
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
