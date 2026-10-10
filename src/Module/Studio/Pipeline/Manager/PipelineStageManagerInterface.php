<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Manager;

use Aurora\Module\Studio\Pipeline\Dto\PipelineStageInputInterface;
use Aurora\Module\Studio\Pipeline\Entity\PipelineStageInterface;

interface PipelineStageManagerInterface
{
    public function create(PipelineStageInputInterface $input): PipelineStageInterface;

    public function update(PipelineStageInterface $stage, PipelineStageInputInterface $input): void;

    public function delete(PipelineStageInterface $stage): void;

    /** @param list<int> $stageIds */
    public function reorder(array $stageIds): void;

    /**
     * The stages, left to right, created from the default list the first time
     * they are asked for.
     *
     * @return list<PipelineStageInterface>
     */
    public function stages(): array;
}
