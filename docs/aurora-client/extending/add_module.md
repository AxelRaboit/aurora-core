# Créer un module client

Guide pour scaffolder un nouveau module dans un projet aurora-client (qui
consomme `axelraboit/aurora` via composer). Un module client vit entièrement
sous `src/Module/<Module>/` avec namespace `App\Module\<Module>\`.

> **Pendant côté core** : [`docs/aurora-core/dev/add_module.md`](../../aurora-core/dev/add_module.md)
> — même philosophie, mêmes patterns, namespace `Aurora\Module\` et
> sequences `seq_core_*`.
>
> **Mémoires aurora-client** (lire d'abord) :
> - [`convention_module_structure.md`](../../../.claude/memory/aurora-client/convention_module_structure.md) — structure `src/Module/<Module>/`
> - [`convention_table_naming.md`](../../../.claude/memory/aurora-client/convention_table_naming.md) — tables `app_*`, sequences `seq_app_*`
> - [`pattern_add_module_toggle.md`](../../../.claude/memory/aurora-client/pattern_add_module_toggle.md) — toggles + context class
> - [`pattern_add_custom_permissions.md`](../../../.claude/memory/aurora-client/pattern_add_custom_permissions.md) — permissions custom
> - [`pitfall_instanceof_scoping.md`](../../../.claude/memory/aurora-client/pitfall_instanceof_scoping.md) — pourquoi le `_instanceof` côté client n'hérite pas du core
> - Pour étendre une entité Aurora plutôt que créer un module client : voir
>   [`extending_aurora.md`](../../aurora-core/dev/extending_aurora.md)

Le guide utilise un module d'exemple **Tracking** pour illustrer chaque étape.
C'est un exemple **générique** — il n'est pas (forcément) présent dans ton
projet ; les chemins `src/Module/Tracking/…` montrent simplement *où* le code
irait si tu suivais l'exemple.

---

## 1. Quand créer un module client ?

Un **module client** = un domaine métier propre au projet, **pas** déjà
couvert par aurora-core. Cinq cas types qui se cumulent :

| Cas | Quand | Couvert §|
|---|---|---|
| **1. Module minimal** | 1 controller + 1 UI, pas d'entité | §3 |
| **2. Toggles + Context** | Plusieurs sous-features activables séparément | §4 |
| **3. Avec entités CRUD** | Persistance Doctrine | §5 |
| **4. Avec frontend public** | Pages publiques (pas que back-office) | §6 |
| **5. Avec settings** | Onglet dans la page admin Settings | §7 |

Si la feature **étend une entité Aurora existante** (ex : ajouter un champ à
`Agency`), ce n'est PAS un module client mais une **extension** — voir
[`extending_aurora.md`](../../aurora-core/dev/extending_aurora.md) et
les patterns `pattern_extend_*` dans `.claude/memory/aurora-client/`.

---

## 2. Wiring obligatoire côté client (à faire une seule fois)

Le client doit **dupliquer** certains mécanismes d'auto-discovery
qu'aurora-core fournit en interne — ils ne traversent pas les frontières du
bundle. Vérifier ces 3 blocs dans le projet **avant** de scaffolder un module :

### 2.1 `config/services.yaml` — `_instanceof` mirroring

```yaml
# config/services.yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    # Mirror aurora-core's `_instanceof` autoconfig. `_instanceof` scopes to the
    # current YAML file only — it does NOT inherit across bundle service configs.
    # Sans ce bloc, les modules client implémentant les interfaces marker du core
    # ne seraient jamais ramassés par les registries correspondantes.
    _instanceof:
        Aurora\Core\Module\Contract\ModuleInterface:
            tags: [aurora.module]
        Aurora\Core\Frontend\Contract\FrontendInterface:
            tags: [aurora.front]

    App\Module\:
        resource: '../src/Module/'
```

> ⚠️ Piège documenté : [`pitfall_instanceof_scoping.md`](../../../.claude/memory/aurora-client/pitfall_instanceof_scoping.md).
> Le `_instanceof` d'aurora-core ne s'applique qu'aux services définis dans
> `vendor/axelraboit/aurora/config/services.yaml`. Sans ce bloc côté client,
> ton `TrackingModule` ne sera **jamais tagué `aurora.module`**.

### 2.2 `config/packages/twig.yaml` — chemin Twig manuel par module

```yaml
# config/packages/twig.yaml
twig:
    paths:
        '%kernel.project_dir%/templates/Core': 'Core'
        '%kernel.project_dir%/src/Module/Tracking/templates': 'Tracking'
        # ajouter une ligne par module client
