<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ce sous quoi on range un livrable de Studio : audit, stratégie, proposition.
 *
 * Propre au module, comme les catégories des présentations : les taxonomies
 * de l'éditorial sont liées aux types de publication et n'ont rien à dire
 * d'un document qui ne va jamais sur le site. Elles ne valent que pour les
 * livrables de Studio, ceux qu'on garde comme modèles ; un livrable d'espace
 * se range par son espace.
 *
 * Une couleur, parce qu'une liste de modèles se parcourt d'un coup d'œil, et
 * un ordre choisi à la main : on range par habitude, pas par ordre alphabétique.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractDeliverableCategory implements DeliverableCategoryInterface
{
    use TimestampableTrait;

    #[ORM\Column(length: 100)]
    protected string $name = '';

    /** Couleur hexadécimale de la pastille, par exemple `#bd4a55`. */
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
