<?php

use Bitprogmbh\SweegoLaravel\SweegoSettings;

it('reads the timeouts and the campaign type from the package configuration', function () {
    $settings = SweegoSettings::fromMailerConfig();

    expect($settings->timeout)->toBe(30)
        ->and($settings->bulkTimeout)->toBe(60)
        ->and($settings->campaignType)->toBe('transac');
});

it('prefers the entries of the mailer over the package configuration', function () {
    $settings = SweegoSettings::fromMailerConfig([
        'key' => 'mailer-key',
        'endpoint' => 'https://eu.sweego.test',
        'timeout' => 10,
        'bulk_timeout' => 120,
        'campaign_type' => 'market',
    ]);

    expect($settings)->apiKey->toBe('mailer-key')
        ->endpoint->toBe('https://eu.sweego.test')
        ->timeout->toBe(10)
        ->bulkTimeout->toBe(120)
        ->campaignType->toBe('market');
});

it('rejects an unknown campaign type', function () {
    SweegoSettings::fromMailerConfig(['campaign_type' => 'spam']);
})->throws(InvalidArgumentException::class, 'Unknown Sweego campaign type "spam"');