```

> Aurora-core auto-globe ses propres templates via `AuroraBundle.php`, mais le
> client n'a pas cette auto-discovery. **Chaque module client doit déclarer
> son namespace Twig** ici (sinon `@Tracking/...` ne se résout pas).

### 2.3 `config/services.yaml` — `DumpJsTranslationsCommand` `$extraSourceDirs`

```yaml
services:
    Aurora\Module\Configuration\Setting\Command\DumpJsTranslationsCommand:
        arguments:
            $auroraDir: '%kernel.project_dir%/vendor/axelraboit/aurora'
            $extraSourceDirs:
                - '%kernel.project_dir%/src/Module/Tracking/translations'
                # ajouter une ligne par module client
```

> Même logique que Twig : aurora-core auto-globe `src/Module/*/translations/`
> mais uniquement dans son propre arbre. Côté client, déclarer chaque dossier
> de traduction module.
> Nom de classe **exact** : `Aurora\Module\Configuration\Setting\Command\DumpJsTranslationsCommand`
> (pas `App\Core\Command\...`). Paramètre nommé `$extraSourceDirs`.

---

## 3. Scaffolding rapide avec `aurora:make:module`

**Le chemin recommandé** pour créer un nouveau module client. La commande
détecte automatiquement le contexte (lit `composer.json`) et génère le
squelette **Cas 1 + Cas 2 (toggle backend par défaut)** avec le namespace
`App\Module\<X>\`, sequences `seq_app_*`, et les 3 fichiers de config
auto-patchés.

### 3.1 Utilisation

```bash
# Module togglable minimal (cas 1 + 2 — défaut)
php bin/console aurora:make:module Loyalty

# Avec frontend public
php bin/console aurora:make:module Loyalty --with-frontend

# Avec onglet Settings dans /backend/configuration/settings
php bin/console aurora:make:module Loyalty --with-settings

# Tout combiné
php bin/console aurora:make:module Loyalty --with-frontend --with-settings

# Module infra-only (toujours actif, pas de toggle backend)
php bin/console aurora:make:module DevTools --no-toggle
```

| Flag | Effet |
|---|---|
| _(aucun)_ | Cas 1 + Cas 2 : `<X>Module.php` + `<X>Context.php` avec `BACKEND_KEY = 'app_<x>_backend'`, togglable via le panel admin "Modules access" |
| `--no-toggle` | Opt-out du toggle backend (réservé infra type Dev) |
| `--with-frontend` | Cas 4 : ajoute `<X>FrontendDescriptor.php` |
| `--with-settings` | Cas 5 : ajoute `Setting/<X>SettingEnum.php` + `<X>ConfigurationTabProvider.php` |

### 3.2 Fichiers générés (défaut, cas 1 + 2)

```
src/Module/<Module>/<Module>Module.php           # ModuleInterface + ModuleToggleProviderInterface
src/Module/<Module>/<Module>Context.php           # BACKEND_KEY + isBackendEnabled()
src/Module/<Module>/Controller/Backend/<Module>Controller.php
src/Module/<Module>/templates/backend/index.html.twig
src/Module/<Module>/translations/messages.{fr,en}.yaml
src/Module/<Module>/assets/backend/<Module>App.vue
```

### 3.3 Auto-patches de config (client only)

Le maker patche **3 fichiers de config** parce qu'aurora-core ne peut pas
auto-découvrir les modules client (son glob ne voit que
`vendor/.../src/Module/*`) :

| Fichier | Ce que le maker ajoute | À quoi ça sert |
|---|---|---|
| `config/packages/twig.yaml` | `'%kernel.project_dir%/src/Module/<X>/templates': '<X>'` sous `twig.paths` | namespace Twig `@<X>` (sans ça : `LoaderError: No registered paths for namespace "<X>"`) |
| `config/packages/framework.yaml` | `- '%kernel.project_dir%/src/Module/<X>/translations'` sous `framework.translator.paths` | Symfony Translator (utilisé par Twig `\|trans`) — sans ça : `backend.nav.<x>` rendu en clé brute |
| `config/services.yaml` | même chemin sous `DumpJsTranslationsCommand.$extraSourceDirs` | vue-i18n côté JS (cf. section 2.3) |

> ✓ Ces 3 entrées sont les sections 2.2, 2.3 + un nouveau patch pour Symfony
> Translator (qui manquait à Tracking jusqu'à 0.5). Les blocs YAML en section
> 2 restent la **référence canonique** si tu dois reconstituer un projet
> client sans le maker (e.g. portage manuel).

### 3.4 Prompts interactifs

Sans `--no-interaction`, deux confirms + deux inputs textuels :
- **Public-facing pages?** → équivalent à `--with-frontend`
- **Own tab in /backend/configuration/settings?** → équivalent à `--with-settings`
- **Display label** (défaut = nom du module en PascalCase) — texte libre nav
- **NavSection priority** (défaut = 60) — plus bas = plus haut dans le sidemenu

L'icône du NavItem est **hardcodée à `flame`** (Lucide). Change la string
dans `<X>Module.php` après scaffold si tu veux autre chose, en piochant
dans
`vendor/.../src/Core/assets/backend/sidemenu/composables/useSidemenuNav.js`
ICON_MAP (~33 icônes pré-importées — toute icône hors map → fallback FileText).

### 3.5 Next steps après scaffold

Le maker imprime à la fin un récap de commandes à lancer **sans
quoi le module ne sera pas opérationnel** :

```bash
make sf CMD="aurora:privileges:sync"         # enregistre la permission <module>.use
make sf CMD="aurora:menus:sync"              # enregistre le NavItem
make translation                             # dump JSON traductions pour vue-i18n
make cc                                      # clear cache (Twig + Symfony)
```

### 3.6 Limites de la commande

- **Refondre un module existant** : la commande refuse si
  `src/Module/<X>/` existe déjà — édition manuelle nécessaire (ou
  supprimer le module avant de relancer le scaffold).

### 3.7 Étapes complémentaires après scaffold

Le maker ne couvre **que** Cas 1, 2, 4, 5 — pas le contenu métier. Pour
aller plus loin :

- **Ajouter une entité CRUD** (cas 3 dans ce doc) : lancer `/add-entity`
  (skill Claude Code) en ciblant le module fraîchement scaffolddé.
- **Ajouter une sous-feature togglable** à un module existant (pattern
  Vault.Safe + Vault.PasswordGenerator) : lancer `/add-submodule` plutôt
  que de re-scaffolder.

---

## 4. Cas 1 — module minimal (stateless)

### 4.1 `<Module>Module.php` — squelette minimal

```php
// src/Module/MyModule/MyModuleModule.php
namespace App\Module\MyModule;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavPermission;
use Aurora\Core\Module\Nav\NavSection;

final readonly class MyModuleModule implements ModuleInterface
{
    public function getId(): string { return 'my_module'; }

    public function getPermissions(): array
    {
        return [new NavPermission('my_module.use')];
    }

    public function getNavSections(): array
    {
        return [
            new NavSection('my_module', [
                new NavItem('backend_my_module', 'backend.nav.my_module', 'flame',
                    requiredPrivilege: 'my_module.use'),
            ], priority: 60),
        ];
    }

    public function getCatalogNavSections(): array
    {
        return $this->getNavSections();   // mêmes items même quand le module est désactivé
    }
}
```

**Signatures à respecter scrupuleusement** (vérifiées dans
`vendor/axelraboit/aurora/src/Core/Module/Nav/` et `Contract/`) :

- `ModuleInterface` (4 méthodes **obligatoires**) :
  `getId(): string`, `getPermissions(): array`, `getNavSections(): array`,
  `getCatalogNavSections(): array`. Si tu oublies `getCatalogNavSections()`,
  PHP refuse de charger la classe.
- `NavItem(string $route, string $labelKey, string $icon, ?string $requiredPrivilege = null, ...)` :
  `route`, `labelKey`, `icon` sont **positionnels et obligatoires**.
  `requiredPrivilege` est un named param optionnel.
- `NavSection(string $id, array $items, int $priority = 100)` :
  pas de `label`, pas de `icon` sur la section — uniquement `id`, `items`, `priority`.
- `NavPermission(string $name)` : la permission string utilisée par
  `#[IsGranted]` côté controller.

### 4.2 Synchroniser permissions + menus

Une fois le module créé (ou modifié), synchroniser :

```bash
make sf CMD="aurora:privileges:sync"
make sf CMD="aurora:menus:sync"
```

- `aurora:privileges:sync` crée les rows `core_permissions` à partir des
  `NavPermission` retournés par `getPermissions()`
- `aurora:menus:sync` met à jour la table `core_menu_items` à partir des
  `NavSection`/`NavItem`

À relancer à chaque ajout/suppression de permission ou de NavItem.

### 4.3 Controller

```php
// src/Module/MyModule/Controller/Backend/MyModuleController.php
namespace App\Module\MyModule\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/my-module', name: 'backend_my_module')]
#[IsGranted('my_module.use')]
final class MyModuleController extends AbstractController
{
    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        return $this->render('@MyModule/backend/index.html.twig');
    }
}
```

- `final class` (pas `final readonly` — `setContainer()` est appelé après
  `__construct`).
- Route prefix `/backend/<kebab-case>`, name `backend_<snake_case>` (pattern
  identique au core).

### 4.4 Template Twig

```twig
{# src/Module/MyModule/templates/backend/index.html.twig #}
{% extends '@Core/backend/layout.html.twig' %}

{% block title %}{{ 'backend.nav.my_module'|trans }} - {{ parent() }}{% endblock %}

{% block body %}
    <div {{ vue_component('mymodule/backend/MyModuleApp', {}) }} class="flex-1 min-w-0"></div>
{% endblock %}
```

**Convention `backend/` (PAS `admin/`)** : harmonisation avec aurora-core
(voir templates `Vault`, `Editorial`, `Notes` côté vendor). Tout le reste de
la convention de nommage (`vue_component(<lowercase_module>/<path>)`) est
identique au core.

### 4.5 Composant Vue

```
src/Module/MyModule/assets/backend/MyModuleApp.vue
```

Le Vue est **co-localisé** avec le code PHP du module sous `src/Module/<X>/assets/`,
en miroir du layout aurora-core (depuis aurora-client commit `9d77f67` —
`refactor(assets): co-locate client extensions under src/, drop assets/`).
Vite résout l'alias `@<kebab>` vers ce dossier via `aliases.js` (vendor) +
`jsconfig.json` (auto-généré par `make sync-jsconfig`).

Utiliser systématiquement les composants `App*` partagés de
`@shared/components/` plutôt que `<button>` / `<input>` bruts.

### 4.6 Traductions

```yaml
# src/Module/MyModule/translations/messages.fr.yaml
backend:
    modules:
        my_module: Mon module
    nav:
        my_module: Mon module
        my_module_description: Description courte (tooltip)

my_module:
    title: Mon module
    # ... clés UI
```

Puis :

```bash
make translation
```

> N'oublie pas d'ajouter le dossier dans `$extraSourceDirs` (cf. §2.3).

---

## 5. Cas 2 — module avec toggles + Context class

Quand le module a plusieurs sous-features activables séparément (backend on/off,
frontend on/off, etc.), suivre le pattern **`ModuleToggleProviderInterface`
+ `<Module>Context`**. Les snippets ci-dessous déroulent le module d'exemple
`Tracking` (générique, non fourni).

### 5.1 `<Module>Context` — façade ModuleAccessChecker

```php
// src/Module/Tracking/Service/TrackingContext.php
namespace App\Module\Tracking\Service;

use Aurora\Core\Module\Service\ModuleAccessChecker;

/**
 * Façade fine sur ModuleAccessChecker pour les toggles de Tracking.
 * Tous les consommateurs (route gate, nav builder, controllers) passent par
 * ce service pour appliquer la résolution global + per-user + cascade.
 */
