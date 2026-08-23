---
name: composable_hierarchical_tree
description: Toute manipulation parent/enfant côté Vue passe par useHierarchicalTree - ne pas dupliquer buildTree / flatten / collectDescendants.
metadata:
  type: feedback
---

## Règle

Pour toute structure parent/enfant manipulée côté Vue (taxonomies, categories,
menus, comments…), importer les helpers depuis :

```js
import {
  buildTree,
  flattenTreeForReorder,
  collectDescendantIds,
  findNodeInTree,
  sortRecursive,
} from '@/shared/composables/tree/useHierarchicalTree.js'
```

Aucune réimplémentation locale.

| Fonction | Rôle |
|---|---|
| `buildTree(flatList, { parentKey, childrenKey })` | flat → arbre |
| `flattenTreeForReorder(tree)` | arbre → liste avec `position` + `parentId` (POST reorder) |
| `collectDescendantIds(node)` | ids récursifs d'un sous-arbre (delete, disable cascade) |
| `findNodeInTree(tree, id)` | recherche par id, retourne `{ node, parent, siblings }` |
| `sortRecursive(tree, comparator)` | tri stable récursif |

## Pourquoi

Avant cette session, ce code était **dupliqué byte-for-byte** entre
`useTaxonomyTree.js` (Editorial) et `ListingCategoriesApp.vue` (Ecommerce).
Toute évolution du contrat (ex: ajout d'un champ `depth`) divergait
silencieusement. La factorisation impose un comportement unique et testable.

## Comment l'appliquer

1. Nouveau tree-like → import depuis `shared/composables/tree`. **Jamais**
   recopier la logique.
2. Si un besoin métier (ex: filtrer un sous-arbre par flag) n'est pas couvert,
   l'ajouter au composable partagé (avec tests), pas en local.
3. Lié côté backend : [[convention_collection_on_concrete]] (les `Collection`
   parent↔children vivent sur la classe concrete).
4. Piège connexe : [[pitfall_nested_drag_drop_clone]] - ne pas shallow-cloner
   `node.children` dans un node récursif VueDraggable.
