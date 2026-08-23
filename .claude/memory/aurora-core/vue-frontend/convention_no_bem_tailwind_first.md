---
name: convention_no_bem_tailwind_first
description: Pas de BEM dans les templates Twig/Vue - utility-first Tailwind exclusivement.
metadata:
  type: feedback
---

## Règle

**Aucune classe BEM** (`block__element--modifier`) dans les templates Twig ni
dans les composants Vue. Le styling se fait exclusivement avec les utilities
Tailwind directement dans le markup.

```twig
{# ❌ NON #}
<nav class="shop-category__breadcrumb">
  <a class="shop-category__breadcrumb-link shop-category__breadcrumb-link--active">…</a>
</nav>

{# ✅ OUI #}
<nav class="flex items-center gap-2 text-sm text-gray-600">
  <a class="hover:text-gray-900 font-semibold text-gray-900">…</a>
</nav>
```

## Pourquoi

- Convention historique du projet : utility-first Tailwind partout, jamais de
  CSS custom hors `src/Core/assets/css/` (CSS partagé) ou co-localisé avec un
  SFC sous `src/Module/<X>/assets/`.
- Pas de feuille de style à maintenir, pas de drift entre classes et styles.
- Les sous-tâches/agents tendent à introduire du BEM par habitude → le code
  doit être reverté systématiquement.

## Comment l'appliquer

1. À l'écriture, n'utiliser que des classes Tailwind.
2. À la review, grep les patterns BEM (`__`, `--` dans les attributs `class`)
   sur les nouveaux fichiers ; flaguer et reverter.
3. Si une combinaison Tailwind se répète vraiment ≥3 fois → composant
   partagé (`AppButton`, `PostCard`, etc.), pas une classe BEM.
4. Lié : [[convention_frontend_rendering]] (passerelle Vue partout),
   [[pattern_shared_listing_card]] (factorisation par composant).
