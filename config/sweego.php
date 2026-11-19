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

    /*
    |--------------------------------------------------------------------------
    | Bulk Request Timeout
    |--------------------------------------------------------------------------
    |
    | The number of seconds to wait for a bulk request ("/send/bulk/email").
    | A bulk request can carry large attachments, so it gets more time than a
    | single mail. It can be overridden per mailer with a "bulk_timeout" entry.
    |
    */

    'bulk_timeout' => (int) env('SWEEGO_BULK_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Campaign Type
    |--------------------------------------------------------------------------
    |
    | The type sent as "campaign-type": "transac", "newsletter" or "market".
    | It can be overridden per mailer with a "campaign_type" entry.
    |
    */

    'campaign_type' => env('SWEEGO_CAMPAIGN_TYPE', 'transac'),

];
