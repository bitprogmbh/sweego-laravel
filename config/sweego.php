<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sweego API Key
    |--------------------------------------------------------------------------
    |
    | The API key used to authenticate against the Sweego API. It can be
    | overridden per mailer with a "key" entry in config/mail.php.
    |
    */

    'key' => env('SWEEGO_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | API Endpoint
    |--------------------------------------------------------------------------
    |
    | The base URL of the Sweego API without a trailing slash. Mails are
    | posted to "{endpoint}/send". It can be overridden per mailer with an
    | "endpoint" entry in config/mail.php.
    |
    */

    'endpoint' => env('SWEEGO_ENDPOINT', 'https://api.sweego.io'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | The number of seconds to wait for the Sweego API before the request
    | fails. It can be overridden per mailer with a "timeout" entry.
    |
    */

    'timeout' => (int) env('SWEEGO_TIMEOUT', 30),

];
