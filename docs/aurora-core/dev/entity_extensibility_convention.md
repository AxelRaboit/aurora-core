# Convention - Rendre une entité Aurora extensible par les clients

**Audience** : contributeurs d'aurora-core (pas les développeurs côté client).

Ce document décrit la convention à suivre pour qu'**une entité d'aurora-core
puisse être étendue de bout en bout par un client** (ajout d'un champ
persistable, validable, sérialisé, et éditable depuis le backoffice) **sans
qu'aucun fichier de `vendor/aurora/` soit modifié côté client**.

L'entité de référence est **Agency** - toute nouvelle entité Aurora doit
suivre ce pattern, et toute entité existante doit y être migrée si elle a
vocation à être étendue par un client.

> Pour le côté client (comment *consommer* cette extensibilité), voir
> [`extending_agency_pilot.md`](./extending_agency_pilot.md). Ce document-ci
> ne traite que de **comment l'exposer** depuis aurora-core.

---

## 1. Rationale

Sans cette convention, un client qui veut ajouter un champ `code` à `Agency`
doit dupliquer (forker) :

- L'entité concrète (avec tous les setters/getters)
- Le DTO d'entrée + sa logique `fromArray()`
- Le Manager (avec sa logique de persist + audit log)
- Le Serializer
- Le composant Vue de la page admin
- Le template Twig

C'est ingérable : à chaque mise à jour d'aurora-core, le client doit
manuellement réconcilier ses copies forkées avec les changements upstream.

Le pattern Sylius (qu'Aurora suit) répond à ce problème : aurora-core
expose des **points d'extension typés** (interfaces, hooks `protected`,
factories, slots Vue scoped), et le client n'écrit que **le delta** -
les setters de son champ, l'appel `parent::applyInput()`, le slot Vue qui
ajoute son input, etc.

---

## 2. Quand appliquer cette convention ?

**Critère unique et net** : *l'entité a-t-elle une page backend CRUD autonome,
avec un tableau ET un formulaire de création/édition dédié ?*

- **Oui** → appliquer le pattern complet (5 couches)
- **Non** (gérée via le formulaire d'un parent, ou auto-générée, ou sans UI
  admin) → seul le niveau 1 (entité substituable via `resolve_target_entities`)
  est requis

### 2.1 Entités à instrumenter (43)

| Module | Entités |
|---|---|
| Core | `Agency`, `Media`, `MediaFolder`, `Menu`, `MountPoint`, `Service`, `Theme`, `User` |
| Editorial | `Comment`, `Form`, `Post`, `PostType`, `Taxonomy` |
| Crm | `Company`, `Contact`, `ContactTag`, `Deal` |
| Erp | `Product` |
| Ecommerce | `Listing`, `ListingCategory`, `ListingTag`, `Order` |
| Photo | `Gallery` |
| Billing | `Invoice`, `Tiers`, `OcrJob` |
| Ged | `Document`, `DocumentCategory`, `DocumentFolder`, `DocumentTag` |
| Project | `Project`, `ProjectTask` |
| Planning | `Planning`, `PlanningEvent` |
| Hr | `Employee` |
| Notes | `BlockNote`, `MarkdownNote`, `PostItNote` |
| Vault | `VaultEntry`, `VaultFolder` |
| PdfForm | `PdfDocument`, `PdfTemplate` |
| Assistant | `AssistantMountPoint` |

**Exclues du Core** (CRUD admin absent ou hors-scope) :
- `Locale` : pas de page admin, géré via fixtures + `LocaleEnum`
- `Notification` : pas de form admin, généré uniquement par `NotificationManager::notify()` depuis le code
- `Setting` : éditeur clé-valeur sans CRUD (les clés sont définies par
  `ApplicationParameterEnum`, seule la valeur change via le panel)

Pour ces 3 entités, **seule la couche 1 est requise** - déjà en place.

### 2.2 Entités à exclure (≈ 40)

