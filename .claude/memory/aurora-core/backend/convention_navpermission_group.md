---
name: NavPermission $group override (rarement nécessaire post-Jalon 5)
description: Le paramètre $group de NavPermission existe encore mais sert rarement depuis que Core a été éclaté en 5 modules - chaque permission est déclarée par son module owner naturel.
type: project
---

## Règle

`NavPermission` accepte un paramètre optionnel `$group` (string) qui
**override le module-id par défaut** utilisé pour grouper la permission
dans la modale Privilèges.

```php
new NavPermission('some.feature.action', group: 'other_module'),
```

**Quand l'utiliser : presque jamais.** Depuis le Jalon 4 (CoreModule
splitté en GeneralModule + PlatformModule + MediaModule +
ConfigurationModule + DevModule) et le Jalon 5 (renaming
`<module_id>.<entity>.<action>` partout), chaque permission est
déclarée par son module owner naturel. Le `getId()` du module renvoie
exactement le préfixe attendu → pas besoin d'override.

## Pourquoi le pattern existe encore

Cas conceptuel résiduel : un module PHP responsable de plusieurs
sections d'UI distinctes, où une permission appartient
*conceptuellement* à une autre section. Ce cas ne se produit plus
aujourd'hui - la convention est `1 module class = 1 section`
(cf [[pattern-core-submodules-split]]).

Garder le mécanisme évite de fermer la porte à un futur cas tordu
(ex : un module métier qui exposerait une permission cross-cutting
voulue dans une autre section).

## Comment l'appliquer (au cas où)

**Quand l'utiliser** :
- Tu déclares une permission dans `MyModule` mais elle gate une feature
  visuellement rattachée à `OtherModule`.

**Quand NE PAS l'utiliser** :
- Convention par défaut (99% des cas) : déclarer chaque permission
  dans le module qui la possède. Le `getId()` aligne automatiquement
  avec `MODULE_PRIORITY` dans `UsersViewBuilder`.

## Lieux clés

- VO : `src/Core/Module/Nav/NavPermission.php` (paramètre `$group`)
- Indexing : `src/Core/Module/Service/PermissionRegistry.php`
  (utilise `permission->group ?? module->getId()`)
- Ordre d'affichage : `src/Core/User/View/UsersViewBuilder.php`
  (`MODULE_PRIORITY` const)

Voir [[convention-privilege-naming]] pour la règle de nommage uniforme
qui a rendu ce `$group` override quasi obsolète.