final readonly class TrackingContext
{
    public const string BACKEND_KEY  = 'app_tracking_backend';
    public const string PROJECTS_KEY = 'app_tracking_projects';
    public const string FRONTEND_KEY = 'app_tracking_frontend';

    public function __construct(private ModuleAccessChecker $moduleAccessChecker) {}

    public function isBackendEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(self::BACKEND_KEY);
    }

    public function isProjectsEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(self::PROJECTS_KEY);
    }

    public function isFrontendEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(self::FRONTEND_KEY);
    }
}
```

**Convention clés** :
- Préfixe `app_` (jamais `core_` qui est réservé aux toggles aurora-core)
- Format `app_<module>_<feature>` (pas `_enabled` à la fin)
- Constantes publiques sur la classe Context pour ré-utiliser depuis
  `getToggles()` et les services consommateurs

### 5.2 `<Module>Module` avec toggles + nav conditionnel

```php
// src/Module/Tracking/TrackingModule.php
namespace App\Module\Tracking;

use App\Module\Tracking\Service\TrackingContext;
use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavPermission;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Core\Module\Toggle\ModuleToggle;

final readonly class TrackingModule implements ModuleInterface, ModuleToggleProviderInterface
{
    public function __construct(private TrackingContext $trackingContext) {}

    public function getId(): string { return 'tracking'; }

    public function getPermissions(): array
    {
        return [
            new NavPermission('tracking.projects.view'),
            new NavPermission('tracking.projects.create'),
            new NavPermission('tracking.projects.edit'),
            new NavPermission('tracking.projects.delete'),
        ];
    }

    public function getNavSections(): array
    {
        if (!$this->trackingContext->isBackendEnabled()) {
            return [];
        }

        $items = [];
        if ($this->trackingContext->isProjectsEnabled()) {
            $items[] = new NavItem('tracking_projects', 'backend.nav.projects', 'flame',
                requiredPrivilege: 'tracking.projects.view');
        }

        return [] === $items ? [] : [new NavSection('tracking', $items, priority: 60)];
    }

    /** Retourne TOUS les items même si module désactivé — pour le picker catalogue. */
    public function getCatalogNavSections(): array
    {
        return [
            new NavSection('tracking', [
                new NavItem('tracking_projects', 'backend.nav.projects', 'flame',
                    requiredPrivilege: 'tracking.projects.view'),
            ], priority: 60),
        ];
    }

    public function getToggles(): array
    {
        return [
            new ModuleToggle(
                key: TrackingContext::BACKEND_KEY,
                labelKey: 'backend.modules.tracking',
                descriptionKey: 'backend.modules.tracking_description',
                moduleId: 'tracking',
            ),
            new ModuleToggle(
                key: TrackingContext::PROJECTS_KEY,
                labelKey: 'backend.nav.projects',
                descriptionKey: 'backend.nav.projects_description',
                parentKey: TrackingContext::BACKEND_KEY,
            ),
            new ModuleToggle(
                key: TrackingContext::FRONTEND_KEY,
                labelKey: 'backend.modules.tracking_frontend',
                descriptionKey: 'backend.modules.tracking_frontend_description',
                parentKey: TrackingContext::BACKEND_KEY,
            ),
        ];
    }
}
```

**Points clés** :
- `ModuleToggleProviderInterface` est **optionnel** — uniquement si tu veux
  exposer ton module dans la page admin Settings + permettre le disable
  per-user via `core_users.disabled_modules`
- `ModuleToggle.parentKey` matérialise la cascade : `PROJECTS_KEY` est OFF
  si `BACKEND_KEY` est OFF (globalement ou per-user)
- `moduleId` (sur le toggle root du module) lie le toggle à l'identifiant du
  module pour le picker UI

Doc référence : [`pattern_add_module_toggle.md`](../../../.claude/memory/aurora-client/pattern_add_module_toggle.md).

---

## 6. Cas 3 — module avec entités CRUD (convention 5 couches)

Pour la persistance Doctrine + UI CRUD, suivre la **même convention 5
couches Sylius-style** qu'aurora-core ([§ canonique](../../aurora-core/dev/entity_extensibility_convention.md)) :
Interface + Abstract + concrete non-final → DTO Interface + Factory →
Manager Interface + hooks `protected` → Serializer → Vue.

Le client peut **omettre l'Abstract** (pas de plan d'extension par d'autres
consommateurs), mais doit **garder l'Interface + Factory** parce que c'est
le bon pattern long terme (préférence "penser long terme" — voir CLAUDE.md
§3bis).

### 6.1 Entity + Interface

```php
// src/Module/Tracking/Project/Entity/ProjectInterface.php
namespace App\Module\Tracking\Project\Entity;

