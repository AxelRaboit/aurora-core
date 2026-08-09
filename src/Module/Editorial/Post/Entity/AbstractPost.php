<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Enum\ThumbnailFitEnum;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\AbstractTaxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractPost implements PostInterface
{
    use TimestampableTrait;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    protected ?string $reference = null;

    /**
     * Optimistic locking. Two editors on the same post do not silently
     * overwrite each other: the second save fails and the UI can offer
     * the conflict rather than the loser's text disappearing.
     */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    protected int $version = 1;

    #[ORM\Column(length: 50, enumType: PostStatusEnum::class)]
    protected PostStatusEnum $status = PostStatusEnum::Draft;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $scheduledAt = null;

    /** Soft delete: a trashed post leaves the listings but is recoverable. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $deletedAt = null;

    #[ORM\Column(options: ['default' => true])]
    protected bool $commentsEnabled = true;

    /**
     * Whether the published page prints its own title and summary.
     *
     * Shared rather than per translation, by the same argument as a span: this
     * is a decision about the page's design, and a design is written once for
     * every language. A page with a heading in French and none in German would
     * be two different pages.
     *
     * Turning it off never leaves the document without an `<h1>` — the
     * template keeps one for readers who cannot see the layout. What it hides
     * is the visible pair.
     */
    #[ORM\Column(options: ['default' => true])]
    protected bool $titleVisible = true;

    /**
     * The banner's design: arrangement, widths, colours, pictures. Shape and
     * defaults belong to {@see BannerNormalizer}, which every write goes
     * through; an empty array here means "never configured" and reads as a
     * disabled banner.
     *
     * On the post rather than on each translation, so a banner is designed
     * once and every language inherits it. The words live on the translation
     * — see {@see AbstractPostTranslation::$banner} — joined back to these
     * items by id.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    protected array $bannerLayout = [];

    /**
     * The content grid's arrangement: which zones exist, in what order, how
     * wide, and of what kind. Shape and defaults belong to
     * {@see GridNormalizer}; an empty array means "never configured".
     *
     * On the post for the same reason as the banner's layout — see
     * {@see AbstractPost::$bannerLayout}. What each zone *holds* lives on the
     * translation and joins back by zone id.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    protected array $gridLayout = [];

    #[ORM\ManyToOne(targetEntity: PostTypeInterface::class, inversedBy: 'posts')]
    #[ORM\JoinColumn(nullable: false)]
    protected PostTypeInterface $postType;

    /**
     * The picture that stands for this publication wherever it is listed: an
     * archive card, a grid zone linking to it, the image a link preview shows.
     *
     * It used to be called the featured image and used to render at the top of
     * the page as well. The custom header does that job now, and doing both
     * printed two hero images one above the other. What is left is what the
     * name says.
     */
    #[ORM\ManyToOne(targetEntity: DocumentInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?DocumentInterface $thumbnail = null;

    /** How that picture fills the frame a card gives it. */
    #[ORM\Column(length: 20, enumType: ThumbnailFitEnum::class, options: ['default' => 'cover'])]
    protected ThumbnailFitEnum $thumbnailFit = ThumbnailFitEnum::Cover;

    /**
     * Where the crop centres, as fractions of the picture, overriding the focal
     * point stored on the document itself.
     *
     * Null means "use the document's". The document's answer is about the file
     * — a face is in the same place wherever it appears — and this one is about
     * this publication's card, which is a different question the moment a wide
     * photo has to work in a narrow frame.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    protected ?float $thumbnailFocalX = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    protected ?float $thumbnailFocalY = null;

    #[ORM\ManyToOne(targetEntity: CoreUserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CoreUserInterface $author = null;

    /** @var Collection<string, PostTranslationInterface> */
    #[ORM\OneToMany(targetEntity: PostTranslationInterface::class, mappedBy: 'post', cascade: ['persist', 'remove'], orphanRemoval: true, indexBy: 'locale')]
    protected Collection $translations;

    /** @var Collection<int, PostRevisionInterface> */
    #[ORM\OneToMany(targetEntity: PostRevisionInterface::class, mappedBy: 'post', cascade: ['remove'], orphanRemoval: true)]
    protected Collection $revisions;

    /**
     * Mapped on the concrete class: both need a join table, which a
     * MappedSuperclass may not declare.
     *
     * @var Collection<int, TaxonomyTermInterface>
     */
    protected Collection $terms;

    /** @var Collection<int, PostInterface> */
    protected Collection $relatedPosts;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
        $this->revisions = new ArrayCollection();
        $this->terms = new ArrayCollection();
        $this->relatedPosts = new ArrayCollection();
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getStatus(): PostStatusEnum
    {
        return $this->status;
    }

    public function setStatus(PostStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->status->isPublic();
    }

    public function getPublishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

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

    public function isCommentsEnabled(): bool
    {
        return $this->commentsEnabled;
    }

    /** @return array<string, mixed> */
    public function getBannerLayout(): array
    {
        return $this->bannerLayout;
    }

    /** @param array<string, mixed> $bannerLayout */
    public function setBannerLayout(array $bannerLayout): static
    {
        $this->bannerLayout = $bannerLayout;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getGridLayout(): array
    {
        return $this->gridLayout;
    }

    /** @param array<string, mixed> $gridLayout */
    public function setGridLayout(array $gridLayout): static
    {
        $this->gridLayout = $gridLayout;

        return $this;
    }

    public function setCommentsEnabled(bool $commentsEnabled): static
    {
        $this->commentsEnabled = $commentsEnabled;

        return $this;
    }

    public function isTitleVisible(): bool
    {
        return $this->titleVisible;
    }

    public function setTitleVisible(bool $titleVisible): static
    {
        $this->titleVisible = $titleVisible;

        return $this;
    }

    public function getPostType(): PostTypeInterface
    {
        return $this->postType;
    }

    public function setPostType(PostTypeInterface $postType): static
    {
        $this->postType = $postType;

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

    public function getThumbnailFit(): ThumbnailFitEnum
    {
        return $this->thumbnailFit;
    }

    public function setThumbnailFit(ThumbnailFitEnum $thumbnailFit): static
    {
        $this->thumbnailFit = $thumbnailFit;

        return $this;
    }

    public function getThumbnailFocalX(): ?float
    {
        return $this->thumbnailFocalX;
    }

    public function getThumbnailFocalY(): ?float
    {
        return $this->thumbnailFocalY;
    }

    /**
     * Both or neither: half a focal point is not a position, and letting one
     * axis come from the publication and the other from the document would put
     * the crop somewhere nobody chose.
     */
    public function setThumbnailFocal(?float $x, ?float $y): static
    {
        $complete = null !== $x && null !== $y;

        $this->thumbnailFocalX = $complete ? max(0.0, min(1.0, $x)) : null;
        $this->thumbnailFocalY = $complete ? max(0.0, min(1.0, $y)) : null;

        return $this;
    }

    public function getAuthor(): ?CoreUserInterface
    {
        return $this->author;
    }

    public function setAuthor(?CoreUserInterface $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function getTranslation(string $locale): ?PostTranslationInterface
    {
        return $this->translations->get($locale);
    }

    public function translate(string $locale): PostTranslationInterface
    {
        if ($this->translations->containsKey($locale)) {
            return $this->translations->get($locale);
        }

        $translation = $this->createTranslation();
        $translation->setPost($this);
        $translation->setLocale($locale);

        $this->translations->set($locale, $translation);

        return $translation;
    }

    public function getTerms(): Collection
    {
        return $this->terms;
    }

    public function addTerm(TaxonomyTermInterface $term): static
    {
        if (!$this->terms->contains($term)) {
            $this->terms->add($term);
            $term->addPost($this);
        }

        return $this;
    }

    public function removeTerm(TaxonomyTermInterface $term): static
    {
        if ($this->terms->removeElement($term)) {
            $term->removePost($this);
        }

        return $this;
    }

    public function getRevisions(): Collection
    {
        return $this->revisions;
    }

    public function getRelatedPosts(): Collection
    {
        return $this->relatedPosts;
    }

    public function addRelatedPost(PostInterface $post): static
    {
        if ($post !== $this && !$this->relatedPosts->contains($post)) {
            $this->relatedPosts->add($post);
        }

        return $this;
    }

    public function removeRelatedPost(PostInterface $post): static
    {
        $this->relatedPosts->removeElement($post);

        return $this;
    }

    /** @see AbstractTaxonomy::createTranslation() */
    protected function createTranslation(): PostTranslationInterface
    {
        return new PostTranslation();
    }
}
