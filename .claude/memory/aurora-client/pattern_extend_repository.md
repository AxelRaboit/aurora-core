# Pattern : étendre les finder methods d'un Repository

## Règle

Pour ajouter des finders custom à un Repository Aurora :
1. Étendre `Aurora\…\<Name>Repository`.
2. Déclarer `repositoryClass: AppXxxRepository::class` dans l'entité
   concrète client.

**Limite assumée** : aurora-core n'expose pas de `<Name>RepositoryInterface`
(cf [decision_repository_no_interface.md](../decision_repository_no_interface.md)).
Le client peut **ajouter** des finders, pas **remplacer** ceux d'Aurora.

## Comment l'appliquer

### 1. Repository étendu

```php
namespace App\Repository;

use Aurora\Module\Platform\Agency\Repository\AgencyRepository;

class AppAgencyRepository extends AgencyRepository
{
    public function findActiveExcludingArchived(): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.archivedAt IS NULL')
            ->orderBy('a.name', 'ASC')
            ->getQuery()->getResult();
    }
}
```

### 2. Entité concrète déclare le repo

```php
#[ORM\Entity(repositoryClass: \App\Repository\AppAgencyRepository::class)]
class Agency extends \Aurora\…\AbstractAgency implements AgencyInterface
{
    // …
}
```

`ResolveTargetEntityRepository` route déjà la query via metadata. Les
finders Aurora **et** custom client cohabitent dans `AppAgencyRepository`.

### 3. Type-hint dans le code client

```php
// Côté code client : type-hint la classe concrète client
public function __construct(
    private readonly AppAgencyRepository $agencyRepository,
) {}

// Côté code Aurora qui consomme le repo :
// type-hint AgencyRepository (la classe concrète Aurora) - fonctionne
// car AppAgencyRepository étend AgencyRepository.
```

## Pourquoi pas d'interface

Cf [decision_repository_no_interface.md](../decision_repository_no_interface.md) :
- Les finders custom client n'ont pas vocation à être appelés depuis
  aurora-core.
- Maintenir une interface en parallèle = surface inutile.
- Si un jour on veut remplacer un finder Aurora (override
  `findActive()`), on créera l'interface a posteriori - pas par
  anticipation.

## Pièges

### 1. Oublier `repositoryClass`

Sans `#[ORM\Entity(repositoryClass: AppAgencyRepository::class)]`,
`$em->getRepository(AppAgency::class)` retournera l'`AgencyRepository`
Aurora - les finders custom ne seront pas accessibles via cette voie.

### 2. Override d'un finder Aurora avec signature différente

```php
// ❌ Risqué - signature différente d'Aurora
class AppAgencyRepository extends AgencyRepository
{
    public function findActive(int $tenantId): array  // ajoute un param
    { … }
}
```

Si Aurora évolue et appelle `findActive()` sans le tenantId, ça casse.
Préférer **ajouter** un nouveau finder (`findActiveForTenant($tenantId)`)
que d'altérer la signature d'un finder existant.
