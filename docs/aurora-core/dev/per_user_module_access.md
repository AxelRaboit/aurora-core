# Accès aux modules par utilisateur

## Vue d'ensemble

Aurora-core supporte deux niveaux de toggle pour les modules :

1. **Global** (dev panel, `ROLE_DEV`) : décide quels modules existent dans
   l'application. Stocké dans `core_settings` via `ModuleParameterEnum`.
2. **Par utilisateur** (`ROLE_DEV` + `ROLE_ADMIN`) : masque sélectivement
   des modules pour un user donné. Stocké dans la colonne JSON
   `core_users.disabled_modules`.

Les deux couches sont consultées via un **service central**
[`ModuleAccessChecker`](../../../src/Core/Module/ModuleAccessChecker.php), qui
applique aussi le **cascade graph** existant (`getCascadeRequires()`).

> **Important** : un user-level override ne peut **rien activer** qui ne soit
> pas déjà ON globalement. Si Dev désactive `CrmBackend`, aucun admin ne peut
> le ré-activer pour un user spécifique.

---

## 1. Architecture

```
┌─────────────────────────┐
│ controller / sidemenu    │
└──────────┬──────────────┘
           │ XxxContext::isYyyEnabled()
           ▼
┌─────────────────────────┐
│ Module *Context service │  (12 services, un par module)
└──────────┬──────────────┘
           │ ModuleAccessChecker::isEnabled(enum|string)
           ▼
┌─────────────────────────┐
│ ModuleAccessChecker     │
│  1. global setting      │  ← SettingRepository
│  2. user override       │  ← User::getDisabledModules()
│  3. cascade graph       │  ← ModuleToggleRegistry
└──────────┬──────────────┘
           │ aggregates from
           ▼
┌─────────────────────────┐
│ ModuleToggleRegistry    │
│  ← every module that    │
│    implements           │
│    ModuleToggleProvider │
│    Interface            │
│  (core + aurora-client) │
└─────────────────────────┘
```

