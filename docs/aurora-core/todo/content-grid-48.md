# Grille de contenu 48 colonnes

> **Statut (2026-08-09) : les six étapes livrées, plus le réglage de
> largeur.** Le contrat, le rendu public, les quatre types de zone, l'éditeur et
> l'aperçu sont en place et testés. Le réglage de largeur est passé à une toile
> manipulable doublée d'une rangée de fractions au clavier — voir le chapitre
> qui lui est consacré, et une **pile** permet à une zone haute de côtoyer deux
> zones empilées. `blocks` a été migré dans la grille : elle est désormais le
> seul corps d'une publication.

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
| Une pile | *ajouté le 2026-08-09* — des zones empilées qui se partagent la hauteur de la ligne |

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
6. ✅ **Sort de `blocks`** — tranché le 2026-08-09 : **la grille devient le
   corps, seule.** Chaque colonne de blocs est passée en une zone texte pleine
   largeur, ce qu'elle a toujours été. Le panneau de blocs disparaît de
   l'éditeur ; `supportsBlocks` nomme toujours les types qui ont un corps, seul
   ce qu'il ouvre a changé.

   **Décidé sur mesure, pas sur intuition.** Une publication rendue des deux
   façons place son paragraphe, son h2 et sa liste au même pixel — même gauche,
   même largeur, même hauteur. Le HTML intérieur est identique, seuls trois
   conteneurs l'entourent, et `prose` remet à zéro la marge du premier enfant là
   où le chemin bloc la donnait. **Rien ne bouge sur une page publiée**, ce qui
   était le seul vrai risque puisque la migration tourne aussi chez les clients.

   **La colonne `blocks` n'est pas supprimée.** Elle garde chaque valeur, ce qui
   rend la migration réversible : le `down()` n'a qu'à rééteindre la grille,
   puisque les mots ne sont jamais partis. La supprimer est une décision d'un
   autre jour, prise quand plus rien ne l'aura lue depuis longtemps — pas le
   jour où la donnée bouge. *(Version20260809150000)*

### Ce qui a été fait en plus du plan

- **Fixtures de démo** : la page d'accueil et l'article portent chacun une
  grille, arrangées différemment — 48/24+24/32+16/48 pour l'une, 48/16+32/24+24
  pour l'autre. *(abfb5fde, 4d6b8998)*
- **`.aurora-grid-flush`** : le padding des items décalait les deux bords
  extérieurs, donc la grille ne s'alignait pas sur le titre au-dessus.
  *(3ba74bca)*

## Réglage de largeur : tranché et livré le 2026-08-09

> **Décision : la toile d'abord, avec les fractions comme chemin clavier.** La
> piste E (toile manipulable), allégée d'un cran — la liste verticale des zones
> reste, seul le réglage de largeur change. La piste A n'est pas une alternative
> à E, c'est la façon d'y accéder au clavier.
>
> Les sections qui suivent conservent l'analyse qui a mené là, parce qu'elle
> explique des choix qui ne se lisent pas dans le code.

### Ce qui a été livré

| Brique | Où |
|---|---|
| La toile | `PostGridCanvas.vue` — une boîte par zone sur `.aurora-grid`, poignée de bord droit, sélection au clic |
| Le placement | `placeZones()` dans `usePostGrid.js` — ligne et colonne de départ de chaque zone |
| Les fractions | `WIDTH_FRACTIONS` + `widthOptions` dans `usePostGrid.js`, rendues par `AppChoiceRow` |
| La rangée de choix | `Core/assets/shared/components/form/select/AppChoiceRow.vue` — radiogroup, un seul arrêt de tabulation |

**Ce qui n'a pas bougé** : le modèle. `span.lg`, `layout.zones`, `GridNormalizer`,
le Twig, le rendu public, `GridNormalizer::SNAPS` et le champ `snap` persisté
sur les deux publications de démo. La toile écrit exactement le nombre que la
jauge écrivait, par le même computed `width`.

### Le panneau ne montre qu'une zone à la fois

