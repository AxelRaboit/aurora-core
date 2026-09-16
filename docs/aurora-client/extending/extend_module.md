# Étendre un module Aurora

Ce document est le **recettier** pour étendre n'importe quel point
d'extension d'aurora-core depuis votre projet client. Chaque section traite
un cas concret (« je veux X → voici le diff minimal côté client »).

> Pour **créer** un module client (de zéro, pas étendre Aurora), voir
> [`add_module.md`](add_module.md).

Pour la théorie complète du pattern Sylius en 5 couches, voir la doc
canonique [`../../aurora-core/dev/entity_extensibility_convention.md`](../../aurora-core/dev/entity_extensibility_convention.md).
Pour un tutoriel pas-à-pas qui ajoute un champ `code` à `DocumentCategory`, voir
[`../../aurora-core/dev/extending_category_pilot.md`](../../aurora-core/dev/extending_category_pilot.md).

> **Règle d'or** : on **n'édite jamais** un fichier sous
> `vendor/axelraboit/aurora/`. Toute modification passe par les points
> d'extension décrits ci-dessous.

---

## 0. Vue d'ensemble - les 5 couches

| Couche | Mécanisme côté client | Section |
|---|---|---|
| 1 - Entité Doctrine | `extends Abstract<Name>`, `repositoryClass`, `resolve_target_entities` | [§1](#1-layer-1--entité-doctrine) |
| 2 - DTO d'entrée + Factory | `extends <Name>Input` + `#[AsAlias(<Name>InputFactoryInterface::class)]` sur la factory client | [§2](#2-layer-2--dto-dentrée-et-factory) |
| 3 - Manager | `extends <Name>Manager` + `#[AsAlias(<Name>ManagerInterface::class)]` + override des hooks `protected` | [§3](#3-layer-3--manager) |
| 4 - Serializer | `extends <Name>Serializer` + `#[AsAlias(<Name>SerializerInterface::class)]` | [§4](#4-layer-4--serializer) |
| 5 - Vue admin | prop `extraFields` + slots scoped `extra-headers` / `extra-cells` / `extra-form-fields` | [§5](#5-layer-5--vue-admin) |

Hors des 5 couches :

- **Twig** - namespace prepend automatique. Voir [§6](#6-override-twig).
- **Repository finders custom** - pattern simple sans interface aurora-core. Voir [§7](#7-finder-method-custom).
- **Décorer un service Aurora autre** (event listener, helper) - voir [§8](#8-décorer-un-service-aurora-arbitraire).
- **Permissions / module toggles** - voir [§9](#9-permissions-et-modules).

---

## 1. Layer 1 - Entité Doctrine

### Cas : je veux ajouter un champ à `DocumentCategory`.

**Diff côté client** :

`src/Module/Ged/DocumentCategory/Entity/DocumentCategory.php` :

```php
namespace App\Module\Ged\DocumentCategory\Entity;

use Aurora\Module\Ged\DocumentCategory\Entity\{AbstractDocumentCategory, DocumentCategoryInterface};
use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentCategoryRepository::class)]
#[ORM\Table(name: 'app_ged_document_categories')]
class DocumentCategory extends AbstractDocumentCategory implements DocumentCategoryInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_app_ged_category_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $code = null;

    public function getId(): ?int { return $this->id; }
    public function getCode(): ?string { return $this->code; }
    public function setCode(?string $code): static { $this->code = $code; return $this; }
}
```

`config/packages/doctrine.yaml` - ajouter la ligne `resolve_target_entities` :

```yaml
doctrine:
    orm:
        resolve_target_entities:
            Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface: App\Module\Ged\DocumentCategory\Entity\DocumentCategory
```

### Règles dures à respecter

- On étend **`AbstractDocumentCategory`** (le `MappedSuperclass`), pas la classe
  concrète `Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory`. Sinon Doctrine exige une
  inheritance type + discriminator - non voulu.
- Table : préfixe **`app_*`** (jamais `core_*` qui est réservé à Aurora,
  jamais sans préfixe).
- Séquence : préfixe **`seq_app_*`** (jamais `seq_core_*`).
- L'`id` + le `SequenceGenerator` sont **redéclarés côté client** parce que
  Doctrine ne propage pas un `SequenceGenerator` à travers un
  `MappedSuperclass`.
- Pas besoin de redéclarer un repository client - `DocumentCategoryRepository` (qui
  étend `ResolveTargetEntityRepository`) querie automatiquement votre table
  dès que `resolve_target_entities` route l'interface.

Cf. [`../../aurora-core/dev/extending_category_pilot.md`](../../aurora-core/dev/extending_category_pilot.md)
pour le pilote complet.

### Cas : je veux substituer une entité d'un module Aurora (`Crm\Deal`, `Billing\Invoice`…)

Le chemin client miroir le namespace Aurora :

| Aurora | Client |
|---|---|
| `Aurora\Module\Ged\DocumentCategory\…` | `src/Module/Ged/DocumentCategory/…` |
| `Aurora\Module\Crm\Deal\…` | `src/Module/Crm/Deal/…` |
| `Aurora\Module\Billing\Invoice\…` | `src/Module/Billing/Invoice/…` |

Le reste est identique au cas DocumentCategory.

---

## 2. Layer 2 - DTO d'entrée et Factory

### Cas : j'ai ajouté `code` à `DocumentCategory`, le formulaire admin doit l'envoyer.

**Diff côté client** :

`src/Module/Ged/DocumentCategory/Dto/DocumentCategoryInput.php` - étend le DTO Aurora :

```php
namespace App\Module\Ged\DocumentCategory\Dto;

use Aurora\Module\Ged\DocumentCategory\Dto\DocumentCategoryInput as AuroraDocumentCategoryInput;
use Symfony\Component\Validator\Constraints as Assert;

class DocumentCategoryInput extends AuroraDocumentCategoryInput
{
    public function __construct(
        string $name,
        #[Assert\Length(max: 50)]
        public readonly ?string $code = null,
    ) {
        parent::__construct($name);
    }

    public function getCode(): ?string
    {
        return $this->code;
    }
}
```

`src/Module/Ged/DocumentCategory/Dto/DocumentCategoryInputFactory.php` - étend la factory et
écrase l'alias :

```php
namespace App\Module\Ged\DocumentCategory\Dto;

use Aurora\Module\Ged\DocumentCategory\Dto\DocumentCategoryInputFactory as AuroraDocumentCategoryInputFactory;
use Aurora\Module\Ged\DocumentCategory\Dto\DocumentCategoryInputFactoryInterface;
use Aurora\Module\Ged\DocumentCategory\Dto\DocumentCategoryInputInterface;
use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(DocumentCategoryInputFactoryInterface::class)]
class DocumentCategoryInputFactory extends AuroraDocumentCategoryInputFactory
{
    public function fromArray(array $data): DocumentCategoryInputInterface
    {
        return new DocumentCategoryInput(
            name: Str::trimFromArray($data, 'name'),
            code: Str::trimFromArray($data, 'code') ?: null,
        );
    }
}
```

### Règles dures

- Toujours **`public readonly` par propriété** dans le constructeur, **pas
  `readonly class`** : un parent `readonly class` empêcherait l'enfant
  d'ajouter une propriété mutable.
- **`#[AsAlias]` se met sur la Factory cliente**, qui prend la place de la
  factory Aurora pour l'interface `DocumentCategoryInputFactoryInterface`. Le DTO
  lui-même n'a pas besoin d'alias - il est instancié par la factory.
- Pas de méthode statique `fromArray()` dans le DTO - c'est la factory qui
  s'en charge (c'est elle qu'on peut décorer).
- Le contrôleur Aurora type-hint déjà `DocumentCategoryInputFactoryInterface`, donc il
  reçoit votre factory cliente sans aucune autre modification.

Sub-DTO (ex. `PostTranslationInput` inclus dans `PostInput`) : ils restent
`final readonly` et **ne sont pas instrumentés**. Pour les substituer, étendez
le DTO racine et fournissez vos propres sub-DTO via la factory cliente.

---

## 3. Layer 3 - Manager

### Cas : persister + auditer le champ `code` à la création / édition

**Diff côté client** - `src/Module/Ged/DocumentCategory/Manager/DocumentCategoryManager.php` :

```php
namespace App\Module\Ged\DocumentCategory\Manager;

use App\Module\Ged\DocumentCategory\Dto\DocumentCategoryInput;
use App\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Dto\DocumentCategoryInputInterface;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentCategory\Manager\DocumentCategoryManager as AuroraDocumentCategoryManager;
use Aurora\Module\Ged\DocumentCategory\Manager\DocumentCategoryManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(DocumentCategoryManagerInterface::class)]
class DocumentCategoryManager extends AuroraDocumentCategoryManager
{
    protected function createDocumentCategory(): DocumentCategoryInterface
    {
        return new DocumentCategory();
    }

    protected function applyInput(DocumentCategoryInterface $category, DocumentCategoryInputInterface $input): void
    {
        parent::applyInput($category, $input);

        if ($category instanceof DocumentCategory && $input instanceof DocumentCategoryInput) {
            $category->setCode($input->getCode());
        }
    }

    protected function auditPayload(DocumentCategoryInterface $category): array
    {
        return [
            ...parent::auditPayload($category),
            'code' => $category instanceof DocumentCategory ? $category->getCode() : null,
        ];
    }
}
```

### Trois familles de hooks à override

1. **`create<X>()`** - un hook par classe instanciée par le Manager. **Sans
   exception** : si vous étendez l'entité, vous **devez** override
   `create<X>()` sinon Doctrine `persist()` votre vieille classe Aurora et
   tous vos champs custom sont perdus à la sauvegarde. Pour
   `ProjectManager`, ça veut dire 4 hooks (`createProject`, `createProjectColumn`,
   `createProjectLabel`, `createProjectSprint`).

2. **`applyInput()`** - toujours appeler `parent::applyInput()` **d'abord**,
   sinon les champs Aurora ne sont pas hydratés.

3. **`auditCreated` / `auditUpdated` / `auditDeleted` / `auditPayload`** -
   override **uniquement** `auditPayload()` 99% du temps. Le splat-merge
   (`[...parent::auditPayload($entity), 'code' => …]`) garde les champs
   Aurora et ajoute les vôtres.

### Variante Manager à hooks multiples (User-style)

Pour `User`, il n'existe **pas** de `applyInput()` unifié - `User` a 6+
méthodes métier distinctes (`changePassword`, `consumeInvitation`,
`toggleDevRole`, `updateProfile`, …). Vous override ces méthodes
individuellement. Les hooks `create<X>()` et `audit*` restent obligatoires.
Cf. [`entity_extensibility_convention.md §4.bis.1`](../../aurora-core/dev/entity_extensibility_convention.md).

### Pièges connus

- **Oublier `create<X>()`** → Doctrine persiste la classe Aurora, perte
  silencieuse des champs client (cf. memory `pitfall_create_hook_required.md`).
- **Oublier `parent::applyInput()`** → tous les champs Aurora restent à null
  / valeur par défaut.
- Les propriétés DI dans `DocumentCategoryManager` Aurora sont `protected readonly`
  (jamais `private`) - accessibles depuis votre sous-classe.

---

## 4. Layer 4 - Serializer

### Cas : exposer `code` dans le JSON envoyé au front

**Diff côté client** -
`src/Module/Ged/DocumentCategory/Serializer/DocumentCategorySerializer.php` :

```php
namespace App\Module\Ged\DocumentCategory\Serializer;

use App\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentCategory\Serializer\DocumentCategorySerializer as AuroraDocumentCategorySerializer;
use Aurora\Module\Ged\DocumentCategory\Serializer\DocumentCategorySerializerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(DocumentCategorySerializerInterface::class)]
class DocumentCategorySerializer extends AuroraDocumentCategorySerializer
{
    public function serialize(DocumentCategoryInterface $category): array
    {
        return [
            ...parent::serialize($category),
            'code' => $category instanceof DocumentCategory ? $category->getCode() : null,
        ];
    }
}
```

Même pattern que le Manager : `extends`, `#[AsAlias(<I>::class)]`, spread du
parent + champs custom. C'est la couche la plus simple.

---

## 5. Layer 5 - Vue admin

Le composant Aurora `<Plural>App.vue` expose une **prop `extraFields`** + 3
slots scoped (`extra-headers`, `extra-cells`, `extra-form-fields`). Côté
client, on ne réécrit pas le composant - on le **wrap**, **co-localisé
avec l'extension PHP** sous `src/Module/<AuroraModule>/<Feature>/assets/`.

> **Comment ça shadow ?** Le glob `src/Module/**/assets/**/*.vue` flatten
> les feature folders (e.g. `Platform/DocumentCategory/assets/...`) → la clé exposée
> est identique à celle d'Aurora (`platform/backend/document-categories/DocumentCategoriesApp`).
> Comme le bloc `clientModules` est spread APRÈS `auroraModules`, ton
> fichier wins automatiquement - pas de Twig override à écrire. Détail
> et règle des deux mirrors (PHP vs URL) :
> [`convention_overrides_vs_modules.md`](../../../.claude/memory/aurora-client/convention_overrides_vs_modules.md).

Exemple détaillé (slots, composables, gotchas `editForm`) :
[`../dev/assets_vue.md`](../dev/assets_vue.md) et la mémoire dédiée
[`pattern_extend_vue.md`](../../../.claude/memory/aurora-client/pattern_extend_vue.md).

Squelette d'override Vue -
`src/Module/Ged/DocumentCategory/assets/backend/document-categories/DocumentCategoriesApp.vue` :

```vue
<script setup>
import AuroraDocumentCategoriesApp from '@platform/backend/document-categories/DocumentCategoriesApp.vue';
</script>

<template>
  <AuroraDocumentCategoriesApp :extra-fields="{ code: { default: '', fromEntity: (category) => category.code ?? '' } }">
    <template #extra-headers>
      <th>{{ $t('core.document-categories.code') }}</th>
    </template>
    <template #extra-cells="{ category }">
      <td>{{ category.code }}</td>
    </template>
    <template #extra-form-fields="{ editForm, errors }">
      <AppInput v-model="editForm.code" :error="errors.code" :label="$t('core.document-categories.code')" />
    </template>
  </AuroraDocumentCategoriesApp>
</template>
```

> **Piège `editForm`** : `editForm` doit contenir **uniquement** les champs
> envoyés au backend (string/number/boolean). Pas d'état UI, pas de computed,
> pas de refs imbriqués. Le submit fait `request(url, { ...editForm })` -
> tout ce qui est dedans part au backend. Détail dans [`../dev/assets_vue.md`](../dev/assets_vue.md).

---

## 6. Override Twig

Aucune config nécessaire. Aurora **prepend automatiquement**
`templates/<Namespace>/` du projet client devant son propre chemin sous
chaque namespace `@<Namespace>`.

Conséquence : posez votre fichier au **même chemin logique** que
l'original côté client. Deux conventions de chemin sont reconnues :

| Namespace Twig | Override client (nouveau, recommandé) | Override client (legacy backward compat) |
|---|---|---|
| `@Core/backend/document-categories/index.html.twig` | `src/Core/templates/Core/backend/document-categories/index.html.twig` | `templates/Core/backend/document-categories/index.html.twig` |
| `@Ecommerce/backend/listings/edit.html.twig` | `src/Module/Ecommerce/templates/backend/listings/edit.html.twig` | `templates/Module/Ecommerce/backend/listings/edit.html.twig` |
| `Frontend/themes/default/editorial/home.html.twig` (null namespace, thèmes) | - | `templates/Frontend/themes/default/editorial/home.html.twig` |

> Les thèmes frontend custom restent à la racine client
> (`<client>/templates/Frontend/themes/<slug>/...`) car ils sont
> considérés comme de la data utilisateur, pas du code Aurora à étendre.

Pour le frontend (site public), le **ThemeResolver** ajoute encore une
couche de résolution par slug de thème - voir
[`../../aurora-core/dev/frontend_theme_override.md`](../../aurora-core/dev/frontend_theme_override.md).

---

## 7. Finder method custom

Aurora **n'expose pas** d'interface `<Name>RepositoryInterface` (coût/bénéfice
non justifié - voir
[`entity_extensibility_convention.md §3 Couche bonus`](../../aurora-core/dev/entity_extensibility_convention.md)).

Le pattern côté client est simple : étendre le repo Aurora, déclarer le
`repositoryClass` sur l'entité concrète.

`src/Module/Ged/DocumentCategory/Repository/AppDocumentCategoryRepository.php` :

```php
namespace App\Module\Ged\DocumentCategory\Repository;

use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;

class AppDocumentCategoryRepository extends DocumentCategoryRepository
{
    public function findActiveExcludingArchived(): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.archivedAt IS NULL')
            ->getQuery()->getResult();
    }
}
```

Puis dans l'entité cliente :

```php
#[ORM\Entity(repositoryClass: AppDocumentCategoryRepository::class)]
class DocumentCategory extends AbstractDocumentCategory implements DocumentCategoryInterface { … }
```

Aurora continue de type-hint `DocumentCategoryRepository` - la metadata résolue par
`ResolveTargetEntityRepository` route les queries vers la bonne table. Vous
type-hint `AppDocumentCategoryRepository` dans votre propre code uniquement.

---

## 8. Décorer un service Aurora arbitraire

Hors des 5 couches, deux mécanismes Symfony :

### a) Remplacer (alias)

```php
#[AsAlias(\Aurora\Core\…\SomeInterface::class)]
final class MyService implements \Aurora\Core\…\SomeInterface { … }
```

Aurora type-hint l'interface partout - votre classe prend sa place.

### b) Décorer (chaîne)

```php
#[AsDecorator(\Aurora\Core\…\SomeService::class)]
final class MyDecorator
{
    public function __construct(private readonly \Aurora\Core\…\SomeService $inner) {}

    public function doStuff(): void
    {
        // pre-hook
        $this->inner->doStuff();
        // post-hook
    }
}
```

⚠️ Décorer ne marche que si les consommateurs **type-hint l'interface**, pas
la classe concrète. Aurora le fait pour les Managers/Serializers/Factories
instrumentés ; pour le reste, lire le code avant de décorer.

### c) Écouter un événement Aurora

```php
#[AsEventListener(event: \Aurora\Core\…\Event\SomethingHappened::class)]
final class MyListener
{
    public function __invoke(SomethingHappened $event): void { … }
}
```

Placement standard : `src/EventListener/MyListener.php` (autoconfiguré) ou
`src/Module/<Mirror>/EventListener/`.

---

## 9. Permissions et modules

### 9.1 Ajouter une permission custom à un module Aurora

Quand un projet client a besoin de permissions supplémentaires sur un module
Aurora existant (ex. `crm.contacts.export`, `crm.deals.archive`), créer un
**module de permissions** dédié - un service qui implémente `ModuleInterface`
avec le **même `getId()`** que le module Aurora cible (pour grouper les
permissions sous la même section dans `/dev/dashboard/permissions`).

```php
// src/Module/ClientCrm/ClientCrmPermissionsModule.php
namespace App\Module\ClientCrm;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Nav\NavPermission;

final readonly class ClientCrmPermissionsModule implements ModuleInterface
{
    public function getId(): string { return 'crm'; }  // même id que CrmModule core

    public function getPermissions(): array
    {
        return [
            new NavPermission('crm.contacts.export'),
            new NavPermission('crm.deals.archive'),
        ];
    }

    public function getNavSections(): array        { return []; }   // pas de nav, juste des permissions
    public function getCatalogNavSections(): array { return []; }
}
```

Pas besoin de toucher `services.yaml` - le bloc `_instanceof: ModuleInterface`
(cf. [`add_module.md` §2.1](add_module.md)) tague automatiquement.

**Traduction obligatoire** (sans elle, le dashboard affiche la clé brute) :

```yaml
# src/Module/ClientCrm/translations/messages.fr.yaml
backend:
    permissions:
        names:
            crm:
                contacts:
                    export: Exporter les contacts (CSV)
                deals:
                    archive: Archiver les opportunités
```

Côté controller / Vue :

```php
#[IsGranted('crm.contacts.export')]
public function export(): Response { /* ... */ }
```

```js
const canExport = computed(() => can('crm.contacts.export'));
```

Cf. memory [`pattern_add_custom_permissions.md`](../../../.claude/memory/aurora-client/pattern_add_custom_permissions.md).

### 9.2 Bonne pratique : permissions granulaires

Toujours décomposer en actions atomiques plutôt qu'un `manage` fourre-tout -
permet d'attribuer des profils de droits fins aux utilisateurs `ROLE_USER`
(lecture seule, création sans suppression, etc.) :

```php
// ✅ Granulaire - recommandé
return [
    new NavPermission('crm.contacts.view'),
    new NavPermission('crm.contacts.create'),
    new NavPermission('crm.contacts.edit'),
    new NavPermission('crm.contacts.delete'),
];

// ❌ Trop vague
return [
    new NavPermission('crm.contacts.view'),
    new NavPermission('crm.contacts.manage'),
];
```

Correspondance Controller ↔ permission :

| Action | `#[IsGranted]` |
|---|---|
| `index()` / `list()` | `*.view` (sur la classe) |
| `create()` | `*.create` |
| `update()` | `*.edit` |
| `delete()` | `*.delete` |

Côté Vue, si une action regroupe create + edit + delete (ex. un bouton
"Modifier" qui peut aussi créer), utiliser un computed combiné :

```js
const canManage = computed(() =>
    can('crm.contacts.create') || can('crm.contacts.edit') || can('crm.contacts.delete')
);
```

### 9.3 Règles d'accès héritées d'aurora-core

| Rôle | Comportement |
|---|---|
| `ROLE_DEV`   | Accès accordé automatiquement à toutes les permissions |
| `ROLE_ADMIN` | Accès accordé automatiquement à toutes les permissions |
| `ROLE_USER`  | Accès accordé seulement si la permission est dans `$user->getPrivileges()` |

Les privilèges d'un `ROLE_USER` se gèrent depuis
`/backend/platform/users → Modifier → Privilèges`.

### 9.4 Plusieurs modules de permissions

Rien n'empêche d'avoir plusieurs modules de permissions clients, un par
domaine (chacun s'enregistre automatiquement via `_instanceof`) :

```
src/Module/ClientCrm/ClientCrmPermissionsModule.php       → id 'crm'
src/Module/ClientBilling/ClientBillingPermissionsModule.php → id 'billing'
src/Module/ClientHr/ClientHrPermissionsModule.php         → id 'hr'
```

### 9.5 Toggle de module utilisateur

`ModuleToggleProviderInterface::getToggles()` retourne des `ModuleToggle`
pour exposer un module au panel "Accès aux modules par user". Exemple
complet : le module d'exemple `Tracking` déroulé dans
[`add_module.md` §5](add_module.md#5-cas-2--module-avec-toggles--context-class)
(générique - pas un module fourni dans le template).
Doc core : [`../../aurora-core/dev/per_user_module_access.md`](../../aurora-core/dev/per_user_module_access.md).

### 9.6 Sync après chaque ajout

```bash
make sf CMD="aurora:privileges:sync"
```

À relancer à chaque ajout/suppression de permission ou de NavItem.

---

## 10. Checklist d'une extension complète d'entité

Pour étendre une entité avec champ custom de bout en bout, suivre l'ordre
suivant (cf. memory
[`checklist_extend_full_entity.md`](../../../.claude/memory/aurora-client/checklist_extend_full_entity.md)) :

1. Entité cliente + `resolve_target_entities` + table `app_*` + séquence `seq_app_*`.
2. `make migration && make migrate` + `make schema-validate`.
3. DTO + Factory + `#[AsAlias]` sur la factory.
4. Manager + `#[AsAlias]` sur le manager + override `create<X>()` +
   `applyInput()` (`parent::` !) + `auditPayload()` (spread `parent::` !).
5. Serializer + `#[AsAlias]` + spread `parent::serialize()`.
6. Vue : composant override **co-localisé** avec l'extension PHP sous
   `src/Module/<AuroraModule>/<Feature>/assets/backend/<plural>/<Name>App.vue`
   (cf. [`convention_overrides_vs_modules.md`](../../../.claude/memory/aurora-client/convention_overrides_vs_modules.md))
   avec `extraFields` + 3 slots.
7. Twig : override sous `src/Core/templates/Core/...` (nouveau) ou
   `templates/Core/...` (legacy backward compat) si besoin.
8. `make cc` (cache:clear, indispensable après `#[AsAlias]`).
9. Test admin : créer une entité, vérifier que le champ persiste, s'audite
   et est sérialisé.

Pour les anti-patterns à éviter (DTO `readonly class`, Manager `final`,
oubli de `create<X>()`, hardcode du submit Vue…), voir la section 5 de la
convention canonique
[`entity_extensibility_convention.md`](../../aurora-core/dev/entity_extensibility_convention.md).
