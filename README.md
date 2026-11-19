# Sweego for Laravel

Mail transport for [Sweego](https://www.sweego.io) in Laravel. It talks to the Sweego HTTP API through the Laravel HTTP client, so no additional SDK is required. The package also ships a bulk client that sends one message to up to 200 recipients with a single API call.

## Requirements

- PHP 8.2 or higher
- Laravel 11, 12 or 13

## Installation

```bash
composer require bitprogmbh/sweego-laravel
```

The service provider is registered through package auto-discovery.

## Configuration

Add your API key to `.env`:

```dotenv
SWEEGO_API_KEY=your-api-key
MAIL_MAILER=sweego
```

Register the mailer in `config/mail.php`:

```php
'mailers' => [
    'sweego' => [
        'transport' => 'sweego',
    ],
],
```

Optionally publish the package configuration:

```bash
php artisan vendor:publish --tag=sweego-config
```

### Options

| Key             | Environment variable    | Default                  | Description                                              |
|-----------------|-------------------------|--------------------------|----------------------------------------------------------|
| `key`           | `SWEEGO_API_KEY`        | –                        | API key used for authentication (required).              |
| `endpoint`      | `SWEEGO_ENDPOINT`       | `https://api.sweego.io`  | Base URL of the API, without a trailing slash.           |
| `timeout`       | `SWEEGO_TIMEOUT`        | `30`                     | Timeout in seconds for a single mail.                    |
| `bulk_timeout`  | `SWEEGO_BULK_TIMEOUT`   | `60`                     | Timeout in seconds for a bulk request.                   |
| `campaign_type` | `SWEEGO_CAMPAIGN_TYPE`  | `transac`                | `transac`, `newsletter` or `market`.                     |

Every option can be overridden per mailer in `config/mail.php`, which is useful if you send transactional mail and newsletters through different mailers:

```php
'mailers' => [
    'sweego' => [
        'transport' => 'sweego',
    ],
    'sweego-newsletter' => [
        'transport' => 'sweego',
        'key' => env('SWEEGO_NEWSLETTER_API_KEY'),
        'campaign_type' => 'newsletter',
    ],
],
```

## Usage

Send mail as usual. Mailables, notifications and `Mail::raw()` all work with the `sweego` mailer:

```php
Mail::mailer('sweego')->to($user)->send(new OrderShipped($order));
```

Sweego accepts up to 5 custom headers per message. A message with more headers is rejected before it is sent. After a successful send, the Sweego `transaction_id` is available as the message ID of the sent message.

### Bulk sending

`SweegoBulkMailer` renders a mailable once and delivers it to many recipients with one request to `/send/bulk/email`. Each recipient receives an individual mail and does not see the other recipients.

```php
use Bitprogmbh\SweegoLaravel\Bulk\BulkRecipient;
use Bitprogmbh\SweegoLaravel\Bulk\SweegoBulkMailer;

$mailer = SweegoBulkMailer::forMailer('sweego');

$recipients = [
    new BulkRecipient('anna@example.com', 'Anna', ['firstname' => 'Anna']),
    new BulkRecipient('ben@example.com', 'Ben', ['firstname' => 'Ben']),
];

foreach (SweegoBulkMailer::split($recipients) as $chunk) {
    $result = $mailer->send(new Newsletter, $chunk);

    $result->transactionId;                       // one ID per request
    $result->messageIdFor('anna@example.com');    // swg_uid of this recipient
}
```

Things to keep in mind:

- Per-recipient variables replace `{{firstname}}`-style placeholders in the subject and in the HTML and text body. Sweego does not substitute variables in headers; a header containing `{{` is rejected.
- A request takes at most 200 recipients (`SweegoBulkMailer::MAX_RECIPIENTS`). `split()` divides a larger list into evenly sized chunks.
- A single recipient is sent through `/send`, because the bulk endpoint requires at least two.
- Queued mailables (`ShouldQueue`) cannot be rendered for a bulk send. Dispatch the job that calls the bulk mailer instead.
- The response confirms that Sweego accepted the request, not that the mail was delivered.

## Error handling

If the API rejects a request, `SweegoApiException` (a Symfony `TransportException`) is thrown. If the API cannot be reached, a plain `TransportException` is thrown.

```php
use Bitprogmbh\SweegoLaravel\Exceptions\SweegoApiException;

try {
    $mailer->send($mailable, $chunk);
} catch (SweegoApiException $exception) {
    $exception->statusCode;
    $exception->body;

    $exception->isTooLarge();         // message including attachments exceeds 20 MB, retrying does not help
    $exception->isValidationError();  // 400/422, for example an invalid address, retrying does not help
}
```

## Testing

```bash
composer install
composer test
```

The tests use [Pest](https://pestphp.com) and [Orchestra Testbench](https://packages.tools/testbench).

## License

Copyright (C) 2026 Tobias Hug <tobias.hug@bitpro.ch>

This package is free software: you can redistribute it and/or modify it under the terms of the [GNU Affero General Public License](LICENSE) as published by the Free Software Foundation, version 3 of the License (`AGPL-3.0-only`).

This package is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Affero General Public License for more details.
