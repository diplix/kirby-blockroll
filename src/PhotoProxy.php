<?php

namespace Blockroll;

use Kirby\Cms\App;
use Kirby\Http\Response;
use Throwable;

/**
 * Local avatar/image proxy: fetch once, resize, cache under site/cache, serve same-origin.
 * Pattern adapted from the site's linkembed image route (no external CDN).
 */
class PhotoProxy
{
    private const EXT_MAP = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'image/avif' => 'avif',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    public static function enabled(): bool
    {
        return (bool) (App::instance()->option('diplix.blockroll.proxyPhotos') ?? false);
    }

    /** Target edge length in px (default 96 ≈ 2× retina for ~48px CSS avatars). */
    public static function size(): int
    {
        $size = (int) (App::instance()->option('diplix.blockroll.proxySize') ?? 96);
        return max(16, min(512, $size));
    }

    public static function cacheRoot(): string
    {
        $custom = App::instance()->option('diplix.blockroll.proxyCache');
        if (is_string($custom) && trim($custom) !== '') {
            return rtrim(trim($custom), '/');
        }

        return rtrim(App::instance()->root('cache'), '/') . '/blockroll-photos';
    }

    /** Max age of on-disk avatars before re-fetch (default 4 weeks). `0` = never re-fetch. */
    public static function cacheTtl(): int
    {
        $ttl = App::instance()->option('diplix.blockroll.proxyCacheTtl');
        if ($ttl === null || $ttl === '') {
            return 60 * 60 * 24 * 28; // 4 weeks
        }

        $ttl = (int) $ttl;
        if ($ttl <= 0) {
            return 0; // keep forever once fetched
        }

        return max(3600, $ttl);
    }

    /**
     * Browser Cache-Control max-age. Permanent disk cache → 1 year HTTP cache.
     */
    public static function httpMaxAge(): int
    {
        $ttl = self::cacheTtl();
        return $ttl > 0 ? $ttl : 31536000;
    }

    /**
     * Same-origin proxy URL for a remote image, or the original when proxy is off.
     * Lightweight URL checks only (no DNS) so page render stays cheap.
     */
    public static function url(string $remoteUrl): string
    {
        $remoteUrl = trim($remoteUrl);
        if ($remoteUrl === '' || !self::enabled() || !self::isAllowedRemoteUrl($remoteUrl, false)) {
            return $remoteUrl;
        }

        if (self::isAlreadyProxied($remoteUrl)) {
            return $remoteUrl;
        }

        $base = url('blockroll/image');
        return $base . (str_contains($base, '?') ? '&' : '?') . 'url=' . rawurlencode($remoteUrl);
    }

    /**
     * Handle GET /blockroll/image?url=…
     * Serves local disk cache; re-fetches at most every proxyCacheTtl (default 4 weeks; 0 = never).
     * The ?refresh=1 query is ignored for anonymous traffic (local files persist).
     */
    public static function respond(?string $url, bool $refresh = false): Response
    {
        $url = trim((string) $url);
        // Full SSRF checks (incl. DNS → public IP) before any network I/O.
        if ($url === '' || !self::isAllowedRemoteUrl($url, true)) {
            return new Response('Bad Request', 'text/plain', 400);
        }

        // Never force-refresh via public query string — avatars live on disk.
        unset($refresh);

        $hash = md5($url);
        $size = self::size();
        $ttl = self::cacheTtl();
        $httpMaxAge = self::httpMaxAge();
        $dir = self::cacheRoot();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // ttl 0 = never expire on disk (pass null to skip age check)
        $cached = self::findCached($dir, $hash, $size, $ttl > 0 ? $ttl : null);
        if ($cached !== null) {
            return self::fileResponse($cached, $httpMaxAge);
        }

        $fetched = self::fetch($url);
        if ($fetched === null) {
            // Prefer serving a stale local file over redirecting to remote
            $stale = self::findCached($dir, $hash, $size, null);
            if ($stale !== null) {
                return self::fileResponse($stale, $httpMaxAge);
            }

            // Only bounce to the original when it still looks publicly safe (no DNS).
            if (self::isAllowedRemoteUrl($url, false)) {
                return new Response('', null, 302, ['Location' => $url]);
            }

            return new Response('Bad Gateway', 'text/plain', 502);
        }

        $processed = self::resize($fetched['data'], $fetched['type'], $size);
        if ($processed === null) {
            $ext = self::EXT_MAP[strtolower($fetched['type'])] ?? 'jpg';
            $path = $dir . '/' . $size . '_' . $hash . '.' . $ext;
            @file_put_contents($path, $fetched['data']);

            return new Response($fetched['data'], $fetched['type'], 200, [
                'Cache-Control' => 'public, max-age=' . $httpMaxAge,
            ]);
        }

        $path = $dir . '/' . $size . '_' . $hash . '.' . $processed['ext'];
        @file_put_contents($path, $processed['data']);

        return new Response($processed['data'], $processed['type'], 200, [
            'Cache-Control' => 'public, max-age=' . $httpMaxAge,
        ]);
    }

