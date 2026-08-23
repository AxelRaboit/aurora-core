---
name: convention_app_loader
description: Toute liste paginée backend doit wrapper son contenu dans relative space-y-N + AppLoader - le loading vient du destructure de useListPage
metadata:
  type: feedback
---

## Règle

Toute liste paginée backend doit wrapper son contenu dans :

```vue
<div class="relative space-y-4">
    <AppLoader :active="loading" />
    <!-- tableau / cartes / etc. -->
</div>
```

Le `loading` vient du destructure de `useListPage` ou du composable feature concerné :

```js
const { items, loading, pagination, fetchPage } = useListPage(url, options);
```

## Pourquoi

Sans `AppLoader`, l'utilisateur n'a aucun retour visuel pendant le chargement des données (changement de page, filtre, tri). Le `relative` sur le wrapper est obligatoire - `AppLoader` est positionné en `absolute` pour couvrir le contenu pendant le fetch sans décaler le layout.

## Comment l'appliquer

- **Nouvelle page avec `useListPage`** → wrapper `<div class="relative space-y-N">` + `<AppLoader :active="loading" />` en premier enfant.
- **Composable feature avec `loading` ref** → même pattern.
- `space-y-N` : adapter N selon le spacing voulu entre toolbar, tableau et pagination (souvent `space-y-4`).
- Ne pas omettre le `relative` - sans lui `AppLoader` se positionne par rapport à un ancêtre, couvrant le mauvais élément.
