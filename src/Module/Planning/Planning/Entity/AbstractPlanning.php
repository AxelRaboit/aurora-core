<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Planning\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Planning\Enum\PlanningVisibilityEnum;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminderInterface;
use Aurora\Module\Planning\Share\Entity\PlanningShareInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractPlanning implements PlanningInterface
{
    use TimestampableTrait;

    /** Highest slot the shared categorical palette defines. */
    public const int MAX_COLOUR_SLOT = 8;

    public const int DEFAULT_COLOUR_SLOT = 1;

    #[ORM\Column(length: 150)]
    protected string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $description = null;

    /**
     * Which of the shared palette's colours this calendar wears.
     *
     * A slot, not a hex value, which is what this column held before. Three
     * reasons, and the first is the one that matters: a stored hex cannot follow
     * the theme, so a colour chosen against a white page is whatever it happens
     * to be against a dark one. The palette has a step per mode for exactly
     * that. It is also already checked for separation under colour-vision
     * deficiency, which a free colour picker cannot promise - `#ff00ff` is a
     * legal answer and an unreadable one.
     *
     * Eight slots, assigned in order. A ninth calendar shares a colour with one
     * above it rather than getting a generated hue, because a generated hue is
     * indistinguishable from an existing one under CVD - see
     * `convention_chart_palette`.
     */
    #[ORM\Column(options: ['default' => self::DEFAULT_COLOUR_SLOT])]
    protected int $colourSlot = self::DEFAULT_COLOUR_SLOT;

    /**
     * The zone the calendar's days are cut in.
     *
     * On the calendar and not on each event: "all day" only means something in
     * one zone, and an event that carried its own would make a day-long event
     * start at 01:00 for a reader in another country.
     */
    #[ORM\Column(length: 64, options: ['default' => 'Europe/Paris'])]
    protected string $timezone = 'Europe/Paris';

    #[ORM\Column(length: 20, enumType: PlanningVisibilityEnum::class, options: ['default' => PlanningVisibilityEnum::Private->value])]
    protected PlanningVisibilityEnum $visibility = PlanningVisibilityEnum::Private;

    /**
     * Set when a module owns this calendar rather than a person.
     *
     * One per source ('editorial.post'), unique, so a module announcing its
     * dates always lands them in the same place - and a reader can fold that
     * place away without folding away their own calendars.
     *
     * Null for every calendar somebody made. That is the common case, and it is
     * why this is a nullable column rather than a third visibility: who owns a
     * calendar and who may see it are two questions.
     */
    #[ORM\Column(length: 64, nullable: true)]
    protected ?string $sourceType = null;

    /**
     * The secret in a published feed's URL, or null when no feed is published.
     *
     * A feed is fetched by a phone's calendar app with no session, so the URL is
     * the credential - the same model as Google's "secret address in iCal
     * format". That makes three things non-negotiable: it is opt-in, so no
     * calendar leaks by default; it is long enough that guessing is hopeless; and
     * it can be revoked, because the only way to un-share a URL is to break it.
     *
     * 32 random bytes, base64url, so 43 characters and no escaping in a path.
     */
    #[ORM\Column(length: 64, nullable: true)]
    protected ?string $feedToken = null;

    /**
     * Nullable, and stays nullable: deleting the account that made a calendar
     * must not take a team's shared calendar with it.
     */
    #[ORM\ManyToOne(targetEntity: CoreUserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CoreUserInterface $owner = null;

    /**
     * @var Collection<int, PlanningEventInterface>
     */
    #[ORM\OneToMany(targetEntity: PlanningEventInterface::class, mappedBy: 'planning', cascade: ['remove'], orphanRemoval: true)]
    protected Collection $events;

    /**
     * The calendar's reminders, which cascade with it for the same reason its
     * events do: deleting a calendar deletes what was on it, and a reminder
     * pointing at a calendar that is gone is a row nothing can reach.
     *
     * @var Collection<int, PlanningReminderInterface>
     */
    #[ORM\OneToMany(targetEntity: PlanningReminderInterface::class, mappedBy: 'planning', cascade: ['remove'], orphanRemoval: true)]
    protected Collection $reminders;

    /**
     * The people this calendar is shared with by name.
     *
     * @var Collection<int, PlanningShareInterface>
     */
    #[ORM\OneToMany(targetEntity: PlanningShareInterface::class, mappedBy: 'planning', cascade: ['remove'], orphanRemoval: true)]
    protected Collection $shares;

    public function __construct()
    {
        // Initialised here rather than at the property, per
        // `convention_collection_on_concrete`: an uninitialised collection is
        // null and the first `add()` is a crash.
        $this->events = new ArrayCollection();
        $this->reminders = new ArrayCollection();
        $this->shares = new ArrayCollection();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getColourSlot(): int
    {
        return $this->colourSlot;
    }

    /**
     * Clamped rather than trusted: a slot outside the palette draws nothing at
     * all, and a calendar with no colour is invisible on a month grid.
     */
    public function setColourSlot(int $colourSlot): static
    {
        $this->colourSlot = max(1, min(self::MAX_COLOUR_SLOT, $colourSlot));

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function getSourceType(): ?string
    {
        return $this->sourceType;
    }

    public function setSourceType(?string $sourceType): static
    {
        $this->sourceType = $sourceType;

        return $this;
    }

    /**
     * A calendar a module fills is not one a person edits.
     *
     * Asked by the screen so it can leave out the pencil, and by the manager so
     * a request that arrives without one is still refused - the same split the
     * events already use.
     */
    public function isFromModule(): bool
    {
        return null !== $this->sourceType;
    }

    public function getFeedToken(): ?string
    {
        return $this->feedToken;
    }

    public function hasFeed(): bool
    {
        return null !== $this->feedToken;
    }

    /**
     * Publishes a feed, or replaces the one that was published.
     *
     * Always a fresh secret rather than reusing an existing one: asking to
     * publish again is how somebody revokes an address they have shared too
     * widely, and handing back the same one would answer the wrong question.
     */
    public function publishFeed(): static
    {
        $this->feedToken = mb_rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return $this;
    }

    public function revokeFeed(): static
    {
        $this->feedToken = null;

        return $this;
    }

    public function getVisibility(): PlanningVisibilityEnum
    {
        return $this->visibility;
    }

    public function setVisibility(PlanningVisibilityEnum $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function getOwner(): ?CoreUserInterface
    {
        return $this->owner;
    }

    public function setOwner(?CoreUserInterface $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    /**
     * @return Collection<int, PlanningEventInterface>
     */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    /**
     * @return Collection<int, PlanningReminderInterface>
     */
    public function getReminders(): Collection
    {
        return $this->reminders;
    }

    /**
     * @return Collection<int, PlanningShareInterface>
     */
    public function getShares(): Collection
    {
        return $this->shares;
    }

    public function addShare(PlanningShareInterface $share): static
    {
        if (!$this->shares->contains($share)) {
            $this->shares->add($share);
            $share->setPlanning($this);
        }

        return $this;
    }

    public function removeShare(PlanningShareInterface $share): static
    {
        $this->shares->removeElement($share);

        return $this;
    }

    /**
     * Whether this person may add and change things here.
     *
     * Three ways in, in the order they were added to the product: you own it, it is
     * shared with everybody who can reach the module, or it is shared with you
     * specifically and with writing allowed.
     *
     * Asked on the entity rather than in the controller, because the same question
     * is asked by every write route and one of them would have answered it
     * differently.
     */
    public function isWritableBy(CoreUserInterface $user): bool
    {
        if ($this->owner?->getId() === $user->getId()) {
            return true;
        }

        if (PlanningVisibilityEnum::Shared === $this->visibility) {
            return true;
        }

        foreach ($this->shares as $share) {
            if ($share->getUser()->getId() === $user->getId() && $share->canWrite()) {
                return true;
            }
        }

        return false;
    }
}
