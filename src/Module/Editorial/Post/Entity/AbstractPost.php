<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
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

    #[ORM\ManyToOne(targetEntity: PostTypeInterface::class, inversedBy: 'posts')]
    #[ORM\JoinColumn(nullable: false)]
    protected PostTypeInterface $postType;

    #[ORM\ManyToOne(targetEntity: DocumentInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?DocumentInterface $featuredMedia = null;

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

    public function setCommentsEnabled(bool $commentsEnabled): static
    {
        $this->commentsEnabled = $commentsEnabled;

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

    public function getFeaturedMedia(): ?DocumentInterface
    {
        return $this->featuredMedia;
    }

    public function setFeaturedMedia(?DocumentInterface $featuredMedia): static
    {
        $this->featuredMedia = $featuredMedia;

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
