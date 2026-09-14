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

1. **Un seul geste primaire reste visible, sauf sur un toolbar de liste.**
   Sur une page qui édite ou montre **un** enregistrement, le verbe de la page
   garde son bouton : Enregistrer sur un éditeur, Présenter sur un deck,
   Contresigner sur un contrat. L'enterrer derrière un clic est l'erreur que ce
   composant rend facile.

   Le **toolbar d'une liste** est l'exception, et elle est **validée en
   regardant l'écran** (Axel, 14/09/2026, sur `/backend/studio/decks`) : Créer
   part dans la feuille avec les autres, et il ne reste qu'un bouton
   « Actions » à droite de la recherche. La différence tient à ce qui occupe la
   rangée : un en-tête d'éditeur n'a que ses boutons, un toolbar de liste a une
   recherche et souvent un filtre qui veulent la largeur. **Ne pas « corriger »
   `DecksApp` ou `ContractTemplatesApp` en ressortant Créer** : c'est le rendu
   voulu, pas un oubli.
2. **Deux seuils, pas un.** Une **ligne** passe en feuille dès **deux**
   actions, c'est la convention déjà appliquée par une quinzaine de listes et
   ce qu'outille `@/shared/composables/useEditDeleteActions.js` (qui exige une
   clé de description par entrée : sans clé, écrire la liste à la main plutôt
   qu'inventer la phrase). Un **en-tête de page** ou
   une **barre de sélection** n'y passe qu'à partir de **trois** : deux boutons
   tiennent partout, y compris à 375 px
   (cf. [[convention_multi_button_toolbar]]).
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

## Ce qui reste en glyphes

Un **glyphe qui porte un état ou qu'on presse en rafale n'est pas une action**
et ne descend pas dans la feuille :

- Monter / descendre d'un rang (`FormsApp`, `TaxonomiesApp`, l'éditeur de
  deck) : une feuille ferait de chaque cran un open-click-close.
- Le chevron d'un arbre, l'étoile d'un favori : ils montrent leur propre état.
- Les outils d'un canevas (`PostGridPanel`, `PostGridCanvas`) : ils sont en
  contact direct avec ce qu'ils modifient.

Deux rangées d'arbre gardent leur bande de glyphes au survol,
`FolderTreeRow` et `NoteTreeItem` : elles n'ont que deux actions chacune à côté
d'un toggle qui reste de toute façon en ligne, et leur déclencheur demanderait
un `v-on:click.stop` que `AppRowActions` n'expose pas.

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
