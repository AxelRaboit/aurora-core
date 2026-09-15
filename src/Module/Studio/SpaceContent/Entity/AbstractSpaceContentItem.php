<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentApprovalEnum;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing to publish, seen from two sides.
 *
 * **This is the decision the whole feature rests on.** The board and the
 * calendar are not two features, they are two readings of these rows: the
 * column says where a piece of content is in its life, the date says when it
 * goes out. A calendar of "events" beside a board of "tasks" is two places to
 * type the same post, and they come apart in the first busy week.
 *
 * So the two views own nothing. Dragging a card between columns writes
 * `column`; dragging it across the month writes `scheduledAt`; neither knows
 * the other exists.
 *
 * **The date is nullable, and that is the point.** An idea has no date yet, and
 * forcing one at creation would mean either inventing a day or refusing to
 * record the idea. An item with no date lives on the board only, and appears on
 * the calendar the moment somebody schedules it. That is also what makes the
 * board the place work starts.
 *
 * The space is held directly as well as through the column, which is
 * deliberate denormalisation: the board reads every item of a space in one
 * join instead of walking its columns, and an item cannot be orphaned onto
 * another space's column by a bad move.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractSpaceContentItem implements SpaceContentItemInterface
{
    use TimestampableTrait;

    #[ORM\ManyToOne(targetEntity: CustomerSpaceInterface::class, inversedBy: 'contentItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CustomerSpaceInterface $space;

    /**
     * `CASCADE`, and the rule that a column holding content is not deleted
     * lives in the Manager instead.
     *
     * `RESTRICT` would have been the obvious choice and it is a trap here.
     * Deleting a space cascades to its columns and to its items at once, and
     * nothing orders the two: the column rows can be reached first, and the
     * restriction then refuses a deletion the user is entitled to. The
     * constraint that protects content from a careless column deletion has to
     * be stated where it can tell the two cases apart, which is the Manager.
     */
    #[ORM\ManyToOne(targetEntity: SpaceContentColumnInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected SpaceContentColumnInterface $column;

    #[ORM\Column(length: 255)]
    protected string $title;

    /**
     * The copy itself, as plain text.
     *
     * Not a block editor and not HTML: what goes out is a caption a person
     * pastes into somewhere else, and a rich document would carry formatting
     * no network keeps. The day this has to become blocks, the column is text
     * either way.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $body = null;

    /**
     * When it goes out, in the space's timezone.
     *
     * Stored as an instant, read in the zone the space carries. The zone lives
     * on the space and not here for the reason the calendar module settled: a
     * day is only a day in one zone, and a per-item zone makes two readers
     * disagree about which Tuesday something landed on.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $scheduledAt = null;

    /** Order inside its column. Rewritten by a move, never read across columns. */
    #[ORM\Column(options: ['default' => 0])]
    protected int $position = 0;

    /**
     * What the client answered, if they have.
     *
     * An opinion recorded against this text, which is why editing the text
     * clears it - see `clearApprovalIfContentChanged` on the Manager. An
     * approval that survived a rewrite would be the client agreeing to
     * something they never read.
     */
    #[ORM\Column(length: 20, enumType: SpaceContentApprovalEnum::class, options: ['default' => 'pending'])]
    protected SpaceContentApprovalEnum $approval = SpaceContentApprovalEnum::Pending;

    /**
     * What they said about it, when they said anything.
     *
     * One field rather than a thread, and that is the scope: a decision is not
     * a conversation. "À revoir" is only actionable with a reason attached, and
     * the reason arrives in the same gesture as the decision - which also means
     * it cannot be left behind by somebody who answered and closed the tab.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $approvalNote = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $approvalAt = null;

    /**
     * Which address answered.
     *
     * `SET NULL`: deleting a link must not delete the answer it carried. What
     * is lost is who, not what, and the studio deleting an address a month
     * later should not silently unapprove six publications.
     */
    #[ORM\ManyToOne(targetEntity: SpaceAccessLinkInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?SpaceAccessLinkInterface $approvalByLink = null;

    abstract public function getId(): ?int;

    public function getSpace(): CustomerSpaceInterface
    {
        return $this->space;
    }

    public function setSpace(CustomerSpaceInterface $space): static
    {
        $this->space = $space;

        return $this;
    }

    public function getColumn(): SpaceContentColumnInterface
    {
        return $this->column;
    }

    public function setColumn(SpaceContentColumnInterface $column): static
    {
        $this->column = $column;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getScheduledAt(): ?DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?DateTimeImmutable $scheduledAt): static
    {
        $this->scheduledAt = $scheduledAt;

        return $this;
    }

    public function isScheduled(): bool
    {
        return $this->scheduledAt instanceof DateTimeImmutable;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getApproval(): SpaceContentApprovalEnum
    {
        return $this->approval;
    }

    public function getApprovalNote(): ?string
    {
        return $this->approvalNote;
    }

    public function getApprovalAt(): ?DateTimeImmutable
    {
        return $this->approvalAt;
    }

    public function getApprovalByLink(): ?SpaceAccessLinkInterface
    {
        return $this->approvalByLink;
    }

    /**
     * Records what a client answered, with who and when.
     *
     * One method rather than four setters: the four values are one event, and a
     * caller able to set the verdict without the date could leave a row saying
     * "approved by nobody, never".
     */
    public function answer(
        SpaceContentApprovalEnum $approval,
        ?string $note,
        SpaceAccessLinkInterface $link,
        DateTimeImmutable $at,
    ): static {
        $this->approval = $approval;
        $this->approvalNote = $note;
        $this->approvalByLink = $link;
        $this->approvalAt = $at;

        return $this;
    }

    /**
     * Forgets the answer, because the text it was about has changed.
     *
     * Not a rollback anybody asked for: an approval is of a specific wording,
     * so a rewrite makes it evidence of nothing. Losing it is the honest
     * outcome and keeping it would be a quiet lie to the person reading the
     * board.
     */
    public function clearApproval(): static
    {
        $this->approval = SpaceContentApprovalEnum::Pending;
        $this->approvalNote = null;
        $this->approvalByLink = null;
        $this->approvalAt = null;

        return $this;
    }
}
