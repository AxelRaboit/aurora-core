<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Entity;

use Aurora\Module\Studio\Pipeline\Repository\PipelineStageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PipelineStageRepository::class)]
#[ORM\Table(name: 'core_studio_pipeline_stages')]
class PipelineStage extends AbstractPipelineStage
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_pipeline_stage_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
