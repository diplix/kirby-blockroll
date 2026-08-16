<?php

use Blockroll\Opml;
use Blockroll\PhotoProxy;

/**
 * Whether the current request asks for OPML (?opml present).
 */
$hasOpmlQuery = static function (): bool {
    return array_key_exists('opml', kirby()->request()->query()->data());
};

return [
    [
        'pattern' => 'blockroll/image',
        'method'  => 'GET',
        'action'  => function () {
            return PhotoProxy::respond(
                get('url'),
                (bool) get('refresh')
            );
        },
    ],
    [
        'pattern' => 'blockroll/opml.xsl',
        'method'  => 'GET|HEAD',
        'action'  => function () {
            return Opml::stylesheetResponse();
        },
    ],
    // Directory of all blogroll pages (never advertised via rel="blogroll")
    [
        'pattern' => 'opml',
        'method'  => 'GET|HEAD',
        'action'  => function () {
            return Opml::directoryResponse();
        },
    ],
    // Home: /?opml
    [
        'pattern' => '',
        'method'  => 'GET|HEAD',
        'action'  => function () use ($hasOpmlQuery) {
            if (!$hasOpmlQuery()) {
                return $this->next();
            }

            return Opml::handle('') ?? $this->next();
        },
    ],
    // Any page: /path/to/page?opml
    [
        'pattern' => '(:all)',
        'method'  => 'GET|HEAD',
        'action'  => function (string $all) use ($hasOpmlQuery) {
            if (!$hasOpmlQuery()) {
                return $this->next();
            }

            if (preg_match('#^(panel|api|media|blockroll)(/|$)#', $all) === 1) {
                return $this->next();
            }

            return Opml::handle($all) ?? $this->next();
        },
    ],
];
