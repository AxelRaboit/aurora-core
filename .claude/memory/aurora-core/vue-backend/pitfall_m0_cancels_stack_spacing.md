---
name: pitfall_m0_cancels_stack_spacing
description: Un `m-0` sur un enfant d'`aurora-stack` ou de `space-y-*` annule l'espace sous lui (Tailwind 4, spécificité nulle) : le bloc suivant vient se coller.
metadata:
  type: feedback
---

## Règle

Ne jamais poser `m-0` (ni `mb-0`, `my-0`) sur un enfant direct d'un conteneur
`aurora-stack` ou `space-y-*`. Pour rapprocher deux blocs qui vont ensemble
(des onglets et la phrase qui les explique), les regrouper dans un
`<div class="flex flex-col gap-2">` : le groupe garde l'espace de la page
sous lui.

## Pourquoi

Avec Tailwind 4, `space-y-*` et l'utilitaire maison `aurora-stack`
(`src/Core/assets/css/base/spacing.css`) posent `margin-block-end` sur les
enfants sous `:where(...)`, donc à spécificité nulle. N'importe quelle classe
de marge de l'enfant gagne : `m-0` remet la marge du bas à zéro, et le bloc
suivant vient toucher celui-ci.

Vu le 04/10/2026 sur Studio > Livrables : la phrase sous les rayons touchait
la première carte (Axel l'a relevé). Même cause dans la fenêtre de copie vers
un espace client : l'intro collée au sélecteur.

## Comment l'appliquer

- Un `<p>` dans un `aurora-stack` ou un `space-y-*` se passe de `m-0` : le
  preflight de Tailwind le met déjà à zéro, et la pile ajoute l'espace voulu.
- Mesurer l'écart au besoin avec `getBoundingClientRect()` dans le navigateur
  plutôt qu'à l'œil.
