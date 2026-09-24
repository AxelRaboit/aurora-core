---
name: process_mobile_header_bar
description: La barre d'entête d'un écran d'édition sur téléphone - retour en chevron seul, libellés qui partent, et la règle qui ne s'applique pas aux boutons pleine largeur.
metadata:
  type: project
---

## Règle

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
