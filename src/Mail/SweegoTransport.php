<?php

namespace Bitprogmbh\SweegoLaravel\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

class SweegoTransport extends AbstractTransport
{
    public const MAX_HEADERS = 5;

    /**
     * Headers that are sent as dedicated payload fields or derived from the envelope.
     *
     * @var list<string>
     */
    protected const EXCLUDED_HEADERS = ['to', 'cc', 'bcc', 'from', 'sender', 'subject', 'reply-to'];

    public function __construct(
        #[SensitiveParameter] protected string $apiKey,
        protected string $endpoint = 'https://api.sweego.io',
        protected int $timeout = 30,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        try {
            $response = Http::withHeaders(['Api-Key' => $this->apiKey])
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout)
                ->post(rtrim($this->endpoint, '/').'/send', $this->buildPayload($email, $message->getEnvelope()));
        } catch (ConnectionException $exception) {
            throw new TransportException('Could not reach the Sweego API: '.$exception->getMessage(), 0, $exception);
        }

        if ($response->failed()) {
            throw new TransportException(sprintf('Unable to send an email via Sweego: %s (code %d).', $response->body(), $response->status()));
        }

        $transactionId = $response->json('transaction_id');

        if (is_string($transactionId) && $transactionId !== '') {
            $message->setMessageId($transactionId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPayload(Email $email, Envelope $envelope): array
    {
        $payload = [
            'recipients' => array_map($this->formatAddress(...), $envelope->getRecipients()),
            'from' => $this->formatAddress($envelope->getSender()),
            'subject' => $email->getSubject(),
            'campaign-type' => 'transac',
            'channel' => 'email',
            'provider' => 'sweego',
        ];

        $replyTo = $email->getReplyTo();

        if ($replyTo !== []) {
            $payload['reply-to'] = $this->formatAddress($replyTo[0]);
        }

        if ($email->getTextBody()) {
            $payload['message-txt'] = $email->getTextBody();
        }

        if ($email->getHtmlBody()) {
            $payload['message-html'] = $email->getHtmlBody();
        }

        if ($email->getAttachments() !== []) {
            $payload['attachments'] = $this->formatAttachments($email);
        }

        $headers = $this->formatHeaders($email);

        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        return $payload;
    }

    /**
     * @return array{email: string, name?: string}
     */
    protected function formatAddress(Address $address): array
    {
        $formatted = ['email' => $address->getEncodedAddress()];

        if ($address->getName() !== '') {
            $formatted['name'] = $address->getName();
        }

        return $formatted;
    }

    /**
     * @return list<array{content: string, filename: ?string, disposition: string, content_id?: string}>
     */
    protected function formatAttachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename');
            $disposition = $headers->getHeaderBody('Content-Disposition');

            $formatted = [
                'content' => base64_encode($attachment->getBody()),
                'filename' => $filename,
                'disposition' => $disposition,
            ];

            if ($disposition === 'inline') {
                $formatted['content_id'] = $attachment->hasContentId() ? $attachment->getContentId() : $filename;
            }

            $attachments[] = $formatted;
        }

        return $attachments;
    }

    /**
     * @return array<string, string>
     */
    protected function formatHeaders(Email $email): array
    {
        $formatted = [];

        foreach ($email->getHeaders()->all() as $header) {
            if (in_array(strtolower($header->getName()), self::EXCLUDED_HEADERS, true)) {
                continue;
            }

            $formatted[$header->getName()] = $header->getBodyAsString();
        }

        if (count($formatted) > self::MAX_HEADERS) {
            throw new InvalidArgumentException(sprintf('The Sweego API supports up to %d custom headers.', self::MAX_HEADERS));
        }

        return $formatted;
    }

    public function __toString(): string
    {
        return 'sweego+api://'.(parse_url($this->endpoint, PHP_URL_HOST) ?: $this->endpoint);
    }
}
