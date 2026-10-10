<?php

declare(strict_types=1);

namespace Aurora\Core\Contact\Prospect;

use DateTimeImmutable;

/**
 * Somebody who wrote in through the website, as the module that received the
 * message reads them: a name, how to reach them, and what they said.
 *
 * Plain values only: the producer (a form) and the consumer (the customers'
 * pipeline) live in two modules that must not know each other's entities.
 */
final readonly class WebsiteContact
{
    public function __construct(
        public string $name,
        public ?string $email,
        public ?string $phone,
        /** What identifies the message, so one message never makes two prospects. */
        public string $sourceReference,
        /** Where it came from, as the reader will recognise it: the form's title. */
        public string $sourceLabel,
        /** The message itself, one "question: answer" per line. */
        public string $summary,
        public DateTimeImmutable $receivedAt,
    ) {}
}
