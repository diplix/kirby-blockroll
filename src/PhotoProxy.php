<?php

namespace Blockroll;

use Kirby\Cms\App;
use Kirby\Http\Remote;
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
     */
    public static function url(string $remoteUrl): string
    {
        $remoteUrl = trim($remoteUrl);
        if ($remoteUrl === '' || !self::enabled() || !self::isRemoteHttp($remoteUrl)) {
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
        if ($url === '' || !self::isRemoteHttp($url)) {
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

            return new Response('', null, 302, ['Location' => $url]);
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

    private static function isRemoteHttp(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        return in_array($scheme, ['http', 'https'], true);
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
     * @return array{data: string, type: string}|null
     */
    private static function fetch(string $url): ?array
    {
        $timeout = (int) (App::instance()->option('diplix.blockroll.proxyTimeout') ?? 10);
        $maxBytes = (int) (App::instance()->option('diplix.blockroll.proxyMaxBytes') ?? 512000);

        try {
            $response = Remote::get($url, [
                'timeout' => $timeout,
                'headers' => [
                    'User-Agent' => 'Kirby-Blogroll-Block/1.0 (+https://github.com/diplix/kirby-blogroll-block)',
                    'Accept'     => 'image/*,*/*;q=0.8',
                ],
            ]);
        } catch (Throwable) {
            return null;
        }

        if ($response->code() < 200 || $response->code() >= 300) {
            return null;
        }

        $data = (string) $response->content();
        if ($data === '' || strlen($data) > $maxBytes) {
            return null;
        }

        $type = trim(explode(';', (string) ($response->info()['content_type'] ?? ''))[0]);
        if ($type === '' || stripos($type, 'image/') !== 0) {
            $type = self::detectMime($data) ?? '';
        }

        if ($type === '' || stripos($type, 'image/') !== 0) {
            return null;
        }

        return ['data' => $data, 'type' => strtolower($type)];
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