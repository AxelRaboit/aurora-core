<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Entity;

use Aurora\Module\Editorial\Post\Banner\BannerNormalizer;
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

    /**
     * The banner's words for this locale — titles, descriptions, alt text and
     * button links — keyed by the id of the layout item they belong to. Shape
     * and defaults belong to {@see BannerNormalizer}, which every write goes
     * through.
     *
     * Only the copy. The design lives once on the post
     * ({@see AbstractPost::$bannerLayout}) so translating a banner means
     * writing its text, not rebuilding it.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    protected array $banner = [];

    /**
     * What each content-grid zone holds in this locale — the text blocks, the
     * alt text, the caption, the video address — keyed by the id of the zone
     * on the post. Shape belongs to {@see GridNormalizer}.
     *
     * Only the content. The arrangement lives once on the post
     * ({@see AbstractPost::$gridLayout}).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    protected array $grid = [];

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

    /** @return array<string, mixed> */
    public function getBanner(): array
    {
        return $this->banner;
    }

    /** @return array<string, mixed> */
    public function getGrid(): array
    {
        return $this->grid;
    }

    /** @param array<string, mixed> $grid */
    public function setGrid(array $grid): static
    {
        $this->grid = $grid;

        return $this;
    }

    /** @param array<string, mixed> $banner */
    public function setBanner(array $banner): static
    {
        $this->banner = $banner;

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