Demandé après un premier essai : la liste verticale de toutes les zones obligeait
à faire défiler pour atteindre celle qu'on voulait. Le panneau se lit maintenant
**Disposition → bouton Aperçu → la zone sélectionnée → Réglages avancés →
ajouter**.

**L'aperçu est passé dans une modale pleine largeur.** Le panneau est une colonne
de quelques centaines de pixels ; une page dessinée sur 48 colonnes n'y montre
rien d'utile. La modale est le premier endroit où l'aperçu est à l'échelle, et
elle rend au panneau la place qu'il prenait. `no-padding` sur la modale : la
grille apporte ses propres gouttières, et y ajouter celles de la modale
décalerait les deux bords extérieurs — exactement ce que `.aurora-grid-flush`
existe pour empêcher.

**`useServerPreview` sait désormais que personne ne regarde.** Il prend une
option `enabled` ; tant qu'elle est fausse, une modification marque l'aperçu
périmé sans déclencher de requête, et l'ouverture rattrape immédiatement —
sans le debounce, qui existe pour absorber la frappe et pas pour faire attendre
quelqu'un qui vient de cliquer. Réouvrir sans avoir rien changé ne coûte rien.
Le défaut reste « toujours actif », donc l'aperçu de la bannière, qui est en
ligne, n'a pas bougé.

**Les cartes non sélectionnées sont masquées, pas démontées — `v-show`, jamais
`v-if`.** Chaque zone de texte porte une instance vivante d'Editor.js ; la
démonter perd sa pile d'annulation. `AppBlockEditor` fait bien un `flush()` dans
son `onBeforeUnmount`, donc le contenu ne serait pas perdu — mais l'annulation
si, et silencieusement. C'est le même choix que les onglets de langue au-dessus
de ce panneau (`PostEditorApp.vue`, « v-show, not v-if: the block editor holds
its own state »), ce qui prouve au passage qu'Editor.js supporte d'être
initialisé dans un conteneur masqué ici.

**Ajouter une zone la sélectionne.** Sans ça, le bouton poserait une boîte sur
la toile et n'ouvrirait rien — ce qui se lit comme un bouton qui n'a pas marché.

### La toile est le constructeur, pas un afficheur

Les quatre boutons d'ajout vivent **dans le cadre de la toile**, sous la grille.
Un cadre répond ainsi à toute la question « de quoi cette page est faite » : les
zones, leurs largeurs, et comment en obtenir une autre.

**Pas de case « + » dans la grille elle-même**, bien que ce soit plus joli : elle
occuperait de vraies colonnes, donc elle déplacerait les retours à la ligne. Une
toile qui ne passe pas à la ligne comme la page vaut moins qu'une toile plus
sobre qui le fait.

**Convertir une zone** — un choix de type sur la zone sélectionnée. Le modèle
était déjà prêt : le normaliseur écrit toutes les clés quel que soit le type, et
convertir garde l'id, donc les autres langues gardent ce qu'elles ont, et la
largeur comme la place dans l'ordre survivent. Seuls les blocs se perdent, et
seulement à l'enregistrement : ils restent en mémoire côté client, donc repasser
en Texte les restaure. D'où un **avertissement** et non un blocage — bloquer
serait faux, prévenir après coup serait inutile.

**Échanger deux zones** — on glisse une boîte sur une autre. Le doc avait écarté
le glissé, à raison : viser un *intervalle* dans une grille qui reflue est
difficile parce que l'intervalle bouge pendant qu'on le vise. Mais déposer **sur**
une boîte est une autre cible, et une boîte ne bouge pas. Glisser-déposer natif
plutôt que des événements pointeur à seuil : le navigateur sait déjà distinguer
un clic d'un glissé et laisse le clic continuer à sélectionner. Pas d'équivalent
clavier, et il n'en est pas dû — les chevrons monter/descendre réordonnent sans
pointeur.

### Trois pièges de mesure et de positionnement, trouvés à l'œil

Aucun n'a levé d'erreur ni fait échouer un test. Tous ont été vus en regardant.

