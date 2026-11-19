<?php

namespace Bitprogmbh\SweegoLaravel\Bulk;

/**
 * Ein Empfänger eines Bulk-Versands, optional mit eigenen Variablen für die Platzhalter `{{name}}` im Inhalt.
 */
final readonly class BulkRecipient
{
    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function __construct(
        public string $email,
        public ?string $name = null,
        public array $variables = [],
    ) {}

    /**
     * @return array{email: string, name?: string, variables?: array<string, scalar|null>}
     */
    public function toPayload(): array
    {
        $payload = ['email' => $this->email];

        if ($this->name !== null && $this->name !== '') {
            $payload['name'] = $this->name;
        }

        if ($this->variables !== []) {
            $payload['variables'] = $this->variables;
        }

        return $payload;
    }
}
