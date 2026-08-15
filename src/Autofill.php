<?php

namespace Blockroll;

use Kirby\Cms\Block;
use Kirby\Cms\Blocks;
use Kirby\Cms\Page;
use Throwable;

/**
 * Enrich blogroll block entries on page save (empty fields only).
 */
class Autofill
{
    private static bool $running = false;

    public static function enrichPage(Page $page): void
    {
        if (self::$running) {
            return;
        }

        $updates = [];

        foreach ($page->blueprint()->fields() as $name => $props) {
            if (($props['type'] ?? null) !== 'blocks') {
                continue;
            }

            $field = $page->content()->get($name);
            if ($field->isEmpty()) {
                continue;
            }

            try {
                $blocks = $field->toBlocks();
            } catch (Throwable) {
                continue;
            }

            if ($blocks->count() === 0) {
                continue;
            }

            $hasBlogroll = false;
            foreach ($blocks as $block) {
                if ($block->type() === 'blogroll') {
                    $hasBlogroll = true;
                    break;
                }
            }

            if (!$hasBlogroll) {
                continue;
            }

            $result = self::enrichBlocks($blocks);
            if ($result['changed']) {
                $updates[$name] = $result['blocks'];
            }
        }

        if ($updates === []) {
            return;
        }

        self::$running = true;
        try {
            $page->update($updates);
        } finally {
            self::$running = false;
        }
    }

    /**
     * @return array{blocks: array<int, array<string, mixed>>, changed: bool}
     */
    public static function enrichBlocks(Blocks $blocks): array
    {
        $out = [];
        $changed = false;

        foreach ($blocks as $block) {
            /** @var Block $block */
            $data = $block->toArray();

            if ($block->type() !== 'blogroll') {
                $out[] = $data;
                continue;
            }

            $content = $data['content'] ?? [];
            $links = $content['links'] ?? [];
            if (!is_array($links)) {
                $out[] = $data;
                continue;
            }

            $newLinks = [];
            foreach ($links as $link) {
                if (!is_array($link)) {
                    continue;
                }

                $url = trim((string) ($link['url'] ?? ''));
                $needsDiscover = $url !== ''
                    && (
                        trim((string) ($link['name'] ?? '')) === ''
                        || trim((string) ($link['description'] ?? '')) === ''
                        || trim((string) ($link['feedUrl'] ?? '')) === ''
                        || trim((string) ($link['photo'] ?? '')) === ''
                    );

                $discovered = [];
                if ($needsDiscover) {
                    try {
                        $discovered = Discovery::fromUrl($url);
                    } catch (Throwable) {
                        $discovered = [];
                    }
                }

                $filled = Links::fillEmpty($link, $discovered);
                $newLinks[] = $filled['link'];
                if ($filled['changed']) {
                    $changed = true;
                }
            }

            $content['links'] = $newLinks;
            $data['content'] = $content;
            $out[] = $data;
        }

        return ['blocks' => $out, 'changed' => $changed];
    }
}
