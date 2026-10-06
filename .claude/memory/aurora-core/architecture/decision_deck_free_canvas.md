# Diapositives : la diapo libre est un gabarit de plus

## Règle

Le moteur de diapositives (`Deliverable/Slides`, celui des livrables au format
présentation) garde ses gabarits déclarés **et** gagne une diapo libre
(`SlideLayoutEnum::Free`), éditée comme dans Canva : éléments posés à la main
(texte, image, vidéo, YouTube/Vimeo, forme, icône, graphique, tableau). Ce n'est
pas un mode du moteur, c'est un gabarit de plus. Pas de retour d'une diapo libre
vers un gabarit : on revient en arrière par l'annulation juste après « Rendre
libre ».

## Pourquoi

Les gabarits sont ce qui permet à `useSlideFit` d'ajuster le texte et à
`SlidesFromBlocks` d'importer un document écrit. Le canevas libre a été refusé
jusqu'au 30/09/2026, puis demandé (« comme Canva »). En faire un gabarit garde
ces deux atouts partout ailleurs.

## Comment l'appliquer

- **Unités** : positions en % de la diapo (`x`/`w` de la largeur, `y`/`h` de la
  hauteur), toutes les autres longueurs en millièmes de la largeur (rendues en
  `cqw`). Jamais de pixel. Rotation calculée dans un espace carré.
- **L'ordre de la liste `elements` est l'ordre d'empilement.** Pas de `z`.
- **Une seule liste blanche** : `FreeSlideNormalizer` (bornes, types, couleurs
  `#rrggbb(aa)` ou `ink`/`accent`/`background`). Ses listes (formes, découpes,
  entrées…) sont envoyées à l'éditeur par `FreeSlideNormalizer::options()`, ne
  pas les recopier côté Vue.
- Le texte passe par `FreeTextSanitizer` (pas `BlockHtmlSanitizer` : pas de
  lien, `span style` limité aux deux couleurs).
- Les clés dérivées (`mediaUrl`, `videoUrl`, `poster`, `embedUrl`,
  `thumbnail`…) sont ajoutées par `SlidesSerializer` et jetées à l'écriture.
- **Polices** : catalogue `@fontsource` chargé à la demande (`free/fonts.js`),
  jamais Google ; polices importées = documents GED servis par
  `DeliverableFontsController` (`/deliverables/fonts/{id}`, route
  `public_deliverable_font`, éteinte avec le module Livrables) depuis la fusion
  des présentations dans les livrables, jamais par `/uploads` (la CSP
  `font-src` refuse une redirection vers R2).
- **Lecteur** : le 16:9 du lecteur plein écran tient par `aspect-ratio` ; le
  remplissage en % se résolvait sur la cellule de grille (diapo déformée sur un
  écran large). Ne pas revenir au seul `padding-top`.
- `structuredClone` échoue sur un proxy réactif de Vue : cloner par JSON.
- « Rendre libre » (`free/fromTemplate.js`) lit la diapo dessinée (boîtes,
  styles calculés, pseudo-éléments positionnés) : un gabarit qui change de CSS
  change la conversion sans rien à mettre à jour.
