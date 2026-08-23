# Extending Aurora

Aurora is designed to be used as a **core** for client applications.
Each client lives in its own git repository and consumes Aurora as a
**Composer package** (`axelraboit/aurora`).

This document is the contract: it describes **how** clients extend Aurora
without ever modifying vendor files. Anything documented here is considered
part of the public extension surface and won't be broken without a major
version bump.

## Creating a new client project

Start from the `aurora-client` template repository (or clone it). Then pull
in the latest Aurora version:

```bash
composer require axelraboit/aurora:dev-develop
make aurora-update
```

`make aurora-update` installs the Composer package, syncs the Makefile,
`CLAUDE.md` and `.claude/memory/` symlinks from vendor.

## Project structure

Aurora client projects follow the module-based structure of aurora-core:
all code (Aurora extensions and new features alike) lives under `src/Module/`.
The path **mirrors the Aurora namespace** of the entity or feature being extended.

```
client-app/
├── vendor/axelraboit/aurora/   # Aurora core (read-only - never edited directly)
├── src/
│   ├── Module/                 # App\Module\* - ALL client PHP code
│   │   ├── Core/               #   Extensions of Aurora\Core\* entities
│   │   │   └── Agency/         #     Entity/ Dto/ Manager/ Serializer/
│   │   ├── Crm/                #   Extensions of Aurora\Module\Crm\* entities
│   │   └── <Name>/             #   Client-owned feature modules
│   ├── Service/                # App\Service\* - cross-module stateless helpers (rare)
│   └── EventListener/          # App\EventListener\* - global listeners (rare)
├── templates/
│   ├── Core/                   # Overrides for @Core/... templates (auto-resolved before Aurora's)
│   └── Module/<Name>/          # Module-specific templates
├── assets/client/
│   ├── Module/<Name>/          # Vue components for first-party feature modules - exposed as <name>/<rest>
│   └── Overrides/              # Wrappers around Aurora's Vue components - exposed without module prefix
├── migrations/                 # Client-specific Doctrine migrations
├── config/
│   ├── packages/               # doctrine.yaml, twig.yaml, etc. - client overrides
│   └── services.yaml           # Service registration / decorators
├── bin/console                 # Entry point - delegates to Aurora's Kernel
├── public/index.php            # Web entry point
└── .env                        # Client env overrides
```

Rule of thumb: **the client never edits files under `vendor/axelraboit/aurora/`.** All
customisation happens through the extension points below - overrides live
under the matching responsibility folder, never under a generic `Custom/`
bucket. Updating Aurora is then a one-liner (`make aurora-update`).

## How Aurora loads client files

Aurora's `Kernel` automatically detects when it runs as a submodule inside a
`vendor/` directory. When detected, it:

1. Loads `config/packages-custom.yaml` - bundle configuration (Doctrine mappings, etc.)
2. Loads `config/services-custom.yaml` - service definitions
3. Adds `templates/` to Twig's lookup path
4. Loads `config/routes-custom.yaml` - custom routes
5. Exposes `%aurora.client_dir%` as a Symfony parameter pointing to the client root

No manual wiring required - the three config files are the entire contract.

---

## Extension points

### 1. Services

The scaffold registers a single PSR-4 prefix in `config/services.yaml`.
Drop a class anywhere under `src/Module/` and it's auto-wired:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\Module\:
        resource: '../src/Module/'

    App\Service\:
        resource: '../src/Service/'

    App\EventListener\:
        resource: '../src/EventListener/'
```

### 2. Decorating an Aurora service

Use Symfony's `#[AsDecorator]` to swap behaviour without touching core code:

```php
namespace App\Service;

use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator(decorates: ThemeContext::class)]
final class CustomThemeContext extends ThemeContext
{
    public function primaryColor(): string
    {
        return '#ff0066';
    }
}
```

### 3. Event listeners

Hook into Aurora's flow via `#[AsEventListener]`:

