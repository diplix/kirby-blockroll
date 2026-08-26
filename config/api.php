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

            if (
                class_exists(\Blockroll\PhotoProxy::class)
                && \Blockroll\PhotoProxy::isAllowedRemoteUrl($url, true) !== true
            ) {
                return Response::json([
                    'status'  => 'error',
                    'message' => 'URL not allowed',
                ], 400);
            }

            $data = Discovery::fromUrl($url);
            $error = isset($data['error']) ? (string) $data['error'] : '';
            unset($data['error']);

            return [
                'status'  => $error !== '' ? 'error' : 'ok',
                'message' => $error !== '' ? $error : null,
                'data'    => $data,
            ];
        },
    ],
];
