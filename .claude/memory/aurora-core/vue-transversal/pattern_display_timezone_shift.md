---
name: pattern_display_timezone_shift
description: Pour dessiner un calendrier dans un fuseau choisi sans réécrire l'arithmétique : réécrire l'instant en horloge murale SANS offset, que `new Date()` lit en local. Aller-retour via `toDisplay` / `fromDisplay` (displayZone.js).
metadata:
  type: project
---

## Règle

Le calendrier stocke des instants UTC et doit les dessiner dans le fuseau que le
lecteur a choisi - qui n'est **pas** forcément celui de son navigateur. La
technique employée : **réécrire l'instant en chaîne d'horloge murale sans
offset**, et laisser `new Date()` la relire comme une heure locale.

```js
// toDisplay : un instant → un Date dont les getters locaux donnent
// l'heure murale du fuseau d'affichage.
// fromDisplay : l'inverse, en deux passes (pour l'heure d'été).
```

Le point clé est ce que ça permet de **ne pas** faire : `getHours()`,
`getDay()`, les comparaisons, le placement en pixels, le calcul de lignes -
toute l'arithmétique déjà écrite et déjà testée continue de fonctionner sans être
touchée. Le fuseau n'existe qu'à deux frontières : `toDisplay` à l'entrée des
données, `fromDisplay` à la sortie vers le serveur.

Fichier : `src/Module/Planning/assets/backend/planning/composables/displayZone.js`
(`toDisplay`, `fromDisplay`, `toDisplayRow`, `useDisplayZone`, `isKnownZone`).

## Pourquoi

**Why:** l'alternative est de passer un fuseau en paramètre à chaque fonction de
date de la grille, et d'utiliser `Intl` partout au lieu des getters. Ça veut dire
toucher `monthGrid.js`, `timeGrid.js`, chaque composant de grille, et chaque test
qui les couvre - pour un résultat où chaque nouveau calcul de date devra se
rappeler de recevoir le fuseau, sinon il travaille silencieusement dans celui du
navigateur.

Le décalage concentre le problème en deux fonctions testées. Le prix est un
`Date` qui **ment sur son propre instant** entre les deux frontières : c'est
volontaire, mais ça doit être su, sinon quelqu'un enverra un objet d'affichage au
serveur.

**Deux conséquences à ne jamais oublier :**

1. **Ne jamais envoyer un `Date` d'affichage au serveur.** Il faut repasser par
   `fromDisplay`. C'est exactement le bug qui a été introduit puis corrigé : un
   glisser-déposer envoyait l'horloge murale telle quelle, et l'événement se
   décalait de deux heures.
2. **`toISOString()` est interdit pour fabriquer une clé de jour.** Il repasse en
   UTC, donc à l'est de Greenwich il nomme la veille. C'est pour ça que
   `monthGrid.js` a `dayKey()`, qui lit les getters locaux. Deux tests de
   glisser-déposer sont tombés là-dessus.

`fromDisplay` fait **deux passes** parce qu'un changement d'heure peut déplacer
l'offset entre la lecture et l'écriture : la première passe donne un instant
approché, la seconde le corrige avec l'offset réellement en vigueur à cet
instant-là.

## Comment l'appliquer

- Nouvelle donnée qui arrive du serveur → `toDisplayRow` avant de la donner à une
  grille.
- Nouvelle écriture vers le serveur → `fromDisplay` sur chaque instant, à la
  frontière (dans `usePlanningEvents`, `realSpan` est le seul endroit qui le
  fait).
- Nouvelle clé de jour → `dayKey()`, jamais `toISOString().slice(0, 10)`.
- Côté serveur, le pendant de cette règle est `PlanningClock` : tout instant est
  normalisé UTC à l'entrée. Voir [[pitfall_mapping_index_schema_drift]] pour
  l'autre moitié des pièges de ce module.
