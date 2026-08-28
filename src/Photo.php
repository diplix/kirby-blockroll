<?php

namespace Blockroll;

/**
 * Resolve avatar/photo URLs for the frontend.
 */
class Photo
{
    /**
     * When `diplix.blockroll.proxyPhotos` is true, remote http(s) photos
     * are rewritten to the local `/{routePrefix}/image?url=…` cache proxy.
     */
    public static function src(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (
            str_starts_with($url, 'data:')
            || str_starts_with($url, '/')
            || str_starts_with($url, './')
            || !preg_match('#^https?://#i', $url)
        ) {
            return $url;
        }

        if (!PhotoProxy::enabled()) {
            return $url;
        }

        return PhotoProxy::url($url);
    }
}