interface ProjectInterface
{
    public function getId(): ?int;
    public function getTitle(): string;
    // ... getters
}
```

```php
// src/Module/Tracking/Project/Entity/Project.php
namespace App\Module\Tracking\Project\Entity;

use App\Module\Tracking\Project\Repository\ProjectRepository;
use Aurora\Core\Timestampable\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'projects')]               // pas de préfixe app_ pour les tables
#[ORM\HasLifecycleCallbacks]
class Project implements ProjectInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_app_tracking_project_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $title;

    // ... colonnes
}
```

**Conventions de naming** (cf.
[`convention_table_naming.md`](../../../.claude/memory/aurora-client/convention_table_naming.md)) :
- **Tables** : nom simple (`projects`, `invoices`) — pas de préfixe
  `client_` ni `app_` côté table. Aurora-core utilise `core_*` pour ses
  tables, le client peut donc utiliser n'importe quoi qui ne commence pas
  par `core_`.
- **Sequences** : préfixe `seq_app_<module>_<entity>_id` (obligatoire pour
  éviter collisions avec aurora-core qui utilise `seq_core_*`).
- **`class` non-`final`** : permet l'extension future, même si pas
  immédiatement utilisée.

### 6.2 DTO 5 couches (Interface + Factory + Input)

```php
// src/Module/Tracking/Project/Dto/ProjectInputInterface.php
namespace App\Module\Tracking\Project\Dto;

