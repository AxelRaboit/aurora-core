<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a trame is filed under: a trade, an offer, whatever the studio sells.
 *
 * A row the studio manages rather than the enum it used to be. The enum named
 * three trades - community management, photography, web development - which
 * were one freelancer's, written into a bundle anybody installs; and decks and
 * deliverables already had categories one creates from the screen. The shape
 * is theirs: a name, a colour for a list that is scanned rather than read, a
 * position somebody arranges.
 *
 * A trame may have none: "nobody has said yet" stays a visible state rather
 * than a default it was never assigned to.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractContractTemplateCategory implements ContractTemplateCategoryInterface
{
    use TimestampableTrait;

    #[ORM\Column(length: 100)]
    protected string $name = '';

    /** Hex colour used to tint the badge, e.g. `#6366f1`. */
    #[ORM\Column(length: 7, nullable: true)]
    protected ?string $color = null;

    /** Ordered by hand: a reader files by habit, not alphabetically. */
    #[ORM\Column(options: ['default' => 0])]
    protected int $position = 0;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;

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
