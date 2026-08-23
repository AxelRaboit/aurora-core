---
name: convention_mobile_card_layout
description: Pattern de carte mobile pour les listes CRUD - sm:hidden cards + hidden sm:block table, avec footer d'actions
metadata:
  type: feedback
---

## Règle

Toute page de liste CRUD avec tableau doit avoir deux vues :
- **Mobile (`sm:hidden`)** : liste de cartes avec un footer d'actions
- **Desktop (`hidden sm:block`)** : tableau classique inchangé

## Structure de la carte mobile

```vue
<div class="sm:hidden space-y-2">
    <AppNoData v-if="!items?.length" :message="t('...')" />
    <div v-for="item in items" :key="item.id"
         class="bg-surface border border-line/60 rounded-xl overflow-hidden shadow-sm">
        <!-- Corps : contenu principal -->
        <div class="flex items-start gap-3 p-4">
            <!-- avatar / thumbnail / icône (shrink-0) -->
            <!-- infos principales (min-w-0 flex-1) -->
        </div>
        <!-- Footer : actions uniquement -->
        <div class="flex justify-end px-3 py-2 border-t border-line/40 bg-surface-2/40">
            <AppIconButton ...>...</AppIconButton>
        </div>
    </div>
</div>
```

## Règles complémentaires

- Bouton "Nouveau" : toujours **full-width sur mobile** via `class="w-full sm:w-auto"` dans un `grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-2` avec l'input de recherche.
- Les filtres/recherche côté client (liste statique, pas paginée) : logique dans le **composable**, pas dans le `.vue`. Exposer `search` (ref) et `filteredItems` (computed) depuis le composable.
- Les filtres côté serveur (liste paginée via `useListPage`) : `useListPage` + `extraParams` comme dans `DocumentsApp`.

## Pourquoi

Référence implémentée dans `UsersApp.vue`. Appliqué sur GED (documents, categories, tags) - commit `df603eb3`.

**How to apply:** Dès qu'on ajoute ou retouche une page de liste CRUD, vérifier que le pattern mobile card est présent. Si absent, l'ajouter dans le même commit.
