---
name: Architecture ModuleParameterEnum
description: Toggles de modules — depuis le monorepo-split, chaque module métier a son <Module>ModuleParameterEnum + provider ; l'enum central est core-infra only
type: project
---

## Règle

Les paramètres "module on/off" vivent dans un enum dédié, **séparément** d'`ApplicationParameterEnum`
(paramètres applicatifs : SEO, séquences, seuils…). Tous implémentent
`ApplicationParameterEnumInterface`, groupe `'modules'`.

**Depuis le monorepo-split (2026-05-30)** la propriété est distribuée :

- **Chaque module métier** porte ses toggles dans son **propre**
  `Aurora\Module\<Module>\Setting\<Module>ModuleParameterEnum` + un
  `<Module>ModuleParameterProvider` (implements `ApplicationParameterProviderInterface`,
  `yield from <Module>ModuleParameterEnum::cases()`). C'est ce qui rend le module
  installable à la carte (le package embarque ses propres toggles).
- **L'enum central** `Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum`
  est désormais **core-infra only** : General / Platform / Configuration / Media / Ged
  (~20 cases). Il ne connaît plus aucun module métier. **Ne JAMAIS y rajouter
  un toggle de module métier** (régression #1 post-split).

**Why:** séparation config applicative ↔ activation modules (le groupe `'modules'`
est filtré hors de l'onglet Parameters par `SettingRepository::findPaginated`) ;
et distribution Composer (un module léger n'embarque pas les toggles des autres).

## Forme d'un `<Module>ModuleParameterEnum` (mirror : `ToolsModuleParameterEnum`, `NotesModuleParameterEnum`)

- Cases **courtes** : `Backend`, `<Sub1>`, `Frontend`… La **valeur** garde la clé
  legacy `modules_<module>_<feature>` (pas de migration BDD au split).
- `getType()` → `'bool'`, `getGroup()` → `'modules'`, `getDefaultValue()` → `'1'`.
- `getModuleId(): ?string` → `'<module>'` pour `Backend`, null sinon (résolution navItems).
- `getCascadeRequires(): ?string` → `self::Backend->value` pour les sous-cases, null pour `Backend`
  (le prérequis qui force l'enfant à `'0'` quand le parent est OFF).
- `getDisplayParent(): ?string` → idem (hiérarchie d'affichage dashboard). **Remplace**
  l'ancien `getParentCase()` du monolithe.
- `toToggle(): ModuleToggle` → `{key, labelKey, descriptionKey, parentKey (=cascade),
  moduleId, displayParentKey}`. Consommé par `<Module>Module::getToggles()`.
- `match ($this)` de `getLabel`/`getDescription` **exhaustif** (pas de `default`) :
  forcing function pour ne pas oublier d'arm en ajoutant une sous-case.

> Cascade intra-module générique (ternaire `self::Backend === $this ? null : self::Backend->value`,
> cf. Notes) → ajouter une sous-case ne touche que `getLabel`/`getDescription`.
> Si l'enum utilise un `match` (cf. Photo : Frontend/Galleries), ajouter aussi l'arm cascade.

## Câblage du toggle au runtime

- `<Module>Context::isBackendEnabled()` → `moduleAccessChecker->isEnabled(<Module>ModuleParameterEnum::Backend->value)`.
  **Passer `->value` (string)** : l'enum par-module ne satisfait pas le type-hint de l'enum
  central, et `ModuleAccessChecker::isEnabled()` accepte `ModuleParameterEnum|string`.
- Le provider est tagué `aurora.application_parameter_provider` (par le `config/services.php`
  du package, ou le `_instanceof` central dans le monorepo). Sans lui,
  `aurora:application-parameter` flague les rows obsolètes et les wipe.

## Consommateurs cross-module

Registry-driven (lisent l'union de tous les `getToggles()` / providers), donc agnostiques
au lieu de stockage : `SettingsService` (cascade), `ModulesViewBuilder`, `UsersViewBuilder`
(`getModuleId()`), `ApplicationParameterCommand` (sync BDD), `ModuleToggleRegistry`.
`DashboardViewBuilder` / `MenuRenderer` référencent des clés string.

## How to apply

- Nouveau module → `/add-module` génère `<Module>ModuleParameterEnum` + provider + bundle.
- Nouvelle sous-feature → `/add-submodule` ajoute une `case <Sub>` dans le
  `<Module>ModuleParameterEnum` du parent (jamais l'enum central).
- Vérifier le câblage → `/audit-module-toggles` (un module métier resté dans l'enum central = `❌`).
- Après ajout → `make sf CMD="aurora:application-parameter"` pour seed les rows.
- `ApplicationParameterEnum` ne contient jamais de case module.

## Liens

- [[project_monorepo_split_chantier]] — le chantier qui a distribué les enums.
- [[pattern_core_submodules_split]] — "1 module = 1 toggle root = 1 context".
- Outils alignés sur ce pattern : skills `/add-module`, `/register-module-toggle`,
  `/audit-module-toggles`, `/add-submodule` + doc `docs/aurora-core/dev/add_module.md`.
