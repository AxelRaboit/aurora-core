<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class NoteShareInput
{
    #[Assert\NotNull]
    public ?int $noteId = null;

    public bool $includeDescendants = false;

    /** Followed transitively; see `SharedNoteScope` for why that is the risky one. */
    public bool $includeLinked = false;

    /**
     * Null for a plain copy-the-link share.
     *
     * Validated rather than trusted: this address is what an outgoing mail is
     * sent to, and a malformed one fails at the mailer, long after the user has
     * been told the share worked.
     */
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $recipientEmail = null;

    #[Assert\Length(max: 120)]
    public string $label = '';

    /** ISO-8601 date, or null for a link that lasts until it is revoked. */
    public ?string $expiresAt = null;
}
