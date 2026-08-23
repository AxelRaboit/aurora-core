# Vocabulaire : module / section / sous-module / NavItem / sous-domaine / entité

Vocabulaire de référence pour nommer les éléments de l'architecture modulaire.
À utiliser systématiquement (dialogue + code + commits) pour éviter les confusions.

## Les 6 niveaux

| Terme | Définition | Ancrage code / nav |
|---|---|---|
| **Module** | Unité fonctionnelle top-level | `src/Module/<X>/` + `<X>Module.php` (`getId()`) + toggle racine `<X>Backend` (ModuleParameterEnum) |
| **Section** | Le regroupement *nav* (en-tête du sidemenu) | `new NavSection('<id>', …)` |
| **Sous-module** | Sous-feature **toggleable** d'un module | case enum enfant (parent via `getParentCase`) + 1 permission |
| **NavItem** | Une entrée cliquable du menu | `new NavItem('backend_<…>', 'backend.nav.<…>', …)` |
| **Sous-domaine** | Dossier de code d'une entité (5 couches Sylius) | `src/Module/<X>/<Feature>/` (Entity/Dto/Manager/…) |
| **Entité** | L'objet métier | classe `Entity/<Name>` |

## Règles clés

- **Module = Section** : c'est le même concept vu de deux côtés (code vs nav).
  En pratique **1 module = 1 section** de même id (`EcommerceModule` →
  `NavSection('ecommerce')` libellé "E-commerce"). Plusieurs modules PEUVENT
  contribuer à une section partagée si besoin (le registry fusionne par id),
  mais le défaut est 1↔1.
- **Sous-module ≠ NavItem ≠ sous-domaine ≠ entité.** Un sous-module (= un
  toggle + une permission) peut couvrir **plusieurs** NavItems / sous-domaines /
  entités. Inversement certains modules ont 1 sous-module = 1 NavItem (Notes,
  Tools).
- Le **toggle** d'un sous-module vit dans `ModuleParameterEnum` (enfant du
  `<X>Backend`) ; la **permission** gate l'accès ; le **NavItem** est l'entrée
  visible ; le **sous-domaine** est le code ; l'**entité** est la donnée.

## Exemple canonique - Ecommerce

```
Section "E-commerce"            (= module Ecommerce, NavSection 'ecommerce')
├─ Sous-module "Listings"       (toggle EcommerceListings, perm ecommerce.listings.view)
│   ├─ NavItem "Boutique"       → sous-domaine Listing/         (entité Listing)
│   ├─ NavItem "Catégories"     → sous-domaine ListingCategory/ (entité ListingCategory)
│   └─ NavItem "Tags"           → sous-domaine ListingTag/      (entité ListingTag)
└─ Sous-module "Orders"         (toggle EcommerceOrders)
    └─ NavItem "Commandes"      → sous-domaine Order/           (entité Order)
```

Donc : « E-commerce » = **module** (et sa **section**) ; « Boutique » = un
**NavItem** rattaché au **sous-module** Listings, dont le code est le
**sous-domaine** `Listing/` (entité `Listing`). Boutique/Catégories/Tags = 3
NavItems mais 1 seul sous-module (Listings) - Catégories/Tags n'ont pas de
toggle propre.

Contre-exemple « 1 sous-module = 1 NavItem » : Tools (ToolsVault, ToolsPasswordGenerator)
et Notes (NotesMarkdown/Block/PostIt) - cf. [[project_url_namespacing_backlog]].
