<?php

namespace Blockroll;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use SimplePie\SimplePie;
use Throwable;

/**
 * Last-publication timestamps per feed URL (SimplePie), file-cached.
 * Call refreshAll() from a cron/job; render paths only read the cache.
 */
class FeedActivity
{
    /**
     * @return array{generated: string, feeds: array<string, array{lastPublished: int|null, error: string|null}>, errors: int, count: int}
     */
    public static function refreshAll(): array
    {
        $feedUrls = self::collectFeedUrls();
        $existing = self::readCache();
        $prevFeeds = is_array($existing['feeds'] ?? null) ? $existing['feeds'] : [];

        $feeds = [];
        $errors = 0;
        $timeout = self::timeout();

        foreach ($feedUrls as $feedUrl) {
            $prev = $prevFeeds[$feedUrl] ?? null;
            $stale = is_array($prev) && isset($prev['lastPublished']) && is_numeric($prev['lastPublished'])
                ? (int) $prev['lastPublished']
                : null;

            try {
                $ts = self::fetchLatestTimestamp($feedUrl, $timeout);
                if ($ts !== null && $ts > 0) {
                    $feeds[$feedUrl] = [
                        'lastPublished' => $ts,
                        'error'         => null,
                    ];
                } else {
                    $errors++;
                    $feeds[$feedUrl] = [
                        'lastPublished' => $stale,
                        'error'         => 'no item date',
                    ];
                }
            } catch (Throwable $e) {
                $errors++;
                $feeds[$feedUrl] = [
                    'lastPublished' => $stale,
                    'error'         => $e->getMessage(),
                ];
                error_log('blockroll FeedActivity: ' . $feedUrl . ' — ' . $e->getMessage());
            }
        }

        $payload = [
            'generated' => date('c'),
            'count'     => count($feeds),
            'errors'    => $errors,
            'feeds'     => $feeds,
        ];

        self::writeCache($payload);

        $pages = self::blogrollPagesList();
        kirby()->trigger('blockroll.feedActivity:after', $payload, $pages);

        return $payload;
    }

    public static function lastPublished(string $feedUrl): ?int
    {
        $feedUrl = trim($feedUrl);
        if ($feedUrl === '') {
            return null;
        }

        $cache = self::readCache();
        $entry = $cache['feeds'][$feedUrl] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        $ts = $entry['lastPublished'] ?? null;
        return is_numeric($ts) && (int) $ts > 0 ? (int) $ts : null;
    }

    /**
     * @return list<string>
     */
    public static function collectFeedUrls(): array
    {
        $urls = [];
        $seen = [];

        foreach (self::blogrollPagesList() as $page) {
            foreach (Opml::blocksOnPage($page) as $block) {
                foreach ($block->links()->toStructure() as $item) {
                    $link = Links::normalize([
                        'url'         => $item->url()->value(),
                        'name'        => $item->name()->value(),
                        'description' => $item->description()->value(),
                        'feedUrl'     => $item->feedUrl()->value(),
                        'photo'       => $item->photo()->value(),
                        'xfn'         => $item->xfn()->split(','),
                        'added'       => $item->added()->value(),
                        'active'      => $item->active()->toBool(true),
                    ]);
                    if ($link['active'] !== true || $link['feedUrl'] === '') {
                        continue;
                    }
                    $key = strtolower($link['feedUrl']);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $urls[] = $link['feedUrl'];
                }
            }
        }

        return $urls;
    }

    /**
     * @return list<Page>
     */
    public static function blogrollPagesList(): array
    {
        if (!method_exists(Opml::class, 'blogrollPages')) {
            return [];
        }

        $pages = [];
        foreach (Opml::blogrollPages() as $page) {
            if ($page instanceof Page) {
                $pages[] = $page;
            }
        }

        return $pages;
    }

    private static function timeout(): int
    {
        $t = App::instance()->option('diplix.blockroll.activityTimeout', 8);

        return max(1, (int) $t);
    }

    private static function cachePath(): string
    {
        return App::instance()->root('cache') . '/blockroll/feed-activity.json';
    }

    /**
     * @return array<string, mixed>
     */
    private static function readCache(): array
    {
        $path = self::cachePath();
        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function writeCache(array $payload): void
    {
        $path = self::cachePath();
        Dir::make(dirname($path), true);
        $tmp = $path . '.' . getmypid() . '.tmp';
        F::write($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        rename($tmp, $path);
    }

    private static function fetchLatestTimestamp(string $feedUrl, int $timeout): ?int
    {
        if (!class_exists(SimplePie::class)) {
            throw new \RuntimeException('simplepie/simplepie is not installed');
        }

        if (
            class_exists(PhotoProxy::class)
            && PhotoProxy::isAllowedRemoteUrl($feedUrl, true) !== true
        ) {
            throw new \RuntimeException('feed URL not allowed');
        }

        $feed = new SimplePie();
        $feed->set_feed_url($feedUrl);
        $feed->enable_cache(false);
        $feed->set_timeout($timeout);
        $feed->force_feed(true);
        $feed->init();
        $feed->handle_content_type();

        if ($feed->error()) {
            throw new \RuntimeException((string) $feed->error());
        }

        $items = $feed->get_items(0, 1);
        if ($items === [] || $items === null) {
            return null;
        }

        $ts = $items[0]->get_date('U');

        return is_numeric($ts) ? (int) $ts : null;
    }
}
