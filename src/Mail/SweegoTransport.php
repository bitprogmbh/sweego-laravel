<?php

namespace Bitprogmbh\SweegoLaravel\Mail;

use Bitprogmbh\SweegoLaravel\Exceptions\SweegoApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

class SweegoTransport extends AbstractTransport
{
    public const MAX_HEADERS = PayloadBuilder::MAX_HEADERS;

    public function __construct(
        #[SensitiveParameter] protected string $apiKey,
        protected string $endpoint = 'https://api.sweego.io',
        protected int $timeout = 30,
        protected string $campaignType = 'transac',
        protected PayloadBuilder $payloads = new PayloadBuilder,
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
            throw SweegoApiException::fromResponse($response);
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
        return [
            'recipients' => array_map($this->payloads->formatAddress(...), $envelope->getRecipients()),
            ...$this->payloads->build($email, $envelope->getSender(), $this->campaignType),
        ];
    }

    public function __toString(): string
    {
        return 'sweego+api://'.(parse_url($this->endpoint, PHP_URL_HOST) ?: $this->endpoint);
    }
}
