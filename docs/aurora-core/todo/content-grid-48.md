# Grille de contenu 48 colonnes

> **Statut (2026-08-09) : cinq étapes sur six livrées.** Le contrat, le rendu
> public, les quatre types de zone, l'éditeur et l'aperçu sont en place et
> testés. Reste le sort de `blocks`, qui cohabite — et un chantier d'ergonomie
> sur le réglage de largeur, décrit plus bas.

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

## À reprendre : rendre le réglage de largeur utilisable

> **Signalé le 2026-08-09.** Les curseurs fonctionnent, mais ils demandent trop
> à l'auteur. Ce chapitre existe pour reprendre le sujet sans avoir suivi la
> session qui l'a produit — il décrit l'existant, pas seulement la cible.

### Ce qu'il y a aujourd'hui

Un `AppRange` par zone dans `PostGridPanel.vue`, borné `min = snap`,
`max = 48`, `step = snap`, avec un libellé « **24 colonnes sur 48** »
(`backend.posts.grid.width_label`).

Le pas d'aimantation est un `AppSelect` en haut du panneau : 4 (douzièmes,
défaut), 2 (vingt-quatrièmes), 1 (colonne par colonne).

Côté état, dans `usePostGrid.js` :

- `width` est un computed accessible en écriture qui ne pilote **que**
  `span.lg`. `span.base` reste à 48 — une zone est pleine largeur sur
  téléphone, toujours. `span.md` n'est jamais écrit.
- `clampToSnap(value, step)` arrondit au pas puis borne entre `step` et 48.
- Changer le pas **ne réécrit pas** les zones déjà posées ; seules les largeurs
  réglées ensuite atterrissent dessus.

### Pourquoi ce n'est pas suffisant

1. **L'unité n'est pas celle de l'auteur.** « 24 colonnes sur 48 » est une
   coordonnée d'implémentation. On pense en moitié, tiers, quart.
2. **Aucun retour sur le résultat pendant le geste.** L'aperçu est en haut du
   panneau et se redessine 400 ms après ; le curseur, lui, ne montre rien de la
   proportion obtenue.
3. **Viser est difficile.** Au pas 1 le curseur a 48 arrêts. À la souris c'est
   pénible, au trackpad davantage, et pour quelqu'un dont la motricité fine est
   limitée c'est un obstacle réel.
4. **Rien ne dit ce qui se passe entre zones.** Deux zones à 32 ne tiennent pas
   sur une ligne, et rien ne le signale avant l'aperçu.

### Pistes, avec ce qu'elles coûtent

**A — Fractions nommées.** Une rangée de puces : 1/1, 1/2, 1/3, 2/3, 1/4, 3/4,
1/6, 5/6. Discret, nommé dans l'unité de l'auteur, atteignable au clavier.
Toutes tombent juste sur 48 (48, 24, 16, 32, 12, 36, 8, 40). Perd les largeurs
arbitraires — à moins de garder une échappatoire « précis » qui rouvre le
curseur. **Le pas d'aimantation devient probablement inutile**, ou une option
avancée : c'est la fraction qui porte le sens.

**B — Sélecteur en cellules.** Douze cases cliquables (au pas 4) qu'on parcourt
au clic-glissé, comme une mini-grille. Manipulation directe, la proportion se
voit. Reste à décider ce qu'il devient au pas 1 — 48 cases sont trop fines.

**C — Poignées sur l'aperçu serveur.** Redimensionner en tirant le bord d'une
zone dans l'aperçu lui-même. **À écarter** : l'aperçu est du HTML rendu par le
serveur, injecté en `v-html` (`useServerPreview`, `GridPreviewController`).
Poser des poignées dessus veut dire calculer des positions sur du markup qu'on
ne contrôle pas, et le recalculer à chaque re-rendu débounced. Voir E, qui
obtient le même geste sans ce problème.

**D — Gabarits de ligne.** Choisir une ligne (50/50, 33/67, tiers, …) et y
déposer les zones. Le plus lisible pour un débutant, mais **ça change le
modèle** : on passerait de « zones qui s'enchaînent avec un span » à « lignes
qui contiennent des zones ». Le normaliseur, le rendu et la migration suivent.

**E — Une toile de structure, manipulable.** La cible la plus ambitieuse et,
sur le fond, la plus juste : on voit la grille, on tire le bord d'une zone pour
la redimensionner, on clique dedans pour ouvrir son contenu.

*L'objection que je croyais rédhibitoire ne l'est pas.* Une toile qui dessine la
**structure** n'est pas un second moteur de rendu : elle pose des boîtes sur
`.aurora-grid` avec les mêmes `--span-*`, donc **la géométrie est celle du site,
littéralement le même CSS**. Une zone y affiche une icône de type et un libellé
— jamais ses blocs, son image ou sa vidéo. L'aperçu serveur reste l'autorité
sur le contenu ; la toile ne parle que de disposition. C'est la distinction qui
rend cette piste abordable, et je l'avais manquée en écartant C.

