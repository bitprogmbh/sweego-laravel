<?php

namespace Bitprogmbh\SweegoLaravel\Bulk;

use Bitprogmbh\SweegoLaravel\Exceptions\SweegoApiException;
use Bitprogmbh\SweegoLaravel\Mail\PayloadBuilder;
use Bitprogmbh\SweegoLaravel\SweegoSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Versendet eine Mail an mehrere Empfänger mit einem einzigen API-Aufruf (`/send/bulk/email`).
 *
 * Der Inhalt wird einmal gerendert und gilt für alle Empfänger. Jeder Empfänger erhält eine eigene Mail und
 * sieht die anderen nicht. Variablen ersetzt Sweego nur im Betreff und in `message-html` / `message-txt`,
 * nicht in Headern. Ein einzelner Empfänger geht über `/send`, weil der Bulk-Endpunkt mindestens 2 verlangt.
 */
class SweegoBulkMailer
{
    public const MAX_RECIPIENTS = 200;

    /**
     * Platzhalter-Empfänger, damit Laravel das Mailable rendern kann. Es wird nichts an ihn gesendet.
     */
    protected const CAPTURE_ADDRESS = 'bulk@sweego.invalid';

    public function __construct(
        protected SweegoSettings $settings,
        protected PayloadBuilder $payloads = new PayloadBuilder,
    ) {}

    /**
     * Ob der Mailer aus `config/mail.php` den Sweego-Transport verwendet.
     */
    public static function supportsMailer(string $name): bool
    {
        return config("mail.mailers.{$name}.transport") === 'sweego';
    }

    /**
     * Erstellt den Client mit den Einstellungen des Mailers (Key, Endpoint, Timeouts, Kampagnentyp).
     */
    public static function forMailer(string $name): self
    {
        if (! self::supportsMailer($name)) {
            throw new InvalidArgumentException(sprintf('The mailer "%s" does not use the Sweego transport.', $name));
        }

        return new self(SweegoSettings::fromMailerConfig((array) config("mail.mailers.{$name}")));
    }

    /**
     * Teilt eine Liste gleichmässig in Chunks von höchstens `$size` Einträgen (höchstens 200). Die Chunks
     * unterscheiden sich um höchstens einen Eintrag. Ab einer Grösse von 3 hat kein Chunk weniger als 2 Einträge,
     * ausser die Liste selbst hat nur einen.
     *
     * @template T
     *
     * @param  array<array-key, T>  $items
     * @return list<list<T>>
     */
    public static function split(array $items, int $size = self::MAX_RECIPIENTS): array
    {
        $items = array_values($items);
        $count = count($items);

        if ($count === 0) {
            return [];
        }

        $size = max(1, min($size, self::MAX_RECIPIENTS));
        $chunks = intdiv($count + $size - 1, $size);
        $base = intdiv($count, $chunks);
        $extra = $count % $chunks;

        $result = [];
        $offset = 0;

        for ($index = 0; $index < $chunks; $index++) {
            $length = $base + ($index < $extra ? 1 : 0);
            $result[] = array_slice($items, $offset, $length);
            $offset += $length;
        }

        return $result;
    }

    /**
     * @param  list<BulkRecipient>  $recipients  Zwischen 1 und 200 Empfänger, grössere Listen vorher mit `split()` teilen.
     * @param  string|null  $campaignType  `transac`, `newsletter` oder `market`, sonst der Standard aus der Konfiguration.
     *
     * @throws SweegoApiException Die API hat die Anfrage abgelehnt.
     * @throws TransportException Die API ist nicht erreichbar.
     */
    public function send(Mailable|Email $message, array $recipients, ?string $campaignType = null): BulkResult
    {
        $recipients = array_values($recipients);
        $count = count($recipients);

        if ($count === 0) {
            throw new InvalidArgumentException('A bulk send needs at least one recipient.');
        }

        if ($count > self::MAX_RECIPIENTS) {
            throw new InvalidArgumentException(sprintf('The Sweego bulk endpoint accepts up to %d recipients per request, %d given. Use split() first.', self::MAX_RECIPIENTS, $count));
        }

        $email = $message instanceof Mailable ? $this->capture($message) : $message;
        $payload = $this->payloads->build($email, Envelope::create($email)->getSender(), $campaignType ?? $this->settings->campaignType);

        $this->assertHeadersHaveNoPlaceholders($payload['headers'] ?? []);

        if ($count === 1) {
            $path = '/send';
            $payload['recipients'] = [array_diff_key($recipients[0]->toPayload(), ['variables' => true])];

            if ($recipients[0]->variables !== []) {
                $payload['variables'] = $recipients[0]->variables;
            }
        } else {
            $path = '/send/bulk/email';
            $payload['recipients'] = array_map(fn (BulkRecipient $recipient): array => $recipient->toPayload(), $recipients);
        }

        return $this->post($path, $payload);
    }

    /**
     * Rendert das Mailable einmal zu einer Symfony-Mail, ohne etwas zu versenden. Ein eigener Mailer mit
     * Array-Transport und ohne Event-Dispatcher verhindert, dass App-Listener oder `Mail::fake()` eingreifen.
     */
    protected function capture(Mailable $mailable): Email
    {
        if ($mailable instanceof ShouldQueue) {
            throw new InvalidArgumentException('A queued mailable cannot be rendered for a bulk send.');
        }

        $mailer = new Mailer('sweego-bulk', app('view'), new ArrayTransport);
        $sentMessage = (clone $mailable)->to(self::CAPTURE_ADDRESS)->send($mailer);

        if ($sentMessage === null) {
            throw new LogicException('The mailable could not be rendered for a bulk send.');
        }

        return MessageConverter::toEmail($sentMessage->getSymfonySentMessage()->getOriginalMessage());
    }

    /**
     * Sweego ersetzt Variablen nicht in Headern. Ein Platzhalter käme wörtlich beim Empfänger an.
     *
     * @param  array<string, string>  $headers
     */
    protected function assertHeadersHaveNoPlaceholders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            if (str_contains($value, '{{')) {
                throw new InvalidArgumentException(sprintf('The header "%s" contains a "{{" placeholder, but Sweego does not substitute variables in headers.', $name));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function post(string $path, array $payload): BulkResult
    {
        try {
            $response = Http::withHeaders(['Api-Key' => $this->settings->apiKey])
                ->acceptJson()
                ->asJson()
                ->timeout($this->settings->bulkTimeout)
                ->post(rtrim($this->settings->endpoint, '/').$path, $payload);
        } catch (ConnectionException $exception) {
            throw new TransportException('Could not reach the Sweego API: '.$exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            throw SweegoApiException::fromResponse($response);
        }

        $transactionId = $response->json('transaction_id');
        $swgUids = $response->json('swg_uids');

        return new BulkResult(
            is_string($transactionId) && $transactionId !== '' ? $transactionId : null,
            is_array($swgUids) ? $swgUids : [],
        );
    }
}
