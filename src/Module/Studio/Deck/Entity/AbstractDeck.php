<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Deck\Enum\DeckThemeEnum;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A deck of slides: what gets shown to somebody.
 *
 * **The customer is nullable, and that nullability is the point.** A deck is
 * usually addressed to a client, which is the whole reason this module holds
 * both - a module may not carry a relation to another module's entity, so
 * either they live together or the link cannot exist. But a strategy deck
 * written for oneself has no client, and a deck module that demanded one would
 * make the commonest internal case impossible. The relation is declared
 * against `CustomerInterface` so a client project that swapped its own
 * customer entity in keeps working.
 *
 * The slides are ordered by `position` and cascade on removal: a slide has no
 * life outside the deck it belongs to, unlike a customer or a contract.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractDeck implements DeckInterface
{
    use TimestampableTrait;

    #[ORM\Column(length: 200)]
    protected string $title = '';

    /** One line, read in the list. Not the first slide's subtitle. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $description = null;

    #[ORM\ManyToOne(targetEntity: DeckCategoryInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?DeckCategoryInterface $category = null;

    /**
     * Who it was written for, when it was written for somebody.
     *
     * `SET NULL` rather than a cascade: deleting a customer must not take the
     * deck that was presented to them. The work happened, and the document is
     * often the only trace of it left.
     */
    #[ORM\ManyToOne(targetEntity: CustomerInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CustomerInterface $customer = null;

    /**
     * The look the slides are drawn in.
     *
     * `Slate` is what every deck looked like before the column existed, so
     * adding it changes nothing anybody had already composed.
     */
    #[ORM\Column(length: 20, enumType: DeckThemeEnum::class, options: ['default' => 'slate'])]
    protected DeckThemeEnum $theme = DeckThemeEnum::Slate;

    /**
     * What this deck overrides of its theme, and the few things no theme
     * carries: the logo, the footer line, the slide numbers.
     *
     * JSON and whitelisted on the way in by `DeckStyleNormalizer`, for the
     * reason `AbstractSlide::$content` gives at length: a column per setting
     * means a migration per new setting and a table of mostly-null columns, and
     * a free-form blob means whatever a form happened to post.
     *
     * An absent key means "inherit the theme". Nothing is written with a
     * default, so a theme retuned in a later version reaches the decks that
     * never overrode it.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    protected array $style = [];

    /** @var Collection<int, SlideInterface> */
    #[ORM\OneToMany(targetEntity: SlideInterface::class, mappedBy: 'deck', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    protected Collection $slides;

    public function __construct()
    {
        $this->slides = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getCategory(): ?DeckCategoryInterface
    {
        return $this->category;
    }

    public function setCategory(?DeckCategoryInterface $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getCustomer(): ?CustomerInterface
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerInterface $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    public function getTheme(): DeckThemeEnum
    {
        return $this->theme;
    }

    public function setTheme(DeckThemeEnum $theme): static
    {
        $this->theme = $theme;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getStyle(): array
    {
        return $this->style;
    }

    /** @param array<string, mixed> $style */
    public function setStyle(array $style): static
    {
        $this->style = $style;

        return $this;
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
            $slide->setDeck($this);
        }

        return $this;
    }

    public function removeSlide(SlideInterface $slide): static
    {
        $this->slides->removeElement($slide);

        return $this;
    }
}
