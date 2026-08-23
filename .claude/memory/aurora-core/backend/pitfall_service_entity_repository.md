# Piège : `ServiceEntityRepository` hardcode la classe

## Règle

Tout `<Name>Repository` Aurora **doit** étendre
`Aurora\Core\Repository\ResolveTargetEntityRepository`, jamais
`Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository`
directement.

## Pourquoi

`ServiceEntityRepository::__construct()` exige le **nom de la classe
concrete** comme deuxième argument :

```php
public function __construct(ManagerRegistry $registry)
{
    parent::__construct($registry, Agency::class);  // ❌ hardcode Aurora\…\Agency
}
```

Résultat : même si un client a défini `App\Module\Core\Agency\Entity\Agency` avec
`resolve_target_entities`, le repo continue de **query la table Aurora**
(`core_agencies`) et non la table client (`app_agencies`).

C'est exactement le bug qu'on a découvert sur Agency au début du rollout :
les agences éditées via l'admin client n'étaient pas trouvées car le repo
cherchait dans la mauvaise table.

## Comment l'appliquer

`ResolveTargetEntityRepository` résout la classe via metadata factory :

```php
// src/Core/Repository/ResolveTargetEntityRepository.php
abstract class ResolveTargetEntityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, string $defaultClass, string $interfaceClass)
    {
        parent::__construct($registry, self::resolveEntityClass($registry, $defaultClass, $interfaceClass));
    }

    private static function resolveEntityClass(ManagerRegistry $registry, string $defaultClass, string $interfaceClass): string
    {
        $manager = $registry->getManagerForClass($defaultClass);
        if (null === $manager) return $defaultClass;
        return $manager->getClassMetadata($interfaceClass)->getName();
    }
}
```

### Squelette du Repository Aurora

```php
class AgencyRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agency::class, AgencyInterface::class);
    }

    // … finder methods custom
}
```

Avec ce pattern :
- Si pas de substitution : query `core_agencies` (table Aurora).
- Si client a substitué via `resolve_target_entities` : query
  `app_agencies` (ou autre table préfixée `app_`).

## État

✅ Tous les 60+ Repositories Aurora ont été migrés au début du rollout
(commit antérieur à la convention finale). Aucun ne devrait plus utiliser
`ServiceEntityRepository` directement.

Vérification :

```bash
grep -rn "extends ServiceEntityRepository" src/ --include='*.php'
# Devrait être vide
```

## Source

Bug découvert sur Agency lors du pilote - un client testait l'override et
l'édition ne sauvait pas les champs custom. Solution : créer
`ResolveTargetEntityRepository` + script de migration pour les 60 repos.
