<?php

namespace Blockroll;

/**
 * XFN relationship tokens, grouped per the XFN 1.1 profile.
 *
 * Ported from pfefferle/wordpress-blockroll (GPL-2.0-or-later).
 */
class Xfn
{
    public const GROUPS = [
        'friendship'   => ['friend', 'acquaintance', 'contact'],
        'physical'     => ['met'],
        'professional' => ['co-worker', 'colleague'],
        'geographical' => ['co-resident', 'neighbor'],
        'family'       => ['child', 'parent', 'sibling', 'spouse', 'kin'],
        'romantic'     => ['muse', 'crush', 'date', 'sweetheart'],
        'identity'     => ['me'],
    ];

    public const EXCLUSIVE = ['friendship', 'geographical', 'family'];

    /**
     * @param array<int, string>|string $tokens
     * @return array<int, string>
     */
    public static function sanitize(array|string $tokens): array
    {
        if (is_string($tokens)) {
            $tokens = preg_split('/[\s,]+/', $tokens, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $allowed = array_merge(...array_values(self::GROUPS));
        $tokens = array_values(array_unique(array_intersect(array_map('strval', $tokens), $allowed)));

        if (in_array('me', $tokens, true)) {
            return ['me'];
        }

        foreach (self::EXCLUSIVE as $group) {
            $found = array_values(array_intersect($tokens, self::GROUPS[$group]));
            if (count($found) > 1) {
                array_shift($found);
                $tokens = array_values(array_diff($tokens, $found));
            }
        }

        return $tokens;
    }

    /**
     * @param array<int, string>|string $tokens
     */
    public static function relString(array|string $tokens): string
    {
        return implode(' ', self::sanitize($tokens));
    }

    /**
     * Options for Kirby multiselect fields.
     *
     * @return array<string, string>
     */
    public static function fieldOptions(): array
    {
        $options = [];
        foreach (self::GROUPS as $tokens) {
            foreach ($tokens as $token) {
                $options[$token] = $token;
            }
        }
        return $options;
    }

    /**
     * HTML profile link for the XFN 1.1 vocabulary (site-wide, like Upstream).
     * XFN is not limited to the blogroll, so the profile belongs on every page.
     */
    public static function profileTag(): string
    {
        return '<link rel="profile" href="https://gmpg.org/xfn/11">' . PHP_EOL;
    }
}
