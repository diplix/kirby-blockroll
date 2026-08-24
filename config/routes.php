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
            return PhotoProxy::respond(get('url'), false);
        },
    ],
    [
        'pattern' => 'blockroll/opml.xsl',
        'method'  => 'GET|HEAD',
        'action'  => function () {
            return Opml::stylesheetResponse();
        },
    ],
    // Canonical directory of all blogroll pages
    [
        'pattern' => 'opml',
        'method'  => 'GET|HEAD',
        'action'  => function () {
            return Opml::directoryResponse();
        },
    ],
    // Same directory at the well-known discovery URL (opml.org / Upstream)
    [
        'pattern' => '.well-known/recommendations.opml',
        'method'  => 'GET|HEAD',
        'action'  => function () {
            return Opml::directoryResponse();
        },
    ],
    // Home /?opml → 301 /opml
    [
        'pattern' => '',
        'method'  => 'GET|HEAD',
        'action'  => function () use ($hasOpmlQuery) {
            if (!$hasOpmlQuery()) {
                return $this->next();
            }

            return Opml::redirectToDirectory();
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
