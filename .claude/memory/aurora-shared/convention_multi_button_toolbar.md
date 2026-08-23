---
name: convention_multi_button_toolbar
description: Multiple action buttons in AppListToolbar #actions slot must be wrapped in a flex container so they keep their gap on desktop and stack full-width on mobile
metadata:
  type: feedback
---

## Règle

Quand le slot `#actions` d'un `<AppListToolbar>` contient **plus d'un**
`<AppButton>`, les envelopper dans un wrapper flex :

```vue
<template #actions>
    <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
        <AppButton variant="ghost"  ...>{{ t("…") }}</AppButton>
        <AppButton variant="secondary" ...>{{ t("…") }}</AppButton>
        <AppButton variant="primary" ...>{{ t("…") }}</AppButton>
    </div>
</template>
```

Pour un **seul bouton**, conserver le pattern simple avec
`class="w-full sm:w-auto"` directement sur l'`AppButton` (cf.
[[convention_mobile_card_layout]]).

## Pourquoi

`AppListToolbar` met les actions dans une seule cellule du grid. Sans
wrapper :
- **Mobile** : les boutons sont des éléments inline mais avec
  `w-full sm:w-auto` chacun → ils empilent verticalement sans gap, en
  collés.
- **Desktop** : ils flow inline sans gap entre eux non plus.

Le wrapper `flex flex-col sm:flex-row gap-2 w-full sm:w-auto` :
- Force `flex-col` sur mobile (boutons stackés avec gap)
- Bascule en `flex-row` à `sm:` (boutons inline avec gap)
- Le `w-full sm:w-auto` sur le wrapper permet aux boutons de remplir
  la largeur mobile sans ajouter de class individuelle

## Comment l'appliquer

- 1 bouton : `<AppButton class="w-full sm:w-auto" ...>` - pas de wrapper
- 2+ boutons : wrapper `<div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">`
- Quand un bouton est secondaire (ex: refresh) sur Dashboard ou Budgets,
  un seul bouton sans `w-full` reste acceptable (cas spécifique des
  pages dashboard, pas une CRUD list).

## Pointeurs

- Lien : [[convention_mobile_card_layout]] - couvre le cas 1 bouton +
  pattern mobile cards/desktop table.
- Lien : [[pattern_admin_list_toolbar]] - le wrapper `AppListToolbar`
  lui-même.
- Exemples en code : `PersonalFinanceTransactionsApp.vue` (Split +
  Transfer + Ajouter), `PersonalFinanceRecurringApp.vue` (1 bouton par
  tab, donc pattern simple).
