<?php

namespace Bitprogmbh\SweegoLaravel;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Verbindungsdaten und Standardwerte für Sweego. Gemeinsam genutzt von Transport und Bulk-Client,
 * damit beide Key, Endpoint und Timeouts gleich aus der Konfiguration lesen.
 */
final readonly class SweegoSettings
{
    /**
     * @var list<string>
     */
    public const CAMPAIGN_TYPES = ['transac', 'newsletter', 'market'];

    public function __construct(
        #[SensitiveParameter] public string $apiKey,
        public string $endpoint,
        public int $timeout,
        public int $bulkTimeout,
        public string $campaignType,
    ) {}

    /**
     * Liest die Einstellungen. Ein Eintrag im Mailer (`config/mail.php`) überschreibt `config/sweego.php`.
     *
     * @param  array<string, mixed>  $config  Die Konfiguration des Mailers aus config/mail.php.
     */
    public static function fromMailerConfig(array $config = []): self
    {
        $apiKey = $config['key'] ?? config('sweego.key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new InvalidArgumentException('The Sweego API key is missing. Set SWEEGO_API_KEY or a "key" entry on the mailer.');
        }

        $campaignType = (string) ($config['campaign_type'] ?? config('sweego.campaign_type'));

        if (! in_array($campaignType, self::CAMPAIGN_TYPES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown Sweego campaign type "%s". Use one of: %s.', $campaignType, implode(', ', self::CAMPAIGN_TYPES)));
        }

        return new self(
            apiKey: $apiKey,
            endpoint: (string) ($config['endpoint'] ?? config('sweego.endpoint')),
            timeout: (int) ($config['timeout'] ?? config('sweego.timeout')),
            bulkTimeout: (int) ($config['bulk_timeout'] ?? config('sweego.bulk_timeout')),
            campaignType: $campaignType,
        );
    }
}
