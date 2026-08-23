<?php

declare(strict_types=1);

namespace Aurora\Core\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;

/**
 * Base class for repositories whose entity may be substituted by a client via
 * Doctrine's `resolve_target_entities` mechanism.
 *
 * `ServiceEntityRepository` hardcodes a concrete entity class in its
 * constructor. When a client overrides `<Name>Interface` → `App\Entity\<Name>`,
 * the repository keeps querying Aurora's original table because Symfony's
 * dependency injection only ever instantiates one repository instance per
 * `repositoryClass`, with the first encountered entity class.
 *
 * This base resolves the actual entity class through the manager's class
 * metadata factory (which honours `resolve_target_entities`), so a single
 * repository class transparently targets either Aurora's default concrete
 * class or the client's substitute - whichever is currently mapped.
 *
 * @template T of object
 *
 * @extends ServiceEntityRepository<T>
 */
abstract class ResolveTargetEntityRepository extends ServiceEntityRepository
{
    /**
     * @param class-string $defaultClass   aurora's default concrete entity class - used as a fallback
     *                                     when no manager is registered for it
     * @param class-string $interfaceClass the entity contract - Doctrine resolves it to whichever
     *                                     concrete class is currently mapped
     */
    public function __construct(ManagerRegistry $registry, string $defaultClass, string $interfaceClass)
    {
        parent::__construct($registry, $this->resolveEntityClass($registry, $defaultClass, $interfaceClass));
    }

    private function resolveEntityClass(ManagerRegistry $registry, string $defaultClass, string $interfaceClass): string
    {
        $manager = $registry->getManagerForClass($defaultClass);
        if (!$manager instanceof ObjectManager) {
            return $defaultClass;
        }

        return $manager->getClassMetadata($interfaceClass)->getName();
    }
}
