<?php

use Blockroll\Opml;
use Blockroll\Options;
use Blockroll\PhotoProxy;
use Kirby\Http\Response;

/**
 * Whether the current request asks for OPML (?opml present).
 */
$hasOpmlQuery = static function (): bool {
    return array_key_exists('opml', kirby()->request()->query()->data());
};

$prefix = Options::routePrefix();
$directory = Options::directoryPath();

$skipOpmlAlias = static function (string $all) use ($prefix, $directory): bool {
    $all = trim($all, '/');
    if ($directory !== null && ($all === $directory || str_starts_with($all, $directory . '/'))) {
        return true;
    }
    if ($all === '.well-known/recommendations.opml') {
        return true;
    }
    $reserved = preg_quote($prefix, '#');
    return preg_match('#^(panel|api|media|' . $reserved . ')(/|$)#', $all) === 1;
};

$routes = [];

if (PhotoProxy::enabled()) {
    $routes[] = [
        'pattern' => $prefix . '/image',
        'method'  => 'GET',
        'action'  => function () {
            return PhotoProxy::respond(get('url'), false);
        },
    ];
}

$routes[] = [
    'pattern' => $prefix . '/opml.xsl',
    'method'  => 'GET|HEAD',
    'action'  => function () {
        return Opml::stylesheetResponse();
    },
];

if ($directory !== null) {
    $routes[] = [
        'pattern' => $directory,
        'method'  => 'GET|HEAD',
        'action'  => function () {
            return Opml::directoryResponse();
        },
    ];
}

if (Options::wellKnown()) {
    $routes[] = [
        'pattern' => '.well-known/recommendations.opml',
        'method'  => 'GET|HEAD',
        'action'  => function () {
            return Opml::directoryResponse();
        },
    ];
}

$routes[] = [
    'pattern' => '(:all).opml',
    'method'  => 'GET|HEAD',
    'action'  => function (string $all) {
        return Opml::handle($all) ?? $this->next();
    },
];

$routes[] = [
    'pattern' => '',
    'method'  => 'GET|HEAD',
    'action'  => function () use ($hasOpmlQuery, $directory) {
        if (!$hasOpmlQuery()) {
            return $this->next();
        }

        if ($directory === null) {
            return $this->next();
        }

        return Opml::redirectToDirectory();
    },
];

$routes[] = [
    'pattern' => '(:all)',
    'method'  => 'GET|HEAD',
    'action'  => function (string $all) use ($hasOpmlQuery, $skipOpmlAlias) {
        if (!$hasOpmlQuery()) {
            return $this->next();
        }

        if ($skipOpmlAlias($all)) {
            return $this->next();
        }

        return Response::redirect(url(trim($all, '/') . '.opml'), 301);
    },
];

return $routes;
