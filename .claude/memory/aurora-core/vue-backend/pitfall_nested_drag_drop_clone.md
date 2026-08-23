---
name: pitfall_nested_drag_drop_clone
description: Dans un node récursif VueDraggable, ne jamais shallow-cloner props.node.children - utiliser un computed bidirectionnel.
metadata:
  type: feedback
---

## Règle

Dans un composant Vue **récursif** (un `<Node>` qui se rend lui-même pour ses
enfants) couplé à `VueDraggable`, **ne JAMAIS** faire :

```js
// ❌ BUG : shallow clone, VueDraggable mute le clone, pas l'arbre parent
const localChildren = ref([...props.node.children])
```

Utiliser un **computed bidirectionnel** qui pointe directement vers
`props.node.children` :

```js
// ✅ OK
const children = computed({
  get: () => props.node.children,
  set: (value) => { props.node.children = value },
})
```

Et brancher `VueDraggable` sur `children` (`v-model="children"`).

## Pourquoi

- Avec le shallow clone, VueDraggable mute `localChildren` (l'array cloné),
  jamais `props.node.children` (l'array de l'arbre racine).
- Quand l'app appelle `flattenTreeForReorder(tree)` pour POSTer le nouvel ordre
  au serveur, elle relit le `tree` racine → **l'ancien ordre** non muté →
  serveur sauvegarde l'ordre initial → l'UI "revert" au prochain render.
- Le bug se manifeste **uniquement** sur les niveaux imbriqués (enfants
  d'enfants), parce que la racine, elle, est rendue directement depuis le
  state app et fonctionne par accident.

### Précédents

- ✅ Corrigé : `src/Module/Ecommerce/assets/backend/listing-categories/ListingCategoryNode.vue`
  (commit `93d626cf` "fix: nested drag-and-drop on ListingCategory tree now persists").
- ⚠️ **À surveiller - encore présent** :
  `src/Module/Editorial/assets/backend/taxonomies/TermNode.vue` - même pattern
  `localChildren = ref([...])`, bug latent identique. À fixer dès qu'une
  session touche au tree des terms.

## Comment l'appliquer

1. Tout node récursif drag-and-drop → computed bidirectionnel sur
   `props.node.children`. Aucun `ref([...])` shallow clone.
2. Tester en glissant un nœud feuille au niveau 2+ et en rechargeant la page :
   l'ordre doit persister.
3. Lié : [[composable_hierarchical_tree]] (`flattenTreeForReorder` lit
   l'arbre racine - donc l'arbre racine doit refléter les drags).
