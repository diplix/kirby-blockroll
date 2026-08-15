<?php

use Blockroll\Discovery;
use Kirby\Http\Response;

return [
    [
        'pattern' => 'blockroll/discover',
        'method'  => 'POST',
        'action'  => function () {
            $kirby = kirby();
            $body = $kirby->request()->body();
            $url = trim((string) ($body->get('url') ?? get('url') ?? ''));

            if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
                return Response::json([
                    'status'  => 'error',
                    'message' => 'Valid url required',
                ], 400);
            }

            $data = Discovery::fromUrl($url);

            return [
                'status' => 'ok',
                'data'   => $data,
            ];
        },
    ],
];
