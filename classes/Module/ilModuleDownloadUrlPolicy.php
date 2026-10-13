<?php

/** Gemeinsame Download-Allowlist für GUI, Downloader und jeden Redirect. */
class ilModuleDownloadUrlPolicy
{
    public static function isValidRepository(string $repo_url): bool
    {
        if (!preg_match('#\Ahttps://github\.com/([A-Za-z0-9-]+)/([A-Za-z0-9_.-]+)/?\z#', $repo_url, $repo)) {
            return false;
        }
        return !in_array($repo[2], ['.', '..'], true);
    }

    public static function isAllowed(string $url, string $repo_url): bool
    {
        if (!self::isValidRepository($repo_url)) {
            return false;
        }

        // Keine alternativen Parser-Deutungen, Userinfo, Query oder Fragmente.
        if (!preg_match('~\Ahttps://(?:github\.com|raw\.githubusercontent\.com)(?::443)?/[^\s?#]+\z~', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        $path = $parts['path'] ?? '';
        foreach (explode('/', substr($path, 1)) as $segment) {
            // Encodierte Punkte, Trenner, Steuerzeichen und doppelte Encodierung
            // ebenfalls ablehnen, bevor cURL oder GitHub den Pfad normalisiert.
            if (preg_match('/%(?![a-fA-F0-9]{2})/', $segment)) {
                return false;
            }
            $decoded = rawurldecode($segment);
            if ($decoded === '' || $decoded === '.' || $decoded === '..'
                || preg_match('~[\x00-\x20\x7f/\\\\?#%]~', $decoded)) {
                return false;
            }
        }

        $repo_path = rtrim(parse_url($repo_url, PHP_URL_PATH), '/');
        return str_starts_with($path, $repo_path . '/')
            && str_ends_with(strtolower($path), '.zip');
    }

    /** Löst Redirects auf; die aufgelöste URL muss erneut dieselbe Allowlist erfüllen. */
    public static function resolveRedirect(string $current_url, string $location, string $repo_url): ?string
    {
        if (!self::isAllowed($current_url, $repo_url) || $location === '') {
            return null;
        }
        if (str_starts_with($location, '//')) {
            $target = 'https:' . $location;
        } elseif (preg_match('#\A[A-Za-z][A-Za-z0-9+.-]*:#', $location)) {
            $target = $location;
        } else {
            $parts = parse_url($current_url);
            $origin = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $target = $origin . (str_starts_with($location, '/')
                ? $location
                : substr($parts['path'], 0, strrpos($parts['path'], '/') + 1) . $location);
        }

        return self::isAllowed($target, $repo_url) ? $target : null;
    }
}
