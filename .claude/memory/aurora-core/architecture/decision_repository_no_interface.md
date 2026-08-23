# Pas d'interface `<Name>RepositoryInterface` dans aurora-core

## Règle

Aurora-core **n'expose pas** d'interface pour les Repositories. Les
controllers / managers Aurora type-hint la classe concrète
`<Name>Repository` directement.

## Pourquoi

Coût/bénéfice non justifié :
- Les finders custom Aurora (`findByCompany`, `findActive`, …) n'ont pas
  vocation à être substitués/décorés par un client.
- Un client veut typiquement **ajouter** des finders, pas remplacer ceux
  d'Aurora.
- Maintenir les interfaces en parallèle des implémentations doublerait la
  surface de maintenance pour zéro valeur ajoutée.

## Comment l'appliquer

### Côté aurora-core (developer)

- `<Name>Repository` étend `Aurora\Core\Repository\ResolveTargetEntityRepository`
  (jamais `ServiceEntityRepository` directement - cf
  `pitfall_service_entity_repository.md`).
- Controllers et Managers type-hint la classe concrete : `private readonly
  AgencyRepository $agencyRepository`.
- **Pas** de fichier `<Name>RepositoryInterface.php`.

### Côté client (consumer)

Pattern documenté pour étendre les finders :

```php
// 1. Étendre le repo Aurora
namespace App\Repository;

use Aurora\Module\Platform\Agency\Repository\AgencyRepository;

class AppAgencyRepository extends AgencyRepository
{
    public function findActiveExcludingArchived(): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.archivedAt IS NULL')
            ->getQuery()->getResult();
    }
}

// 2. Déclarer dans l'entité concrète client
#[ORM\Entity(repositoryClass: AppAgencyRepository::class)]
class Agency extends \Aurora\Module\Platform\Agency\Entity\AbstractAgency implements AgencyInterface
{
    // …
}
```

`ResolveTargetEntityRepository` route déjà la query via metadata, donc les
finders Aurora **et** custom client cohabitent sans conflit. Le client
type-hint `AppAgencyRepository` dans son propre code ; Aurora continue de
type-hint `AgencyRepository`.

## Limite assumée

Un client ne peut pas **remplacer** un finder Aurora (overrider
`findActive` pour ajouter une condition de tenant). Il peut juste en
ajouter via héritage. Si ce besoin émerge un jour, on pourra ajouter une
interface a posteriori - mais pas par anticipation.

## Source

Décision de l'audit post-Editorial. Section "Couche bonus -
ResolveTargetEntityRepository / Étendre une finder method côté client" du
doc convention.