interface ProjectInputInterface
{
    public function getTitle(): string;
    // ... getters
}
```

```php
// src/Module/Tracking/Project/Dto/ProjectInput.php
namespace App\Module\Tracking\Project\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ProjectInput implements ProjectInputInterface
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 150)]
        public readonly string $title,
        // ... autres props readonly
    ) {}

    public function getTitle(): string { return $this->title; }
}
```

```php
// src/Module/Tracking/Project/Dto/ProjectInputFactoryInterface.php
namespace App\Module\Tracking\Project\Dto;

interface ProjectInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): ProjectInputInterface;
}
```

```php
// src/Module/Tracking/Project/Dto/ProjectInputFactory.php
namespace App\Module\Tracking\Project\Dto;

use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ProjectInputFactoryInterface::class)]
class ProjectInputFactory implements ProjectInputFactoryInterface
{
    public function fromArray(array $data): ProjectInputInterface
    {
        return new ProjectInput(
            title: Str::trimFromArray($data, 'title'),
            // ... mapping data → typed props
        );
    }
}
```

**Pourquoi pas `final readonly class` global** : si un jour quelqu'un veut
étendre le DTO pour ajouter un champ custom (cf. extension par d'autres
projets ou par toi-même plus tard), le `readonly` global empêche l'enfant
d'avoir des props mutables. Préférer `public readonly` par propriété.

Doc référence : [`pattern_extend_dto.md`](../../../.claude/memory/aurora-client/pattern_extend_dto.md).

### 6.3 Manager avec hooks `protected`

```php
// src/Module/Tracking/Project/Manager/ProjectManagerInterface.php
namespace App\Module\Tracking\Project\Manager;

use App\Module\Tracking\Project\Dto\ProjectInputInterface;
use App\Module\Tracking\Project\Entity\ProjectInterface;

