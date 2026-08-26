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
        return self::blocksOnPage($page, true) !== [];
    }

    /**
     * Whether this blogroll block should appear in OPML discovery / aggregation.
     * Missing field (older content) defaults to true.
     */
    public static function blockPublishesOpml(\Kirby\Cms\Block $block): bool
    {
        return $block->publishOpml()->toBool(true);
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
        foreach (self::blocksOnPage($page, true) as $block) {
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

        foreach (self::blocksOnPage($page, true) as $block) {
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
     * Blogroll blocks on the page.
     *
     * @param bool $opmlOnly When true, only blocks with „Als OPML veröffentlichen“ (default on).
     * @return list<\Kirby\Cms\Block>
     */
    public static function blocksOnPage(Page $page, bool $opmlOnly = false): array
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
                    if ($block->type() !== 'blogroll') {
                        continue;
                    }
                    if ($opmlOnly && !self::blockPublishesOpml($block)) {
                        continue;
                    }
                    $found[] = $block;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $found;
    }

    /**
     * Listed pages that contain at least one blogroll block.
     * Uses a persistent ID index (no daily expiry). Missing index is rebuilt under lock.
     */
    public static function blogrollPages(): Pages
    {
        $kirby = App::instance();
        $ids = self::readIndexCache();

        if ($ids === null) {
            $ids = self::rebuildIndexLocked();
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
     * After page create/update/delete/status/slug: only touch caches when a blogroll is involved.
     * Index is updated surgically (never wiped for unrelated page saves).
     */
    public static function onPageChanged(?Page $page, ?Page $previous = null): void
    {
        $candidates = [];
        if ($page instanceof Page) {
            $candidates[] = $page;
        }
        if ($previous instanceof Page) {
            $candidates[] = $previous;
        }

        if ($candidates === []) {
            return;
        }

        $relevant = false;
        foreach ($candidates as $candidate) {
            if (self::pageHasBlogroll($candidate) || self::indexContains($candidate->id())) {
                $relevant = true;
                break;
            }
        }

        if ($relevant === false) {
            return;
        }

        if ($page instanceof Page) {
            self::flushPageCache($page);
            self::syncPageInIndex($page);
        }

        if ($previous instanceof Page && (!$page instanceof Page || $previous->id() !== $page->id())) {
            self::flushPageCache($previous);
            self::removeFromIndex($previous->id());
        }

        self::flushDirectoryCache();
        self::scheduleWarmDirectory();
    }

    /**
     * Rebuild page-id index + directory OPML (for shutdown / maintenance).
     */
    public static function warmCaches(): void
    {
        self::rebuildIndexLocked(force: true);
        self::flushDirectoryCache();
        try {
            self::directoryResponse();
        } catch (Throwable) {
            // ignore warm failures
        }
    }

    /**
     * Drop page/directory XML only (legacy). Prefer onPageChanged().
     *
     * @deprecated Use onPageChanged()
     */
    public static function flushCaches(?Page $page = null): void
    {
        if ($page instanceof Page) {
            self::onPageChanged($page);
            return;
        }

        self::flushDirectoryCache();
        self::flushAllPageCaches();
        self::scheduleWarmIndex();
    }

    /**
     * @deprecated Use onPageChanged()
     */
    public static function flushIndexCache(): void
    {
        // Intentionally no longer deletes the persistent index.
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

    private static function indexContains(string $id): bool
    {
        $ids = self::readIndexCache();
        return is_array($ids) && in_array($id, $ids, true);
    }

    private static function syncPageInIndex(Page $page): void
    {
        $ids = self::readIndexCache();
        if ($ids === null) {
            self::scheduleWarmIndex();
            return;
        }

        $shouldList = $page->isListed() && self::pageHasBlogroll($page);
        $id = $page->id();
        $has = in_array($id, $ids, true);

        if ($shouldList && !$has) {
            $ids[] = $id;
            self::writeIndexCache($ids);
        } elseif (!$shouldList && $has) {
            self::removeFromIndex($id);
        }
    }

    private static function removeFromIndex(string $id): void
    {
        $ids = self::readIndexCache();
        if ($ids === null) {
            return;
        }

        $next = array_values(array_filter($ids, static fn (string $x): bool => $x !== $id));
        if (count($next) !== count($ids)) {
            self::writeIndexCache($next);
        }
    }

    /**
     * @return list<string>
     */
    private static function scanBlogrollPageIds(): array
    {
        $ids = [];
        foreach (App::instance()->site()->index()->listed() as $page) {
            if (self::pageHasBlogroll($page)) {
                $ids[] = $page->id();
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private static function rebuildIndexLocked(bool $force = false): array
    {
        self::ensureDir(self::cacheRoot());
        $lockFile = self::cacheRoot() . '/index.lock';
        $fp = @fopen($lockFile, 'c+');
        if ($fp === false) {
            $ids = self::scanBlogrollPageIds();
            self::writeIndexCache($ids);
            return $ids;
        }

        if (flock($fp, LOCK_EX | LOCK_NB)) {
            try {
                if ($force === false) {
                    $existing = self::readIndexCache();
                    if ($existing !== null) {
                        return $existing;
                    }
                }
                $ids = self::scanBlogrollPageIds();
                self::writeIndexCache($ids);
                return $ids;
            } finally {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }

        // Another worker is rebuilding — wait up to ~90s for the file
        for ($i = 0; $i < 900; $i++) {
            usleep(100000);
            $ids = self::readIndexCache();
            if ($ids !== null) {
                fclose($fp);
                return $ids;
            }
        }

        fclose($fp);
        return [];
    }

    /**
     * Page was deleted: drop from index / page OPML if it was a blogroll page.
     */
    public static function onPageDeleted(Page $page): void
    {
        if (!self::pageHasBlogroll($page) && !self::indexContains($page->id())) {
            return;
        }

        self::flushPageCache($page);
        self::removeFromIndex($page->id());
        self::flushDirectoryCache();
        self::scheduleWarmDirectory();
    }

    private static function scheduleWarmIndex(): void
    {
        self::scheduleAfterResponse(static function (): void {
            self::rebuildIndexLocked(force: true);
            self::flushDirectoryCache();
            try {
                self::directoryResponse();
            } catch (Throwable) {
            }
        });
    }

    private static function scheduleWarmDirectory(): void
    {
        self::scheduleAfterResponse(static function (): void {
            try {
                self::directoryResponse();
            } catch (Throwable) {
            }
        });
    }

    private static function scheduleAfterResponse(callable $callback): void
    {
        static $queue = [];
        static $registered = false;

        $queue[] = $callback;
        if ($registered) {
            return;
        }
        $registered = true;

        register_shutdown_function(static function () use (&$queue): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            foreach ($queue as $cb) {
                try {
                    $cb();
                } catch (Throwable) {
                }
            }
            $queue = [];
        });
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
        // htmlspecialchars statt esc(..., 'attr'): Kirby/Laminas Escaper
        // hex-encodiert :, /, ?, Leerzeichen usw. — unnötig in doppelten Anführungszeichen.
        $e = static fn(string $v): string => htmlspecialchars(
            $v,
            ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
            'UTF-8'
        );

        return '<link rel="blogroll" type="text/xml" href="'
            . $e($href)
            . '" title="'
            . $e($title)
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
