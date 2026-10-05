<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Repository\Trait\PaginationTrait;
use Aurora\Module\Configuration\Setting\Entity\Setting;
use Aurora\Module\Configuration\Setting\Entity\SettingInterface;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnumInterface;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @extends ResolveTargetEntityRepository<SettingInterface>
 */
class SettingRepository extends ResolveTargetEntityRepository implements ResetInterface
{
    use PaginationTrait;

    /** @var array<string, string|null>|null */
    private ?array $cache = null;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class, SettingInterface::class);
    }

    /**
     * Forgets the settings read so far.
     *
     * Called by Symfony between two messages of a worker. Without it the
     * worker kept the settings of the moment it started: switching a module
     * off did not stop its scheduled jobs until the worker was restarted.
     */
    public function reset(): void
    {
        $this->cache = null;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $this->warmUp();

        return array_key_exists($key, $this->cache) ? ($this->cache[$key] ?? $default) : $default;
    }

    /**
     * Returns the stored value for the given parameter, falling back to its
     * declared default when missing or null. Always returns a non-null string.
     */
    public function getOrDefault(ApplicationParameterEnumInterface $parameter): string
    {
        $default = $parameter->getDefaultValue();

        return $this->get($parameter->getKey(), $default) ?? $default;
    }

    public function getBoolean(string $key, bool $default = false): bool
    {
        return '1' === $this->get($key, $default ? '1' : '0');
    }

    public function set(string $key, ?string $value): void
    {
        $this->saveMany([[$key, $value]]);
    }

    /**
     * Atomically persists a batch of key/value writes (single flush).
     *
     * @param iterable<array{0: string, 1: ?string}> $entries
     */
    public function saveMany(iterable $entries): void
    {
        foreach ($entries as [$key, $value]) {
            $setting = $this->find($key);

            if (!$setting instanceof SettingInterface) {
                $setting = new Setting($key, $value);
                $this->getEntityManager()->persist($setting);
            } else {
                $setting->setValue($value);
            }

            if (null !== $this->cache) {
                $this->cache[$key] = $value;
            }
        }

        $this->getEntityManager()->flush();
    }

    private function warmUp(): void
    {
        if (null !== $this->cache) {
            return;
        }

        $this->cache = [];
        foreach ($this->findAll() as $setting) {
            $this->cache[$setting->getKey()] = $setting->getValue();
        }
    }

    /**
     * @return Setting[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.group', Order::Ascending->value)
            ->addOrderBy('s.key', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{items: Setting[], total: int, page: int, totalPages: int}
     */
    /**
     * @param list<string> $excludeKeys Keys to exclude from results (e.g. those already surfaced in /suite/settings)
     */
    public function findPaginated(int $page, int $limit = 20, ?string $search = null, ?string $group = null, array $excludeKeys = []): array
    {
        $queryBuilder = $this->createQueryBuilder('s')
            ->orderBy('s.group', Order::Ascending->value)
            ->addOrderBy('s.key', Order::Ascending->value);
        $countQueryBuilder = $this->createQueryBuilder('s')->select('COUNT(s.key)');

        if (null !== $search && '' !== $search) {
            $queryBuilder->andWhere('LOWER(s.key) LIKE :search')->setParameter('search', '%'.mb_strtolower($search).'%');
            $countQueryBuilder->andWhere('LOWER(s.key) LIKE :search')->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        if (null !== $group && '' !== $group) {
            $queryBuilder->andWhere('s.group = :group')->setParameter('group', $group);
            $countQueryBuilder->andWhere('s.group = :group')->setParameter('group', $group);
        } else {
            $queryBuilder->andWhere('s.group != :modules')->setParameter('modules', ModuleParameterEnum::MODULE);
            $countQueryBuilder->andWhere('s.group != :modules')->setParameter('modules', ModuleParameterEnum::MODULE);
        }

        if ([] !== $excludeKeys) {
            $queryBuilder->andWhere('s.key NOT IN (:excludeKeys)')->setParameter('excludeKeys', $excludeKeys);
            $countQueryBuilder->andWhere('s.key NOT IN (:excludeKeys)')->setParameter('excludeKeys', $excludeKeys);
        }

        return $this->paginate($queryBuilder, $countQueryBuilder, $page, $limit);
    }
}
