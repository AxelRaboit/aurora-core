---
name: pattern_admin_list_toolbar
description: Toolbar standard des pages admin (search + bouton(s) d'action) via AppListToolbar partagé
metadata:
  type: feedback
---

## Règle

Pour le toolbar standard d'une page admin (search à gauche, bouton(s) d'action à droite), utiliser `<AppListToolbar>` de `@/shared/components/list/AppListToolbar.vue` :

- **Slot par défaut** : contenu de gauche (typiquement `AppSearchInput`, mais peut être un `AppMultiselect` ou autre).
- **Slot `#actions`** : contenu de droite (un ou plusieurs `AppButton`, ou un groupe `<div class="flex …">` pour plusieurs boutons).

```vue
<AppListToolbar>
    <AppSearchInput v-model="searchInput" v-on:search="onSearch" />
    <template #actions>
        <AppButton variant="primary" v-on:click="openCreate">…</AppButton>
    </template>
</AppListToolbar>
```

Le composant est purement layout - pas de props, pas de logique, juste deux slots et le grid responsive.

**Plusieurs boutons à droite** : les regrouper dans un `<div class="flex items-center gap-2 w-full sm:w-auto">` à l'intérieur de `#actions`.

## Pourquoi

Sans wrapper partagé, chaque page duplique `grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-2`. Une seule évolution (changer le breakpoint `sm:` → `md:`) nécessiterait de modifier toutes les pages. `AppListToolbar` rend ça atomique.

## Comment l'appliquer

- **Nouvelle page admin avec une liste** → wrapper `<AppListToolbar>`.
- **Mobile** (par défaut) : empilement vertical, le bouton garde `class="w-full sm:w-auto"`.
- **Desktop sm+** : search à gauche (1fr), actions à droite (auto).

Lien : [[convention_mobile_card_layout]] - le toolbar est le pendant haut du pattern de carte mobile.
