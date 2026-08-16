<?php

/**
 * Kirby Blockroll — blogroll block with h-cards, XFN and URL discovery.
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
        // Cache remote avatars via GET /blockroll/image?url=… (site/cache/blockroll-photos)
        'proxyPhotos'   => false,
        'proxySize'     => 96, // px square (2× retina for ~48px CSS avatars)
        'proxyTimeout'  => 10,
        'proxyMaxBytes' => 512000,
        'proxyCache'    => null, // default: {kirby cache root}/blockroll-photos
        // Save-hook Autofill can rewrite blocks; off by default until safer
        'autoEnrich'    => false,
    ],
    'blueprints' => [
        'blocks/blogroll' => __DIR__ . '/blueprints/blocks/blogroll.yml',
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
            Opml::flushIndexCache();
            if (App::instance()->option('diplix.blockroll.autoEnrich') !== true) {
                return;
            }
            Autofill::enrichPage($newPage);
        },
        'page.create:after' => function (Page $page) {
            Opml::flushIndexCache();
            if (App::instance()->option('diplix.blockroll.autoEnrich') !== true) {
                return;
            }
            Autofill::enrichPage($page);
        },
        'page.delete:after' => function () {
            Opml::flushIndexCache();
        },
        'page.changeStatus:after' => function () {
            Opml::flushIndexCache();
        },
        // Inject CSS (when blogroll present) + rel="blogroll" discovery links
        'page.render:after' => function (string $contentType, array $data, string $html, $page) {
            if ($contentType !== 'html' || ($head = strpos($html, '</head>')) === false) {
                return $html;
            }

            $tags = '';

            if (str_contains($html, 'blockroll-blogroll')) {
                $url = $this->plugin('diplix/blockroll')->asset('blockroll.css')->url();
                $tags .= '<link rel="stylesheet" href="' . $url . '">' . PHP_EOL;
            }

            if ($page instanceof Page) {
                $tags .= Opml::discoveryTags($page);
            }

            if ($tags === '') {
                return $html;
            }

            return substr_replace($html, $tags, $head, 0);
        },
    ],
]);
