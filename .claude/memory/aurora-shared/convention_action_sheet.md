---
name: convention_action_sheet
description: Les actions multiples se lisent dans une feuille modale derrière un bouton unique - AppRowActions pour une ligne, AppPageActions pour un en-tête de page, à partir de 3 actions
metadata:
  type: feedback
---

## Règle

Une liste d'actions ne s'étale pas en boutons côte à côte. Elle vit derrière
**un seul** bouton qui ouvre une feuille modale, une action par ligne, nommée
en toutes lettres.

Trois composants dans `@/shared/components/action/` :

- `AppActionSheet` : la mécanique (la modal, l'ordre, la fermeture avant
  exécution). **Ne pas l'utiliser directement** : elle a deux façades et elles
  sont censées rester les deux seules.
- `AppRowActions` : la façade ligne de tableau. Déclencheur = trois points.
- `AppPageActions` : la façade en-tête de page. Déclencheur = un bouton qui dit
  « Actions ».

```vue
<AppPageActions
    v-if="headerActions.length"
    :actions="headerActions"
    :busy="previewing || saving"
/>
```

Une action : `{ key, title, description?, color?, icon?, href?, onSelect?,
disabled?, loading? }`.

**Les cinq règles de placement :**

1. **Un seul geste primaire reste visible.** Enregistrer, Publier, Créer gardent
   leur propre bouton. Enterrer le verbe principal derrière un clic est
   l'erreur que ce composant rend facile.
2. **À partir de trois actions, une feuille.** Deux boutons tiennent partout,
   y compris à 375 px (cf. [[convention_multi_button_toolbar]]).
3. **La navigation n'est pas une action.** « Retour à la liste » reste à gauche,
   hors de la feuille.
4. **Ordre : ordinaire, puis prudent (amber), puis destructeur (rose), en
   dernier.** Même ordre en ligne et en page.
5. **Le déclencheur page est étiqueté.** Dans un tableau la colonne s'appelle
   « Actions » et trois points suffisent ; en haut d'une page rien ne le dit.

**La liste appartient à l'appelant**, dans un composable ou un `computed`,
jamais écrite dans le template : elle dépend des privilèges et de l'état de
l'enregistrement.

## Pourquoi

Un en-tête gagne un bouton par capacité jusqu'à devenir un mur. `PostEditorApp`
en alignait six dans un `flex` sans `flex-wrap` : sur un téléphone, un article
en attente de relecture les écrasait en tranches illisibles. Une glyphe ne parle
qu'à qui la connaît déjà, et la destructrice finit à quelques pixels des
inoffensives.

Douze listes qui font chacune pousser leur propre modale, ce sont douze endroits
où elle peut diverger. D'où la mécanique unique et les deux façades.

## Comment l'appliquer

- `loading` sur une action : elle tourne, la ligne le montre si on rouvre la
  feuille, et le clic ne repart pas.
- `busy` sur `AppPageActions` : la feuille s'est fermée en lançant l'action, le
  déclencheur porte le spinner à la place du bouton disparu.
- `href` au lieu d'`onSelect` pour une navigation, qui doit rester ouvrable dans
  un nouvel onglet. **Attention** : `AppActionButton` ne pose pas l'attribut
  `download`, un fichier s'ouvre donc au lieu de se télécharger.
- Pas de footer dans la feuille : le corps *est* la liste d'actions
  (cf. [[convention_modal_and_confirmation]], écrite pour les modales de
  formulaire, qui ne couvre pas ce cas).
- Une action déjà offerte ailleurs sur l'écran peut sortir de la feuille sans
  disparaître : `DocumentShowApp` a laissé partir « Télécharger » parce que le
  bloc fichier plus bas l'offre déjà.

## Pointeurs

- Lien : [[convention_multi_button_toolbar]] - le cas à deux boutons, qui reste
  un wrapper flex et pas une feuille.
- Lien : [[pattern_admin_list_toolbar]] - le slot `#actions` où atterrit le
  geste primaire.
- Lien : [[convention_mobile_card_layout]] - la carte mobile, qui déplie ses
  actions au lieu d'ouvrir une feuille : un menu dans un menu sur un téléphone
  est un geste de trop.
- Exemples en code : `PostEditorApp.vue` (liste conditionnelle, décision de
  relecture), `DocumentShowApp.vue` (liste partagée avec les lignes de la GED
  via `useDocumentRowActions`), `ContractDocumentApp.vue` (deux actions
  remontées du milieu de page, où elles étaient sous un mur de hashes), les
  quinze listes qui utilisent déjà `AppRowActions`.
