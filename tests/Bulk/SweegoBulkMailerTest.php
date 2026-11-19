<?php

use Bitprogmbh\SweegoLaravel\Bulk\BulkRecipient;
use Bitprogmbh\SweegoLaravel\Bulk\SweegoBulkMailer;
use Bitprogmbh\SweegoLaravel\Exceptions\SweegoApiException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * @param  array<string, mixed>|string|null  $body
 */
function fakeBulkEndpoint(int $status = 200, array|string|null $body = null): void
{
    Http::fake(['api.sweego.io/send/bulk/email' => Http::response($body ?? [
        'transaction_id' => 'tx-1',
        'swg_uids' => ['anna@example.com' => 'uid-anna', 'bob@example.com' => 'uid-bob'],
    ], $status)]);
}

function recordedRequest(): Request
{
    return Http::recorded()->sole()[0];
}

function bulkMailer(): SweegoBulkMailer
{
    return SweegoBulkMailer::forMailer('sweego');
}

function bulkEmail(): Email
{
    return (new Email)
        ->from(new Address('noreply@example.com', 'Example'))
        ->subject('Hallo')
        ->html('<p>Hallo</p>')
        ->text('Hallo');
}

/**
 * @return list<BulkRecipient>
 */
function bulkRecipients(): array
{
    return [new BulkRecipient('anna@example.com', 'Anna'), new BulkRecipient('bob@example.com', 'Bob')];
}

function bulkMailable(): Mailable
{
    return new class extends Mailable
    {
        public static int $builds = 0;

        public function build(): static
        {
            self::$builds++;

            return $this->from('sender@example.com', 'Sender')
                ->subject('Hallo')
                ->replyTo('reply@example.com', 'Reply')
                ->html('<p>Body</p>')
                ->attachData('pdf-content', 'datei.pdf', ['mime' => 'application/pdf'])
                ->withSymfonyMessage(function (Email $message): void {
                    $message->text('Plain');
                    $message->getHeaders()->addTextHeader('X-Tour-Id', '42');
                });
        }
    };
}

it('posts the shared content and each recipient with name and variables to the bulk endpoint', function () {
    fakeBulkEndpoint();
    $email = bulkEmail()->subject('Hallo {{name}}')->html('<p>Hi {{name}}</p>')->text('Hi {{name}}');

    bulkMailer()->send($email, [
        new BulkRecipient('anna@example.com', 'Anna', ['name' => 'Anna']),
        new BulkRecipient('bob@example.com', 'Bob', ['name' => 'Bob']),
    ]);

    $request = recordedRequest();
    expect($request->url())->toBe('https://api.sweego.io/send/bulk/email')
        ->and($request->hasHeader('Api-Key', 'test-key'))->toBeTrue()
        ->and($request->data())->toMatchArray([
            'recipients' => [
                ['email' => 'anna@example.com', 'name' => 'Anna', 'variables' => ['name' => 'Anna']],
                ['email' => 'bob@example.com', 'name' => 'Bob', 'variables' => ['name' => 'Bob']],
            ],
            'from' => ['email' => 'noreply@example.com', 'name' => 'Example'],
            'subject' => 'Hallo {{name}}',
            'message-html' => '<p>Hi {{name}}</p>',
            'message-txt' => 'Hi {{name}}',
            'campaign-type' => 'transac',
            'channel' => 'email',
            'provider' => 'sweego',
        ]);
});

it('leaves out name and variables of recipients that have none', function () {
    fakeBulkEndpoint();

    bulkMailer()->send(bulkEmail(), [new BulkRecipient('anna@example.com', 'Anna'), new BulkRecipient('bob@example.com')]);

    expect(recordedRequest()->data()['recipients'])->toBe([
        ['email' => 'anna@example.com', 'name' => 'Anna'],
        ['email' => 'bob@example.com'],
    ]);
});