    /**
     * Allow only http(s) URLs to public hosts.
     *
     * @param bool $resolve When true, DNS-resolve the host and require every A/AAAA
     *                      record to be a public unicast address (SSRF guard).
     */
    public static function isAllowedRemoteUrl(string $url, bool $resolve = true): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        // userinfo@host can confuse parsers / hide the real target
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return false;
        }

        $hostCheck = self::normalizeHost($host);
        if ($hostCheck === '' || self::isBlockedHostname($hostCheck)) {
            return false;
        }

        // Literal IP in the URL
        if (filter_var($hostCheck, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($hostCheck);
        }

        // Numeric / hex hosts some clients treat as IPv4 (e.g. 2130706433, 0x7f000001)
        if (self::looksLikeEncodedIpv4($hostCheck)) {
            return false;
        }

        if (!$resolve) {
            return true;
        }

        $ips = self::resolveHostIps($hostCheck);
        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private static function normalizeHost(string $host): string
    {
        $host = trim($host);
        // IPv6 in URLs: [::1]
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return strtolower($host);
    }

    private static function isBlockedHostname(string $host): bool
    {
        if ($host === 'localhost' || $host === '0' || $host === '0.0.0.0') {
            return true;
        }

        foreach (['.localhost', '.local', '.internal', '.intranet', '.lan', '.home', '.corp'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        // Common cloud metadata hostnames
        return in_array($host, [
            'metadata.google.internal',
            'metadata.goog',
            'kubernetes.default',
            'kubernetes.default.svc',
        ], true);
    }

    private static function looksLikeEncodedIpv4(string $host): bool
    {
        if (ctype_digit($host)) {
            return true;
        }

        // 0x7f000001, 0177.0.0.1, 127.1, …
        if (preg_match('/^0x[0-9a-f]+$/i', $host) === 1) {
            return true;
        }

        if (preg_match('/^0[0-7]+(\.[0-7]+)*$/', $host) === 1) {
            return true;
        }

        // Dotted forms with fewer than 4 parts that inet may expand (127.1 → 127.0.0.1)
        if (preg_match('/^\d+(\.\d+){0,2}$/', $host) === 1) {
            return true;
        }

        return false;
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $ip = strtolower($ip);

        // Belt-and-braces beyond the filter flags
        if (str_starts_with($ip, 'fe80:') || str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd')) {
            return false;
        }

        if ($ip === '::' || $ip === '::1' || $ip === '0.0.0.0') {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function resolveHostIps(string $host): array
    {
        $ips = [];

        if (function_exists('dns_get_record')) {
            try {
                foreach (['A', 'AAAA'] as $type) {
                    $records = @dns_get_record($host, $type === 'A' ? DNS_A : DNS_AAAA);
                    if (!is_array($records)) {
                        continue;
                    }
                    foreach ($records as $record) {
                        $ip = $type === 'A'
                            ? (string) ($record['ip'] ?? '')
                            : (string) ($record['ipv6'] ?? '');
                        if ($ip !== '') {
                            $ips[] = $ip;
                        }
                    }
                }
            } catch (Throwable) {
                // fall through to gethostbynamel
            }
        }

        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                foreach ($v4 as $ip) {
                    if (is_string($ip) && $ip !== '') {
                        $ips[] = $ip;
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private static function isAlreadyProxied(string $url): bool
    {
        return str_contains($url, '/blockroll/image')
            || str_contains($url, 'linkembed/image');
    }

    private static function findCached(string $dir, string $hash, int $size, ?int $maxAge = null): ?string
    {
        $matches = glob($dir . '/' . $size . '_' . $hash . '.*') ?: [];
        foreach ($matches as $path) {
            if (!is_file($path)) {
                continue;
            }
            if ($maxAge !== null) {
                $mtime = filemtime($path);
                if ($mtime === false || $mtime < time() - $maxAge) {
                    continue; // expired — caller may re-fetch
                }
            }
            return $path;
        }
        return null;
    }

    private static function clearCached(string $dir, string $hash, int $size): void
    {
        foreach (glob($dir . '/' . $size . '_' . $hash . '.*') ?: [] as $path) {
            @unlink($path);
        }
        foreach (glob($dir . '/' . $hash . '.*') ?: [] as $path) {
            @unlink($path);
        }
    }

    /**
     * Fetch with SSRF guards: re-validate every hop, no blind redirect follow,
     * DNS pinned via CURLOPT_RESOLVE so a later lookup cannot rebind to a private IP.
     *
     * @return array{data: string, type: string}|null
     */
    private static function fetch(string $url): ?array
    {
        $timeout = (int) (App::instance()->option('diplix.blockroll.proxyTimeout') ?? 10);
        $maxBytes = (int) (App::instance()->option('diplix.blockroll.proxyMaxBytes') ?? 512000);
        $maxRedirects = 3;
        $current = $url;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            if (!self::isAllowedRemoteUrl($current, true)) {
                return null;
            }

            $result = self::curlOnce($current, $timeout, $maxBytes);
            if ($result === null) {
                return null;
            }

            $code = $result['code'];
            if ($code >= 300 && $code < 400) {
                $location = trim($result['location'] ?? '');
                if ($location === '') {
                    return null;
                }
                $next = self::absoluteUrl($location, $current);
                if ($next === null || $next === $current) {
                    return null;
                }
                $current = $next;
                continue;
            }

            if ($code < 200 || $code >= 300) {
                return null;
            }

            $data = $result['body'];
            if ($data === '' || strlen($data) > $maxBytes) {
                return null;
            }

            $type = $result['type'];
            if ($type === '' || stripos($type, 'image/') !== 0) {
                $type = self::detectMime($data) ?? '';
            }

            if ($type === '' || stripos($type, 'image/') !== 0) {
                return null;
            }

            return ['data' => $data, 'type' => strtolower($type)];
        }

        return null;
    }

    /**
     * @return array{code: int, body: string, type: string, location: string}|null
     */
    private static function curlOnce(string $url, int $timeout, int $maxBytes): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $host = (string) $parts['host'];
        $hostCheck = self::normalizeHost($host);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $ips = filter_var($hostCheck, FILTER_VALIDATE_IP)
            ? [$hostCheck]
            : self::resolveHostIps($hostCheck);

        if ($ips === []) {
            return null;
        }

        // Prefer IPv4 for CURLOPT_RESOLVE pinning when both exist
        $pinIp = $ips[0];
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $pinIp = $ip;
                break;
            }
        }

        if (!self::isPublicIp($pinIp)) {
            return null;
        }

        // CURLOPT_RESOLVE host must be without brackets; IPv6 pin must be with brackets.
        $resolveHost = $hostCheck;
        $resolveIp = filter_var($pinIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '[' . $pinIp . ']'
            : $pinIp;

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Kirby-Blogroll-Block/1.0 (+https://github.com/diplix/kirby-blogroll-block)',
            CURLOPT_HTTPHEADER     => [
                'Accept: image/*,*/*;q=0.8',
            ],
            // Pin DNS to the IP we already validated as public (anti DNS-rebinding).
            CURLOPT_RESOLVE        => [
                $resolveHost . ':' . $port . ':' . $resolveIp,
            ],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$headers): int {
                $len = strlen($header);
                if (stripos($header, 'Location:') === 0) {
                    $headers['location'] = trim(substr($header, 9));
                }
                return $len;
            },
        ]);

        // Soft cap: abort after maxBytes + small slack (cURL may overshoot slightly)
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static function (
            $curl,
            float $downloadSize,
            float $downloaded
        ) use ($maxBytes): int {
            return ($downloaded > ($maxBytes + 8192)) ? 1 : 0;
        });

        try {
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $errno = curl_errno($ch);
        } catch (Throwable) {
            curl_close($ch);
            return null;
        }
        curl_close($ch);

        if ($body === false || $errno !== 0) {
            return null;
        }

        return [
            'code'     => $code,
            'body'     => (string) $body,
            'type'     => trim(explode(';', $ctype)[0]),
            'location' => (string) ($headers['location'] ?? ''),
        ];
    }

    private static function absoluteUrl(string $location, string $base): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }

        // Already absolute
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $baseParts = parse_url($base);
        if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return null;
        }

        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        $origin = $scheme . '://' . $host . $port;

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = (string) ($baseParts['path'] ?? '/');
        $dir = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/') + 1) : '/';
        return $origin . $dir . $location;
    }

    /**
     * Center-crop to a square and scale to $size×$size.
     * Prefers PNG when the source has alpha; JPEG otherwise.
     *
     * @return array{data: string, type: string, ext: string}|null
     */
    private static function resize(string $data, string $type, int $size): ?array
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
            return null;
        }

        $src = @imagecreatefromstring($data);
        if ($src === false) {
            return null;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW < 1 || $srcH < 1) {
            imagedestroy($src);
            return null;
        }

        $side = min($srcW, $srcH);
        $srcX = (int) floor(($srcW - $side) / 2);
        $srcY = (int) floor(($srcH - $side) / 2);

        $dst = imagecreatetruecolor($size, $size);
        if ($dst === false) {
            imagedestroy($src);
            return null;
        }

        // Preserve transparency for PNG/GIF/WebP when possible
        $keepAlpha = in_array($type, ['image/png', 'image/gif', 'image/webp'], true);
        if ($keepAlpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $size, $size, $transparent);
        }

        imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $size, $size, $side, $side);
        imagedestroy($src);

        ob_start();
        if ($keepAlpha && function_exists('imagepng')) {
            imagepng($dst, null, 6);
            $outType = 'image/png';
            $ext = 'png';
        } else {
            imagejpeg($dst, null, 85);
            $outType = 'image/jpeg';
            $ext = 'jpg';
        }
        imagedestroy($dst);
        $out = (string) ob_get_clean();

        if ($out === '') {
            return null;
        }

        return ['data' => $out, 'type' => $outType, 'ext' => $ext];
    }

    private static function detectMime(string $data): ?string
    {
        $len = strlen($data);
        if ($len >= 8 && str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if ($len >= 3 && str_starts_with($data, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if ($len >= 6 && in_array(substr($data, 0, 6), ['GIF87a', 'GIF89a'], true)) {
            return 'image/gif';
        }
        if ($len >= 12 && str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if ($len >= 4 && (str_starts_with($data, "\x00\x00\x01\x00") || str_starts_with($data, "\x00\x00\x02\x00"))) {
            return 'image/x-icon';
        }
        return null;
    }

    private static function fileResponse(string $path, ?int $maxAge = null): Response
    {
        $mime = mime_content_type($path) ?: 'image/jpeg';
        if (stripos($mime, 'image/') !== 0) {
            $mime = 'image/jpeg';
        }

        $maxAge ??= self::httpMaxAge();

        return new Response((string) file_get_contents($path), $mime, 200, [
            'Cache-Control' => 'public, max-age=' . $maxAge,
        ]);
    }
}