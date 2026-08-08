# Entity — Interface + Abstract + concrete

## Règle

Chaque entité Aurora se décompose en **3 fichiers** :

```
src/<Module>/<Feature>/Entity/
├── <Name>Interface.php       ← contrat public (getters + setters)
├── Abstract<Name>.php         ← MappedSuperclass Doctrine — toutes les colonnes SAUF id + sequence + ManyToMany
└── <Name>.php                 ← entité concrète, non-`final`, juste id + sequence + ManyToMany
```

## Pourquoi cette séparation

- **Interface** : contrat stable que les services/managers Aurora type-hint.
  Le client peut déclarer une concrete différente qui implémente la même
  interface → substitution transparente via `resolve_target_entities`.
- **Abstract** : MappedSuperclass Doctrine qui contient les colonnes
  communes. Pas instanciable, hérité par la concrete.
- **Concrete** : entity Doctrine instanciée. Juste l'id + sequence (que
  Doctrine ne propage pas au MappedSuperclass) + les ManyToMany (que
  Doctrine ne supporte pas proprement sur MappedSuperclass) +
  éventuellement les overrides client (côté client uniquement).

## Squelette canonique

### `<Name>Interface.php`

```php
<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Agency\Entity;

use Aurora\Core\Contract\TimestampableInterface;

interface AgencyInterface extends TimestampableInterface
{
    public function getId(): ?int;

    public function getName(): string;

    public function setName(string $name): self;

    // … autres getters / setters
}
```

**Notes** :
- Étend `TimestampableInterface` si l'entité utilise `TimestampableTrait`
  (createdAt / updatedAt).
- Tous les **getters/setters publics** doivent y être pour qu'une concrete
  étendue puisse être traitée par les services Aurora.

### `Abstract<Name>.php`

```php
<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Agency\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractAgency implements AgencyInterface
{
    use TimestampableTrait;

    #[ORM\Column(length: 150)]
    protected string $name = '';

    #[ORM\Column(length: 50, nullable: true, unique: true)]
    protected ?string $reference = null;

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    // … autres getters / setters
}
```

**Notes** :
- `#[ORM\MappedSuperclass]` (pas `#[ORM\Entity]`).
- `#[ORM\HasLifecycleCallbacks]` obligatoire dès que `TimestampableTrait`
  est utilisé — sans lui les callbacks ne tournent pas et les dates
  restent nulles. Le trait vient de `Aurora\Core\Timestampable`,
  **jamais de Knp** (cf. [[convention_timestampable]]).
- `abstract class` (pas instanciable).
- Propriétés `protected` (pas `private`) pour que la concrete y accède
  directement si besoin de surcharger.
- Pas d'`id` ni de `SequenceGenerator` ici — Doctrine ne les propage pas
  au MappedSuperclass.
- Pas de ManyToMany ici — Doctrine ne supporte pas proprement les
  ManyToMany sur MappedSuperclass.

### `<Name>.php` (concrete Aurora)

```php
<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Agency\Entity;

use Aurora\Module\Platform\Agency\Repository\AgencyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AgencyRepository::class)]
#[ORM\Table(name: 'core_agencies')]
class Agency extends AbstractAgency implements AgencyInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\Column]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_agency_id', initialValue: 1)]
    protected ?int $id = null;

    public function getId(): ?int { return $this->id; }
}
```

**Notes** :
- **Non-`final`** (clients étendent).
- Table `core_<entity>` pour les entités Core, `<module>_<entity>` pour
  les modules (ex: `editorial_posts`, `crm_deals`).
- Sequence nommée `seq_core_<entity>_id` (préfixe `seq_core_` obligatoire).
  Cf [`convention_naming.md`](convention_naming.md).
- Pas de logique métier — uniquement `id` (et ManyToMany éventuels).

### Concrete avec ManyToMany

```php
class Agency extends AbstractAgency implements AgencyInterface
{
    #[ORM\Id] /* … */
    protected ?int $id = null;

    /** @var Collection<int, ServiceInterface> */
    #[ORM\ManyToMany(targetEntity: ServiceInterface::class)]
    #[ORM\JoinTable(name: 'core_agency_services')]
    protected Collection $services;

    public function __construct()
    {
        $this->services = new ArrayCollection();
    }

    public function getServices(): Collection { return $this->services; }
    public function addService(ServiceInterface $service): self { /* … */ }
    public function removeService(ServiceInterface $service): self { /* … */ }
}
```

## resolve_target_entities

Chaque entité instrumentée **doit être inscrite** dans
`AuroraBundle::$resolve_target_entities` :

```php
public function build(ContainerBuilder $container): void
{
    parent::build($container);

    $container->prependExtensionConfig('doctrine', [
        'orm' => [
            'resolve_target_entities' => [
                AgencyInterface::class => Agency::class,
                // … toutes les entités Aurora
            ],
        ],
    ]);
}
```

Permet au client de substituer la concrete via la même interface.

## Sequences

- **Nom** : `seq_core_<entity>_id` ou `seq_<module>_<entity>_id`
- **Préfixe `seq_core_*`** obligatoire pour les entités Core.
- **Préfixe `seq_<module>_*`** pour les modules.
- Pourquoi : éviter les collisions avec des entités client homonymes (un
  client tracking app peut très bien avoir sa propre table `projects`
  avec son propre `seq_project_id`).

Le nom de la sequence est detecté à boot par `SequencePrefixConflictListener`
qui throw si un client réutilise un préfixe `seq_core_*`. Cf
`docs/aurora-core/dev/extending_aurora.md` section "Sequence prefix convention".

## Tables

- Core : `core_<plural>` (ex: `core_agencies`, `core_users`, `core_media`).
- Module : `<module>_<plural>` (ex: `editorial_posts`, `crm_contacts`,
  `billing_invoices`).
- Liaisons ManyToMany : `<module>_<entity1>_<entity2>` (ex:
  `core_agency_services`).

Côté client, préfixe différent : `client_<plural>` ou `app_<plural>`.

## Anti-patterns

- ❌ Logique métier dans l'entité (calculs complexes, validation cross-field,
  appels de service). Garder l'entité minimale, déléguer au Manager/Service.
- ❌ Setter qui ne retourne pas `$this` (casse le chaînage utilisé partout
  dans Aurora : `$entity->setName(...)->setStatus(...)->setReference(...)`).
- ❌ Concrete `final` (empêche l'extension client).
- ❌ Sequence sans préfixe `seq_core_` ou `seq_<module>_` (collision
  potentielle).
