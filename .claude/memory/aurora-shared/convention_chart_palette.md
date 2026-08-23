---
name: convention_chart_palette
description: Graphiques - palette catégorielle fixe (--chart-cat-1..8), ordre non recyclé, part-d'un-tout = barre empilée jamais camembert, valeur lisible en texte quand le contraste passe sous 3:1
metadata:
  type: feedback
---

## Règle

### La forme d'abord, la couleur en dernier

| Ce que le lecteur doit faire | Forme | Rôle de la couleur |
|---|---|---|
| Voir une répartition (part d'un tout) | **barre empilée**, `AppShareBar` | catégorielle |
| Comparer des grandeurs | barres / colonnes | séquentielle, une seule teinte |
| Suivre une évolution | courbe | séquentielle ou 1 catégorielle |
| Distinguer des séries | barres groupées, multi-courbes | catégorielle |
| Une seule valeur, ou un ratio contre une limite | **pas de graphique** : une tuile chiffrée, une jauge | aucune |

**Jamais de camembert.** Comparer des angles est plus difficile que comparer des
longueurs, et des libellés longs n'ont nulle part où tenir sur des parts. Un
camembert de deux parts est le cas le plus clair : c'est une jauge.

### La palette

Les teintes viennent de `--chart-cat-1..8`, définies dans
`src/Core/assets/css/base/chart.css`, en clair et en sombre.

- **Ordre fixe, attribution par position, jamais recyclée.** Au-delà du huitième
  créneau, replier la queue dans « autre » ou éclater en petits multiples. Une
  neuvième teinte générée est indistinguable d'une existante sous déficience de
  vision des couleurs.
- **Attribuer les créneaux avant de retirer les valeurs nulles**, pour qu'une
  catégorie garde sa couleur quand une voisine se vide. Sinon publier le dernier
  brouillon repeint les survivants et le lecteur réapprend le graphique.
- **Ne jamais construire la palette sur `--th-accent-*`.** Un thème peut
  repeindre l'accent, et cinq teintes qui glissent avec lui cessent d'être cinq
  teintes distinctes.
- **Le texte ne porte jamais la couleur de la donnée.** Libellés, valeurs et
  légendes restent en `text-primary` / `text-secondary` / `text-muted` ; c'est la
  pastille colorée **à côté** du texte qui porte l'identité.

### La règle de relief

Trois teintes du mode clair (aqua, jaune, magenta) passent sous 3:1 contre une
surface blanche. C'est autorisé **à condition** que la valeur soit lisible en
texte, pas seulement comme un aplat. Donc : la légende imprime chaque valeur, et
les déplacer dans l'infobulle casse le graphique au lieu de l'épurer.

### Séparer les marques

Un écart de 2px dans la couleur de surface entre deux segments qui se touchent,
pas une bordure. Une bordure ajoute de l'encre qui n'est pas de la donnée.

## Pourquoi

Les couleurs d'un graphique ne se choisissent pas à l'œil : la séparation sous
protanopie, deuteranopie et tritanopie se calcule. La palette de `chart.css` a
été validée contre les surfaces réelles d'Aurora (`#ffffff` et `rgb(17 24 39)`)
sur six contrôles : bande de luminosité, plancher de chroma, séparation des
paires adjacentes en vision déficiente, séparation en vision normale, et
contraste.

Résultat : pire paire adjacente ΔE 9,1 en clair et 8,4 en sombre (cible 8), et
19,6 / 19,3 en vision normale (plancher 15). Les pas du mode sombre sont choisis
**pour** la surface sombre, pas obtenus en inversant ceux du clair.

## Comment l'appliquer

- Répartition : `AppShareBar`, avec `[{ key, label, value }]` dans l'ordre de
  lecture.
- Séries temporelles, plusieurs séries, axes : `AppChart` (Chart.js). Il rend
  dans un canvas, donc ni tokens de thème ni `AppTooltip`, et sa palette par
  défaut n'est pas celle-ci : la lui passer explicitement.
- Nouvelle teinte nécessaire : ne pas en inventer une. Ajouter un créneau
  demande de revalider la palette entière, pas seulement la nouvelle couleur.
- Le catalogue des composants partagés
  (`docs/aurora-client/dev/shared_components_catalog.md`) dit lequel des deux
  choisir.