**L'image d'une zone débordait de sa boîte.** Elle est en `absolute inset-0`, et
la boîte n'était pas `relative` — l'ancêtre positionné était donc l'item de
grille, dont la boîte de padding **inclut les gouttières**. L'image dépassait
d'une gouttière de chaque côté, la zone paraissait plus large, et sa ligne
paraissait plus serrée. `overflow-hidden` ne rattrape pas ça : un bloc ne rogne
pas un descendant absolu dont le bloc conteneur est un de ses ancêtres.

**Les lignes se touchaient pendant que les colonnes respiraient.** Deux voisines
d'une même ligne montrent deux gouttières entre elles ; l'axe des lignes n'avait
rien. `gap-y-2` = 0,5rem rétablit le maillage. Mesuré sur la page publique :
padding d'item 16px, donc 32px entre deux zones, et `gap-y-8` = 32px — le Twig
avait déjà fait ce calcul.

**Le glissé mesurait la mauvaise boîte.** Le `p-2` était sur l'élément
`.aurora-grid`, celui que `getBoundingClientRect()` mesure. Les 48 pistes vivent
dans la boîte de **contenu**, le rect renvoie la boîte de **bordure** : l'erreur
valait zéro au centre et environ une colonne à chaque bord. Le padding est passé
sur un conteneur intermédiaire, et un test épingle l'invariant — l'élément mesuré
ne porte aucune classe de padding.

### Trois décisions à ne pas défaire

**La toile écrit les largeurs dans `--span-base`, pas `--span-lg`.** La chaîne
réelle n'applique `--span-lg` qu'au-dessus de **1024px de viewport**, et le
panneau se lit souvent dans une fenêtre plus étroite. Laissée aux media queries,
la toile afficherait tout en pleine largeur et les poignées auraient l'air
cassées. Ce que l'auteur règle, c'est le grand écran : la toile le montre à
n'importe quelle largeur de panneau.

**La toile n'arrondit rien.** Elle émet la largeur brute que le pointeur
demande ; l'arrondi au pas et le bornage 1..48 restent dans `clampToSnap`. Deux
clamps, ce seraient deux règles à garder d'accord.

**Le placement est de l'arithmétique, pas de la mesure.** `.aurora-grid` n'a pas
`grid-auto-flow: dense`, donc une zone qui ne rentre pas démarre la ligne
suivante et **rien ne rebouche le trou derrière**. `placeZones` fait la même
boucle, sans lire un seul rect. Un test l'affirme explicitement (« never
backfills the gap a wrap leaves behind ») : si cette hypothèse tombe, la toile
dessine une disposition et la page en rend une autre.

### Les fractions : six, pas huit

`1/4, 1/3, 1/2, 2/3, 3/4, 1/1` — soit 12, 16, 24, 32, 36, 48.

**Toutes sont des multiples de 4**, donc `clampToSnap` les laisse intactes au
pas 4 comme au pas 1. C'est ce qui a rendu le chantier purement additif : ni le
pas ni `SNAPS` n'ont eu à disparaître. Un test le vérifie pour chaque fraction à
chaque pas, parce qu'une puce qui atterrit ailleurs que ce qu'elle annonce est
un bouton qui ment.

**Les sixièmes (8 et 40) sont écartés volontairement**, bien qu'arithmétiquement
aussi propres. Personne ne dessine en sixièmes, et le chantier est né d'une
remarque sur la difficulté à viser : rallonger la rangée de cibles inutilisées
aurait aggravé exactement ce qu'on corrigeait. Elles restent atteignables par le
curseur « précis ».

**Ordre croissant** : la flèche qui élargit dans la rangée va dans le même sens
que la poignée qui élargit sur la toile.

### Ce qui reste ouvert

- **`blocks`** — étape 6, intacte.
- **L'indication de ligne** est devenue inutile : la toile fait passer à la
  ligne au même endroit que le site, donc on le voit.
- **Le rapport d'image** est livré, sur la zone média seulement — voir plus bas.
  La vignette de carte et la vidéo gardent leur `aspect-video` en dur.
