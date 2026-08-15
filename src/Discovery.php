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
     * @return array{name: string, description: string, feedUrl: string, photo: string}
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
            return $empty;
        }

        $timeout = (int) (App::instance()->option('diplix.blockroll.discoverTimeout') ?? 8);

        try {
            $response = Remote::get($url, [
                'timeout' => $timeout,
                'headers' => [
                    'User-Agent' => 'Kirby-Blockroll/1.0 (+https://getkirby.com)',
                    'Accept'     => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                ],
            ]);
        } catch (Throwable) {
            return $empty;
        }

        if ($response->code() < 200 || $response->code() >= 400) {
            return $empty;
        }

        $finalUrl = $response->info()['url'] ?? $url;
        if (!is_string($finalUrl) || $finalUrl === '') {
            $finalUrl = $url;
        }

        return self::fromHtml((string) $response->content(), $finalUrl);
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

        $hcard = self::findByClass($xpath, null, 'h-card');
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
            foreach ($xpath->query('//meta[@name="description"][@content]') as $node) {
                $result['description'] = self::sanitizeText($node->getAttribute('content'));
                break;
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

        return $result;
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
