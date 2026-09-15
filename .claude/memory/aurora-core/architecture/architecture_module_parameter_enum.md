---
name: Architecture ModuleParameterEnum
description: Toggles de modules - un seul enum central, y compris pour les modules métier ; la distribution par module n'existe plus dans le code
type: project
---

## Règle

Les paramètres "module on/off" vivent dans un enum dédié, **séparément**
d'`ApplicationParameterEnum` (paramètres applicatifs : SEO, séquences,
seuils…). Tous implémentent `ApplicationParameterEnumInterface`, groupe
`'modules'`.

**Il y a exactement un enum** :
`Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum`. Il porte les
toggles de **tous** les modules, infra comme métier : General, Platform,
Configuration, Media, Editorial, Ged, Planning, Notes, Studio. Un seul
provider l'expose, `CoreModuleParameterProvider`.

**Why:** séparation config applicative ↔ activation modules (le groupe
`'modules'` est filtré hors de l'onglet Parameters par
`SettingRepository::findPaginated`).

## ⛔ Correction du 15/09/2026 : la distribution par module n'existe pas

Cette mémoire affirmait jusqu'ici que chaque module métier portait son propre
`<Module>ModuleParameterEnum` + provider, et que rajouter un toggle métier dans
l'enum central était « la régression #1 post-split ». **C'est faux, et ça l'est
sur le disque** :

```bash
find src/Module -name '*ModuleParameterEnum.php'
# → src/Module/Configuration/Setting/Enum/ModuleParameterEnum.php, et rien d'autre
```

La distribution a appartenu au split en dépôts `aurora-*`, abandonné en août
2026 (cf. [[project_monorepo_split_chantier]]). Les enums par module sont
partis avec lui. La mémoire, elle, avait gardé la version d'après-split, et
elle envoyait donc créer un fichier qui n'a aucun équivalent dans le dépôt.

**Ne pas y croire sans vérifier vaut aussi pour les skills.** `/add-module`,
`/add-submodule`, `/audit-module-toggles` et `/register-module-toggle`
décrivent tous le même monde disparu (`<Module>ModuleParameterEnum`,
`getDisplayParent()`, un provider par package). `/audit-module-toggles`
signalerait aujourd'hui ❌ tous les modules du dépôt. Ces quatre skills
restent à reprendre.

## Forme de l'enum

- Case **explicite et préfixée** : `StudioBackend`, `StudioCustomers`,
  `GedDocuments`… et non `Backend` court. La **valeur** est la clé BDD
  `modules_<module>_<feature>`, sans suffixe `_enabled`.
- `getType()` → `'bool'`, `getGroup()` → `'modules'`, `getDefaultValue()` → `'1'`.
- `getModuleId(): ?string` → `'<module>'` pour les cases `*Backend`, null sinon.
- `getParentCase(): ?self` → la case racine du module (hiérarchie d'affichage).
  Il n'y a **pas** de `getDisplayParent()`.
- `getCascadeRequires(): ?string` → la clé qui doit être active avant celle-ci.
  Ce n'est pas forcément le `*Backend` du module : `StudioContracts` et
  `StudioSpaces` dépendent de `StudioCustomers`, parce qu'un contrat et un
  espace s'adressent tous deux à un client.
- `getCascadeDisableTargets()` est dérivé des deux précédents, rien à écrire.
- `toToggle(): ModuleToggle`, consommé par `<Module>Module::getToggles()`.
- Les `match ($this)` de `getLabel`/`getDescription` sont **exhaustifs** (pas de
  `default`) : c'est la forcing function qui fait échouer la compilation quand
  on ajoute une case sans la libeller. `getParentCase` et `getCascadeRequires`
  ont un `default => null`, eux.

## Câblage au runtime

- `<Module>Context::is<Sub>Enabled()` →
  `moduleAccessChecker->isEnabled(ModuleParameterEnum::<Case>)`. La case elle-même,
  pas `->value` : le type-hint accepte les deux, et les contextes existants
  passent la case.
- Le provider est tagué `aurora.application_parameter_provider` par le
  `_instanceof` de `config/services.yaml`. Sans le tag,
  `aurora:application-parameter` flague les rows comme obsolètes et les wipe.

## Consommateurs cross-module

Registry-driven (lisent l'union des `getToggles()`), donc agnostiques au
stockage : `SettingsService` (cascade), `ModulesViewBuilder`,
`UsersViewBuilder` (`getModuleId()`), `ApplicationParameterCommand` (sync BDD),
`ModuleToggleRegistry`.

## How to apply

- Nouvelle sous-feature → ajouter une `case <Module><Sub>` dans l'enum central,
  ses arms `getLabel`/`getDescription`, son `getParentCase`, son
  `getCascadeRequires`, puis `->toToggle()` dans `<Module>Module::getToggles()`.
- Après ajout → `php bin/console aurora:application-parameter` pour seed la row.
- `ApplicationParameterEnum` ne contient jamais de case module.

## Liens

- [[project_monorepo_split_chantier]] - le chantier qui avait distribué les enums,
  et qui les a remportés en étant abandonné.
- [[pattern_core_submodules_split]] - "1 module = 1 toggle root = 1 context".
- [[project_studio_customer_spaces]] - le lot qui a trouvé cette mémoire fausse.
