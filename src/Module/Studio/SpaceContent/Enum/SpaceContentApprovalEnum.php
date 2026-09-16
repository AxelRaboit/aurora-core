<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Enum;

/**
 * What the client said about one piece of content.
 *
 * Three cases, and `Pending` carries the most weight: it means nobody has said
 * anything, which is not the same as "not approved". A column that folded
 * silence into refusal would tell the studio a client had rejected six posts
 * they have not read.
 *
 * **It is an opinion, not a state machine.** Approving does not move the card to
 * the next step, and asking for changes does not send it back. A client
 * clicking the wrong button would otherwise have scheduled or unscheduled a
 * publication, and the person who acts on the answer is the one who reads it.
 * The board shows the answer; a human moves the card.
 *
 * The values are persisted, so they are part of the schema: add and remove,
 * never rename.
 */
enum SpaceContentApprovalEnum: string
{
    case Pending = 'pending';

    case Approved = 'approved';

    case ChangesRequested = 'changes_requested';

    public function getLabelKey(): string
    {
        return match ($this) {
            self::Pending => 'backend.studio.space_content.approvals.pending',
            self::Approved => 'backend.studio.space_content.approvals.approved',
            self::ChangesRequested => 'backend.studio.space_content.approvals.changes_requested',
        };
    }

    /** Whether a client has actually answered, as opposed to not having looked. */
    public function isAnswered(): bool
    {
        return self::Pending !== $this;
    }
}
