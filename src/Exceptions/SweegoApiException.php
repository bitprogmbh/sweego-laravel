<?php

namespace Bitprogmbh\SweegoLaravel\Exceptions;

use Illuminate\Http\Client\Response;
use Symfony\Component\Mailer\Exception\TransportException;
use Throwable;

/**
 * Die Sweego-API hat die Anfrage mit einem Fehlerstatus beantwortet.
 */
class SweegoApiException extends TransportException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly string $body,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        return new self(
            sprintf('Unable to send an email via Sweego: %s (code %d).', $response->body(), $response->status()),
            $response->status(),
            $response->body(),
        );
    }

    /**
     * Die Nachricht samt Anhängen ist zu gross (Sweego erlaubt 20 MB). Ein Wiederholen ändert daran nichts.
     */
    public function isTooLarge(): bool
    {
        return $this->statusCode === 413 || stripos($this->body, 'over max size') !== false;
    }

    /**
     * Die Anfrage wurde wegen ihres Inhalts abgelehnt (400 oder 422), zum Beispiel wegen einer ungültigen Adresse.
     * Ein Wiederholen mit denselben Daten schlägt wieder fehl. Eine zu grosse Nachricht zählt nicht dazu.
     */
    public function isValidationError(): bool
    {
        return in_array($this->statusCode, [400, 422], true) && ! $this->isTooLarge();
    }
}
