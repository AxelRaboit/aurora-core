# Grille de contenu 48 colonnes

> **Statut (2026-08-08) : à démarrer.** Les décisions structurantes sont
> prises et la primitive CSS est déjà livrée et testée. Ce document existe
> pour qu'on reprenne sans re-débattre.

---

## L'idée

Aujourd'hui le corps d'une publication est **une colonne** d'Editor.js. On veut
des **zones posées sur une grille de 48 colonnes** : redimensionnables,
déplaçables, avec du contenu de nature variable dans chacune.

C'est la suite directe de la bannière, qui utilise déjà cette grille pour ses
items. Le vocabulaire, la primitive CSS et la moitié des patterns existent.

## Ce qui est déjà décidé

**48 colonnes, et ce n'est pas arbitraire** : 48 = 4 × 12 = 2 × 24. Un douzième
fait 4 colonnes, un vingt-quatrième en fait 2. Moitiés, tiers, quarts, sixièmes
et huitièmes tombent tous sur des entiers.

**Pas de grille secondaire.** Une seule grille à 48 colonnes, et un **pas
d'aimantation variable** : 4 par défaut (on travaille en douzièmes), 2 ou 1
quand on veut affiner. Choisir le pas plutôt que changer de grille évite d'avoir
deux systèmes de coordonnées à réconcilier.

**Largeurs responsives en `{base, md, lg}`**, comme la bannière. Un palier
absent hérite de celui du dessous par la chaîne de repli de la variable CSS —
c'est ce qui évite une règle par combinaison.

**Types de contenu par zone** (v1) :

| Type | Note |
|---|---|
| Texte Editor.js | l'éditeur actuel, dans une zone |
| Une autre publication | choisie par select — c'est la « carte » |
| Un média direct | image ou vidéo depuis la GED |
| Une URL vidéo | YouTube, Vimeo, Dailymotion |

## Ce qui existe déjà et qu'il faut réutiliser

| Brique | Où | Remarque |
|---|---|---|
| `.aurora-grid` | `src/Core/assets/css/base/aurora-grid.css` | 48 pistes, `column-gap: 0`, gouttière en `padding-inline` sur les items |
| Garde-fou de la gouttière | `tests/Unit/AuroraGridGutterTest.php` | échoue si un `.aurora-grid` reçoit un gap de colonne |
| Forme du span | `BannerNormalizer::span()` | `{base, md, lg}`, borné 1..48 |
| Span → CSS | `BannerViewBuilder::spanStyle()` | propriétés personnalisées, pas des classes |
| Constructeur d'items | `PostBannerPanel.vue` | ajout / monter / descendre / supprimer |
| Aperçu serveur | `BannerPreviewController` | rend le vrai Twig, pas une réimplémentation Vue |
| Ids d'items stables | `BannerNormalizer::itemId()` | joint deux moitiés sans dépendre de la position |

**Le piège de la gouttière, à ne pas refaire.** Un `column-gap` sur 48 pistes,
ce sont **47 gouttières**, pas une entre items. À 2rem, ça fait 1504px de gap
dans un conteneur de 1280 : toutes les pistes tombent à zéro et le dernier item
sort du cadre. Les gouttières vivent sur les items. Le test ci-dessus l'empêche
de revenir.

## La question à trancher en premier

**Où vit le contenu de la grille ?**

La bannière a été scindée en deux : la mise en page sur le post (partagée par
toutes les langues), les textes sur chaque traduction, joints par id d'item.
La grille pose exactement la même question, et il faut y répondre avant
d'écrire la première ligne.

Aujourd'hui `blocks` est **par traduction**. Si la grille remplace ou double
`blocks`, on retrouve le problème d'origine : refaire la mise en page dans
chaque langue, et deux versions qui divergent sans que rien ne dise laquelle
fait foi.

