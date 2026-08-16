<?php

namespace Blockroll;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Kirby\Cms\App;
use Kirby\Http\Remote;
use Throwable;

/**
 * Extract feed, name, description, and photo from a fetched HTML page.
 *
 * Ported from pfefferle/wordpress-blockroll (GPL-2.0-or-later).
 */
class Discovery
{
    /**
     * Fetch a URL and extract link details.
     *
     * Retries on HTTP 429/503 (Tumblr and others rate-limit scrapers).
     *
     * @return array{name: string, description: string, feedUrl: string, photo: string, error?: string}
     */
    public static function fromUrl(string $url): array
    {
        $empty = [
            'name'        => '',
            'description' => '',
            'feedUrl'     => '',
            'photo'       => '',
        ];

        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return $empty + ['error' => 'Ungültige URL'];
        }

        $timeout = (int) (App::instance()->option('diplix.blockroll.discoverTimeout') ?? 8);
        $attempts = max(1, (int) (App::instance()->option('diplix.blockroll.discoverRetries') ?? 3));
        $lastCode = 0;
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Remote::get($url, [
                    'timeout' => $timeout,
                    'headers' => [
                        // Identify politely; some hosts (Tumblr) rate-limit anonymous bots harder
                        'User-Agent'      => 'Mozilla/5.0 (compatible; Kirby-Blockroll/1.0; +https://github.com/diplix/kirby-blockroll)',
                        'Accept'          => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                        'Accept-Language' => 'en-US,en;q=0.8,de;q=0.7',
                    ],
                ]);
            } catch (Throwable $e) {
                $lastError = $e->getMessage() ?: 'Netzwerkfehler';
                if ($attempt < $attempts) {
                    usleep(400000 * $attempt);
                    continue;
                }
                return $empty + ['error' => $lastError];
            }

            $lastCode = (int) $response->code();

            if ($lastCode === 429 || $lastCode === 503) {
                $lastError = 'HTTP ' . $lastCode . ' (Rate-Limit) — bitte erneut versuchen';
                if ($attempt < $attempts) {
                    usleep(700000 * $attempt);
                    continue;
                }
                return $empty + ['error' => $lastError];
            }

            if ($lastCode < 200 || $lastCode >= 400) {
                return $empty + ['error' => 'HTTP ' . $lastCode . ' beim Abruf'];
            }

            $finalUrl = $response->info()['url'] ?? $url;
            if (!is_string($finalUrl) || $finalUrl === '') {
                $finalUrl = $url;
            }

            $parsed = self::fromHtml((string) $response->content(), $finalUrl);
            if (
                $parsed['name'] === ''
                && $parsed['description'] === ''
                && $parsed['feedUrl'] === ''
                && $parsed['photo'] === ''
            ) {
                $parsed['error'] = 'Seite geladen, aber keine Metadaten gefunden';
            }

            return $parsed;
        }

        return $empty + ['error' => $lastError ?: ('HTTP ' . $lastCode)];
    }

    /**
     * @return array{name: string, description: string, feedUrl: string, photo: string}
     */
    public static function fromHtml(string $html, string $baseUrl): array
    {
        $result = [
            'name'        => '',
            'description' => '',
            'feedUrl'     => '',
            'photo'       => '',
        ];

        if (trim($html) === '') {
            return $result;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);

        foreach ($xpath->query('//link[@rel and @href and @type]') as $node) {
            if (
                self::hasToken($node->getAttribute('rel'), 'alternate')
                && preg_match('#application/(rss|atom)\+xml#i', $node->getAttribute('type'))
            ) {
                $result['feedUrl'] = self::absolute($node->getAttribute('href'), $baseUrl);
                break;
            }
        }

        // Only use an h-card that represents this site (u-url same host).
        // Avoids picking blogroll/sidebar cards on large homepages.
        $hcard = self::representativeHcard($xpath, $baseUrl);
        if ($hcard !== null) {
            $result['name'] = self::hcardText($xpath, $hcard, 'p-name');
            $result['description'] = self::hcardText($xpath, $hcard, 'p-note');
            $result['photo'] = self::hcardUrl($xpath, $hcard, 'u-photo', $baseUrl);
        }

        if ($result['name'] === '') {
            $result['name'] = self::metaProperty($xpath, 'og:title');
        }

        if ($result['name'] === '') {
            $title = $xpath->query('//title')->item(0);
            $result['name'] = $title ? self::sanitizeText($title->textContent ?? '') : '';
        }

        if ($result['description'] === '') {
            $result['description'] = self::metaProperty($xpath, 'og:description');
        }

        if ($result['description'] === '') {
            foreach ($xpath->query('//meta[@name="description"][@content]') as $node) {
                $result['description'] = self::sanitizeText($node->getAttribute('content'));
                break;
            }
        }

        if ($result['photo'] === '') {
            $result['photo'] = self::metaProperty($xpath, 'og:image');
            if ($result['photo'] !== '') {
                $result['photo'] = self::absolute($result['photo'], $baseUrl);
            }
        }

        if ($result['photo'] === '') {
            foreach ($xpath->query('//link[@rel and @href]') as $node) {
                if (self::hasToken($node->getAttribute('rel'), 'icon')) {
                    $result['photo'] = self::absolute($node->getAttribute('href'), $baseUrl);
                    break;
                }
            }
        }

        // Prefer page title over a bare URL used as p-name
        if ($result['name'] !== '' && filter_var($result['name'], FILTER_VALIDATE_URL)) {
            $og = self::metaProperty($xpath, 'og:title');
            if ($og !== '') {
                $result['name'] = $og;
            } else {
                $title = $xpath->query('//title')->item(0);
                $fromTitle = $title ? self::sanitizeText($title->textContent ?? '') : '';
                if ($fromTitle !== '') {
                    $result['name'] = $fromTitle;
                }
            }
        }

        return $result;
    }

    /**
     * Prefer an h-card whose u-url points at the same site as $baseUrl.
     * Skips cards inside a blockroll list (common on sites that embed their own roll).
     */
    private static function representativeHcard(DOMXPath $xpath, string $baseUrl): ?DOMNode
    {
        foreach ($xpath->query('//*[@class]') as $node) {
            if (!self::hasToken($node->getAttribute('class'), 'h-card')) {
                continue;
            }

            if (self::isInsideBlockroll($node)) {
                continue;
            }

            $cardUrl = self::hcardUrl($xpath, $node, 'u-url', $baseUrl);
            if ($cardUrl !== '' && self::sameHost($cardUrl, $baseUrl)) {
                return $node;
            }
        }

        return null;
    }

    private static function isInsideBlockroll(DOMNode $node): bool
    {
        for ($current = $node; $current; $current = $current->parentNode) {
            if (!$current instanceof \DOMElement) {
                continue;
            }
            $class = $current->getAttribute('class');
            if (
                self::hasToken($class, 'blockroll')
                || self::hasToken($class, 'blockroll-blogroll')
                || self::hasToken($class, 'blockroll-entry')
            ) {
                return true;
            }
        }

        return false;
    }

    private static function sameHost(string $a, string $b): bool
    {
        $ha = strtolower((string) (parse_url($a, PHP_URL_HOST) ?? ''));
        $hb = strtolower((string) (parse_url($b, PHP_URL_HOST) ?? ''));
        $ha = preg_replace('#^www\.#', '', $ha) ?? $ha;
        $hb = preg_replace('#^www\.#', '', $hb) ?? $hb;

        return $ha !== '' && $ha === $hb;
    }

    private static function hasToken(string $value, string $token): bool
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        return in_array($token, $parts, true);
    }

    private static function metaProperty(DOMXPath $xpath, string $property): string
    {
        foreach ($xpath->query('//meta[@content]') as $node) {
            $prop = $node->getAttribute('property');
            if ($prop === '') {
                $prop = $node->getAttribute('name');
            }
            if (strcasecmp($prop, $property) === 0) {
                return self::sanitizeText($node->getAttribute('content'));
            }
        }
        return '';
    }

    private static function findByClass(DOMXPath $xpath, ?DOMNode $scope, string $class): ?DOMNode
    {
        foreach ($xpath->query('descendant-or-self::*[@class]', $scope) as $node) {
            if (self::hasToken($node->getAttribute('class'), $class)) {
                return $node;
            }
        }
        return null;
    }

    private static function hcardText(DOMXPath $xpath, DOMNode $hcard, string $class): string
    {
        $node = self::findByClass($xpath, $hcard, $class);
        return $node ? self::sanitizeText($node->textContent ?? '') : '';
    }

    private static function hcardUrl(DOMXPath $xpath, DOMNode $hcard, string $class, string $baseUrl): string
    {
        foreach ($xpath->query('descendant-or-self::*[@class]', $hcard) as $node) {
            if (!self::hasToken($node->getAttribute('class'), $class)) {
                continue;
            }
            $url = $node->getAttribute('src') ?: $node->getAttribute('href');
            if ($url !== '') {
                return self::absolute($url, $baseUrl);
            }
        }
        return '';
    }

    private static function absolute(string $url, string $baseUrl): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $url;
        }

        $parts = parse_url($baseUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $origin = $scheme . '://' . $host . $port;

        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }

        $path = $parts['path'] ?? '/';
        $dir = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
        return $origin . $dir . $url;
    }

    private static function sanitizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
