<?php

namespace Blockroll;

/**
 * Normalize, filter and sort blogroll link data.
 *
 * Adapted from pfefferle/wordpress-blockroll (GPL-2.0-or-later).
 */
class Links
{
    /**
     * @param array<string, mixed> $link
     * @return array{
     *   url: string,
     *   name: string,
     *   description: string,
     *   feedUrl: string,
     *   photo: string,
     *   xfn: array<int, string>,
     *   added: string,
     *   active: bool
     * }
     */
    public static function normalize(array $link): array
    {
        $active = $link['active'] ?? true;
        if (is_string($active)) {
            $active = in_array(strtolower($active), ['1', 'true', 'yes', 'on'], true);
        }

        $xfn = $link['xfn'] ?? [];
        if (is_string($xfn)) {
            $xfn = preg_split('/[\s,]+/', $xfn, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return [
            'url'         => trim((string) ($link['url'] ?? '')),
            'name'        => trim((string) ($link['name'] ?? '')),
            'description' => trim((string) ($link['description'] ?? '')),
            'feedUrl'     => trim((string) ($link['feedUrl'] ?? $link['feedurl'] ?? '')),
            'photo'       => trim((string) ($link['photo'] ?? '')),
            'xfn'         => Xfn::sanitize($xfn),
            'added'       => trim((string) ($link['added'] ?? '')),
            'active'      => (bool) $active,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $links
     * @return array<int, array<string, mixed>>
     */
    public static function onlyActive(array $links): array
    {
        return array_values(array_filter(
            $links,
            static fn (array $link): bool => ($link['active'] ?? true) === true
                && trim((string) ($link['url'] ?? '')) !== ''
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $links
     * @return array<int, array<string, mixed>>
     */
    public static function sort(array $links, string $sortBy): array
    {
        $links = array_values($links);

        if ($sortBy === 'name') {
            usort(
                $links,
                static fn (array $a, array $b): int => strcasecmp(
                    (string) ($a['name'] !== '' ? $a['name'] : $a['url']),
                    (string) ($b['name'] !== '' ? $b['name'] : $b['url'])
                )
            );
        } elseif ($sortBy === 'added') {
            usort(
                $links,
                static fn (array $a, array $b): int => strcmp(
                    (string) ($b['added'] ?? ''),
                    (string) ($a['added'] ?? '')
                )
            );
        }

        return $links;
    }

    /**
     * Fill only empty discoverable fields from Discovery result.
     *
     * @param array<string, mixed> $link
     * @param array{name?: string, description?: string, feedUrl?: string, photo?: string} $discovered
     * @return array{link: array<string, mixed>, changed: bool}
     */
    public static function fillEmpty(array $link, array $discovered): array
    {
        $changed = false;
        $fields = ['name', 'description', 'feedUrl', 'photo'];

        foreach ($fields as $field) {
            $current = trim((string) ($link[$field] ?? ''));
            $next = trim((string) ($discovered[$field] ?? ''));
            if ($current === '' && $next !== '') {
                $link[$field] = $next;
                $changed = true;
            }
        }

        if (trim((string) ($link['added'] ?? '')) === '') {
            $link['added'] = date('Y-m-d');
            $changed = true;
        }

        if (!array_key_exists('active', $link) || $link['active'] === null || $link['active'] === '') {
            $link['active'] = true;
            $changed = true;
        }

        return ['link' => $link, 'changed' => $changed];
    }
}
