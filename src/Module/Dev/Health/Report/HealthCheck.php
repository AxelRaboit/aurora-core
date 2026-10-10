<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\Health\Report;

use Aurora\Module\Dev\Health\Enum\HealthStatusEnum;

/**
 * One line of the « État du système » block: what was measured, how it
 * stands, and the figures the line shows.
 *
 * `labelKey` and `messageKey` are translation keys, read by the screen in the
 * reader's language; `parameters` fill the message; `facts` are the raw
 * figures shown beside it (a version, a count, an age in seconds).
 */
final readonly class HealthCheck
{
    /**
     * @param array<string, scalar|null> $parameters
     * @param array<string, mixed>       $facts
     */
    public function __construct(
        public string $key,
        public HealthStatusEnum $status,
        public string $labelKey,
        public string $messageKey,
        public array $parameters = [],
        public array $facts = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'status' => $this->status->value,
            'labelKey' => $this->labelKey,
            'messageKey' => $this->messageKey,
            'parameters' => $this->parameters,
            'facts' => $this->facts,
        ];
    }
}
