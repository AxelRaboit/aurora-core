---
name: process_mobile_header_bar
description: La barre d'entête d'un écran (AppPageBar) - retour à gauche, commandes à droite, vrais boutons de 38 px, icône seule sous sm ; et la règle qui ne s'applique pas aux boutons pleine largeur.
metadata:
  type: project
---

## Règle

### Une seule barre : `AppPageBar` (02/10/2026)

Tout écran qui a un retour ou des commandes de page pose `AppPageBar` en
tête : **le retour à gauche, les commandes à droite**, sur téléphone comme sur
ordinateur. Le titre n'entre pas dans la barre ; il vient dessous, sur sa
ligne. Avant elle, quatre manières de pousser les commandes à droite
(`justify-between`, espaceur, `ml-auto`, rien) et deux écrans (trame, texte
adapté) qui collaient les commandes derrière le retour.

Les commandes y sont **de vrais boutons, tous en `md` (38 px)**, icône
`w-4 h-4` : `AppPageActions icon-only-on-phone` (secondary) puis le verbe
principal (`AppButton :label icon-only-on-phone`, primary), le plus à droite.
Une bascule permanente (favori, panneau) prend `icon-only`. Un `ghost` n'a
rien à faire dans une barre d'entête.

### Icône nue ou vrai bouton

- **Commande d'une barre** (entête de page, barre d'outils, barre de
  sélection) : vrai bouton, fond ou filet.
- **Geste dans le contenu** (« … » d'une ligne de tableau, croix d'une
  modale, outils de l'éditeur, cadre de l'application en haut) : icône nue,
  `AppIconButton`, toujours avec un `title`.

### Le retour

`AppBackLink`, jamais un bouton. Chevron seul sous `sm`, libellé à partir de
là, `aria-label` dans les deux cas. Il se rend en ancre avec `href`, en bouton
qui émet `back` sans.

Un retour est une navigation. Un bouton, même fantôme, le met au poids
d'« Enregistrer » posé à un centimètre, et sur téléphone où les deux
s'empilent l'écran perd sa hiérarchie.

### Les commandes de la barre

Dans une barre d'entête **qui reste horizontale sous `sm`**, les libellés
partent : `<span class="sr-only sm:not-sr-only">` plus un `title`.
`AppPageActions` le fait avec `iconOnlyOnPhone`.

**Ce n'est pas la règle partout.** Un bouton qui prend la ligne entière - ce
que la maison demande par ailleurs pour les gestes d'un formulaire ou d'une
liste - garde son nom : privé de libellé il devient une barre vide avec trois
points au milieu. Mesuré à 359 px de large sur la liste des utilisateurs.

Les deux règles répondent à deux situations. Le composant dit dans laquelle
il se trouve ; on ne le devine pas depuis la largeur.

## Pourquoi

Le même geste existait sous quatre formes - bouton fantôme, ancre à flèche,
ancre à chevron, bouton de texte - et l'entête de l'éditeur de publication
empilait quatre bandes de commandes sur un téléphone, sans hiérarchie. Après :
une ligne de 34 pixels, trois contrôles.
