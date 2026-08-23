---
name: Add a custom module toggle (per-user access)
description: Comment exposer un module client (ex Tracking) dans la modale "Accès aux modules par user" du backend
type: project
---

## Règle

Pour qu'un module client apparaisse dans la modale **Accès aux modules**
de la fiche user backend (et bénéficie du gating per-user + cascade),
son `Module` class doit implémenter
`Aurora\Core\Module\Contract\ModuleToggleProviderInterface` en plus de
`ModuleInterface`.

```php
namespace App\Module\Tracking;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Core\Module\Toggle\ModuleToggle;

final readonly class TrackingModule implements ModuleInterface, ModuleToggleProviderInterface
{
    public function getId(): string { return 'tracking'; }
    // ... getPermissions, getNavSections, getCatalogNavSections

    public function getToggles(): array
    {
        return [
            new ModuleToggle(
                key: 'app_tracking_admin',                       // clé en core_settings + dans User::disabledModules
                labelKey: 'backend.modules.tracking',
                descriptionKey: 'backend.modules.tracking_description',
                moduleId: 'tracking',                            // top-level → apparaît dans le picker
            ),
            new ModuleToggle(
                key: 'app_tracking_pixels',
                labelKey: 'backend.nav.tracking_pixels',
                descriptionKey: 'backend.nav.tracking_pixels_description',
                parentKey: 'app_tracking_admin',                 // cascade : OFF si parent OFF
            ),
        ];
    }
}
```

## Pourquoi

Aurora-core fournit un `ModuleToggleRegistry` (cf. `pattern_user_scoped_module_access`
côté core) qui agrège **tous** les `ModuleToggleProviderInterface`.
Aucun patch sur l'enum core ou sur `UsersViewBuilder` n'est requis - le
mécanisme est strictement parallèle à `PermissionRegistry`.

## How to apply

1. Crée ta clé `app_<module>_<feature>` (préfixe `app_` recommandé pour
   distinguer des clés `backend_*` du core).
2. Ajoute les traductions FR + EN des `labelKey` et `descriptionKey` dans
   le YAML du module client.
3. **Top-level** (`moduleId` non-null) : le toggle apparaît dans la modale
   admin "Accès aux modules". Un seul par module.
4. **Sub-toggles** (`parentKey` non-null) : participent au cascade mais ne
   sont pas exposés dans la modale. Disabler le parent (global ou per-user)
   suffit à désactiver les enfants.
5. Côté Context du module client, route via `ModuleAccessChecker::isEnabled('app_tracking_admin')`
   ou via une dépendance directe au checker - jamais `SettingRepository::getBoolean()` direct.
6. Privilege admin de gestion : `platform.users.module_access.manage` (déjà fourni par core,
   réutilisable tel quel - pas besoin d'un nouveau privilege client).