| Catégorie | Entités |
|---|---|
| Translations (gérées via parent) | toutes les `*Translation` |
| Items / lignes inline | `CartItem`, `OrderLine`, `InvoiceLine`, `FormField`, `PostTypeField`, `ProjectTaskItem`, `ProjectTaskComment`, `ProjectTaskTimeEntry`, `ProjectTaskAttachment`, `GalleryItem`, `GalleryItemComment`, `GalleryPick`, `GalleryFinalization`, `GalleryInvite`, `CommentReaction` |
| Audit / historique auto-générés | `AuditLog`, `PostRevision`, `PostSlugHistory`, `FormSubmission`, `DocumentVersion`, `MediaVersion` |
| Auth tunnel sans page admin | `AccessRequest`, `ResetPasswordRequest` |
| Configs gérées inline dans le parent | `MenuItem`, `TaxonomyTerm`, `ProjectColumn`, `ProjectLabel`, `ProjectSprint`, `ProjectSavedView` |
| Sessions runtime (pas de page admin) | `Cart`, `Conversation`, `Message`, `VaultUserConfig` |

Pour ces entités, **seul le niveau 1 est requis** : pattern
`Interface + AbstractX + concrete` + `resolve_target_entities`.
Pas de DTO, pas de Manager extensible, pas de Vue slots.

---

## 3. Les 5 couches du pattern complet

Pour une entité éligible (= avec page admin), exposer **toutes** les couches
ci-dessous. Ne pas en oublier une.

### Couche 1 - Entité Doctrine

**Toujours** (déjà appliqué sur les 86 entités Aurora) :

```
Aurora\<Module>\<Feature>\Entity\
├── <Name>Interface.php       # contrat public (getters/setters)
├── Abstract<Name>.php         # MappedSuperclass Doctrine - toutes les colonnes sauf id
└── <Name>.php                 # entité concrète, non-final, juste id + sequence
```

Convention :
- `<Name>Interface` étend `Aurora\Core\Contract\TimestampableInterface` si
  l'entité utilise `TimestampableTrait`
- `Abstract<Name>` est `#[ORM\MappedSuperclass]`, contient TOUTES les colonnes
  **sauf** l'`id` et la sequence (Doctrine ne propage pas les `SequenceGenerator`
  au MappedSuperclass)
- `<Name>` est `#[ORM\Entity]` non-`final`, contient uniquement l'`id` +
  `SequenceGenerator` + les ManyToMany éventuelles (Doctrine ne supporte pas
  les ManyToMany sur MappedSuperclass de manière propre)
- Sequence nommée `seq_core_<entity>_id` - **règle dure**, le préfixe `core_`
  est obligatoire pour éviter les collisions avec des entités client
  homonymes (un client tracking app peut très bien avoir sa propre table
  `projects` avec son propre `seq_project_id` ; toutes les sequences Aurora
  doivent vivre sous leur propre namespace `seq_core_*`)
- Référencé dans `src/AuroraBundle.php::$resolve_target_entities`

### Couche 2 - DTO d'entrée + Factory

**Scope** : on instrumente uniquement le DTO **racine** (celui que le
controller reçoit). Les sub-DTO (DTO inclus dans un tableau du DTO racine,
type `array<string, PostTranslationInput>`) restent `final readonly` et ne
sont pas instrumentés - ils ne sont jamais désérialisés directement par un
controller, ils sont construits par la factory du DTO racine. Si un client
a besoin d'étendre une sub-DTO, il étend le DTO racine et fournit ses
propres sub-DTO via la factory qu'il décore.

**Si** l'entité est créée/éditée via un formulaire admin :

```
Aurora\<Module>\<Feature>\Dto\
├── <Name>InputInterface.php          # contrat - getters utilisés par le Manager
├── <Name>Input.php                    # implémentation par défaut, non-final, readonly
├── <Name>InputFactoryInterface.php   # contrat de la factory
└── <Name>InputFactory.php            # factory par défaut, #[AsAlias(<Name>InputFactoryInterface::class)]
```

**Pourquoi une factory ?** Le controller ne peut pas faire `new <Name>Input(...)`
en dur - sinon le client ne peut pas substituer son propre DTO. La factory
est un service injectable que le client peut décorer via `#[AsAlias]`.

Le contrat `<Name>InputInterface` expose les **getters** des champs requis
par Aurora (typiquement `getName(): string`). Pas de setters - le DTO est
immutable.

`<Name>Input` est :
- `class` non-`final` avec propriétés `public readonly` individuelles (et
  **non** `readonly class` global). Pourquoi ? Un parent `readonly class`
  contraint l'enfant à être également `readonly class` → impossible pour
  un client d'ajouter une propriété mutable (state interne, cache, …) en
  étendant. Avec des `public readonly` par propriété, l'enfant peut
  ajouter des champs `public readonly` ou non-readonly indifféremment.
