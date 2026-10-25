<?php
return [
    'cache' => [
        'value' => [
            'type' => [
                'class_name' => '\Bitrix\Main\Data\CacheEngineRedis',
                'extension' => 'redis',
            ],
            'redis' => [
                'host' => 'redis',
                'port' => '6379',
            ],
            'sid' => $_SERVER['DOCUMENT_ROOT'] . '#01',
        ],
    ],
];