- **Le placement absolu** (bord gauche indépendant, hauteur) reste écarté, pour
  les raisons de la section « Redimensionner dans les quatre sens ».
- **La pile** est livrée : une zone haute avec deux zones empilées à sa droite
  se construit, se règle et se publie. Reste à pouvoir y glisser une zone qui
  existe déjà ailleurs.

### Pourquoi les jauges seules ne suffisaient pas

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

> Conservées telles qu'elles ont été écrites avant l'arbitrage. **E et A ont été
> retenues ensemble** ; B et D ne l'ont pas été, et C reste écartée pour la
> raison qui y est notée. La reco initiale disait « A d'abord, la toile ensuite
> si le besoin se confirme » — le besoin s'est confirmé tout de suite, et
> vérifier que `.aurora-grid` est déjà chargée dans le backend a fait tomber le
> principal coût supposé de E.

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

### Redimensionner dans les quatre sens : ce que le modèle permet

Question posée le 2026-08-09, et qui touche le modèle plus que l'interface. Une
zone porte aujourd'hui `id, type, span, mediaId, postId` — **ni hauteur, ni
ligne, ni colonne de départ**.

**Gauche et droite : possible, mais les deux bords font la même chose.** Les
zones s'enchaînent : une zone commence là où la précédente finit. Tirer son bord
gauche ne peut donc que changer sa largeur, exactement comme le bord droit —
alors que le geste laisse attendre un déplacement du point de départ. Un bord
gauche véritablement indépendant suppose une colonne de départ explicite, donc
le **placement absolu** : cellules vides possibles, collisions à arbitrer, et la
question du téléphone qui devient difficile (que devient une disposition 2D sur
une colonne ?). C'est le compromis noté au tout début du document, et il tient
toujours : le placement libre s'ajoute, il ne se retire pas.

**Haut et bas : n'existe pas du tout.** La hauteur d'une zone est celle de son
contenu. La régler suppose soit des `grid-row: span N` avec une hauteur de ligne
fixée — une vraie grille 2D, mêmes conséquences que ci-dessus — soit une hauteur
minimale par zone.

**Et il faut se demander si on la veut.** Imposer une hauteur à du contenu
produit l'un ou l'autre : du texte coupé, ou du vide. La plupart des
constructeurs de page qui l'offrent cassent sur un autre écran que celui où la
page a été dessinée.

### La pile : livrée le 2026-08-09

> **Demande** : une zone haute à gauche, et à sa droite deux zones empilées
> faisant chacune la moitié de sa hauteur. **Impossible avec les seules zones de
> ligne**, et le blocage était dans le modèle.

**Pourquoi ça ne marchait pas.** Les zones s'enchaînent en une suite et passent
à la ligne quand elle est pleine ; aucune ne peut occuper deux lignes. Posez
gauche(24), droiteA(24), droiteB(24) et `placeZones` donne :

| Zone | Ligne | Colonne de départ |
|---|---|---|
| gauche | 0 | 0 |
| droiteA | 0 | 24 |
| droiteB | **1** | **0** |

La troisième se pose **sous** la zone de gauche. Pour qu'elle reste à droite il
faudrait un `grid-row: span 2`, donc le placement explicite que ce document
écarte depuis le début.

**La réponse : un cinquième type de zone**, `stack`, dont le contenu est
d'autres zones. Le placement ne change pas — une pile est une zone de plus, qui
se trouve en contenir d'autres.

**La hauteur n'est déclarée nulle part.** `.aurora-grid` ne pose aucun
`align-items`, donc les items d'une ligne s'étirent déjà à sa hauteur : une pile
fait exactement la hauteur de la zone d'à côté et n'a qu'à répartir ce qu'on lui
a donné. C'est ce seul fait qui rend la piste abordable, et il ne se voit pas en
lisant le CSS.

**Les enfants réutilisent `span` comme part de hauteur.** Dans une pile l'axe
d'écoulement est vertical : « 24 sur 48 » veut dire la moitié de la hauteur
exactement comme il veut dire la moitié de la largeur sur une ligne. Un champ,
un vocabulaire, et la rangée de fractions marche telle quelle.