- Implémente `<Name>InputInterface`
- Constructeur en property promotion avec annotations `#[Assert\*]` Symfony
  pour la validation

Squelette :

```php
class AgencyInput implements AgencyInputInterface
{
    public function __construct(
        #[Assert\NotBlank]
        public readonly string $name,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }
}
```

`<Name>InputFactory` :
- Implémente `<Name>InputFactoryInterface`
- A `#[AsAlias(<Name>InputFactoryInterface::class)]` pour devenir le service
  par défaut
- Une seule méthode : `fromArray(array $data): <Name>InputInterface`
- Utilise `Aurora\Core\Support\Str::trimFromArray($data, 'name')` pour le
  parsing standard

### Couche 3 - Manager

**Si** l'entité a un Manager (création + update + delete via un service) :

```
Aurora\<Module>\<Feature>\Manager\
├── <Name>ManagerInterface.php   # contrat des opérations métier (create/update/delete)
└── <Name>Manager.php             # implémentation par défaut, #[AsAlias(<Name>ManagerInterface::class)]
```

**Pourquoi des hooks `protected` ?** Pour que le client n'ait à override que
le strict minimum (instanciation + hydratation), pas toute la mécanique de
persist + audit log.

`<Name>Manager` doit :
- Être non-`final` (clients étendent)
- Avoir des propriétés `protected readonly` (pas `private`) pour que les
  sous-classes y accèdent
- Avoir `#[AsAlias(<Name>ManagerInterface::class)]`

**Trois familles de hooks `protected`**, chacune répondant à un point
d'extension distinct :

#### 3.1 Hooks d'instanciation - `create<X>()`

**Règle dure, sans exception** : exposer un hook
`protected create<X>(): <X>Interface` **pour chaque classe d'entité que le
Manager instancie**, qu'elle ait ou non sa propre page admin. C'est le seul
moyen pour un client de substituer sa classe enfant.

Exemples : `AgencyManager` → `createAgency()` seul. `OrderManager` →
`createOrder()` + `createOrderLine()`. `FormManager` → `createForm()` +
`createFormField()`. `ProjectManager` → `createProject()` +
`createProjectColumn()` + `createProjectLabel()` + `createProjectSprint()`.

> La liste d'exclusion 2.2 ne dispense **que** des couches DTO racine /
> Manager racine / Serializer / Vue. Les sous-entités instanciées doivent
> toujours avoir leur Couche 1 (Interface + Abstract + concrete) et un
> hook `create<X>()` dans le Manager parent.

#### 3.2 Hook d'hydratation - `applyInput()`

**Requis par défaut**. Signature : `protected applyInput(<Name>Interface
$entity, <Name>InputInterface $input): void`. Appelé par `create()` et
`update()` pour copier les champs du DTO vers l'entité.

**Exception (variante "Manager à hooks multiples")** : ne pas exposer
`applyInput()` **uniquement si les 3 critères ci-dessous sont réunis** :
1. Le Manager a ≥ 6 méthodes publiques métier distinctes
2. Aucun flow create+update simple via DTO unique n'existe
3. Les opérations métier ont des règles de validation/sécurité distinctes
   (transitions de statut, autorisations, contextes différents)

À ce jour, seul `User` qualifie. Pour Order, Project, Invoice, etc.,
`applyInput()` reste obligatoire même s'ils exposent quelques méthodes
spécialisées en plus du flow standard.

#### 3.3 Hooks d'audit log - `auditCreated/Updated/Deleted` + `auditPayload`

**Requis** dès qu'un Manager fait du logging via `AuditLogger`. Évite que
le client copie tout le flow `persist + flush + log` pour ajouter un champ
au payload :

```php
protected function auditCreated(<Name>Interface $entity): void
{
    $this->auditLogger->log('core', '<entity>.created', '<Name>', $entity->getId(), $this->auditPayload($entity));
}
protected function auditUpdated(<Name>Interface $entity): void { /* idem */ }
protected function auditDeleted(<Name>Interface $entity): void { /* idem */ }

protected function auditPayload(<Name>Interface $entity): array
{
    return ['name' => $entity->getName()];
}
```

