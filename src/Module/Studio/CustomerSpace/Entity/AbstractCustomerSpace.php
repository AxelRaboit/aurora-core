<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumnInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where one customer's work is kept, and later, shown to them.
 *
 * A space is not a second customer record. The company's legal identity lives
 * on {@see CustomerInterface} and is retyped nowhere: this row holds what the
 * work needs and nothing the accountant needs.
 *
 * **Several spaces may name the same customer**, which is why the relation is a
 * plain `ManyToOne` and not a `OneToOne`. A client with two brands runs two
 * editorial calendars, and a client who signs for a website and then for social
 * media has two pieces of work with different rhythms and, eventually, different
 * people looking at them. Collapsing them onto the company would mean one
 * calendar with two unrelated halves, which is the shape a spreadsheet takes
 * when it is about to be split anyway.
 *
 * The relation is required. A space with no customer would be a project folder,
 * and Studio is not where project folders go - that boundary is the whole
 * reason the module's docblock exists.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractCustomerSpace implements CustomerSpaceInterface
{
    use TimestampableTrait;

    /** Highest slot the shared categorical palette defines. Mirrors `AbstractPlanning`. */
    public const int MAX_COLOUR_SLOT = 8;

    public const int DEFAULT_COLOUR_SLOT = 1;

    /**
     * What the space is called, which is rarely the company's legal name.
     *
     * "Boulangerie Martin - Instagram" tells a reader which of two spaces they
     * are in; "MARTIN SAS" does not, and is already printed on every contract.
     */
    #[ORM\Column(length: 150)]
    protected string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $description = null;

    /**
     * `RESTRICT`, not `CASCADE`: deleting a company must not silently take a
     * year of published content with it. The manager refuses the deletion in
     * words instead, the way `CustomerManager` already refuses a customer a
     * contract names.
     */
    #[ORM\ManyToOne(targetEntity: CustomerInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    protected CustomerInterface $customer;

    #[ORM\Column(length: 20, enumType: CustomerSpaceStatusEnum::class, options: ['default' => 'active'])]
    protected CustomerSpaceStatusEnum $status = CustomerSpaceStatusEnum::Active;

    /**
     * Which of the shared palette's colours this space wears.
     *
     * A slot rather than a hex value, for the reasons `AbstractPlanning` gives
     * at length: a stored colour cannot follow the theme, and the palette is
     * already checked for separation under colour-vision deficiency. Eight
     * slots; a ninth space shares one rather than getting a generated hue.
     *
     * It exists here and not only on a screen because the space's dates will be
     * drawn on the shared calendar next to everybody else's, and a reader needs
     * to tell one client's week from another's.
     */
    #[ORM\Column(options: ['default' => self::DEFAULT_COLOUR_SLOT])]
    protected int $colourSlot = self::DEFAULT_COLOUR_SLOT;

    /**
     * The zone this space's days are cut in.
     *
     * On the space and not on each item, which is the rule `AbstractPlanning`
     * settled: "published on Tuesday" only means something in one zone, and an
     * item carrying its own would land on two different days for two readers.
     * A client abroad is the case this is for.
     */
    #[ORM\Column(length: 64, options: ['default' => 'Europe/Paris'])]
    protected string $timezone = 'Europe/Paris';

    /** @var Collection<int, CustomerSpaceMemberInterface> */
    #[ORM\OneToMany(targetEntity: CustomerSpaceMemberInterface::class, mappedBy: 'space', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected Collection $members;

    /**
     * The board's columns.
     *
     * Declared so the relation has an inverse side to be named from, and left
     * without a cascade on purpose: the columns are created and removed through
     * their own Manager, and the database takes them when the space goes.
     *
     * @var Collection<int, SpaceContentColumnInterface>
     */
    #[ORM\OneToMany(targetEntity: SpaceContentColumnInterface::class, mappedBy: 'space')]
    #[ORM\OrderBy(['position' => 'ASC'])]
    protected Collection $contentColumns;

    /** @var Collection<int, SpaceContentItemInterface> */
    #[ORM\OneToMany(targetEntity: SpaceContentItemInterface::class, mappedBy: 'space')]
    protected Collection $contentItems;

    public function __construct()
    {
        $this->members = new ArrayCollection();
        $this->contentColumns = new ArrayCollection();
        $this->contentItems = new ArrayCollection();
    }

    abstract public function getId(): ?int;

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

    public function getCustomer(): CustomerInterface
    {
        return $this->customer;
    }

    public function setCustomer(CustomerInterface $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    public function getStatus(): CustomerSpaceStatusEnum
    {
        return $this->status;
    }

    public function setStatus(CustomerSpaceStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isArchived(): bool
    {
        return CustomerSpaceStatusEnum::Archived === $this->status;
    }

    public function getColourSlot(): int
    {
        return $this->colourSlot;
    }

    /** Clamped rather than rejected: an out-of-range slot is a bug in a caller, not input a person typed. */
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

    /** @return Collection<int, CustomerSpaceMemberInterface> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(CustomerSpaceMemberInterface $member): static
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
            $member->setSpace($this);
        }

        return $this;
    }

    public function removeMember(CustomerSpaceMemberInterface $member): static
    {
        $this->members->removeElement($member);

        return $this;
    }

    /** @return Collection<int, SpaceContentColumnInterface> */
    public function getContentColumns(): Collection
    {
        return $this->contentColumns;
    }

    /** @return Collection<int, SpaceContentItemInterface> */
    public function getContentItems(): Collection
    {
        return $this->contentItems;
    }
}
