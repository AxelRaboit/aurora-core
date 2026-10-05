---
name: structure_template_folders
description: Folder-per-feature pour les templates Twig dès qu'un feature a ≥1 fichier ; plat sinon.
metadata:
  type: feedback
---

## Règle

Pour chaque feature Twig :

- **≥1 fichier** → dossier dédié, point d'entrée `index.html.twig` :
  `<Module>/<area>/<feature>/index.html.twig` + ses voisins
  (`category.html.twig`, `tag.html.twig`, `show.html.twig`, …).
- **Single-file** → reste plat : `<Module>/<area>/<feature>.html.twig`.

S'applique aux deux `<area>` : `suite/` et `frontend/`. Aligné avec le
backend admin qui suivait déjà la convention.

### Exemples post-refacto

```
Ecommerce/frontend/
├── shop/
│   ├── index.html.twig        ← /shop
│   ├── category.html.twig     ← /shop/category/<slug>
│   ├── tag.html.twig          ← /shop/tag/<slug>
│   └── product.html.twig      ← /shop/<slug>
├── order/
│   └── show.html.twig
├── account/
│   └── orders.html.twig
├── cart.html.twig             ← single-file, reste plat
└── checkout.html.twig         ← single-file, reste plat

Editorial/frontend/
├── post/
│   ├── index.html.twig
│   └── show.html.twig
├── term/
│   └── show.html.twig
├── archive/
│   └── index.html.twig
├── home/
│   └── index.html.twig
└── form/
    └── show.html.twig
```

## Pourquoi

- **Lisibilité** : la liste des fichiers d'un module raconte ses features.
- **Croissance** : ajouter une variante de page (`category`, `tag`, `show`,
  `edit`) ne pollue pas la racine du module.
- **Cohérence** : même convention que les controllers et que les sous-dossiers
  Vue (`src/Module/<M>/assets/frontend/<feature>/`).

## Comment l'appliquer

1. Avant de créer un template, regarder s'il existe ou existera un voisin
   (variant, sub-page). Si oui → folder-per-feature dès le premier fichier.
2. Si un feature plat (`shop.html.twig`) gagne un voisin, le migrer en bloc
   vers `shop/{index,…}.html.twig` et update toutes les références
   (`ThemeResolver::resolve(…)`, `render(…)`, `include`).
3. Lié : [[convention_frontend_rendering]] (corps = passerelle Vue),
   [[structure_templates]] (namespaces + thèmes).
