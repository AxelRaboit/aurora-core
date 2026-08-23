---
name: convention_frontend_search
description: Quand faire la recherche côté client (client-side) vs côté serveur (API), et comment utiliser AppSearchInput correctement dans chaque cas.
metadata:
  type: feedback
---

## Règle

### Client-side vs server-side : le critère de décision

| Situation | Approche |
|-----------|----------|
| Volume prévisible et limité (< quelques centaines de docs) | **Client-side** - tout charger en mémoire, filtrer avec `computed` |
| Volume potentiellement grand ou illimité | **Server-side** - endpoint JSON + pagination |

**Ne jamais** faire `findPaginated(1, 200, ...)` ou `findPaginated(1, PHP_INT_MAX, ...)` pour contourner la pagination. Si on charge tout, créer une méthode `findAllXxx()` dédiée dans le repo. Si on veut de la vraie pagination, aller en server-side.

---

### AppSearchInput - deux usages selon l'approche

#### Client-side : `v-model` direct
```vue
<AppSearchInput v-model="query" :placeholder="..." />
```
Le filtre réactif (`computed`) se déclenche à chaque frappe via `v-model`. Le debounce intégré de `AppSearchInput` ne s'applique **pas** (il ne concerne que l'event `@search`). C'est intentionnel : filtrer instantanément une liste en mémoire est meilleure UX que d'attendre 300ms.

#### Server-side : `@search` event
```vue
<AppSearchInput :model-value="query" :placeholder="..." v-on:search="onSearch" />
```
`@search` est émis avec un debounce de 300ms par `AppSearchInput`. Le handler appelle l'API et reset la page à 1 :
```js
function onSearch(q) {
    query.value = q;
    debouncedFetch(q, 1);  // ou directement fetchPage(q, 1) si AppSearchInput débounce déjà
}
```
Ne **pas** utiliser `v-model` ici - cela ferait une requête API à chaque frappe sans debounce.

---

### Structure composable server-side

```js
export function useDocumentSearch(props) {
    const items = ref(props.initialItems);
    const page = ref(props.initialPage);
    const totalPages = ref(props.initialTotalPages);
    const query = ref("");

    const { loading, request } = useFrontendRequest();

    async function fetchPage(q, p) {
        const params = new URLSearchParams({ page: p });
        if (q.trim()) params.set("q", q.trim());
        const data = await request(`${props.searchPath}?${params}`, null, HttpMethod.Get);
        if (!data?.success) return;
        items.value = data.items;
        page.value = data.page;
        totalPages.value = data.totalPages;
    }

    function onSearch(q) { query.value = q; fetchPage(q, 1); }
    function goToPage(p) { page.value = p; fetchPage(query.value, p); }

    return { query, items, page, totalPages, loading, onSearch, goToPage };
}
```

- `useFrontendRequest` + `HttpMethod.Get` pour les requêtes GET avec params URL
- `goToPage` sans debounce (action utilisateur explicite)
- Props initiales passées par le `*ViewBuilder` via `vue_component(...)` côté Twig (la première page est sérialisée dans le payload du composant Vue, pas rendue en HTML - cf [[convention_frontend_rendering]]).

---

### Endpoint backend

```php
#[Route('/search', name: '_search', methods: [HttpMethodEnum::Get->value])]
public function search(string $locale, Request $request): JsonResponse
{
    $query = trim($request->query->getString('q', ''));
    $page = max(1, $request->query->getInt('page', 1));
    return $this->jsonSuccess(
        $this->viewBuilder->pageData($page, '' !== $query ? $query : null)
    );
}
```

La méthode `pageData()` du ViewBuilder est réutilisée par `indexView()` (SSR) et `search()` (JSON).

## Pourquoi

**Why:** Apparu lors de la migration GED frontend (2026-05-14). La version initiale chargeait tous les docs côté client. Passé en server-side avec endpoint JSON + pagination pour ne pas avoir de limite arbitraire sur le volume.

**How to apply:** À chaque nouvelle feature de recherche frontend, poser la question volume d'abord. Si server-side → endpoint séparé, `AppSearchInput @search`, `AppPagination @change`, `ViewBuilder.pageData()` partagé.
