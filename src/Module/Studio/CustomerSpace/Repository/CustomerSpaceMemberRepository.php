<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMemberInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<CustomerSpaceMemberInterface>
 */
class CustomerSpaceMemberRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerSpaceMember::class, CustomerSpaceMemberInterface::class);
    }
}
