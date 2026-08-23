---
name: composable-client-filtered-list
description: useClientFilteredList - items + recherche client-side + reload pour listes admin courtes (vs useListPage server-side).
metadata:
  type: feedback
---

# Composable : useClientFilteredList

## Règle

Pour toute liste admin **courte** (< quelques centaines d'items) qui veut une
barre de recherche, importer `useClientFilteredList` de
`@/shared/composables/list/useClientFilteredList.js`. Pas de duplication
de `items` ref + `searchInput` ref + `filteredItems` computed + `reload()`
fonction.

```js
import { useClientFilteredList } from "@/shared/composables/list/useClientFilteredList.js";

const { items, searchInput, filteredItems, reload } = useClientFilteredList(
    props.items,         // initial array (SSR hydration)
    props.listPath,      // JSON endpoint { items: [...] }
    (item, query) =>     // matcher
        (item.label ?? "").toLowerCase().includes(query)
        || (item.slug ?? "").toLowerCase().includes(query),
);
```

Template :

```vue
<AppSearchInput v-model="searchInput" :placeholder="..." />
…
<tr v-for="item in filteredItems" :key="item.id">…
<tr v-if="!filteredItems.length"><td>Aucun résultat.</td></tr>
```

## Pourquoi

Avant l'extraction, `ContactTagsApp` et `ListingTagsApp` (et probablement
d'autres apps admin "flat list") dupliquaient le même bloc de ~15 lignes :
`items` ref, `searchInput` ref, `filteredItems` computed, `reload()` qui
fetch le JSON et remplace `items.value`. Une 4ème app voulait la même
chose. Extraire = un seul endroit pour fixer un bug ou faire évoluer
(debounce, accent-insensitive, etc.).

Cf [[convention_frontend_search]] pour le critère "client-side vs
server-side". Si volume > quelques centaines ou pagination requise,
basculer sur `useListPage`.

## Comment l'appliquer

1. Liste flat avec recherche client-side ? → `useClientFilteredList`.
2. Le matcher est passé à l'appel - donne accès aux champs spécifiques
   de l'entité (label/name/slug/tags imbriqués…).
3. `listPath` est **optionnel**. Si `null`, `reload()` est un no-op -
   utile quand les updates viennent du payload d'un form action plutôt
   que d'un endpoint list dédié (cf `useDocumentTagsForm.js`).
4. Le retour expose `items` (ref) si tu as besoin d'inspecter / muter
   la liste depuis le call site. Sinon n'utiliser que `filteredItems`.

## Précédents d'usage

- `ContactTagsApp` (CRM) - items hydratés via SSR, `reload()` sur listPath
- `ListingTagsApp` (Ecommerce) - idem
- `useDocumentTagsForm` (Ged) - items hydratés via SSR, **pas de listPath**
  car le composable form-action retourne la liste mise à jour dans son
  payload (`applyUpdatedList(data)`). Démontre l'usage de `listPath: null`.
