<?php

/**
 * Kirby Blogroll Block — blogroll block with h-cards, XFN and URL discovery.
 *
 * @package   Blockroll
 * @author    Felix Schwenzel
 * @copyright Felix Schwenzel
 * @license   GPL-2.0-or-later
 *
 * Discovery and XFN logic adapted from pfefferle/wordpress-blockroll
 * (https://github.com/pfefferle/wordpress-blockroll), GPL-2.0-or-later.
 */

use Blockroll\Autofill;
use Blockroll\Opml;
use Blockroll\Xfn;
use Kirby\Cms\App;
use Kirby\Cms\Page;

@include_once __DIR__ . '/vendor/autoload.php';

if (!class_exists(\Blockroll\Discovery::class)) {
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'Blockroll\\')) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen('Blockroll\\')));
        $path = __DIR__ . '/src/' . $relative . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
}

App::plugin('diplix/blockroll', [
    'options' => [
        'discoverTimeout' => 8,
        'discoverRetries' => 3,
        // Cache remote avatars via GET /blockroll/image?url=… (site/cache/blockroll-photos)
        'proxyPhotos'   => false,
        'proxySize'     => 96, // px square (2× retina for ~48px CSS avatars)
        'proxyTimeout'  => 10,
        'proxyMaxBytes' => 512000,
        'proxyCache'    => null, // default: {kirby cache root}/blockroll-photos
        // Re-fetch local avatars at most every N seconds (default 28 days; 0 = never)
        'proxyCacheTtl' => 2419200,
        // Save-hook Autofill can rewrite blocks; off by default until safer
        'autoEnrich'    => false,
        // Browser/CDN max-age for OPML responses (seconds)
        'opmlMaxAge'    => 3600,
    ],
    'blueprints' => [
        'blocks/blogroll' => __DIR__ . '/blueprints/blocks/blogroll.yml',
    ],
    'fields' => [
        // URL field with Discover button (fills empty sibling fields via API)
        'blockroll-url' => [
            'extends' => 'url',
        ],
    ],
    'snippets' => [
        'blocks/blogroll'           => __DIR__ . '/snippets/blocks/blogroll.php',
        'blockroll/opml'            => __DIR__ . '/snippets/opml.php',
        'blockroll/opml-directory'  => __DIR__ . '/snippets/opml-directory.php',
    ],
    'assets' => [
        'blockroll.css' => __DIR__ . '/assets/blockroll.css',
        'opml.xsl'      => __DIR__ . '/assets/opml.xsl',
    ],
    'api' => [
        'routes' => require __DIR__ . '/config/api.php',
    ],
    'routes' => require __DIR__ . '/config/routes.php',
    'hooks' => [
        'page.update:after' => function (Page $newPage, Page $oldPage) {
            Opml::onPageChanged($newPage, $oldPage);
            if (App::instance()->option('diplix.blockroll.autoEnrich') !== true) {
                return;
            }
            Autofill::enrichPage($newPage);
        },
        'page.create:after' => function (Page $page) {
            Opml::onPageChanged($page);
            if (App::instance()->option('diplix.blockroll.autoEnrich') !== true) {
                return;
            }
            Autofill::enrichPage($page);
        },
        'page.delete:after' => function ($status, $page = null) {
            if ($page instanceof Page) {
                Opml::onPageDeleted($page);
            }
        },
        'page.changeStatus:after' => function ($newPage = null, $oldPage = null) {
            Opml::onPageChanged(
                $newPage instanceof Page ? $newPage : null,
                $oldPage instanceof Page ? $oldPage : null
            );
        },
        'page.changeSlug:after' => function ($newPage = null, $oldPage = null) {
            Opml::onPageChanged(
                $newPage instanceof Page ? $newPage : null,
                $oldPage instanceof Page ? $oldPage : null
            );
        },
        // Inject CSS (when blogroll present) + XFN profile + rel="blogroll" discovery
        'page.render:after' => function (string $contentType, array $data, string $html, $page) {
            if ($contentType !== 'html' || ($head = strpos($html, '</head>')) === false) {
                return $html;
            }

            $tags = '';

            // XFN profile is site-wide (relationships may appear outside the block)
            $tags .= Xfn::profileTag();

            if (str_contains($html, 'blockroll-blogroll')) {
                $url = $this->plugin('diplix/blockroll')->asset('blockroll.css')->url();
                $tags .= '<link rel="stylesheet" href="' . $url . '">' . PHP_EOL;
            }

            if ($page instanceof Page) {
                $tags .= Opml::discoveryTags($page);
            }

            return substr_replace($html, $tags, $head, 0);
        },
    ],
]);
