<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Slides\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Slides\Enum\SlideLayoutEnum;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One slide, which is a layout plus the words that fill it.
 *
 * **The content is JSON and the layout says what may be in it.** A column per
 * slot would mean a migration per new layout and a table of mostly-null
 * columns; a free-form blob would mean whatever a form happened to post. The
 * middle is what `GridNormalizer` already does for the content grid: the shape
 * is declared in code, the payload is whitelisted against it on the way in,
 * and the text is sanitised at render.
 *
 * `speakerNotes` never reaches a viewer. It is what the presenter reads while
 * the slide is on screen, which is why it is a column of its own rather than a
 * slot: no layout should be able to put it on the wall by accident.
 *
 * **A slide belongs to a deliverable in the slides format.** Studio's decks
 * became such deliverables (`Version20261006140000`); the slides kept their
 * ids, `deliverable_id` became the only owner, and the table took the
 * deliverables' name (`Version20261006150000`).
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractSlide implements SlideInterface
{
    use TimestampableTrait;

    /**
     * Nullable in PHP only for the moment between `new` and the `addSlide()`
     * that attaches it: the column is not.
     */
    #[ORM\ManyToOne(targetEntity: DeliverableInterface::class, inversedBy: 'slides')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected ?DeliverableInterface $deliverable = null;

    #[ORM\Column(length: 20, enumType: SlideLayoutEnum::class)]
    protected SlideLayoutEnum $layout = SlideLayoutEnum::Bullets;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    protected array $content = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $speakerNotes = null;

    #[ORM\Column(options: ['default' => 0])]
    protected int $position = 0;

    public function getDeliverable(): ?DeliverableInterface
    {
        return $this->deliverable;
    }

    public function setDeliverable(?DeliverableInterface $deliverable): static
    {
        $this->deliverable = $deliverable;

        return $this;
    }

    public function getLayout(): SlideLayoutEnum
    {
        return $this->layout;
    }

    public function setLayout(SlideLayoutEnum $layout): static
    {
        $this->layout = $layout;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getContent(): array
    {
        return $this->content;
    }

    /** @param array<string, mixed> $content */
    public function setContent(array $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getSpeakerNotes(): ?string
    {
        return $this->speakerNotes;
    }

    public function setSpeakerNotes(?string $speakerNotes): static
    {
        $this->speakerNotes = $speakerNotes;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
