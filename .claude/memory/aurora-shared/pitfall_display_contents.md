---
name: pitfall_display_contents
description: AppTooltip a display:contents pour racine - les marges dessus sont ignorées, et first:/last: matchent chaque enfant. Mettre en page avec gap.
metadata:
  type: feedback
---

## Règle

Toute ligne enveloppée dans `AppTooltip` (donc `AppNavLink`, `AppNavButton`, et
tout composant dont la racine est `AppTooltip`) est sous un élément
`display: contents`. Trois conséquences, à connaître **avant** d'écrire la mise
en page :

1. **Les marges sur cet élément ne s'appliquent pas.** Donc `space-y-*` sur le
   parent ne produit **rien** : la classe pose `margin-top` sur l'enfant, et un
   élément `display: contents` ne génère pas de boîte pour la porter.
   → Mettre en page avec `flex flex-col gap-*`. Les `gap` atteignent bien les
   lignes, parce que les enfants d'un `display: contents` sont promus éléments
   flexibles du parent.

2. **`first:` et `last:` matchent chaque ligne.** Chaque ligne est le fils
   *unique* de sa propre enveloppe, donc les deux pseudo-classes s'appliquent à
   toutes. Une barre empilée est sortie avec les deux bouts arrondis sur chaque
   segment.
   → Calculer la position depuis l'index (`v-for="(x, index)"`) et lier la
   classe, jamais `first:`/`last:`.

3. **Idem pour tout sélecteur structurel** : `:nth-child`, `~`, `+` comptent
   dans l'arbre DOM, où l'enveloppe existe toujours.

## Pourquoi

`display: contents` est **voulu** sur `AppTooltip` : envelopper une ligne ne doit
pas ajouter de boîte à la mise en page du parent, sinon chaque infobulle
casserait la grille ou le flex qui l'entoure. Le composant est correct ; c'est
son effet de bord qu'il faut connaître.

Le coût réel, mesuré : **trois défauts en une journée** (23/08/2026).

| Symptôme observé | Cause |
|---|---|
| Un seul écart visible dans toute la sidemenu, entre sections | `space-y-0.5` dans quatre conteneurs n'a jamais rien rendu |
| Jointures arrondies des deux côtés sur une barre empilée | `first:`/`last:` matchant chaque segment |
| Écart de 40px sous le dernier item | le `py-4` du nav, seul rembourrage réellement rendu |

Les trois se ressemblaient à un problème d'espacement mal réglé. Aucun ne l'était.

## Comment l'appliquer

- Conteneur de lignes de navigation ou de boutons enveloppés : `flex flex-col gap-*`.
- Jamais `space-y-*` autour de `AppNavLink` / `AppNavButton` / `AppTooltip`.
  `space-y-*` reste correct autour d'éléments ordinaires (formulaires, cartes) et
  la sidemenu en garde trois usages légitimes.
- Position dans une liste : depuis l'index, pas depuis une pseudo-classe.
- En cas de doute sur un composant tiers du dépôt : vérifier sa racine.
  `grep -n 'class="contents"' <fichier>`.

Voir [[convention_form_components]] pour les composants concernés.
