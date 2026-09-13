<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateCategoryEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\Mapping as ORM;

/**
 * A contract template: the reusable document a contract is built from.
 *
 * The template itself holds no text. Its wording lives in versions, because
 * the wording is the part that must stop changing once a contract has been
 * signed against it, and the template is the part that has to keep a stable
 * identity across those versions. Renaming a template or retiring it is
 * therefore not a change to any contract.
 *
 * Retired rather than deleted, most of the time: `archivedAt` takes a template
 * out of the pickers without removing the history of what was sent. Deleting
 * one is nonetheless safe, and that is a property of the design rather than an
 * oversight - a contract carries its own frozen copy of the text, so it does
 * not read anything back from here.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractContractTemplate implements ContractTemplateInterface
{
    use TimestampableTrait;

    /**
     * How the template is found in the back office.
     *
     * Distinct from a version's title, which is what prints at the top of the
     * document: one is for whoever is looking for the trame, the other is for
     * whoever reads the contract, and they are not always the same words.
     */
    #[ORM\Column(length: 180)]
    protected string $name;

    #[ORM\Column(length: 16, enumType: ContractTemplateKindEnum::class)]
    protected ContractTemplateKindEnum $kind = ContractTemplateKindEnum::Body;

    /**
     * Which trade this template serves, or null while nobody has said.
     *
     * Nullable on purpose: see {@see ContractTemplateCategoryEnum}. An
     * unclassified template is a question, a template defaulted into a trade is
     * a wrong answer.
     */
    #[ORM\Column(length: 32, nullable: true, enumType: ContractTemplateCategoryEnum::class)]
    protected ?ContractTemplateCategoryEnum $category = null;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $archivedAt = null;

    /**
     * The last version number handed out.
     *
     * A counter rather than `MAX(number) + 1`: a discarded draft takes its row
     * away with it, so the maximum goes back down and the next open would
     * reuse a number that already appears in the audit trail under a different
     * document.
     */
    #[ORM\Column(options: ['default' => 0])]
    protected int $versionCounter = 0;

    /**
     * Newest first, so the version a reader wants is the one at the top.
     *
     * On the abstract, with its constructor: that is what the four entities
     * carrying translations do today (Post, Form, Taxonomy, Planning). A
     * client substituting the concrete class has to call
     * `parent::__construct()`, which is the cost of the arrangement and the
     * reason the older convention put collections on the concrete.
     *
     * @var Collection<int, ContractTemplateVersionInterface>
     */
    #[ORM\OneToMany(
        targetEntity: ContractTemplateVersionInterface::class,
        mappedBy: 'template',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['number' => Order::Descending->value])]
    protected Collection $versions;

    public function __construct()
    {
        $this->versions = new ArrayCollection();
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

    public function getKind(): ContractTemplateKindEnum
    {
        return $this->kind;
    }

    public function setKind(ContractTemplateKindEnum $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getCategory(): ?ContractTemplateCategoryEnum
    {
        return $this->category;
    }

    public function setCategory(?ContractTemplateCategoryEnum $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getArchivedAt(): ?DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function archive(DateTimeImmutable $at): static
    {
        // The first archiving is the one that counts, like a revocation:
        // archiving twice is an ordinary double click, and moving the date
        // would rewrite when the template actually left the pickers.
        $this->archivedAt ??= $at;

        return $this;
    }

    public function restore(): static
    {
        $this->archivedAt = null;

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt instanceof DateTimeImmutable;
    }

    public function getVersions(): Collection
    {
        return $this->versions;
    }

    public function addVersion(ContractTemplateVersionInterface $version): static
    {
        if (!$this->versions->contains($version)) {
            $this->versions->add($version);
            $version->setTemplate($this);
        }

        return $this;
    }

    public function removeVersion(ContractTemplateVersionInterface $version): static
    {
        $this->versions->removeElement($version);

        return $this;
    }

    public function getDraft(): ?ContractTemplateVersionInterface
    {
        foreach ($this->versions as $version) {
            if (!$version->isPublished()) {
                return $version;
            }
        }

        return null;
    }

    public function getLatestPublishedVersion(): ?ContractTemplateVersionInterface
    {
        // The collection is ordered newest first, so the first published one
        // encountered is the highest numbered.
        foreach ($this->versions as $version) {
            if ($version->isPublished()) {
                return $version;
            }
        }

        return null;
    }

    public function claimNextVersionNumber(): int
    {
        return ++$this->versionCounter;
    }

    public function getVersionCounter(): int
    {
        return $this->versionCounter;
    }
}