**Rendu en `flex-grow: <span>; flex-basis: 0`, sans `min-height: 0`.** La basis
donne les proportions exactes plutôt qu'un partage du reste ; l'absence du
min-height empêche un enfant d'être écrasé sous son propre contenu. Proportions
quand ça tient, croissance quand ça ne tient pas, **texte coupé jamais** — c'est
le reproche que ce document fait aux autres constructeurs de page depuis le
début, et il fallait ne pas le mériter.

**Les parts se rééquilibrent.** Ce sont des facteurs relatifs : régler une zone
sur 2/3 pendant que sa voisine reste à 1/2 donnait 57 % et 43 %. Changer une
part rend désormais le reste aux autres, proportionnellement à ce qu'elles
avaient, avec un plancher d'une unité. Et « 1/1 » n'est pas offert comme part :
une zone qui partage sa hauteur par définition ne peut pas la prendre entière.
Le panneau affiche en plus le pourcentage réel — le nombre qui ne peut pas
mentir.

**Deux bornes, testées.** Profondeur d'un niveau : au-delà, une page devient un
arbre de mise en page qu'aucun consommateur ne lit sans récursion sans fin. Ids
uniques sur tout l'arbre : le contenu est indexé par id dans une carte plate, et
deux zones qui partagent un id partageraient leurs mots dans toutes les langues.

**Sur la toile**, une pile dessine ses zones aux mêmes `flex-grow` que la page.
Aucune poignée sur les tranches — une part est une hauteur, et la toile n'a
jamais redimensionné que des largeurs. **Un lâcher sur une pile reste un
échange** : un geste, un sens, sinon l'auteur ne peut plus prédire ce qu'il
obtient.

Livrée en trois tranches : `55f8cdb4` (modèle et rendu public), `2efde2f6`
(panneau), `98835f47` (toile).

**Déplacer une zone existante dans une pile** se fait en la glissant sur une
**tranche** de la pile — pas sur la pile elle-même. Deux intentions demandent
deux cibles : la boîte garde le sens qu'ont toutes les autres, l'échange, et les
tranches disent « dedans, ici ». Une tranche est un rectangle qui ne bouge pas,
ce qui est la raison pour laquelle déposer *entre* deux zones avait été refusé
sur l'axe horizontal.

Une tranche refuse ce qu'elle ne peut pas prendre — une pile, puisque la
profondeur s'arrête à un — et laisse alors l'événement remonter : la boîte
derrière le prend comme un échange, donc le geste fait quelque chose plutôt que
rien. Le surlignage dit lequel des deux : la boîte s'allume, la tranche non.
*(`52dc2b5f`)*

**Sortir une zone d'une pile** se fait en glissant sa tranche sur une boîte de
la ligne — le miroir exact du geste inverse. Sans ça une pile serait un piège :
une zone construite dedans ne pourrait en sortir qu'en étant supprimée, ce qui
efface ce que chaque langue en tient.

Son span est **remis à la moitié** plutôt que conservé. Dans la pile le nombre
était une part de hauteur ; sur la ligne le même nombre est une largeur. Le
garder réinterpréterait 36 en silence, de « trois quarts de la hauteur » à
« trois quarts de la ligne » — une valeur que personne n'a choisie, obtenue
parce qu'un champ veut dire deux choses.

### Livré le 2026-08-09 : le rapport d'image, sur la zone média

`ratio` sur la zone — `natural` (défaut), `16x9`, `4x3`, `1x1`, `3x4`. **Partagé
comme le span** : recadrer est du dessin, écrit une fois pour toutes les langues.

**Un fait qui reformule la question et qui n'était pas dans l'analyse
ci-dessous.** `.aurora-grid` ne pose aucun `align-items`, donc les items d'une
grille CSS sont en `stretch` : **deux zones d'une même ligne ont déjà la même
hauteur**. Ce qui ne remplit pas, c'est le contenu — l'image est en `h-auto`.
« Aligner une image sur le texte à côté » ne demandait donc jamais une hauteur,
seulement de dire à l'image quelle forme prendre.

