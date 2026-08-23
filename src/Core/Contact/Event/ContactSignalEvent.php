<?php

declare(strict_types=1);

namespace Aurora\Core\Contact\Event;

/**
 * Cross-module signal that a person's contact details were captured
 * (an ecommerce order, an editorial form submission, ...). A CRM module,
 * if installed, may listen and create/enrich a contact; if no CRM is
 * present the event has no listener and is a harmless no-op.
 *
 * Lives in core precisely so producers (Ecommerce, Editorial, ...) and the
 * consumer (Crm) never depend on one another - they only know this event.
 * `sourceKey` is a free string (e.g. 'order', 'form') the consumer may map
 * to its own taxonomy; `tagSlugs` are optional tag hints.
 */
class ContactSignalEvent
{
    /** @param list<string> $tagSlugs */
    public function __construct(
        private readonly string $email,
        private readonly string $fullName = '',
        private readonly ?string $phone = null,
        private readonly string $sourceKey = '',
        private readonly array $tagSlugs = [],
    ) {}

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getSourceKey(): string
    {
        return $this->sourceKey;
    }

    /** @return list<string> */
    public function getTagSlugs(): array
    {
        return $this->tagSlugs;
    }
}
