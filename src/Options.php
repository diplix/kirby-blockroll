<?php

namespace Blockroll;

use Kirby\Cms\App;

/**
 * Plugin options (diplix.blockroll.*) with safe defaults for routes.
 */
class Options
{
    /**
     * Public path prefix for proxy + XSL (`/blockroll/image`, `/blockroll/opml.xsl`).
     */
    public static function routePrefix(): string
    {
        return self::safePath(
            App::instance()->option('diplix.blockroll.routePrefix', 'blockroll'),
            'blockroll'
        );
    }

    /**
     * Directory OPML path (`/opml`). Null = do not register the route.
     */
    public static function directoryPath(): ?string
    {
        $value = App::instance()->option('diplix.blockroll.directoryPath', 'opml');
        if ($value === false || $value === null) {
            return null;
        }

        $path = self::safePath($value, '');
        return $path !== '' ? $path : null;
    }

    public static function wellKnown(): bool
    {
        return App::instance()->option('diplix.blockroll.wellKnown', true) !== false;
    }

    public static function injectCss(): bool
    {
        return App::instance()->option('diplix.blockroll.injectCss', true) !== false;
    }

    private static function safePath(mixed $value, string $fallback): string
    {
        if (is_bool($value) || $value === null) {
            return $fallback;
        }

        $path = trim((string) $value, '/');
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '.')) {
            return $fallback;
        }

        return $path;
    }
}
