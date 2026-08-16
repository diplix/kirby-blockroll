<?php

namespace Blockroll;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Http\Response;
use Throwable;

/**
 * OPML export and rel="blogroll" discovery.
 * Adapted from pfefferle/wordpress-blockroll (GPL-2.0-or-later).
 */
class Opml
{
    /** Default browser/CDN max-age for OPML responses (seconds). */
    private const DEFAULT_MAX_AGE = 3600;

    /**
     * Handle GET …?opml for a page path (not home — home ?opml redirects to /opml).
     * Returns a Response, or null to continue routing.
     */
    public static function handle(?string $path): ?Response
    {
        $path = trim((string) $path, '/');

        if ($path === '' || $path === 'home' || $path === 'opml') {
            return null;
        }

        $page = self::pageFromPath($path);
        if ($page === null) {
            return null;
        }

        if (self::pageHasBlogroll($page)) {
            return self::pageResponse($page);
        }

        return null;
    }

    public static function pageFromPath(string $path): ?Page
    {
        $kirby = App::instance();
        if ($path === '' || $path === 'home') {
            return $kirby->site()->homePage();
        }

        try {
            return $kirby->page($path);
        } catch (Throwable) {
            return null;
        }
    }

    public static function pageHasBlogroll(Page $page): bool
    {
        return self::blocksOnPage($page) !== [];
    }

    /**
     * OPML URL for a page (permalink + ?opml).
     * Home page points at the canonical directory /opml.
     */
    public static function opmlUrl(Page $page): string
    {
        if ($page->isHomePage()) {
            return url('opml');
        }

        $url = $page->url();
        return $url . (str_contains($url, '?') ? '&' : '?') . 'opml';
    }

    /**
     * Canonical directory URL.
     */
    public static function directoryUrl(): string
    {
        return url('opml');
    }

    /**
     * Display / OPML title for a single blogroll block.
     */
    public static function blockTitle(\Kirby\Cms\Block $block): string
    {
        $name = trim((string) $block->content()->get('name')->value());
        return $name !== '' ? $name : 'Blogroll';
    }

    /**
     * Title for page-level OPML / discovery: block name(s), else page title.
     * (No author suffix — that came from the page author field, not a hardcoded string.)
     */
    public static function title(Page $page): string
    {
        $names = [];
        foreach (self::blocksOnPage($page) as $block) {
            $names[] = self::blockTitle($block);
        }

        $names = array_values(array_unique(array_filter($names)));
        if ($names !== []) {
            return implode(', ', $names);
        }

        $title = trim($page->title()->value());
        return $title !== '' ? $title : 'Blogroll';
    }