Ce qu'elle demande vraiment :

- **Redimensionner** : événements pointeur sur une poignée de bord, colonne
  déduite de la position en x dans la grille, aimantée. `layout.zones` est déjà
  la seule source ; la toile écrit dedans comme le curseur le fait aujourd'hui.
- **Ouvrir une zone** : les champs par type sortent de la liste verticale pour
  aller dans un panneau latéral ou une modale. **Attention à Editor.js** — le
  démonter en fermant perd la pile d'annulation. Il faut `v-show`, ou garder les
  instances vivantes (le registre de `usePostEditor` est fait pour ça).
- **Réordonner** : le glissé dans une grille qui reflue est difficile à viser.
  Garder monter/descendre sur la zone sélectionnée est plus sûr, et cohérent
  avec le reste du backend.

**Le piège à ne pas reproduire.** Ce chantier est né d'une remarque
d'accessibilité : viser un curseur est difficile pour certaines personnes. Une
toile *uniquement* manipulable à la souris recrée le même problème en pire. Il
faut un chemin discret et clavier — les fractions nommées de A, sur la zone
sélectionnée. **A et E ne sont donc pas concurrentes : A est le chemin
accessible de E.**

### Ce que ça gagnera vraiment — et ce que ça ne réglera pas

**Le gain est dans l'unité, pas dans le contrôle.** « 24 colonnes sur 48 » est
une coordonnée ; « la moitié » est une pensée. C'est le seul point où changer
l'interface change réellement ce que l'auteur a à faire — et c'est la piste A
qui l'obtient, la moins chère des quatre. Un bouton nommé se décrit lui-même,
ce qui dissout au passage une bonne part du besoin de retour pendant le geste :
on n'a pas besoin de voir le résultat d'un réglage qui dit ce qu'il fait.

**Le coût est mesurable et faible.** Au pas 4, douze largeurs sont atteignables.
Les huit fractions en couvrent huit. Les quatre perdues sont 1/12, 5/12, 7/12
et 11/12 — des proportions qu'on ne dessine pas. Une échappatoire « précis »
peut les rendre, mais elle ne se justifie qu'à l'usage.

**Ce qui ne sera pas réglé pour autant : rien ne dit si une ligne tient.** Deux
zones à 2/3 ne rentrent pas ensemble, et l'auteur ne l'apprend qu'en regardant
l'aperçu. Ni A ni B ne corrigent ça — c'est un problème de *relation entre
zones*, pas de réglage d'une zone. E le règle par construction, puisqu'on voit
la ligne.

Le remède est indépendant et moins cher que n'importe laquelle des pistes : une
**indication de ligne** dans le panneau — « cette ligne : 32 + 16 = 48, pleine »
ou « 32 + 32 = 64, la seconde passe à la ligne ». Le total est déjà calculable
à partir de `layout.zones`, sans requête ni rendu. Fait avec la piste A, ça
couvre probablement l'essentiel du sujet ; fait seul, ça aiderait déjà.

### Contraintes à ne pas redécouvrir

- **`span.base` reste 48.** Côte à côte sur téléphone, c'est deux colonnes de
  quatre mots. Ce que règle l'auteur, c'est le grand écran.
- **Tailwind n'émet que les classes qu'il lit dans les sources.** Une largeur
  est un nombre choisi à l'exécution : elle passe par les propriétés
  personnalisées `--span-*`, jamais par une classe assemblée.
- **Les gouttières viennent du padding des items**, pas d'un `column-gap` — 47
  gouttières sur 48 pistes. `AuroraGridGutterTest` le fait échouer en CI.
- **`.aurora-grid-flush`** annule le décalage des bords extérieurs. Toute
  nouvelle grille rendue dans le flux d'un article en a besoin.

### Ce qui se cassera, et qu'il faudra reprendre

- `usePostGrid.test.js` — 17 tests, dont cinq portent directement sur le pas :
  « lands a width on the current step », « reaches finer widths on a finer
  step », « never lets a zone reach zero or overflow the grid », « leaves
  placed zones alone when the step changes », « keeps a zone full width below
  the large breakpoint ». Les garanties restent valables quelle que soit
  l'interface ; ce sont les appels qui changent.
- Les clés `backend.posts.grid.width`, `width_label`, `snap`, `snap_hint` et
  `snaps.*` — à revoir ou à retirer selon la piste retenue.
- `GridNormalizer::SNAPS` et le champ `snap` du layout, si le pas disparaît.
  **Attention** : il est déjà persisté en base sur deux publications de démo.

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
