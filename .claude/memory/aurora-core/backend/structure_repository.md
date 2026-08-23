# Repository - convention

## Règle

Tout `<Name>Repository` Aurora **étend** `Aurora\Core\Repository\ResolveTargetEntityRepository`,
**jamais** `ServiceEntityRepository` directement (cf
[`pitfall_service_entity_repository.md`](pitfall_service_entity_repository.md)).

## Squelette canonique

```php
<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Agency\Repository;

use Aurora\Module\Platform\Agency\Entity\Agency;
use Aurora\Module\Platform\Agency\Entity\AgencyInterface;
use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<AgencyInterface>
 */
class AgencyRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agency::class, AgencyInterface::class);
    }

    /** @return AgencyInterface[] */
    public function findAllAlphabetical(): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.name', 'ASC')
            ->getQuery()->getResult();
    }
}
```

## Détails clés

- **3 args** au constructeur : `$registry`, `$defaultClass`,
  `$interfaceClass`. La classe de base résout via metadata factory pour
  honorer `resolve_target_entities` du client.
- **`@extends ResolveTargetEntityRepository<<Interface>>`** dans le PHPDoc
  pour aider PHPStan à inférer le type de retour de `find()` etc.
- **Type de retour des finders** : `<Name>Interface[]` ou
  `?<Name>Interface` (l'interface, pas la concrete).
- **Pas de classe `final`** - clients peuvent étendre.

## Naming des finders

- `findOneBy<Critère>(...)` : retourne un seul résultat ou null.
- `findBy<Critère>(...)` : retourne un array.
- `findAll<Variante>(...)` : retourne tout, avec un ordre / filtre standard.
- `findPaginated(int $page, int $limit, ?string $search = null)` : pour les
  list pages avec pagination.
- `count<Critère>(...)` : retourne un int.

## Order enum (`'ASC'` / `'DESC'` interdits)

Toujours `Doctrine\Common\Collections\Order` :

```php
use Doctrine\Common\Collections\Order;

$qb->orderBy('a.name', Order::Ascending->value);   // ✅
$qb->orderBy('a.name', 'ASC');                      // ❌
```

Cf [`convention_doctrine_order_enum.md`](convention_doctrine_order_enum.md).

## Pourquoi pas d'interface

Cf [`decision_repository_no_interface.md`](decision_repository_no_interface.md) :
- Coût/bénéfice non justifié.
- Les controllers / Managers Aurora type-hint la **classe concrete**.
- Les clients étendent + déclarent `repositoryClass` sur l'entité concrete.

## Cas particuliers

### Repos avec finders complexes (DQL multi-table)

Tout va dans le même repo. Pas de splitting en mini-repos par cas
d'usage.

### Repos avec count + paginated

Pattern courant : un finder paginé + un count :

```php
public function findPaginated(int $page, int $limit, ?string $search = null): array
{
    $qb = $this->createQueryBuilder('a');
    if (null !== $search && '' !== $search) {
        $qb->andWhere('LOWER(a.name) LIKE :s')->setParameter('s', '%'.mb_strtolower($search).'%');
    }
    $total = (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
    $items = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

    return [
        'items' => $items,
        'total' => (int) $total,
        'page' => $page,
        'totalPages' => (int) ceil($total / $limit),
    ];
}
```

### Batch fetch (éviter N+1)

Préférence utilisateur : **toujours** `findBy(['id' => $ids])` plutôt
qu'une boucle `find()` :

```php
// ❌ N+1
foreach ($ids as $id) {
    $entities[] = $this->repo->find($id);  // 1 requête par appel
}

// ✅ 1 requête
$entities = $this->repo->findBy(['id' => $ids]);
```

Cf user-level memory `feedback_batch_queries`.

## Pièges

### `ServiceEntityRepository` direct → bug Sylius

Cf [`pitfall_service_entity_repository.md`](pitfall_service_entity_repository.md).
Tous les repos Aurora ont été migrés à `ResolveTargetEntityRepository`.
Vérifier qu'aucun nouveau repo n'utilise l'ancienne classe :

```bash
grep -rn "extends ServiceEntityRepository" src/
# Devrait être vide
```

### Un repo dans le mauvais module

Si un repo est utilisé par un seul module métier mais vit dans Core, le
déplacer dans le module. Inversement, si un repo "Core" finit par être
utilisé par 3 modules, le sortir vers Core.

### Lazy-loading et N+1 caché

Doctrine charge en lazy par défaut. Une boucle qui accède
`$entity->getRelation()->getOtherRelation()` fait N+1.

Solution : `addSelect('JOIN ...')` dans le QueryBuilder du repo, ou utiliser
`fetch="EAGER"` sur la relation Doctrine.
