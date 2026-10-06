<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentApprovalEnum;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentColumnRoleEnum;
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

    /**
     * Whether the date also shows in the calendar.
     *
     * **A date and a publication are not the same thing.** A card can carry
     * a deadline that concerns the studio and nobody else: a reminder to
     * prepare, a shoot to schedule, a delivery to check. Dated, they all
     * crowded into the month and drowned what the calendar exists to show,
     * what goes out and when.
     *
     * True by default, because that is the common case and the opposite
     * would mean ticking every card to get the previous behaviour back.
     * Unticked, the card keeps its date, shows it on the board, and no longer
     * appears in the month - neither for the studio nor for the client.
     */
    #[ORM\Column(options: ['default' => true])]
    protected bool $showOnCalendar = true;

    /**
     * The date by which the client must have answered.
     *
     * **It is not the publication date.** A post planned for the 30th is not
     * approved on the 30th: there has to be time to produce, edit, sometimes
     * redo. `scheduledAt` says when it goes out, this one says when the
     * decision must have been made, and mixing the two means finding out the
     * day before that an answer was missing.
     *
     * Nullable, and that is the common case: most cards have no review
     * deadline, and a mandatory field would force inventing a date every
     * time. A date gone by is information, never a sanction: nothing blocks
     * or unschedules itself, for the same reason that makes approval an
     * opinion and not an automaton.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $reviewBy = null;

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
     *
     * **The words that came with it are not here.** They are messages on the
     * thread, which is what lets this be reset without destroying them: a
     * verdict is a state, and "le ton est trop formel" is an event the studio
     * is about to act on. Keeping both in one column meant the instruction
     * disappeared exactly when it was being used.
     */
    #[ORM\Column(length: 20, enumType: SpaceContentApprovalEnum::class, options: ['default' => 'pending'])]
    protected SpaceContentApprovalEnum $approval = SpaceContentApprovalEnum::Pending;

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

    /**
     * When the content was put in the trash; null, it is alive.
     *
     * In the trash, it leaves the board, the list, the calendar, the counts
     * and the client page, and its address answers like an unknown card. It
     * keeps its stage, its thread and its files: restoring puts it back in
     * its stage, or in the first one if its own has gone in the meantime. The
     * scheduled purge destroys it after the common delay.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $deletedAt = null;

    abstract public function getId(): ?int;

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    public function isTrashed(): bool
    {
        return $this->deletedAt instanceof DateTimeImmutable;
    }

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

    public function getReviewBy(): ?DateTimeImmutable
    {
        return $this->reviewBy;
    }

    public function setReviewBy(?DateTimeImmutable $reviewBy): static
    {
        $this->reviewBy = $reviewBy;

        return $this;
    }

    /**
     * The review deadline has passed and the client has not answered, on a
     * card they have in front of them.
     *
     * The rule of `SpaceWorkload`, written once for the card: an approved
     * card is late for nothing, a card without a deadline has nothing to go
     * past, and a card the client does not see - internal stage, off the
     * calendar, already published - cannot be waiting for them. The card's
     * badge said "late" where the counters counted nothing.
     */
    public function isLateForReview(DateTimeImmutable $now): bool
    {
        return $this->reviewBy instanceof DateTimeImmutable
            && $this->reviewBy < $now
            && !$this->approval->isAnswered()
            && $this->isShownToClient()
            && $this->isAtClientStep()
            && SpaceContentColumnRoleEnum::Published !== $this->getColumn()->getRole();
    }

    /**
     * Whether this card sits where the client answers.
     *
     * The step with the Review role when the board has one; any step the
     * client can see when it has none, which is how every board read before
     * the role decided anything. The counts of the dashboard and the editorial
     * calendar apply the same rule in SQL ({@see SpaceContentItemRepository}).
     */
    public function isAtClientStep(): bool
    {
        $column = $this->getColumn();

        if (!$column->isVisibleToClient()) {
            return false;
        }

        foreach ($this->getSpace()->getContentColumns() as $step) {
            if (SpaceContentColumnRoleEnum::Review === $step->getRole()) {
                return SpaceContentColumnRoleEnum::Review === $column->getRole();
            }
        }

        return true;
    }

    public function isScheduled(): bool
    {
        return $this->scheduledAt instanceof DateTimeImmutable;
    }

    public function isShownOnCalendar(): bool
    {
        return $this->showOnCalendar;
    }

    public function setShowOnCalendar(bool $showOnCalendar): static
    {
        $this->showOnCalendar = $showOnCalendar;

        return $this;
    }

    /**
     * What the calendar takes, and only the calendar.
     *
     * Both conditions together, because mixing them up is the mistake one
     * would make: a card without a date has no business there, and neither
     * does a dated card that was unticked.
     */
    public function appearsOnCalendar(): bool
    {
        return $this->showOnCalendar && $this->isScheduled();
    }

    /**
     * What the client page shows: a card of their calendar, in a stage open
     * to them.
     *
     * **A single rule for what the page carries and for what it accepts.**
     * The page draws only its calendar; sending it the other cards put their
     * titles and texts in its source, and letting them receive an opinion or
     * a comment opened actions on work the studio had not shown.
     */
    public function isShownToClient(): bool
    {
        // A content in the trash, or from a space in the trash, is no longer
        // on the client page: neither shown nor open to an answer.
        return !$this->isTrashed() && !$this->space->isTrashed() && $this->appearsOnCalendar() && $this->getColumn()->isVisibleToClient();
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
        SpaceAccessLinkInterface $link,
        DateTimeImmutable $at,
    ): static {
        $this->approval = $approval;
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
     *
     * The thread is untouched. What the client wrote stays written - it is the
     * reason the studio is rewriting in the first place.
     */
    public function clearApproval(): static
    {
        $this->approval = SpaceContentApprovalEnum::Pending;
        $this->approvalByLink = null;
        $this->approvalAt = null;

        return $this;
    }
}
