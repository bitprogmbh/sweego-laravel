<?php

namespace Bitprogmbh\SweegoLaravel;

use Bitprogmbh\SweegoLaravel\Mail\SweegoTransport;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class SweegoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sweego.php', 'sweego');

        $this->callAfterResolving(MailManager::class, function (MailManager $mailManager): void {
            $mailManager->extend('sweego', fn (array $config): SweegoTransport => $this->makeTransport($config));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sweego.php' => config_path('sweego.php'),
            ], 'sweego-config');
        }
    }

    /**
     * @param  array<string, mixed>  $config  The mailer configuration from config/mail.php.
     */
    protected function makeTransport(array $config): SweegoTransport
    {
        $apiKey = $config['key'] ?? config('sweego.key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new InvalidArgumentException('The Sweego API key is missing. Set SWEEGO_API_KEY or a "key" entry on the mailer.');
        }

        return new SweegoTransport(
            apiKey: $apiKey,
            endpoint: (string) ($config['endpoint'] ?? config('sweego.endpoint')),
            timeout: (int) ($config['timeout'] ?? config('sweego.timeout')),
        );
    }
}
