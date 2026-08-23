---
name: User-scoped module access pattern
description: Toggle modules par user via colonne JSON + ModuleAccessChecker central, en plus du toggle global dev-panel
type: project
originSessionId: 5f57072d-46e9-4159-86a2-abd011c4a049
---
## Pattern

Deux niveaux de toggle modules co-existent dans aurora-core :

- **Global** (dev panel, `ROLE_DEV`) : `core_settings` via `ModuleParameterEnum`
- **Par user** (`ROLE_DEV` + `ROLE_ADMIN`) : colonne JSON `core_users.disabled_modules`

**Single source of truth** : `Aurora\Core\Module\ModuleAccessChecker`. Tous
les `*Context` services (`BillingContext`, `CrmContext`, etc.) routent
`isXxxEnabled()` via ce service. Le cascade est appliqué à l'intérieur du
checker, donc plus de chained `&& $this->isParentEnabled()` dans les contexts.

**Source de vérité du cascade graph** : `ModuleToggleRegistry`, alimenté
par chaque module implémentant `ModuleToggleProviderInterface::getToggles()`.
Aurora-core et aurora-client utilisent le **même mécanisme** - un module
client (ex: Tracking) ajoute son toggle en implémentant cette interface
et apparaît automatiquement dans la modale "Accès aux modules". Plus aucun
patch sur l'enum ou `UsersViewBuilder` requis côté client.

**Why:** Permet de masquer un module à un user spécifique sans toucher au
global. L'admin gère l'accès (action organisationnelle), le dev gère
l'existence du module (action plateforme).

**How to apply:**
- Jamais appeler `settingRepository->getBoolean(ModuleParameterEnum::*)`
  directement - passer par le `*Context` ou directement par
  `ModuleAccessChecker::isEnabled()`.
- Pour ajouter un nouveau module **core** : suit le pattern existant
  (Context `final readonly`, `private ModuleAccessChecker $moduleAccessChecker`),
  une méthode par enum case. Le `*Module` implémente
  `ModuleToggleProviderInterface` et liste ses cases via `->toToggle()`.
- Pour ajouter un module **client** (aurora-client) : son `Module` class
  implémente `ModuleToggleProviderInterface` et renvoie ses propres
  `ModuleToggle` (clés string libres, hors enum). Voir
  `docs/aurora-core/dev/per_user_module_access.md` § 5.
- Le user-level override ne peut **rien activer** qui ne soit pas déjà ON
  globalement. La cascade est appliquée en interne récursivement.
- **Sanitization en écriture** : `UserManager::sanitizeDisabledModules()`
  interroge `ModuleToggleRegistry` (pas l'enum directement) pour valider
  les clés. C'est ce qui permet aux toggles client (`app_*`) d'être
  persistés. Si on oublie ça, le save renvoie 200 mais la liste arrive
  vide en DB.
- Anti-lockout : `UserManager::updateDisabledModules()` exige
  `canActOn($actor, $target)` quand un actor est passé. Empêche un admin
  de masquer des modules à un dev.

## Lieux clés

- Service : `src/Core/Module/ModuleAccessChecker.php`
- Registry : `src/Core/Module/ModuleToggleRegistry.php`
- VO + Interface : `src/Core/Module/ModuleToggle.php`, `ModuleToggleProviderInterface.php`
- Helper enum → toggle : `ModuleParameterEnum::toToggle()`
- Entité : `AbstractUser::$disabledModules` (JSON)
- Privilege : `platform.users.module_access.manage` (déclaré dans `PlatformModule`)
- Controller endpoint : `POST /backend/platform/users/{id}/disabled-modules`
- UI : `UsersApp.vue` + `useUsersDisabledModules.js` composable
- Doc complète : `docs/aurora-core/dev/per_user_module_access.md`