    /**
     * Active, normalized links from all blogroll blocks on the page.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function linksFromPage(Page $page): array
    {
        $all = [];
        $sortBy = 'name';

        foreach (self::blocksOnPage($page) as $block) {
            $sortBy = $block->sortBy()->or('name')->value();
            foreach ($block->links()->toStructure() as $item) {
                $all[] = Links::normalize([
                    'url'         => $item->url()->value(),
                    'name'        => $item->name()->value(),
                    'description' => $item->description()->value(),
                    'feedUrl'     => $item->feedUrl()->value(),
                    'photo'       => $item->photo()->value(),
                    'xfn'         => $item->xfn()->split(','),
                    'added'       => $item->added()->value(),
                    'active'      => $item->active()->toBool(true),
                ]);
            }
        }

        $all = Links::onlyActive($all);
        return Links::sort($all, $sortBy);
    }

    /**
     * @return list<\Kirby\Cms\Block>
     */
    public static function blocksOnPage(Page $page): array
    {
        $found = [];

        foreach ($page->blueprint()->fields() as $name => $props) {
            if (($props['type'] ?? null) !== 'blocks') {
                continue;
            }

            $field = $page->content()->get($name);
            if ($field->isEmpty()) {
                continue;
            }

            try {
                foreach ($field->toBlocks() as $block) {
                    if ($block->type() === 'blogroll') {
                        $found[] = $block;
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $found;
    }

    /**
     * Listed pages that contain at least one blogroll block.
     * Result is cached (site index + block scan is expensive on large sites).
     */
    public static function blogrollPages(): Pages
    {
        $kirby = App::instance();
        $ids = self::readIndexCache();

        if ($ids === null) {
            $ids = [];
            foreach ($kirby->site()->index()->listed() as $page) {
                if (self::pageHasBlogroll($page)) {
                    $ids[] = $page->id();
                }
            }
            self::writeIndexCache($ids);
        }

        $pages = [];
        foreach ($ids as $id) {
            $page = $kirby->page($id);
            if ($page instanceof Page && $page->isListed() && self::pageHasBlogroll($page)) {
                $pages[] = $page;
            }
        }

        return new Pages($pages);
    }

    /**
     * Drop index + all OPML XML caches (call after content changes).
     */
    public static function flushCaches(?Page $page = null): void
    {
        self::flushIndexCache();
        self::flushDirectoryCache();

        if ($page instanceof Page) {
            self::flushPageCache($page);
            return;
        }

        self::flushAllPageCaches();
    }

    /**
     * @deprecated Use flushCaches()
     */
    public static function flushIndexCache(): void
    {
        $file = self::indexCacheFile();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public static function flushDirectoryCache(): void
    {
        $file = self::directoryCacheFile();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public static function flushPageCache(Page $page): void
    {
        $file = self::pageCacheFile($page);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public static function flushAllPageCaches(): void
    {
        $dir = self::opmlCacheDir() . '/pages';
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*.xml') ?: [] as $file) {
            @unlink($file);
        }
    }

    private static function cacheRoot(): string
    {
        return App::instance()->root('cache') . '/blockroll';
    }

    private static function opmlCacheDir(): string
    {
        return self::cacheRoot() . '/opml';
    }

    private static function indexCacheFile(): string
    {
        return self::cacheRoot() . '/blogroll-page-ids.json';
    }

    private static function directoryCacheFile(): string
    {
        return self::opmlCacheDir() . '/directory.xml';
    }

    private static function pageCacheFile(Page $page): string
    {
        $key = hash('sha256', $page->id());
        return self::opmlCacheDir() . '/pages/' . $key . '.xml';
    }

    /**
     * @return list<string>|null
     */
    private static function readIndexCache(): ?array
    {
        $file = self::indexCacheFile();
        if (!is_file($file)) {
            return null;
        }

        $mtime = filemtime($file);
        if ($mtime !== false && $mtime < time() - 86400) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? array_values(array_map('strval', $data)) : null;
    }

    /**
     * @param list<string> $ids
     */
    private static function writeIndexCache(array $ids): void
    {
        self::ensureDir(dirname(self::indexCacheFile()));
        self::writeAtomic(self::indexCacheFile(), json_encode(array_values($ids), JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    private static function readXmlCache(string $file, ?int $mustBeNewerOrEqual = null): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        if ($mustBeNewerOrEqual !== null) {
            $mtime = filemtime($file);
            if ($mtime === false || $mtime < $mustBeNewerOrEqual) {
                return null;
            }
        }

        $xml = file_get_contents($file);
        return is_string($xml) && $xml !== '' ? $xml : null;
    }

    private static function writeXmlCache(string $file, string $xml): void
    {
        self::ensureDir(dirname($file));
        self::writeAtomic($file, $xml);
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    private static function writeAtomic(string $file, string $contents): void
    {
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contents) === false) {
            return;
        }
        rename($tmp, $file);
    }

    private static function maxAge(): int
    {
        $age = App::instance()->option('diplix.blockroll.opmlMaxAge');
        if ($age === null || $age === '') {
            return self::DEFAULT_MAX_AGE;
        }

        return max(0, (int) $age);
    }

    private static function xmlResponse(string $xml): Response
    {
        $maxAge = self::maxAge();

        return new Response($xml, 'text/xml', 200, [
            'Cache-Control' => 'public, max-age=' . $maxAge,
        ]);
    }

    /**
     * Public URL of the browser OPML stylesheet (stable route).
     */
    public static function stylesheetUrl(): string
    {
        return url('blockroll/opml.xsl');
    }

    /**
     * XML declaration + xml-stylesheet processing instruction (like Upstream).
     */
    public static function prolog(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<?xml-stylesheet type="text/xsl" href="'
            . htmlspecialchars(self::stylesheetUrl(), ENT_XML1 | ENT_QUOTES, 'UTF-8')
            . '"?>' . "\n";
    }

    public static function stylesheetResponse(): Response
    {
        $path = dirname(__DIR__) . '/assets/opml.xsl';
        $body = is_file($path) ? (string) file_get_contents($path) : '';

        return new Response($body, 'text/xsl', 200, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public static function pageResponse(Page $page): Response
    {
        $cacheFile = self::pageCacheFile($page);
        $modified = (int) $page->modified();
        $cached = self::readXmlCache($cacheFile, $modified);

        if ($cached !== null) {
            return self::xmlResponse($cached);
        }

        $links = self::linksFromPage($page);
        $xml = snippet('blockroll/opml', [
            'page'  => $page,
            'links' => $links,
            'title' => self::title($page),
        ], true);

        self::writeXmlCache($cacheFile, $xml);

        return self::xmlResponse($xml);
    }

    public static function directoryResponse(): Response
    {
        $cacheFile = self::directoryCacheFile();
        $cached = self::readXmlCache($cacheFile);

        if ($cached !== null) {
            return self::xmlResponse($cached);
        }

        $pages = self::blogrollPages();
        $xml = snippet('blockroll/opml-directory', [
            'pages' => $pages,
            'site'  => App::instance()->site(),
        ], true);

        self::writeXmlCache($cacheFile, $xml);

        return self::xmlResponse($xml);
    }

    /**
     * 301 to canonical directory /opml.
     */
    public static function redirectToDirectory(): Response
    {
        return Response::redirect(self::directoryUrl(), 301);
    }

    /**
     * HTML <link rel="blogroll"> tags for the current page context.
     */
    public static function discoveryTags(?Page $page): string
    {
        if ($page === null) {
            return '';
        }

        $tags = [];

        if (self::pageHasBlogroll($page) && !$page->isHomePage()) {
            $tags[] = self::linkTag(self::opmlUrl($page), self::title($page));
        }

        // Home: advertise every blogroll page (like Upstream), not /opml
        if ($page->isHomePage()) {
            foreach (self::blogrollPages() as $blogrollPage) {
                if ($blogrollPage->isHomePage()) {
                    continue;
                }
                $tags[] = self::linkTag(self::opmlUrl($blogrollPage), self::title($blogrollPage));
            }
        }

        return implode('', $tags);
    }

    private static function linkTag(string $href, string $title): string
    {
        return '<link rel="blogroll" type="text/xml" href="'
            . esc($href, 'attr')
            . '" title="'
            . esc($title, 'attr')
            . '">' . PHP_EOL;
    }

    /**
     * Plain-text description for OPML attributes (no HTML).
     */
    public static function plainDescription(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/\([a-z0-9_-]+:.*?\)/si', '', $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
