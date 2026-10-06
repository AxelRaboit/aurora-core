<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Slides\Entity;

use Aurora\Module\Studio\Deliverable\Slides\Repository\SlideRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SlideRepository::class)]
#[ORM\Table(name: 'core_studio_deliverable_slides')]
class Slide extends AbstractSlide
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_deliverable_slide_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