interface ProjectManagerInterface
{
    public function create(ProjectInputInterface $input): ProjectInterface;
    public function update(ProjectInterface $project, ProjectInputInterface $input): void;
    public function delete(ProjectInterface $project): void;
}
```

```php
// src/Module/Tracking/Project/Manager/ProjectManager.php
namespace App\Module\Tracking\Project\Manager;

use App\Module\Tracking\Project\Dto\ProjectInputInterface;
use App\Module\Tracking\Project\Entity\Project;
use App\Module\Tracking\Project\Entity\ProjectInterface;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ProjectManagerInterface::class)]
class ProjectManager implements ProjectManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function create(ProjectInputInterface $input): ProjectInterface
    {
        $project = $this->createProject();                  // hook
        $this->applyInput($project, $input);                // hook
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        $this->auditCreated($project);                      // hook
        return $project;
    }

    public function update(ProjectInterface $project, ProjectInputInterface $input): void
    {
        $this->applyInput($project, $input);
        $this->entityManager->flush();
        $this->auditUpdated($project);
    }

    public function delete(ProjectInterface $project): void
    {
        $this->auditDeleted($project);
        $this->entityManager->remove($project);
        $this->entityManager->flush();
    }

    // --- Hooks protected (override-friendly) ---

    protected function createProject(): Project { return new Project(); }

    protected function applyInput(ProjectInterface $project, ProjectInputInterface $input): void
    {
        $project->setTitle($input->getTitle());
        // ... mapping
    }

    protected function auditCreated(ProjectInterface $project): void
    {
        $this->auditLogger->log('tracking', 'project.created', 'Project',
            $project->getId(), $this->auditPayload($project));
    }

    protected function auditUpdated(ProjectInterface $project): void { /* idem */ }
    protected function auditDeleted(ProjectInterface $project): void { /* idem */ }

    protected function auditPayload(ProjectInterface $project): array
    {
        return ['title' => $project->getTitle()];
    }
}
```

**Règles dures** :
- `protected readonly` sur les dépendances (pas `private`) — sinon un manager
  enfant ne peut pas y accéder
- `#[AsAlias(<Interface>::class)]` sur l'implémentation — permet
  l'override via décoration sans patcher le code
- Hook `createProject()` **obligatoire** pour chaque classe que le manager
  instancie (cf. [`pitfall_create_hook_required.md`](../../../.claude/memory/aurora-client/pitfall_create_hook_required.md))
- Si tu override `applyInput()` dans un enfant, n'oublie pas
  `parent::applyInput()` (cf. [`pitfall_call_parent_apply_input.md`](../../../.claude/memory/aurora-client/pitfall_call_parent_apply_input.md))

### 6.4 Repository

```php
// src/Module/Tracking/Project/Repository/ProjectRepository.php
namespace App\Module\Tracking\Project\Repository;

use App\Module\Tracking\Project\Entity\Project;
use App\Module\Tracking\Project\Entity\ProjectInterface;
use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Repository\Trait\PaginationTrait;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ResolveTargetEntityRepository<ProjectInterface> */
class ProjectRepository extends ResolveTargetEntityRepository
{
    use PaginationTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class, ProjectInterface::class);
    }

    public function findPaginated(int $page, int $limit = 20, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('p')->orderBy('p.createdAt', 'DESC');
        // ... build query
        return $this->paginate($qb, $page, $limit);
    }
}
```

`ResolveTargetEntityRepository` (jamais `ServiceEntityRepository` directement)
— même côté client, le pattern est de tracer l'interface dès le départ pour
permettre une éventuelle substitution future.

Doc référence : [`pattern_extend_repository.md`](../../../.claude/memory/aurora-client/pattern_extend_repository.md).

### 6.5 Serializer

```php
// src/Module/Tracking/Project/Serializer/ProjectSerializerInterface.php
namespace App\Module\Tracking\Project\Serializer;

use App\Module\Tracking\Project\Entity\ProjectInterface;

interface ProjectSerializerInterface
{
    public function toArray(ProjectInterface $project): array;
}
```

```php
// src/Module/Tracking/Project/Serializer/ProjectSerializer.php
namespace App\Module\Tracking\Project\Serializer;

use App\Module\Tracking\Project\Entity\ProjectInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ProjectSerializerInterface::class)]
class ProjectSerializer implements ProjectSerializerInterface
{
    public function toArray(ProjectInterface $project): array
    {
        return [
            'id'    => $project->getId(),
            'title' => $project->getTitle(),
            // ...
        ];
    }
}
```

### 6.6 Controller