Le client qui ajoute `code` override **uniquement** `auditPayload()` :
```php
protected function auditPayload(AgencyInterface $agency): array
{
    return [...parent::auditPayload($agency), 'code' => $agency->getCode()];
}
```

#### 3.4 Squelette de référence

```php
class AgencyManager implements AgencyManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function create(AgencyInputInterface $input): AgencyInterface
    {
        $agency = $this->createAgency();
        $this->applyInput($agency, $input);
        $this->entityManager->persist($agency);
        $this->entityManager->flush();
        $this->auditCreated($agency);
        return $agency;
    }

    public function update(AgencyInterface $agency, AgencyInputInterface $input): void
    {
        $this->applyInput($agency, $input);
        $this->entityManager->flush();
        $this->auditUpdated($agency);
    }

    public function delete(AgencyInterface $agency): void
    {
        $this->entityManager->remove($agency);
        $this->entityManager->flush();
        $this->auditDeleted($agency);
    }

    protected function createAgency(): AgencyInterface
    {
        return new Agency();
    }

    protected function applyInput(AgencyInterface $agency, AgencyInputInterface $input): void
    {
        $agency->setName($input->getName());
    }

    protected function auditCreated(AgencyInterface $agency): void
    {
        $this->auditLogger->log('core', 'agency.created', 'Agency', $agency->getId(), $this->auditPayload($agency));
    }

    protected function auditUpdated(AgencyInterface $agency): void
    {
        $this->auditLogger->log('core', 'agency.updated', 'Agency', $agency->getId(), $this->auditPayload($agency));
    }

    protected function auditDeleted(AgencyInterface $agency): void
    {
        $this->auditLogger->log('core', 'agency.deleted', 'Agency', $agency->getId(), $this->auditPayload($agency));
    }

    protected function auditPayload(AgencyInterface $agency): array
    {
        return ['name' => $agency->getName()];
    }
}
```

### Couche 4 - Serializer

**Si** l'entité est sérialisée en JSON pour le front :

```
Aurora\<Module>\<Feature>\Serializer\
├── <Name>SerializerInterface.php   # contrat - méthode serialize()
└── <Name>Serializer.php             # implémentation par défaut, #[AsAlias(...)]
```

`<Name>Serializer` doit :
- Être non-`final`
- Avoir `#[AsAlias(<Name>SerializerInterface::class)]`
- Exposer une méthode `serialize(<Name>Interface $entity): array` qui
  retourne le payload JSON minimal d'Aurora

Le client étend cette classe et override `serialize()` pour ajouter ses
propres champs au tableau retourné par `parent::serialize($entity)`.

### Couche 5 - Vue + Twig

**Si** l'entité a une page admin tableau + formulaire :

#### 5.1 Composant Vue principal

`src/Module/<Module>/assets/backend/<plural>/<Plural>App.vue` doit exposer :

- **Slots scoped** :
  - `extra-headers` (sans scope) - `<th>` additionnels pour la table
  - `extra-cells` (scoped sur `agency` - ou nom équivalent) - `<td>` par ligne
  - `extra-form-fields` (scoped sur `editForm` + `errors`) - inputs additionnels
    dans le modal de création/édition

- **Prop `extraFields`** (défaut `{}`) - config passée au composable :
  ```js
  {
      <fieldName>: {
          default: <valeur initiale>,
          fromEntity: (entity) => <valeur depuis l'entité>,
      },
  }
  ```

#### 5.2 Composable `useXxxEdit`

Doit :
- Accepter `options.extraFields` en 4ème argument
- Initialiser `editForm` avec `name` (ou clé Aurora par défaut) **+ les clés
  des `extraFields`**
- Implémenter `resetExtras()` (utilisé par `openCreate`) et
  `loadExtrasFrom(entity)` (utilisé par `openEdit`)
- Envoyer le payload via `request(url, { ...editForm })` (spread !) - pas
  `{ name: editForm.name }` en dur, sinon les champs client ne sont pas envoyés

> ⚠️ **`editForm` doit rester strict** - uniquement les champs primitifs
> envoyés au backend (string, number, boolean, array de primitives). Le
> spread `{ ...editForm }` sérialise *tout* ce qu'il contient ; toute valeur
> non prévue pollue le payload et casse la validation Aurora.