**Le ratio sort en style inline, pas en classe Tailwind.** Le doc proposait une
classe littérale ; c'est un piège. `aspect-video` existe par hasard dans ce Twig,
mais `aspect-square` et `aspect-[3/4]` n'apparaissent dans aucune source que
Tailwind lit — choisies en PHP, elles n'émettraient rien et le recadrage ne se
ferait pas, en silence. Le projet avait déjà répondu à cette question pour les
spans, qui sortent en propriétés personnalisées pour la même raison.
`GridViewBuilder::ratioStyle()` rend donc `aspect-ratio: 16 / 9;` ou la chaîne
vide. Aucune ligne à ajouter dans `@source inline`, rien à se rappeler.

**Et c'est vraiment une ligne dans le Twig.** Avec `width: 100%` et
`height: auto`, un `aspect-ratio` fixe la hauteur seul — pas de conteneur, pas de
second élément. Le style s'ajoute à celui qui portait déjà `object-position`, et
c'est le point focal, déjà là, qui fait tomber le recadrage au bon endroit.

**Pas de migration** : `grid_layout` est une colonne JSON et le normaliseur
défaut à `natural`. Les zones enregistrées avant ce champ rendent exactement ce
qu'elles rendaient — un test l'affirme.

**La toile ne montre pas le ratio.** Ses boîtes sont à hauteur uniforme, ce qui
est ce qui rend les lignes lisibles en tant que lignes ; lui faire montrer les
hauteurs réelles la rapprocherait d'un aperçu. Le contrôle dit la valeur,
l'aperçu serveur montre le résultat.

**Les deux autres `aspect-video` restent en dur, volontairement.** La vignette de
carte parce que la rendre réglable changerait des pages déjà publiées ; la vidéo
parce que son iframe en `absolute inset-0` a besoin d'une boîte et que le ratio
appartient au fournisseur, pas à l'auteur.

---

**Le chemin intermédiaire, probablement le bon** : pas une hauteur libre, mais
un **rapport d'image par zone** — 16:9, 4:3, carré, ou naturel. Ça couvre
l'essentiel de ce à quoi sert « redimensionner en hauteur » (une image et une
vidéo côte à côte qui s'alignent, une rangée de cartes régulière) sans introduire
de modèle 2D. Une zone média a déjà `aspect-video` en dur dans les cartes ; le
rendre réglable est une ligne dans le normaliseur et une classe littérale de
plus dans le Twig — **littérale, pas assemblée** : Tailwind n'émet que ce qu'il
lit.

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

### Ce qu'on craignait de casser — et qui n'a pas bougé

Cette section annonçait trois ruptures. Aucune n'a eu lieu, parce que les
fractions passent par le même computed `width` que la jauge :

- **Les 17 tests de `usePostGrid.test.js`** sont intacts, y compris les cinq qui
  portent sur le pas. S'y ajoutent 6 tests sur `placeZones` et 2 sur les
  fractions.
- **Les clés `width`, `width_label`, `snap`, `snap_hint`, `snaps.*`** servent
  toutes encore. `width_label` a changé de rôle : de libellé principal, elle est
  devenue le repli qui affiche le compte exact quand aucune fraction ne
  correspond — et c'est là qu'elle est enfin à sa place, puisque c'est le moment
  où l'auteur compte réellement des colonnes.
- **`GridNormalizer::SNAPS` et le champ `snap`** sont inchangés, donc les deux
  publications de démo qui le portent en base n'ont rien eu à migrer.

Ajoutées : `canvas`, `canvas_empty`, `canvas_hint`, `resize_zone`, `precise`,
`advanced`, `fractions.*`.

**Trouvé en chemin** : `AppSelect`, `AppInput` et les autres champs partagés ne
déclaraient pas de prop `hint`. Les 26 `:hint` du projet tombaient en attribut
sur la div racine et ne s'affichaient nulle part — dont `snap_hint`,
`zone_post_hint` et `zone_video_hint` de ce panneau. Corrigé dans les composants
partagés en parallèle de ce lot.

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
