# Structure d'un module / sous-module dans `src/`

## Règle

Chaque entité Aurora est rangée dans un **dossier dédié** sous `src/Core/`
ou `src/Module/<Module>/<Feature>/`, avec une structure standard :

```
src/Core/<Feature>/                            ← ex: src/Core/Notification/
src/Module/<Module>/<Feature>/                  ← ex: src/Module/Editorial/Post/
├── Controller/
│   ├── Backend/                                ← endpoints admin (/backend/...)
│   └── Frontend/                               ← endpoints frontend public (sans /backend)
├── Dto/                                        ← Input + InputInterface + InputFactory + InputFactoryInterface
├── Entity/                                     ← Interface + Abstract + concrete
├── Enum/                                       ← enums backed (StatusEnum, etc.)
├── Manager/                                    ← Manager + ManagerInterface
├── Repository/                                 ← <Name>Repository extends ResolveTargetEntityRepository
├── Serializer/                                 ← Serializer + SerializerInterface
├── Service/                                    ← logique stateless (calculs, validateurs, providers)
├── View/                                       ← <Plural>ViewBuilder pour l'admin Twig
├── Message/                                    ← messages async (Symfony Messenger), si applicable
├── MessageHandler/                             ← handlers async
├── EventSubscriber/                            ← listeners Symfony, si applicable
├── Twig/                                       ← TwigExtension custom, si applicable
└── Security/                                   ← Voters, AccessChecker, si applicable
```

**Lequel des deux emplacements** : un module qui a un NavItem dans le menu
vit sous `src/Module/`, jamais sous `src/Core/` - c'est
[[decision_core_submodule_nesting]]. `src/Core/<Feature>/` est réservé à
l'infra transverse ; il n'en reste qu'un de cette forme, `src/Core/Notification/`.

## Pourquoi cette séparation

- **Cohésion verticale** : tout ce qui concerne `Post` est sous
  `src/Module/Editorial/Post/`. Pas de répartition horizontale par type
  (`src/Controller/`, `src/Manager/`, …).
- **Module isolé** : on peut ajouter / supprimer un module entier en
  ajoutant / supprimant un dossier `src/Module/<Module>/`.
- **Découverte** : navigation IDE simple - chercher `Post` ouvre
  `src/Module/Editorial/Post/`.

## Comment l'appliquer

### Choisir Core ou Module ?

- **Core** : infrastructure transversale, indépendante d'un domaine métier
  (Notification, Sequence, Validation, Storage, Locale, Trash, Search…).
- **Module** : domaine métier (Editorial, Crm, Ged, Studio, Notes,
  Platform, Configuration…).

Si une entité touche du métier, c'est `src/Module/<Module>/<Entité>/`.
Sinon `src/Core/<Entité>/`. En pratique le second cas est devenu rare :
un module qui a un NavItem vit sous `src/Module/`, cf
[[decision_core_submodule_nesting]].

### Sous-dossiers obligatoires vs optionnels

**Toujours** : `Entity/`, `Repository/`.

**Si backend CRUD** (entité instrumentée, cf
[`convention_extensibility.md`](convention_extensibility.md)) : `Dto/`,
`Manager/`, `Serializer/`, `Controller/Backend/`, `View/`.

**Selon besoin** : `Service/` (helpers stateless), `Message/`+
`MessageHandler/` (async), `EventSubscriber/`, `Twig/`, `Security/`,
`Controller/Frontend/`, `Enum/`.

### Naming des fichiers

- Singulier pour les entités : `DocumentCategory.php`, `Post.php`.
- Singulier pour les `<Name>Manager.php`, `<Name>Repository.php`,
  `<Name>Serializer.php`, `<Name>Input.php`.
- Pluriel pour les Controllers + ViewBuilders :
  `DocumentCategoriesController.php`, `DocumentCategoriesViewBuilder.php`
  (cohérent avec le pluriel des routes `/backend/ged/categories`).

## Exemples

### Standard simple (DocumentCategory)

C'est le pilote des 5 couches, déroulé en entier dans
`docs/aurora-core/dev/extending_category_pilot.md`.

```
src/Module/Ged/DocumentCategory/
├── Controller/Backend/DocumentCategoriesController.php
├── Dto/{DocumentCategoryInput,DocumentCategoryInputFactory}{,Interface}.php
├── Entity/{DocumentCategory,AbstractDocumentCategory,DocumentCategoryInterface}.php
├── Manager/{DocumentCategoryManager,DocumentCategoryManagerInterface}.php
├── Repository/DocumentCategoryRepository.php
├── Serializer/{DocumentCategorySerializer,DocumentCategorySerializerInterface}.php
├── Service/{DocumentCategoryFactory,DocumentCategoryResolver,InlineUploadCategoryProvider}.php
├── Message/PurgeTrashedCategoriesMessage.php + MessageHandler/
└── View/DocumentCategoriesViewBuilder.php
```

### Module avec Frontend + Backend (Post)

```
src/Module/Editorial/Post/
├── Controller/Backend/{PostsController,PostTypesController}.php
├── Controller/Frontend/PageController.php
├── Dto/...
├── Entity/...
├── Enum/{PostStatusEnum,PostFieldTypeEnum}.php
├── Manager/{PostManager,PostTypeManager,PostManagerInterface,PostTypeManagerInterface}.php
├── Message/{ProcessPostScheduledMessage}.php       ← async
├── MessageHandler/{ProcessPostScheduledHandler}.php
├── Repository/...
├── Security/PostVoter.php                          ← ACL specifique
├── Serializer/...
├── Service/{PostTextExtractor,PostSlugGenerator}.php
└── View/{PostsViewBuilder,PostTypesViewBuilder}.php
```

## Cas particulier : `src/Core/Module/` (infrastructure transversale)

Ce dossier n'est PAS une entité métier, mais l'**infrastructure du système
de modules** (registry, voter, toggles, nav). Il ne suit donc pas la
convention `Entity/Manager/...` mais une organisation par **rôle technique** :

```
src/Core/Module/
├── Contract/         ← Interfaces (ModuleInterface, ModuleToggleProviderInterface)
├── Controller/Dev/   ← PermissionsController (admin UI)
├── Enum/             ← ModuleToggleTypeEnum (Backend/Frontend)
├── Nav/              ← NavItem, NavSection, NavPermission (value objects nav)
├── Security/         ← ModulePermissionVoter
├── Service/          ← ModuleAccessChecker, ModuleRegistry, PermissionRegistry
├── Toggle/           ← ModuleToggle (VO) + ModuleToggleRegistry
└── View/             ← PermissionsViewBuilder
```

**Pourquoi** : 12 fichiers initialement au root → audit a montré que la
convention Entity-based ne s'applique pas (pas d'entité Doctrine ici). On
groupe par responsabilité : `Contract/` pour les interfaces, `Nav/` pour
les VO de navigation, `Toggle/` pour la machinerie d'activation, etc.

**Comment l'appliquer** : si tu ajoutes un nouveau composant à
`src/Core/Module/`, range-le par rôle (un nouveau voter → `Security/`, un
nouvel event → `Event/`, un nouveau service → `Service/`). Ne crée pas
un fichier au root.

## Anti-patterns

- ❌ Mélanger plusieurs entités dans un même dossier (ex:
  `src/Module/Editorial/{Post,Comment,Form}` partagent un dossier
  `Controller/`)
- ❌ Casser la convention pour gagner 1 ligne d'import (ex: mettre
  `PostInput` à côté de `PostManager` au lieu de `Dto/`)
- ❌ Créer un sous-dossier optionnel vide (`Service/` qui ne contient rien
  → ne pas créer)
