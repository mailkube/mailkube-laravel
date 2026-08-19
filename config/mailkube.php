<?php

declare(strict_types=1);

/**
 * Configuration for the mailkube/mailkube-laravel package.
 *
 * Publish it with:
 *
 *     php artisan vendor:publish --tag=mailkube-config
 *
 * Publishing is optional. The package merges these defaults, so an application that only sets
 * the environment variables never needs the file at all.
 */
return [

    /*
    |---------------------------------------------------------------------------------------------
    | Credentials
    |---------------------------------------------------------------------------------------------
    |
    | Every value here is null by default, and null means "not configured" rather than "empty".
    | The package omits an unset value entirely when it builds the SDK client, so the SDK's own
    | environment fallbacks still apply. Writing an explicit null through would defeat them.
    |
    */

    'api_key' => env('MAILKUBE_API_KEY'),

    'base_url' => env('MAILKUBE_BASE_URL'),

    'timeout' => env('MAILKUBE_TIMEOUT'),

    /*
    |---------------------------------------------------------------------------------------------
    | Logging
    |---------------------------------------------------------------------------------------------
    |
    | The name of a channel from `config/logging.php`. Set it and the SDK writes its request and
    | response metadata (never bodies, never the API key) through Laravel's logging stack at debug
    | level. Leave it null and the SDK stays silent unless its own `MAILKUBE_LOG` environment
    | variable says otherwise.
    |
    */

    'log_channel' => env('MAILKUBE_LOG_CHANNEL'),

    /*
    |---------------------------------------------------------------------------------------------
    | Inbound webhooks
    |---------------------------------------------------------------------------------------------
    |
    | `path` is null by default, which means NO route is registered. An HTTP endpoint that appears
    | in an application merely because a package was installed is a surprise, so opting in is
    | explicit. Set it to something like 'webhooks/mailkube' to enable it.
    |
    | The route defaults to the `api` middleware group: it has no session and therefore no CSRF
    | verification, which a machine-to-machine POST cannot satisfy. If you move it into `web`, add
    | the path to your CSRF exceptions or every delivery is rejected with a 419.
    |
    */

    'webhooks' => [

        'path' => env('MAILKUBE_WEBHOOK_PATH'),

        'middleware' => ['api'],

        'secret' => env('MAILKUBE_WEBHOOK_SECRET'),

        /*
         * Seconds of clock skew tolerated on a signature timestamp. Null uses the SDK's own
         * default, which is the value the platform documents; set it only if you have a reason.
         */
        'tolerance' => env('MAILKUBE_WEBHOOK_TOLERANCE'),

    ],

];
