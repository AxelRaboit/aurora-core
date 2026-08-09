# Grille de contenu 48 colonnes

> **Statut (2026-08-09) : cinq étapes sur six livrées.** Le contrat, le rendu
> public, les quatre types de zone, l'éditeur et l'aperçu sont en place et
> testés. Reste le sort de `blocks`, qui cohabite.

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

## La question tranchée (2026-08-09)

**Où vit le contenu de la grille ?** Par le précédent de la bannière, pas par un
arbitrage neuf.

| Partagé — sur le post | Par langue — sur la traduction |
|---|---|
| id de zone, **type**, span, ordre | blocs de texte |
| `mediaId` | `alt`, `caption` |
| `postId` de la publication liée | URL vidéo |

Chaque côté se justifie. Une zone qui serait du texte en français et une vidéo
en anglais n'est pas une zone. Une publication liée a ses propres traductions —
c'est au rendu de choisir la bonne, pas à l'éditeur de la re-choisir. Une image
est la même image ; la décrire, c'est écrire. Et l'adresse d'une vidéo est du
contenu : la première bannière écrite pointait vers `/fr/page/premiers-pas`.

**Les zones s'enchaînent**, elles ne sont pas posées en coordonnées.
Redimensionner change un span, déplacer réordonne. Pas de cellule vide à gérer,
et le placement libre reste ajoutable — l'inverse ne l'est pas.

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

## Étapes

Chacune verte et livrable, comme pour la fusion Media → GED.

1. ✅ **Modèle et normaliseur** — `GridNormalizer`, deux colonnes, migration,
   passage par le DTO et le manager. 19 tests unitaires, 3 d'intégration sur la
   frontière d'écriture. *(32927c47)*
2. ✅ **Rendu public** — `GridViewBuilder` + `_grid.html.twig` sur
   `.aurora-grid`, branché dans `PostPageRenderer`. La grille **remplace** la
   colonne de blocs, elle ne s'y ajoute pas. 13 tests. *(c50fd299)*
3. ✅ **Éditeur** — `usePostGrid` + `PostGridPanel`, dans l'onglet Contenu.
   Largeur au curseur par pas d'aimantation, réordonnancement par
   monter/descendre. 17 tests. *(0a4095f6)*
4. ✅ **Les quatre types de zone** — livrés avec l'étape 2 plutôt qu'après :
   une zone configurée qui ne rend rien ressemble à un bug pour qui vient de la
   configurer. Les vidéos passent par `VideoEmbedResolver`, 21 tests.
5. ✅ **Aperçu** — `GridPreviewController` rend le vrai Twig, le panneau
   l'affiche au-dessus des zones. Le composable d'aperçu de la bannière a été
   généralisé en `useServerPreview` au passage plutôt que dupliqué : seul le
   format de charge différait. 6 tests d'intégration, 7 sur le composable.
6. ⬜ **Sort de `blocks`** — aujourd'hui les deux **cohabitent** : le panneau de
   blocs disparaît quand la grille est activée (`supportsBlocks &&
   !gridLayout.enabled`), et le rendu public fait le même choix. Rien n'est
   migré, rien n'est supprimé. La décision reste entière.

### Ce qui a été fait en plus du plan

- **Fixtures de démo** : la page d'accueil et l'article portent chacun une
  grille, arrangées différemment — 48/24+24/32+16/48 pour l'une, 48/16+32/24+24
  pour l'autre. *(abfb5fde, 4d6b8998)*
- **`.aurora-grid-flush`** : le padding des items décalait les deux bords
  extérieurs, donc la grille ne s'alignait pas sur le titre au-dessus.
  *(3ba74bca)*

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
