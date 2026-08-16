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
    /**
     * Handle GET …?opml or /opml for the given path (empty = home).
     * Returns a Response, or null to continue routing.
     */
    public static function handle(?string $path): ?Response
    {
        $path = trim((string) $path, '/');
        $kirby = App::instance();

        // Dedicated directory URL
        if ($path === 'opml') {
            return self::directoryResponse();
        }

        $page = self::pageFromPath($path);
        if ($page === null) {
            return null;
        }

        if (self::pageHasBlogroll($page)) {
            return self::pageResponse($page);
        }

        // Home without a blogroll block → directory of all blogrolls
        if ($page->isHomePage()) {
            $pages = self::blogrollPages();
            if ($pages->count() > 0) {
                return self::directoryResponse();
            }
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
     */
    public static function opmlUrl(Page $page): string
    {
        $url = $page->url();
        return $url . (str_contains($url, '?') ? '&' : '?') . 'opml';
    }

    public static function title(Page $page): string
    {
        $title = trim($page->title()->value());
        if ($title === '') {
            $title = 'Blogroll';
        }

        $author = '';
        try {
            $user = $page->author()->toUser();
            if ($user) {
                $author = trim($user->name()->value());
            }
        } catch (Throwable) {
            // ignore
        }

        if ($author !== '') {
            return $title . ' by ' . $author;
        }

        return $title;
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
     * Drop the blogroll page index (call after content changes).
     */
    public static function flushIndexCache(): void
    {
        $file = self::indexCacheFile();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private static function indexCacheFile(): string
    {
        return App::instance()->root('cache') . '/blockroll/blogroll-page-ids.json';
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
        // Refresh at most daily; also flushed on page writes
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
        $file = self::indexCacheFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($file, json_encode(array_values($ids), JSON_UNESCAPED_SLASHES));
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
        $links = self::linksFromPage($page);
        $xml = snippet('blockroll/opml', [
            'page'  => $page,
            'links' => $links,
            'title' => self::title($page),
        ], true);

        return new Response($xml, 'text/xml', 200);
    }

    public static function directoryResponse(): Response
    {
        $pages = self::blogrollPages();
        $xml = snippet('blockroll/opml-directory', [
            'pages' => $pages,
            'site'  => App::instance()->site(),
        ], true);

        return new Response($xml, 'text/xml', 200);
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

        if (self::pageHasBlogroll($page)) {
            $tags[] = self::linkTag(self::opmlUrl($page), self::title($page));
        }

        // Home: also advertise every blogroll page (like Upstream)
        if ($page->isHomePage()) {
            foreach (self::blogrollPages() as $blogrollPage) {
                if ($blogrollPage->is($page)) {
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

        // Strip KirbyTags roughly for attributes
        $text = preg_replace('/\([a-z0-9_-]+:.*?\)/si', '', $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