it('renders a mailable once and sends its attachments, reply-to and headers with one request', function () {
    fakeBulkEndpoint();
    $mailable = bulkMailable();
    $mailable::$builds = 0;
    $recipients = [...bulkRecipients(), new BulkRecipient('carla@example.com', 'Carla')];

    bulkMailer()->send($mailable, $recipients);

    expect($mailable::$builds)->toBe(1)
        ->and(recordedRequest()->data())->toMatchArray([
            'from' => ['email' => 'sender@example.com', 'name' => 'Sender'],
            'subject' => 'Hallo',
            'reply-to' => ['email' => 'reply@example.com', 'name' => 'Reply'],
            'message-html' => '<p>Body</p>',
            'message-txt' => 'Plain',
            'headers' => ['X-Tour-Id' => '42'],
            'attachments' => [['content' => base64_encode('pdf-content'), 'filename' => 'datei.pdf', 'disposition' => 'attachment']],
        ])
        ->and(recordedRequest()->data()['recipients'])->toHaveCount(3);
});

it('does not address the placeholder recipient used to render a mailable', function () {
    fakeBulkEndpoint();

    bulkMailer()->send(bulkMailable(), bulkRecipients());

    $emails = collect(recordedRequest()->data()['recipients'])->pluck('email')->all();
    expect($emails)->toBe(['anna@example.com', 'bob@example.com']);
});

it('sends a single recipient through the send endpoint with the variables at root level', function () {
    Http::fake(['api.sweego.io/send' => Http::response(['transaction_id' => 'tx-2', 'swg_uids' => ['anna@example.com' => 'uid-anna']])]);

    $result = bulkMailer()->send(bulkEmail(), [new BulkRecipient('anna@example.com', 'Anna', ['name' => 'Anna'])]);

    $request = recordedRequest();
    expect($request->url())->toBe('https://api.sweego.io/send')
        ->and($request->data())->toMatchArray([
            'recipients' => [['email' => 'anna@example.com', 'name' => 'Anna']],
            'variables' => ['name' => 'Anna'],
        ]);
    expect($result->messageIdFor('anna@example.com'))->toBe('uid-anna');
});

it('returns the transaction id and the swg uid of each recipient', function () {
    fakeBulkEndpoint();

    $result = bulkMailer()->send(bulkEmail(), bulkRecipients());

    expect($result->transactionId)->toBe('tx-1')
        ->and($result->swgUids)->toBe(['anna@example.com' => 'uid-anna', 'bob@example.com' => 'uid-bob']);
});

it('returns no ids when the response carries none', function () {
    fakeBulkEndpoint(body: []);

    $result = bulkMailer()->send(bulkEmail(), bulkRecipients());

    expect($result->transactionId)->toBeNull()
        ->and($result->swgUids)->toBe([]);
});

it('rejects a recipient list that is empty or has more than 200 entries', function (int $count) {
    $recipients = array_map(fn (int $number): BulkRecipient => new BulkRecipient("user{$number}@example.com"), $count > 0 ? range(1, $count) : []);

    bulkMailer()->send(bulkEmail(), $recipients);
})->with([
    'no recipients' => 0,
    'one recipient too many' => 201,
])->throws(InvalidArgumentException::class);

it('rejects a queued mailable', function () {
    $mailable = new class extends Mailable implements ShouldQueue
    {
        public function build(): static
        {
            return $this->from('sender@example.com')->subject('Hallo')->html('<p>Body</p>');
        }
    };

    bulkMailer()->send($mailable, bulkRecipients());
})->throws(InvalidArgumentException::class, 'queued mailable');

it('rejects a header with a placeholder because sweego does not substitute headers', function () {
    $email = bulkEmail();
    $email->getHeaders()->addTextHeader('List-Unsubscribe', '<https://example.test/unsubscribe/{{token}}>');

    bulkMailer()->send($email, bulkRecipients());
})->throws(InvalidArgumentException::class, 'List-Unsubscribe');

it('uses the campaign type from the configuration', function () {
    fakeBulkEndpoint();
    config(['sweego.campaign_type' => 'newsletter']);

    bulkMailer()->send(bulkEmail(), bulkRecipients());

    expect(recordedRequest()->data())->toMatchArray(['campaign-type' => 'newsletter']);
});

