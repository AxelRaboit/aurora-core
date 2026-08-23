---
name: Convention privilege naming - <module_id>.<entity>.<action>
description: Tous les privilèges Aurora suivent le pattern uniforme préfixé par le module id du owner - y compris les sous-modules Core depuis le Jalon 5
type: project
---

## Règle

Chaque privilege déclaré via `NavPermission(...)` suit **strictement** le
pattern :

```
<module_id>.<entity>.<action>
```

où :
- `<module_id>` = le `getId()` du `<X>Module.php` qui DÉCLARE la permission
- `<entity>` = nom de la ressource (au singulier ou pluriel selon le module)
- `<action>` = `view`, `create`, `edit`, `delete`, ou un verbe métier
  (`moderate`, `regenerate`, `validate`, `sync`, …)

Pour les permissions globales d'un module (pas liées à une entité
spécifique), le pattern devient `<module_id>.<feature>.<action>` :

```
general.dashboard.view
general.search.view
vault.use
password_generator.use
```

## Exemples canoniques par module

```
// Core sub-modules (depuis Jalon 5)
general.dashboard.view              ← GeneralModule
general.search.view                 ← GeneralModule
platform.users.manage               ← PlatformModule
platform.users.module_access.manage       ← PlatformModule
platform.agencies.manage            ← PlatformModule
platform.services.manage            ← PlatformModule
media.view / .create / .edit / .delete   ← MediaModule
media.folders.create / .edit / .delete    ← MediaModule
configuration.settings.manage       ← ConfigurationModule
configuration.themes.manage         ← ConfigurationModule

// Modules métier (pattern identique)
editorial.posts.view / .create / .edit / .delete
editorial.comments.view / .moderate / .delete
crm.contacts.view / .create / .edit / .delete
billing.invoices.view / .create / .validate
photo.galleries.view / .create / .edit / .delete
...
```

## Pourquoi

**Pré-Jalon 5** : les permissions Core étaient toutes préfixées `core.*`
(`core.media.view`, `core.users.manage`, etc.) parce qu'un seul
`CoreModule` les déclarait toutes. Après le split en 5 sous-modules
(General/Platform/Media/Configuration/Dev), le préfixe `core.*` ne
correspondait plus à un module class réel.

**Bénéfices du nommage uniforme** :
- Un dev qui lit `media.folders.create` sait immédiatement que c'est
  déclaré dans `MediaModule.php` (`src/Module/Media/MediaModule.php`).
- Le `PermissionRegistry::byModule()` indexe naturellement sous le bon
  groupe sans `$group:` override (cf
  [[convention-navpermission-group]] devenu quasi obsolète).
- Le `MODULE_PRIORITY` dans `UsersViewBuilder` mappe 1:1 avec les
  préfixes.
- Pour un dev aurora-client qui ajoute un module, le pattern est le
  même : `<son_module>.<entity>.<action>`. Pas de cas spécial pour
  Core.

## Comment l'appliquer

### Ajouter une permission

1. Déterminer le module owner (celui qui contrôle la feature).
2. Préfixer avec son `getId()`.
3. Suivre la granularité CRUD (cf [[convention-privilege-granularity]] -
   préférer `view/create/edit/delete` à `manage` fourre-tout).
4. Déclarer dans `<Module>Module.php::getPermissions()`.
5. Ajouter les traductions FR + EN dans
   `<module-translations>/messages.{fr,en}.yaml` sous
   `backend.permissions.names.<module>.<entity>.<action>` (cf
   [[convention-privilege-translations]]).

### Audit de cohérence

Une permission `X.Y.Z` ne devrait jamais être déclarée par un module
dont le `getId()` ne retourne pas `X`. Si tu vois le contraire, c'est
soit un cas légitime pour `NavPermission::$group` override (rare), soit
un nettoyage à faire.

```bash
# Toutes les permissions et leur module de déclaration
grep -rn "new NavPermission(" src/ --include="*.php"
```

## Migration / clean break

Le Jalon 5 a renommé tous les `core.*` en leurs équivalents `<module>.*`
sans alias legacy (pas de prod). La migration Doctrine
`Version20260516180000.php` rename les valeurs en JSONB
`core_users.privileges` pour les DB dev existantes. Voir aussi
`Version20260516200000.php` (Jalon 5.1) pour le rename
`platform.search.view → general.search.view`.

## Anti-patterns

```
core.media.view          ❌ Préfixe `core.*` obsolète depuis Jalon 5
backend.users.manage     ❌ `backend.*` n'est pas un module id
crm.manage               ❌ Manque l'entity (granularité insuffisante)
editorial.post.list      ❌ Préférer `view` (action standardisée)
```

Voir aussi :
- [[convention-privilege-granularity]] - granularité CRUD obligatoire
- [[convention-privilege-translations]] - clés YAML alignées sur le nom
- [[pattern-core-submodules-split]] - pourquoi Core est éclaté en 5
