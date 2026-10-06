<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a Studio deliverable is filed under: audit, strategy, proposal.
 *
 * Specific to the module, like the presentation categories: the editorial
 * taxonomies are tied to publication types and have nothing to say about a
 * document that never goes on the site. They only apply to Studio
 * deliverables, the ones kept as templates; a space deliverable is filed by
 * its space.
 *
 * A colour, because a list of templates is scanned at a glance, and a
 * hand-picked order: people sort by habit, not alphabetically.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractDeliverableCategory implements DeliverableCategoryInterface
{
    use TimestampableTrait;

    #[ORM\Column(length: 100)]
    protected string $name = '';

    /** Hexadecimal colour of the pill, for example `#bd4a55`. */
    #[ORM\Column(length: 7, nullable: true)]
    protected ?string $color = null;

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
