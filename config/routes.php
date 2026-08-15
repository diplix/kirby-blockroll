<?php

use Blockroll\PhotoProxy;

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
];