Réponse probable — la même que pour la bannière : **la disposition des zones
sur le post, le contenu de chaque zone par traduction**. Mais un « contenu de
zone » n'est pas un simple texte : une zone « autre publication » pointe un id,
une zone média pointe un `mediaId`. Ceux-là sont-ils partagés ou traduits ?
(Une vidéo peut avoir une version par langue ; une publication liée en a une par
définition.) À décider explicitement, pas par défaut.

## Défauts du voisinage — corrigés le 2026-08-08

Quatre choses cassées ont été trouvées sur ce terrain en préparant ce document.
Toutes corrigées avant d'écrire la grille, pour ne pas construire dessus.
Conservées ici parce qu'elles expliquent des choix qui suivront.

**1. `BlockHtmlSanitizer` supprimait les `<span>`** — donc la couleur de
texte, la couleur de fond et la taille de police, visibles dans l'éditeur et
nulle part ailleurs. En creusant, bien pire : `strip_tags` garde une balise
entière ou la jette entière, donc `<a href="javascript:…">` et
`<b onmouseover="…">` passaient tels quels. Faille XSS stockée. Le sanitizer
filtre désormais les attributs un par un, via DOM. **Toute nouvelle balise
autorisée doit déclarer ses attributs dans `ALLOWED`.**

**2. `twoColumn` publiait deux div vides**, et **`mediaText` publiait son
texte sans image** — même cause : le renderer lisait une forme que l'outil
n'écrit pas. Les deux lisent maintenant la vraie forme. La grille rend
probablement `twoColumn` obsolète : deux zones côte à côte, c'est exactement ce
qu'elle fait. À supprimer le moment venu, avec conversion des contenus.

**3. `.two-column` et `.media-text` n'avaient aucune CSS publique** — seul
`.media-text-block`, la classe de l'éditeur, était stylée. Les deux blocs
publiaient des div nues. Styles ajoutés dans `base/content-blocks.css`, qui est
le fichier des blocs **rendus** ; `components/editor/blocks.css` ne sert qu'à
l'éditeur. **La grille doit poser ses styles dans le premier, pas le second.**

## Étapes proposées

Chacune verte et livrable, comme pour la fusion Media → GED.

1. **Trancher le modèle de stockage** (cf. plus haut) et écrire le
   normaliseur — c'est le contrat, tout en découle.
2. **Rendu public** : le Twig de la grille sur `.aurora-grid`, avec un seul type
   de zone (texte Editor.js). Publiable tel quel.
3. **Éditeur** : poser, redimensionner, déplacer les zones. Pas de
   glisser-déposer au début — le projet réordonne partout par monter/descendre,
   et une zone dans une grille se décrit très bien par un span et une position.
4. **Les trois autres types de zone**, un par un.
5. **Aperçu** via le même Twig, sur le patron de `BannerPreviewController`.
6. **Sort de `blocks`** : cohabitation, migration, ou remplacement. À décider à
   ce moment-là, avec les autres étapes vertes.

## Ce qu'il ne faut pas oublier

- **Le pas d'aimantation est une option d'auteur**, pas une constante : 4 par
  défaut, 2 et 1 disponibles.
- **L'aperçu passe par le vrai Twig.** Réimplémenter le rendu en Vue serait plus
  rapide et divergerait — c'est exactement comme ça que `twoColumn` en est
  arrivé à écrire une forme que son renderer ne sait pas lire.
- **Tailwind ne voit que les classes qu'il peut lire dans les sources.** Un span
  est un nombre choisi à l'exécution : il passe par une propriété
  personnalisée, jamais par une classe assemblée par concaténation.
- **Un `<h1>` par page.** La bannière prend la main quand elle porte un titre,
  sinon c'est le titre de la publication. Si une zone de grille peut produire un
  titre de niveau 1, ce calcul est à revoir — aujourd'hui l'éditeur de blocs
  n'offre que les niveaux 2 à 4, ce qui garantit qu'il n'y a que deux sources
  possibles.
