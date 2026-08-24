<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Link\Entity;

use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

use function bin2hex;
use function random_bytes;

/**
 * One address that reaches a calendar without an account.
 *
 * The third way in, and the one the other two could not express.
 * `PlanningVisibilityEnum` is nobody or everybody-with-an-account; `PlanningShare`
 * names people who already have one. Neither says "this photographer, until the
 * end of the month" - which is what somebody means when they share a calendar
 * outside the company.
 *
 * **A row and not a column**, unlike the `feedToken` this replaces. A column can
 * hold one address for one calendar, and it cannot hold when the address stops
 * working, who it was for, or whether it has ever been used. Those are the
 * questions somebody asks a week later when they want to close it again, and a
 * column has no room for the answers.
 *
 * `expiresAt` is nullable, and that is what lets this table hold both kinds of
 * address. Their lifetimes genuinely differ: a phone polling an `.ics` feed for
 * years must not have it expire underneath it, and a link handed to an outsider
 * must not stay open for ever. One nullable column says both; two columns, or two
 * tables, would say it twice.
 */
#[ORM\MappedSuperclass]
abstract class AbstractPlanningShareLink implements PlanningShareLinkInterface
{
    /**
     * 32 bytes, hex. Same generator as `AbstractAccessRequest`, which is the
     * project's existing answer to "an unguessable string in a URL".
     */
    #[ORM\Column(length: 64, unique: true)]
    protected string $token;

    /**
     * What this link is for, in the words of whoever made it.
     *
     * Required, because the question that matters later is not "which of these
     * tokens is `a3f9...`" but "which one did I give to the studio". A list of
     * hashes is a list you cannot revoke with any confidence.
     */
    #[ORM\Column(length: 120, options: ['default' => ''])]
    protected string $label = '';

    /**
     * The calendars this address shows. One or several: a guest usually wants the
     * shoots and the deadlines together, and making them hold two links to see one
     * schedule is the sort of thing that gets solved by forwarding both to a
     * colleague.
     *
     * @var Collection<int, PlanningInterface>
     */
    #[ORM\ManyToMany(targetEntity: PlanningInterface::class)]
    // The join columns are named rather than left to Doctrine, which would derive
    // them from the class names and give `abstract_planning_share_link_id` and
    // `planning_interface_id` - the mapping's vocabulary leaking into the schema.
    #[ORM\JoinTable(name: 'core_planning_share_link_calendars')]
    #[ORM\JoinColumn(name: 'share_link_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'planning_id', onDelete: 'CASCADE')]
    protected Collection $calendars;

    /**
     * Which surface this address serves.
     *
     * A web link and a feed subscription are the same secret pointed at two
     * different renderers, and letting one token serve both would mean a guest's
     * page URL also being a subscribable feed - a wider grant than the person
     * sharing it chose.
     */
    #[ORM\Column(length: 10, enumType: PlanningShareLinkModeEnum::class, options: ['default' => 'web'])]
    protected PlanningShareLinkModeEnum $mode = PlanningShareLinkModeEnum::Web;

    /** Null means it does not expire, which is what a feed subscription needs. */
    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $expiresAt = null;

    /**
     * Revoked rather than deleted.
     *
     * Deleting the row answers "is this link live" and loses "was there ever a
     * link, and when did we close it" - which is the question asked after
     * something leaks. The row is cheap; the history is not recoverable.
     */
    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $revokedAt = null;

    /**
     * When it last served a request.
     *
     * The one thing that makes a list of links maintainable: "this has never been
     * opened" is what tells somebody a link can be closed without asking around
     * first.
     */
    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column]
    protected DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->calendars = new ArrayCollection();
        $this->token = bin2hex(random_bytes(32));
        $this->createdAt = new DateTimeImmutable();
    }

    abstract public function getId(): ?int;

    public function getToken(): string
    {
        return $this->token;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /** @return Collection<int, PlanningInterface> */
    public function getCalendars(): Collection
    {
        return $this->calendars;
    }

    public function addCalendar(PlanningInterface $planning): static
    {
        if (!$this->calendars->contains($planning)) {
            $this->calendars->add($planning);
        }

        return $this;
    }

    public function removeCalendar(PlanningInterface $planning): static
    {
        $this->calendars->removeElement($planning);

        return $this;
    }

    public function getMode(): PlanningShareLinkModeEnum
    {
        return $this->mode;
    }

    public function setMode(PlanningShareLinkModeEnum $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(DateTimeImmutable $at): static
    {
        // The first revocation is the one that counts. Revoking twice is an
        // ordinary double click, and moving the date would rewrite when the link
        // actually stopped working.
        $this->revokedAt ??= $at;

        return $this;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(DateTimeImmutable $at): static
    {
        $this->lastUsedAt = $at;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Whether this address still works, at a given moment.
     *
     * One method, and the only thing the guest route asks. Expiry and revocation
     * are different facts with the same consequence, and a caller that had to
     * check both would eventually check one.
     */
    public function isUsableAt(DateTimeImmutable $now): bool
    {
        if ($this->revokedAt instanceof DateTimeImmutable) {
            return false;
        }

        // Expiry is inclusive of the instant itself: a link good "until 18:00"
        // stops at 18:00 rather than lasting the rest of that second.
        return !$this->expiresAt instanceof DateTimeImmutable || $this->expiresAt > $now;
    }
}
