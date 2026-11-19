<?php

namespace Bitprogmbh\SweegoLaravel\Bulk;

/**
 * Antwort auf einen Bulk-Versand: eine `transaction_id` pro Anfrage und eine `swg_uid` pro Empfänger.
 * Die Antwort bestätigt nur die Annahme, nicht die Zustellung (eine nicht existierende Adresse erhält ebenfalls eine `swg_uid`).
 */
final readonly class BulkResult
{
    /**
     * Die `swg_uid` je Empfänger, die Adressen sind kleingeschrieben.
     *
     * @var array<string, string>
     */
    public array $swgUids;

    /**
     * @param  array<array-key, mixed>  $swgUids  Adresse zu `swg_uid`, wie von der API geliefert.
     */
    public function __construct(
        public ?string $transactionId = null,
        array $swgUids = [],
    ) {
        $normalized = [];

        foreach ($swgUids as $email => $uid) {
            if (is_string($uid) && $uid !== '') {
                $normalized[strtolower((string) $email)] = $uid;
            }
        }

        $this->swgUids = $normalized;
    }

    /**
     * Die `swg_uid` des Empfängers, ersatzweise die `transaction_id` der Anfrage.
     */
    public function messageIdFor(string $email): ?string
    {
        return $this->swgUids[strtolower($email)] ?? $this->transactionId;
    }
}