**Toute consultation** d'un toggle module passe par `*Context`, jamais par
`SettingRepository::getBoolean()` directement. Voir
[`pitfall_module_context_bypass.md`](#) si vous êtes tenté de le faire.

### Conséquence pour `RouteGateSubscriber`

Les `XxxRouteGateSubscriber` consomment déjà `XxxContext::isBackendEnabled()`.
Ils deviennent automatiquement user-aware sans changement - un user dont
le module est masqué reçoit un 404 sur les routes du module concerné.

---

## 2. Modèle de données

### Colonne `disabled_modules` sur `core_users`

```php
/** @var list<string> Toggle keys masked for this user (core enum + client toggles) */
#[ORM\Column(type: 'json', options: ['default' => '[]'])]
protected array $disabledModules = [];
```

Chaque entrée est une clé de toggle déclarée dans le `ModuleToggleRegistry`
(soit un `ModuleParameterEnum::value` ex. `modules_crm_backend`, soit une
clé client ex. `app_tracking_backend`). Les valeurs non déclarées sont
silencieusement filtrées par `UserManager::sanitizeDisabledModules()`,
qui interroge le registry comme source de vérité.

### Pourquoi JSON et pas une table dédiée ?

- ~37 modules max → 2 KB par user
- Chargé avec le User en une requête (comme `privileges`)
- Pas de N+1 sur sidemenu / route gate

Une table dédiée serait nécessaire si on voulait :
- des reportings « qui a accès à X » sans full table scan
- un historique des modifications (mais l'AuditLogger couvre déjà ça)

---

## 3. Sécurité - qui peut modifier quoi

| Action | Rôle requis | Privilege |
|---|---|---|
| Activer/désactiver un module globalement | `ROLE_DEV` | n/a (dev panel) |
| Modifier `disabledModules` d'un user | `ROLE_DEV`, `ROLE_ADMIN`, ou user avec `platform.users.module_access.manage` | `platform.users.module_access.manage` |

**Garde-fou de rang** dans `UserManager::updateDisabledModules()` :
un actor ne peut modifier les `disabledModules` que d'un user de rang ≤ le
sien (`canActOn`). Empêche qu'un admin masque des modules à un dev.

Aucune « critical module » constante n'est définie : le module User est dans
Core, pas dans `ModuleParameterEnum`. Aucune valeur de l'enum ne peut donc
locker un admin hors de sa propre page de gestion.

---

## 4. UI admin

Une icône `LayoutGrid` apparaît dans la table users (`UserRowActions.vue`)
quand toutes ces conditions sont remplies :

- `canManageDisabledModules` est `true` (privilege accordé à l'utilisateur courant)
- `modulesForAccess.length > 0` (au moins un module activé globalement)
- l'actor peut agir sur la cible (`canAct`)
- la cible n'est pas un Dev (`!user.isDev`)

Le clic ouvre une modale avec une **arborescence** de checkboxes :
- au niveau racine, un toggle par module top-level (CRM, Vault, …) ;
- en-dessous, indentés sur la gauche, les sous-modules (Contacts, Deals,
  Tiers, …) - gérables individuellement.

Sémantique :
- décocher un parent grise visuellement tous ses enfants (cascade) et
  rend la modification d'un enfant inopérante côté serveur (la cascade
  est de toute façon appliquée par `ModuleAccessChecker`) ;
- un enfant peut être désactivé sans toucher au parent (granularité
  fine - ex: laisser CRM mais cacher Deals).

### Sémantique des checkboxes

- **Cochée** = le module est visible pour le user (valeur **absente** de `disabledModules`)
- **Décochée** = masqué (valeur **présente** dans `disabledModules`)

L'absence = par défaut visible, ce qui évite toute migration de données
lors de l'ajout d'un nouveau module.

---

## 5. Extension côté client

### Ajouter un module client (ex: Tracking) au picker

Le client n'a **rien à patcher dans aurora-core**. Sa classe Module
implémente simplement `ModuleToggleProviderInterface` :

```php
namespace App\Module\Tracking;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Core\Module\Toggle\ModuleToggle;

final readonly class TrackingModule implements ModuleInterface, ModuleToggleProviderInterface
{
    public function getId(): string { return 'tracking'; }
    public function getPermissions(): array { return []; }
    public function getNavSections(): array { /* ... */ }
    public function getCatalogNavSections(): array { /* ... */ }

    public function getToggles(): array
    {
        return [
            new ModuleToggle(
                key: 'app_tracking_admin',
                labelKey: 'backend.modules.tracking',
                descriptionKey: 'backend.modules.tracking_description',
                moduleId: 'tracking',          // top-level → apparaît dans le picker admin
            ),
            new ModuleToggle(
                key: 'app_tracking_pixels',
                labelKey: 'backend.nav.tracking_pixels',
                descriptionKey: 'backend.nav.tracking_pixels_description',
                parentKey: 'app_tracking_admin', // cascade : OFF si parent OFF
            ),
        ];
    }
}
```

Une fois le service tagué `aurora.module` (auto-configuré si le module
réside dans `App\Module\...` selon la convention `aurora-client`), il :
- apparaît automatiquement dans la modale "Accès aux modules" du user ;
- bénéficie de la cascade ;
- peut être désactivé per-user comme n'importe quel module core.

Aucun changement requis sur `ModuleParameterEnum`, `ModuleAccessChecker`
ou `UsersViewBuilder`.

### Substituer la liste de modules exposés

Override `UsersViewBuilder` côté client si tu veux ajouter une logique
de tri / regroupement personnalisée. Les sous-modules sont déjà exposés
par défaut (cf. `buildToggleNode()`).

### Ajouter un « critical module » (futur)

Aurora-core n'expose pas encore de constante `CRITICAL_MODULES`. Si tu as
un module qui ne doit jamais être masqué (ex: un dashboard métier
indispensable au login d'un user), tu peux :

1. Override `UserManager::updateDisabledModules()` dans
   `App\Module\<Mirror>\<User>\Manager\UserManager` (extends).
2. Throw `InvalidArgumentException` si la liste contient un module critique.

Aurora-core ajoutera ce mécanisme nativement si plusieurs clients en ont besoin.

---

## 6. Tests

- [`tests/Unit/Module/ModuleAccessCheckerTest.php`](../../../tests/Unit/Module/ModuleAccessCheckerTest.php)
  - matrice complète (global × user × cascade).
- Tous les `tests/Unit/Module/*/Service/*ContextTest.php` ont été
  réécrits pour stub `ModuleAccessChecker` au lieu de `SettingRepository`.
- 746 tests verts à l'introduction (commit de rollout).

---

## 7. Pièges connus

1. **Cache `ModuleAccessChecker::globalCache`** : scope request seulement.
   Si tu modifies un setting dans la même requête, le cache devient stale.
   Symétrique du cache de `SettingRepository`.
2. **Anonymous user** : `isEnabled()` ne consulte que le setting global +
   cascade quand aucun user n'est authentifié (login page, public routes).
3. **`new ModuleAccessChecker(...)`** : dépend de `Security` (de
   `SecurityBundle`), pas du token storage brut. Stable en mock.
