---
name: decision-core-submodule-nesting
description: Décision (mai 2026) - les sous-modules Core (User, Agency, Service, Auth, Setting, Theme, Audit, Dashboard, Profile, Search, Media) vivent désormais sous le dossier de leur module parent (Aurora\Module\Platform\User, etc.) pour aligner avec la convention Vault-style déjà en place côté src/Module/.
metadata:
  type: project
---

# Décision : nesting des sous-modules Core sous leur module parent

## Décision (mai 2026, finalisée dans 0.4.0)

**Tout module (avec NavItem dans la sidemenu) vit sous `src/Module/`.**
Plus de séparation src/Core ≠ src/Module pour les modules - ils sont
tous au même endroit. `src/Core/` héberge **uniquement** de l'infrastructure
cross-cutting (sans NavItem propre).

| Module | Localisation finale |
|---|---|
| `PlatformModule` | `src/Module/Platform/` (sous-modules : User, Agency, Service, Auth) |
| `ConfigurationModule` | `src/Module/Configuration/` (Setting, Theme) |
| `GeneralModule` | `src/Module/General/` (Dashboard, Profile, Search) |
| `MediaModule` | `src/Module/Media/` (Library) |
| `DevModule` | `src/Module/Dev/` (Audit, MountPoint, Prerequisite) |
| Business modules | `src/Module/{Editorial,Vault,Crm,Billing,...}/` (déjà là) |

Le **fichier `<X>Module.php` vit à la racine du folder du module** :
`src/Module/Platform/PlatformModule.php`, `src/Module/Editorial/EditorialModule.php`,
etc. Convention symétrique entre tous les modules (Core et business).

## Why

Avant : 2 conventions co-existaient. Côté `src/Module/` (Vault, Notes,
Editorial…) les sous-modules vivaient dans un sous-dossier
(`src/Module/Vault/Safe/`, `src/Module/Notes/Markdown/`). Côté
`src/Core/`, les sous-modules étaient à plat (`src/Core/User/`,
`src/Core/Agency/` - alors que ces 3 entités appartiennent à
`PlatformModule`). Incohérence pénible :

- **Discoverabilité** : un dev nouveau cherchant "tout ce qui est
  Platform" doit grep, pas `cd`. Idem pour Configuration, Media, etc.
- **Cohésion logique** : module = vue abstraite (sidemenu + permissions
  + toggles) dispersée à travers le tree de fichiers.
- **Skills Claude / scaffold** : `/add-module` et `/add-submodule`
  doivent encoder 2 conventions au lieu d'1 - friction sur l'outillage.

## How to apply

### Pour un nouveau sous-module Core

Toujours créer sous `src/Core/<ParentModule>/<SubModule>/`. Exemple :
ajouter une sub-feature `Webhook` à `ConfigurationModule` →
`src/Module/Configuration/Webhook/Entity/Webhook.php`, namespace
`Aurora\Module\Configuration\Webhook\Entity\Webhook`.

### Pour un nouveau module Core (rare)

`<Name>Module.php` reste flat : `src/Core/<Name>Module.php`, namespace
`Aurora\Core\<Name>Module`. Ses sous-modules vont sous `src/Core/<Name>/`.

### Pour aurora-client qui étend une entité Aurora

Le chemin client miroir le namespace Aurora. Avant : `Aurora\Core\Agency`
→ `src/Module/Platform/Agency/` côté client. Après : `Aurora\Module\Platform\Agency`
→ `src/Module/Platform/Agency/`. Cf.
[`convention_module_structure.md`](../../aurora-client/convention_module_structure.md)
(à mettre à jour côté client si pas déjà fait).

### Pour les contextes des modules (PlatformContext, VaultContext, etc.)

**Convention unique** : `<racine-du-module>/<Module>Context.php` (à la
racine du folder du module, à côté des sous-modules). S'applique core
ET business :

- **Core** : `src/Module/Platform/PlatformContext.php`,
  `src/Module/Configuration/ConfigurationContext.php`,
  `src/Module/Media/MediaContext.php`,
  `src/Module/General/GeneralContext.php`.
- **Business** : `src/Module/Vault/VaultContext.php`,
  `src/Module/Editorial/EditorialContext.php`, etc. (12 modules).

L'historique : avant l'alignement, les Core contexts vivaient à
`src/Core/Service/`, puis à `src/Core/Module/Context/` (étape
intermédiaire éphémère), avant d'atterrir à la racine du folder du
module. Les Business contexts vivaient à
`src/Module/<X>/Service/<X>Context.php` (10/12 modules n'avaient QUE le
Context dans Service/ - dossier trompeur). Le pattern actuel reflète
mieux la réalité : le Context **appartient** au module et vit à sa
racine, comme `<Module>FrontendDescriptor.php`.