```php
// src/Module/Tracking/Project/Controller/Backend/ProjectsController.php
namespace App\Module\Tracking\Project\Controller\Backend;

use App\Module\Tracking\Project\Dto\ProjectInputFactoryInterface;
use App\Module\Tracking\Project\Manager\ProjectManagerInterface;
use App\Module\Tracking\Project\Serializer\ProjectSerializerInterface;
use App\Module\Tracking\Project\View\ProjectsViewBuilder;
use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Core\Validation\Service\PayloadValidator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/tracking/projects', name: 'tracking_projects')]
#[IsGranted('tracking.projects.view')]
final class ProjectsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        // Type-hint les INTERFACES partout — permet la substitution via AsAlias
        private readonly ProjectSerializerInterface $projectSerializer,
        private readonly ProjectManagerInterface $projectManager,
        private readonly ProjectInputFactoryInterface $projectInputFactory,
        private readonly PayloadValidator $payloadValidator,
        private readonly ProjectsViewBuilder $viewBuilder,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(PaginationRequest $pagination): Response
    {
        return $this->render('@Tracking/backend/projects/index.html.twig',
            $this->viewBuilder->indexView($pagination));
    }

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('tracking.projects.create')]
    public function create(Request $request): JsonResponse
    {
        $input = $this->projectInputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }
        $project = $this->projectManager->create($input);
        return $this->json(['id' => $project->getId()]);
    }

    // ... update/delete/list
}
```

