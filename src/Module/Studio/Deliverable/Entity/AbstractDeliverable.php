<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableFormatEnum;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Module\Studio\Deliverable\Service\DeliverableAppearance;
use Aurora\Module\Studio\Deliverable\Slides\Entity\SlideInterface;
use Aurora\Module\Studio\Deliverable\Slides\Enum\DeckThemeEnum;
use Aurora\Module\Studio\Deliverable\Slides\Service\DeckStyleNormalizer;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A document written for a client: an audit, a strategy, a report.
 *
 * **A page, not a publication.** It is composed with the same zone grid as
 * the site's pages, but it has neither their address, nor SEO, nor the
 * draft, review, publish cycle: it is never on the site. It was a publication
 * attached to a space until 0.9.321; the editorial module then carried a
 * space id, exceptions in its lists, its sitemap and its feed, for a document
 * that had no business there. Here it lives next to the space, and nothing in
 * the editorial module knows it exists.
 *
 * **One language, the client's.** A site page is translated because its
 * readers speak several languages; a deliverable has one reader. The grid
 * therefore keeps its layout and its content side by side, without
 * translations.
 *
 * **What the client sees is decided by a checkbox**, `visibleToClient`, off
 * by default: like a space resource, a deliverable in progress does not show
 * up for the client before you decide so. It is not a status: nothing is
 * scheduled, reviewed or archived.
 *
 * **Its appearance is its own**: the colours of the background, the header,
 * the footer, the accent, the headings and the key figures, over the theme's.
 * A deliverable often carries the client's colours rather than the studio's.
 *
 * **With or without a space.** Attached to a client space, it lives in its
 * tab and the space's team reads it. Without a space (since 1.7.0), it lives
 * in Studio's Deliverables module: a proposal written before a client exists,
 * a strategy for yourself, a template the team reuses. It then has an author
 * and a scope, personal or shared, see {@see DeliverableScopeEnum}. The
 * "visible par le client" box only makes sense in a space.
 *
 * **What only makes sense without a space**: the "modèle" box, which offers
 * it when creating another one, and the client it was written for before a
 * space existed. Both stay silent in a space: the entity refuses them, and a
 * copy dropped at a client's does not take them along.
 *
 * **A page or slides**, see {@see DeliverableFormatEnum}. A slideshow has no
 * grid: it has its slides, ordered, and their own appearance (`slideTheme`,
 * `slideStyle`), separate from the page's, because a slide is drawn with the
 * presentation theme and not with the site colours. A page has neither, and
 * these two columns stay empty for it.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractDeliverable implements DeliverableInterface
{
    use TimestampableTrait;

    /** The sentence under the title, in the client's list and at the top of the page. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $summary = null;

    /** The grid layout: `{enabled, snap, reveal, zones}`, like a site page. */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    protected array $gridLayout = [];

    /** The content of its zones: `{zones: {id: …}}`. */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    protected array $gridContent = [];

    /**
     * The page's colours and presentation choices, see
     * {@see DeliverableAppearance}.
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    protected array $appearance = [];

    /** What the page header says: for whom, the date, the logo. */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    protected array $readingHeader = [];

    #[ORM\Column(options: ['default' => false])]
    protected bool $visibleToClient = false;

    /**
     * Who created it. Null when the account was deleted: a shared deliverable
     * stays with the team, a personal one falls to the administrators.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CoreUserInterface $owner = null;

    /**
     * Its category, for a Studio deliverable; a space deliverable is filed by
     * its space and has none. Deleting the category deletes nothing: the
     * deliverable goes back to "sans catégorie".
     */
    #[ORM\ManyToOne(targetEntity: DeliverableCategoryInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?DeliverableCategoryInterface $category = null;

    /**
     * Its image, taken from the media library: the thumbnail that sets it
     * apart from the others in a list. Deleting the document removes it,
     * nothing more.
     */
    #[ORM\ManyToOne(targetEntity: DocumentInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?DocumentInterface $thumbnail = null;

    /** Personal or shared, for a deliverable without a space; ignored in a space. */
    #[ORM\Column(length: 16, enumType: DeliverableScopeEnum::class, options: ['default' => 'shared'])]
    protected DeliverableScopeEnum $scope = DeliverableScopeEnum::Shared;

    /**
     * A template: a Studio deliverable that exists to be copied, offered when
     * creating one. A flag and not a table, because a template *is* a
     * deliverable: it is composed, previewed and sent like the others. Never
     * in a space: what is dropped there is written for a client, not to be
     * reused.
     */
    #[ORM\Column(options: ['default' => false])]
    protected bool $template = false;

    /**
     * The client it was written for, when it has no space yet: the proposal
     * made to a prospect. In a space, the space states its client, and this
     * field stays empty.
     *
     * `SET NULL` and not a cascade: deleting a client's record does not
     * delete what was written to them.
     */
    #[ORM\ManyToOne(targetEntity: CustomerInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CustomerInterface $customer = null;

    /**
     * A slideshow's slides, in order; a page has none. They have no life
     * outside the deliverable: deleted, it takes them along.
     *
     * @var Collection<int, SlideInterface>
     */
    #[ORM\OneToMany(targetEntity: SlideInterface::class, mappedBy: 'deliverable', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    protected Collection $slides;

    /**
     * The slides' theme; null for a page, and read as "ardoise" (the default
     * presentation theme) until one is chosen.
     */
    #[ORM\Column(length: 20, nullable: true, enumType: DeckThemeEnum::class)]
    protected ?DeckThemeEnum $slideTheme = null;

    /**
     * What the slides adjust in their theme, screened by
     * {@see DeckStyleNormalizer} on write.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    protected array $slideStyle = [];

    public function __construct(
        /** The client space that receives it; null for a Studio deliverable. */
        #[ORM\ManyToOne(targetEntity: CustomerSpaceInterface::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        protected ?CustomerSpaceInterface $space,
        #[ORM\Column(length: 255)]
        protected string $title,
        /** The language it is written in: that of the page's dates and labels. */
        #[ORM\Column(length: 8)]
        protected string $locale,
        /** A page or slides: set here, without a setter, see {@see DeliverableFormatEnum}. */
        #[ORM\Column(length: 16, enumType: DeliverableFormatEnum::class, options: ['default' => 'page'])]
        protected DeliverableFormatEnum $format = DeliverableFormatEnum::Page,
    ) {
        $this->slides = new ArrayCollection();
    }

    /** @return Collection<int, SlideInterface> */
    public function getSlides(): Collection
    {
        return $this->slides;
    }

    public function addSlide(SlideInterface $slide): static
    {
        if (!$this->slides->contains($slide)) {
            $this->slides->add($slide);
            $slide->setDeliverable($this);
        }

        return $this;
    }

    public function removeSlide(SlideInterface $slide): static
    {
        $this->slides->removeElement($slide);

        return $this;
    }

    public function getSlideTheme(): DeckThemeEnum
    {
        return $this->slideTheme ?? DeckThemeEnum::Slate;
    }

    public function setSlideTheme(DeckThemeEnum $theme): static
    {
        $this->slideTheme = $theme;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getSlideStyle(): array
    {
        return $this->slideStyle;
    }

    /** @param array<string, mixed> $style */
    public function setSlideStyle(array $style): static
    {
        $this->slideStyle = $style;

        return $this;
    }

    public function isSlides(): bool
    {
        return DeliverableFormatEnum::Slides === $this->format;
    }

    public function getFormat(): DeliverableFormatEnum
    {
        return $this->format;
    }

    public function isTemplate(): bool
    {
        return $this->template;
    }

    /** No effect in a space: a space deliverable is never a template. */
    public function setTemplate(bool $template): static
    {
        $this->template = $template && $this->isStandalone();

        return $this;
    }

    public function getCustomer(): ?CustomerInterface
    {
        return $this->customer;
    }

    /** No effect in a space: the space states its client. */
    public function setCustomer(?CustomerInterface $customer): static
    {
        $this->customer = $this->isStandalone() ? $customer : null;

        return $this;
    }

    public function getSpace(): ?CustomerSpaceInterface
    {
        return $this->space;
    }

    public function isStandalone(): bool
    {
        return !$this->space instanceof CustomerSpaceInterface;
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

    public function getThumbnail(): ?DocumentInterface
    {
        return $this->thumbnail;
    }

    public function setThumbnail(?DocumentInterface $thumbnail): static
    {
        $this->thumbnail = $thumbnail;

        return $this;
    }

    public function getCategory(): ?DeliverableCategoryInterface
    {
        return $this->category;
    }

    public function setCategory(?DeliverableCategoryInterface $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getScope(): DeliverableScopeEnum
    {
        return $this->scope;
    }

    public function setScope(DeliverableScopeEnum $scope): static
    {
        $this->scope = $scope;

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

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getGridLayout(): array
    {
        return $this->gridLayout;
    }

    public function setGridLayout(array $gridLayout): static
    {
        $this->gridLayout = $gridLayout;

        return $this;
    }

    public function getGridContent(): array
    {
        return $this->gridContent;
    }

    public function setGridContent(array $gridContent): static
    {
        $this->gridContent = $gridContent;

        return $this;
    }

    public function getAppearance(): array
    {
        return $this->appearance;
    }

    public function setAppearance(array $appearance): static
    {
        $this->appearance = $appearance;

        return $this;
    }

    public function getReadingHeader(): array
    {
        return $this->readingHeader;
    }

    public function setReadingHeader(array $readingHeader): static
    {
        $this->readingHeader = $readingHeader;

        return $this;
    }

    public function isVisibleToClient(): bool
    {
        return $this->visibleToClient;
    }

    public function setVisibleToClient(bool $visibleToClient): static
    {
        $this->visibleToClient = $visibleToClient;

        return $this;
    }

    /**
     * When it was moved to the trash; null, it is live.
     *
     * A soft delete, like the one for notes and publications: a deliverable
     * is a client document written by hand, and permanently deleting an audit
     * by mistake cannot be undone. Once trashed, it leaves the lists, the
     * search and the counts, and its reading links stop answering; it still
     * keeps its images (the media library still counts it) and its links,
     * which resume on restore. The scheduled purge destroys it after the
     * common delay.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $deletedAt = null;

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

    /**
     * Marks the deliverable as modified now.
     *
     * The Doctrine callback only dates a change if a column changed; a save
     * that only touches a reading link, or rewrites the same grid, must still
     * say "mis à jour aujourd'hui" to the client who comes back to look.
     */
    public function touch(): static
    {
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }
}
