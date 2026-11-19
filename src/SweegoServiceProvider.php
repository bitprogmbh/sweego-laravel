<?php

namespace Bitprogmbh\SweegoLaravel;

use Bitprogmbh\SweegoLaravel\Mail\SweegoTransport;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;

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
        $settings = SweegoSettings::fromMailerConfig($config);

        return new SweegoTransport(
            apiKey: $settings->apiKey,
            endpoint: $settings->endpoint,
            timeout: $settings->timeout,
            campaignType: $settings->campaignType,
        );
    }
}