**Règles** :
- `final class` (controllers Symfony n'acceptent pas `readonly`)
- Type-hint les **interfaces**, pas les classes concrètes (sinon le client
  d'un client ne peut pas substituer via `#[AsAlias]`)
- Traits `JsonRequestTrait` + `JsonResponseTrait` fournis par aurora-core
  pour les helpers JSON (`decodeJson`, `jsonInvalidInput`, etc.)
- `PaginationRequest` (DTO Aurora) auto-résolu via Symfony ParamConverter
  pour récupérer `page`, `search`, etc. depuis la query string

### 6.7 ViewBuilder (pattern admin)

`<Module>/<Entity>/View/<Entity>ViewBuilder.php` : helper qui prépare les
payloads pour les templates index + endpoints list. Découple la logique de
construction de vue du controller.

```php
// src/Module/Tracking/Project/View/ProjectsViewBuilder.php (squelette)
final readonly class ProjectsViewBuilder
{
    public function __construct(
        private ProjectRepository $repository,
        private ProjectSerializerInterface $serializer,
    ) {}

    public function indexView(PaginationRequest $pagination): array
    {
        return [
            'initialItems' => $this->buildListPayload($pagination),
            // métadonnées pour le composant Vue racine
        ];
    }

    public function buildListPayload(PaginationRequest $pagination): array
    {
        $result = $this->repository->findPaginated(
            $pagination->page, $pagination->limit, $pagination->search
        );
        return [
            'items' => array_map([$this->serializer, 'toArray'], $result['items']),
            'meta'  => $result['meta'],
        ];
    }
}
```

### 6.8 Migration Doctrine

```bash
make migration && make migrate
```

Génère + applique le schéma. Le nom de la table viendra de l'attribut
`#[ORM\Table(name: '…')]` sur l'entité.

---

## 7. Cas 4 — module avec frontend public

Si le module expose des pages publiques (pas que back-office), créer
**`<Module>FrontendDescriptor.php`** à la racine du module. Tagué
automatiquement `aurora.front` via le `_instanceof` (§2.1).

```php
// src/Module/Tracking/TrackingFrontendDescriptor.php
namespace App\Module\Tracking;

use App\Module\Tracking\Service\TrackingContext;
use Aurora\Core\Frontend\Contract\FrontendInterface;

final class TrackingFrontendDescriptor implements FrontendInterface
{
    public function getSlug(): string             { return 'tracking'; }
    public function getLabel(): string            { return 'Suivi'; }
    public function getHomeRoute(): string        { return 'tracking_frontend_home'; }
    public function getPriority(): int            { return 5; }
    public function getModuleSettingKey(): string { return TrackingContext::FRONTEND_KEY; }
    public function getRoutePrefixes(): array     { return ['tracking_frontend_']; }
}
```

- `getModuleSettingKey()` pointe vers la clé du toggle frontend (cf. §4) —
  `FrontendRouteGateSubscriber` 404 automatiquement toutes les routes
  matchant `getRoutePrefixes()` quand le toggle est OFF.
- Tagué auto via `_instanceof: Aurora\Core\Frontend\Contract\FrontendInterface:
  tags: [aurora.front]` — déjà déclaré dans `config/services.yaml` (§2.1).

Mémoire référence : [`pattern_frontend_descriptor.md`](../../../.claude/memory/aurora-core/architecture/pattern_frontend_descriptor.md).

---

## 8. Cas 5 — module avec settings (onglet Configuration)

Pour contribuer un onglet à la page admin Settings, implémenter
**`ConfigurationTabProviderInterface`** (pattern identique au core) :

```php
// src/Module/Tracking/Setting/TrackingConfigurationTabProvider.php
namespace App\Module\Tracking\Setting;

use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;
use Aurora\Module\Configuration\Setting\Configuration\SettingFieldDescriptor;

final readonly class TrackingConfigurationTabProvider implements ConfigurationTabProviderInterface
{
    public function getTabs(): array
    {
        // Construire les ConfigurationTab à partir d'un TrackingSettingEnum
        // (mêmes conventions que CrmConfigurationTabProvider côté core)
    }
}
```

Le bloc `_instanceof` du `services.yaml` doit aussi inclure ce tag si le
client veut contribuer aux settings :

```yaml
_instanceof:
    Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface:
        tags: [aurora.configuration_tab_provider]
```

Mémoire référence : [`pattern_configuration_tab_provider.md`](../../../.claude/memory/aurora-core/architecture/pattern_configuration_tab_provider.md).

---

## 9. Checklist finale — module complet

Pour un module client **avec entités CRUD + toggles + frontend public** (cas
le plus complet, comme le module d'exemple `Tracking`) :

> **Raccourci** : `php bin/console aurora:make:module <Module> --with-frontend --with-settings`
> couvre les étapes 2, 3, 11, 12, 13, 14, 15 + auto-patche les 3 fichiers de
> config de l'étape 1 (twig.yaml + framework.yaml + services.yaml). Cf.
> section 3 plus haut.

1. [ ] §2 — `services.yaml` `_instanceof` + `App\Module\` resource (one-time
   per project ; les paths twig/translations sont auto-patchés par le maker)
2. [ ] `<Module>Module.php` (4 méthodes `ModuleInterface` +
   `ModuleToggleProviderInterface` par défaut)
3. [ ] `<Module>Context.php` (à la racine du folder du module — pas sous
   `Service/` depuis 0.4)
4. [ ] Entité `Entity/<Name>Interface` + `<Name>` non-final avec sequence
   `seq_app_<module>_<entity>_id`
5. [ ] DTO 5 couches : Input + Interface + Factory + Interface
6. [ ] Manager : Interface + class non-final + `#[AsAlias]` + hooks `protected`
7. [ ] Repository extends `ResolveTargetEntityRepository` (avec Interface)
8. [ ] Serializer : Interface + class + `#[AsAlias]`
9. [ ] Controller `Backend/` : `final`, type-hint **interfaces**
10. [ ] `View/<Name>ViewBuilder.php` (helper templates + payloads list)
11. [ ] Template `src/Module/<Module>/templates/backend/<entity>/index.html.twig`
12. [ ] Vue : `src/Module/<Module>/assets/backend/<Name>App.vue` (co-localisé)
13. [ ] Traductions `src/Module/<Module>/translations/messages.{fr,en}.yaml`
14. [ ] `<Module>FrontendDescriptor.php` (si front public)
15. [ ] `Setting/<Module>ConfigurationTabProvider.php` (si settings)
16. [ ] `make migration && make migrate` — schéma DB
17. [ ] `make sf CMD="aurora:privileges:sync"` — permissions enregistrées
18. [ ] `make sf CMD="aurora:menus:sync"` — menus mis à jour
19. [ ] `make translation` — dump JSON des traductions
20. [ ] `make ft` vert (fix code + tous les tests)

---

## 10. Pièges connus

- **`_instanceof` ne traverse pas les bundles** : si tu oublies le bloc
  `_instanceof` dans `config/services.yaml` côté client, ton module ne sera
  jamais tagué `aurora.module` et n'apparaîtra pas dans la nav.
  Cf. [`pitfall_instanceof_scoping.md`](../../../.claude/memory/aurora-client/pitfall_instanceof_scoping.md).
- **`getCatalogNavSections()` oublié** : PHP refuse de charger le module
  (méthode abstraite non implémentée). Toujours 4 méthodes sur
  `ModuleInterface`.
- **Sequence sans préfixe `seq_app_`** : risque de collision avec les
  sequences aurora-core (`seq_core_*`). Toujours préfixer client = `seq_app_`.
- **`readonly class` sur l'Input** : empêche un enfant d'avoir des props
  mutables. Préférer `public readonly` par propriété.
- **Type-hint des classes concrètes dans le controller** : empêche la
  substitution via `#[AsAlias]`. Toujours type-hint les interfaces.
- **Override `applyInput()` sans `parent::`** : perd toute la logique de
  mapping du parent.
  Cf. [`pitfall_call_parent_apply_input.md`](../../../.claude/memory/aurora-client/pitfall_call_parent_apply_input.md).
- **Pas de hook `createX()` pour chaque entité instanciée** : le client ne
  peut pas substituer ta classe. Cf.
  [`pitfall_create_hook_required.md`](../../../.claude/memory/aurora-client/pitfall_create_hook_required.md).
- **Twig namespace pas déclaré** : `@MyModule/...` retourne une erreur
  Twig "namespace not registered". Toujours ajouter la ligne dans
  `config/packages/twig.yaml`.
- **Traductions manquantes côté JS** : ajouter le dossier dans
  `$extraSourceDirs` du `DumpJsTranslationsCommand` et lancer `make translation`,
  sinon les composants Vue n'ont pas accès aux clés.
