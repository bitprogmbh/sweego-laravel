<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportException;

beforeEach(function () {
    Http::preventStrayRequests();
});

function fakeSweego(int $status = 200, array $body = ['transaction_id' => 'tx-123']): void
{
    Http::fake(['api.sweego.io/send' => Http::response($body, $status)]);
}

function sentPayload(): array
{
    $payload = null;

    Http::assertSent(function (Request $request) use (&$payload) {
        $payload = $request->data();

        return true;
    });

    return $payload;
}

it('posts the mail to the Sweego send endpoint with the Api-Key header', function () {
    fakeSweego();

    Mail::raw('Hello', fn (Message $message) => $message->to('anna@example.com', 'Anna')->subject('Greetings'));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.sweego.io/send'
        && $request->method() === 'POST'
        && $request->hasHeader('Api-Key', 'test-key'));
});

it('sends recipients, sender, subject and bodies in the Sweego payload format', function () {
    fakeSweego();

    Mail::html('<p>Hello</p>', fn (Message $message) => $message
        ->to('anna@example.com', 'Anna')
        ->cc('cc@example.com')
        ->bcc('bcc@example.com')
        ->subject('Greetings'));

    expect(sentPayload())->toMatchArray([
        'recipients' => [
            ['email' => 'anna@example.com', 'name' => 'Anna'],
            ['email' => 'cc@example.com'],
            ['email' => 'bcc@example.com'],
        ],
        'from' => ['email' => 'noreply@example.com', 'name' => 'Example'],
        'subject' => 'Greetings',
        'message-html' => '<p>Hello</p>',
        'campaign-type' => 'transac',
        'channel' => 'email',
        'provider' => 'sweego',
    ])->not->toHaveKeys(['message-txt', 'attachments', 'headers', 'reply-to']);
});

it('sends the text body as message-txt', function () {
    fakeSweego();

    Mail::raw('Plain text', fn (Message $message) => $message->to('anna@example.com')->subject('Greetings'));

    expect(sentPayload())->toMatchArray(['message-txt' => 'Plain text'])->not->toHaveKey('message-html');
});

it('sets the message id from the Sweego transaction id', function () {
    fakeSweego(body: ['transaction_id' => 'tx-987']);

    $sentMessage = Mail::mailer('sweego')->raw('Hello', fn (Message $message) => $message->to('anna@example.com')->subject('Greetings'));

    expect($sentMessage->getMessageId())->toBe('tx-987');
});

it('sends only the first reply-to address', function () {
    fakeSweego();

    Mail::raw('Hello', fn (Message $message) => $message
        ->to('anna@example.com')
        ->replyTo('first@example.com', 'First')
        ->replyTo('second@example.com')
        ->subject('Greetings'));

    expect(sentPayload()['reply-to'])->toBe(['email' => 'first@example.com', 'name' => 'First']);
});

it('base64 encodes attachments and gives inline attachments a content id', function () {
    fakeSweego();

    Mail::html('<img src="cid:logo">', function (Message $message) {
        $message->to('anna@example.com')->subject('Greetings');
        $message->attachData('file-content', 'report.txt', ['mime' => 'text/plain']);
        $message->embedData('image-content', 'logo.png', 'image/png');
    });

    $attachments = sentPayload()['attachments'];

    expect($attachments)->toHaveCount(2)
        ->and($attachments[0])->toMatchArray([
            'content' => base64_encode('file-content'),
            'filename' => 'report.txt',
            'disposition' => 'attachment',
        ])->not->toHaveKey('content_id')
        ->and($attachments[1])->toMatchArray([
            'content' => base64_encode('image-content'),
            'filename' => 'logo.png',
            'disposition' => 'inline',
        ])->toHaveKey('content_id');
});

it('forwards custom headers but not the headers that have their own field', function () {
    fakeSweego();

    Mail::raw('Hello', function (Message $message) {
        $message->to('anna@example.com')->cc('cc@example.com')->subject('Greetings');
        $message->getHeaders()->addTextHeader('X-Tour-Id', '42');
    });

    expect(sentPayload()['headers'])->toBe(['X-Tour-Id' => '42']);
});

it('rejects a message with more than five custom headers', function () {
    fakeSweego();

    Mail::raw('Hello', function (Message $message) {
        $message->to('anna@example.com')->subject('Greetings');

        foreach (range(1, 6) as $number) {
            $message->getHeaders()->addTextHeader("X-Custom-{$number}", 'value');
        }
    });
})->throws(InvalidArgumentException::class, 'up to 5 custom headers');

it('throws a transport exception with status and body when Sweego rejects the mail', function () {
    fakeSweego(422, ['message' => 'Invalid sender']);

    Mail::raw('Hello', fn (Message $message) => $message->to('anna@example.com')->subject('Greetings'));
})->throws(TransportException::class, 'Invalid sender"} (code 422)');

it('throws a transport exception when the Sweego API is unreachable', function () {
    Http::fake(['api.sweego.io/send' => fn () => throw new ConnectionException('timed out')]);

    Mail::raw('Hello', fn (Message $message) => $message->to('anna@example.com')->subject('Greetings'));
})->throws(TransportException::class, 'Could not reach the Sweego API: timed out');

it('sends the campaign type configured on the mailer', function () {
    config()->set('mail.mailers.sweego', ['transport' => 'sweego', 'campaign_type' => 'newsletter']);
    fakeSweego();

    Mail::raw('Hello', fn (Message $message) => $message->to('anna@example.com')->subject('Greetings'));

    expect(sentPayload())->toMatchArray(['campaign-type' => 'newsletter']);
});
