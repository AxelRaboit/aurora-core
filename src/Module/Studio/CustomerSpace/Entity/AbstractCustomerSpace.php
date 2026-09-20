<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Entity;

use Aurora\Core\Support\ChartPalette;
use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolderInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;
use Aurora\Module\Studio\CustomerSpace\Service\SpaceDocumentFolderProvider;
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
    public const int MAX_COLOUR_SLOT = ChartPalette::MAX_SLOT;

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

    /**
     * Where this space's uploads are filed in the library.
     *
     * **Created on the first upload, not with the space.** A space that never
     * receives a file would otherwise leave an empty folder behind, and the
     * library is a screen people read: one empty folder per prospect is
     * litter. {@see SpaceDocumentFolderProvider}
     * resolves it, the same way the filing category is resolved on demand.
     *
     * **Nullable, and `SET NULL` rather than `RESTRICT`.** The folder belongs
     * to the library once it exists: somebody may trash it, and that must not
     * be a deletion the library refuses for a reason it cannot explain. The
     * provider notices the loss and files the next upload into a fresh folder.
     *
     * **Its name is not kept in step with the space's.** Renaming the space
     * leaves the folder alone, because from the moment it exists it is an
     * ordinary folder that the studio may rename, move or nest. Writing over
     * that from another module would be a surprise nobody could trace back
     * here. The link is the foreign key, never the name.
     */
    #[ORM\ManyToOne(targetEntity: DocumentFolderInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?DocumentFolderInterface $documentFolder = null;

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

    /**
     * Le dossier Drive que le client a partagé pour cet espace.
     *
     * **L'identifiant, pas l'adresse.** C'est ce que Google attend, et c'est
     * la fin de l'adresse d'un dossier - ce qui suit `/folders/`. Le stocker
     * entier obligerait à le découper à chaque appel, et à redécouper le jour
     * où Google change la forme de ses adresses.
     *
     * Nul par défaut : un espace n'a pas de Drive tant que personne n'en
     * branche un, et la plupart n'en auront jamais.
     */
    #[ORM\Column(length: 128, nullable: true)]
    protected ?string $driveFolderId = null;

    /**
     * Le mot de passe qui ferme l'onglet Drive, haché.
     *
     * **Haché et non chiffré**, contrairement à la clé du compte de service :
     * une clé doit être relue pour signer, un mot de passe n'a jamais besoin
     * d'être relu, seulement comparé. Personne ne peut donc le retrouver, pas
     * même depuis la base, et c'est la propriété qu'on veut.
     *
     * Nul par défaut : l'onglet est ouvert tant que personne ne le ferme.
     */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $drivePassword = null;

    /**
     * La génération en cours des sessions ouvertes sur le Drive.
     *
     * **Ce qui permet de tout refermer sans changer le mot de passe.** Une
     * session qui a saisi le bon mot de passe retient cette valeur ; elle
     * reste ouverte tant que l'espace montre la même. En tirer une nouvelle
     * referme donc toutes les sessions d'un coup, celle qui appuie comprise,
     * sans que personne ait à changer quoi que ce soit.
     *
     * Elle change aussi quand le mot de passe change ou disparaît : sans
     * cela, celui qui avait ouvert avec l'ancien resterait dedans, et
     * remplacer un mot de passe compromis n'aurait servi à rien.
     */
    #[ORM\Column(length: 32, nullable: true)]
    protected ?string $driveLockGeneration = null;

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
        $this->colourSlot = ChartPalette::clamp($colourSlot);

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getDriveFolderId(): ?string
    {
        return $this->driveFolderId;
    }

    public function getDrivePassword(): ?string
    {
        return $this->drivePassword;
    }

    public function getDriveLockGeneration(): ?string
    {
        return $this->driveLockGeneration;
    }

    public function setDriveLockGeneration(?string $driveLockGeneration): static
    {
        $this->driveLockGeneration = $driveLockGeneration;

        return $this;
    }

    public function setDrivePassword(?string $drivePassword): static
    {
        $this->drivePassword = $drivePassword;

        return $this;
    }

    /** L'onglet Drive est-il fermé par un mot de passe ? */
    public function isDriveLocked(): bool
    {
        return null !== $this->drivePassword && '' !== $this->drivePassword;
    }

    public function setDriveFolderId(?string $driveFolderId): static
    {
        $this->driveFolderId = $driveFolderId;

        return $this;
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

    public function getDocumentFolder(): ?DocumentFolderInterface
    {
        return $this->documentFolder;
    }

    public function setDocumentFolder(?DocumentFolderInterface $documentFolder): static
    {
        $this->documentFolder = $documentFolder;

        return $this;
    }
}