✅ Correct - uniquement les champs de l'entité :

```js
const editForm = reactive({
    name: "",       // déclaré côté Aurora
    code: "",       // ajouté via extraFields côté client
    address: "",    // ajouté via extraFields côté client
});
// submit envoie : { name, code, address } ✓
```

❌ À éviter - formes courantes de pollution :

```js
// 1. Computed property dans le reactive
const editForm = reactive({
    name: "",
    code: "",
    get displayLabel() { return `${this.name} (${this.code})`; },
});
// → submit envoie un displayLabel parasite

// 2. État UI mélangé
const editForm = reactive({
    name: "",
    isDirty: false,              // état UI, pas un champ d'entité
    lastSavedAt: new Date(),     // Date non sérialisable proprement
});
// → champs parasites en base ou validation cassée

// 3. Refs imbriqués
const editForm = reactive({
    name: "",
    selectedTags: ref([]),       // ref dans un reactive
});
// → sérialisation imprévisible selon le runtime Vue
```

Pour l'état UI / computed / refs, utiliser un `reactive` ou des `ref()`
**séparés** - pas `editForm`.

#### 5.3 Override Twig automatique

Aucun changement nécessaire côté aurora-core - le bundle prepend
automatiquement les paths côté projet client devant ses propres paths
sous chaque namespace. Pour chaque namespace deux paths d'override sont
reconnus :

- **Nouveau** (recommandé, aligné sur la convention core) :
  `<client>/src/Core/templates/Core/...`, `<client>/src/Core/templates/Shared/...`,
  `<client>/src/Module/<X>/templates/...`
- **Legacy** (backward compat) :
  `<client>/templates/Core/...`, `<client>/templates/Shared/...`,
  `<client>/templates/Module/<X>/...`

Le client met simplement son override dans l'un ou l'autre, et c'est
résolu en priorité.

### Couche bonus - ResolveTargetEntityRepository

Le `<Name>Repository` doit étendre
`Aurora\Core\Repository\ResolveTargetEntityRepository` (et non pas
`ServiceEntityRepository`) :

```php
class AgencyRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agency::class, AgencyInterface::class);
    }
}
```

Sans ça, le constructor du `ServiceEntityRepository` hardcode la classe
concrete Aurora → le repo continue de query la table Aurora même quand le
client a substitué l'entité. **Tous les repos Aurora ont déjà été migrés.**

#### Étendre une finder method côté client

**Limite assumée** : Aurora **n'expose pas** de `<Name>RepositoryInterface`.
Les controllers et Managers Aurora type-hint la classe concrète
`<Name>Repository`, pas une interface. Coût/bénéfice non justifié - les
finders custom client n'ont pas vocation à être appelés depuis
aurora-core.

**Pattern client** quand un client veut ajouter ou override un finder :

```php
// 1. Le client étend le repo Aurora
namespace App\Repository;

use Aurora\Module\Platform\Agency\Repository\AgencyRepository;
use Doctrine\Persistence\ManagerRegistry;

class AppAgencyRepository extends AgencyRepository
{
    public function findActiveExcludingArchived(): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.archivedAt IS NULL')
            ->getQuery()->getResult();
    }
}

// 2. Et le déclare dans son entité concrète
#[ORM\Entity(repositoryClass: AppAgencyRepository::class)]
class Agency extends \Aurora\Module\Platform\Agency\Entity\AbstractAgency implements AgencyInterface
{
    // …
}
```

`ResolveTargetEntityRepository` route déjà la query via metadata, donc
les finders Aurora **et** custom client cohabitent sans conflit. Le client
type-hint `AppAgencyRepository` dans son propre code ; Aurora continue de
type-hint `AgencyRepository`.

---

## 4. Conventions de nommage

Pour `<Name> = Agency` :

