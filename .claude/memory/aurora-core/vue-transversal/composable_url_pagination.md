---
name: composable_url_pagination
description: Pagination full-page-reload (route par page ?page=N) passe par useUrlPagination - pas pour la pagination AJAX.
metadata:
  type: feedback
---

## Règle

Pour la pagination **full-page-reload** (chaque page = une nouvelle requête
HTTP avec `?page=N` qui re-rend le template Twig), utiliser :

```js
import { useUrlPagination } from '@/shared/composables/nav/useUrlPagination.js'

const { currentPage, totalPages, hrefForPage, goToPage } = useUrlPagination({
  currentPage: initialPage,
  totalPages: pageCount,
  baseUrl: window.location.pathname,
  query: { /* query params à préserver */ },
})
```

**Ne pas l'utiliser** pour la pagination AJAX (résultats injectés dynamiquement
sans navigation). Dans ce cas, le composable de search/listing du feature
gère lui-même son état de page (cf [[convention_frontend_search]] côté
client-side / server-side AJAX).

## Pourquoi

- Toutes les pages frontend `shop/`, `shop/category`, `shop/tag`, `archive`,
  `term` paginent par reload → besoin d'un seul composable qui gère :
  préservation des query params, génération de `href` pour `<a>` (crawlable),
  bornes (1 ≤ page ≤ totalPages).
- Distinguer pagination AJAX vs full-reload évite de confondre deux modèles
  d'UX très différents (le full-reload est crawlable, l'AJAX ne l'est pas).

## Comment l'appliquer

1. Page Vue qui consomme du HTML pré-rendu paginé → `useUrlPagination`.
2. Modal/section qui injecte des résultats sans navigation → composable du
   feature, pas celui-ci.
3. Le composant `<AppPagination>` accepte `hrefForPage` pour render des `<a>`
   navigables (utile au SEO + Ctrl+clic).
