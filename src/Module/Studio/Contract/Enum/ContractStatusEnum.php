<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Enum;

/**
 * Where a contract is in its life.
 *
 * The order below is the order it travels, and the two ends are not symmetric.
 * Everything up to `Sent` is reversible: a draft can be rewritten, a link can
 * be revoked. From `SignedByCustomer` onwards nothing is, because somebody has
 * committed.
 *
 * `Countersigned` is the terminal state that matters: the customer signs first
 * and the provider countersigns, so it is the countersignature that concludes
 * the contract - and that is what makes it the moment the PDF is generated.
 */
enum ContractStatusEnum: string
{
    /** Being prepared. The wording is still whatever the templates say today. */
    case Draft = 'draft';

    /**
     * Sealed. The document is final and has not gone out yet.
     *
     * Its own state rather than folded into `Sent`, because sealing and
     * sending are two acts a day apart: one makes the document final, the
     * other reaches somebody outside. Saying "sent" before the mail left
     * would be a claim nobody could check.
     */
    case Sealed = 'sealed';

    /** Mailed. Somebody outside now holds an address that opens it. */
    case Sent = 'sent';

    /** The link was opened at least once. */
    case Opened = 'opened';

    /** The customer signed. Awaiting the countersignature. */
    case SignedByCustomer = 'signed_by_customer';

    /** Concluded. */
    case Countersigned = 'countersigned';

    /** The customer declined to sign. */
    case Refused = 'refused';

    /** Nobody signed in time. */
    case Expired = 'expired';

    /** Withdrawn before signature. */
    case Revoked = 'revoked';
    /**
     * Sealed by mistake and withdrawn before anybody signed.
     *
     * Kept rather than deleted: the reference was drawn and may have been
     * printed or mentioned, and a numbering with a hole in it is harder to
     * explain than a line that says « annulé ».
     */
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return 'backend.studio.contracts.status.'.$this->value;
    }

    /**
     * Whether the wording can still be written.
     *
     * Only a draft. Everything else has been sent to somebody, and what was
     * sent is what has to stay readable.
     */
    public function isEditable(): bool
    {
        return self::Draft === $this;
    }

    /** Whether the document is final, whether or not it has gone out. */
    public function isSealed(): bool
    {
        return self::Draft !== $this;
    }

    /** Whether somebody has committed and the contract can no longer be withdrawn. */
    public function isEngaged(): bool
    {
        return in_array($this, [self::SignedByCustomer, self::Countersigned], true);
    }

    public function isConcluded(): bool
    {
        return self::Countersigned === $this;
    }

    /** Out with the customer, and no answer yet. */
    public function isWaitingForCustomer(): bool
    {
        return in_array($this, [self::Sent, self::Opened], true);
    }

    /**
     * Whether a link can be handed out now: a first send, or a new one after a
     * refusal, an expiry or a revocation. Resending while a link is still out
     * is a reminder, and nothing is sent once somebody has signed.
     */
    public function canBeSent(): bool
    {
        return in_array($this, [self::Sealed, self::Refused, self::Expired, self::Revoked], true);
    }

    /**
     * Whether it can be cancelled: sealed, and nobody has signed or holds an
     * address that opens it.
     */
    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Sealed, self::Refused, self::Expired, self::Revoked], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