it('lets the caller override the campaign type', function () {
    fakeBulkEndpoint();

    bulkMailer()->send(bulkEmail(), bulkRecipients(), campaignType: 'market');

    expect(recordedRequest()->data())->toMatchArray(['campaign-type' => 'market']);
});

it('builds the client from the key and endpoint of the sweego mailer', function () {
    config()->set('mail.mailers.sweego', ['transport' => 'sweego', 'key' => 'mailer-key', 'endpoint' => 'https://eu.sweego.test/']);
    Http::fake(['eu.sweego.test/send/bulk/email' => Http::response(['transaction_id' => 'tx-3'])]);

    bulkMailer()->send(bulkEmail(), bulkRecipients());

    $request = recordedRequest();
    expect($request->url())->toBe('https://eu.sweego.test/send/bulk/email')
        ->and($request->hasHeader('Api-Key', 'mailer-key'))->toBeTrue();
});

it('rejects a mailer that does not use the sweego transport', function () {
    config()->set('mail.mailers.log', ['transport' => 'log']);

    SweegoBulkMailer::forMailer('log');
})->throws(InvalidArgumentException::class, 'does not use the Sweego transport');

it('knows which mailers use the sweego transport', function (string $mailer, bool $expected) {
    config()->set('mail.mailers.log', ['transport' => 'log']);

    expect(SweegoBulkMailer::supportsMailer($mailer))->toBe($expected);
})->with([
    'sweego mailer' => ['sweego', true],
    'log mailer' => ['log', false],
    'unknown mailer' => ['missing', false],
]);

it('classifies a rejected request by status and body', function (int $status, string $body, bool $isValidationError, bool $isTooLarge) {
    fakeBulkEndpoint($status, $body);

    $exception = null;

    try {
        bulkMailer()->send(bulkEmail(), bulkRecipients());
    } catch (SweegoApiException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(SweegoApiException::class)
        ->and($exception->statusCode)->toBe($status)
        ->and($exception->body)->toBe($body)
        ->and($exception->isValidationError())->toBe($isValidationError)
        ->and($exception->isTooLarge())->toBe($isTooLarge);
})->with([
    'invalid content' => [422, '{"detail":"Wrong mailto format"}', true, false],
    'bad request' => [400, 'Bad request', true, false],
    'message over the size limit' => [422, 'Email over max size (20 Mo)', false, true],
    'payload too large' => [413, 'Request Entity Too Large', false, true],
    'invalid api key' => [401, 'Unauthorized', false, false],
    'server error' => [500, 'Unable to send message', false, false],
]);

it('puts status and body into the message of the exception', function () {
    fakeBulkEndpoint(422, 'Invalid sender');

    bulkMailer()->send(bulkEmail(), bulkRecipients());
})->throws(SweegoApiException::class, 'Invalid sender (code 422)');

it('throws a transport exception when the Sweego API is unreachable', function () {
    Http::fake(['api.sweego.io/send/bulk/email' => fn () => throw new ConnectionException('timed out')]);

    bulkMailer()->send(bulkEmail(), bulkRecipients());
})->throws(TransportException::class, 'Could not reach the Sweego API: timed out');

it('splits a list evenly into chunks of at most the given size', function (int $count, int $size, array $expectedSizes) {
    $chunks = SweegoBulkMailer::split(range(1, $count), $size);

    expect(array_map('count', $chunks))->toBe($expectedSizes);
})->with([
    'one item' => [1, 200, [1]],
    'exactly the maximum' => [200, 200, [200]],
    'one more than the maximum' => [201, 200, [101, 100]],
    'uneven rest' => [401, 200, [134, 134, 133]],
    'small size without a single-item rest' => [7, 3, [3, 2, 2]],
    'size above the maximum is capped' => [250, 500, [125, 125]],
]);

it('keeps the order of the items when splitting', function () {
    $items = range(1, 450);

    $chunks = SweegoBulkMailer::split($items, 200);

    expect(array_merge(...$chunks))->toBe($items);
});

it('returns no chunks for an empty list', function () {
    expect(SweegoBulkMailer::split([]))->toBe([]);
});
