# Piège : `resolve_target_entities` ne couvre pas `new`

## Règle

Le mécanisme Doctrine `resolve_target_entities` **ne s'applique qu'aux
relations Doctrine** (`@ManyToOne`, `@OneToMany`, etc.). Il **ne fait
rien** sur :
- Les `new <Name>()` directs dans le code
- Les requêtes (`$em->getRepository(<Name>::class)` doit utiliser le bon
  resolver)

## Pourquoi

C'est exactement la raison pour laquelle le hook `protected create<X>():
<X>Interface` existe. Sans lui, un client qui a étendu `Agency` :

```php
// Manager Aurora-core
public function create(AgencyInputInterface $input): AgencyInterface
{
    $agency = new Agency();  // ❌ Toujours la classe Aurora, pas App\Agency
    $this->applyInput($agency, $input);
    // …
}
```

→ Doctrine persiste la classe Aurora, **pas** la classe étendue. Les
champs custom du client sont perdus.

## Comment l'appliquer

### Au moment d'écrire un Manager

Lister toutes les classes que le Manager instancie via `new`. Pour chaque,
créer un hook :

```php
public function create(AgencyInputInterface $input): AgencyInterface
{
    $agency = $this->createAgency();  // ✅ via hook
    $this->applyInput($agency, $input);
    // …
}

protected function createAgency(): AgencyInterface
{
    return new Agency();  // override-able par le client
}
```

### Côté client

Override le hook + déclarer dans `resolve_target_entities` (pour les
relations Doctrine - c'est complémentaire) :

```php
// App\Module\Core\Agency\Manager\AgencyManager
class AgencyManager extends \Aurora\…\AgencyManager
{
    protected function createAgency(): AgencyInterface
    {
        return new \App\Module\Core\Agency\Entity\Agency();  // classe étendue
    }
}

// config/packages/doctrine.yaml → resolve_target_entities
'resolve_target_entities' => [
    \Aurora\…\AgencyInterface::class => \App\Module\Core\Agency\Entity\Agency::class,
];
```

Les **deux mécanismes sont nécessaires** :
- `createAgency()` hook → couvre les `new Agency()` directs dans les
  Managers.
- `resolve_target_entities` → couvre les relations Doctrine (`@ManyToOne(targetEntity: AgencyInterface::class)`).

## Piège bonus - constructeur du repo prend 3 args, pas 2

`ResolveTargetEntityRepository::__construct(registry, defaultClass, interfaceClass)`
**exige 3 arguments**, pas 2. La signature `ServiceEntityRepository` standard
(2 args) ne suffit pas - le 3e (`interfaceClass`) est ce qui permet à la base
de résoudre la classe substituée via les metadata Doctrine.

```php
// ❌ erreur Symfony à l'instanciation : "Too few arguments..."
public function __construct(ManagerRegistry $registry)
{
    parent::__construct($registry, MyEntity::class);
}

// ✅ correct
public function __construct(ManagerRegistry $registry)
{
    parent::__construct($registry, MyEntity::class, MyEntityInterface::class);
}
```

Les tests unitaires ne capturent pas ce bug (`createMock` bypass le
constructeur). C'est l'instanciation via la DI Symfony qui plante au premier
endpoint qui injecte le repo.

## Source

Découvert au début du rollout (Agency pilot). Cf le doc convention,
section "Couche bonus - ResolveTargetEntityRepository" et la note de la
section 3.1 sur les hooks d'instanciation.
