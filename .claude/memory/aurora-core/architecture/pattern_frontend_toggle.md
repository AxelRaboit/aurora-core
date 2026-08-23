---
name: Per-front enable/disable via FrontendInterface + Registry
description: Chaque front (Editorial, Tracking, EcommerceFrontend, etc.) peut être désactivé indépendamment ; cascade vers /backend si tous off
type: project
---

## Pattern

Chaque "front" (site public servi par Aurora) implémente
`Aurora\Core\Frontend\Contract\FrontendInterface` et déclare :

- `getSlug()` - identifiant du front (`'editorial'`, `'tracking'`, …)
- `getHomeRoute()` - route nommée de sa page d'accueil
- `getModuleSettingKey()` - clé `ModuleParameterEnum` qui contrôle son
  on/off **dédié au front** (pas la même que l'admin module)
- `getRoutePrefixes()` - liste de préfixes de noms de routes qui lui
  appartiennent (utilisée par le route gate)

**Le toggle d'un front est SÉPARÉ du toggle de son admin module.**
Ex : `EditorialBackend` = backend admin Editorial activé ; `EditorialFrontend` =
site public Editorial servi. C'est `EditorialFrontend` qui est
retourné par `EditorialFrontend::getModuleSettingKey()`. Le sub-toggle
front a son parent en cascade (`requires = EditorialBackend`), donc
désactiver l'admin désactive aussi le front.

## Pourquoi

Le site public peut être en maintenance pendant que l'admin reste
fonctionnel (corriger du contenu, etc.). Ou inversement, l'admin peut
être désactivé tandis que le front reste servi (cas rare mais possible).
Bref : deux axes indépendants.

## Comment l'appliquer

### Pour un nouveau front

```php
final class TrackingFrontend implements FrontendInterface
{
    public function getSlug(): string { return 'tracking'; }
    public function getLabel(): string { return 'Suivi'; }
    public function getHomeRoute(): string { return 'tracking_front_home'; }
    public function getPriority(): int { return 5; }
    public function getModuleSettingKey(): ?string
    {
        // Soit une nouvelle clé `ModuleParameterEnum::TrackingFrontEnabled`
        // (recommandé pour cohérence), soit null = always on.
        return null;
    }
    public function getRoutePrefixes(): array
    {
        // CRITIQUE : ces préfixes doivent matcher TOUTES les routes du front
        // (pas l'admin). L'admin Tracking utilise `tracking_projects_*` ;
        // le front utilise `tracking_front_*`. Les préfixes ne doivent
        // pas se chevaucher entre admin et front sinon le gate 404 les routes admin.
        return ['tracking_front_'];
    }
}
```

### Pour un module core qui ajoute un front

1. Ajouter une case sub-toggle à `ModuleParameterEnum` :
   `case XxxFrontEnabled = 'front_xxx';` avec parent =
   `XxxEnabled` (cascade) et `getModuleId()` qui retourne `null`
   (sous-toggle, pas top-level).
2. Le `XxxFrontend::getModuleSettingKey()` retourne cette nouvelle clé.
3. Translations FR + EN sous `backend.modules.xxx_front` + `xxx_front_description`.

### Cascade de redirection quand tous les fronts sont off

`RootDispatchController::root()` (route `frontend_root`, URL `/`) :
- Cherche le premier front activé via `Registry::all()` (triés par priorité)
- Aucun → `redirectToRoute('backend_dashboard')`
- Et `GeneralRouteGateSubscriber` redirige `/backend` → `/backend/general/profile`
  si Dashboard masqué pour l'user

Donc la chaîne `/ → /backend → /backend/general/profile` fonctionne
automatiquement.

### Sidemenu "Voir le site"

`FrontendExtension` (Twig) expose `has_enabled_fronts()`. Le layout passe
`hasEnabledFronts` à `AppSidemenu.vue`, qui gate le lien "Voir le site"
avec `v-if="hasEnabledFronts"`.

## Pièges

1. **Préfixes mal choisis** : `'tracking_'` matcherait à la fois
   `tracking_projects` (admin) et `tracking_front_home` (front). Le gate
   404'rait les routes admin. TOUJOURS préfixer le front
   différemment de l'admin (`tracking_front_` vs `tracking_projects_`).

2. **`getRoutePrefixes()` vide** : autorisé si le front utilise des routes
   qui ne suivent aucun préfixe, mais alors aucun 404 automatique. Les
   per-controller `IsGranted` restent applicables.

3. **Cascade implicite** : si le front sub-toggle a `parent = AdminEnabled`,
   désactiver l'admin désactive aussi le front. C'est généralement ce
   qu'on veut. Sinon, ne pas mettre de parent.

4. **`frontend_root` n'est PAS gated** par `FrontendRouteGateSubscriber`
   (le contrôleur a sa logique de redirect propre). Le subscriber skip
   explicitement cette route.

## Lieux clés

- Interface : `src/Core/assets/Contract/FrontendInterface.php`
- Registry : `src/Core/assets/Service/Registry.php`
- Route gate : `src/Core/assets/EventSubscriber/FrontendRouteGateSubscriber.php`
- Root dispatch + redirect cascade : `src/Core/assets/Controller/RootDispatchController.php`
- Twig helper : `src/Core/assets/Twig/FrontendExtension.php` (`has_enabled_fronts()`)
- Implémentations actuelles : `Aurora\Module\Editorial\EditorialFrontendDescriptor`,
  `Aurora\Module\Ecommerce\EcommerceFrontendDescriptor`,
  `Aurora\Module\Photo\PhotoFrontendDescriptor`,
  `Aurora\Module\Ged\GedFrontendDescriptor`,
  `App\Module\Tracking\Frontend\TrackingFrontend` (aurora-client)
- Convention de nommage : suffixe **`FrontendDescriptor`** au niveau racine
  du module - voir [`pattern_frontend_descriptor.md`](pattern_frontend_descriptor.md).

## Voir aussi

- [`pitfall_route_gate_priority.md`](pitfall_route_gate_priority.md) -
  `FrontendRouteGateSubscriber` priorité 0 (après firewall)
- [`pattern_user_scoped_module_access.md`](pattern_user_scoped_module_access.md) -
  ModuleAccessChecker (global + per-user) consommé indirectement par
  le contexte des admin modules