```php
namespace App\EventListener;

use Aurora\Module\Ecommerce\Event\OrderCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: OrderCreatedEvent::class)]
final class OrderSubscriber
{
    public function __invoke(OrderCreatedEvent $event): void
    {
        // send confirmation email, notify ERP, etc.
    }
}
```

See `src/*/Event/` in aurora for the full catalog of domain events.

### 4. Routes & controllers

Place controllers under `src/Controller/`. The scaffold writes a route
loader for them in `config/routes.yaml`:

```yaml
client:
    resource: '../src/Controller/'
    type: attribute
```

Routes declared via `#[Route]` attributes on the controller methods are
discovered automatically.

### 5. Twig templates

Aurora ships all its templates co-located under `src/`. To override an
Aurora template, mirror its logical path in your client project:

```
# Aurora template (bundle side)
vendor/aurora/src/Core/templates/Core/backend/layout.html.twig

# Client override - two paths are recognized (either works)
#   1. New (recommended, matches the bundle layout):
src/Core/templates/Core/backend/layout.html.twig
#   2. Legacy (kept for backward compat with pre-move client projects):
templates/Core/backend/layout.html.twig
```

To add new templates (not overrides), place them under
`src/Module/<Name>/templates/` if they belong to a feature module (co-located
with the module's PHP code, like `assets/` and `translations/`) or under
`src/Core/templates/` for cross-cutting templates:

```twig
{# src/Module/Contracts/templates/invoice.html.twig #}
{% extends '@Core/backend/layout.html.twig' %}
```

> Client backward compat: existing client projects that store overrides under
> `templates/Module/<Name>/` or `templates/Core/`, `templates/Shared/` still
> resolve - Aurora registers those legacy paths too for any namespace it
> knows about.

### 6. Custom entities & migrations

The scaffold registers a single `AuroraClient` Doctrine mapping covering all of
`src/Module/`. Drop your entity under the matching module path:

```php
// src/Module/Crm/Contract/Entity/Contract.php
namespace App\Module\Crm\Contract\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_contracts')]
class Contract
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
}
```

(For overriding/extending an Aurora entity rather than creating a fresh one,
see section 6.bis below.)

Then generate and run the migration:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

### 6.bis Substituer une entité Core (ResolveTargetEntity)

Aurora utilise le pattern **ResolveTargetEntity** de Doctrine. Chaque entité
Core extensible expose :

- `Aurora\...\Entity\<Name>Interface` - le contrat public (getters/setters)
- `Aurora\...\Entity\Abstract<Name>` - `MappedSuperclass` Doctrine avec le mapping (sans `id`)
- `Aurora\...\Entity\<Name>` - l'entité concrète, non-`final`, avec `id` + sequence

Pour étendre une entité côté client, **étendez le `MappedSuperclass`** (pattern
Sylius) et déclarez votre propre table - c'est le seul pattern qui marche
proprement avec Doctrine (étendre la classe concrète exigerait un type
d'héritage et un discriminator).

```php
// src/Module/Crm/Deal/Entity/Deal.php
namespace App\Module\Crm\Deal\Entity;

use Aurora\Module\Crm\Deal\Entity\AbstractDeal;
use Aurora\Module\Crm\Deal\Entity\DealInterface;
use Aurora\Module\Crm\Deal\Repository\DealRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DealRepository::class)]
#[ORM\Table(name: 'app_deals')]
class Deal extends AbstractDeal implements DealInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_app_deal_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $customField = null;

    public function getId(): ?int { return $this->id; }
    public function getCustomField(): ?string { return $this->customField; }
    public function setCustomField(?string $value): static { $this->customField = $value; return $this; }
}
```

**Substitution** - dans `config/packages/doctrine.yaml` :

```yaml
doctrine:
    orm:
        resolve_target_entities:
            Aurora\Module\Crm\Deal\Entity\DealInterface: App\Module\Crm\Deal\Entity\Deal
```

**Repository** - réutilisez `Aurora\...\Repository\<Name>Repository` directement
via `#[ORM\Entity(repositoryClass: ...)]`. Toutes les repositories Aurora
étendent `Aurora\Core\Repository\ResolveTargetEntityRepository` qui résout
l'entité concrète à la construction via les class metadata Doctrine - la même
instance de repo querie automatiquement votre table dès que
`resolve_target_entities` route l'interface vers votre classe. Vous n'avez à
créer un repository client que si vous voulez ajouter vos propres méthodes
(auquel cas étendez celui d'Aurora et déclarez-le dans le `repositoryClass`
de votre entité).

À partir de là, toutes les associations Aurora qui pointent `DealInterface`
(ex: `Project::$crmDeal`) résolvent automatiquement vers `App\Module\Crm\Deal\Entity\Deal`.

**Migration de données** - si la table Aurora `core_deals` contient déjà des
lignes (fixtures, données de prod) et que les FK Aurora pointent vers son `id`,
ajoutez à la migration générée un `INSERT INTO app_deals … SELECT … FROM core_deals
core_deals` avant de basculer la contrainte FK. Cf. la migration pilote
`Version20260508123924` côté aurora-client pour un exemple complet.

**Manager / création** - `DealManager::create()` instancie `new Deal()`
(la classe Aurora) par défaut. Pour qu'il instancie votre `App\Module\Crm\Deal\Entity\Deal`,
étendez `DealManager` ou décorez `DealManagerInterface` (cf. section 2).

### 6.ter Étendre toute la pile (DTO + Manager + Serializer + Vue)

Pour ajouter un champ visible/éditable depuis le backoffice (formulaire +
tableau), il faut intervenir sur 5 couches : Entity, DTO, Manager, Serializer
et le composant Vue. Aurora expose des points d'extension pour chacune.

Le module **Agency** sert de pilote complet - voir
[`extending_agency_pilot.md`](./extending_agency_pilot.md) pour la recette
end-to-end : factory `AgencyInputFactoryInterface` (`#[AsAlias]`),
`AgencyManagerInterface` / `AgencySerializerInterface` (décoration),
slots Vue (`extra-headers` / `extra-cells` / `extra-form-fields`) et
override Twig.

**Toutes les entités Aurora avec page backend CRUD sont instrumentées** (26 entités
au total - voir `entity_extensibility_convention.md` section 2.1). Le pattern
Agency s'applique identiquement à chacune.

### 7. Bundle configuration

Any Symfony bundle configuration (Twig globals, rate limiter rules…) goes in
`config/packages-custom.yaml`:

```yaml
twig:
    globals:
        client_name: '%env(APP_NAME)%'
```

### 8. Environment variables

`.env` in the client root is loaded by the runtime. Redefine any Aurora
default here. Common overrides:

- `APP_NAME`, `APP_SECRET`
- `DATABASE_URL`
- `MAILER_DSN`, `MAILER_FROM`, `ADMIN_EMAIL`

Never commit secrets - use `.env.local` (gitignored) for local values.

### 9. Theme & branding

Configure the active theme's `primary_color` in the admin UI - `ThemeContext`
regenerates the full accent palette (50 → 950) from that single seed.

For deeper branding (logo, footer, header text), use the theme config fields
surfaced by `ThemeContext`.

### 10. Sequences de références métier

Aurora génère des références numérotées pour toutes ses entités (`FAC-000001`, `ORD-000001`, etc.)
via `SequenceGenerator`, qui crée une séquence PostgreSQL par préfixe.

Si une entité cliente doit elle aussi avoir des références numérotées, elle doit déclarer
ses propres préfixes - **sans jamais utiliser un préfixe déjà pris par le Core.**

#### Déclarer des préfixes clients

```php
// src/Sequence/ClientSequencePrefixProvider.php
namespace App\Sequence;

use App\Enum\ClientPrefixEnum;
use Aurora\Core\Sequence\SequencePrefixProviderInterface;

final class ClientSequencePrefixProvider implements SequencePrefixProviderInterface
{
    public function values(): array
    {
        return array_column(ClientPrefixEnum::cases(), 'value');
    }

    public function name(): string { return 'My Client App'; }
}
```

```php
// src/Enum/ClientPrefixEnum.php
namespace App\Enum;

enum ClientPrefixEnum: string
{
    case Contract = 'CTRX';
    case Intervention = 'INTX';
}
```

(Add `App\Sequence\:` and `App\Enum\:` PSR-4 prefixes to `services.yaml` if
you create those folders - the scaffold ships with the most common ones
(Controller, Entity, Dto, Manager, Serializer, Service, EventListener) and
you extend the list as needed.)

La classe est auto-taggée via `_instanceof` dès qu'elle implémente `SequencePrefixProviderInterface`.
Aucune configuration supplémentaire.

#### Conflit détecté automatiquement

Si un préfixe client entre en collision avec un préfixe Core (dans les deux sens),
Aurora lève une `LogicException` à la première requête :

```
[Aurora] Sequence prefix conflict: "OFC" is declared by both "Aurora Core"
and "My Client App". Each prefix must be globally unique - rename one of them.
```

Cela se déclenche au boot après un `aurora-update` si le Core a introduit une valeur
déjà utilisée côté client - l'erreur est visible immédiatement, avant la mise en prod.

**Renommer un préfixe déjà en production implique une migration de données** (update de
toutes les colonnes `reference` concernées + renommage de la séquence PostgreSQL). La
prévention vaut mieux que la correction.

#### Préfixes réservés - Aurora Core

Ces valeurs sont définies dans `SequencePrefixEnum` et **ne doivent jamais être utilisées
côté client.** La liste est mise à jour à chaque ajout dans le Core.

| Préfixe | Entité |
|---|---|
| `FAC` | Invoice |
| `AV` | CreditNote |
| `ORD` | Order |
| `PROD` | Product |
| `DEAL` | Deal |
| `CTT` | Contact |
| `CPY` | Company |
| `LST` | Listing |
| `GAL` | Gallery |
| `ART` | Post |
| `FRM` | Form |
| `TRS` | Tiers |
| `USR` | User |
| `MED` | Media |
| `ACR` | AccessRequest |
| `SUB` | FormSubmission |
| `PHO` | GalleryItem |
| `GIV` | GalleryInvite |
| `CMT` | Comment |
| `LOG` | AuditLog |
| `RPR` | ResetPasswordRequest |
| `MFD` | MediaFolder |
| `MNI` | MenuItem |
| `OCR` | OcrJob |
| `CRT` | Cart |
| `CRI` | CartItem |
| `ORL` | OrderLine |
| `FLD` | FormField |
| `TRM` | TaxonomyTerm |
| `GFN` | GalleryFinalization |
| `GIC` | GalleryItemComment |
| `GPK` | GalleryPick |
| `DOC` | GedDocument |
| `PRJ` | Project |
| `TSK` | ProjectTask |
| `PRJC` | ProjectColumn |
| `PLN` | Planning |
| `PEV` | PlanningEvent |

#### Convention de nommage

Pour éviter les conflits futurs, les préfixes clients doivent :

- Être distincts de tous les préfixes Core listés ci-dessus
- Inclure un suffixe ou préfixe propre au projet pour réduire le risque de collision lors d'une mise à jour Core future - ex. : `ACME_CTR` plutôt que `CTR`
- Avoir une longueur ≥ 4 caractères (les préfixes Core courts de 2-3 chars sont dans la zone de danger)

---

## Updating Aurora in a client

```bash
make aurora-update
```

Cette commande fait en séquence : `composer update axelraboit/aurora`, réinstalle
les dépendances npm du vendor, joue les migrations, resync les privileges, le
jsconfig, le security.yaml, le CLAUDE.md et le Makefile.

Breaking changes are listed in Aurora's `CHANGELOG.md` under a `BREAKING:` line.

---

## What is NOT an extension point

The following are internal and may change without notice:

- Private methods of any service
- Internal CSS variable names not prefixed with `--th-*` or `--color-*`
- Database migration internals
- Class names of helpers not under a `*\Contract\` or `*\Api\` namespace