| Élément | Nom |
|---|---|
| Entité concrète | `Agency` (`Aurora\Module\Platform\Agency\Entity\Agency`) |
| Mapped superclass | `AbstractAgency` |
| Interface entité | `AgencyInterface` |
| Table | `core_agencies` |
| Sequence | `seq_core_agency_id` |
| DTO d'entrée | `AgencyInput` |
| Interface DTO | `AgencyInputInterface` |
| Factory | `AgencyInputFactory` |
| Interface factory | `AgencyInputFactoryInterface` |
| Manager | `AgencyManager` |
| Interface Manager | `AgencyManagerInterface` |
| Serializer | `AgencySerializer` |
| Interface Serializer | `AgencySerializerInterface` |
| Repository | `AgencyRepository` |
| Vue main app | `AgenciesApp.vue` |
| Composable form | `useAgenciesForm.js` (unifié create+edit, option `extraFields`) |
| Hooks Manager - instanciation | `createAgency()` (1 par classe instanciée, sans exception) |
| Hook Manager - hydratation | `applyInput()` (sauf variante User) |
| Hooks Manager - audit | `auditCreated()` + `auditUpdated()` + `auditDeleted()` + `auditPayload()` |
| Vue slots | `extra-headers`, `extra-cells`, `extra-form-fields` |

**Exception** : pour `User`, l'interface s'appelle `CoreUserInterface` (et
non `UserInterface`) car Symfony utilise déjà `UserInterface` dans son
namespace `Symfony\Component\Security\Core\User\UserInterface`.

---

## 4.bis Variantes structurelles

Le pattern Agency est la **référence canonique**. Les autres règles de la
convention (scope du DTO, hooks par classe instanciée, `applyInput()` quand
applicable, composables `useXxxForm` unifiés) sont décrites directement dans
les couches 2/3/5 et ne constituent pas des variantes - juste des
généralisations de la même règle.

Restent **quatre variantes structurelles** où la forme du composant impose
réellement un écart au pattern de référence :

### 4.bis.1 Manager à hooks multiples (sans `applyInput()`)

**Cas** : `User` - et seulement les entités qui matchent **les 3 critères**
de la sous-section 3.2 :
1. ≥ 6 méthodes publiques métier distinctes
2. Aucun flow create+update simple via DTO unique n'existe
3. Règles de validation/sécurité distinctes par opération

`User` qualifie : `changePassword`, `consumeInvitation`, `toggleDevRole`,
`updateProfile`, `updateAgencyAndService`, `requestPasswordReset`, … chacune
avec son contexte de sécurité. On n'expose **pas** de `applyInput()` ; les
méthodes publiques sont customisables une par une. Les hooks d'instanciation
(`create<X>()`) et d'audit (`audit*` + `auditPayload()`) restent
obligatoires comme partout.

À documenter par un commentaire au-dessus du hook `create<X>()` : *"single
instantiation hook : business operations are overridden via individual
public methods"*.

Côté frontend, ce Manager s'accompagne souvent de **deux composables
distincts** (`useUsersInvite` + `useUsersForm`) car invitation et édition
sont fonctionnellement différentes (form, validation, route distinctes).
Slots correspondants : `extra-invite-form-fields` + `extra-form-fields`.

### 4.bis.1bis Composables Vue séparés (forms structurellement différents)

**Cas additionnel** : `Theme`. Le form de création est minimal (slug, name,
description) tandis que l'édition expose une interface CSS-config riche
(variables thème, header/footer modes, primary color picker). Les deux
forms ne partagent quasiment aucun champ - les unifier produirait un
composable plus complexe que les deux séparés.

Pattern à appliquer : `useXxxCreate` + `useXxxEdit` avec `extraFields`
chacun, slots `extra-create-form-fields` (côté create modal) et
`extra-form-fields` (côté edit modal).

Quand cette variante s'applique-t-elle ? **Quand les deux forms n'ont aucun
champ commun au-delà de `name`/`description`**. Sinon, le pattern unifié
`useXxxForm` (Agency, Deal, Service, Media, Menu, …) reste préférable.

### 4.bis.2 Editor full-page (pas un modal)

**Cas** : `PostEditor.vue`.

Quand le formulaire d'édition est une page entière (aside + main +
multi-tabs locales + plusieurs panels) au lieu d'un `<AppModal>`, la logique
de form vit directement dans le composant - pas de composable
`useXxxForm`. Le composant accepte une prop `extraFields` et expose un
slot `extra-form-fields` placé **sémantiquement** près d'un panel existant
proche par fonction (ex : juste après le panel "custom fields", avant
SeoPanel). L'hydratation des extras se fait dans `onMounted` après le
chargement initial des données.

Pour la liste (`PostsApp.vue`), les slots `extra-headers` / `extra-cells`
restent identiques au pattern Agency - la complexité de l'editor ne change
pas la liste.

