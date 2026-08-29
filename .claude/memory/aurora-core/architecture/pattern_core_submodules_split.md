---
name: pattern-core-submodules-split
description: Core est éclaté en 5 ModuleInterface implémentations (GeneralModule, PlatformModule, MediaModule, ConfigurationModule, DevModule), pas en 1 monolithe - pour respecter le pattern "1 module class = 1 NavSection = 1 toggle root = 1 context".
metadata:
  type: project
---

## Règle

Aurora-core expose **cinq** `<Name>Module.php` au lieu d'un seul
`CoreModule.php` god-class. Depuis 0.4.0, ces 5 modules vivent sous
`src/Module/` (et non plus `src/Core/`) pour aligner avec les modules
métier (`src/Module/Vault/`, `src/Module/Editorial/`, etc.) :

- **`GeneralModule`** (priority 10) - Dashboard. Toggle root :
  `GeneralBackend`. Context : `GeneralContext`. Section id : `general`.
- **`PlatformModule`** (priority 20) - Users, Agencies, Services
  (organization layer). Toggle root : `PlatformBackend`. Context :
  `PlatformContext`. Section id : `platform`.
- **`MediaModule`** (priority 22) - Média library. Toggle root :
  `MediaBackend`. Context : `MediaContext`. Section id : `media`. Split
  hors de Platform en Jalon 4.5 car cross-cutting (consommé par tous les
  modules métier).
- **`ConfigurationModule`** (priority 25) - Settings, Themes. Toggle root :
  `ConfigurationBackend`. Context : `ConfigurationContext`. Section id :
  `configuration`.
- **`DevModule`** (priority 1000) - Admin/dev tools. Pas de toggle (gated
  par `ROLE_DEV` au niveau du NavItem), pas de context. N'implémente pas
  `ModuleToggleProviderInterface`.

Chacun suit exactement le shape de `VaultModule`/`EditorialModule` côté
modules métier. Auto-discovery via tag `aurora.module` (services.yaml,
`_instanceof`).

Route gating est aussi split par module : `PlatformRouteGateSubscriber`
gate Users/Agencies/Services, `MediaRouteGateSubscriber` gate
`backend_media_media*`, `ConfigurationRouteGateSubscriber` gate Settings/Themes.

## Pourquoi

Avant Jalon 4 : `CoreModule.php` produisait 4 NavSections, déclarait les
toggles et permissions des 4 sections, injectait `PlatformContext` ET
`GeneralContext`. Trois incohérences cumulées :

1. **Classe vs sections** : 1 fichier PHP pour 4 sections, alors que les
   modules métier suivent strictement "1 fichier = 1 section".
2. **Toggle hierarchy** : `Settings`/`Themes` étaient enfants de
   `PlatformBackend` même après le split visuel "Configuration" - donc
   désactiver Platform désactivait Configuration, contre-intuitif.
3. **Labels divergents** : la modale Privilèges affichait "Configuration",
   la modale Module-Access affichait "Plateforme → Réglages/Thèmes" pour
   les deux mêmes items.

Le split règle les trois.

Le pattern devient enfin uniforme : un dev aurora-client qui veut ajouter
son propre module peut copier-coller n'importe quel `<X>Module.php`
(business OU core) - même shape partout.

## Comment l'appliquer

**Ajouter une nouvelle section Core** (rare) :
- **Module class** (`<Name>Module.php`) : à la racine du folder du module,
  `src/Module/<Name>/<Name>Module.php`, namespace `Aurora\Module\<Name>\<Name>Module`.
- **Sous-modules** du module : nichés sous `src/Module/<Name>/<SubModule>/`
  (depuis 0.4.0, cf. [[decision_core_submodule_nesting]]). Exemple :
  `src/Module/Platform/User/`, `src/Module/Configuration/Setting/`,
  `src/Module/General/Dashboard/`.
- Ajouter la case `<Name>Backend` dans `ModuleParameterEnum` + son context
  dans `src/Module/<Name>/<Name>Context.php` (le Context vit à la racine du
  folder du module, à côté de ses sous-modules - convention unifiée
  core+business depuis 0.4.0).
- L'auto-discovery fait le reste (services.yaml `_instanceof`, Twig glob,
  translations glob à depth 2).

**Renommage de section.id** : la section "Dashboard" a vu son id passer
de `'core'` à `'general'` pour aligner avec `getModuleId()`. Migration
Doctrine `Version20260516120000` gère le rename des
`nav_section_aliases.core` → `.general` pour préserver les alias admin.

**Cascade des toggles** : `PlatformSettings`/`PlatformThemes` ont été
renommés `ConfigurationSettings`/`ConfigurationThemes` (case ET value
persistée - `modules_platform_settings` → `modules_configuration_settings`).
Cascade reparented : ils dépendent de `ConfigurationBackend` au lieu de
`PlatformBackend`. La migration préserve l'intention "Platform off" en
posant explicitement `modules_configuration_backend = '0'` si Platform
était off pré-migration.

**Tests à mettre à jour** : aucun. Le shape étant uniforme, les tests
existants (`ModulesViewBuilderTest`, `UsersViewBuilderTest`,
`PermissionRegistryTest`) traitent les 4 modules core comme n'importe
quel autre module - pas de cas spécial.

Voir aussi [[pattern_configuration_tab_provider]] (Phase A/B/C settings
extensibility - préalable à ce Jalon 4) et
[[architecture_module_parameter_enum]] pour la cascade graph.