Le dossier `Service/` reste valide pour des **vrais services métier**
(ex. `Crm/Service/CrmNotificationService.php`,
`PdfForm/Service/PdfManipulator.php`) - pas pour le Context.

## Périmètre exclu

`src/Core/Notification/` reste à plat - n'a pas de NavItem dédié,
c'est de l'infra cross-cutting (Project + d'autres modules émettent
des notifs). Idem pour Encryption, Frontend, Locale, Mail, Migration,
Module, Repository, Scheduler, Sequence, Storage, Support, Timestampable,
Twig, Validation, Enum, EventSubscriber, DataFixtures.

**Menu** et **MountPoint** ont été déplacés (vague follow-up de 0.4.0) :
- `src/Core/Menu/` → `src/Module/Editorial/Menu/` (le NavItem
  `backend_editorial_menus` est déjà déclaré dans `EditorialModule` →
  Menu = sous-module d'Editorial)
- `src/Core/MountPoint/` → `src/Module/Dev/MountPoint/` (seul controller
  exposé est `Dev/MountPointsController`)

## Règle "module vs infra" (finale après 0.4.0)

Pour décider si un folder doit aller sous `src/Module/<X>/` ou rester en
`src/Core/` :

| Cas | Verdict | Exemple |
|---|---|---|
| A un NavItem dans la sidemenu | `src/Module/<X>/` (module ou sous-module) | Platform, Editorial, Vault |
| Pas de NavItem mais cross-cutting (utilisé par plusieurs modules pour leur fonctionnement) | `src/Core/<X>/` (infra) | Mail, Notification, Sequence, Storage, Locale, Encryption, Twig |
| Définit ce qu'est un Module (Contract, Nav, Toggle…) | `src/Core/Module/` | ModuleInterface, NavItem, ModuleToggle |
| Pas de NavItem mais c'est une feature à part entière (entité + UI minimale + 1 seul usage) | À discuter - par défaut **module** sous src/Module/ | (rare) |

### Le cas Notification (pédagogique)

Notification reste en `src/Core/Notification/` malgré son entité +
controller :
- C'est un **service cross-cutting** (n'importe quel module peut envoyer
  une notif, équivalent à `Mail`)
- L'UI (badge cloche + dropdown) est un **trigger** trans-module, pas une
  feature de produit
- Pas de NavItem propre dans la sidemenu

Le critère décisif est "**le NavItem dans la sidemenu**" + "**est-ce
qu'un module pourrait être désactivé sans casser les autres ?**". Pour
Notification, désactiver = casser tous les modules qui envoient des notifs.
Pour Mail, idem. → Infrastructure.

Pour Editorial, désactiver = juste plus de CMS, le reste tourne. → Module.

## Commits du refacto

Le refacto a été exécuté en 5 commits atomiques :
- `71b4151c` - Prep (élargir glob translations à depth 2)
- `db277d5d` - Étape 1 : General (Dashboard + Profile + Search)
- `996a7f64` - Étape 2 : Dev (Audit)
- `4c34062d` - Étape 3 : Configuration (Setting + Theme)
- `ff9c9e16` - Étape 4 : Media (Library)
- `a380781e` - Étape 5 : Platform (User + Agency + Auth + Service)

Méthode : `git mv` puis `sed -i` sur les namespaces (RenameNamespaceRector
absent de la version Rector installée). Pattern sed validé sur la plus
petite étape (General) avant d'attaquer les grosses (Platform 413
fichiers).

## Pièges identifiés pendant le refacto

1. **Pattern sed avec trailing `\\`** : matche `use Aurora\Core\X\Y;`
   mais **rate** `namespace Aurora\Core\X\Y;` quand `Y` est suivi de
   `;` (pas de backslash final). N'est un problème que pour les
   namespaces à profondeur > 2 (Service\Entity\). Solution : 2e passe de
   sed avec patterns sans `\\` final ciblés sur les déclarations namespace.
2. **Conflit `Aurora\Core\Media` vs `Aurora\Module\Media\MediaModule`** : le
   pattern `Aurora\\Core\\Media\\` (avec backslash final) discrimine bien
   les deux (MediaModule n'a pas de `\` après "Media").
3. **`src/Core/Service/` dual-purpose au moment du refacto initial** : hébergeait à la fois l'entité Service ET les contextes
   globaux. Le pattern sed doit cibler explicitement les 7 sous-namespaces
   de Service (`Entity`, `Dto`, `Manager`, …) - pas le préfixe
   `Aurora\\Core\\Service\\` qui capturerait aussi les contextes.

Voir aussi [[pattern_core_submodules_split]] (le pattern "1 module = 1
section" qui motive ce refacto) et la migration guide client
`docs/aurora-client/MIGRATION_0.4.md`.
