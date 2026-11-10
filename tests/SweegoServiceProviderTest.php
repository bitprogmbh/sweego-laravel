<?php

use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('registers the sweego mail driver', function () {
    expect((string) Mail::mailer('sweego')->getSymfonyTransport())->toBe('sweego+api://api.sweego.io');
});

it('prefers the key and endpoint configured on the mailer', function () {
    config()->set('mail.mailers.sweego', [
        'transport' => 'sweego',
        'key' => 'mailer-key',
        'endpoint' => 'https://eu.sweego.test/',
    ]);
    Http::fake(['eu.sweego.test/send' => Http::response(['transaction_id' => 'tx-1'])]);

    Mail::raw('Hello', fn (Message $message) => $message->to('anna@example.com')->subject('Greetings'));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://eu.sweego.test/send'
        && $request->hasHeader('Api-Key', 'mailer-key'));
});

it('rejects a mailer without an API key', function () {
    config()->set('sweego.key', null);

    Mail::mailer('sweego');
})->throws(InvalidArgumentException::class, 'Sweego API key is missing');

it('publishes the config file', function () {
    $this->artisan('vendor:publish', ['--tag' => 'sweego-config', '--force' => true])->assertSuccessful();

    expect(config_path('sweego.php'))->toBeFile();

    unlink(config_path('sweego.php'));
});