### 4.bis.3 Tree-based editor (pas une table)

**Cas** : `MarkdownNotesApp.vue`.

Quand l'interface est un **arbre** (sidebar récursive de nœuds parent/enfant)
+ éditeur central, pas de table tabulaire, le mapping des slots
`extra-headers` / `extra-cells` à la convention Agency demande une
adaptation :

- **`extra-headers`** : surface du panneau sidebar au-dessus de l'arbre
  (à côté de la recherche / des filtres tags). Sert à injecter des
  contrôles globaux (filtre custom, view-switcher, etc.).
- **`extra-cells`** : décoration par-rangée passée **récursivement** au
  `NoteTreeItem`, qui forward le slot via une `<template #extra-cells>`
  imbriquée pour que tous les niveaux de profondeur le rendent.
- **`extra-form-fields`** : à côté du titre/tags dans l'header de
  l'éditeur (l'éditeur lui-même reste le textarea markdown - les champs
  custom vivent dans le header au-dessus).

Le composable form n'est pas `useXxxForm` mais `useNotesEditor` : il
accepte une option `extraFields` (même shape `{ key: { default } }`),
seede l'état initial, persiste les valeurs custom en spread dans le
payload `update`, et inclut les clés extra dans la comparaison `isDirty`
de l'auto-save.

---

## 5. Anti-patterns à éviter

❌ **`final readonly class <Name>Input`** - empêche l'extension
✅ `readonly class <Name>Input implements <Name>InputInterface`

❌ **`final class <Name>Manager`** - empêche l'extension
✅ `class <Name>Manager implements <Name>ManagerInterface`

