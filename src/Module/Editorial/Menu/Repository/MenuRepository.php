<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\Menu\Entity\Menu;
use Aurora\Module\Editorial\Menu\Entity\MenuInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<MenuInterface>
 */
class MenuRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Menu::class, MenuInterface::class);
    }

    public function findOneByLocation(string $location): ?MenuInterface
    {
        return $this->findOneBy(['location' => $location]);
    }

    /**
     * The whole menu in one query. A navigation rendered on every page must
     * not cost a query per entry per locale.
     *
     * @return list<MenuInterface>
     */
    public function findAllWithItems(): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.items', 'i')
            ->leftJoin('i.translations', 't')
            ->addSelect('i', 't')
            ->orderBy('m.location', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }
}
