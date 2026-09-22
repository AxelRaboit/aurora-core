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

    /**
     * Si la date se lit aussi dans le calendrier.
     *
     * **Une date et une parution ne sont pas la même chose.** Une carte peut
     * porter une échéance qui regarde le studio et personne d'autre : une
     * relance à préparer, un tournage à caler, un envoi à vérifier. Datées,
     * elles s'invitaient toutes dans le mois et noyaient ce que le calendrier
     * existe pour montrer, ce qui sort et quand.
     *
     * Vrai par défaut, parce que c'est le cas courant et que l'inverse
     * obligerait à cocher chaque carte pour retrouver le comportement d'avant.
     * Décochée, la carte garde sa date, la montre sur le tableau, et ne
     * s'affiche plus dans le mois - ni au studio, ni chez le client.
     */
    #[ORM\Column(options: ['default' => true])]
    protected bool $showOnCalendar = true;

    /**
     * La date avant laquelle le client doit avoir répondu.
     *
     * **Ce n'est pas la date de parution.** Une publication prévue le 30 ne se
     * valide pas le 30 : il faut le temps de produire, de monter, parfois de
     * reprendre. `scheduledAt` dit quand ça sort, celle-ci dit quand il faut
     * avoir tranché, et confondre les deux fait découvrir la veille qu'il
     * manquait une réponse.
     *
     * Nullable, et c'est le cas courant : la plupart des cartes n'ont pas
     * d'échéance de relecture, et un champ obligatoire forcerait à inventer une
     * date à chaque fois. Une date dépassée est une information, jamais une
     * sanction : rien ne se bloque ni ne se déprogramme tout seul, pour la même
     * raison qui fait de l'approbation un avis et pas un automate.
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
     * L'échéance de relecture est passée et personne n'a répondu.
     *
     * Les deux conditions ensemble : une carte déjà validée n'est en retard de
     * rien, et une carte sans échéance n'a rien à dépasser.
     */
    public function isLateForReview(DateTimeImmutable $now): bool
    {
        return $this->reviewBy instanceof DateTimeImmutable
            && $this->reviewBy < $now
            && !$this->approval->isAnswered();
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
     * Ce que le calendrier prend, et lui seul.
     *
     * Les deux conditions ensemble, parce que les confondre est l'erreur
     * qu'on ferait : une carte sans date n'a rien à y faire, et une carte
     * datée qu'on a décochée non plus.
     */
    public function appearsOnCalendar(): bool
    {
        return $this->showOnCalendar && $this->isScheduled();
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
