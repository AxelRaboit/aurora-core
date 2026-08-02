<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Entity;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class AbstractPostTranslation implements PostTranslationInterface
{
    #[ORM\Column(length: 10)]
    protected string $locale;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $slug = null;

    /**
     * The short summary a reader sees: under the title on the page, and as
     * the teaser on a listing card. Optional.
     *
     * Deliberately not the meta description, which is written for a search
     * snippet and cut off around 160 characters. One field serving both is
     * what produced 247-character "meta descriptions" that were really
     * prose. WordPress draws the same line with post_excerpt.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $description = null;

    /**
     * Editor.js native shape: ordered list of `{id?, type, data}` entries.
     * Identity is the Editor.js-generated id; order is the array order.
     *
     * @var list<array{id?: string, type: string, data: array<string, mixed>}>
     */
    #[ORM\Column(type: Types::JSON)]
    protected array $blocks = [];

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $metaTitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $metaDescription = null;

    /** @var array<string, mixed> Values of the PostType's custom fields, keyed by field name. */
    #[ORM\Column(type: Types::JSON)]
    protected array $customFields = [];

    #[ORM\ManyToOne(targetEntity: DocumentInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?DocumentInterface $ogImage = null;

    #[ORM\Column(length: 500, nullable: true)]
    protected ?string $canonicalUrl = null;

    #[ORM\Column(options: ['default' => false])]
    protected bool $noindex = false;

    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $focusKeyword = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    protected ?array $jsonLd = null;

    /** Flattened text of title + blocks, rebuilt on save so search hits the body. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $searchContent = null;

    #[ORM\ManyToOne(targetEntity: PostInterface::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected PostInterface $post;

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;

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

    public function getBlocks(): array
    {
        return $this->blocks;
    }

    public function setBlocks(array $blocks): static
    {
        $this->blocks = $blocks;

        return $this;
    }

    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }

    public function setMetaTitle(?string $metaTitle): static
    {
        $this->metaTitle = $metaTitle;

        return $this;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): static
    {
        $this->metaDescription = $metaDescription;

        return $this;
    }

    public function getCustomFields(): array
    {
        return $this->customFields;
    }

    public function setCustomFields(array $customFields): static
    {
        $this->customFields = $customFields;

        return $this;
    }

    public function getOgImage(): ?DocumentInterface
    {
        return $this->ogImage;
    }

    public function setOgImage(?DocumentInterface $ogImage): static
    {
        $this->ogImage = $ogImage;

        return $this;
    }

    public function getCanonicalUrl(): ?string
    {
        return $this->canonicalUrl;
    }

    public function setCanonicalUrl(?string $canonicalUrl): static
    {
        $this->canonicalUrl = $canonicalUrl;

        return $this;
    }

    public function isNoindex(): bool
    {
        return $this->noindex;
    }

    public function setNoindex(bool $noindex): static
    {
        $this->noindex = $noindex;

        return $this;
    }

    public function getFocusKeyword(): ?string
    {
        return $this->focusKeyword;
    }

    public function setFocusKeyword(?string $focusKeyword): static
    {
        $this->focusKeyword = $focusKeyword;

        return $this;
    }

    public function getJsonLd(): ?array
    {
        return $this->jsonLd;
    }

    public function setJsonLd(?array $jsonLd): static
    {
        $this->jsonLd = $jsonLd;

        return $this;
    }

    public function getSearchContent(): ?string
    {
        return $this->searchContent;
    }

    public function setSearchContent(?string $searchContent): static
    {
        $this->searchContent = $searchContent;

        return $this;
    }

    public function getPost(): PostInterface
    {
        return $this->post;
    }

    public function setPost(PostInterface $post): static
    {
        $this->post = $post;

        return $this;
    }
}
