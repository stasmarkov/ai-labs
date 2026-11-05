<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mercure hub
    |--------------------------------------------------------------------------
    |
    | "publish_url" is where the app POSTs updates (the hub inside the web
    | container). "topic_base" prefixes every topic. The two keys sign the HS256
    | JWTs the hub checks; they come from .env only and are never committed.
    | Token lifetimes are in seconds.
    |
    */

    'enabled' => (bool) env('MERCURE_ENABLED', true),

    'publish_url' => env('MERCURE_PUBLISH_URL', 'http://localhost/.well-known/mercure'),

    'topic_base' => env('MERCURE_TOPIC_BASE', env('APP_URL', 'http://localhost')),

    'publisher_jwt_key' => env('MERCURE_PUBLISHER_JWT_KEY'),

    'subscriber_jwt_key' => env('MERCURE_SUBSCRIBER_JWT_KEY'),

    'publisher_token_ttl' => 60,

    'subscriber_token_ttl' => 3600,

    'timeout' => 2,

    'connect_timeout' => 1,

];
