<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Slides\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\Deliverable\Slides\Entity\Slide;
use Aurora\Module\Studio\Deliverable\Slides\Entity\SlideInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<SlideInterface>
 */
class SlideRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Slide::class, SlideInterface::class);
    }
}
