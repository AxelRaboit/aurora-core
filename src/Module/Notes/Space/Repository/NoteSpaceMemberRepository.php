<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Notes\Space\Entity\NoteSpaceMember;
use Aurora\Module\Notes\Space\Entity\NoteSpaceMemberInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<NoteSpaceMemberInterface>
 */
class NoteSpaceMemberRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NoteSpaceMember::class, NoteSpaceMemberInterface::class);
    }
}
