---
name: convention_mobile_card_layout
description: Pattern de carte mobile pour les listes CRUD - cartes sur téléphone, tableau au-dessus ; les gestes toujours derrière AppRowActions (« … ») dans l'en-tête de la carte, jamais en pied de boutons
metadata:
  type: feedback
---

## Règle

Toute page de liste CRUD avec tableau doit avoir deux vues :
- **Mobile (`sm:hidden`)** : liste de cartes, les gestes derrière le bouton « … » (`AppRowActions`) à hauteur du titre
- **Desktop (`hidden sm:block`)** : tableau classique inchangé

## Structure de la carte mobile

```vue
<div class="sm:hidden space-y-2">
    <AppNoData v-if="!items?.length" :message="t('...')" />
    <div v-for="item in items" :key="item.id"
         class="bg-surface border border-line/60 rounded-xl overflow-hidden shadow-sm">
        <div class="flex items-start gap-3 p-4">
            <!-- avatar / thumbnail / icône (shrink-0) -->
            <!-- infos principales (min-w-0 flex-1) -->
            <AppRowActions class="shrink-0" :actions="actionsFor(item)" :label="item.name" />
        </div>
    </div>
</div>
```

## Les gestes d'une carte : toujours « … » (décision d'Axel du 04/10/2026)

- Une liste montre ses gestes derrière `AppRowActions`, sur téléphone comme dans
  un tableau, dans l'en-tête de la carte à droite (statut éventuel juste avant).
  Plus de pied de boutons pleine largeur : `AppCardActions` a été supprimé.
- **Une seule action** : à partir de `sm`, `AppRowActions` l'affiche directement
  en petit bouton (icône + mot), sans feuille ; **sur téléphone, toujours « … »**,
  même pour une seule (Axel, 04/10/2026). Aucune action : rien n'est dessiné.
- Une action qui ouvre un nouvel onglet (aperçu) passe par
  `onSelect: () => window.open(url, "_blank", "noopener")`, la feuille ne
  connaissant pas `target`.

## Règles complémentaires

- Bouton "Nouveau" : toujours **full-width sur mobile** via `class="w-full sm:w-auto"` dans un `grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-2` avec l'input de recherche.
- Les filtres/recherche côté client (liste statique, pas paginée) : logique dans le **composable**, pas dans le `.vue`. Exposer `search` (ref) et `filteredItems` (computed) depuis le composable.
- Les filtres côté serveur (liste paginée via `useListPage`) : `useListPage` + `extraParams` comme dans `DocumentsApp`.

## Pourquoi

Référence implémentée dans `UsersApp.vue`. Appliqué sur GED (documents, categories, tags) - commit `df603eb3`.

**How to apply:** Dès qu'on ajoute ou retouche une page de liste CRUD, vérifier que le pattern mobile card est présent. Si absent, l'ajouter dans le même commit.