❌ **`private readonly EntityManagerInterface $entityManager`** dans le Manager
✅ `protected readonly EntityManagerInterface $entityManager` (sous-classes
   ont besoin d'y accéder)

❌ **Static `<Name>Input::fromArray($data)`** appelé directement dans le
   controller - non-décorable
✅ `<Name>InputFactoryInterface` injecté + `$this->factory->fromArray($data)`

❌ **`new <Name>()` directement dans `Manager::create()`** - non-overridable
✅ `$this->create<Name>()` (hook `protected`) - override-able

❌ **Vue submit en dur** : `request(url, { name: editForm.name })`
✅ `request(url, { ...editForm })` - spread tout le form, les champs client
   sont envoyés automatiquement

❌ **Repository extends `ServiceEntityRepository` directement**
✅ Repository extends `Aurora\Core\Repository\ResolveTargetEntityRepository`

❌ **Sequence nommée `seq_<entity>_id`** (sans `core_`) - collision potentielle
   avec des entités client homonymes
✅ Sequence nommée `seq_core_<entity>_id`

❌ **`<Name>Manager::create()` qui contourne `applyInput()`** - fait perdre
   au client la capacité d'override la logique d'hydratation
✅ Toujours passer par les hooks `create<Name>()` + `applyInput()`

---

## 6. Checklist - Retrofitter une entité existante

Pour appliquer cette convention à une entité qui n'a pas encore le pattern
complet (ex : Deal, Post, User, Project, Contact, Company, Order, etc.) :

### Côté code

- [ ] Vérifier que le pattern entité (Interface + AbstractX + concrete) est
      déjà en place. Sinon, le faire d'abord (cf. couche 1).
- [ ] Vérifier que le repository étend `ResolveTargetEntityRepository`. Sinon,
      le faire d'abord (cf. couche bonus).
- [ ] Sequence renommée `seq_core_*` ?
- [ ] **DTO** :
  - Créer `<Name>InputInterface.php`
  - Retirer `final` de `<Name>Input.php`, ajouter `implements <Name>InputInterface`
  - Créer `<Name>InputFactoryInterface.php` + `<Name>InputFactory.php` avec
    `#[AsAlias]`
  - Retirer la méthode statique `fromArray()` de `<Name>Input` (la déplacer
    dans la factory)
- [ ] **Manager** :
  - Créer `<Name>ManagerInterface.php`
  - Retirer `final readonly` de `<Name>Manager.php` → `class`
  - Changer `private readonly` → `protected readonly` sur les propriétés DI
  - Ajouter `#[AsAlias(<Name>ManagerInterface::class)]`
  - Refactor `create()` / `update()` pour passer par les hooks
    `protected create<Name>()` + `protected applyInput()`
- [ ] **Serializer** :
  - Créer `<Name>SerializerInterface.php`
  - Retirer `final readonly` de `<Name>Serializer.php` → `class`
  - Ajouter `#[AsAlias(<Name>SerializerInterface::class)]`
  - Implémenter `<Name>SerializerInterface`
- [ ] **Controller** : remplacer les imports concrets par les interfaces dans
      le constructeur du controller :
  ```php
  protected readonly <Name>SerializerInterface $serializer,
  protected readonly <Name>ManagerInterface $manager,
  protected readonly <Name>InputFactoryInterface $inputFactory,
  ```
- [ ] **Vue** :
  - Refactor `<Plural>App.vue` pour exposer les 3 slots scoped + prop
    `extraFields`
  - Refactor `useXxxEdit.js` pour accepter `options.extraFields`,
    pré-initialiser `editForm`, et envoyer `{ ...editForm }` au submit
  - Vérifier qu'aucun composant Vue ne hardcode la liste des colonnes /
    champs

### Côté validation

- [ ] `php bin/console cache:clear`
- [ ] `php bin/console doctrine:schema:validate`
- [ ] `vendor/bin/phpunit` - 494 tests doivent rester verts
- [ ] Lancer la page admin Aurora correspondante en navigateur, créer/éditer
      une entité - comportement Aurora inchangé

### Côté doc

- [ ] Si nouvelle entité : ajouter dans la convention si elle introduit un
      cas pas couvert ici
- [ ] Si gap dans cette convention découvert pendant le retrofit : le
      documenter

---

## 7. Checklist - Créer une nouvelle entité Aurora

Pour une nouvelle entité créée from-scratch dans aurora-core :

- [ ] Créer les 3 fichiers d'entité (`Interface` + `Abstract<Name>` + `<Name>`)
- [ ] Sequence Postgres en `seq_core_<entity>_id`
- [ ] Référencer dans `src/AuroraBundle.php::$resolve_target_entities`
- [ ] Repository qui étend `ResolveTargetEntityRepository`
- [ ] Migration Doctrine standard
- [ ] **Si l'entité a une page backend CRUD** :
  - Créer les 4 fichiers DTO (Input + InputInterface + Factory +
    FactoryInterface)
  - Créer les 2 fichiers Manager (Manager + ManagerInterface) avec hooks
    `create<Name>()` + `applyInput()`
  - Créer les 2 fichiers Serializer (Serializer + SerializerInterface)
  - Controller qui injecte les 3 interfaces (factory + manager + serializer)
  - Vue : `<Plural>App.vue` avec les 3 slots `extra-*` + prop `extraFields`
  - Composable `useXxxEdit.js` qui accepte `extraFields` et fait le spread
    au submit

- [ ] Suivre les conventions de nommage section 4
- [ ] Ne pas tomber dans les anti-patterns section 5

---

## 8. Référence canonique

Pour copier-coller un exemple en bon état, partir de **`Agency`** :

| Couche | Fichiers de référence |
|---|---|
| Entity | `src/Module/Platform/Agency/Entity/{AgencyInterface,AbstractAgency,Agency}.php` |
| DTO | `src/Module/Platform/Agency/Dto/{AgencyInputInterface,AgencyInput,AgencyInputFactoryInterface,AgencyInputFactory}.php` |
| Manager | `src/Module/Platform/Agency/Manager/{AgencyManagerInterface,AgencyManager}.php` |
| Serializer | `src/Module/Platform/Agency/Serializer/{AgencySerializerInterface,AgencySerializer}.php` |
| Repository | `src/Module/Platform/Agency/Repository/AgencyRepository.php` |
| Controller | `src/Module/Platform/Agency/Controller/Backend/AgenciesController.php` |
| Vue main | `src/Module/Platform/assets/backend/agencies/AgenciesApp.vue` |
| Vue composables | `src/Module/Platform/assets/backend/agencies/composables/useAgenciesForm.js` |
| Twig | `src/Module/Platform/templates/backend/agencies/index.html.twig` (namespace `@Platform`) |

Toute déviation de ce pattern doit être justifiée (cas spécifique au domaine
de l'entité) et documentée dans cette même convention.
