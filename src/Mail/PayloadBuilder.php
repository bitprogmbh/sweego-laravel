<?php

namespace Bitprogmbh\SweegoLaravel\Mail;

use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Baut die Felder einer Sweego-Anfrage, die für alle Empfänger einer Mail gleich sind. Gemeinsam genutzt
 * vom Transport (`/send`) und vom Bulk-Client (`/send/bulk/email`). Die Empfänger setzt der Aufrufer.
 */
class PayloadBuilder
{
    public const MAX_HEADERS = 5;

    /**
     * Headers that are sent as dedicated payload fields or derived from the envelope.
     *
     * @var list<string>
     */
    protected const EXCLUDED_HEADERS = ['to', 'cc', 'bcc', 'from', 'sender', 'subject', 'reply-to'];

    /**
     * @return array<string, mixed>
     */
    public function build(Email $email, Address $sender, string $campaignType = 'transac'): array
    {
        $payload = [
            'from' => $this->formatAddress($sender),
            'subject' => $email->getSubject(),
            'campaign-type' => $campaignType,
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
    public function formatAddress(Address $address): array
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
}
