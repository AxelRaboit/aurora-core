# Changelog Aurora-core

Format : [SemVer](https://semver.org). Section **"Dans aurora-client"** = ce que les
projets clients doivent répercuter après avoir lancé `make aurora-update`.

---

## [0.9.155] - 2026-09-13

### Modifié

#### La corbeille est un endroit, plus un coin de chaque écran
Cinq corbeilles vivaient chacune dans son module : un onglet pour les
publications, un bouton dans la GED, une section du panneau latéral pour les
dossiers, une modale pour les notes. Chercher ce qu'on venait de supprimer
demandait de savoir d'où on l'avait supprimé.

**Général > Corbeille** fait maintenant tout : un onglet par type, ce qui
attend dedans, les jours restants avant la purge, et sur chaque ligne
restaurer ou supprimer définitivement. Vider une corbeille se fait de là
aussi. Les corbeilles des modules sont retirées : il n'y a plus qu'un endroit,
donc plus de question sur lequel regarder.

L'écran ne sait toujours pas restaurer quoi que ce soit : il poste aux adresses
que le module lui a données, c'est-à-dire aux endpoints que l'écran du module
appelait avant lui. Un dossier rend son contenu à la racine, une catégorie
reçoit un slug libre, une note dont le parent est encore supprimé revient à la
racine. Ces règles restent écrites une fois, là où elles sont.

Ce que la page montre dépend de qui regarde : une publication n'apparaît que
pour son auteur si le lecteur n'est ni développeur ni administrateur, les notes
sont celles du lecteur, et une corbeille dont il n'a pas le privilège n'a pas
d'onglet du tout.

### Ajouté

#### Vider la corbeille des dossiers et celle des notes
Les deux n'avaient que la suppression une par une. Elles ont désormais leur
`empty-trash` comme les trois autres, ce qui est ce que la nouvelle page
appelle. Vider celle des dossiers rend leur contenu à la racine, comme le fait
déjà une suppression définitive unitaire : ce sont les documents que personne
n'a demandé à perdre.

---

## [0.9.154] - 2026-09-13

### Corrigé

#### Supprimer un client lié à un contrat ne casse plus la page
La base refusait déjà : la clé étrangère est en `RESTRICT`. Mais elle refusait
par une erreur SQL au milieu de la requête, qui arrivait à l'écran en 500, sans
un mot sur ce qui venait de se passer. La règle est une règle comptable, elle se
dit maintenant en français, à l'endroit où le bouton a été cliqué : un client
nommé par un contrat ne se supprime pas, et le message compte les contrats
concernés.

Le texte d'avertissement de la fenêtre de confirmation promettait l'inverse
(« les contrats resteront en base mais ne pointeront plus vers sa fiche »). Il
est corrigé.

### Modifié

#### Une trame dont un contrat figé est issu ne se supprime plus
Le document, lui, y survivait : un contrat porte sa propre copie scellée du
texte et ne relit rien depuis la trame. Ce qui ne survivait pas, c'est la
réponse à « quelle version de nos conditions ont-ils signée » : la clé étrangère
est en `SET NULL`, et le lien disparaissait sans bruit.

La suppression est donc refusée tant qu'un contrat figé est issu de la trame,
et l'archivage reste là pour la mettre de côté. Une trame qu'aucun contrat
figé n'a utilisée se supprime toujours : un brouillon abandonné n'est la preuve
de rien.

#### Un refus de suppression ne passe plus inaperçu
Quand le serveur répondait « non, et voici pourquoi », l'écran ne montrait rien :
la fenêtre restait ouverte, le bouton redevenait normal, et le clic avait l'air
de ne pas avoir été pris en compte. Le refus est maintenant affiché tel qu'il a
été écrit, pour toutes les suppressions du back-office, pas seulement en
comptabilité.

---

## [0.9.153] - 2026-09-13

### Corrigé

#### La corbeille des dossiers et des catégories GED se vide vraiment
La page Corbeille annonce, pour chaque ligne, le nombre de jours qui reste
avant la purge. C'était vrai pour les documents, les publications et les notes,
et faux pour les dossiers et les catégories : rien ne les purgeait. Un décompte
qui ne se termine jamais est pire que pas de décompte du tout.

Les deux se branchent maintenant sur le même réglage `TrashAutoPurgeDays` et le
même passage de 3 h que les autres.

La purge d'un dossier n'est volontairement pas la suppression définitive en
boucle. Supprimer définitivement un dossier remonte son contenu à la racine,
parce que c'est le dossier qu'on a voulu perdre et pas les documents. Appliqué
à la purge, ce geste effacerait la date de suppression de documents que la
purge s'apprête justement à prendre, et on les verrait revenir dans la
bibliothèque trente jours après avoir été supprimés.

---

## [0.9.152] - 2026-09-13

### Ajouté

#### Une page qui dit où sont les choses supprimées
Cinq corbeilles existent maintenant, chacune dans son écran : documents,
dossiers, catégories, publications, notes. Rien ne disait combien elles
contenaient, ni depuis quand, et une corbeille qu'on oublie est une corbeille
qui se vide toute seule sans que personne l'ait voulu.

**Général > Corbeille** les liste sur une page, avec ce qui attend dans
chacune et le nombre de jours qui lui reste avant la purge. Ce qui n'est pas
vide passe en tête, et chaque ligne mène à l'écran qui possède ces éléments :
les documents et les catégories s'y ouvrent directement sur leur corbeille.

La page ne restaure ni ne supprime, volontairement. Restaurer un document, un
dossier et une catégorie n'obéit pas aux mêmes règles, et un bouton unique ici
devrait toutes les redire, dans un second endroit où se tromper.

Un module contribue sa corbeille par un `TrashSourceInterface` posé dans le
noyau, comme il contribue ses chiffres au tableau de bord : la page ne nomme
aucun module et n'en importe aucun. Un module éteint, ou une permission que le
lecteur n'a pas, ne laisse pas de ligne derrière lui.

---

## [0.9.151] - 2026-09-12

### Ajouté

#### Supprimer une note Markdown se défait
Supprimer une note emportait son contenu, ses sous-pages et les images qu'elle
citait, en un geste et sans retour possible. Le contenu étant chiffré au repos,
même une sauvegarde de la base ne le rendait pas lisible : ce qui était parti
était parti.

La note part maintenant à la corbeille, avec ses sous-pages. La restaurer remet
la branche en place, et seulement celle-là : chaque ligne retient quelle note
l'a emportée, donc restaurer une page ne ressuscite pas une sous-page supprimée
à la main la semaine précédente. Une note dont le parent est encore en
corbeille revient à la racine plutôt que sous un parent invisible.

La corbeille s'ouvre depuis la barre d'outils des notes, et ne liste que les
notes supprimées pour elles-mêmes : proposer de restaurer séparément une
sous-page tombée avec son parent poserait une page sous un parent absent. On y
restaure, ou on supprime définitivement, et c'est à ce moment-là seulement que
les images deviennent orphelines et sont nettoyées.

La purge automatique tourne chaque nuit à 3 h et lit le même réglage
`TrashAutoPurgeDays` que les autres corbeilles, trente jours par défaut : une
durée de rétention est une promesse, et trois promesses différentes n'en font
aucune.

---

## [0.9.150] - 2026-09-12

### Ajouté

#### Le gabarit « image pleine page » accepte enfin une image
C'était le seul des six gabarits incomplet : son emplacement existait dans le
modèle, mais l'éditeur n'offrait aucun moyen de le remplir. Il porte maintenant
le sélecteur partagé, qui puise dans la médiathèque ou dépose un fichier depuis
la machine.

L'adresse de l'image est **calculée, jamais stockée** : la slide ne garde que
l'identifiant du document. L'adresse change quand le fichier change, et une
copie gardée dans la slide serait une seconde vérité à tenir. Le gestionnaire
la jette d'ailleurs tout seul, puisqu'elle ne fait pas partie des emplacements
que le gabarit déclare.

Les images d'une présentation sont résolues **en une requête** plutôt qu'une par
slide, et la variante « large » est préférée à l'original. L'image est cadrée en
`contain` : une capture rognée pour remplir le cadre perd justement le coin
qu'on voulait montrer.

## [0.9.149] - 2026-09-12

### Ajouté

#### Supprimer une catégorie de la GED se défait aussi
Comme pour les dossiers, supprimer une catégorie ne détruisait aucun document :
elle leur retirait simplement leur classement, silencieusement et sans retour.

La suppression met maintenant la catégorie à la corbeille. Les documents
continuent de la porter, ce qui est précisément ce qui permet à la restauration
de rendre le classement. Un bouton Corbeille apparaît dans la barre de la page
Catégories dès qu'il y a quelque chose dedans, et une catégorie en corbeille
n'offre plus que deux gestes : la restaurer, ou la supprimer définitivement,
avec sa propre confirmation.

Un détail invisible mais nécessaire : le slug d'une catégorie est unique, et
une catégorie en corbeille ne doit pas garder un nom en otage. L'unicité est
donc devenue partielle, côté base : un nom est pris seulement par une catégorie
qui est réellement dans la liste. Une catégorie en attente garde son slug, tel
quel et lisible, et deux « factures » peuvent cohabiter dans la corbeille. À la
restauration, le slug n'est recalculé que si quelqu'un l'a pris entre-temps,
auquel cas la catégorie revient en « factures-2 » au lieu d'échouer.

---

## [0.9.148] - 2026-09-12

### Ajouté

#### Supprimer un dossier de la GED se choisit, et se défait
Supprimer un dossier ne détruisait aucun document, mais il détruisait le
rangement : les documents et les sous-dossiers remontaient à la racine, et
l'information « ce document était là » était perdue pour de bon.

La confirmation propose maintenant deux gestes. Par défaut, le dossier part à
la corbeille avec son contenu, et le restaurer remet la branche exactement
comme elle était, rangement compris. L'autre option garde l'ancien
comportement, le contenu remonte à la racine, à ceci près que le dossier
lui-même reste récupérable, vide.

Pour que la restauration sache quoi remonter, chaque ligne qui tombe avec un
dossier retient lequel l'a emportée. Restaurer un dossier ne ressuscite donc
pas un document supprimé à la main la semaine précédente : il reste dans la
corbeille, là où son propriétaire l'a mis.

Deux conséquences volontaires : un dossier restauré dont le parent est encore
en corbeille revient à la racine plutôt que sous un parent invisible, et
supprimer définitivement un dossier libère ses documents à la racine au lieu de
les détruire. Seul le vidage de la corbeille des documents les efface.

Les dossiers en corbeille sont listés en bas du panneau latéral de la GED, avec
restaurer et supprimer définitivement.

---

## [0.9.147] - 2026-09-12

### Ajouté

#### Le manuel couvre les présentations
Quatre pages dans la rubrique Studio : créer une présentation, composer les
slides, présenter et imprimer, partager par une adresse secrète. Douze captures,
prises sur l'instance de démonstration.

Les fixtures portaient déjà deux présentations pour ça, dont une sans client,
parce que c'est le cas interne ordinaire et non un cas dégradé.

### Corrigé

#### Le menu d'une ligne de présentation n'affichait rien et ne faisait rien
Les trois actions étaient décrites avec les mauvaises clés : `label` au lieu de
`title`, `onClick` au lieu de `onSelect`. La fenêtre montrait trois icônes sans
texte, et **aucun des trois boutons ne se déclenchait** : ni modifier, ni
dupliquer, ni supprimer.

Trouvé en photographiant l'écran pour le manuel, ce qui est le genre de défaut
qu'un test ne voit pas : le composant recevait un tableau valide, il n'y avait
simplement rien dedans qu'il sache lire.

## [0.9.146] - 2026-09-12

### Ajouté

#### Partager une présentation par une adresse secrète
Un lien qui ouvre un deck sans compte, comme les Notes en ont un. Intitulé
libre pour s'y retrouver des mois plus tard, expiration facultative, et la
liste dit si le destinataire l'a ouvert et quand.

**Les notes d'orateur ne partent jamais avec le lien**, et c'est le contrôleur
qui les retire de la charge plutôt que le gabarit qui s'abstient de les
afficher : un gabarit se modifie bien plus souvent qu'un sérialiseur.

**Le jeton est stocké en clair**, comme celui des Notes et contrairement à
celui d'un contrat. La différence est l'enjeu, pas un oubli : le jeton d'un
contrat sépare une fuite de base d'une signature au nom de quelqu'un d'autre,
tandis qu'un deck n'a rien à contrefaire. Une base de decks qui fuit a déjà
fait fuiter les decks.

**Révoquer appose une date, ça n'efface jamais la ligne.** « Qui pouvait ouvrir
ceci, et jusqu'à quand » est une question à laquelle on veut pouvoir répondre
après coup, et une ligne supprimée ne répond à rien. La liste montre donc les
liens révoqués, estompés.

Un lien inconnu, révoqué ou expiré donne la même réponse : le 404 d'une adresse
qui n'existe pas. Dire laquelle des trois c'est confirmerait que l'adresse
était bonne, ce qu'un jeton deviné ne doit surtout pas apprendre.

Le privilège est `studio.decks.share` et non `edit` : remettre un document à
quelqu'un hors de l'application n'est pas le même acte que l'écrire.

### Migration

Une table neuve, `core_deck_share_links`. Le lien vers le deck est
`ON DELETE CASCADE` : une adresse vers un deck supprimé ne peut que répondre
404, et la garder serait garder un secret pour rien.

## [0.9.145] - 2026-09-12

### Ajouté

#### Présenter une présentation, et l'imprimer
Le bouton « Présenter » ouvre le deck en plein écran, une slide à la fois.
Flèches, espace, et les touches Page précédente et Page suivante qu'envoie une
télécommande de présentation. Échap pour sortir. La slide est mise en boîte
aux lettres plutôt qu'étirée : un cadre en 16/9 sur un vidéoprojecteur en 16/10
a des bandes, et remplir l'écran rognerait un coin de ce que quelqu'un a écrit.

**Les notes d'orateur ne sont pas dans le lecteur**, et c'est la raison pour
laquelle elles sont une colonne et non un emplacement de gabarit : un seul
écran est l'écran du public, donc tout ce qui y est dessiné est public.

#### L'export, par l'impression du navigateur
« Imprimer » ouvre une page qui ne porte que les slides, une par page, en
paysage, et lance le dialogue d'impression. « Enregistrer en PDF » est dans le
même dialogue.

dompdf est pourtant déjà là, mais son propre commentaire dit à quoi il sert :
un document légal à mise en page fixe, sans JavaScript, et il ne sait ni flex
ni grid. Reconstruire les six gabarits une seconde fois dans ce sous-ensemble,
puis tenir les deux versions d'accord, achèterait un export serveur que
personne n'a demandé. Le navigateur qui a dessiné la slide imprime la slide
qu'il a dessinée.

Sa propre page plutôt qu'une feuille d'impression posée sur l'éditeur : masquer
un back-office par sélecteur demande de connaître son balisage, puis de le
connaître encore à chaque fois qu'il change.

### Corrigé

#### Les puces d'une slide n'avaient pas de puce
La réinitialisation de Tailwind retire les marqueurs de toutes les listes, et
une liste à puces sans puces se lit comme un paragraphe coupé.

## [0.9.144] - 2026-09-12

### Ajouté

#### Composer les slides d'une présentation
La page d'une présentation : les slides à gauche, celle qu'on écrit à droite,
et son aperçu au-dessus du formulaire.

**Pas de bouton Enregistrer, et c'est voulu.** Un deck s'écrit en sautant d'une
slide à l'autre, et chaque saut est le moment où le travail sur celle qu'on
quitte est fini. Enregistrer là ne demande rien à retenir ; un bouton serait
une chose de plus à ne pas oublier avant de fermer l'onglet. La fermeture de
l'onglet enregistre aussi.

Le gabarit d'une slide peut changer en cours de route : une slide écrite en
puces veut souvent devenir un intercalaire une fois que le deck a pris forme.
Le contenu est alors filtré contre le **nouveau** gabarit, donc les
emplacements qu'il n'a plus disparaissent.

L'aperçu et les vignettes sont le même composant, au même rapport 16/9 fixe.
C'est la raison d'être des gabarits : ce qu'on arrange ici est ce qui arrive au
mur, et un aperçu qui se réagence serait un aperçu qui ment.

L'ordre des slides se change par deux flèches plutôt qu'au glisser : un deck
est une liste courte, et deux boutons marchent au clavier, sur un écran tactile
et pour qui ne peut pas glisser. L'ordre part entier, donc le serveur n'a
jamais à reconstituer un geste depuis une suite d'échanges.

Les fixtures de démonstration portent deux présentations, dont une sans client,
parce que c'est le cas interne ordinaire et non un cas dégradé.

## [0.9.143] - 2026-09-12

### Ajouté

#### Les présentations, dans Studio
Un jeu de slides se construit, se classe et se duplique. C'est le sous-module
que le renommage rendait possible : **une présentation peut nommer le client
pour qui elle a été écrite**, et un module n'a pas le droit de porter une
relation vers l'entité d'un autre module. Soit les deux vivent ensemble, soit
le lien n'existe pas.

Le champ client est facultatif, et cette nullabilité est le vrai choix : une
trame de stratégie écrite pour soi n'a pas de client, et l'exiger aurait rendu
impossible le cas interne le plus courant. Supprimer un client ne supprime pas
la présentation qu'on lui a montrée.

**Six gabarits fixes plutôt qu'un canevas libre** : titre, titre et puces,
image pleine page, deux colonnes, citation, intercalaire. Un deck d'audit ou de
stratégie, c'est cela à quatre-vingt-dix pour cent, et le placement libre aurait
voulu dire construire un outil de design. Le contenu d'une slide est du JSON
filtré en liste blanche contre les emplacements que son gabarit déclare, comme
la grille de contenu d'Editorial.

Dupliquer copie les slides et **laisse le client derrière** : « repartir de
celle-ci » veut presque toujours dire la même forme pour quelqu'un d'autre, et
emporter le client est la façon dont un deck finit présenté à une société avec
le nom d'une autre dessus.

Le sous-module a son propre interrupteur, `modules_studio_decks`, indépendant
des clients et des contrats : on peut avoir les présentations sans rien vendre.

### Migration

Trois tables neuves, `core_decks`, `core_deck_slides` et
`core_deck_categories`. Rien d'existant n'est touché. Le lien vers un client
est `ON DELETE SET NULL`, celui d'une slide vers son deck `ON DELETE CASCADE` :
une slide n'a pas de vie hors du deck, une présentation en a une hors du client.

## [0.9.142] - 2026-09-12

### Modifié

#### Le manuel suit le renommage du module
La rubrique « Comptabilité » devient « Studio » : dossier, intitulés, et les
huit renvois en prose du type « Comptabilité → Clients ». Une page de la
rubrique Configuration citait aussi l'onglet de réglages, elle est corrigée.

Les 210 captures sont reprises, pas seulement les 52 de la rubrique. Le menu
latéral apparaît sur la plupart des écrans du manuel et il porte désormais le
nouveau nom : choisir image par image aurait laissé un jeu de millésimes
mélangés, ce qui est exactement la mécanique qui a déjà fait publier un écran
périmé sous un texte neuf.

Le diff est donc large et il dit la vérité : sur les 108 captures d'autres
rubriques qui changent, treize ne changent que dans le menu, quatorze d'un
poignée de pixels invisibles, et le reste montre ce que le produit a gagné
depuis la dernière campagne, notamment l'onglet de stockage et la corbeille de
la GED.

Les outils de capture pointaient encore sur `/backend/accounting/` : corrigé,
c'était un reste du renommage.

## [0.9.141] - 2026-09-12

### Modifié

#### Le module Comptabilité devient Studio
« Comptabilité » nommait un coin du module et excluait le reste. Il contient
les clients, les contrats, les trames et la signature, et il va contenir les
présentations : ce qu'on vend à un client et ce qu'on lui livre.

La question qui a payé le renommage est concrète : un jeu de slides est adressé
à un client, et un module n'a pas le droit de porter une relation vers l'entité
d'un autre module. Soit les deux vivent ensemble, soit la relation n'existe pas.

La règle qui décide de ce qui entre est écrite dans le docblock du module :
**Studio contient ce qu'on vend et ce qu'on livre. Pas les outils avec lesquels
on le fabrique.** Les notes, la GED et le calendrier restent chez eux.

Rien ne change à l'usage, hormis le mot dans le menu et dans l'onglet de
réglages. Les sous-interrupteurs restent indépendants : on peut avoir les
clients sans les contrats, comme avant.

### Corrigé

#### Un document déplacé vers le stockage distant devenait introuvable
Le résolveur regardait le disque local, puis **le disque actif**, et s'arrêtait
là. Quand les nouveaux fichiers vont sur le serveur et qu'un document a été
déplacé à la main vers le compartiment, le disque actif est justement le local :
le fichier n'était donc jamais cherché ailleurs, et toutes ses adresses
répondaient 404.

C'est pourtant la combinaison que le produit annonce comme normale, le
commentaire de `isRelocationAvailable` le dit mot pour mot. Constaté en
production le 12/09/2026 : quatre films et leurs quatre images d'attente,
déplacés volontairement, disparus d'une page publique. Les octets n'ont jamais
été en danger, rien n'allait les chercher.

Le résolveur balaie maintenant tous les backends configurés. Le disque local
reste interrogé en premier, par un appel système et non par une requête
facturée, donc un fichier présent sur le serveur ne coûte toujours rien.

Les adaptateurs répondent pour cela à une nouvelle question, `isReady()` : un
backend jamais configuré est sauté au lieu d'être appelé, parce qu'une
exception est la façon dont un backend *configuré* signale une vraie panne, et
les deux ne doivent pas se ressembler.

### Ajouté

#### Savoir où vit un document, depuis la liste et depuis sa fiche
Une colonne « Stockage » dans la vue liste de la médiathèque, avec la même
pastille que la vue cartes, et la même information dans la modale de détail.
Les deux disparaissent tant qu'un seul stockage existe.

#### Un lecteur pour les vidéos et les sons dans la modale de détail
Une vidéo tombait dans la branche générique et s'affichait comme une icône de
fichier : le seul type dont l'intérêt est d'être lu, et l'écran n'offrait pas
de le lire. Le son avait le même sort.

Contrairement à la page publique, la modale précharge les métadonnées :
quelqu'un qui ouvre un document a demandé ce document, et la durée fait partie
de ce qu'il vient vérifier.

### Migration

La base ne change pas de forme : aucune table ne portait le nom du module, ce
sont `core_contracts`, `core_customers` et `core_contract_templates`. Ce qui
porte le nom, ce sont des chaînes, et la migration les réécrit toutes :

- les 3 interrupteurs et les 17 paramètres de `core_settings`, dont l'identité
  du prestataire imprimée sur chaque contrat ;
- l'onglet de réglages qui les regroupe ;
- les privilèges, le masque de modules par utilisateur, et les sections et
  entrées de menu masquées, tous rangés en colonnes JSON ;
- le nom de section et les noms de route **à l'intérieur** de la valeur JSON
  des quatre réglages `nav_*`, dont les clés, elles, ne changent pas. C'est le
  seul endroit qu'un balayage sur les clés de réglages aurait manqué.

### Dans aurora-client
Rien à répercuter à la main pour le stockage. **Un document déplacé vers le
stockage distant avant cette version redevient accessible sans rien faire** :
ses octets étaient là, seule la recherche s'arrêtait trop tôt.

Pour le renommage, rien non plus dans le cas courant : les adresses publiques
des contrats sont préfixées `/contracts`, pas `/backend/accounting`, donc les
liens de signature déjà envoyés à des clients continuent de fonctionner. Un
projet client qui aurait surchargé une classe du module doit en revanche suivre
le namespace `Aurora\Module\Accounting` → `Aurora\Module\Studio` et les
privilèges `accounting.*` → `studio.*`.

---

## [0.9.140] - 2026-09-12

### Ajouté

#### Supprimer un document de la GED ne détruit plus son fichier
Le bouton Supprimer emportait la ligne, ses vignettes, ses versions et le
fichier lui-même, en un geste et sans retour possible. Pour un document
téléversé, ces octets n'existent souvent nulle part ailleurs.

La suppression met désormais le document à la corbeille : il disparaît de la
bibliothèque, de la recherche et des statistiques, mais son fichier reste sur
le disque. Un bouton Corbeille apparaît dans la barre de la page Documents,
avec le nombre d'éléments dedans, et la corbeille est la même liste avec la
condition inversée : les filtres par catégorie, type ou dossier continuent d'y
fonctionner.

Un document en corbeille n'offre plus que trois gestes : le voir, le restaurer,
le supprimer définitivement. Cette dernière action a sa propre confirmation,
parce que c'est la seule de l'écran qui efface vraiment les octets. Le bouton
Vider la corbeille annonce combien de documents il détruit.

Ce qui reste dans la corbeille est purgé automatiquement au bout du délai déjà
utilisé par les publications, réglable dans les paramètres et actuellement fixé
à 30 jours. La purge passe par le même code que le bouton, donc un fichier
encore référencé par un autre document est épargné dans les deux cas.

Le journal d'audit distingue maintenant la mise à la corbeille, la restauration
et la suppression définitive.

---

## [0.9.139] - 2026-09-12

### Corrigé

#### Chaque déploiement effaçait la configuration du stockage distant
`aurora:application-parameter` tourne à chaque `make deploy-prod` et supprime
toute ligne de réglage dont aucun fournisseur ne se porte garant. Les huit
lignes du stockage, adresse, compartiment, les deux clés et la date de
vérification, n'étaient déclarées nulle part.

Constaté en production le 12/09/2026 : un compartiment configuré et vérifié à
09h33 a été effacé par le déploiement de 10h29, sans un mot, et le site est
reparti sur le disque du serveur.

C'est la deuxième fois que ce piège se referme, après la clé d'API Pexels. Un
onglet qui écrit ses propres lignes doit déclarer un
`OwnedSettingProviderInterface`, et rien ne le rappelait.

Un test le rappelle maintenant : toute énumération `*SettingEnum` qui n'est pas
dessinée par l'écran générique doit être revendiquée par un fournisseur, sinon
la suite échoue en nommant les clés qui seront supprimées. Vérifié en retirant
le fournisseur : le test tombe et liste les huit.

### Dans aurora-client
Rien à faire dans le code. **Une configuration de stockage distant perdue lors
d'un déploiement antérieur est à ressaisir**, clés comprises, puis à vérifier.

---

## [0.9.138] - 2026-09-12

### Ajouté

#### Une aide dépliable à côté d'un libellé, réutilisable partout
Un texte sous un champ a une ligne pour lui, une page de documentation demande
d'aller ailleurs et de revenir. Entre les deux il manquait trois paragraphes
qu'on ouvre quand on en a besoin.

`AppHelp` est branché sur `AppFieldLabel`, le libellé que **onze contrôles
partagent déjà** : n'importe quel champ de l'application en obtient un en
ajoutant `help="mon.sujet"`, sans avoir à se demander quels composants le
supportent. Les onze qui n'en passent pas s'affichent exactement comme avant.

Quatre types, chacun avec son icône et sa couleur : champ, notion, sécurité,
facturation. Un lecteur qui a déjà vu le bouclier sait de quoi il s'agit avant
de lire.

Le contenu vit dans les fichiers de traduction, pas dans les composants : un
paragraphe écrit dans un composant n'existerait qu'en français. Deux sujets
pour commencer, sur les réglages du stockage.

#### Un bandeau d'état en haut des réglages du stockage
Il dit si le stockage distant est branché, sur quel compartiment, quand il a
été vérifié et où partent les nouveaux fichiers. Quatre champs remplis et une
date perdue à côté d'un bouton ne répondaient pas à la question qui amène sur
cet écran.

#### Un bouton pour débrancher le stockage distant
C'est le seul geste qui efface une clé : le formulaire n'envoie un champ de clé
que s'il contient quelque chose, donc un champ vide y veut dire « garde ce qui
est enregistré » et rien n'a jamais voulu dire « oublie-la ». Une configuration
posée une fois pouvait être pointée ailleurs, jamais retirée.

Refusé tant qu'un document vit encore sur le stockage distant, en disant
combien il en reste : les identifiants sont le seul chemin vers ces fichiers.

Refusé aussi quand la configuration vient de l'environnement du serveur, qui
prime sur la table des réglages. Sans cette vérification le bouton effaçait
bien les lignes, le formulaire se remplissait à nouveau depuis l'environnement,
et le geste passait pour sans effet. Le bandeau annonce désormais l'origine de
la configuration, et le bouton n'est pas proposé dans ce cas.

### Dans aurora-client
Rien à répercuter à la main.

---

## [0.9.137] - 2026-09-12

### Corrigé

#### Un refus des réglages du stockage vidait les deux champs de clé
Le refus renvoyait l'état courant, l'écran s'y rafraîchissait, et les deux
champs de clé étant en écriture seule, ils repartaient vides. Se tromper sur
l'un obligeait donc à recoller les deux, à chaque essai.

Un refus ne porte plus d'état quand rien n'a été écrit, et l'écran ne se
rafraîchit que lorsqu'il en reçoit un. Le refus sur la bascule non testée, lui,
en porte toujours un : à ce moment-là les identifiants ont bien été enregistrés.

#### Une vérification qui ne pouvait pas se déclencher
Le message signalant un compartiment collé au bout de l'adresse du compte
n'était atteignable par aucun appelant : tous normalisent l'adresse avant de
poser la question, donc le défaut est réparé avant d'être cherché. Le contrôle
et ses trois traductions sont retirés, et la raison est écrite à côté de ceux
qui restent.

---

## [0.9.136] - 2026-09-12

### Corrigé

#### Deux erreurs de saisie du stockage R2 ne se voyaient qu'au moment du test
Coller l'« adresse S3 » que Cloudflare affiche met le nom du compartiment à la
fin de l'adresse du compte, et l'application l'ajoute derrière : il se
retrouvait deux fois dans chaque requête. Le texte sous le champ le disait
depuis l'origine, ce qui n'a empêché personne de tomber dedans le 12/09/2026.
Un texte sous un champ n'attrape rien : l'adresse est maintenant corrigée à
l'enregistrement, et à la lecture, ce qui répare aussi les configurations déjà
en base.

Une clé d'une longueur impossible partait également jusqu'à Cloudflare, qui
répondait `InvalidArgument: Credential access key has length 24, should be 32`
au milieu d'une URL, sans nommer ni le champ ni l'écran. L'identifiant de clé
et la clé secrète sont vérifiés avant l'enregistrement, et le refus dit lequel
des deux est en cause. Le test de connexion fait la même vérification, pour
couvrir les valeurs venues de l'environnement du serveur, que rien ne validait.

#### L'écran des réglages annonçait un enregistrement qui n'avait pas eu lieu
Un refus du serveur est un objet comme un autre, et le composant ne regardait
que sa présence, pas son `success`. Le message de succès s'affichait donc même
quand rien n'avait été écrit, ce qui valait déjà pour le refus existant sur la
bascule non testée. L'écran lit maintenant la réponse, affiche le message du
serveur, et le bouton de test s'arrête au lieu d'interroger Cloudflare avec une
configuration qui vient d'être rejetée.

---

## [0.9.135] - 2026-09-12

### Corrigé

#### `make aurora-update` effaçait le balayage des caches orphelins du client
Le gabarit `.claude/client_template/Makefile` est la source de `sync-makefile`,
et les dix-sept lignes ajoutées le 11/09/2026 à la cible `cc-prod` n'y avaient
jamais été reportées. Chaque propagation écrasait donc le Makefile du client et
supprimait la protection, silencieusement, puisque le fichier est annoncé comme
généré.

C'est arrivé deux fois d'affilée, sur 0.9.133 puis sur 0.9.134. Sans ces lignes,
chaque `cache:clear` en production peut laisser derrière lui un dossier
`.!!xxx` que personne ne voit tant que `var/cache` n'a pas atteint plusieurs
centaines de méga-octets.

Le gabarit porte maintenant la cible complète, à l'octet près.

### Dans aurora-client
Rien à faire : la prochaine synchronisation remet les lignes en place.

---

## [0.9.134] - 2026-09-12

### Ajouté

#### Une vidéo de la médiathèque a maintenant une image d'attente
Un lecteur `preload="none"` sans poster est un rectangle noir à « 0:00 » : tant
que personne n'a cliqué, le navigateur n'a aucune image et ignore jusqu'aux
proportions du film, donc il dessine sa boîte par défaut, large et courte. Une
vidéo verticale y ressemblait à une bande écrasée.

Le thème savait afficher un poster depuis le début, et `GridViewBuilder` allait
le chercher dans `thumbnail_path`. Personne ne remplissait cette colonne pour
une vidéo : seul le PDF y avait droit.

**C'est le navigateur qui fabrique l'image**, au moment du dépôt, en décodant
le fichier qu'il s'apprête à envoyer. Rien à installer sur le serveur, et la
capture ne peut échouer que sur une vidéo qu'aucun visiteur n'aurait pu lire de
toute façon. Les dimensions du film voyagent avec elle.

`ffmpeg` reste branché pour les dépôts qui ne passent par aucun navigateur,
l'API, une fixture, un import en console, et pour le rattrapage par
`aurora:ged:thumbnails:generate`, désormais étendue aux vidéos. Il n'est jamais
requis : l'installer coûte près de deux cents paquets, pilotes GPU et moteur de
reconnaissance vocale compris, sur chaque serveur qui héberge une Aurora.

La balise `<video>` porte aussi `width` et `height`, pour que la boîte ait la
bonne forme même sans poster.

### Corrigé

#### Le formulaire de la médiathèque perdait la vignette qu'il venait de recevoir
`/upload` renvoyait `thumbnailPath`, mais le formulaire ne le recopiait nulle
part et ne le renvoyait donc pas au moment d'enregistrer. Un PDF déposé par
l'écran repartait sans sa vignette, alors qu'elle avait bien été produite et
écrite sur le disque. Le champ fait maintenant l'aller-retour comme `filePath`,
et le sérialiseur l'expose pour que l'édition n'efface pas ce qui existe.

### Dans aurora-client
Rien à répercuter à la main. Les vidéos déjà en base n'ont pas de poster :
`php bin/console aurora:ged:thumbnails:generate` en produit un si `ffmpeg` est
présent sur le serveur, sinon il suffit de redéposer le fichier depuis la
médiathèque.

---

## [0.9.133] - 2026-09-12

### Modifié

#### La documentation technique de la couche de stockage
`docs/aurora-core/dev/storage_backends.md` : le contrat, les trois verbes de
`LocalWorkspace` et pourquoi les confondre fait disparaître un dérivé, comment
ajouter un support, ce qui se facture sur un stockage objet, et les deux pièges
qui ne se voient qu'en production.

CLAUDE.md §5bis décrivait le modèle d'avant et citait un dossier appartenant à
un module supprimé. Il pointe désormais vers la doc et tient les règles dures.

Le paramètre `app.upload_dir` renvoyait vers `docs/aurora-core/dev/storage_policy.md`,
qui n'a jamais existé.

#### La mémoire distribuée aux clients disait le contraire du code
`convention_storage_var_uploads` demandait d'injecter
`%app.upload_dir%/<categorie>`, ce qui est exactement ce que la couche a
retiré. Elle part chez les projets clients, donc elle comptait. Elle décrit
maintenant `StorageManager` et `LocalWorkspace`, et liste comme anti-patterns
les gestes qu'elle recommandait.

### Dans aurora-client

Rien à répercuter, mais la consigne change pour tout nouveau stockage : injecter
`StorageManager` plutôt que `%app.upload_dir%`. La mémoire partagée, lue depuis
`vendor/axelraboit/aurora/`, le dit désormais.

## [0.9.132] - 2026-09-12

### Modifié

#### Les derniers fichiers hors médiathèque passent par la couche de stockage
Les photos de profil et les PDF des contrats signés étaient les deux dernières
choses écrites directement sur le disque. Elles suivent maintenant le même
chemin que le reste, donc le réglage de stockage vaut pour tout ce que
l'application écrit, et non plus pour la médiathèque seule.

Un contrat reste servi par l'application, derrière l'authentification, quel que
soit le mode de livraison choisi. Une pièce juridique ne s'expose pas par un
lien public, même sur une installation qui sert ses images ainsi.

Deux détails qui auraient cassé des fichiers existants et qui ne l'ont pas
fait : la base ne stocke que le nom d'une photo de profil, jamais son dossier,
et ce partage reste tel quel ; le dossier des contrats garde son nom. Renommer
l'un ou l'autre aurait rendu introuvable tout ce qui a été écrit avant.

La suppression d'une photo interroge les deux supports plutôt que le support
actif. Une photo déposée avant une bascule vit encore de l'autre côté, et
demander sa suppression au mauvais endroit ne fait rien, en silence.

#### `StorageAreaEnum` décrit enfin ce qui existe
Quatre cas sur cinq ne servaient plus : `media`, `ocr` et `photo` appartenaient
à des modules retirés, et `users` n'a jamais correspondu à la réalité puisque
les photos de profil vont dans `profile-photos`. Seul leur propre test citait
encore leurs valeurs, ce qui est la forme exacte d'une constante que personne
n'utilise.

Restent trois cas, qui sont trois dossiers réels. Le test qui les garde dit
maintenant pourquoi : ce sont des préfixes de chemin, et en renommer un rend
introuvable tout ce qui a été écrit avant.

### Dans aurora-client

Aucune migration, aucun fichier déplacé, rien à répercuter sauf pour un projet
qui étend une de ces classes :

- `ContractPdfGenerator` : `absolutePathFor()` et `root()` disparaissent, au
  profit de `keyFor()`, `exists()`, `readStream()` et `withLocalCopy()`. Le
  service ne reçoit plus de dossier d'upload.
- `UserProfilePhotoManager` : reçoit un `StorageManager` à la place du
  `Filesystem` et du dossier d'upload.
- `StorageAreaEnum` : quatre cas retirés. Un projet qui en nommait un ne
  compilera plus, ce qui est préférable au silence.

## [0.9.131] - 2026-09-12

### Ajouté

#### Le manuel décrit le stockage des fichiers
Deux pages : celle des réglages, dans Configuration, et celle du déplacement
d'un document, dans Médiathèque. Elles disent aussi quand **ne pas** s'en
servir, ce qui manque à la plupart des manuels : en dessous de quelques
giga-octets, le disque du serveur fait l'affaire et demande moins de réglages.

### Corrigé

#### La page des groupes de réglages annonçait treize onglets
Il y en a quatorze depuis la 0.9.129. Rien n'échoue quand une page compte mal,
et personne ne l'aurait vu.

### Interne

#### Une règle et un skill pour que la documentation suive
Demandé le 12/09/2026. Toute évolution visible met à jour le manuel, **et les
pages voisines qu'elle a rendues fausses**, et les captures des écrans
touchés. Ce deuxième point est celui qu'on saute, et c'est exactement ce qui
s'était produit avec la page des onglets.

La capture du nouvel onglet vide ses deux premiers champs avant la prise de
vue. L'écran affiche la configuration réellement en vigueur, identifiants de
compte compris : sur la machine d'un développeur ayant branché un vrai
compartiment, la capture aurait emporté son identifiant Cloudflare vers un
dépôt public et une page ouverte à tous.

## [0.9.130] - 2026-09-12

### Ajouté

#### Déplacer un document d'un stockage à l'autre, d'un clic
Une action dans le menu de chaque document : l'envoyer vers le stockage
distant, ou le rapatrier sur le serveur. Une pastille sur chaque ligne dit où
il vit, et l'action disparaît complètement tant qu'aucun second stockage n'a
été configuré et testé, parce qu'une action qui ne peut qu'échouer est pire
qu'une action absente.

**Ce qui se déplace, c'est le document**, pas le fichier : ses octets, sa
vignette, ses trois variantes, et le fichier de chacune de ses versions. Un
document dont la moitié serait restée de l'autre côté aurait une colonne qui
ment.

**L'ordre est copier, vérifier, enregistrer, supprimer**, et jamais un autre.
Un processus interrompu à n'importe quel moment laisse le document intact et
lisible là où il était. Ce qu'il laisse de l'autre côté est au pire une copie
que personne ne référence, et que la tentative suivante écrase, puisque les
clés sont identiques des deux côtés. Cette identité est aussi ce qui rend une
relance sans danger.

**La suppression à la source demande la permission.** Deux documents peuvent
pointer sur le même chemin, et une version partage volontairement le fichier du
document courant : la copie source n'est retirée que si plus aucune ligne
restée de ce côté ne la nomme. La question se pose par support, sans quoi elle
répondrait « encore utilisée » à propos des lignes qui viennent justement de
partir.

**Un déplacement en cours refuse le suivant.** L'état affiché sert de verrou :
il est pris par une écriture conditionnelle, donc un second clic trouve la
porte fermée au lieu de recopier les mêmes octets et de courir avec le premier
pour savoir quelle suppression gagne. Utiliser l'état visible plutôt qu'un
verrou caché est délibéré : deux mécanismes qui disent la même chose finissent
par se contredire, et c'est l'invisible qui reste faux.

**Petit tout de suite, gros en arrière-plan.** En dessous de 8 Mo cumulés, le
déplacement se fait dans la requête et la ligne se met à jour sous les yeux.
Au dessus, un message part sur le worker : un navigateur n'a pas à être tenu
ouvert sur un compartiment distant.

Un échec est lisible : l'état passe en « déplacement échoué », le motif est
conservé et affiché au survol, et l'action reste relançable. Un déplacement qui
échoue en silence est un bouton qui a l'air cassé.

Le geste existe aux trois endroits où on peut vouloir le faire : le menu d'une
ligne, le bouton sur la page d'un document, et la barre de sélection pour en
déplacer plusieurs. Le lot rend des comptes plutôt qu'un succès à plat, parce
qu'une sélection est légitimement un mélange : certains déjà à destination, un
autre pris par un déplacement en cours, un troisième refusé. Dire « c'est fait »
là-dessus serait un mensonge, dire « ça a échoué » en serait un autre.

Un filtre par emplacement complète la liste, utile dès qu'une bibliothèque vit
des deux côtés.

### Dans aurora-client

**Une migration**, jouée par `make aurora-update` : deux colonnes sur
`core_ged_documents`, l'état du transfert et le motif du dernier échec.

**Un nouveau privilège**, `ged.documents.relocate`, à accorder aux personnes
concernées. Volontairement distinct de `ged.documents.edit` : déplacer des
octets d'un support à l'autre dépense du transfert et des requêtes sur le
compte de quelqu'un, ce qui n'est pas la même permission que corriger une
faute dans un titre.

Rien d'autre à répercuter, et rien ne change tant qu'aucun second stockage
n'est configuré.

## [0.9.129] - 2026-09-12

### Ajouté

#### Un écran pour choisir où sont écrits les fichiers
Le stockage objet posé en 0.9.128 existait sans que personne puisse l'allumer.
Il y a désormais un onglet **Stockage des fichiers** dans les réglages : les
identifiants du compte, le compartiment, un bouton pour tester, et le choix du
support.

Les deux clés sont chiffrées en base, comme la clé Pexels et les mots de passe
des points de montage. Elles ne repartent jamais vers le navigateur : l'écran
sait qu'une valeur est enregistrée, pas laquelle.

L'environnement du serveur reste prioritaire, champ par champ. Un opérateur qui
préfère garder ses secrets hors d'une base sauvegardée chaque nuit peut le
faire, sans perdre le compartiment qu'un administrateur aurait saisi dans
l'écran.

**Basculer demande d'avoir testé.** Le test écrit un fichier témoin, le relit,
vérifie qu'il apparaît dans la liste, puis le supprime. Lire quatre chaînes non
vides ne prouve rien, et la panne que tout le monde rencontre est un jeton en
lecture seule, qui a l'air parfaitement configuré jusqu'à la première écriture.
Changer d'adresse, de compartiment ou de clé annule la vérification
précédente : elle portait sur une autre configuration.

#### La GED sait où vivent ses fichiers
Chaque document et chaque version portent maintenant le support qui détient
leurs octets. C'est ce qui permet à un document écrit avant une bascule de
rester lisible après, et à deux documents de vivre de chaque côté sans que rien
ne s'en aperçoive.

Le réglage dit où va le **prochain** fichier ; un fichier déjà écrit dit
lui-même où il est. Suppression, recadrage, variantes, purge des versions : tout
passe désormais par le support du fichier concerné, pas par le support actif.

#### Trois façons de servir un fichier distant
Par l'application, par lien signé temporaire, ou par un domaine public branché
sur le compartiment.

**L'adresse d'un document ne change jamais**, quel que soit ce choix, ni quand
le fichier change de support. Ce n'est pas un détail de confort : l'éditeur
inscrit l'adresse d'une image dans le corps de la publication, donc une adresse
qui suivrait son fichier casserait toutes les pages qui l'ont intégrée. Seule
change la réponse de `/uploads/{chemin}`.

Servir par l'application se fait par morceaux et non d'un bloc : ce mode existe
pour les installations qui veulent garder leurs règles d'accès, et ce sont les
mêmes qui servent des fichiers trop gros pour tenir en mémoire.

### Corrigé

#### Deux failles trouvées en branchant l'écran sur un vrai compartiment
La complétude de la configuration était jugée sur les seuls réglages en base.
Un opérateur ayant mis ses identifiants dans l'environnement, ce que la
documentation propose, n'aurait jamais pu basculer : l'écran l'aurait refusé au
motif qu'il manquait des informations qu'il avait pourtant fournies.

Et l'obligation d'avoir testé n'existait qu'au niveau du contrôleur. Une
fixture, une commande ou une extension cliente pouvait pointer l'application
vers un compartiment que rien n'avait jamais joint. Le garde-fou est descendu
là où la réponse est lue, donc il vaut pour tout le monde.

### Dans aurora-client

**Une migration**, jouée par `make aurora-update` : une colonne `storage_disk`
sur `core_ged_documents` et `core_ged_document_versions`, défaut `local`. Rien
ne bouge : elle enregistre un fait, elle ne le change pas.

Rien d'autre à répercuter, et rien ne change tant que personne n'ouvre le nouvel
onglet. Le stockage par défaut reste le disque du serveur.

À répercuter seulement si le projet étend une de ces classes :

- `DocumentInterface` et `DocumentVersionInterface` gagnent `getStorageDisk()`
  et `setStorageDisk()`. Une entité cliente qui étend `AbstractDocument` les a
  déjà ; une implémentation partant de l'interface doit les ajouter.
- `DocumentManager::deleteUnreferencedFiles()` prend un second argument, le
  support concerné.
- `StorageManager` prend un `ActiveStorageDiskProviderInterface` en second
  argument de constructeur.

## [0.9.128] - 2026-09-12

### Ajouté

#### Un second support de stockage : Cloudflare R2
La couche posée en 0.9.127 n'avait qu'une implémentation, le disque. Elle en a
une seconde, qui parle à R2 par son API compatible S3. Rien ne s'en sert
encore : la GED écrit toujours sur le disque, et R2 ne s'allumera qu'avec
l'écran de configuration, à venir. Ce qui est là est l'adaptateur, éprouvé.

Le SDK retenu est `async-aws/simple-s3`, 2,2 Mo installés là où le SDK AWS en
pèse plus de dix. Aurora est un bundle que chaque client installe : le poids se
paie chez eux.

#### La commande `aurora:storage:doctor`
Elle ne vérifie pas la configuration, elle l'utilise : elle écrit un objet
témoin, le relit, compare les octets, confirme que le listing le voit, puis le
supprime, et rapporte chaque étape séparément. Lire quatre chaînes non vides
dans l'environnement ne prouve rien, et la panne que tout le monde rencontre
est un jeton en lecture seule ou limité au mauvais bucket, ce qui a l'air
parfaitement configuré jusqu'à la première écriture.

Les échecs courants reçoivent la chose à aller changer plutôt que le message de
l'API, qui ne nomme aucune des causes. Le témoin est supprimé dans un `finally`,
donc un support qui sait écrire mais pas supprimer le dit aussi.

### Corrigé

#### La taille d'un fichier était fausse pour les types que Cloudflare compresse
Trouvé en branchant l'adaptateur sur un vrai bucket. Cloudflare gzippe à la
volée les types compressibles, et une réponse gzippée n'a pas de
`Content-Length` et porte un ETag faible. Demander ses métadonnées à un objet
par une requête `HEAD` renvoyait donc zéro octet pour un `text/plain`, et la
bonne taille pour un PNG.

Une taille fausse ne se voit pas tout de suite : elle se serait vue plus tard,
à la migration, quand la vérification aurait comparé la taille locale à une
taille distante nulle. Les métadonnées viennent maintenant d'un listing réduit
à la clé cherchée, qui rapporte ce que l'objet pèse dans le bucket et non ce
que la réponse pèse sur le fil.

### Dans aurora-client

Rien à répercuter. Aucune migration, aucune signature changée, et le stockage
par défaut reste le disque.

Pour essayer R2 sur une installation : `R2_ENDPOINT`, `R2_BUCKET`,
`R2_ACCESS_KEY_ID` et `R2_SECRET_ACCESS_KEY` dans `.env.local` ou dans
l'environnement du serveur, jamais dans un fichier versionné, puis
`php bin/console aurora:storage:doctor --disk=r2`. L'endpoint est l'URL du
compte **sans** le bucket à la fin : la console en affiche une avec le bucket
ajouté, et la coller telle quelle met le bucket deux fois dans chaque requête.

## [0.9.127] - 2026-09-11

### Modifié

#### Les fichiers ne passent plus par le dossier d'upload, mais par une couche de stockage
Huit classes lisaient `app.upload_dir` et joignaient un chemin à la main. Changer
de support voulait dire les toucher toutes, et l'outillage image et PDF en
dessous prend des noms de fichiers, pas des flux.

`StorageAdapterInterface` est maintenant le seul chemin vers les octets. Une clé
y est le chemin relatif que la base stocke déjà, inchangé : brancher un autre
support plus tard ne réécrira aucune ligne. `LocalStorageAdapter` fait ce
qu'Aurora a toujours fait, au même endroit et aux mêmes fichiers, et c'est lui
qui tourne dans les tests, sans identifiants à fournir.

`LocalWorkspace` prête un vrai chemin à GD, `pdftoppm` et Ghostscript. Trois
verbes, parce que « j'ai besoin d'un chemin » cache trois intentions et que les
confondre est la façon dont une vignette cesse silencieusement d'être
enregistrée : regarder sans modifier, modifier sur place, ou créer un nouvel
objet. Sur le disque, les trois prêtent le fichier stocké lui-même : aucune
copie, aucun temporaire, exactement les performances d'avant.

`aurora:ged:prune-orphans` parcourait le dossier puis interrogeait chaque
fichier pour sa taille et sa date. Il fait désormais un seul listing, qui porte
ces deux informations, et supprime en un appel.

Rien ne change pour qui utilise l'application : mêmes fichiers, mêmes adresses,
même comportement.

#### `UploadPathResolver` est supprimé
Reliquat de la fusion Media vers GED. Plus aucun appelant, ni dans le core ni
dans aurora-client, et son `is_file()` tournait à chaque appel pour une garantie
que personne ne demandait.

### Dans aurora-client

Aucune migration, aucune donnée touchée. À répercuter seulement si le projet
étend une de ces classes :

- `DocumentManager` prend un `StorageManager` en dernier argument. Un manager
  client qui redéclare le constructeur doit le passer.
- `ImageVariantGenerator::generate()` et `deleteVariants()`, ainsi que
  `PdfThumbnailGenerator::generate()`, prennent un `StorageAdapterInterface` en
  premier argument. Le bon, dans la plupart des cas, est
  `$storageManager->active()`.
- `GedDocumentUploader` ne reçoit plus `Filesystem` ni le dossier d'upload, mais
  un `StorageManager` et un `LocalWorkspace`.
- `UploadPathResolver` n'existe plus. Un appelant qui a besoin d'un chemin local
  passe par `LocalWorkspace`, qui le prête pour la durée du travail.

Un projet qui n'étend aucune de ces classes n'a rien à faire.

## [0.9.126] - 2026-09-11

### Corrigé

#### Le premier clic sur une rubrique de la documentation ne faisait rien
Déplier une rubrique du manuel demandait deux clics, la première fois seulement.

Le magasin qui retient les plis a pour défaut « déplié », alors que le panneau
affiche « replié » sauf pour la rubrique en cours de lecture. Inverser ce que le
magasin croit n'était donc pas inverser ce qui était à l'écran : le premier clic
écrivait l'état déjà affiché, et le second seulement ouvrait la rubrique. Une
fois la valeur écrite, tout se comportait normalement, ce qui rendait la chose
difficile à voir deux fois.

`usePersistedExpanded` reçoit un `set(id, valeur)`, et le panneau écrit l'état
voulu au lieu d'inverser celui qu'on lui prête.

## [0.9.125] - 2026-09-11

### Modifié

#### Les rubriques de la documentation passent dans le menu latéral
Le manuel portait sa propre colonne de gauche : la recherche, les huit rubriques
et leurs quatre-vingt-douze pages, sur toutes les pages, à l'intérieur du
contenu. Elle coûtait un quart de la largeur au texte, et sur un téléphone elle
s'empilait au-dessus de lui, si bien que la première chose qu'on y rencontrait
était quatre-vingt-douze liens vers ailleurs.

Cette colonne est maintenant un panneau du menu latéral, le même mécanisme que
l'arborescence de la GED et la liste des notes. Le texte récupère la largeur, et
les captures avec lui : la colonne de lecture passe de 48 à 56 rems, ce qui se
voit sur des images de 1600 pixels.

Une rubrique se plie et se déplie, et seule celle qu'on lit s'ouvre d'elle-même.
Un pli qu'on a ouvert ou fermé soi-même est retenu et l'emporte sur ce défaut.

### Corrigé

#### Les panneaux de module étaient absents sur mobile
Le tiroir du menu, sur téléphone, ne montait aucun panneau de module : il
n'affichait que les liens. Un module qui avait sorti une colonne de sa page pour
la mettre dans le menu l'avait donc sortie de portée d'un téléphone. Les
dossiers de la GED, l'arborescence des notes et les agendas du calendrier
n'existaient que sur grand écran.

Le tiroir monte maintenant le panneau, à son ouverture plutôt qu'au chargement
de la page : un panneau va chercher ses propres données, et personne ne devrait
payer pour un panneau qu'il n'ouvre pas.

## [0.9.124] - 2026-09-11

### Corrigé

#### Le même saut d'ancre, sur le site public
La correction de la 0.9.123 ne valait que pour la documentation. La zone
« Sommaire » d'une publication pointe elle aussi sur les titres de la page, et
le site public a son propre en-tête collé : le titre visé atterrissait dessous,
exactement comme dans l'administration.

La règle passe donc sur `.prose`, que les deux côtés partagent, et chacun
déclare la hauteur de sa barre dans `--aurora-topbar` : six rems et demi pour
l'administration, six pour le site public. Une page dont l'en-tête est plus
court gagne un peu d'air plutôt qu'un titre caché, ce qui est le bon sens de
l'erreur.

Vérifié des deux côtés : rien n'est plus masqué.

---

## [0.9.123] - 2026-09-11

### Corrigé

#### Un lien du sommaire atterrissait sous le titre visé
Cliquer une entrée du sommaire amenait le titre tout en haut de la fenêtre,
c'est-à-dire derrière la barre collée : on arrivait au deuxième paragraphe,
sans avoir vu le titre qu'on venait de demander.

`scroll-margin-top` déplace la ligne d'arrivée du saut, pas l'élément : la mise
en page ne bouge pas. Même origine que le sommaire qui passait sous la barre
dans la 0.9.122, et même mesure de référence, `--aurora-topbar`.

---

## [0.9.122] - 2026-09-11

### Corrigé

#### Le sommaire d'une page de documentation passait sous la barre du haut
La barre de titre et le fil d'Ariane sont collés en haut et mesurent cent
quatre pixels. Le sommaire, lui, se collait à seize pixels : ses quatre-vingt-
huit premiers pixels étaient donc **derrière** la barre. Sur une page à quatre
sections, il n'en restait que la dernière ligne à l'écran - ce qui donnait un
sommaire qui suit le défilement en ne montrant rien d'utile.

La hauteur de cette barre est désormais nommée une fois, `--aurora-topbar`, et
les deux colonnes collées de la documentation s'en servent pour leur décalage
comme pour leur hauteur maximale. La correction précédente bornait leur
hauteur sans corriger leur point d'ancrage : elle était nécessaire, elle
n'était pas suffisante.

Le seul autre élément collé du back-office vit dans une fenêtre modale, qui a
son propre défilement et ne passe sous rien.

---

## [0.9.121] - 2026-09-11

### Corrigé

#### La pagination de la médiathèque ne changeait pas de page
`AppPagination` émet `change`, et quatre écrans écoutaient `go-to-page` : le
clic partait dans le vide. Les documents, les catégories, et les deux
paginations du sélecteur d'images étaient concernés - c'est-à-dire toutes
celles de la GED, et seulement celles-là. Le reste de l'application écoutait
déjà le bon nom.

Signalé sur la liste des documents, où la deuxième page était inatteignable
alors que le serveur la rendait correctement.

#### Le tableau de bord titrait des barres vides
La carte « Commentaires par statut » s'affichait sur un site sans un seul
commentaire. Sa garde comptait les segments, et une barre de répartition en
reçoit un par catégorie connue, remplie ou non : trois statuts donnent trois
segments même quand tout est à zéro.

Les **quatre** barres du tableau de bord posaient la même garde fautive -
publications par statut, commentaires, comptes par rôle, documents par type.
La règle est nommée une fois, dans `hasAnyShare`, plutôt que recopiée quatre
fois. Le graphique « Publications par mois » la posait déjà correctement,
c'était le seul.

#### Le sommaire d'une page de documentation pouvait déborder
Il n'avait ni hauteur maximale ni défilement propre, là où la liste des
rubriques à gauche a les deux. Sur une page à beaucoup de sections dans une
fenêtre courte, la fin du sommaire n'était atteignable par aucun geste.

---

## [0.9.120] - 2026-09-11

### Corrigé

#### Ouvrir une note par son adresse en affichait une autre
L'écran des notes demande deux notes coup sur coup au chargement - celle que
le gabarit annonce, puis celle de l'adresse - et `selectNote` posait la
réponse sans vérifier qu'elle concernait encore la note choisie. Quand elles
revenaient dans le désordre, la première écrasait la seconde : le lien d'une
note en ouvrait une autre, au hasard du réseau.

#### Une frappe pouvait écrire une note par-dessus une autre
Le même `selectNote` posait le nouvel identifiant **avant** d'avoir la
réponse, et sortait sur échec sans toucher au formulaire. L'éditeur affichait
donc le texte de la note précédente en face du nouvel identifiant. Une seule
frappe suffisait ensuite à déclencher la sauvegarde automatique, qui écrivait
l'ancienne note par-dessus la nouvelle.

Rien à l'écran ne le signalait : il y avait du texte, il avait l'air d'être le
bon. Le formulaire est désormais marqué non chargé tant que la note demandée
n'est pas arrivée, et la sauvegarde refuse d'écrire un formulaire qui
n'appartient pas à la note courante.

Les deux ont été trouvés parce que le script de capture refuse de
photographier une note dont le titre n'est pas celui attendu.

### Documentation

#### Les cinq écrans de Général, repris
Ils dataient d'avant le module de documentation, et la capture de la
recherche globale montrait des publications du type `documentation` qui
n'existe plus. La palette montre maintenant son ouverture, ses résultats
groupés par nature, et le déplacement au clavier.

La page du menu latéral annonçait le repli et l'affichage des descriptions
comme réglables sur cet écran : ils sont sur le menu lui-même. Et l'écran
s'appelle « Préférences », ce que la page ne disait pas.

#### Trois doublons dans les Notes
Cinq légendes promettaient cinq choses différentes, adossées à trois copies
de la même capture. Un cadrage raté retombait en silence sur une capture
pleine page, ce qui produit une image plausible et fausse. Le repli est
retiré : un cadrage qui échoue doit le dire.

### Interne

#### La démo n'invente plus de module de facturation
Une « Échéance facture F-2043 » se présentait comme venant d'un module
`billing` qui n'existe pas. Le seul module qui dépose vraiment une date est
l'éditorial, quand une publication est programmée. Le cas lecture seule reste
couvert par `PlanningModuleSyncTest`.

---

## [0.9.119] - 2026-09-11

### Documentation

#### Les six dernières pages d'Éditorial
Elles n'avaient qu'une vue d'ensemble chacune. Les champs personnalisés
montrent maintenant la liste des champs d'un type avec leurs badges, puis la
fenêtre qui en ajoute un. Les entrées de menu montrent le menu de la
navigation publique et la fenêtre d'une entrée, où se lisent d'un coup sa
cible, sa rubrique, son parent et sa visibilité. L'anti-robots montre son
onglet de réglages.

#### Quatre fonds nommés comme dans le code
La page des fonds de zone listait les noms de code des quatre valeurs.
L'écran affiche « Aucun », « Teinté », « Carte », « Accent », et le réglage
s'appelle « Fond ». Une page qui nomme les choses autrement que l'écran est
une page qu'on lit deux fois.

Sa capture promettait « ses réglages de surface » et montrait la grille : la
légende dit maintenant ce qu'on y voit.

#### Le captcha reçoit l'adresse IP du visiteur
L'écran le dit en jaune, la page ne le disait pas. C'est une donnée
personnelle, le compte doit être celui du site, et la politique de
confidentialité doit le mentionner. La page précise aussi que cette
vérification s'ajoute au piège à robots, au filtre à liens, à la limite
d'envois et à la modération : elle est la dernière ligne, pas la première.

#### « Suit la langue » s'appelle « Par langue »
Le badge de l'écran et le titre de la section disaient deux choses
différentes.

---

## [0.9.118] - 2026-09-11

### Documentation

#### Les réglages, refaits sur l'écran d'aujourd'hui
Les sept captures d'onglets dataient d'avant plusieurs changements d'écran.
Une capture de réglages périmée est pire qu'une capture absente : elle montre
un champ que le lecteur ne trouvera pas. Elles sont reprises, et la page qui
les résume ne promet plus que les onglets qu'un administrateur voit.

« Général et langues » parlait de deux onglets avec une seule image, celle du
second. Chacun a la sienne.

#### L'éditeur d'un thème
La page des thèmes montrait la liste et s'arrêtait là, alors que tout ce
qu'elle décrit - la couleur d'accent dont la palette est dérivée, le contraste
calculé pour chaque surface - vit dans l'éditeur.

#### Une page retirée
« Activer et désactiver les modules » décrit `dev_modules`, derrière
`ROLE_DEV` comme le reste de cette section.

#### « Palette du picker de couleurs »
Un mot anglais au milieu d'une interface française, dans l'onglet Apparence.
Vu sur la capture. C'est « Palette du sélecteur de couleurs » désormais, le
mot que la documentation employait déjà.

#### Le menu latéral
La page disait ce qui se règle sans dire comment : la poignée qui déplace une
ligne, le nom d'origine en filigrane quand le champ est vide, le compte
d'entrées par section, et le bouton qui rend au menu son état d'origine.

---

## [0.9.117] - 2026-09-11

### Documentation

#### La Plateforme, étape par étape
La liste des comptes, les actions d'une ligne, la fiche en lecture, la
fenêtre des privilèges rangée par module, le panneau d'invitation, et la page
de mot de passe oublié telle que la voit quelqu'un qui n'est pas connecté.
Sept captures, là où quatre pages partageaient la même vue d'ensemble.

Deux corrections en écrivant : l'invitation ne règle pas les privilèges, ils
se posent après ; et elle sait préparer un compte désactivé, dont
l'invitation partira à la réactivation.

#### Cinq pages retirées, toutes derrière le rôle développeur
La rubrique Administration décrivait le journal d'audit, les permissions
enregistrées, les points de montage et les comptes côté administration. Ces
quatre écrans vivent dans `DevModule`, et leur entrée de menu porte
`ROLE_DEV` : un administrateur chez un client ne la voit pas grisée, il ne
la voit pas du tout. La page « Les demandes d'accès » part pour la même
raison - l'écran qui les traite est `dev_access_requests`.

Documenter un écran que le lecteur ne peut pas ouvrir est pire qu'une page
absente : cela lui apprend à chercher ce qui n'est pas là. L'interrupteur du
formulaire de demande d'accès reste documenté, dans les réglages Système, en
disant où les demandes atterrissent.

Reste à trancher côté produit : le journal d'audit répond à « qui a touché à
quoi », une question d'administrateur plutôt que de développeur. La page
reviendra le jour où l'écran leur sera ouvert.

#### Deux onglets de réglages invisibles au client
`CoreConfigurationTabProvider::DEV_ONLY_GROUPS` réserve « Médias » et
« Séquences » au rôle développeur. Leurs deux pages partent, et les trois
endroits qui renvoyaient à ces réglages - la taille maximale d'un dépôt, le
nombre de versions gardées, l'affichage du crédit d'une photo - le disent
désormais : le réglage existe, il se demande au développeur du site.

### Interne

#### Trois demandes d'accès dans la démo
Elles naissent d'un formulaire public que personne ne remplit sur une démo,
donc l'écran qui les traite s'ouvrait sur « Aucune demande d'accès » - et
c'est cet écran vide qui était parti dans la documentation. Une en attente,
une acceptée, une refusée.

---

## [0.9.116] - 2026-09-11

### Corrigé

#### Le champ du titre s'appelait « Événements »
Dans la fenêtre d'un événement comme dans celle d'un rappel, le champ qui
demande un titre portait le nom pluriel de la section : « Événements »,
« Rappels ». Vu en photographiant les deux formulaires pour la
documentation. C'est « Titre » désormais, et la bande des rappels de la
grille garde l'intitulé qui est le sien.

### Documentation

#### Le Calendrier, étape par étape
Vingt captures remplacent les deux vues d'ensemble que dix pages se
partageaient : la page des récurrences était illustrée par une vue semaine
qui ne montre aucune récurrence, celle des invités par la même image que
celle des alertes.

Les quatre vues ont chacune la leur. Les récurrences montrent le menu des
quatre cas courants puis le panneau que « Personnalisé… » déplie. Les invités
se lisent sur la bulle d'un événement, avec leurs réponses. Le partage à un
compte et le partage par lien deviennent deux pages distinctes, parce que ce
sont deux écrans différents et que les confondre était le défaut de la page
précédente.

#### Trois portées, pas quatre
La page des récurrences annonçait quatre portées pour la modification d'une
occurrence. Il y en a trois : cette occurrence, celle-ci et les suivantes,
toute la série.

### Interne

#### La réunion générale reçoit des réponses différentes
Les entretiens de la démo portent chacun une réponse unique, ce qui montre
bien chaque état mais jamais le cas ordinaire : on invite plusieurs personnes
et on reçoit des réponses qui ne sont pas les mêmes. La liste ne dit ce
qu'elle sert à dire qu'à ce moment-là.

---

## [0.9.115] - 2026-09-11

### Corrigé

#### « Ajouter une catégorie » ne faisait rien
Dans la bibliothèque, le bouton restait sans effet : `openCreate()` appelait
`emptyForm()` sans la liste des champs qu'un projet client peut ajouter,
`Object.entries(undefined)` levait, et le gestionnaire de clic mourait avant
d'ouvrir la fenêtre. Vue avale l'erreur d'un gestionnaire, donc l'écran ne
disait rien : le bouton se contentait de ne pas répondre.

Personne ne pouvait créer une catégorie depuis l'interface. Trois tests
couvrent maintenant l'ouverture, avec et sans champs ajoutés, et le retour à
un formulaire vide d'une ouverture à l'autre.

### Documentation

#### La Médiathèque, étape par étape
Dix-huit captures remplacent les quatre vues d'ensemble : le dépôt du panneau
vide à la ligne créée, l'arborescence des dossiers et le filtre qu'un dossier
pose, les catégories et leurs actions, les étiquettes et leur couleur,
l'historique des versions, le recadrage avec son cadre tiré à la souris.

Le dépôt méritait sa suite d'images à lui seul : **le texte alternatif et la
légende n'apparaissent qu'une fois le fichier choisi**, et une capture du
panneau vide laissait croire qu'ils n'existent pas. La page disait aussi que
l'on peut déposer plusieurs fichiers par glisser-déposer : c'est un fichier à
la fois, par un bouton.

#### Trois pages retirées
- « La médiathèque côté visiteur » parle du site public, hors sujet pour une
  documentation du back-office.
- « Les fichiers orphelins » décrit une commande en terminal : rien à l'écran,
  rien à montrer à quelqu'un qui utilise l'administration.
- « Où un document est utilisé » décrivait un panneau que **rien n'alimente** :
  `DocumentUsageProviderInterface` n'a aucune implémentation dans le noyau,
  donc la liste reste vide tant qu'un module client n'en branche pas une.

Deux pages de Comptabilité renvoyaient elles aussi à une commande. « Vérifier
les sceaux » devient « Le sceau d'un contrat » et s'appuie sur le bandeau, qui
fait la même vérification à chaque affichage.

#### La recherche Pexels n'est pas là où la page le disait
Elle vit dans le sélecteur d'images, celui qui s'ouvre pour choisir une
vignette, pas dans la bibliothèque.

### Interne

#### La démo n'avait aucun historique de versions
Le bloc « Historique des versions » ne s'affiche qu'à partir de deux versions,
et une version naît d'un remplacement de fichier - que personne ne fait avant
la première capture. Un visuel de la démo en porte trois désormais, datées et
avec leurs fichiers sur le disque, sinon une ligne d'historique pointerait
vers rien.

Les flux de capture de la Médiathèque nettoient ce qu'ils créent : le dépôt
photographié est un vrai dépôt, et sans ce ménage chaque prise laissait une
affiche de plus dans la démo.

---

## [0.9.114] - 2026-09-11

### Ajouté

#### Agrandir une capture de la documentation
Une capture d'un écran entier est illisible à la largeur d'une colonne de
texte. Le clic l'ouvre en grand, les flèches passent d'une étape à la
suivante, Échap referme.

La visionneuse du site public a été promue dans la bibliothèque partagée,
`AppLightbox`, plutôt que recopiée : elle ne rendait déjà aucune vignette et
trouvait ses déclencheurs par un attribut que l'appelant nomme, donc il n'y
avait rien à changer pour qu'elle serve deux fois. Le point de montage du
site public garde son nom, parce qu'un nom dans un gabarit est une adresse
qui ne devrait pas bouger parce qu'un composant a déménagé.

### Corrigé

#### La page des notifications montrait du JSON
`/backend/notifications` est le point d'API qui nourrit la cloche, pas une
page : les notifications n'en ont pas. La capture affichait donc le JSON
rendu par le navigateur sur fond sombre.

C'est la quatrième capture prise sur un point d'API après le captcha, Pexels
et le graphe des notes, et toutes répondaient 200. `audit-paths.mjs` vérifie
désormais les trente et une adresses photographiées et signale celles qui ne
rendent pas une page.

#### La démo n'avait aucune notification
La cloche s'ouvrait sur « Aucune notification » : elles naissent d'une tâche
de fond, et une démo fraîche n'en a donc pas tant que le worker n'a pas
tourné. La démo en porte trois, dont une déjà lue.

#### Le fil d'Ariane de la documentation disait deux fois « Documentation »
La section et l'entrée de menu portent le même mot. Le fil va maintenant de
la section à la rubrique puis à la page, trois crans qui disent chacun
quelque chose.

---

## [0.9.113] - 2026-09-11

### Ajouté

#### Le manuel du produit, dans le produit
Une entrée **Documentation** dans le menu latéral ouvre cent quatre pages qui
expliquent le back-office écran par écran, avec cent soixante et onze
captures.

Elles vivent dans le code, `src/Module/Documentation/content/`, un fichier
Markdown par page : le dossier est la rubrique, le numéro est l'ordre de
lecture, l'en-tête porte le titre et le résumé. Aucun index à tenir à jour,
ajouter une page est ajouter un fichier. Les captures sont à côté, dans
`images/`, servies par une route plutôt que par le build : un client met
Aurora à jour avec Composer et rien d'autre, et une page aux cadres vides
serait pire que pas de page.

**Pourquoi pas dans les publications.** Ces pages y étaient, écrites avec
l'éditeur comme n'importe quel contenu. C'était le mauvais endroit deux fois :
un client pouvait les modifier, et une fonctionnalité partait sans sa
documentation puisque les deux vivaient dans des dépôts différents. Ici la
page est à côté du code qu'elle décrit, et les changer ensemble tient dans un
commit.

L'écran se lit en trois colonnes : les rubriques à gauche avec une recherche
qui cherche dans le texte et ignore les accents, la page au milieu, et ses
étapes à droite. Rien à configurer, aucun réglage, aucune permission propre :
qui peut ouvrir le back-office peut lire comment il marche.

---

## [0.9.112] - 2026-09-10

### Ajouté

#### Prévisualiser une publication depuis la liste
Le bouton n'existait que dans l'éditeur : voir une page telle que le visiteur
la voit demandait de l'ouvrir en modification d'abord. Il est maintenant dans
le menu d'une ligne, après « Modifier » qui reste le geste courant de cet
écran.

Il n'enregistre rien, contrairement à celui de l'éditeur : depuis une liste
il n'y a rien à l'écran à sauver, et c'est le dernier état enregistré que le
lecteur demande à regarder.

Il ne réclame aucun droit d'écriture, ce qui comble un manque : le privilège
`editorial.posts.view` existe séparément de `.edit`, et un compte qui pouvait
voir la liste sans pouvoir modifier n'avait **aucune action** dans ce menu.

#### Une liste qui se replie au-delà de cinq entrées
`AppRevealList` montre les premières entrées d'une liste et cache le reste
derrière « 12 de plus ». Posée sur les trois colonnes de filtres des
publications, où les termes suivent le nombre de rubriques du site : à
dix-sept, la colonne poussait la liste filtrée sous la ligne de flottaison.

Elle s'ouvre d'elle-même quand une entrée cachée est active, au chargement
comme plus tard. Sans quoi un filtre coché mais invisible rétrécit l'écran
sans que rien ne l'explique, ce qui est pire que la colonne trop longue.

Le composant est dans le socle et prend sa liste en propriété plutôt que de
compter ce qu'un slot a rendu : d'autres écrans ont des colonnes de filtres
qui grandiront pareil.

---

## [0.9.111] - 2026-09-10

### Ajouté

#### Une barre de recherche sur un type lu en séquence
Un sommaire de cent trente liens répond à « qu'est-ce qu'il y a » ; il ne
répond pas à « où est la page qui parle de la date de dépublication ». Le
champ est au-dessus du sommaire, sur chaque page d'un type lu en séquence, et
il cherche dans le **texte** des pages et pas seulement dans leurs titres.

La recherche publique existante savait déjà chercher le contenu, mais elle
était câblée sur le type que liste la page d'accueil. Elle accepte désormais
`?type=<slug>`, ce qui la rend utilisable par n'importe quel type. Un type
nommé mais inconnu répond 404 plutôt que de retomber en silence sur les
articles : un appelant qui se trompe doit l'apprendre.

Le sommaire reste rendu par le serveur et le composant le masque pendant
qu'il répond, au lieu de le redessiner : ce sont cent trente liens que les
moteurs doivent lire et qu'un lecteur sans JavaScript doit pouvoir suivre.

### Corrigé

#### Une section vide du sommaire s'affichait quand même
Une section dont aucune rubrique ne portait de page publiée imprimait son
titre suivi de blanc. Cela arrive dès qu'un terme existe sans publication
visible : une rubrique dépubliée, ou créée avant que rien n'y soit écrit.

#### La démo n'avait aucune lecture en séquence à montrer
Aucune taxonomie n'était rattachée à un type de contenu, si bien que l'écran
d'édition ne proposait aucun terme et que le site public ne dessinait ni
sommaire ni page suivante. Ce qui distingue une documentation d'un blog était
donc invisible dans la démonstration. Les catégories et les étiquettes sont
maintenant rattachées au type Article.

---

## [0.9.110] - 2026-09-10

### Corrigé

#### Le graphe des liens entre notes n'avait aucun bouton pour l'ouvrir
Le composant était monté, branché sur son point d'API et traduit jusqu'au
libellé « Ouvrir le graphe » - et `graphOpen` n'était mis à vrai nulle part.
L'icône `Network` était même déjà importée dans l'écran, sans être utilisée.
La fonction existait entière, sans porte d'entrée, comme l'historique des
versions avant elle.

Le bouton est dans la barre de la note, entre « Partager » et le panneau des
liens entrants.

#### L'écran de modération nommait les publications dans une langue au hasard
`getTranslations()->first()` rend la traduction que Doctrine a chargée en
premier. Le back-office français listait donc des commentaires « Sur
Escribir su primer artículo ». Le titre suit maintenant la langue de qui
modère, avec repli sur une autre langue plutôt qu'une case vide pour une
publication qui n'existe pas dans la sienne.

### Ajouté

#### Des commentaires et un carnet de notes dans la démo
`make demo` ne créait ni l'un ni l'autre. L'écran de modération, la page
publique d'un article, l'écran des notes et le graphe s'ouvraient donc tous
sur du vide - et les captures de la documentation montraient ce vide, sur
une douzaine de pages.

La démo porte maintenant quatre commentaires dans les trois états, dont une
réponse et des réactions, et sept notes reliées entre elles par des
`[[liens]]`, avec des étiquettes, une note fille, une mention non liée et
une note orpheline. De quoi photographier ce que ces écrans font.

---

## [0.9.109] - 2026-09-10

### Ajouté

#### L'historique des versions d'une publication
Chaque enregistrement écrivait déjà une version, et les trois routes pour les
lire et en restaurer une existaient, testées et soumises aux permissions.
Rien ne les appelait : la fonction était complète et hors d'atteinte, et la
documentation décrivait un filet de sécurité que personne ne pouvait tirer.

Un bouton **Historique** ouvre le panneau depuis l'éditeur. La liste donne
date, statut et auteur de chaque version ; choisir une version l'affiche à
côté de la version actuelle - titre, adresse, résumé et texte du contenu -
et la restauration demande confirmation avant d'écrire.

Côte à côte plutôt que différence ligne à ligne : ce qu'on cherche d'abord
est « laquelle est-ce », et cela se répond sans moteur de comparaison. La
restauration crée elle-même une version, donc le retour reste possible.

### Corrigé

#### Annuler dans une fenêtre de confirmation quittait la page
Dans toutes les listes du produit : ouvrir le menu d'une ligne, choisir une
action, puis cliquer **Annuler** renvoyait sur le tableau de bord du module.
Supprimer faisait la même chose - la suppression avait bien lieu, et on se
retrouvait ailleurs, sans ses filtres ni sa page.

`useBackButtonClose` poussait une entrée d'historique par surcouche et
appelait `history.back()` par surcouche à la fermeture. `history.back()` est
asynchrone : l'entrée n'est pas retirée quand l'appel revient, elle l'est
quand `popstate` arrive. L'enchaînement le plus courant du produit - un menu
de ligne qui se ferme pendant que la fenêtre qu'il ouvre apparaît - croisait
donc une poussée et un retrait en attente, et la pile se dépilait d'un cran
de trop.

Une seule entrée partagée désormais, pour « une surcouche est ouverte », et
son retrait attend une micro-tâche : le remplacement d'une surcouche par une
autre ne touche plus du tout à l'historique. La touche Retour continue de
fermer la surcouche du dessus, une par une, avant de quitter la page.

#### Deux statuts manquaient à la démo
`make demo` créait des publications dans trois états sur cinq. La page de
documentation sur le cycle de vie en annonce cinq, et sa capture en montrait
trois. La démo porte maintenant un article en attente de revue et un article
archivé.

---

## [0.9.108] - 2026-09-10

### Corrigé

#### Toutes les dates de l'interface s'affichaient vides
`d(valeur, "short")` demande à vue-i18n un format nommé. Aucune table de
formats n'était déclarée, et vue-i18n répond alors par une chaîne vide, sans
exception ni repli : la liste des soumissions d'un formulaire affichait
« SUB-000001 · · fr », un commentaire à modérer arrivait sans date, et la
ligne d'un prochain événement dans le menu latéral n'en portait pas non plus.

Le trou ne se voyait qu'à l'œil, sur un écran qui avait des données à
montrer. Les quatre appels concernés passaient par le même `d(…, "short")` ou
`d(…, "long")` ; les dizaines d'autres endroits passent un objet d'options et
n'ont jamais été touchés, ce qui explique qu'une interface entière ait pu
paraître normale.

`datetimeFormats.js` déclare les deux noms dans les trois langues : `short`
répond à « quand exactement », horloge comprise, `long` à « quel jour », et
c'est ce que reçoit une entrée qui dure toute la journée. Le même tableau est
donné aux tests de composants, pour qu'un test qui formate une date voie ce
que le produit affiche.

#### Un champ de formulaire pouvait être rangé sur une étape inexistante
Le constructeur acceptait n'importe quel nombre dans la case « Étape ». Le
rendu public, lui, compte les étapes à partir de 1 et n'affiche que celles du
formulaire : un champ sur l'étape 0, ou sur l'étape 3 d'un formulaire qui en
a deux, n'était montré à personne. Il restait listé dans l'administration,
modifiable, et absent du site. Le seul moyen de s'en apercevoir était
d'ouvrir la page publique et de compter.

L'enregistrement le refuse maintenant, à la création comme à la modification,
et la case n'accepte plus de valeur en dessous de 1.

#### Le menu d'un champ montrait deux clés de traduction
`Modifier` et `Supprimer` y étaient suivis de
`backend.forms.fields.row_actions.edit_description` et de son pendant. Les
deux clés n'existaient nulle part : elles sont écrites comme données, pas
appelées par `t()`, et le test qui vérifie les traductions des composants ne
regardait que les appels. Il lit désormais aussi les littéraux qui commencent
par une racine du catalogue, ce qui est le seul endroit d'où ils peuvent
venir.

### Ajouté

#### Un formulaire de démonstration dans les fixtures
`make demo` ne créait aucun formulaire, donc l'écran du constructeur, la liste
des types de champ et celle des demandes reçues s'ouvraient tous sur « Aucun
formulaire ». La démo porte maintenant une demande de devis en deux étapes,
avec un champ de chacun des neuf types, un champ conditionné, et trois
demandes déjà reçues.

---

## [0.9.107] - 2026-09-10

### Corrigé

#### Le bouton « Résilier » n'ouvrait aucune fenêtre
Sur un contrat conclu, le bouton était là, et il ne faisait rien. Tout le
reste existait pourtant : l'état qui commande la fenêtre, le modèle du
formulaire avec ses quatre champs, la fonction d'envoi, les options
d'initiative, la route du serveur, la validation, et les traductions
jusqu'aux textes d'aide sous chaque champ. Seul le balisage de la fenêtre
manquait, si bien que le clic posait un drapeau que rien ne lisait.

Deux composants importés et utilisés nulle part, `AppSelect` et
`AppTextarea`, disaient d'ailleurs que ce balisage avait existé.

La fenêtre est écrite, calquée sur celle de contresignature : les deux dates,
l'initiative, le motif facultatif, et un bouton qui reste éteint tant que les
trois champs obligatoires ne sont pas remplis.

Trouvé en photographiant le parcours pour la documentation : la capture
montrait une page inchangée après le clic.

---

## [0.9.106] - 2026-09-10

### Corrigé

#### Quinze jetons affichaient leur clé de traduction dans le panneau des variables
Le panneau qui liste les jetons disponibles à l'écriture d'une trame dérive
la clé de libellé du jeton lui-même, ce qui évite une table de correspondance
à tenir. Mais rien ne vérifiait que la traduction existe : les douze jetons
`provider.*` et les trois `contract.amends_*` n'en avaient aucune, et le
panneau affichait à leur place
`backend.accounting.contract_templates.variables.provider_name`, à côté de la
valeur d'exemple.

Les quinze libellés manquants sont écrits, en français et en anglais. Un test
parcourt le catalogue et exige un libellé par jeton et par langue du
back-office, de sorte qu'un jeton ajouté sans traduction fera tomber la suite
en nommant le jeton fautif.

Trouvé en photographiant l'éditeur de trame pour la documentation.

---

## [0.9.105] - 2026-09-10

### Corrigé

#### La page de signature n'annonce plus un code qui n'est pas parti
Sur la page qu'ouvre le client, la demande de code basculait l'écran dans
l'état « code envoyé » quelle que soit la réponse du serveur. Quand l'envoi
échouait, le client lisait « Code envoyé à . », sans adresse, et attendait
ensuite un code que personne n'avait expédié. Aucune erreur ne s'affichait.

L'adresse masquée rendue par le serveur est ce qui prouve que l'envoi a eu
lieu : sans elle, l'écran signale l'échec et invite à réessayer ou à prévenir
son interlocuteur, plutôt que d'annoncer un succès.

C'est la seule page du produit qu'un client voit, et une panne d'envoi y
coûtait une signature sans que personne le sache.

Trouvé en écrivant la documentation, en photographiant le parcours de
signature de bout en bout.

---

## [0.9.104] - 2026-09-10

### Corrigé

#### Une date tapée au clavier n'est plus avalée en silence
Le champ de date ressemble à un champ de texte, donc il se tape. Le composant
n'écoutait que les clics dans le calendrier : la saisie s'affichait, puis
disparaissait à la fermeture, sans message et sans que la valeur précédente
revienne. Une personne qui tapait sa date au lieu de la choisir enregistrait
un formulaire sans date, et rien ne le lui disait.

La saisie clavier est maintenant acceptée, dans les formes qu'on écrit
vraiment : `15/11/2026` d'abord, puis `2026-11-15`, `15112026`, `15-11-2026`
et `15.11.2026`. Entrée et Tab valident. Le mois seul a les siennes,
`11/2026` et `2026-11`, et un champ avec heure accepte `15/11/2026 09:30`.

Une saisie que le composant ne sait pas lire laisse la valeur précédente en
place plutôt que de vider le champ.

Trouvé en écrivant la documentation : le script qui rejouait le parcours de
préparation d'un contrat tapait la date, et le contrat partait sans date de
prise d'effet.

---

## [0.9.103] - 2026-09-10

### Corrigé

#### Le champ de date se peint comme les autres champs
Le sélecteur de date pose son propre `input`, qui ne peut donc pas porter les
classes utilitaires des autres champs : son apparence est écrite à part, et
elle avait dérivé. Le liseré de focus était un indigo en dur, si bien que sur
un site dont la couleur d'accent n'est pas l'indigo, un seul champ du
formulaire s'allumait de la mauvaise couleur. Le texte de substitution, lui,
n'était pas atténué comme ailleurs.

Les deux suivent maintenant les jetons du thème, comme `AppInput`. Visible
partout où une date se saisit : contrats, agenda, rappels, partages, éditeur
de publication.

---

## [0.9.102] - 2026-09-09

### Corrigé

#### La page d'une rubrique liste ce que ses sous-rubriques contiennent
Une publication est rangée sous une feuille de la taxonomie, jamais sous la
branche au-dessus. La page d'une rubrique qui ne contient que des
sous-rubriques répondait donc 200 et ne listait rien : une adresse qu'un
lecteur atteint légitimement, et une page vide qui se lit comme une panne
plutôt que comme un vide.

Sorti sur la documentation, dont les trois parties sont des termes sans
publication propre.

La page d'un terme liste maintenant le terme et tout ce qui est classé
dessous. Une taxonomie à plat n'a pas de descendant, donc rien ne change pour
les étiquettes.

L'appartenance est demandée en `EXISTS` et non en jointure, et ce n'est pas
un choix de style : une publication portant deux termes de la même branche
correspond deux fois à la jointure, donc serait affichée deux fois et comptée
deux fois dans la pagination. `DISTINCT` serait la réponse habituelle, et
PostgreSQL la refuse ici puisque la ligne porte la grille, une colonne `json`,
et que `json` n'a pas d'opérateur d'égalité. Ne pas joindre du tout règle les
deux.

---

## [0.9.101] - 2026-09-09

### Corrigé

#### La page d'un terme n'est pas une publication qui partage son adresse
`/{locale}/{a}/{b}` se lit d'abord comme une publication, et ne retombe sur
la page de terme que si aucune publication ne répond. La recherche la plus
large des deux, celle qui ignore le type et qui existe pour qu'une adresse
partagée avant un changement de type mène encore quelque part, s'exécutait
avant cette retombée : n'importe quelle publication, de n'importe quel type,
portant l'adresse du terme capturait sa page et répondait une redirection
**permanente** vers elle-même. La page du terme devenait inatteignable, et
les navigateurs gardaient le détour en cache.

Sorti en vérifiant la documentation : la rubrique « site-public » et une
carte du tour à cette adresse sont toutes deux légitimes, et aucune n'est
l'autre.

L'adresse qui nomme une taxonomie existante et un terme existant de
celle-ci répond maintenant la page de ce terme, avant tout élargissement de
la recherche. Une publication du type que l'adresse nomme continue de
gagner : c'est la page la plus précise.

Troisième défaut de la même famille après la 0.9.98 et la 0.9.100, et
toujours la même cause : une identité pensée plus large que l'URL qui la
porte.

---

## [0.9.100] - 2026-09-09

### Corrigé

#### Une adresse de publication est unique dans son type, pas dans tout le site
La route publique est `/{locale}/{postTypeSlug}/{slug}` et la recherche
ignorait le type : la première publication portant cette adresse gagnait,
quel que soit son type. Là où les deux divergeaient, le contrôleur y voyait
une publication ayant changé de type et répondait une redirection
**permanente** vers l'autre. La page demandée devenait inatteignable, et les
navigateurs gardaient le détour en cache.

Le défaut est sorti en écrivant la documentation : une page « tableau de
bord » allait s'écrire à côté d'une carte du tour portant exactement cette
adresse. Les deux sont légitimes, et aucune n'est l'autre.

La recherche demande maintenant d'abord le type que l'adresse nomme. La
redirection reste, en repli, et seulement quand aucune publication de ce
type ne répond : une adresse partagée avant qu'une publication change de
type doit continuer de mener quelque part.

C'est le même défaut que celui des termes de taxonomie corrigé en 0.9.98, et
la même cause : une identité pensée plus large que l'URL qui la porte.

---

## [0.9.99] - 2026-09-09

### Ajouté

#### Le sommaire d'une documentation, et la page suivante
Ce qui sépare une documentation d'une liste d'articles : un lecteur qui
arrive sur une page doit voir où elle se situe et ce qui vient après. Un
blog n'a besoin ni de l'un ni de l'autre, donc rien de tout cela ne
s'affiche par défaut.

Un type de contenu déclare qu'il **se lit en séquence**, une case à cocher
de plus à côté de « contenu en blocs » et « image à la une ». Cochée, la
page publique dessine sous son contenu le sommaire de l'ensemble, la page
en cours marquée, puis un lien vers la précédente et un vers la suivante.

Le sommaire n'est pas une seconde structure à tenir à jour : c'est la
taxonomie hiérarchique du type, dessinée. Les rubriques et sous-rubriques
donnent l'arborescence, leur position donne l'ordre, et les pages s'y
accrochent par leur terme. Leur ordre à elles est le rang de lecture livré
en 0.9.97.

Les deux voisins traversent tout l'ouvrage plutôt que de s'arrêter au bout
de la rubrique : un lecteur qui finit « Éditorial » veut la première page
de « Médiathèque », pas une impasse. Une page hors de l'arborescence n'a
pas de voisins du tout, plutôt que les deux premières de la séquence, ce
qui mentirait sur l'endroit où elle se trouve.

Tout est lu en deux requêtes et regroupé en mémoire : le nombre de requêtes
ne grandit pas avec le nombre de rubriques.

---

## [0.9.98] - 2026-09-09

### Corrigé

#### L'adresse d'un terme est unique dans sa taxonomie, plus dans toutes à la fois
L'index `uniq_term_locale_slug` couvrait `(locale, slug)` pour l'ensemble des
taxonomies, au motif écrit dans le code que « `/fr/theme/boulange` désigne
exactement un terme ». La route dit le contraire : elle est
`/{locale}/{taxonomySlug}/{termSlug}`, et le contrôleur cherche le terme
**dans la taxonomie que l'URL nomme**. L'index large ne servait donc pas à
lever l'ambiguïté de l'adresse.

Ce qu'il faisait, en revanche, c'était refuser du contenu légitime : deux
taxonomies ne pouvaient pas avoir chacune un terme « Éditorial », une
étiquette et une rubrique de documentation par exemple. Et le refus arrivait
sous forme de violation de contrainte, sans une phrase pour la personne qui
venait de taper le nom.

L'unicité voulue porte sur deux tables - la taxonomie est sur le terme, le
slug sur sa traduction - et aucun index ne couvre deux tables. La taxonomie
est donc portée aussi par la traduction, écrite par l'entité depuis le terme
et jamais par un appelant, pour que les deux colonnes ne puissent pas se
contredire.

Le manager vérifie désormais l'adresse avant d'écrire et rend une phrase. La
base continue de vérifier derrière lui : un contrôle applicatif seul laisse
passer les courses.

#### La médiathèque répondait à la recherche globale sans rien vérifier
L'agrégateur derrière le champ de recherche n'exige que
`general.search.view`, donc chaque fournisseur doit dire lui-même qui a le
droit de lire ce qu'il renvoie. Celui de la médiathèque ne le faisait pas :
un compte sans aucun privilège sur la bibliothèque recevait des noms de
fichiers, et un déploiement où le module est éteint en recevait aussi.

Il est aligné sur ses deux voisins : refus quand le module est éteint, refus
sans le privilège `ged.documents.view`, et le `try/catch` que le contrat
impose pour qu'un fournisseur en panne n'emporte pas le champ entier.

Des noms de fichiers sont une fuite modeste, et ce n'est pas le sujet : trois
fournisseurs qui répondent à la même question doivent y répondre de la même
façon, sinon personne ne peut raisonner sur ce que le champ expose.

---

## [0.9.97] - 2026-09-09

### Ajouté

#### Un ordre de lecture explicite sur une publication
Tout ce qu'un visiteur voit était trié par date de publication, ce qui est
juste pour un blog et faux pour ce qui se lit dans un ordre. Le tour du
produit sur `/fr/page/aurora` simulait déjà son ordre en espaçant ses
vingt-trois cartes d'une minute dans `published_at` : insérer une carte
entre deux autres oblige à recalculer tous les horodatages suivants. Une
documentation de cent trente-quatre pages ne tient pas comme ça.

Une publication porte maintenant un **rang de lecture** facultatif, saisi
dans l'onglet Paramétrage. Vide, il ne change rien : la liste retombe sur la
date, exactement comme avant. Renseigné à partir de 1, il place la
publication en tête, dans l'ordre des rangs. Les archives, les pages de
terme et les zones « liste automatique » le respectent toutes les trois.

Ce n'est pas une bascule « manuel ou chronologique » par type de contenu,
qui était l'autre option et la plus élaborée : il faudrait la poser avant de
pouvoir numéroter, elle peut contredire les rangs réellement enregistrés, et
elle répond à une question que personne ne pose. Un auteur numérote ce qu'il
veut voir dans un ordre et laisse le reste tranquille.

Le tri s'appuie sur le fait que PostgreSQL classe les valeurs nulles en
dernier en ordre croissant. C'est un défaut, pas une garantie de la norme,
donc c'est un test qui le tient plutôt qu'un `COALESCE` que personne ne
comprendrait dans deux ans.

Une copie ne reprend pas le rang de l'originale : à rang égal la date
départage, et la copie passerait devant.

---

## [0.9.96] - 2026-09-09

### Corrigé

#### Les trois écrans du module Comptabilité s'ouvrent enfin en local
Les listes des clients, des trames et des contrats répondaient 500 partout
sauf en production, et depuis la 0.9.86. Leurs constructeurs de vue
fabriquaient `/contracts/__id__/update` en appelant directement le générateur
d'URL, sur une route dont le `id` est déclaré `\d+` : le générateur refuse, et
il refuse pendant le rendu de la page.

La production ne le montrait pas. `strict_requirements: null` y perce le trou
sans rien dire, alors qu'en dev et en test la même génération lève. Un défaut
qui n'existe que là où sont les développeurs, ce qui explique qu'il ait tenu
onze versions et que le module n'ait jamais eu la moindre capture d'écran.

Le mécanisme qui règle ça existait déjà, mais seulement pour Twig. Il devient
un service, `PathTemplateGenerator`, que les constructeurs de vue peuvent
appeler comme les gabarits appelaient `path_template()`. Les routes gardent
leur `\d+` honnête : ce qui est relâché, c'est la génération, et seulement pour
l'appel qui veut laisser un trou.

Un test demande maintenant les trois écrans et vérifie qu'ils répondent. C'est
le test le plus ennuyeux du module et celui qui manquait le plus : les autres
pilotent les endpoints, aucun ne demandait une liste.

#### Deux étiquettes de module illisibles dans le journal d'audit
La colonne Module affichait `backend.modules.accounting` et
`backend.modules.notes_markdown` en clair. L'écran lit `backend.modules.<module>`
avec la chaîne que porte la ligne d'audit, qui n'est pas la clé du réglage
d'activation du module. Les deux traductions manquaient.

### Ajouté

#### Un jeu de démonstration pour la comptabilité
`fixtures/Accounting/` : trois sociétés fictives dont les SIRET passent la
somme de contrôle, quatre trames au texte inventé, et un contrat dans chaque
état que la liste sait dessiner - brouillon, envoyé avec une relance, conclu,
avenant de ce conclu, refusé avec son motif, et résilié avec un préavis en
cours.

Le module était le seul sans jeu d'essai, donc le seul dont les écrans ne
montraient rien en local. Les douze réglages d'identité du prestataire sont
écrasés et pas seulement remplis quand ils sont vides : sur une base locale
recopiée de la production, ils portaient une vraie identité, et le scellement
l'avait figée dans le document. Un instantané ne se corrige pas après coup,
c'est tout son intérêt.

#### Les captures du tour, reproductibles
`tools/screenshots/capture-tour.mjs` : un script Playwright qui régénère les
images des cartes de `/fr/page/aurora` sur une instance locale chargée en
`make demo`, toutes à la même taille et dans le même thème. Les vingt-huit
existantes avaient été prises à la main une par une, ce qui explique les deux
doublons.

Local et jeu factice uniquement, et c'est la raison d'être du script : une
capture prise en production mettrait le nom et le SIRET d'un vrai client sur
une page publique.

---

## [0.9.95] - 2026-09-09

### Ajouté

#### Les avenants
La question qui décide de tout : un avenant est-il un nouveau document ou un
nouvel état ? C'est un document, et il n'y avait pas le choix. Un contrat
scellé est immuable par construction, donc « modifier l'annexe d'un contrat
signé » n'a aucune implémentation qui ne soit un mensonge. Ce qui se passe sur
papier est ce qui se passe ici : un second document, signé lui aussi, qui dit
quelle partie du premier il remplace.

C'est aussi la réponse au cas de l'annexe modifiée après signature du corps.
L'original garde sa copie scellée des deux moitiés pour toujours, l'avenant
porte la nouvelle annexe, et l'histoire se lit dans l'ordre où elle est
arrivée. Un test le prouve en vérifiant que la référence, le hash et le HTML
du parent ne bougent pas.

La référence est dérivée du parent, `CM-2026-0001-A1`, et pas tirée de la
séquence : une ligne dans un export comptable dit à quoi elle appartient sans
jointure. Le rang est compté au gel, donc un brouillon abandonné ne consomme
rien.

Quatre refus, chacun une erreur différente : on n'amende pas un brouillon, un
avenant ne s'amende pas lui-même (la pratique les numérote tous contre
l'original), le client d'un avenant est celui du contrat modifié, et un
contrat résilié n'a plus rien à modifier. Plus une garde de jeton : les
variables `{{contract.amends_*}}` existent, et une trame qui les emploie
refuse de sceller un contrat qui n'amende personne, au lieu d'imprimer un
blanc là où elle nomme le document qu'elle modifie.

#### La résiliation
L'inverse d'un avenant : un fait, pas un document. Un contrat conclu reste
conclu, parce qu'il a été signé et que ça n'expire pas ; ce qui se termine est
la relation. Deux dates, puisqu'un préavis est exactement l'écart entre elles,
et une origine, parce qu'un client qui part, un prestataire qui arrête et un
accord commun ne se relisent pas de la même façon un an plus tard.

Pas un parcours de signature : un client résilie par email, pas en cliquant
dans une application où il n'a pas de compte, et lui construire un formulaire
serait construire ce que personne ne peut utiliser. La date d'effet dans le
futur reste distinguée de la date passée : un contrat notifié aujourd'hui pour
la fin du mois tourne encore.

### Dans aurora-client

Une migration, jouée par `make aurora-update` : sept colonnes sur
`core_contracts` et une clé étrangère du contrat vers lui-même, en `SET NULL`
avec la référence du parent copiée à côté - un avenant dit encore ce qu'il
amende après que le parent soit sorti de sa conservation.

Rien d'autre à répercuter. Pour l'utiliser il faudra une trame d'avenant, qui
est du contenu : un corps qui emploie `{{contract.amends_reference}}` et décrit
ce qu'il remplace.

## [0.9.94] - 2026-09-09

### Ajouté

#### La mention d'information sur la page de signature
La page demandait à un inconnu son nom, son email, un lieu et une date,
enregistrait son adresse IP et son navigateur, et stockait le tracé de sa
signature. Elle n'en disait rien. L'article 13 le demande **au moment de la
collecte**, c'est-à-dire sur cette page et pas ailleurs : une politique de
confidentialité sur un site qu'on n'atteint pas d'ici ne satisfait rien.

Elle est repliée dans un bloc dépliable en bas de page, présente aussi quand
le contrat est signé ou refusé - les données ont été collectées dans les deux
cas, et cette page est le seul endroit où cette personne peut revenir. Un
renvoi discret sous la case d'acceptation la signale, pour que la case ne se
coche pas avec l'information hors de portée.

Elle nomme les deux bases séparément, parce qu'elles ne couvrent pas la même
chose : l'identité et la signature relèvent de l'exécution du contrat
(art. 6.1.b), les éléments observés de l'intérêt légitime à pouvoir défendre
ce contrat (art. 6.1.f). Et elle dit que le refus est enregistré lui aussi,
avec sa date, son IP et son motif.

**Tout ce qu'elle affirme, elle le lit.** Le responsable du traitement vient
des réglages que le document imprime déjà ; la durée de conservation vient de
la valeur que la garde de suppression applique. Une mention qui citerait ses
propres chiffres finirait par promettre une conservation que personne
n'applique, ce qui est pire que le silence : une affirmation écrite contredite
par le code d'à côté. Un test le vérifie sur le HTML servi, pas sur le service
qui le construit.

Elle n'est pas dans le document scellé, contrairement à la clause de langue
faisant foi : celle-là est contractuelle, celle-ci non, et l'ajouter au HTML
scellé changerait toutes les empreintes déjà prises.

### Dans aurora-client

Rien à répercuter, aucune migration. La mention se remplit d'elle-même à
partir de *Configuration > Comptabilité* : la dénomination, l'adresse et
l'email du prestataire y nomment le responsable du traitement, et un réglage
vide est omis plutôt qu'imprimé en blanc.

## [0.9.93] - 2026-09-09

### Ajouté

#### Refuser explicitement
Ne pas signer, c'était ne pas cliquer : un contrat que personne n'a ouvert
ressemblait exactement à un contrat que le client a refusé, et le prestataire
ne pouvait pas distinguer « en attente » de « non ». La page publique porte
maintenant un lien discret sous le bouton de signature, avec un motif
facultatif.

Aucun code par email n'est demandé, et c'est une décision. Un code prouve une
boîte aux lettres, ce dont une signature a besoin parce qu'elle engage ; un
refus n'engage personne et se défait d'un renvoi. L'exiger reviendrait à
laisser un problème de messagerie s'interposer entre quelqu'un et le mot non.
Le motif est facultatif pour la même raison : demander de justifier un refus
est une petite contrainte de trop dans un document dont le sujet est le
consentement.

Le refus garde la trace qu'une signature garde - quand, depuis où, avec quel
agent - refuse de toucher un contrat déjà signé, et laisse l'adresse ouverte
exprès : la personne qui vient de répondre doit voir ce qui a été enregistré,
pas le 404 d'un inconnu. Renvoyer le contrat efface le refus courant, l'audit
en garde l'histoire.

#### Relancer un contrat envoyé et non signé
Une relance est un renvoi, pas un mail à côté. L'application ne garde qu'un
hash du jeton qu'elle a distribué : elle est donc incapable de reconstruire
l'adresse envoyée la semaine dernière, et un rappel qui pointerait dessus
serait un lien que ce code ne sait pas produire. Elle en crée une nouvelle,
révoque la précédente et le dit dans le mail.

Éteint par défaut, parce qu'un mail qui part tout seul chez le client de
quelqu'un d'autre est une décision. Un plafond de relances, un délai compté
depuis le dernier envoi et non depuis le scellement, et rien d'envoyé à un
contrat qui a déjà répondu : signé, conclu, refusé, expiré et révoqué sont des
réponses, et relancer une réponse est ce qui rend le mail automatique odieux.

#### Une durée de conservation, et une suppression qui l'attend
Un contrat scellé ne pouvait pas être supprimé du tout, ce qui protégeait la
preuve et gardait indéfiniment des données personnelles. Il peut l'être
maintenant, mais seulement quand la conservation est échue, et le refus donne
la date : « pas encore » sans date laisse le lecteur se demander s'il est en
avance d'un jour ou d'une décennie.

Dix ans par défaut, cinq en plancher que le réglage ne peut pas franchir : un
champ vidé ou mal tapé ne doit pas pouvoir dire « supprimez librement les
contrats signés ». `aurora:contracts:retention` rapporte ce que l'archive
contient et ce qui en est sorti, et ne supprime jamais : une conservation
échue est une permission, pas une instruction.

### Dans aurora-client

Une migration, jouée par `make aurora-update` : six colonnes ajoutées à
`core_contracts`, toutes nullables ou avec un défaut, donc rien à reprendre.

Les relances passent par le Scheduler déjà en place, donc par le worker
`aurora-worker` : rien à configurer, mais un worker arrêté est une relance qui
ne part pas. Les quatre réglages arrivent dans *Configuration >
Comptabilité*, relances éteintes.

## [0.9.92] - 2026-09-09

### Corrigé

#### Une action prise dans la liste des trames répond dans la liste
Ouvrir un brouillon et dupliquer sautaient dans l'éditeur. Chacun se
défendait isolément, mais la règle qui compte est plus simple : ce qu'on
déclenche depuis une ligne s'affiche sur cette ligne. Le badge ambre du
brouillon est désormais le chemin vers l'éditeur, et c'est le lecteur qui
décide d'y aller.

Le saut avait aussi un défaut concret : quelqu'un qui ouvrait un brouillon sur
la mauvaise trame se retrouvait dans l'éditeur, avec le bouton Retour du
navigateur pour seule sortie et un brouillon dont il ne voulait pas.

Un test couvre les trois actions plutôt que de le confier à la relecture : un
`location.assign` égaré se lit comme une commodité jusqu'au jour où quelqu'un
perd sa place dans une liste de trente trames. Il couvre aussi le filtre par
type, dont le refus d'une valeur que l'application ne déclare pas.

### Dans aurora-client

Rien à répercuter, aucune migration.

## [0.9.91] - 2026-09-09

### Ajouté

#### Un filtre par type sur les trames
Le groupe de pilules que le reste de l'application utilise pour filtrer, avec
un *Tous*, le compte à côté de chaque libellé, et le choix gardé dans l'URL
comme le sélecteur de vue. Deux valeurs et un *tous* : le panneau de cases à
cocher de la liste des articles gagne sa place sur trois dimensions, pas sur
une.

Le compte suit la recherche et le repli des archivées, sinon il promettrait des
lignes que le clic ne donne pas. Et la liste vide distingue désormais les deux
absences : rien encore, ou rien qui corresponde. La deuxième est celle où il
faut effacer un filtre plutôt que créer une trame.

#### Abandonner un brouillon depuis la liste
L'action existait, mais seulement dans l'éditeur : il fallait entrer dans le
brouillon pour en sortir. Elle est maintenant dans le menu de la ligne et sur
la carte, sous la permission de suppression, comme le bouton de l'éditeur.

La confirmation dit ce qui revient et ce qui ne revient pas. La version en
vigueur ne bouge pas et aucun contrat n'est touché ; le numéro que le brouillon
avait pris reste consommé, parce qu'un numéro qui a existé ne doit jamais
désigner un autre texte. Sans cette phrase, quelqu'un qui lit « comme si je
n'avais rien ouvert » et voit la version 4 croirait à un défaut.

### Modifié

#### La version et les langues passent en badge
La version en vigueur porte la couleur d'un contenu publié, le brouillon celle
d'un contenu en cours, et le badge du brouillon reste le chemin vers l'éditeur.
Une absence reste du texte : « jamais publiée » est une phrase, pas un état à
repérer.

Les langues aussi, un badge chacune plutôt qu'une liste à virgules : la
question posée sur cette colonne est de savoir si une langue donnée est
rédigée, et un badge y répond sans lire la ligne.

### Dans aurora-client

Rien à répercuter, aucune migration.

## [0.9.90] - 2026-09-09

### Corrigé

#### Des exemples inventés à la place d'une identité réelle
Le groupe `provider` du catalogue de variables portait le vrai nom et le vrai
SIRET du prestataire, et trois tests les reprenaient. Les deux dépôts sont
publics, et le groupe `customer` juste au-dessus montrait la bonne façon de
faire depuis le début : une société inventée, au format juste.

Le SIRET d'exemple passe la clé de Luhn, comme un vrai, pour qu'il reste
utilisable là où la contrainte est vérifiée. Le lieu de signature des tests
devient une ville quelconque : ce qu'ils prouvent est qu'un lieu déclaré
arrive dans la preuve, pas lequel.

### Dans aurora-client

Rien à répercuter, aucune migration. Les exemples ne servent qu'à peupler la
liste des jetons dans l'éditeur de trame ; les valeurs qui s'impriment
viennent des réglages.

## [0.9.89] - 2026-09-09

### Modifié

#### La barre d'outils des listes tient sur une ligne
Le sélecteur de vue quitte le bloc d'actions et reste collé au champ de
recherche, téléphone compris. Empilé sous le champ il se lisait comme un
second filtre et coûtait une ligne entière ; c'est un contrôle de la
recherche, pas une action de la page. Le bouton primaire garde la sienne,
avec son texte : c'est lui qu'on cherche quand on ouvre l'écran.

`AppListToolbar` porte un slot `inline` pour ça, donc les autres listes
peuvent l'adopter sans réinventer la grille. Sans ce slot, la mise en page
est celle d'avant : rien à changer dans les écrans qui ne s'en servent pas.

#### Les actions des trames et des contrats passent en modal
Les deux écrans du module prennent la colonne *Actions* nommée, alignée à
droite, et le regroupement derrière un seul bouton déjà en place sur les
utilisateurs et sur les clients. Une bande d'icônes ne parle qu'à qui les
connaît déjà, elle serre la ligne sur un écran étroit, et l'action
destructrice y finit à quelques pixels des inoffensives.

La liste d'actions est définie une fois par écran et rendue deux fois : les
cartes la déplient, la ligne la replie. Écrite dans les deux gabarits, une
permission ajoutée d'un côté et oubliée de l'autre est un bouton que
quelqu'un possède et ne devrait pas.

### Corrigé

#### L'en-tête des contrats scellés était d'une colonne trop court
La colonne du lien de signature n'avait pas de titre, alors que sa
traduction existait depuis le début : cinq en-têtes pour six cellules, donc
un décalage silencieux sur les écrans larges.

### Dans aurora-client

Rien à répercuter, aucune migration.

## [0.9.88] - 2026-09-09

### Ajouté

#### Vue liste ou vue cartes, sur les trames et les contrats
Les deux écrans du module offrent le même choix que le reste de l'application,
avec le même composant et le choix gardé dans l'URL : une vue envoyée dans un
lien s'ouvre comme celle qu'on a quittée. **La liste est la vue par défaut** :
elle répond en une ligne par trame à ce pour quoi l'écran est ouvert.

En liste, aucune couleur hors la pastille de type. Un tableau gagne à être
parcourable, et une ligne teintée entrerait en concurrence avec les deux états
qui changent vraiment, publiée et brouillon.

#### Duplication d'une trame
La copie reprend le texte en vigueur dans un brouillon, sous un nom suffixé, et
n'hérite ni de la publication ni des numéros de version : une trame qui se
publierait elle-même serait utilisable pour un contrat que personne n'a relu.
Dupliquer une trame archivée la ravive, donc la copie démarre active.

#### L'identité du prestataire vient des réglages
Un onglet Comptabilité porte les douze champs que chaque contrat imprime :
dénomination, représentant, adresse, SIRET, code APE, mention de TVA,
coordonnées, et les coordonnées bancaires. Les trames les demandent par les
jetons `{{provider.*}}`.

Le bloc était tapé une fois par trame, donc cinq endroits à oublier le jour où
une banque ou une adresse change. Un réglage vide dont la trame a besoin refuse
le scellement, avant que la référence soit tirée, et le message parle des
réglages plutôt que d'un jeton inconnu : la personne qui le lit doit savoir où
aller.

### Modifié

#### Les cartes suivent la couleur du thème
Le corps de contrat porte la couleur d'accent du thème choisi, l'annexe reste
neutre. Deux teintes importées auraient été les seules couleurs de l'écran à
ignorer ce choix, et la paire dit quelque chose de vrai : un corps est le
contrat, une annexe s'y attache. Le type reste nommé dans la pastille, donc la
lecture ne dépend pas de la distinction des teintes.

#### Les dates du back-office passent sur AppDatePicker
Les deux champs date du module utilisaient l'entrée native. Celui de la page
publique de signature la garde, et c'est écrit dans le fichier : la roue du
téléphone bat n'importe quel sélecteur pour un inconnu qui signe.

### Dans aurora-client

Rien à répercuter, et aucune migration cette fois. Les douze réglages du
prestataire arrivent vides et se remplissent dans *Configuration >
Comptabilité*.

## [0.9.87] - 2026-09-09

### Ajouté

#### Les blancs qu'une trame laisse à un contrat
Les trames réelles portent une poignée de champs qui ne sont ni l'identité du
client ni du texte de trame : la personne habilitée à valider, un seuil
kilométrique, un taux d'acompte. Ils varient d'un contrat à l'autre, donc ils
n'ont leur place ni dans le gabarit ni sur la fiche client, et jusqu'ici ils
n'en avaient nulle part.

La trame les déclare en les utilisant. Écrire `{{contract.custom.acompte}}` dans
un article suffit : l'écran de préparation demande le champ dès que la trame est
choisie, et le scellement refuse de le laisser vide. Il n'y a pas de seconde
liste des champs attendus, qui divergerait de la rédaction dès la première
clause modifiée.

Le refus arrive avant que la référence soit tirée : un contrat refusé n'a rien
consommé, et la séquence n'a pas de trou à expliquer. Une chaîne vide compte
comme manquante, parce que sceller « un acompte de  % à la signature » ne
produit pas une erreur mais un document signé avec un trou dedans. La valeur est
insérée en texte, comme les autres, pour qu'un champ saisi à la main ne puisse
pas porter de balise dans un document à signer.

### Modifié

#### Une couleur par type de trame
L'écran des trames de contrat affichait des cartes identiques, le type écrit en
gris parmi le reste. Un corps de contrat porte maintenant un liseré et une
pastille bleus, une annexe des violets, et le type est nommé dans la pastille :
la couleur ne sert à rien si elle n'est pas légendée juste à côté.

Deux familles délibérément inutilisées ailleurs sur ces cartes : le vert et
l'ambre y disent déjà « publiée » et « brouillon », et les réemployer ferait
passer le type d'une trame pour un état.

#### Plus de tiret cadratin dans l'interface
L'écran des clients séparait le représentant de sa fonction par un cadratin. Il
est remplacé par un tiret simple, comme partout ailleurs.

### Dans aurora-client

Rien à répercuter : une migration, jouée par `make aurora-update`.

## [0.9.86] - 2026-09-08

### Ajouté

#### Un module de comptabilité, qui commence par des contrats signés en ligne
Le module se construit comme une publication : des trames versionnées, un
contrat monté depuis une trame, un lien public, une signature, un PDF. Trois
écrans dans le back-office (clients, trames, contrats), une seule page pour le
signataire, et rien à installer chez lui.

**Le client est une entité, rattachée ou pas à un compte.** Le bloc d'identité
qu'ouvre un contrat de prestation français : raison sociale, forme juridique,
capital, siège, SIRET, RCS, représentant. Le SIRET est validé par sa clé de
Luhn, avec la règle particulière de La Poste, et il est unique quand il est
renseigné.

**Une trame est versionnée, et une version publiée ne bouge plus.** La garde
est sur l'entité, pas dans le service : un chemin de code qui l'oublierait
n'existe pas. Modifier un texte publié se fait en ouvrant une nouvelle version,
amorcée depuis celle en vigueur. Un seul brouillon à la fois, tenu par un index
partiel en base.

**Un contrat est scellé avant de partir.** À l'envoi, le module fige d'un seul
tenant la référence, le texte rendu, les valeurs substituées et une empreinte
SHA-256 calculée sur une forme canonique dont le numéro est stocké avec elle.
Ce que lit le signataire est l'octet près ce que couvre l'empreinte, et
`aurora:contracts:verify` sait le dire pour tout le stock.

**Le lien public est un sélecteur plus un jeton haché.** Une fuite de base ne
permet pas de signer. Toutes les façons d'échouer rendent la même page. Rien
n'est indexable, et le lien ne porte jamais le document, seulement l'adresse.

**La signature est simple au sens de l'article 1367 du Code civil.** Identité
déclarée, dessin de la signature, consentement explicite, code à six chiffres
envoyé à l'adresse contractuelle, puis l'heure, l'IP et le code vérifié
conservés avec la signature. Le client signe le premier, le prestataire
contresigne et conclut : le document étant déjà scellé, aucune signature ne
peut être invalidée par une modification.

**Le PDF est généré une fois, à la contresignature.** Avec son propre SHA-256,
son bloc de preuve, et un refus net si on lui en demande un second : un
document régénéré serait celui que produit le moteur du jour. Il part en pièce
jointe du mail de conclusion.

**La langue du contrat pilote tout ce que voit le signataire** - la page, le
formulaire, les mails, le PDF - et une trame écrite en plusieurs langues doit
dire laquelle fait foi. La clause est rendue dans le document scellé, pas dans
l'habillage de la page : un encart de gabarit serait absent du PDF que le
client garde.

#### Une pièce jointe dans le service de mail
`MailService::send()` accepte des pièces jointes. Un fichier illisible est
ignoré avec un avertissement plutôt que fatal : l'envoi tourne dans la requête
qui enregistre une signature, et perdre la signature pour un PDF absent serait
le mauvais échange.

### Dans aurora-client

**1. Déclarer les deux limites de débit, avant de lancer `make aurora-update`.**
Les routes publiques de signature sont non authentifiées, et le contrôleur les
câble par nom : sans ces deux entrées le conteneur ne se construit pas, et le
`cache:clear` que la cible enchaîne échoue au milieu de la mise à jour. C'est
donc la seule chose de cette liste à poser d'abord :

```yaml
# config/packages/rate_limiter.yaml
framework:
    rate_limiter:
        contract_signature:
            policy: sliding_window
            limit: 10
            interval: '1 hour'
        contract_signature_code:
            policy: sliding_window
            limit: 15
            interval: '1 hour'
```

**2. Migrer.** Sept migrations arrivent avec le module : les clients, les
trames et leurs versions, les contrats, les liens publics, les signatures et
leurs codes, le PDF signé, la langue faisant foi.

```bash
php bin/console doctrine:migrations:migrate
```

**3. Rien à ajouter dans composer.json.** dompdf arrive avec le bundle.

**4. Vérifier les droits sur `var/uploads/`.** Les PDF signés sont écrits sous
`var/uploads/contracts/{année}/`, servis par une route gatée du back-office et
jamais par le catch-all des uploads.

## [0.9.85] - 2026-09-08

### Modifié

#### « Archive » ne veut plus dire deux choses
Le mot servait à deux notions sans rapport : le **statut** d'une publication
retirée du site, et la **page qui liste** les publications d'un type. Héritage
de WordPress, où toute page de liste automatique s'appelle une archive.

En français c'est franchement trompeur, « archive » disant plutôt « rangé,
plus d'actualité », alors que la page en question est la vitrine d'un type de
contenu. L'interface disait d'ailleurs déjà « page de liste » presque partout,
sauf à l'endroit qui compte le plus : la case qui la crée.

Le mot est désormais réservé au statut. Partout ailleurs on lit « page de
liste ». Les clés de traduction, les colonnes et les routes ne bougent pas :
renommer ce que le lecteur lit ne coûte rien, renommer ce sur quoi le code
s'appuie casserait les projets clients pour un synonyme.

## [0.9.84] - 2026-09-08

### Ajouté

#### Une page de liste peut être composée
Une page de liste dessinait un en-tête et une rangée de cartes, et on ne
pouvait rien poser d'autre dessus. Impossible d'y mettre une carte à gauche et
le texte qui la vend à droite : la mise en page appartenait au thème, pas à
l'auteur.

Elle emprunte déjà sa bannière et son résumé à une publication qu'elle désigne.
Elle emprunte maintenant aussi la grille de cette publication, rendue par le
même gabarit que sur la page de la publication elle-même : une zone se comporte
pareil des deux côtés.

Avec ça vient une case, **Lister les publications sous le contenu**, cochée par
défaut. Sur une archive d'articles la liste est le sujet et la grille n'est
qu'une introduction. Sur une page comme Services, où la grille place déjà
chaque publication, il faut la décocher : sinon chaque entrée s'affiche deux
fois, une fois arrangée et une fois en carte que personne n'a placée.

La case n'apparaît qu'une fois une publication désignée. Décocher sans rien
avoir composé au-dessus laisserait une page vide.

### Dans aurora-client
Une migration ajoute la colonne. `make deploy-prod` la joue.

Un thème qui a son propre gabarit d'archive ne dessinera ni la grille ni la
case tant qu'il ne lit pas `postType.grid` et `postType.showsList`. Attention
au test : `postType.showsList|default(true)` répond toujours vrai, le filtre
`default` de Twig se déclenche sur le vide et non sur l'absence. C'est
`postType.showsList is not defined or postType.showsList`.

## [0.9.83] - 2026-09-08

### Corrigé

#### Les tests pouvaient passer au vert en testant un autre dossier
Composer déduit l'emplacement du code du `__FILE__` de son propre autoloader,
et PHP résout ça à travers les liens symboliques. Un `vendor/` lié depuis un
autre checkout - le raccourci évident quand on monte un worktree git - fait
donc charger le `src/` de l'autre dossier. La suite tourne, elle est verte, et
elle a testé du code que personne n'a modifié.

C'est la pire réponse qu'un lancement de tests puisse donner : une mauvaise qui
a l'air bonne. Le démarrage de la suite vérifie maintenant que l'autoloader
pointe bien à l'intérieur du dossier depuis lequel on l'a lancée, et s'arrête
en le disant sinon.

Rien à changer dans un projet client : sur une installation normale la
vérification ne se voit pas.

## [0.9.82] - 2026-09-08

### Ajouté

#### Une zone peut porter un point d'ancrage
Un lien menait à une page, jamais à un endroit dans la page. Sur une page
longue, envoyer quelqu'un « voir la section Tarifs » voulait dire le déposer en
haut et le laisser chercher.

Chaque zone de la grille accepte maintenant un nom. La zone porte ce nom comme
identifiant, et n'importe quel lien du site suivi de `#ce-nom` descend
directement dessus : un bouton, une entrée de menu, une adresse écrite dans un
texte, un lien envoyé par mail.

Le nom est transformé en adresse à l'enregistrement, parce qu'il finit dans un
attribut puis dans une URL : « Où me trouver ? » devient `ou-me-trouver`, un
accent devient sa lettre simple plutôt que de se percenter dans chaque lien qui
pointe dessus. Il est unique dans la page, y compris à l'intérieur d'une pile :
deux zones répondant au même nom, c'est une page qui se comporte autrement
après qu'on ait déplacé l'une des deux, donc la seconde est numérotée.

Vide par défaut, et ça reste le cas normal : une zone n'a d'adresse que si
quelqu'un compte lui en donner une. L'éditeur montre sous le champ l'adresse
que le nom produit, puisque c'est ce qu'on va coller dans un bouton.

L'en-tête du site est fixe, donc la zone visée réserve la place de la barre :
sans ça la fonctionnalité marche et a l'air cassée, le bloc atterrissant sous
le bandeau.

## [0.9.81] - 2026-09-08

### Corrigé

#### Une zone en pleine largeur partait sur le côté
Mise en pleine largeur, une zone plus étroite que la ligne se décalait au lieu
de traverser l'écran. La bande est dessinée en repoussant une boîte large comme
l'écran de la moitié de son conteneur, et ça ne tombe au milieu que si ce
conteneur est la ligne entière : à deux tiers de large, la bande se centrait
sur le milieu de ces deux tiers.

La largeur n'est donc plus une question qu'une zone en pleine largeur a le
droit de poser : elle prend la ligne. L'éditeur cache d'ailleurs la largeur et
le décalage quand l'option est cochée, parce qu'un réglage dont la valeur est
ensuite écrasée est un réglage qui ment.

#### La liste des formulaires n'arrivait pas jusqu'à l'éditeur
Le sélecteur de formulaire d'une zone n'affichait que son texte d'attente, et
une zone qui désignait pourtant un formulaire s'affichait comme si elle n'en
désignait aucun : un navigateur retombe sur la première option quand la valeur
choisie ne correspond à aucune autre.

L'écran d'édition calculait la liste depuis toujours et le gabarit qui monte
l'éditeur ne la transmettait pas. Rien ne le signalait, le composant déclarant
une liste vide par défaut.

Le texte d'attente disait « Tous », emprunté aux filtres d'une liste
automatique où « tous » veut dire quelque chose. Pour un formulaire, non : il
dit maintenant « Choisir un formulaire ».

#### La moitié des zones n'avaient pas d'icône
La palette dessinait un pictogramme pour cinq types et un mot nu pour les sept
autres. La table des icônes vivait dans les deux composants qui la dessinent,
et les deux copies s'étaient arrêtées aux types qui existaient le jour où elle
a été écrite.

Elle vit maintenant à un seul endroit, complète, et un test la compare à la
liste des types : un type ajouté demain échoue tant qu'il n'a pas la sienne.

## [0.9.80] - 2026-09-08

### Corrigé

#### Les composants rendaient en français sur un site en espagnol
Une page en espagnol s'affichait en espagnol, sauf ce que dessine Vue : le
bouton d'un formulaire disait « Envoyer », la visionneuse et les commentaires
avec lui.

Twig lit les catalogues YAML, donc une langue ajoutée est traduite côté serveur
dès que ses fichiers existent. Les composants, non : on leur passe un paquet
assemblé à la main dans `i18n.js`, un import par langue. L'espagnol était bien
généré et jamais importé, alors vue-i18n retombait sur le français pour chaque
clé demandée par un composant.

L'espagnol y est. Les 144 clés du site public sont traduites ; le back-office
en espagnol reste en repli français, ce qui n'a pas changé.

Un test lit `i18n.js` et vérifie que chaque langue déclarée par l'application
figure dans le paquet servi au navigateur. C'était le trou : les deux moitiés
sont écrites dans deux langages et aucune n'importe l'autre, donc rien ne
signalait l'oubli.

### Ajouté

#### Les états vides du thème et les pages de partage en espagnol
Le titre des archives, le champ de recherche et les messages « aucun article »
du thème, plus les six phrases de la page qu'ouvre un lien de planning
partagé.

## [0.9.79] - 2026-09-08

### Ajouté

#### La vérification anti-robots protège aussi les formulaires
Elle ne gardait que les commentaires, et c'était l'inverse de ce qu'il fallait :
un commentaire attend un modérateur, alors qu'un envoi de formulaire est
validé, enregistré, envoyé par mail au propriétaire et poussé vers son webhook
dès qu'il arrive. Un formulaire est aussi la seule chose sur un site qu'un
robot trouve sans avoir de lien à suivre.

Le même réglage sert maintenant aux deux. Rien à activer en plus : un site qui
avait déjà configuré Turnstile ou reCAPTCHA voit ses formulaires protégés à la
mise à jour, et un site qui n'en a pas continue exactement comme avant.

Sur un formulaire en plusieurs étapes, la case n'apparaît qu'à la dernière :
répondue à l'étape une, elle aurait expiré pendant que le visiteur remplit
l'étape trois.

Le refus emprunte le message de la limite d'envois. Dire lequel des contrôles a
refusé, c'est indiquer au robot quoi changer.

### Modifié

#### La vérification anti-robots n'appartient plus aux commentaires
Le code vivait sous le module de commentaires, qui était son seul client. Il
remonte d'un cran, à `Editorial\Captcha`, puisqu'il en a deux. Les clés
enregistrées ne bougent pas : les lignes de réglages portaient déjà un nom de
module et non de fonctionnalité.

La fonction Twig s'appelle désormais `public_captcha()`. L'ancien nom,
`comment_captcha()`, continue de répondre : un thème est un fichier dans le
projet d'un client, et le renommer là-bas n'est pas quelque chose qu'une
version de ce paquet peut faire.

### Dans aurora-client
Un thème qui dessine un formulaire doit lui passer la configuration, comme le
fait le thème par défaut :

```twig
{{ vue_component('editorial/frontend/FormRender', {
    form: formData,
    submitPath: submitPath,
    captcha: public_captcha(),
}) }}
```

Sans cette ligne le formulaire s'affiche et s'envoie comme avant, mais sans
case et sans jeton : le serveur le refusera si la vérification est activée.

## [0.9.78] - 2026-09-08

### Ajouté

#### La couleur du thème dans la palette de l'éditeur
L'outil de couleur de texte proposait douze teintes fixes. Le vert de la liste
était un vert quelconque, pas celui de l'application : un titre mis en couleur
depuis l'éditeur ne pouvait donc jamais tomber juste, et il restait figé sur sa
valeur le jour où le thème changeait.

La palette s'ouvre maintenant sur **Accent**, qui est la couleur du thème
elle-même et non la teinte qu'elle vaut aujourd'hui. Un titre coloré ainsi suit
le thème quand on en change, et suit le lecteur entre le thème clair et le
thème sombre, où l'accent n'a pas la même intensité.

Le nettoyeur de contenu accepte cette valeur par son nom, et uniquement
celle-là : la fonction `var()` prend une valeur de repli, et laisser passer la
forme générale reviendrait à laisser écrire n'importe quoi dans un attribut de
style. Un test lit la palette de l'éditeur et vérifie que chacune de ses
couleurs survit au nettoyage, parce que les deux moitiés sont écrites dans deux
langages et qu'aucune n'importe l'autre.

## [0.9.77] - 2026-09-08

### Ajouté

#### Une zone Vidéo peut jouer un fichier de la médiathèque
Elle n'acceptait qu'une adresse YouTube, Vimeo ou Dailymotion. Le film d'un
client devait donc être publié chez un tiers avant de pouvoir apparaître sur
son propre site, ce qui n'est pas toujours souhaitable ni possible.

On choisit maintenant une vidéo dans la médiathèque, comme on choisit une
image, et le navigateur la lit. Les deux voies coexistent : une adresse va
chercher le lecteur du fournisseur, un document est joué sur place. La réponse
dépend du projet.

Le lecteur ne précharge rien et affiche l'image de couverture du document, s'il
en a une : une page de portfolio peut porter plusieurs films, et le visiteur en
regarde un. Le type du fichier est vérifié au moment du rendu et non à
l'enregistrement, parce qu'un document dont le fichier est remplacé après coup
laisserait sinon un lecteur pointé sur un PDF.

## [0.9.76] - 2026-09-08

### Corrigé

#### Une image haute sortait de l'écran dans la visionneuse
Ouverte en grand, une photo au format story dépassait l'écran par le haut et
par le bas : à 1280 × 720, elle commençait 582 pixels au-dessus du bord et
courait 618 en dessous.

La figure qui la contient est un élément flex sans borne basse, donc sa hauteur
venait de son contenu, et le `max-h-full` de l'image se calculait sur une boîte
qui avait déjà débordé. Elle est maintenant tenue par la ligne qui la porte.

La visionneuse gagne au passage une suite de tests qui mesure la position de
l'image dans la fenêtre, pour deux formats et deux tailles d'écran : ce qui
s'est cassé est une mise en page, et seule une vraie fenêtre la calcule.

## [0.9.75] - 2026-09-08

### Ajouté

#### Un texte d'en-tête peut porter son propre bouton
Un appel à l'action posé comme élément séparé tombe sur la ligne suivante de
la grille. Dans une bannière où une image haute occupe la même ligne, cela le
place une demi-photo plus bas que la phrase qui le justifie.

Un élément Texte affiche maintenant son bouton juste sous sa description,
quand il porte un libellé et une adresse. Les deux champs voyageaient déjà
avec chaque élément : c'est le costume qui ne les dessinait pas, si bien qu'on
pouvait remplir un libellé et ne rien voir.

Un libellé sans adresse ne dessine toujours rien : un bouton qui ne mène nulle
part est pire qu'absent.

## [0.9.74] - 2026-09-08

### Corrigé

#### Un bouton aligné à droite sortait à gauche
Le gabarit testait l'alignement contre le mot `end`, que rien n'écrit : le
vocabulaire du produit est `center`, `left` et `right`, celui-là même que la
zone Image lit correctement juste à côté. Un bouton aligné à droite retombait
donc sur la valeur par défaut, et le seul alignement qui fonctionnait était
celui qu'on n'a pas à choisir.

#### L'image de partage pesait le poids du fichier d'origine
La balise `og:image` pointait sur le fichier tel qu'il a été envoyé. Sur une
publication dont l'image à la une est un PNG de 1744 × 2700, cela fait 2,8 Mo
téléchargés par chaque robot qui rencontre le lien, pour une carte affichée à
environ 1200 pixels de large.

Rien sur la page n'était lent, donc rien ne le disait : le coût tombe sur
l'aperçu du lien, hors de vue. La balise sert maintenant la variante `large`,
plafonnée à 1920 pixels, et retombe sur l'original pour les documents qui n'ont
pas de variante.

## [0.9.73] - 2026-09-08

### Modifié

#### Une image seule sur un fond le remplit
Une zone Image posée sur une carte gardait la marge intérieure du fond, ce qui
mettait la photo au milieu d'un encadré au lieu de la faire remplir son cadre.
La marge existe pour que des mots ne touchent pas un bord : une image n'en a
pas.

Elle est donc retirée quand la zone est une image et qu'elle ne porte ni
légende ni crédit, et l'image cesse alors d'arrondir ses propres coins, que la
carte arrondit déjà. Avec une légende, la marge reste : les mots, eux, en ont
toujours besoin.

### Ajouté

#### Une carte d'offre affiche son pictogramme
Chaque entrée d'une liste peut porter une image, l'éditeur la propose depuis
toujours, et le costume « offres » était le seul à ne pas la dessiner : on
pouvait donc choisir une icône et ne rien voir. Elle s'affiche maintenant
au-dessus du titre, à une taille qui se lit comme une icône, sans recadrage.

## [0.9.72] - 2026-09-08

### Modifié

#### Masquer le crédit des photos demande une confirmation
La case reste cochée par défaut, et la décocher ouvre maintenant une fenêtre
qui dit ce que ça implique : les conditions d'usage de l'API Pexels demandent
de créditer le photographe pour les photos importées avec votre clé, alors que
la licence des photos, elle, n'impose pas l'attribution. Les deux phrases
comptent, et c'est le moment de les lire.

Le mécanisme est général plutôt que particulier à ce réglage : un champ à
cocher peut déclarer ce qu'il faut dire avant qu'on l'éteigne, et l'interrupteur
ne bouge pas tant que la question est ouverte. Une fenêtre qui modifie ce
qu'elle demande a déjà répondu à votre place. Rallumer retire la question.

Réservé aux réglages dont l'extinction est une décision et non une préférence :
une confirmation sur un interrupteur ordinaire est un clic qu'on apprend à
écarter, et c'est comme ça que celles qui comptent cessent d'être lues.

## [0.9.71] - 2026-09-07

### Ajouté

#### Le crédit des photos peut être masqué
Une photo importée depuis une banque d'images porte le nom de son
photographe, sous la figure ou dans le coin de la bannière. Une case
**Afficher le crédit des photos**, dans l'onglet Médias, permet de ne plus
l'afficher.

Le réglage est lu à un seul endroit, celui qui décide si un document a un
crédit, plutôt que dans les quatre gabarits qui le dessinent : l'honorer sur
la bannière et l'oublier sur la galerie est exactement la façon dont une page
finit à moitié créditée.

Allumé par défaut, parce que c'est ce que demandent les conditions d'usage de
l'API Pexels pour la clé qui a servi à télécharger la photo. L'éteindre est
une décision du propriétaire du site sur son propre compte, et la description
du réglage le dit.

## [0.9.70] - 2026-09-07

### Ajouté

#### Une vérification anti-robots sur le formulaire de commentaires
Un onglet **Anti-robots** dans les réglages, éteint par défaut, au choix
Cloudflare Turnstile (recommandé : pas d'énigme, pas de cookie publicitaire) ou
Google reCAPTCHA v3. Les clés sont celles du site, entrées dans son propre
back-office, et la clé secrète est chiffrée en base comme celle de Pexels.

Elle s'ajoute à ce qui existait déjà et ne le remplace pas : le piège à robots,
le filtre à liens, la limite de cinq envois par heure et la modération avant
parution. C'est pour cela qu'un service injoignable laisse passer le
commentaire, en le journalisant : une panne chez Cloudflare ne doit pas fermer
le formulaire d'un client que quatre autres protections gardent encore.

Le service reçoit l'adresse IP du visiteur. L'onglet le dit, parce que c'est au
propriétaire du site de décider et de l'écrire dans sa politique de
confidentialité.

#### Une entrée de menu peut déclarer la rubrique qu'elle coiffe
Le surlignage de la navigation suit l'adresse : une entrée qui pointe vers
`/fr/projets` reste allumée sur `/fr/projets/onyx`, parce qu'une adresse est
sous l'autre. Cela couvre une rubrique dont les publications vivent sous
l'entrée, et rien d'autre.

Une page sommaire est le cas qu'il manquait. `/fr/page/aurora` présente des
publications rangées à `/fr/aurora/…`, et les deux adresses n'ont aucun début
commun : suivre une carte depuis le sommaire éteignait donc la navigation, sur
une page qui est pourtant à l'intérieur de cette rubrique.

Un champ **Rubrique menée par cette entrée** le dit là où les adresses ne le
peuvent pas. Vide, le comportement est celui d'avant.

## [0.9.69] - 2026-09-07

### Ajouté

#### L'espagnol, troisième langue du produit
`es` rejoint `fr` et `en` : la langue est créée à l'installation, apparaît dans
le sélecteur du site public avec son drapeau et son nom, et chaque publication
gagne un onglet de plus.

Rien d'autre n'a eu besoin de changer pour l'accueillir, ce qui était le pari
de départ : le produit travaille sur une liste de langues, pas sur une paire.
Un seul test disait le contraire, et c'est le bon endroit pour qu'une
troisième langue se remarque.

Les textes vus par un visiteur sont traduits : le socle partagé, les
formulaires, les commentaires, les galeries, les pages de connexion et
d'inscription, et tous les e-mails. L'administration suit progressivement ;
d'ici là, une clé sans traduction espagnole s'affiche en français plutôt que de
laisser un trou, côté serveur comme côté interface.

### Démonstration

#### Le site de démonstration parle les trois langues
Les publications, les termes, les galeries, la bannière et les entrées de menu
ont leur version espagnole.

Au passage, le corps des publications de démonstration était écrit en français
quelle que soit la langue : une page anglaise portait donc un titre anglais
au-dessus d'un paragraphe français, ce qui est précisément l'erreur qu'une
démonstration multilingue est censée montrer qu'on évite.

## [0.9.68] - 2026-09-07

### Démonstration

#### La médiathèque de démonstration ne contenait que des PDF
Six images s'ajoutent aux documents : un visuel de campagne, une photo
d'équipe, un logo, une illustration, un plan et une capture. La moitié de ce
que fait cet écran ne se voyait pas sans elles : les tuiles, l'aperçu quand on
ouvre un document, les dimensions affichées, et les tailles générées à l'envoi.

Les documents de démonstration portent maintenant ces tailles, comme le ferait
un envoi par l'interface. Elles étaient vides, donc chaque page de démo servait
l'original en pleine taille à un téléphone : la seule chose que la médiathèque
promet était la seule que la démonstration ne faisait pas.

## [0.9.67] - 2026-09-07

### Modifié

#### Une liste automatique peut aller jusqu'à 24 publications
Le plafond était de douze. C'est assez pour une rangée de cartes au milieu
d'une page, et trop peu pour la forme où cette zone est la meilleure : une page
sommaire dont les cartes sont les rubriques du site. Au-delà de douze, les
suivantes disparaissaient sans que rien ne le dise.

Le nombre proposé dans l'éditeur et celui que le serveur conserve sont
désormais tenus par un test : ils étaient écrits deux fois, avec un commentaire
pour seul lien, et une divergence n'aurait fait échouer personne : le champ
aurait proposé douze choix pour un serveur qui en accepte vingt-quatre, ou
l'inverse, en rognant un nombre choisi au moment d'enregistrer.

### Corrigé

#### Les données de démonstration se dédoublaient à chaque `make demo`
Les étiquettes et les dossiers de la médiathèque étaient recréés à chaque
chargement, et rattachés aux mêmes documents : après cinq passages, un contrat
portait cinq fois « Confidentiel » et cinq fois « Signé ». Les documents, eux,
étaient retrouvés par leur chemin (qui contient le mois), donc un chargement
en septembre sur une base semée en août les insérait tous une seconde fois.

Les trois sont maintenant retrouvés par ce qui les identifie vraiment, et les
étiquettes d'un document sont réécrites plutôt qu'ajoutées : la démonstration
dit ce que le fixture décrit, quel que soit le nombre de passages.

#### `make demo` échouait sur une machine sans le dossier `test_files`
Ce dossier vit à côté du dépôt et n'est pas livré avec lui. Les images
manquantes étaient sautées, ce qui décalait les références publiées, et les
fixtures éditoriales mouraient trois fixtures plus loin sur
`ged_demo_media_0 does not exist`, une erreur qui nomme une image et pas le
dossier absent qui l'a causée.

Une image sans source est désormais dessinée : un aplat teinté par son propre
nom, visiblement un substitut et pas une photographie. `make demo` fonctionne
donc sur un clone neuf.

### Démonstration

#### Le site de démonstration ressemble enfin à un site
Sa page d'accueil est une page composée (bannière pleine largeur, fondu vers
le bas, puis le contenu en zones), et non plus la liste automatique de ses deux
articles, qui est l'écran de repli d'un site n'ayant pas choisi sa page
d'accueil.

Trois palettes complètes s'ajoutent à l'écran des thèmes, pour que basculer
d'un thème à l'autre montre quelque chose : une liste d'un seul élément ne se
compare à rien. Elles restent inactives, et la première n'est activée que si le
site est encore sur le thème d'installation sans couleurs.

S'ajoutent aussi un formulaire de devis avec ses huit types de champ et ses
demandes reçues, des commentaires dans les trois états de modération, des notes
Markdown reliées entre elles, et quatre champs personnalisés sur le type
Article. Chacun de ces écrans se présentait vide, ce qui montre où vit une
fonctionnalité sans rien montrer de ce qu'elle fait.

## [0.9.66] - 2026-09-07

### Corrigé

#### Une page de liste décrivait le site plutôt qu'elle-même
Elle empruntait l'en-tête d'une publication mais pas son résumé, et repartait
donc avec la description par défaut du site : la même phrase sur chaque page
d'archive, dans les moteurs de recherche comme dans un partage.

Elle emprunte maintenant les deux à la même publication, dans la langue lue,
et l'affiche aussi sous son titre quand la bannière est éteinte : sans cela, la
page aurait porté une phrase qu'elle ne montrait qu'aux moteurs, et aurait été
la seule du site dont le titre ne peut pas être suivi d'une ligne disant ce
qu'elle liste.
Sans rien de désigné, elle garde la description du site, ce qu'elle a toujours
eu. Les refus — rien de désigné, publication supprimée, en brouillon, muette
dans cette langue — sont désormais écrits une seule fois : le titre et le
résumé posaient la même question, et la poser deux fois est la façon dont deux
réponses finissent par diverger.

## [0.9.65] - 2026-09-07

### Ajouté

#### Un fondu au bas de l'en-tête
Une case **Fondu vers le bas** : le pied de la bannière se dissout dans la
page au lieu de s'arrêter net. Le dégradé va vers la couleur de fond du thème,
pas vers un noir supposé, et vaut aussi pour une bannière sans image — une
bande de couleur cesse d'être un bloc et devient le haut de la page.

Éteint par défaut : le bord franc est ce qu'ont toutes les bannières déjà
publiées.

### Corrigé

#### Le favicon portait l'initiale d'un autre produit
La route qui le dessine écrivait un « V » en dur, resté du premier nom du
produit — chaque site livré depuis portait donc une lettre étrangère dans son
onglet. Il prend maintenant l'initiale du nom du site, ce qui est la bonne
réponse pour un produit remis à des clients : personne n'a à y penser.

Au passage, le favicon **téléversé** dans l'écran Habillage était lu par un
seul gabarit — la galerie photo — et ignoré partout ailleurs. La route le sert
désormais quand il existe, et ne dessine la lettre qu'à défaut.

#### L'onglet Pexels n'accusait pas réception
Enregistrer n'affichait rien. Rien ne change visiblement sur cet écran quand
un enregistrement réussit — la clé revient sous la forme « une clé est
enregistrée », jamais elle-même — donc appuyer sur le bouton ressemblait à
n'appuyer sur rien. Il le dit maintenant, avec le même mot que les autres
écrans de réglages.

## [0.9.64] - 2026-09-07

### Corrigé

#### Le déploiement lançait ses commandes avec l'ancien conteneur
`make deploy-prod` installait le nouveau code, puis lançait les migrations et
les commandes de synchronisation, et ne reconstruisait le cache qu'après. Ces
commandes tournaient donc contre un conteneur compilé à la release précédente :
il suffit qu'un service ait gagné un argument de constructeur pour que la
première d'entre elles échoue, avant qu'une seule migration ne soit jouée.

C'est arrivé aujourd'hui, sur le correctif de la clé Pexels. Le cache est
maintenant vidé juste après l'installation des dépendances, et à nouveau après
la construction des assets — deux fois, volontairement, et le Makefile le dit
pour que personne ne prenne le premier pour un doublon.

### Dans aurora-client
Le gabarit du Makefile client porte la correction ; un projet existant doit la
répercuter dans son propre `Makefile`, cible `deploy-prod` : un `make cc-prod`
juste avant `doctrine:migrations:migrate`.

## [0.9.63] - 2026-09-07

### Corrigé

#### La clé Pexels était effacée à chaque déploiement
`aurora:application-parameter` tourne à chaque mise en production et supprime
les lignes de réglage qu'aucun fournisseur de paramètres ne réclame — c'est
ainsi qu'un réglage retiré du code cesse de traîner en base.

Or l'onglet Pexels écrit quatre lignes qui lui sont propres, et se tient
volontairement à l'écart de cette interface pour que la clé API ne soit jamais
dessinée par l'écran générique des réglages. Aucune énumération de paramètres
ne les nommait donc, et la synchronisation les supprimait toutes les quatre à
chaque release. La clé partait avec : l'intégration était configurée, elle
fonctionnait, et le déploiement suivant la vidait sans rien dire.

Les deux questions sont désormais posées séparément. Un fournisseur de
paramètres dit « dessine ce champ et garde cette ligne » ; un propriétaire de
réglages dit seulement « cette ligne est à moi, n'y touche pas ». La commande
ne crée rien et n'affiche rien depuis ces clés — elle cesse simplement de les
prendre pour des débris.

### Dans aurora-client
Rien à répercuter, mais **la clé Pexels est à ressaisir** une fois : celles qui
ont déjà été effacées ne peuvent pas être récupérées. Configuration → Pexels,
et elle survivra aux déploiements suivants.

## [0.9.62] - 2026-09-07

### Ajouté

#### Une page de liste porte enfin son propre nom
Le libellé d'un type nomme une publication — une publication *est* un Service —
et la page qui les liste toutes s'appelle Services. Elle affichait le libellé,
donc elle s'annonçait au singulier.

Un champ **Titre de la page de liste** sur le type de contenu, à côté de
« Possède une page d'archive » : c'est la même nature d'information, un fait
sur le type. Laissé vide, il reprend le libellé — aucune page existante ne
change.

#### Une page de liste peut emprunter l'en-tête d'une publication
Plutôt qu'une image posée sur le type de contenu. Une image seule ici aurait
été une deuxième bannière : le même nom que la vraie, aucun de ses réglages —
ni hauteur, ni assombrissement, ni alignement, ni boutons, ni textes par langue
— et réglée sur l'écran qui décrit la structure du site au lieu de l'éditeur où
se prennent toutes les autres décisions visuelles.

La page désigne donc une publication et reprend sa bannière entière, avec ses
réglages et ses mots dans chaque langue. Elle est refusée quand elle ne peut
pas être affichée : rien de désigné, publication supprimée, en brouillon, ou
muette dans la langue lue — et la page garde alors l'en-tête sobre qu'elle a
toujours eu.

À savoir : cette publication garde une adresse à elle, donc deux URL montrent
le même en-tête. « Ne pas indexer », dans son onglet Moteurs de recherche,
règle la question.

## [0.9.61] - 2026-09-07

### Ajouté

#### Deux nouvelles mises en forme pour une zone Liste
**Chronologie** : la même colonne que les étapes numérotées, mais c'est une
date qui se tient dans la marge plutôt qu'un numéro — ce qu'une histoire
demande.

**Cartes d'offres** : nom, prix, ce qui est compris (une ligne par élément,
chacune devient une puce) et un bouton. Une carte peut être marquée
**recommandée** : elle est dessinée plus fort, sans grandir — une carte qui
dépasse ses voisines casse la ligne à laquelle elle appartient.

Les deux réutilisent les quatre champs des autres costumes, donc changer de
mise en forme ne perd toujours rien de ce qui est écrit.

#### Numéros de ligne et bouton « copier » sur une zone Code
Les numéros sont une colonne à côté de l'extrait, jamais quelque chose inséré
dedans : le colorateur reste maître de ce qu'il y a dans le bloc, et une
chaîne de caractères qui court sur deux lignes ne peut pas être cassée. Ils ne
partent pas à la copie. Éteints par défaut — un exemple de trois lignes n'a pas
besoin de coordonnées.

Le bouton de copie est rendu masqué et révélé par son script une fois qu'il a
trouvé un presse-papiers : un bouton qui ne peut pas faire son travail ne
s'affiche pas.

#### Un sommaire automatique
Une zone **Sommaire** : la seule qui n'a rien à remplir. Elle lit les zones
Texte de la page et liste leurs titres, avec un lien vers chacun. Les ancres
sont posées au passage sur les titres eux-mêmes — un sommaire écrit à la main
est faux dès la première section renommée, celui-ci ne peut pas l'être.

Les ancres sont numérotées plutôt que déduites des mots : deux sections
« Tarifs » sur une page feraient deux ancres du même nom, et le second lien
mènerait à la première. Une page qui ne demande pas de sommaire garde
exactement le balisage qu'elle avait.

Posé dans une colonne plus étroite que la page, le sommaire suit la lecture ;
sur toute la largeur, il reste où il est.

#### Les images d'une grille s'agrandissent au clic
La visionneuse de la galerie sert désormais aussi aux zones Image d'une page,
avec sa propre file : une page peut avoir les deux, chacune avec son ordre. Le
clic ouvre, les flèches parcourent, Échap ferme. Sans JavaScript, l'image est
déjà entière sur la page — la visionneuse est le supplément, pas le contenu.

## [0.9.60] - 2026-09-07

### Corrigé

#### Le formulaire de commentaires n'indiquait pas ce qu'on attendait
Nom, e-mail et commentaire n'avaient qu'une étiquette. Une étiquette dit ce
qu'est le champ ; un exemple dit à quoi ressemble une réponse — et sur un
formulaire public, où personne ne s'entraîne, c'est la seconde qui manque le
plus.

Le test maison sur le sujet ne voyait que les champs bâtis avec les composants
de l'application, et ce formulaire-là est écrit à la main : il était invisible
pour lui. Il relit maintenant aussi les champs écrits directement, en laissant
de côté ceux où un exemple n'a pas de sens — cases à cocher, sélecteurs de
couleur, champs en lecture seule et le pot de miel anti-robots.

C'était le seul écran concerné du dépôt : le reste passait déjà.

## [0.9.59] - 2026-09-07

### Ajouté

#### Une Pile peut avoir un fond, donc un bandeau d'appel
Le fond arrivé avec la version précédente ne valait que pour une zone seule.
Une **Pile** — un titre, une phrase et un bouton empilés — n'en avait pas, ce
qui est précisément l'arrangement dont un appel à l'action est fait : sans
fond, les trois restaient trois zones que rien ne tenait ensemble.

Une Pile se pose maintenant sur une carte, un fond teinté ou l'accent, et peut
traverser l'écran comme n'importe quelle autre zone.

## [0.9.58] - 2026-09-07

### Ajouté

#### Un fond par zone
Chaque zone d'une grille peut maintenant être posée sur quelque chose :
**Carte** (encadrée, sur la surface du thème), **Teinté** (un fond sourd qui
regroupe) ou **Accent** (la couleur du thème, pour ce sur quoi on veut qu'on
clique). Rien par défaut — une zone reste ce qu'elle était.

C'est ce qui manquait pour qu'une page cesse d'être un long rouleau : dix zones
sur un même fond plat se suivent sans qu'aucune ne prenne le pas. Un fond, et
une suite de zones se lit comme des sections.

#### La pleine largeur pour toutes les zones
L'option ne concernait que les images ; elle vaut désormais pour n'importe
quelle zone, et devient surtout intéressante avec un fond : une bande qui
traverse l'écran.

Les mots, eux, restent centrés à la largeur de la page — un paragraphe à la
largeur d'un écran ne se lit pas. Une image continue d'aller d'un bord à
l'autre, puisqu'elle est le contenu et non son décor.

L'interrupteur a quitté les réglages de l'image pour rejoindre le fond, sous la
largeur : ces trois-là répondent à la même question.

## [0.9.57] - 2026-09-07

### Corrigé

#### Le numéro d'une étape touchait son titre
Dans une liste **Étapes numérotées**, la pastille chiffrée s'arrêtait à un
vingtième de rem du titre : les deux se lisaient comme une seule ligne, et la
pastille n'était pas non plus centrée sur le filet vertical.

Elle l'est maintenant, avec une vraie respiration entre le chiffre et les mots.

## [0.9.56] - 2026-09-06

### Corrigé

#### Les entrées d'une zone Liste repartaient sans leur identité
Suite du correctif précédent, et la vraie cause : l'éditeur ne recevait pas la
liste d'entrées telle qu'elle est rangée, mais la vue qu'en fait la page —
`{disposition, colonnes, entrées}`. Une clé, deux formes, un seul nom.

Il renvoyait donc cette vue en guise d'arrangement. Le serveur y lisait trois
entrées sans identifiant, leur en donnait trois nouveaux, et les mots — classés
sous les anciens — tombaient à côté. D'où trois entrées toujours vides,
quoi qu'on tape, et des identifiants qui changeaient à chaque enregistrement.

L'éditeur reçoit maintenant les entrées telles qu'il devra les rendre :
identité, ordre et image, les blanches comprises. La page, elle, garde sa vue
prête à lire. Une zone convertie en liste se voit aussi donner de quoi
accueillir sa première entrée.

## [0.9.55] - 2026-09-06

### Corrigé

#### Les entrées d'une zone Liste perdaient leurs mots à l'enregistrement
Titres, textes, questions, réponses, chiffres : tout se tapait normalement,
s'affichait normalement, et n'était plus là au rechargement de la page. Aucun
message, aucune erreur — les mots partaient simplement sans arriver.

Une liste d'entrées vide voyage en JSON comme `[]` : PHP n'a aucun moyen
d'écrire une table vide autrement. L'éditeur y rangeait ensuite les mots par
identifiant, ce qu'un tableau JavaScript accepte sans broncher et que
`JSON.stringify` jette au moment de partir. Ce que le serveur recevait était
donc une liste vide, et il enregistrait fidèlement le vide.

Les trois cartes de contenu par langue — bannière, grille, galerie — sont
désormais relues à l'arrivée, et une liste vide y redevient une table vide.

## [0.9.54] - 2026-09-06

### Corrigé

#### Les champs de la zone Code étaient invisibles
Choisir une zone **Code** dans une publication ouvrait un panneau sans langage
ni extrait : les deux champs manquaient, sans message, sans rien dans l'écran
pour dire pourquoi.

En cause, une phrase du catalogue : `function bonjour() { … }`, le texte
d'exemple du champ Extrait. Symfony y voit une phrase ; vue-i18n y voit une
variable nommée `…`, refuse de compiler le message, et le composant qui
l'affichait n'affiche plus rien du tout. Le même piège attendait le champ de
configuration du thème, dont l'exemple est un objet JSON.

Les accolades qui ne nomment rien sont désormais échappées à la génération du
catalogue JavaScript, comme l'étaient déjà les `@` des adresses e-mail. Un
auteur écrit la phrase — ou l'extrait — qui doit s'afficher, et n'a rien à
savoir de tout ceci.

## [0.9.53] - 2026-09-06

### Ajouté

#### Une zone Code, colorée
Un extrait, avec son langage choisi dans une liste : bash, CSS, HTML,
JavaScript, JSON, Markdown, PHP, Python, SQL, TypeScript, YAML.

Le serveur envoie un `<pre><code>` ordinaire, lisible tel quel ; la coloration
arrive ensuite, dans le navigateur. Un lecteur sans JavaScript garde donc
l'extrait entier dans un cadre à chasse fixe, ce qui est le comportement voulu
et non un repli. La bibliothèque — une cinquantaine de kilo-octets compressés
— n'est téléchargée que si la page contient réellement un extrait ; les autres
paient un `querySelector` et rien de plus.

Le colorateur existait déjà, pour l'aperçu Markdown du back-office. Sa liste
de langages a quitté le module Notes pour un module partagé : deux copies,
c'était un langage ajouté pour un lecteur et manquant pour un rédacteur.

#### Trois mises en forme pour une zone Texte
**Normal**, **Chapô** — le premier paragraphe grossit et s'éclaircit, ce qu'est
une accroche — et **Mentions**, plus petit et plus discret.

#### Une image peut sortir de sa colonne
Une case « Pleine largeur » sur une zone Image : la figure quitte la colonne
de l'article et traverse l'écran. Sans `100vw`, qui compte la barre de
défilement et offre à la page une barre horizontale en prime. L'option ne
s'affiche pas dans une pile, où il n'y a pas de colonne à quitter.

## [0.9.52] - 2026-09-06

### Ajouté

#### Un formulaire peut être posé dans n'importe quelle page
Un formulaire avait sa page à lui, à `/{langue}/forms/{slug}`, et c'était le
seul endroit où on pouvait le remplir. La nouvelle zone **Formulaire** le pose
au bas d'une page de service — là où quelqu'un qui vient de lire ce que vous
proposez est disposé à le remplir, plutôt qu'après un lien qui lui demande
d'aller ailleurs d'abord.

C'est le même formulaire, pas une copie : la zone monte le composant que sa
propre page monte et envoie vers la même adresse. La validation, la limite de
débit et le message de confirmation restent une seule implémentation.

Un formulaire désactivé n'est pas proposé dans l'éditeur et ne se dessine pas
s'il l'était : sa page répond déjà 404, et une zone ne doit pas être un moyen
de contourner ça.

## [0.9.51] - 2026-09-06

### Ajouté

#### Une zone qui liste des publications toute seule
La zone **Publication** nomme une publication et ne change jamais d'avis. La
nouvelle zone **Liste automatique** pose une question — les plus récentes de
ce type, classées sous ce terme, tant d'entre elles — et y répond à chaque
affichage.

C'est la différence entre une page qu'il faut rouvrir chaque fois qu'on publie
et une page qui se tient à jour seule. Les deux filtres sont facultatifs et se
combinent : un type seul, un terme seul, les deux, ou aucun pour tout le site.

Une publication qui liste ses voisines ne se propose pas elle-même.

#### Trois densités pour les cartes de publication
**Complète** garde l'image, le surtitre et le résumé. **Compacte** ne garde
que le titre et la flèche, pour une colonne dense. **Horizontale** pose
l'image à côté du texte plutôt qu'au-dessus.

Le réglage vaut pour la zone Publication comme pour la liste automatique :
les deux dessinent la même carte, donc elles offrent les mêmes densités.

### Dans aurora-client

Rien à répercuter. `GridViewBuilder::build()` accepte un quatrième argument
facultatif — la publication qui affiche la grille, pour qu'une liste ne
s'inclue pas elle-même. Un appel existant continue de fonctionner sans le
passer, et la liste se contentera alors de ne rien exclure.

## [0.9.50] - 2026-09-06

### Ajouté

#### Trois nouveaux types de zone, dont un qui en vaut cinq
La grille de contenu passe de cinq types à huit.

**Bouton.** Un libellé, une adresse, et de quoi le dessiner : plein, contour
ou discret, en trois tailles, aligné à gauche, au centre ou à droite. Les
boutons n'existaient jusqu'ici que dans la bannière, donc au-dessus de
l'article et nulle part ailleurs — une page qui disait ce qu'elle propose se
terminait sans moyen d'agir.

**Séparateur.** Un filet ou simplement de l'espace, en trois hauteurs. Ça
paraît dérisoire, c'est ce qui permet de faire respirer une page sans
bricoler.

**Liste**, et c'est celle qui compte : une même zone dessinée de cinq façons.
*Étapes numérotées* pour un processus, *chiffres clés*, *questions
fréquentes* en accordéon, *témoignages* avec portrait et rôle, *logos* en
bandeau. Cinq types de zone auraient été cinq normaliseurs, cinq panneaux et
cinq gabarits à maintenir en phase ; une zone avec une présentation, c'est un
seul de chaque, et le rédacteur choisit dans une liste courte au lieu de
chercher dans un menu plus long.

Les quatre champs d'une entrée sont les mêmes pour les cinq présentations,
parce que ce sont les mêmes questions posées autrement : le titre d'une étape
est la valeur d'un chiffre est une question est l'auteur d'une citation. Le
panneau les renomme selon la présentation choisie, et **changer de
présentation ne perd donc rien de ce qui est écrit**.

L'accordéon utilise `<details>` plutôt qu'un script : il s'ouvre sans
JavaScript, il est annoncé correctement, et la recherche du navigateur trouve
le texte d'une réponse fermée.

Comme partout ailleurs, la disposition est partagée par toutes les langues et
les mots sont par langue : ajouter une entrée en français l'ajoute en anglais,
vide. Une entrée retirée emporte ses mots plutôt que de les laisser traîner
dans chaque traduction.

### Dans aurora-client

Rien à répercuter. Un thème client qui a sa propre copie de
`_grid_zone.html.twig` ignorera les trois nouveaux types jusqu'à ce qu'il les
gère ; les zones existantes ne bougent pas.

## [0.9.49] - 2026-09-06

### Ajouté

#### L'entrée de menu de la page courante est mise en évidence
Sur « À propos », l'entrée « À propos » du menu s'affiche désormais active :
texte plein et soulignement à la couleur d'accent. Automatiquement, sans rien
configurer, et pour tous les emplacements de menu — en-tête, pied de page,
compte.

Deux états sont calculés, parce qu'un menu répond à deux questions
différentes. **La page courante** est l'entrée exacte, et elle seule porte
`aria-current="page"` : cet attribut ne vaut que s'il désigne une destination
unique. **La branche courante** est plus large et c'est elle qui pilote le
style : une entrée qui pointe vers `/projets` reste allumée pendant qu'on lit
`/projets/onyx`, sinon la navigation s'éteint dès qu'un lecteur entre dans une
section. Un menu déroulant hérite de l'état de ses enfants.

Trois pièges classiques sont évités et couverts par les tests : le lien
d'accueil n'est pas l'ancêtre de tout le site, `/projets` ne revendique pas
`/projets-secrets`, et une barre oblique finale ou un paramètre d'URL ne
changent pas de page.

### Dans aurora-client

Rien à répercuter. Un thème client qui a sa propre copie de
`partials/menu.html.twig` continue de fonctionner sans mise en évidence ; pour
en profiter, lire `item.isActive` pour le style et `item.isCurrent` pour
`aria-current`.

## [0.9.48] - 2026-09-06

### Corrigé

#### L'icône Malt des liens sociaux était minuscule
Son tracé occupe un coin du carré de 24 dans lequel il a été dessiné — le
reste servait au mot-symbole, qui n'a pas été repris. Le cadrage suit
maintenant le dessin.

Les colonnes de la rangée passent aussi de 240 px au minimum au lieu de 210,
pour qu'une adresse e-mail complète tienne sur une ligne, et le libellé de
chaque lien s'écrit plus petit et moins gras : c'est une étiquette, pas un
titre.

## [0.9.47] - 2026-09-06

### Ajouté

#### Tailwind est utilisable depuis l'éditeur
Écrire `class="grid md:grid-cols-3 gap-4"` dans un bloc HTML de l'éditeur ne
produisait rien. Ce n'était pas le nettoyeur de contenu — il laisse passer
`class` depuis toujours — mais Tailwind lui-même : il ne génère que les classes
qu'il trouve en scannant les fichiers source, et celles qu'un rédacteur écrit
vivent en base de données, que le scanner ne lit jamais.

Une liste blanche déclarée dans `app.css` rend désormais disponibles les
utilitaires courants : disposition, grille, espacements, dimensions,
typographie, bordures, arrondis, ombres, transitions et états au survol, avec
les préfixes responsive là où ils servent. Pour les couleurs, seuls les jetons
du thème sont offerts — un contenu ne peut donc pas sortir de la charte du
site en écrivant une couleur au hasard.

Le coût est mesuré : **+60 Ko de CSS brut, soit +7,4 Ko compressés**, ce que
tout visiteur télécharge désormais. La liste est volontairement close ; toute
addition se pèse.

#### Une rangée de liens sociaux qui a un état au survol
`.aurora-social-grid` et `.aurora-social-link`, avec une classe par réseau
portant sa couleur de marque et son icône : LinkedIn, GitHub, Malt, Upwork,
Instagram, Facebook, e-mail. Au survol, la carte se soulève, sa bordure prend
la couleur du réseau et la pastille d'icône s'allume.

Ça existe parce que le nettoyeur supprime `<style>` : une rangée de liens
écrite dans l'éditeur était condamnée aux styles en ligne, qui ne savent pas
exprimer un survol. Les icônes sont des masques CSS plutôt que des images,
donc la même icône sert sur le fond sombre comme sur l'état survolé.

### Dans aurora-client

Rien à répercuter. Les classes sont disponibles dès la mise à jour ; un thème
client qui redéfinit ses propres jetons de couleur les voit s'appliquer sans
rien changer.

## [0.9.46] - 2026-09-06

### Corrigé

#### Une page pouvait rester sans style après un déploiement
Certaines pages revenaient chez le visiteur en texte brut, sans aucune mise en
forme, et seul un rechargement forcé les réparait. C'est arrivé sur la page
« À propos » d'app.axelraboit.fr, à répétition.

La cause : une page qui n'a pas changé répond « rien de neuf » au navigateur,
qui réaffiche alors sa copie. Sauf qu'une page, ce n'est pas seulement son
contenu — c'est aussi le nom des fichiers de style qu'elle réclame, et ces
noms changent à chaque construction des assets. La copie conservée par le
visiteur restait donc jugée valable tout en pointant vers des fichiers que le
déploiement venait de supprimer.

Une page est désormais considérée à jour seulement si ni son contenu ni les
assets n'ont bougé depuis. Un déploiement rafraîchit tout le monde, une page
inchangée continue d'économiser un rendu.

### Modifié

#### Les cartes de publication ont été retravaillées
Le terme de la publication s'affiche au-dessus du titre plutôt qu'en pastille
en bas, une flèche apparaît au survol — rien n'indiquait jusqu'ici qu'une
carte était cliquable — et la carte se soulève avec un filet à la couleur
d'accent, là où seule la bordure changeait de gris. L'image adopte un rapport
fixe de 16/10 et s'agrandit légèrement au survol, ce qui aligne les cartes
d'une même ligne quelles que soient les photos.

Le listing et les zones « Publication » d'une grille dessinaient chacun leur
copie de cette carte, et les deux avaient déjà divergé. Elles partagent
maintenant `_post_card.html.twig`.

#### Le texte d'une bannière posée sur une photo reste lisible
Une ombre portée est appliquée au titre et à la description quand il y a une
photo derrière, et seulement dans ce cas. Monter l'assombrissement assez haut
pour la zone claire que le texte traverse aplatissait toute l'image.

#### La barre de navigation suit le défilement
Elle reste en haut, translucide et floutée là où le navigateur le permet.

#### Le titre d'une page de listing a la place qui lui revient
Il avait le poids d'un titre de carte sur une page dont c'est le seul propos,
et la grille démarrait sous une simple ligne de texte. Il passe en grand, avec
un filet en dessous.

### Dans aurora-client

Rien à répercuter. Un thème client qui surcharge `_posts.html.twig` ou
`_grid_zone.html.twig` continue de fonctionner ; pour bénéficier de la
nouvelle carte, inclure `_post_card.html.twig` plutôt que recopier son
balisage.

## [0.9.45] - 2026-09-06

### Ajouté

#### Un écran de réglages pour la banque de photos Pexels
Configuration › Pexels. L'intégration y est **désactivée par défaut** et ne
peut être activée qu'après avoir coché une case d'acceptation des conditions
d'utilisation, avec les liens vers ces conditions et vers la licence. La date
de l'acceptation et l'adresse de la personne qui l'a donnée sont enregistrées
en base et réaffichées dans l'onglet ; retirer l'acceptation coupe
l'intégration dans la même opération.

L'onglet explique aussi, en trois étapes, comment obtenir la clé : créer un
compte Pexels, la demander sur leur page API, la coller ici. C'est écrit pour
quelqu'un qui n'a jamais entendu parler de Pexels.

La clé d'API est saisie là plutôt que dans `.env`, et stockée chiffrée avec le
service de chiffrement d'Aurora. Elle n'est jamais renvoyée au navigateur :
l'écran sait seulement qu'une clé existe. Laisser le champ vide conserve celle
déjà enregistrée.

Cette organisation existe parce qu'Aurora est livré à des clients : le compte
Pexels est celui du client, et ce sont ses conditions qu'il doit accepter
lui-même, sur son propre site, avec sa propre clé.

### Modifié

#### Les photos Pexels sont téléchargées, plus seulement pointées
Une photo importée est désormais rapatriée dans la médiathèque comme
n'importe quel fichier : elle est stockée, ses vignettes sont générées ici, et
la page qui l'affiche ne fait plus aucune requête vers un tiers.

La licence Pexels accorde le droit de télécharger et de conserver l'image, de
façon irrévocable, à celui qui la télécharge. Un site construit ainsi détient
donc ses propres droits sur ses propres images : il continue de fonctionner si
la clé est révoquée ou si la photo est retirée de Pexels, et il n'envoie plus
l'adresse IP de ses visiteurs à un service tiers. Unsplash, lui, interdisait le
réhébergement, ce qui avait imposé le fonctionnement précédent.

Conséquence : toute la machinerie qui servait un document distant disparaît.
`DocumentInterface::isRemote()` est retiré, ainsi que la redirection vers le
CDN et les variantes calculées en largeur. Les colonnes `source_url`,
`attribution_name` et `attribution_url` restent : elles disent d'où vient la
photo et de qui elle est, et c'est d'elles que le crédit est rendu. Le crédit
s'appuie maintenant sur la présence d'une attribution, et non plus sur le fait
que le fichier soit distant.

### Dans aurora-client

Retirer `PEXELS_API_KEY` de `.env.local` s'il y a été ajouté : la clé se
renseigne maintenant dans Configuration › Pexels. Aucune migration. Les sites
existants n'ont rien à reprendre : aucun document distant n'a jamais été créé.

## [0.9.44] - 2026-09-06

### Modifié

#### Les photos viennent de Pexels et non plus d'Unsplash
L'onglet du sélecteur d'images s'appelle maintenant « Pexels » et interroge
leur API. Tout le reste se comporte pareil : on tape une recherche, on choisit
une photo, elle arrive dans la médiathèque comme n'importe quel autre média,
sans être téléchargée — `filePath` reste vide et `sourceUrl` porte l'adresse.

Trois différences tiennent au fournisseur. La clé s'appelle `PEXELS_API_KEY`
et se demande sur https://www.pexels.com/api/ ; le quota gratuit est de 200
requêtes par heure et 20 000 par mois, là où Unsplash s'arrêtait à 50. Il n'y
a plus d'appel de suivi à leur envoyer à chaque sélection : Pexels n'en
demande pas. Le crédit affiché sous la photo porte désormais deux liens, le
photographe et Pexels, ce que leurs recommandations réclament.

Les vignettes servies par le CDN sont demandées en largeur seule. Les tailles
toutes faites de Pexels arrivent recadrées dans une boîte fixe — leur « large »
fait 940×650 quelle que soit la photo — donc c'est l'original qui est
enregistré, et la hauteur et le ratio de pixels qui recadreraient sont retirés
de l'adresse au moment du rendu.

Rien à reprendre dans les contenus déjà publiés : la clé Unsplash n'ayant
jamais été renseignée, aucun document distant n'existe en base.

### Dans aurora-client

Remplacer `UNSPLASH_ACCESS_KEY` par `PEXELS_API_KEY` dans `.env.local`, en
local comme sur le serveur. Laissée vide, l'intégration reste inactive et le
reste de la GED fonctionne comme avant. Aucune migration.

## [0.9.43] - 2026-09-06

### Ajouté

#### Chercher une photo Unsplash depuis le sélecteur d'images
Le sélecteur d'images du back-office a un second onglet, « Unsplash », partout
où un champ demande une image : vignette, bannière, zones image de la grille,
galerie. On tape une recherche, on choisit une photo, elle arrive dans la
médiathèque comme n'importe quel autre média.

La photo n'est pas téléchargée, et ce n'est pas un raccourci : les conditions
d'utilisation d'Unsplash interdisent de réhéberger ce que leur API renvoie.
Un document peut donc désormais pointer vers une image distante — `filePath`
reste vide, `sourceUrl` porte l'adresse. Les tailles responsives sont demandées
au CDN d'Unsplash (256, 800, 1920 px) plutôt que générées ici, donc le rendu
reste identique côté page.

Deux obligations de leur licence sont tenues automatiquement : le crédit du
photographe s'affiche sous la photo partout où elle est rendue, avec les liens
`utm` qu'ils demandent, et chaque sélection leur est signalée.

Sans clé API, l'onglet reste visible mais annonce simplement qu'il n'est pas
configuré. Rien d'autre ne change.

### Dans aurora-client

Renseigner `UNSPLASH_ACCESS_KEY` dans `.env.local` pour activer l'onglet — la
clé s'obtient sur https://unsplash.com/oauth/applications. Laissée vide,
l'intégration reste inactive et le reste de la GED fonctionne comme avant. Une
migration ajoute trois colonnes nullables à `core_ged_documents`.

## [0.9.42] - 2026-09-06

### Corrigé

#### La zone "Publication" de la grille ne proposait jamais aucune publication
Le champ « Publication à afficher » restait vide dans tous les cas : le
backend gérait déjà entièrement les publications liées (relation, sauvegarde,
sérialisation), mais aucun écran de l'éditeur ne permettait de choisir quelles
publications lier. L'onglet Paramétrage propose maintenant une recherche pour
retrouver une publication par son titre et l'ajouter aux publications liées de
celle en cours d'édition, avec la liste de celles déjà liées et un moyen de les
retirer.

## [0.9.41] - 2026-09-06

### Corrigé

#### Le logo du sidemenu affichait la lettre "V"
Reliquat du tout premier nom du projet, **Velox**, renommé en Aurora depuis
longtemps. Le logo par défaut du menu latéral (`AppLogo.vue`) était un SVG
généré à la main avec la lettre codée en dur — tout le reste de l'application
avait suivi le renommage, sauf ce texte. Il affiche maintenant "A".

## [0.9.40] - 2026-09-01

### Ajouté

#### Un utilisateur a une adresse
`/backend/platform/users/42` ouvre la liste sur cet utilisateur. Il était une
**modale au-dessus d'un tableau** et rien d'autre : impossible à envoyer à un
collègue, absent du fil d'Ariane, invisible à la palette. C'est le même manque
que les onglets de réglages avaient avant la 0.9.29.

L'adresse existait déjà comme point d'API pour les appels de la page ; elle
répond maintenant aux deux, JSON pour la page et page entière pour qui arrive
par un lien, distingués par `X-Requested-With`. La barre d'adresse suit
l'ouverture et la fermeture de la modale, en `replace` et non en `push` : ouvrir
une modale n'est pas une page où revenir en arrière.

**La liste ne redirige pas** vers un premier utilisateur, contrairement à celles
d'Editorial. Un tableau qu'on parcourt et qu'on filtre est une destination en
soi ; l'enregistrement est ce qu'on en ouvre.

## [0.9.39] - 2026-09-01

### Ajouté

#### Les calendriers sont dans le menu, et la grille récupère sa place
Sixième module à passer dans la nouvelle colonne, et celui que mon inventaire
avait raté : sa navigation n'était pas une balise `<aside>` mais un composant,
donc elle est passée au travers du balayage.

Son propre code plaidait pour ce déplacement mieux que moi. La liste était une
colonne de 13 rem à côté de la grille, ce qui coûtait 224 pixels sur une semaine
de sept jours — **32 pixels par jour, soit un titre d'événement entier**. Elle
avait donc été remontée en barre horizontale, ce qui rendait la largeur et
prenait une rangée de hauteur. La colonne du menu est déjà à l'écran : elle ne
coûte ni l'une ni l'autre, et la rangée est rendue.

- **Un rendu au lieu de deux.** `CalendarBar` et `CalendarSidebar` avaient
  exactement les mêmes props et les mêmes émissions — deux dessins du même
  contrat, à tenir d'accord à la main. La barre est supprimée ; le panneau
  réutilise la colonne, qui était déjà écrite.
- La feuille mobile propre à Planning disparaît aussi : le menu a son tiroir.
- La page répond aux sept intentions du panneau avec les gestionnaires que la
  barre appelait déjà, et lui annonce son état — aucun endpoint ne pourrait le
  servir, les compteurs dépendant de la plage que la grille affiche.

### Corrigé

#### Les boutons d'une ligne de l'arbre des notes rechargeaient la page
Le « + » qui ajoute une sous-note suivait le lien de la ligne au lieu de créer
quoi que ce soit, et la suppression ouvrait sa modale sans jamais aboutir. Même
cause : j'avais mis les boutons **à l'intérieur** du lien. Du contenu interactif
dans un `<a>` est du HTML invalide, et leur `.stop` empêchait la ligne d'annuler
la navigation — donc le navigateur partait.

La ligne redevient un `div` — cible de dépôt et poignée de glissement — et seul
le titre est un lien. Les boutons sont à côté, pas dedans.

#### Le panneau des notes avait perdu trois fonctions
Créer une sous-note, supprimer, et tout le glisser-déposer. En déportant l'arbre
j'ai réécrit la ligne à la main alors qu'il existait déjà un composant,
`NoteTreeItem`, qui portait les trois. Un `v-for` écrit à la main n'a que ce
qu'on pense à lui donner.

Le panneau utilise ce composant et **transmet ses huit événements à la page**,
qui a déjà tous les gestionnaires : la page est toujours montée quand le panneau
s'affiche, Notes n'ayant qu'une destination.

#### Une note créée dans l'éditeur n'apparaissait qu'après rechargement
Le pont ne parlait que dans un sens. Le socle gagne l'autre — `tellPanels` et
`onPageNotice` — et l'éditeur annonce sa liste à chaque changement.

### Modifié

#### Une ligne de dossier est un composant, comme une ligne de note
`FolderTreeRow`, extrait des cent quarante-sept lignes de markup que le panneau
GED tenait dans un `v-for`. C'est la forme qui a coûté ses trois fonctions à
Notes : rien n'y dit ce qu'une ligne est censée porter. Le contrat est
maintenant la liste des props et des événements, et six tests l'épinglent.

#### Une intention du pont dit sa direction
`changed` désignait **deux directions opposées** selon le module : le panneau
vers la page côté GED, la page vers le panneau côté Notes. Un nom est désormais
`<module>:<verbe>` et dit ce que l'émetteur veut ; `changed` est réservé aux
annonces de la page vers ses panneaux. `ged:folder` devient `ged:select`,
`ged:folders-changed` devient `ged:reload`.

### Ajouté

#### La vue de module de Notes a enfin un test
Le seul des cinq modules sans. Rien n'affirmait qu'il déclare une vue, ni qu'il
cesse de le faire quand on l'éteint. Le test de Configuration rejoint au passage
le dossier où vivent les quatre autres.

## [0.9.38] - 2026-09-01

### Modifié

#### Les entrées d'enregistrement disent quelque chose, plus un slug
La seconde ligne montrait `category`, `tag`, `primary` — le slug, c'est-à-dire un
jeton technique juste sous « Modérer les commentaires des lecteurs et écarter les
indésirables ». Une seconde ligne doit valoir la place qu'elle prend.

Chaque entrée porte désormais la **description de l'enregistrement**, la même
phrase que la page affiche : « Affichée dans l'en-tête, sur toutes les pages du
site public » pour un menu. Quand il n'y en a pas, elle dit ce que
l'enregistrement contient — « douze termes », « aucune publication » — ce qui est
un fait et non une phrase, mais reste une information.

Un type de contenu **n'a aucun champ description** : c'est le seul des quatre
dans ce cas, et il tombe donc toujours sur le compte. Lui en donner un est une
migration, pas un changement de menu.

#### Un type de contenu peut enfin dire à quoi il sert
Il était le seul des quatre familles d'Editorial sans champ description, donc le
seul dont l'entrée de menu ne pouvait afficher qu'un compte là où les autres
montrent une phrase. La colonne est nullable : tous les types existants sont
antérieurs, et exiger une phrase aurait obligé à en inventer une pour chacun au
passage de la migration. Un champ apparaît dans les formulaires de création et
de modification.

### Modifié (suite)

#### Trois tests de la grille de publication cherchaient leur cible trop tôt
`drag` envoie l'événement directement sur un élément, et ces trois-là prenaient
la poignée **avant** le `dragstart` — donc une poignée vers ce que la grille
affichait avant de savoir qu'un glissement commençait. Le surlignage re-rend ces
nœuds ; le dépôt atterrissait alors sur un élément détaché et n'émettait rien.
C'est ce qui faisait échouer le fichier de temps en temps et passer au coup
suivant.

La cible est cherchée après le début du glissement maintenant, et un rendu est
attendu entre chaque étape. Je n'ai jamais réussi à reproduire l'échec — huit
exécutions propres avant, six après — donc ceci supprime une vraie classe de
course sans qu'on puisse affirmer que c'était celle-là.

### Documenté

#### Pourquoi les deux vues du menu se répètent
La question revenait à chaque module. Les deux vues sont **exclusives** : la
colonne montre l'une ou l'autre. Une vue de module qui n'afficherait que ce que
le menu projet ne montre pas laisserait le lecteur bloqué — dans la GED, sans
ligne « Étiquettes », le seul chemin vers les étiquettes serait de ressortir. La
répétition est donc structurelle.

Reste à savoir si le menu **projet** devrait se réduire à une ligne par module,
maintenant que les huit ont une vue. Non : ces lignes sont le chemin en un clic
vers une destination depuis n'importe où ailleurs, et les replier mettrait tout
saut inter-module derrière deux clics pour supprimer une duplication qui ne
coûte rien — les deux listes sont construites par les mêmes fabriques de
`NavItem` dans chaque module, donc elles ne peuvent pas diverger comme deux
copies tenues à la main. C'est écrit dans `ModuleNavView` pour qu'on cesse d'en
débattre.

### Supprimé

#### Le pont `/backend/ged/folders`
Livré en 0.9.35 avec une échéance de deux versions, retiré à la version dite.
L'adresse renvoie maintenant un 404 ; les cinq points d'écriture sous le même
préfixe restent, c'est eux que le panneau appelle.

## [0.9.37] - 2026-09-01

### Ajouté

#### Les types de contenu, taxonomies, menus et formulaires sont des pages
Chacun a désormais **son adresse** — `/backend/editorial/post-types/3` — donc il
se partage, porte un fil d'Ariane et se trouve dans la palette. Ils vivaient
jusqu'ici dans une `selectedId` locale, sans URL : le même argument que pour les
onglets de réglages en 0.9.29.

Le menu latéral en liste un par enregistrement, sous un intitulé de famille, et
la colonne de boutons qui servait à en choisir un a quitté les trois pages.

- Une adresse nue (`/post-types`) **redirige** vers le premier enregistrement,
  au lieu d'afficher ce que `/post-types/3` affiche déjà. Deux adresses pour une
  seule vue, c'est une de trop — même arbitrage qu'en 0.9.29.
- La ligne générique disparaît de la vue de module : c'est l'intitulé du groupe
  qui nomme la famille, comme pour les réglages. Le menu projet garde la sienne,
  qui est le chemin d'entrée.
- Le bouton « créer » reste sur la page : un intitulé de groupe n'a nulle part
  où en mettre un, et c'est le seul chemin pour ajouter le suivant.
- `/{id}` porte une contrainte de chiffres. Sur les menus elle n'est pas
  théorique : `/menus/targets` est une route GET littérale du même contrôleur,
  que `/{id}` aurait sinon interceptée.

#### Les notes sont dans le menu, avec leur arbre, leur recherche et leurs étiquettes
La plus large des six barres latérales, et celle pour laquelle le panneau avait
été inventé : neuf cents notes ne peuvent pas être neuf cents entrées de menu
comme le sont trois types de contenu, et ce qui va par-dessus la liste — un
champ de recherche, un filtre par étiquette, un état de pliage — n'est pas ce
qu'exprime une liste de liens.

- **Une note est une page** : `/backend/notes/markdown/42`. L'adresse existait
  déjà mais ne répondait qu'en JSON ; elle négocie maintenant, JSON pour les
  appels de la page, page entière pour qui arrive par un lien. Même patron que
  `AuditController`.
- Cliquer une note depuis l'éditeur la change **sans recharger** : c'est la page
  qui répond, par le pont. Créer aussi — nommer une note et y poser le curseur,
  seule la page sait le faire.
- Le tiroir mobile propre à Notes disparaît : le menu a le sien, et deux tiroirs
  c'était deux gestes à connaître pour la même chose.
- Le point d'extension `<slot name="extra-headers" />` de cette page disparaît
  avec l'aside. **Changement d'API** pour les projets clients — aucun ne s'en
  servait sur Notes, la couture reste ouverte sur les autres composants qui la
  proposent.

### Supprimé

#### La colonne d'onglets des préférences du profil
Elle affichait **un seul onglet**, donc une navigation entre une destination et
elle-même, et gardait ce choix dans le fragment d'URL — l'arrangement que la
page des réglages a quitté en 0.9.29. Il n'y avait rien à déporter ici, juste un
contrôle à retirer. À remettre le jour où un deuxième onglet existe, et alors
dans la vue de module, pas dans la page.

### Modifié

#### `NavItem` accepte un libellé et une description littéraux
Une entrée nommée d'après une donnée — un type de contenu que quelqu'un a appelé
« Article » — n'a rien à traduire. Passer ce nom dans `labelKey` revenait à
demander à `t()` de traduire une donnée : il rend la chaîne telle quelle **en
avertissant à chaque rendu**. `label` porte le nom lisible, `labelKey` reste le
repli.

`description` suit la même logique, et pour une raison qui se voyait à l'écran :
avec « Afficher les descriptions » actif, une entrée sans seconde ligne restait
blanche à côté de celles qui en avaient une. Pire, la ligne manquante était
précisément **ce qui distingue deux enregistrements** — le slug d'un type de
contenu, l'emplacement d'un menu. C'est ce que la colonne de sélection affichait
sous chaque nom avant de disparaître ; les entrées le portent maintenant.

C'est le socle qui bouge sous son premier vrai client, comme `routeParams` en
0.9.29 : rien ne le réclamait tant qu'aucune entrée n'était nommée par une
donnée.

#### Le chargement d'un panneau passe par `useRequest`
`useModulePanelData` appelait `fetch` directement, ce qu'interdit
`convention_no_raw_fetch` — et la raison invoquée par cette convention s'est
réalisée dans le même chantier : `useRequest` est seul à envoyer
`X-Requested-With`, l'en-tête sur lequel Symfony décide de répondre en JSON
plutôt qu'une page. La route d'une note vient précisément de se mettre à en
dépendre. `useRequest` gagne une option `silent`, parce qu'un panneau qui se
remplit tout seul ne doit pas afficher d'erreur que personne n'a demandée.

## [0.9.36] - 2026-08-31

### Ajouté

#### Les sept onglets d'administration sont dans le menu
Deuxième module à passer ses onglets en entrées de menu, après les réglages en
0.9.29 — et le cas facile : chaque onglet **avait déjà sa propre route**, tenue
dans l'adresse par `useUrlSyncedState`. Rien à inventer, pas de nom de route
partagé, pas de clé stable : sept `NavItem` ordinaires vers sept routes qui
existaient.

- La rangée d'onglets disparaît de la page, colonne `w-44` et variante mobile
  comprises. Deux surfaces qui répondent à « sur quel onglet suis-je » en font
  une de trop, c'est le même arbitrage qu'en 0.9.29.
- Les libellés, icônes et chemins des onglets quittent le tableau JavaScript de
  `AdministrationApp` : ils vivent dans `DevModule` maintenant, et une seconde
  copie serait une seconde copie à oublier — exactement ce qui est arrivé aux
  deux tables d'icônes de navigation.
- `AdministrationApp` perd 34 lignes, son `useUrlSyncedState` et neuf imports.
  Quel onglet s'affiche est désormais la réponse du serveur, qui arrive en prop.
- Les icônes `puzzle` et `network` entrent dans la table de navigation. C'est le
  test de garde écrit en 0.9.29 qui les a réclamées, en nommant le fichier
  fautif — il a fait exactement son travail.

### Supprimé

#### Le pont des anciennes adresses `#seo` des réglages
Livré en 0.9.29 avec une échéance de deux versions ; on est en 0.9.36. Un pont
qui reste devient une deuxième façon d'adresser la même page, ce qui était
l'argument pour le dater. La prop `tabPathTemplate` et sa génération côté Twig
partent avec.

## [0.9.35] - 2026-08-31

### Supprimé

#### La page « Dossiers » n'existe plus, le menu la remplace
`/backend/ged/folders` était un écran entier pour gérer une arborescence que le
menu affiche déjà en permanence. Tout ce qu'elle savait faire est dans le
panneau : créer, renommer, supprimer, reparenter — et maintenant **ordonner**,
qui était la seule chose qui lui restait en propre.

- Le glisser-déposer reprend ses **trois bandes** : déposer sur les 40 % du haut
  insère avant, sur les 40 % du bas insère après, et le cinquième du milieu
  reparente. Le docblock de la page annonçait 30/40/30 ; le code faisait
  40/20/40, et c'est le code qui a été repris.
- Les lignes du panneau passent à `min-h-8`. Sur la page, elles étaient en
  `py-3` et pleine largeur ; dans 280 px, la bande « dedans » est la petite des
  trois et il faut de la hauteur pour la viser.
- L'entrée « Dossiers » disparaît du menu. Les dossiers n'ont plus de
  destination à eux, ils ont un panneau — visible sur **toutes** les pages du
  module au lieu d'une seule.
- L'ancienne adresse **redirige** vers les documents plutôt que de renvoyer un
  404 : elle a vécu un an dans des menus et des favoris. C'est un pont, à
  retirer d'ici deux versions, comme celui du fragment `#seo`.
- Tous les autres points d'entrée `/backend/ged/folders/*` restent : ce sont eux
  que le panneau appelle.
- L'arbre du panneau se trie enfin **par position**. `buildFolderTree` trie par
  nom sauf si on lui dit autre chose, et le panneau ne lui disait rien : le
  serveur enregistrait bien le nouvel ordre, le rendu suivant le remettait par
  ordre alphabétique, et le glisser semblait n'avoir servi à rien. La page
  supprimée avait ce comparateur ; le panneau hérite de son travail, il devait
  hériter de ça aussi.
- Une poignée de glissement apparaît sur les lignes. Une ligne qui est aussi un
  lien se lit comme cliquable, pas comme déplaçable — rien ne disait qu'on
  pouvait la prendre.
### Corrigé

#### Déposer un dossier au bord d'un autre le déplaçait n'importe où
Et un dossier rangé dans un autre ne pouvait plus jamais en ressortir. Même
cause : l'endpoint `reorder` attribue des positions et **ne touche jamais au
parent**. Un dossier déposé à côté d'une ligne d'une autre branche était donc
renuméroté dans l'ordre de cette branche tout en restant là où il était — il
semblait sauter à un endroit que personne n'avait demandé, et déposer un dossier
imbriqué à côté d'un dossier racine ne le faisait jamais remonter. Le parent est
changé d'abord maintenant, et l'ordre seulement ensuite.

#### Ranger un dossier dans son propre enfant faisait disparaître les deux
`move()` n'avait aucun garde-fou. Les deux dossiers finissaient par se pointer
l'un l'autre, plus aucun n'était joignable depuis une racine, et **tout écran qui
construit un arbre cessait de les afficher** — eux et tout ce qu'ils
contenaient. Les lignes restaient en base, inaccessibles à la moindre interface.
C'est antérieur au chantier : la page supprimée avait le même glisser.

Le serveur refuse maintenant le déplacement et le dit, le panneau ne le propose
même plus (aucun repère de dépôt sur une ligne de sa propre descendance), et
`buildFolderTree` promeut à la racine ce qu'il n'atteint pas — sans quoi une
donnée déjà abîmée resterait invisible pour toujours, donc irréparable.

### Modifié (suite)

- Au passage, une incohérence part avec la page : elle glissait ses dossiers
  sous le type MIME `application/x-aurora-folder`, le panneau sous
  `application/x-aurora-document-folder`. Deux chaînes pour la même chose, il
  n'en reste qu'une.

## [0.9.34] - 2026-08-31

### Corrigé

#### Le « + » du panneau des dossiers n'ouvrait rien sur une GED vierge
Les deux modales du panneau vivaient dans le slot par défaut de
`AppModulePanel` — précisément celui qu'une liste vide ne rend pas. Le bouton
qui sert à créer le **premier** dossier était donc le seul à ne pas fonctionner,
exactement dans l'état où on en a besoin.

`AppModulePanel` gagne un slot `#overlay`, rendu hors des branches chargement /
vide / contenu. C'est là que vont les modales de n'importe quel panneau : son
bouton d'action reste cliquable quand la liste est vide, donc ce qu'il ouvre
doit exister à ce moment-là.

Au passage, « Tous les documents » et « Racine » disparaissaient eux aussi
faute de dossiers. Ce ne sont pas des dossiers ; ne pas en avoir ne doit pas
les emporter.

#### Une modale fermée laissait une feuille invisible sur toute la page
Le wrapper d'`AppModal` est un `fixed inset-0 z-50` qui couvre l'écran entier et
**survit à la fermeture** le temps de la transition de sortie. Ses enfants sont
masqués, donc il ne se voit pas — mais il prend tous les clics et tout le
scroll, et indéfiniment si quoi que ce soit empêche le minuteur d'aboutir. Il
passe désormais en `pointer-events-none` dès le début de la fermeture : même
resté en place, il ne peut plus rien intercepter.

#### Le verrou de scroll est compté, au lieu du dernier qui parle
Chaque modale écrivait `document.body.style.overflow` en direct. C'est une règle
« dernier arrivé gagne », et elle casse dans les deux sens dès qu'il y a deux
surfaces à l'écran : une modale qui se ferme rend le scroll alors qu'une autre
couvre encore la page, et une modale **démontée pendant qu'elle est ouverte** ne
relâche jamais rien — la page reste figée sans rien à l'écran à blâmer.

C'était théorique jusqu'à ce que le menu se mette à monter ses propres modales
en 0.9.33 : deux applications Vue, un seul `body`. `bodyScrollLock` compte les
détenteurs, ne rend la page qu'au dernier, restitue la valeur d'avant, et chaque
détenteur relâche à la fermeture comme au démontage.

## [0.9.33] - 2026-08-31

### Ajouté

#### Un canal entre un panneau du menu et la page de son module
`modulePanelBridge` dans le socle, et c'est la pièce qui rend le déport possible
tout court. Deux applications Vue distinctes - le menu monté par le layout, la
page qui monte la sienne - ne partagent ni store, ni props, ni parent. Elles
partagent le document : un `CustomEvent` **annulable** sur `window`.

Annulable, et c'est tout le dessin : une ligne de panneau reste un vrai
`<a href>`, donc clic milieu et « ouvrir dans un nouvel onglet » fonctionnent et
l'adresse est honnête. Au clic simple le panneau **demande** d'abord. Si la page
écoute, elle prend le travail et le lien ne part pas ; si personne n'écoute -
le lecteur est sur une autre page du module - le lien navigue comme écrit. Un
seul panneau, correct dans les deux cas.

Avec lui, `AppModulePanel` (l'habillage commun : titre, action, états) et
`useModulePanelData` (un panneau ne reçoit aucune prop, il va donc toujours
chercher ses données). Les trois serviront aux cinq autres barres latérales de
page qui doivent suivre le même chemin.

### Modifié

#### La barre latérale des dossiers de la GED est passée dans le menu
Elle n'existait que sur la page Documents. La même question - dans quel dossier
je travaille - se pose sur les Étiquettes, les Catégories et la fiche d'un
document, où **aucun chemin vers un dossier n'existait**. Elle est donc partie
dans le menu, entièrement : création, renommage, suppression, favoris, et le
glisser-déposer d'un document ou d'un dossier vers un autre dossier.

- L'`aside` de la page est supprimée, pas dupliquée. `DocumentsApp` perd 190
  lignes et deux composables.
- Le glisser-déposer traverse les deux applications sans rien coûter : les types
  MIME `application/x-aurora-document*` sont posés sur `dataTransfer`, qui
  appartient au navigateur et non à l'une des deux applications. La page reste
  la source du glissement, le menu devient la cible.
- Cliquer un dossier depuis la page Documents filtre toujours **sans
  rechargement**, comme avant : c'est la page qui répond, par le canal.
- La 0.9.31 est annulée : le panneau redessine sur la page Documents, puisque
  c'est désormais le seul arbre.
- Rien n'est perdu au passage : la liste de raccourcis « Favoris » que l'`aside`
  gardait au-dessus de son arbre est là aussi, au même endroit. Quelqu'un qui a
  mis neuf dossiers sur quatre-vingt-dix en favori l'a fait pour arrêter de
  faire défiler, et l'arbre en dessous est précisément ce défilement.
- `folderEditOptions` descend dans `useDocumentSidebarTree`. C'est une
  dérivation de l'arbre, pas du CRUD, et le sélecteur de dossier du formulaire
  en a besoin sur une page qui ne crée plus aucun dossier. La modale de
  déplacement groupé en recopiait une troisième version à la main.

## [0.9.32] - 2026-08-31

### Corrigé

#### On peut revenir au menu du module qu'on vient de quitter
La vue de module ne s'ouvrait que dans un sens. Échap, ou la ligne « Tous les
modules », ramenait à la vue projet — et **rien ne ramenait dans l'autre sens**.
Il fallait recharger la page.

C'est en ligne depuis la 0.9.28 et ça touche déjà les réglages : un lecteur qui
presse Échap sur `/backend/configuration/settings/seo` perd les onze onglets du
menu, sur une page qui n'en propose plus aucun autre chemin.

La fonction existait pourtant, `enterModuleView`, et deux tests la couvraient
depuis le premier jour. Aucun contrôle ne l'appelait. C'est le genre de trou
qu'un test de composable ne voit pas par construction : la composable n'a jamais
été la partie cassée, c'est le câblage — il faut monter le composant pour
remarquer qu'une fonction n'a pas d'appelant.

Une ligne « Revenir à {module} » apparaît donc en vue projet quand la page
appartient à un module, symétrique de celle qui en sort.

## [0.9.31] - 2026-08-31

### Modifié

#### Une seule arborescence de dossiers sur la page Documents
Le panneau livré en 0.9.30 ne se dessine plus sur `/backend/ged/documents`. Cette
page a déjà son arbre, et un meilleur : il crée, renomme et supprime un dossier,
il accepte un document déposé dessus, et il porte « Tous les documents » et
« Racine », qui sont des filtres. Deux arbres à trente centimètres l'un de
l'autre répondant à la même question, c'est un de trop.

Le panneau reste sur toutes les autres pages de la GED - Étiquettes, Catégories,
Dossiers, la fiche d'un document - où il est la seule façon d'atteindre un
dossier. C'est de là que vient sa valeur, pas de la page qui l'avait déjà.

La page est reconnue par son chemin **exact** : `/backend/ged/documents/42` est
un document, et cette page-là n'a pas d'arbre.

Le surlignage du dossier courant part avec. Le lecteur ne regarde un dossier que
sur la page Documents, la seule que le panneau ne dessine pas : c'était un état
qui ne pouvait plus jamais être vrai.

## [0.9.30] - 2026-08-31

### Ajouté

#### La GED a son propre menu, et l'arborescence de dossiers y est
Deuxième module à déclarer une vue de module, et le premier à se servir du
panneau : sous les quatre destinations de la GED, le menu latéral affiche
maintenant l'arborescence des dossiers.

- **Elle suit le lecteur.** L'arbre existait déjà, mais seulement dans la barre
  latérale de la page Documents. Depuis les Étiquettes ou les Catégories, il n'y
  avait aucun chemin vers un dossier ; il en faut un seul maintenant.
- Chaque ligne est une **adresse** (`/backend/ged/documents?folderId=42`), pas un
  gestionnaire de clic. C'est ce qui la rend utilisable depuis une page qui n'a
  aucune liste de documents à filtrer - un gestionnaire n'aurait fonctionné que
  sur la page qui affiche déjà cet arbre.
- L'état de pliage est **celui de la page Documents**, même clé de stockage. Les
  dossiers repliés dans le menu le sont dans la page, et inversement : deux
  états auraient fini par montrer deux arbres différents.
- Le panneau **va chercher ses propres données**. Le menu le monte sans aucune
  prop - il n'a pas à savoir ce qu'est un dossier - donc l'arbre ne peut pas
  arriver avec la charge utile de la page. Un GET par page GED, sur
  `/backend/ged/documents/folders`, qui renvoie exactement la forme et les
  compteurs que la page Documents utilise déjà.
- Ce que le panneau ne fait **pas** : créer, renommer, déplacer ou supprimer un
  dossier. Une colonne de 280 px est le mauvais endroit pour confirmer une
  suppression, et la ligne « Dossiers » est juste au-dessus.
- Le panneau n'apparaît que si Documents **et** Dossiers sont actifs : un arbre
  dont aucune ligne ne mène nulle part est une décoration.

### Corrigé

#### Un nom de panneau qui ne correspond à rien ne passe plus inaperçu
`ModuleNavView::$panelComponent` et `registerModulePanel()` s'accordent sur une
chaîne écrite deux fois, dans deux langages, et rien ne les comparait. Une faute
de frappe donnait un panneau absent, silencieusement : le menu dessine ses liens
de toute façon, ce qui est le bon comportement et rend la panne invisible. Un
test échoue maintenant, dans les deux sens, en nommant les deux fichiers.

### Modifié

#### Les entrées de menu de la GED ne sont plus écrites deux fois
`GedModule` déclarait ses quatre `NavItem` à l'identique dans `getNavSections()`
et `getCatalogNavSections()`. La vue de module en aurait fait une troisième
copie - le genre de triplication où les copies cessent de s'accorder un
interrupteur à la fois. Le catalogue garde sa liste, et c'est délibéré : il
montre ce que le module *propose*, sous-fonctionnalités désactivées comprises,
donc il ne doit pas consulter les interrupteurs.

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`. Un module client qui veut le même
traitement implémente `ModuleNavViewProviderInterface` et, s'il a un panneau,
enregistre son composant depuis un fichier `*.register.js`.

## [0.9.29] - 2026-08-31

### Ajouté

#### Chaque onglet des réglages est devenu une page
C'est le premier module à utiliser la vue de module livrée en 0.9.28, et le
premier changement qu'on **voit** : la colonne d'onglets à l'intérieur de la page
de réglages disparaît, ses onze onglets remontent dans le menu latéral, à côté
de Thèmes.

- Un onglet a une adresse : `/backend/configuration/settings/seo`. Il était un
  fragment d'URL (`#seo`), donc il ne pouvait pas être envoyé à quelqu'un, ne
  portait pas de fil d'Ariane, ne créait pas d'entrée d'historique et restait
  introuvable depuis la palette. Les trois arrivent d'un coup.
- Une seule route paramétrée, pas onze routes nommées : les onglets sont
  **contribués à l'exécution** - un module client ajoute le sien via
  `ConfigurationTabProviderInterface` - et aucune déclaration statique ne peut
  connaître son existence.
- `/backend/configuration/settings` redirige vers le premier onglet visible.
  Rendre les deux aurait donné deux adresses au même onglet, et le menu n'aurait
  pas su sur laquelle il se trouve. Le premier onglet n'est pas codé en dur : un
  client peut contribuer un onglet de priorité plus basse.
- Un onglet qu'on n'a pas le droit de voir est un **404 décidé côté serveur**.
  Avant, il était simplement absent de la charge utile et le navigateur écartait
  le fragment qui le nommait - correct, mais la barrière était dans le client.
- `ConfigurationTab` accepte un `requiredPrivilege`. Les onglets d'Aurora le
  laissent à `null` : ils sont tous derrière `configuration.settings.manage`,
  appliqué une fois sur le contrôleur, et le découper plus fin inventerait des
  permissions que personne n'a demandées. Il existe pour les modules clients.
- La page ne résout plus que l'onglet regardé. Elle construisait les onze, dont
  les champs `media` - une requête document et une génération d'URL par champ,
  pour des onglets que personne n'avait ouverts.
- Une vieille adresse en `#seo` est redirigée une fois, au chargement, vers
  l'URL de l'onglet. C'est un pont, à supprimer d'ici deux versions : un pont
  qui reste devient une deuxième façon d'adresser la même page.

### Modifié

#### Le socle de la vue de module accepte des entrées paramétrées
Ce que le premier vrai client a révélé. `NavItem` gagne `routeParams` et une
`key` stable distincte du nom de route, parce que **onze entrées partagent
`backend_configuration_settings_tab`** : sans clé propre, masquer un onglet
depuis les préférences en masquait onze, et les onze se seraient allumés en même
temps. Une entrée porteuse de paramètres se reconnaît donc à son chemin, pas à
son nom de route - côté menu comme côté palette, où « récemment visité » aurait
sinon toujours ramené au premier onglet.

`ModuleNavResolver` procède maintenant en deux passes. Déclarer une vue n'est pas
toujours gratuit - celle de Configuration doit lire les onglets contribués - et
la version précédente interrogeait **chaque module à chaque page**. La première
passe ne compare que les préfixes des `NavSection`, que les modules construisent
déjà pour le menu, et seul le gagnant est interrogé. La seconde passe existe pour
les routes qu'aucune section ne déclare, et ne tourne que si la première n'a
trouvé personne.

La règle de visibilité des onglets sort de `SettingsViewBuilder` dans
`SettingsTabAccess` : trois appelants en ont besoin - la page, le contrôleur qui
valide le `{tab}` de l'URL, et la vue de module qui les liste - et une règle sur
qui voit quoi est la dernière chose à garder en trois copies.

#### Une seule table d'icônes de navigation
`useSidemenuNav.js` importe désormais `resolveNavIcon` de `navMeta.js` au lieu de
tenir sa propre copie de la table. Les deux étaient censées se refléter, et
`navMeta.js` le disait dans son en-tête ; la copie du menu avait pris du retard.
Au passage `tag` entre dans la table : la GED le déclarait pour ses étiquettes,
aucune table ne le connaissait, et la ligne affichait donc une icône de document.
Un test vérifie maintenant que **tout nom d'icône déclaré côté PHP se résout**.

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`. Un module client qui contribue un
onglet de réglages n'a rien à changer : son onglet devient une page et une entrée
de menu tout seul. S'il veut le réserver à un rôle, `ConfigurationTab` accepte
maintenant `requiredPrivilege`.

## [0.9.28] - 2026-08-31

### Ajouté

#### Le menu latéral sait porter une deuxième vue, celle du module ouvert
Socle uniquement : **rien ne change à l'écran**. Aucun module ne déclare encore
de vue, donc le menu ne quitte jamais sa vue projet. Ce commit met en place la
mécanique, les suivants y branchent les modules.

- Une colonne, deux vues, jamais les deux en même temps. Les 280 px ne changent
  pas de largeur, ils changent de contenu : la vue **projet** (les sections de
  `ModuleRegistry`) ou la vue **module** (les destinations du module ouvert).
  C'est le seul arrangement qui ne coûte pas de largeur à la page - deux
  colonnes juxtaposées en prendraient 520, soit 36 % d'un écran de 1440.
- Un module déclare sa vue en implémentant `ModuleNavViewProviderInterface`,
  interface **companion optionnelle** de `ModuleInterface` - même mécanique que
  `ModuleToggleProviderInterface`, et pour la même raison : les projets clients
  implémentent `ModuleInterface` eux-mêmes, une méthode requise de plus les
  casserait tous au prochain `composer update`.
- `ModuleNavResolver` répond « quel module pour cette route ». Rien ne le
  faisait côté serveur jusqu'ici : la section active était déduite dans le
  navigateur par `activeRoute.startsWith(...)`, ce qui suffit pour teinter une
  ligne déjà affichée mais pas pour décider **quoi rendre** - une décision côté
  client peindrait la vue projet puis la remplacerait une frame plus tard, sous
  les yeux du lecteur.
- Le match est la même règle de préfixe que le menu a toujours utilisée, avec un
  ajout : **le plus long préfixe gagne**. C'est ce qui empêche `dev_dashboard`
  d'être réclamé par un module dont le préfixe est seulement `dev_`, et c'est le
  seul arbitrage qui ne dépende pas de l'ordre d'enregistrement DI - donc
  instable.
- Un module absent du menu principal ne prend pas la colonne. `getNavSections()`
  ne renvoie rien quand un module est désactivé, ce qui en fait la barrière la
  plus honnête disponible : la vue module suit le menu.
- La vue s'ouvre sur ce que le serveur a résolu, donc un lien direct vers une
  page d'un module affiche le menu de ce module. Le retour (`Échap` dans la
  colonne, ou la ligne « Tous les modules ») est un **état de page**, pas une
  préférence : la question « où suis-je » a une bonne réponse à chaque rendu, et
  une réponse mémorisée la contredirait à la navigation suivante.
- Le filtre du menu cherche dans la vue affichée et nulle part ailleurs. Un
  champ qui remonterait des lignes que la colonne ne montre pas demanderait une
  phrase d'explication ; c'est la palette `⌘K` qui cherche partout, et elle le
  dit.
- La palette gagne au passage les destinations du module ouvert. Une destination
  déclarée au seul niveau module n'était trouvable nulle part - c'était le
  premier coût de l'éparpillement actuel.
- Ce qu'une liste de liens ne sait pas dire - un arbre de dossiers, une liste de
  neuf cents notes - passe par `ModuleNavView::$panelComponent` et
  `modulePanelRegistry.js`, même arrangement que `panelRegistry.js` du tableau
  de bord. Sans lui, `AppSidemenu` devrait importer depuis `@ged/...`, la
  dépendance inter-modules que le système de modules existe pour éviter.

### Modifié

#### La résolution d'un `NavItem` a désormais un seul endroit
`NavItemResolver` sort de `ModuleRegistry` : privilège, génération du chemin,
enfants, filtre des entrées masquées par l'utilisateur. La vue module a besoin
du traitement exact des mêmes lignes, et deux copies auraient divergé au premier
changement - qui aurait été un changement de sécurité, puisque c'est là que
`requiredPrivilege` est appliqué. `ModuleRegistry` perd deux dépendances et
délègue.

`AppSidemenuNav.vue` dessine les deux vues : un `themeId` optionnel sépare la
palette empruntée de la clé de repli, et un groupe sans en-tête n'est pas
repliable - son en-tête *est* le bouton, donc un groupe sans en-tête replié
serait définitivement inaccessible.

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`. Un module client peut dès
maintenant implémenter `ModuleNavViewProviderInterface` pour déclarer sa vue ;
ne rien implémenter garde le comportement actuel.

## [0.9.27] - 2026-08-31

### Modifié

#### Le menu latéral s'ouvre plus large
- La largeur par défaut passe de 240 à **280 px**. Elle ne concerne que les
  sessions qui n'ont jamais tiré la poignée : une largeur redimensionnée vit dans
  le navigateur et n'est pas touchée.
- Deux valeurs devaient bouger ensemble : le `--sidemenu-width` de
  `sidemenu.css`, appliqué au premier rendu, et le `defaultValue` du
  `useResizable` dans `AppSidemenu.vue`, appliqué dès que Vue monte
  (`watch(..., { immediate: true })`). N'en changer qu'une faisait **sauter la
  largeur une fois sous les yeux**. Un commentaire croisé le dit maintenant aux
  deux endroits.

#### Les entrées du menu n'ouvrent plus de bulle au survol
- Elle répétait le libellé que la ligne affiche déjà, et son seul autre rôle -
  porter la description - est repris par l'interrupteur « afficher les
  descriptions », qui met le texte dans la ligne où il se lit sans le chercher.
- C'était d'ailleurs visible dans le code : la description de la bulle était
  éteinte dès que l'interrupteur était allumé. Deux façons de montrer la même
  chose, dont une qu'il fallait faire taire - la forme d'une fonctionnalité
  remplacée.
- Le `title` du chevron d'ouverture reste : ce n'est pas une bulle d'aide mais le
  **nom accessible** d'un bouton sans texte. Sans lui, un lecteur d'écran annonce
  « bouton » et rien d'autre.
- À savoir : les libellés sont tronqués (`truncate`), et la bulle était le seul
  moyen de lire un libellé trop long. Les 280 px compensent en partie.

### Corrigé

#### « Pilotez votre contenu avec X » disait « avec »
- Le titre de la page de connexion annonce désormais « Pilotez votre contenu
  **sur** {siteName} ». L'anglais suit : « Run your content **on** {siteName} ».

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`.

## [0.9.26] - 2026-08-31

### Ajouté

#### Un compte peut être créé pour quelqu'un qui arrive plus tard
- La modale d'invitation porte une case **« Créer le compte désactivé »**. Le
  compte existe, personne n'est prévenu, et la connexion lui est refusée.
  L'invitation part quand on ouvre le compte depuis la liste.
- Jusqu'ici la seule façon de préparer un accès était d'inviter tout de suite,
  donc d'envoyer un mail à quelqu'un qui n'en avait pas encore l'usage - et de
  laisser un jeton expirer en 48 heures avant que la personne n'arrive.
- Aucune colonne ajoutée : `invitedAt`, qui existait déjà, sert de repère. Nul,
  il veut dire « personne n'a jamais été contacté », ce qui distingue un compte
  pré-provisionné d'un compte désactivé après avoir servi - les deux portent le
  même statut `Disabled`.
- Conséquence dans la liste : sur un compte jamais contacté, l'action ne dit
  plus « Réactiver » mais **« Activer et inviter »**, avec sa propre
  confirmation. Elle n'ouvre pas un accès, elle en envoie le premier.
- Ouvrir un tel compte le passe `Invited`, pas `Active` : son mot de passe est
  un aléa que personne ne connaît, et `Active` l'aurait fait paraître utilisable
  sans que quiconque puisse s'en servir.

#### On peut inviter quelqu'un sur le site public
- La modale d'invitation porte un choix de **type** : l'administration, ou le
  site public. Jusqu'ici seule l'administration était invitable, et un compte du
  site public ne pouvait naître que d'une inscription spontanée - donc impossible
  d'ouvrir un accès à un client qu'on connaît.
- Le sélecteur de rôle disparaît quand le type est « site public » : celui-ci
  n'a qu'un rôle, `ROLE_USER`, que l'inscription publique pose déjà en dur.
  Afficher un choix qui n'existe pas serait mentir, et le serveur force la valeur
  de toute façon - une charge utile trafiquée demandant `ROLE_ADMIN` sur un
  compte public n'obtient rien.
- L'invitation mène à une page d'acceptation **du site public**, avec son propre
  texte : « réinitialiser » est faux pour quelqu'un qui n'a jamais eu de mot de
  passe. La personne est ensuite connectée sur le site, pas dans l'administration.

### Corrigé

#### Les deux pages d'acceptation refusent le jeton de l'autre population
- La mécanique du jeton est commune aux deux types, et c'est voulu : une seule
  expiration, un seul hachage à maintenir. Mais `findValidInvitation` ne filtre
  donc pas le type, et **aucune des deux routes ne le faisait**.
- Conséquence avant correction, restée hors d'atteinte tant que seuls des comptes
  d'administration étaient invitables : un invité du site public suivant l'adresse
  du backend s'y serait connecté le temps d'une requête. `admin_user_provider` ne
  résolvant que les comptes d'administration, sa session aurait sauté au
  rafraîchissement suivant - après un passage sur le tableau de bord, et sans
  qu'aucun message ne le lui explique.
- Chaque route refuse désormais la population qui n'est pas la sienne, avec le
  même message qu'un jeton expiré : la page n'a pas à révéler qu'un compte existe
  ailleurs.

### Dans aurora-client

`make aurora-update` suffit, et rien n'est à éditer à la main.

`config/packages/security.yaml` gagne la règle d'`access_control` de la nouvelle
route d'acceptation publique, mais elle arrive toute seule : `aurora-update`
appelle `sync-security`, qui recopie le fichier depuis le vendor. **Ne pas
l'ajouter à la main** - une édition manuelle de ce fichier est écrasée à la mise
à jour suivante.

## [0.9.25] - 2026-08-31

### Ajouté

#### Une publication peut repeindre la topbar, le pied et le fond pour elle seule
- L'écran de thème choisit déjà ces trois couleurs, mais pour tout le site à la
  fois. Une page qui doit sortir du lot - un dossier, une campagne, une annonce -
  n'avait pas d'autre issue que de créer un thème entier et de le basculer, donc
  de repeindre aussi toutes les autres pages.
- Un onglet **Apparence** porte les trois champs sur la publication. Vide veut
  dire « hérite » : une page qui ne choisit que sa topbar garde le fond et le
  pied du thème. La substitution se fait surface par surface, jamais en bloc,
  sinon choisir une couleur en effacerait deux.
- Chaque surface repeinte emporte son jeu de jetons contrasté, par le même
  calcul que l'écran de thème (`SurfaceContrast`). C'est ce qui sépare
  « repeindre » de « rendre illisible » : un fond sombre posé seul laisserait
  les libellés, les mentions discrètes et les bordures en sombre sur sombre,
  sans qu'aucune erreur ne le signale.
- La prévisualisation passe par le même rendu que la page publique, donc elle
  montre les couleurs choisies avant publication.

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`. La migration ajoutant les trois
colonnes à `core_posts` part avec, et `make deploy-prod` la joue.

## [0.9.24] - 2026-08-30

### Corrigé

#### Le bloc HTML brut était inutilisable
- Sa feuille livrée ne fixe **aucune largeur**. Sans `width`, un `<textarea>`
  retombe sur son attribut `cols`, soit une vingtaine de caractères : pour du
  HTML écrit à la main, la zone de saisie était trop étroite pour y travailler.
- Elle code aussi ses couleurs en dur, en sombre. Sur un backend en thème clair,
  la zone restait noire au milieu d'une page blanche. Les deux cas se règlent
  d'un coup en passant par les jetons du projet, qui suivent le thème.

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`.

## [0.9.23] - 2026-08-30

### Modifié

#### Le séparateur ressemble enfin à ce qu'il produit
- L'outil existait déjà, sous le nom « Séparateur », et son rendu public est un
  `<hr>`. Mais `@editorjs/delimiter` dessine **trois astérisques** dans
  l'éditeur, et l'écart suffit à ce qu'on cherche l'outil sans le reconnaître :
  `* * *` ne se lit pas comme une ligne de séparation.
- L'éditeur affiche désormais la ligne que le lecteur verra.

### Corrigé

#### Le bloc HTML brut s'appelait « Raw HTML » en français
- Ajouté en 0.9.21, il avait échappé au dictionnaire des noms d'outils : il
  apparaissait en anglais au milieu d'une liste traduite. Il s'appelle
  maintenant « HTML brut ».

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`.

## [0.9.22] - 2026-08-30

### Ajouté

#### Le bloc HTML brut accepte les icônes SVG
- `RawHtmlSanitizer` laisse désormais passer un sous-ensemble strict de SVG :
  `svg`, `g`, `path`, `circle`, `ellipse`, `rect`, `line`, `polyline`,
  `polygon`, avec leurs attributs géométriques.
- L'intérêt sur une image `data:` déjà acceptée : un SVG en ligne peut porter
  `fill="currentColor"`, donc **prendre la couleur du texte** et suivre le thème.
  Dans une image `data:`, la couleur est figée dans le fichier et ne suit rien.
- Restent exclus, et c'est la raison d'une liste plutôt que d'une autorisation
  de `<svg>` : `use`, qui référence un document extérieur ; `foreignObject`, qui
  réintroduirait du HTML arbitraire au milieu du SVG ; `image`, `style`, et
  toutes les balises d'animation. Les scripts et les attributs `on*` étaient
  déjà bloqués.

#### La casse des attributs SVG est restituée
- Le parseur HTML de PHP met tous les noms d'attributs en minuscules. Correct en
  HTML, faux en SVG : un `viewbox` est ignoré par les navigateurs et l'icône
  perd son cadrage, **sans qu'aucune erreur ne le signale**. `viewBox` et
  `preserveAspectRatio` retrouvent leur casse à la sérialisation.

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`. Une icône se colle désormais dans
un bloc HTML brut, à l'endroit choisi, et suit la couleur du texte.

## [0.9.21] - 2026-08-30

### Supprimé

#### Les icônes automatiques sur les liens sociaux
- Introduites en 0.9.18, corrigées en 0.9.19 et 0.9.20, elles sont retirées.
  Le principe même était en cause : décorer un lien à partir de son domaine
  impose un rendu que l'auteur n'a pas demandé et ne peut pas refuser depuis
  l'éditeur.
- Le nettoyeur de contenu n'autorisant ni `<svg>` ni `<img>`, il n'existait pas
  de version « opt-in » de cette fonctionnalité : elle était forcément
  automatique, donc forcément subie. Mieux vaut ne rien imposer.
- Part avec elles le garde-fou `a:empty::before`, qui n'existait que pour
  rattraper un effet de bord des icônes.

### Ajouté

#### Un bloc « HTML brut » dans l'éditeur
- `@editorjs/raw` est installé. Il ouvre ce que l'éditeur ne sait pas faire :
  une mise en page à la main, un tableau complexe, un lecteur intégré.
- Il ne passe **pas** par `BlockHtmlSanitizer`, qui le viderait de tout ce qui
  justifie son existence. Un second filtre lui est dédié, `RawHtmlSanitizer`,
  nettement plus large : structure, tableaux, figures, images, classes et styles.
- Plus large, mais fermé aux mêmes choses. Ne passent jamais : `<script>`,
  `<style>`, `<form>` et ses champs, `<object>`, `<embed>`, `<link>`, `<meta>`,
  `<base>`, tout attribut `on*`, les URL `javascript:`, et les `data:` sauf
  images. Les `<iframe>` ne sont acceptées que vers une liste d'hôtes nommés :
  un cadre est une page entière qu'on ne contrôle pas, posée dans la sienne.
- Un lien portant `target` reçoit automatiquement son `rel="noopener
  noreferrer"`, posé plutôt que refusé.
- 26 tests couvrent le filtre, dont **quatorze tentatives d'injection** écrites
  comme telles : script imbriqué, `onerror` sur une image cassée, `javascript:`
  en casse mélangée, `data:text/html`, `meta refresh`, `<base>` détournant les
  URL relatives.

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`. Les liens redeviennent des liens,
sans pastille, et un bloc « HTML brut » apparaît dans l'éditeur. Aucun contenu
existant n'est modifié.

> Le bloc est ouvert à tout compte pouvant éditer un contenu. Sur un projet à
> plusieurs rédacteurs, c'est un choix à faire consciemment : le filtre ferme la
> porte au script, pas au mauvais goût ni à une mise en page cassée.

## [0.9.20] - 2026-08-30

### Corrigé

#### Un lien sans libellé affichait quand même son icône
- L'éditeur produit facilement `<a href="..."></a>` : il suffit d'effacer le
  texte d'un lien sans effacer le lien. L'ancre devient invisible en lecture,
  mais elle recevait son icône, et on se retrouvait avec un logo orphelin collé
  au lien suivant. Constaté sur une page réelle après une retouche dans le
  backend, où une icône Facebook s'était installée à côté de celle du courriel.
- Corriger le contenu ne suffisait pas, la prochaine édition recréant l'ancre.
  C'est donc au rendu de refuser de décorer ce qui n'a rien à décorer :
  `.prose a:empty::before { content: none; }`.

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`. Une ancre vide déjà présente dans
un contenu cesse d'afficher son icône, sans que le contenu soit retouché.

## [0.9.19] - 2026-08-30

### Corrigé

#### Les icônes de la 0.9.18 n'apparaissaient pas
- Les cinq règles qui déclarent `--aurora-link-icon` étaient supprimées à la
  construction : le SVG était enveloppé dans des guillemets simples après que
  ses propres guillemets doubles eurent été convertis en simples. Le parseur CSS
  s'arrêtait donc au premier guillemet interne, et la déclaration entière
  partait à la poubelle. Seules les règles qui *consomment* la variable
  survivaient, d'où des pastilles de la bonne taille et de la bonne couleur,
  mais vides.
- Le SVG est désormais entièrement encodé en pourcent : plus aucun guillemet ni
  chevron ne subsiste dans l'URI, donc plus rien qui puisse terminer la chaîne
  prématurément.
- Le contrôle de la 0.9.18 était trop faible pour voir le défaut : chercher
  `aurora-link-icon` dans le bundle matchait les cinq utilisations. Il compte
  maintenant les **déclarations** et les utilisations séparément, et décode une
  URI pour vérifier que le SVG est bien formé.

### Dans aurora-client

`make aurora-update`. Une installation restée en 0.9.18 affiche des liens sans
icône, sans autre conséquence.

## [0.9.18] - 2026-08-30

### Ajouté

#### Les liens sociaux du contenu portent leur icône
- Un lien vers GitHub, LinkedIn, Instagram, Facebook ou une adresse `mailto:`
  reçoit désormais sa petite icône, dans le contenu éditorial du site public.
- Les tracés viennent de **Lucide**, dont le projet dépend déjà : mêmes icônes
  que celles du backend, aucune n'a été redessinée. Un script lit les sources du
  paquet installé et en reconstruit le SVG.
- L'icône est posée en **masque** et non en image de fond, donc peinte avec
  `currentColor`. Elle suit le jeu de jetons de la surface sans seconde
  déclaration : claire sur fond sombre, sombre sur fond clair.

#### Pourquoi en CSS et pas dans le contenu
- Le nettoyeur de blocs n'autorise ni `<svg>` ni `<img>`, délibérément. Un
  rédacteur ne peut donc pas écrire l'icône, et c'est très bien ainsi. Elle est
  déduite du domaine du lien.
- **C'est opinionné** : tout lien vers ces domaines, dans n'importe quel
  contenu, reçoit son icône. Pour un site personnel c'est le comportement voulu.
  Un projet qui n'en veut pas surcharge `--aurora-link-icon: none` sur
  `.prose a`.
- La portée est limitée au frontend par `html[data-theme]`, attribut que seul le
  layout public porte. Le backend garde ses icônes Vue, rien n'y change.

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`. Les liens déjà écrits dans des
contenus existants gagnent leur icône sans être retouchés.

## [0.9.17] - 2026-08-30

### Corrigé

#### Le contenu éditorial ignorait la couleur de fond choisie
- La 0.9.16 fait bien basculer le texte de la page, des menus et du pied, mais
  pas celui des articles. Sur un fond noir, le corps des contenus restait en
  gris foncé : illisible, et en contradiction avec le reste de la page qui, lui,
  avait basculé.
- La cause est le plugin Tailwind Typography. La classe `.prose`, par laquelle
  passe tout le contenu éditorial, peint son texte avec ses propres variables
  (`--tw-prose-body`, `--tw-prose-headings`, et une quinzaine d'autres), des gris
  figés qui ne connaissent pas les jetons du projet. Aucun mapping ne les
  reliait.
- Les variables du plugin sont désormais branchées sur les jetons : le corps et
  les titres sur `--th-primary`, les compteurs et légendes sur `--th-muted`, les
  filets et bordures de tableau sur `--color-border`.
- Le mapping est **global et non limité au frontend**, parce que le défaut valait
  aussi pour le backend : l'aperçu de note en mode sombre affichait du texte
  sombre sur fond sombre pour la même raison.
- Effet de bord assumé sur le thème clair : le corps des articles passe du gris
  du plugin au `--th-primary` du projet, soit un texte légèrement plus foncé. Un
  paragraphe rendu dans `.prose` a maintenant exactement la couleur d'un
  paragraphe rendu à côté, ce qui n'était pas le cas.

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`.

## [0.9.16] - 2026-08-30

### Ajouté

#### Le site public se colore depuis l'écran de thème
- Trois couleurs, une par surface : le fond des pages, la barre de navigation et
  le pied de page. Chacune se choisit dans la palette de presets existante ou en
  hexadécimal, et se réinitialise pour revenir au défaut.
- La couleur du texte n'est pas un second réglage : elle est **déduite**. Le
  nouveau service `SurfaceContrast` compare le rapport de contraste WCAG du fond
  contre le noir et contre le blanc, et retient le meilleur.
- Ce qu'il retient n'est pas une couleur mais **tout un jeu de jetons** : texte
  fort, libellés, mentions discrètes, surfaces et bordures. Ne basculer que la
  couleur principale aurait laissé les gris moyens et les traits de séparation
  invisibles sur un fond sombre. Les deux jeux sont ceux que `theme.css` définit
  déjà pour `:root` et `.dark`, éprouvés par le backend.
- Les menus déroulants suivent leur barre. C'est acquis sans toucher à leur
  balisage : les règles émises redéfinissent les jetons **sur l'élément de
  surface**, et les propriétés personnalisées CSS étant héritées, tout ce que la
  barre contient suit, y compris les panneaux peints en `bg-bg`.
- Une surface sans couleur n'émet aucune règle. L'apparence historique reste donc
  le comportement par défaut, sans valeur à maintenir nulle part.

#### Ce que le calcul de contraste a appris au passage
- L'écran signale le seuil **AAA** (7:1) et non AA (4,5:1), et ce n'est pas un
  excès de zèle : AA ne peut pas échouer ici. En retenant toujours le meilleur du
  noir et du blanc, le rapport ne descend jamais sous **4,608:1**, minimum atteint
  sur le gris `#757575`. Un avertissement AA aurait été une interface qui ne
  s'allume jamais. Le plancher est vérifié par un test qui rebalaie les 256 gris,
  des deux côtés, PHP et JavaScript.

### Précédence

L'écran de thème expose maintenant deux mécanismes qui touchent aux mêmes
variables : ces trois couleurs, qui déduisent tout un jeu, et la pose d'une
variable `--th-*` à la main, qui existait déjà. **La seconde gagne** : c'est
l'échappatoire, et une échappatoire qui perd ne sert à rien. Concrètement, le CSS
des surfaces est rendu avant les overrides bruts.

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`. Un thème existant garde son
apparence tant que personne n'ouvre l'écran pour choisir une couleur.

## [0.9.15] - 2026-08-30

### Corrigé

#### La page d'accueil répétait le nom du site en titre
- Le listing s'ouvrait sur `<h1>{{ context.siteName }}</h1>`, deux lignes sous
  l'en-tête qui affiche déjà ce nom comme marque du site. Sur un site sans
  publication, la page entière se lisait « le nom, le nom, une phrase ».
- Répéter la marque gâchait aussi le seul `<h1>` de la page : un titre qui
  nomme le site plutôt que la page ne décrit rien, ni pour un lecteur ni pour
  un moteur. Le titre nomme désormais le listing (« Publications » / « Posts »)
  et reste un `<h1>` : le supprimer aurait laissé la page sans aucun.

### Dans aurora-client

Rien à faire. Un site qui préfère une vraie page d'accueil composée dans le
back-office peut toujours en désigner une avec le réglage `homepage_post_id`,
auquel cas ce listing ne s'affiche plus.

## [0.9.14] - 2026-08-30

### Corrigé

#### Des libellés traduits nommaient le framework
- Une installation cliente est le site de quelqu'un : son nom dans les
  réglages, son domaine, sa marque. Cinq chaînes disaient « Aurora » quand
  même, et deux disaient quelque chose de faux : le badge gris « Fourni par
  Aurora » à côté d'un type de contenu veut dire que le type est natif, pas
  qu'Aurora fournit le contenu.
- Les deux badges `built_in` deviennent « Natif » / « Native » / « Built-in ».
- Le placeholder du titre de bandeau devient « Bienvenue » / « Welcome ».
- Le placeholder du nom du site devient « Mon entreprise » / « My company ».
  Il se trouvait dans le champ même qu'un propriétaire modifie pour nommer son
  site, et proposait le nom du framework comme exemple. Le fichier anglais
  portait en plus la chaîne française telle quelle.
- Un test lit désormais chaque fichier de traduction sous `src/` et échoue sur
  toute valeur traduite qui nomme le framework.

### Dans aurora-client

Rien à faire.

## [0.9.13] - 2026-08-30

### Supprimé

#### Dix-huit modules de `shared/`, résidus du split abandonné
- Tous étaient sans consommateur. Leurs anciens appelants sont partis avec les
  modules extraits du monorepo (Billing, Crm, Tools, Erp, Ecommerce,
  PersonalFinance, Assistant) ou avec des panneaux Editorial retirés au même
  moment. **Les quatorze dépôts `aurora-*` correspondants sont archivés** :
  seuls aurora-core et aurora-client sont vivants, et aucun des deux n'importe
  ces fichiers.
- Composables : `useDetailDelete`, `useFormModal`, `useInlineEdit`,
  `useSlugLock`, `useLoadMore`, `useUrlPagination`, `useInfiniteScroll`,
  `usePasswordGenerator`.
- Utilitaires : `parseJson`, `blocksRenderer`, `mergeBlocks`, `revisionDiff`,
  `currencies`, `formatPrice`, `parseMoney`, `pickTranslation`, `seoCounter`,
  `passwordStrength`.
- Soit 28 fichiers avec leurs tests, environ 1700 lignes. Les entrées
  correspondantes sortent de `composables_catalog.md`, et les deux mémoires qui
  leur étaient dédiées (`composable_url_pagination`, `utility_pick_translation`)
  disparaissent avec leur index.

#### Ce que cette suppression a coûté en allers-retours
- La 0.9.11 avait retiré `useSlugLock` seul, la 0.9.12 l'avait restauré parce
  qu'`aurora-editorial` l'importe. Les deux décisions reposaient sur une
  question jamais posée : ces dépôts sont-ils vivants ? Ils ne le sont pas.
  La mémoire du projet le disait déjà pour Editorial, revenu dans le core en
  août ; l'API GitHub le confirme pour les treize autres.
- **La vérification qui compte n'est pas « qui importe ce fichier », c'est
  « ce consommateur existe-t-il encore ».** Un `grep` dans les dépôts frères
  répond oui à la première et masque la seconde.

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`. Un projet client qui importerait
l'un de ces fichiers depuis `@/shared/` doit en revanche le copier chez lui
avant de mettre à jour ; l'historique git d'aurora-core en conserve la version
exacte.

## [0.9.12] - 2026-08-30

### Corrigé

#### `useSlugLock` est restauré : la 0.9.11 l'a supprimé à tort
- La 0.9.11 affirmait que rien ne l'utilisait. C'est faux, et la vérification
  était incomplète : elle portait sur aurora-core et sur un projet client, pas
  sur les **paquets de modules extraits**. `aurora-editorial` l'importe depuis
  `@/shared/composables/form/useSlugLock.js` et l'utilise avec son cadenas
  (`slugLocked`, `toggleSlugLock`) dans son éditeur de post. La 0.9.11 casse
  donc ce paquet ; **ne restez pas dessus si vous installez `aurora-editorial`**.
- Le composable, son test et son entrée au catalogue sont remis en place à
  l'identique. L'entrée précise désormais qui le consomme, en quoi il diffère
  de `slugifyIfEmpty`, et qu'il ne doit être câblé qu'avec son `toggle` affiché.

#### La leçon, pour les prochains ménages
- `shared/` du core est consommé par les paquets frères, pas seulement par
  aurora-core et les projets clients. Un `grep` dans ce dépôt ne prouve donc
  rien : dix-sept autres modules de `shared/` ressortent « inutilisés » au même
  test, et se révèlent tous être des dépendances vivantes de `aurora-billing`,
  `aurora-crm`, `aurora-tools`, `aurora-erp` et consorts, ou des modules
  Editorial retirés du monorepo au moment du split.
- Aucun d'eux n'est supprimé. Avant de retirer quoi que ce soit de `shared/`,
  la vérification doit couvrir les dépôts `aurora-*` du compte.

### Dans aurora-client

Rien à faire. Un projet client qui serait passé par la 0.9.11 sans installer
`aurora-editorial` n'a rien vu ; la mise à jour rétablit simplement le fichier.

## [0.9.11] - 2026-08-30

### Supprimé

#### `useSlugLock`, jamais utilisé et redondant depuis la 0.9.10
- Le composable n'était appelé nulle part, ni dans aurora-core ni dans un
  projet client, alors qu'il était documenté au catalogue. Une API annoncée
  que personne n'a jamais branchée est une promesse que rien ne vérifie.
- Il est surtout redondant depuis que la 0.9.10 a câblé `slugifyIfEmpty()`
  pour le même besoin. Deux mécanismes pour dériver un slug d'un titre, c'est
  la garantie qu'un jour le mauvais soit choisi : celui-ci est verrouillé par
  défaut, donc le slug y suit le titre en permanence, y compris sur un contenu
  publié dont l'URL est déjà partagée.
- Son entrée est retirée de `composables_catalog.md`. `slugify()` et
  `slugifyIfEmpty()`, qui y figuraient déjà, restent la façon de faire.

> Le motif « cadenas ouvrable » n'est pas mauvais en soi, il demande juste
> l'affordance qui va avec, un bouton pour déverrouiller. Si le besoin revient,
> l'historique git contient la quinzaine de lignes.

### Dans aurora-client

Rien à faire, sauf pour un projet qui importerait `useSlugLock` depuis
`@/shared/composables/form/`. Aucun ne le fait à notre connaissance. Le
remplacement tient en un appel :

```js
translation.slug = slugifyIfEmpty(translation.slug, translation.title);
```

Il ne remplit que si le slug est vide, donc ne réécrit jamais une URL existante.

## [0.9.10] - 2026-08-30

### Modifié

#### L'éditeur de post utilise enfin les composants maison
- Les deux champs de date, « publier le » et « dépublier le », étaient des
  `<AppInput type="datetime-local">`, donc le sélecteur natif du navigateur :
  apparence différente à chaque navigateur, et sans rapport avec le reste du
  backend. Ils passent à `AppDatePicker` avec `enable-time`, qui émet
  exactement le même format `YYYY-MM-DDTHH:MM` : aucune donnée existante n'est
  affectée.
- « Commentaires ouverts » était une case à cocher là où le projet rend
  partout ailleurs ce genre de bascule avec `AppToggle`. C'est désormais un
  interrupteur.

### Ajouté

#### Le slug se remplit depuis le titre
- À la saisie du titre, le slug de la même langue se remplit **s'il est vide**,
  et seulement dans ce cas. Écrire par-dessus un slug existant changerait l'URL
  d'un contenu déjà publié sur une simple correction de titre, et casserait le
  référencement sans rien dire.
- `slugifyIfEmpty()` existait déjà dans `shared/utils/format/slugify.js`, avec
  cette sémantique exacte et ses tests, mais n'était utilisé nulle part. Il est
  simplement câblé.
- Le comportement est par langue : le titre français remplit le slug français.
  Changer d'onglet de langue ne déclenche rien, une traduction qui a déjà un
  titre sans slug n'en reçoit pas un que personne n'a demandé.

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`. Aucun format de données ne
change, et le remplissage du slug ne touche jamais une valeur existante.

> À noter pour plus tard : `shared/composables/form/useSlugLock.js` est du code
> mort lui aussi, mais sa sémantique diffère. Il est verrouillé par défaut,
> donc le slug suit le titre en permanence, y compris sur un contenu publié.
> Le câbler tel quel réécrirait des URLs en production.

## [0.9.9] - 2026-08-30

### Corrigé

#### Les notifications à l'administrateur partaient vers un domaine inexistant
- `backend_email` était semé avec `admin@aurora.app`, et `MailService::adminEmail()`
  retournait cette valeur telle quelle. Or `sendToAdmin()` est appelé par
  `FormNotificationService` et `CommentNotificationService` : sur toute
  installation où personne n'a pensé à changer ce réglage, **chaque soumission
  de formulaire et chaque commentaire en attente partait vers un domaine qui
  n'existe pas**, sans erreur visible nulle part.
- `adminEmail()` retombe désormais sur `ADMIN_EMAIL`, la variable que
  l'installateur du serveur a de toute façon renseignée, quand le réglage est
  vide ou porte encore la valeur semée. À défaut des deux, elle retourne `null`
  et `sendToAdmin()` s'abstient, ce qui est honnête : ne pas envoyer vaut mieux
  qu'envoyer vers une adresse qui rebondit.

#### `site_url` hors requête ne connaissait que le placeholder
- `Context::siteUrl()` savait déjà ignorer le `http://localhost` semé et prendre
  l'origine de la requête, ce qui rend les balises canoniques correctes sur un
  site en ligne. Mais hors requête, une commande console ou un worker Messenger,
  il retournait le placeholder faute de mieux.
- Il consulte maintenant le contexte de routage, que le framework remplit depuis
  `DEFAULT_URI`. Un sitemap ou une balise canonique rendus par le worker nomment
  donc l'hôte que le déploiement a déjà déclaré. Le placeholder ne subsiste que
  si rien n'est configuré nulle part.

#### Les deux paramètres sont semés vides
- `site_url` et `backend_email` arrivaient avec `http://localhost` et
  `admin@aurora.app`. Une valeur plausible affichée dans l'écran de réglages se
  lit comme un choix déjà fait : personne ne la corrige. Vide, le champ dit ce
  qu'il est, et les deux méthodes ci-dessus savent quoi en faire.
- Les installations existantes ne sont pas touchées en base : les anciennes
  valeurs sont reconnues comme des placeholders par le code, donc corrigées de
  fait sans migration.

### Dans aurora-client

`make aurora-update` suffit. Aucune action en base n'est nécessaire : une
installation qui porte encore `admin@aurora.app` ou `http://localhost` se met à
utiliser `ADMIN_EMAIL` et `DEFAULT_URI` dès la mise à jour.

Vérifiez tout de même que `ADMIN_EMAIL` pointe sur une adresse réelle dans le
`.env.local` du serveur : c'est elle qui reçoit désormais les notifications si
le réglage n'a jamais été renseigné.

## [0.9.8] - 2026-08-30

### Corrigé

#### Les permissions documentées cassaient le premier déploiement
- Le §6 du guide de déploiement prescrivait `chown -R www-data:www-data var/`
  puis `chmod g+rX`. Or `deploy-prod` tourne sous un compte humain et écrit dans
  `var/cache` via `cache:clear` : en suivant la doc à la lettre, le déploiement
  échoue dès `make cc-prod`. Cette recette n'est correcte que si le déploiement
  s'exécute **en tant que** `www-data`, ce que le Makefile ne fait pas.
- Le §6 décrit maintenant le montage qui tient : propriétaire au déployeur,
  groupe `www-data`, setgid sur les répertoires de `var/`, `umask 0002`. Il
  ajoute que `.env.local` doit rester lisible par `www-data` : en `600`, PHP-FPM
  ne le lit plus et Symfony retombe silencieusement sur les valeurs de `.env`.

#### L'OPcache était présenté comme un absolu
- « reset après chaque déploiement » ne vaut que si
  `opcache.validate_timestamps=0`. Avec le réglage par défaut des paquets Debian
  et Ubuntu (`On`, `revalidate_freq=2`), PHP reprend les fichiers modifiés tout
  seul et le reset ne sert à rien. Énoncé sans condition, il pousse à ajouter au
  déploiement un `systemctl reload php8.4-fpm`, donc une exigence de root
  souvent inutile. Le §7 distingue désormais les deux cas et donne la commande
  pour savoir dans lequel on est.

#### Versions de PostgreSQL périmées
- `joining_a_project.md` proposait `serverVersion=16` et le README du template
  annonçait « Postgres 16 », alors que tout le reste du projet est sur 18.

#### §10 décrivait une séquence que `deploy-prod` fait désormais lui-même
- Remplacée par `git checkout <tag> && make deploy-prod`, avec le renvoi vers
  §7 pour le seul cas où un reload de PHP-FPM reste nécessaire.

### Ajouté

#### `server_provisioning.md` : d'une machine nue à l'application servie
- La doc de déploiement partait d'un serveur déjà provisionné, sans dire comment
  y arriver. Le nouveau document couvre les paquets, la création du rôle et de
  la base PostgreSQL (le rôle applicatif n'a besoin ni de `SUPERUSER` ni de
  `CREATEDB`), le modèle de permissions, le vhost Apache complet, et HTTPS.
- **HTTPS n'était mentionné nulle part** dans les 68 fichiers de doc, alors que
  le guide fait poser `DEFAULT_URI=https://…`. Le document explique certbot et
  surtout ce qu'il fait au vhost : il en recopie une version 443, modifie
  l'original pour la redirection, et le fichier d'origine cesse donc de décrire
  l'état réel du serveur.
- Le vhost documenté pose `Options +FollowSymLinks`, ce qui n'est pas
  décoratif : `public/build` est un lien symbolique vers
  `vendor/axelraboit/aurora/public/build`. Le bloc `<Directory /var/www/>`
  livré par Ubuntu l'active par défaut, mais tout durcissement qui pose
  `-FollowSymLinks` renvoie 403 sur la totalité des assets Vite sans que rien
  n'explique pourquoi.

#### §11 Sauvegardes
- La doc avertissait en gras que `AURORA_MOUNT_POINT_KEY` ne doit jamais changer
  sous peine de rendre les MountPoints illisibles, sans jamais dire de
  sauvegarder le fichier qui la contient. Le §11 liste les trois choses qui ne
  se reconstruisent pas depuis git (`.env.local`, la base, `var/uploads/`), et
  insiste sur les deux points qui distinguent une sauvegarde d'un fichier qui
  grossit : vérifier le dump à la production avec `pg_restore --list`, et avoir
  testé une restauration réelle au moins une fois.

### Dans aurora-client

`make aurora-update` récupère le README du template (mention PostgreSQL). La
doc, elle, est livrée avec le paquet : elle est à jour dans
`vendor/axelraboit/aurora/docs/` dès la mise à jour.

Si un serveur existant a été monté en suivant l'ancien §6, vérifiez que le
compte qui déploie peut écrire dans `var/` : `deploy-prod` échouerait sinon dès
le cache prod.

## [0.9.7] - 2026-08-30

### Corrigé

#### Dernier reste de français dans le `Makefile`
- La cible `tag`, dépréciée, affichait son explication en français. C'était le
  dernier endroit du fichier à s'écarter de l'anglais, la 0.9.6 ayant traité
  les trois cibles ajoutées en 0.9.5. Les messages `echo` du template sont
  désormais homogènes de bout en bout.

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`, qui récupère le `Makefile` par
`sync-makefile`. Aucun comportement ne change : `make tag` refuse toujours de
s'exécuter et renvoie vers la publication depuis `master`.

## [0.9.6] - 2026-08-30

### Corrigé

#### Les cibles ajoutées en 0.9.5 parlaient français
- `worker-stop`, `worker-start` et `deploy-check` sortaient leurs messages en
  français, alors que le `Makefile` est en anglais de bout en bout : aide des
  cibles, commentaires de recette et messages `echo` (`✅ Runtime directories
  created`, `❌ Refused: target is destructive`, `⚠️ aurora-core's nested
  vendor/ is missing`). Tout est repassé en anglais, commentaires compris.

```
🔎 Post-deploy checks
  ✅ deployed version: v0.1.14
  ✅ the application boots in prod
  ✅ no pending migration
  ✅ worker aurora-worker is active (started Sun 2026-08-30 13:10:22 UTC)
  ✅ no failed message
  ✅ https://app.example.com answers 200
✅ All green.
```

> À noter, hors périmètre de cette version : la cible `tag` est le seul autre
> endroit du `Makefile` dont les messages sont en français. Elle est
> dépréciée et ne fait qu'afficher une explication, donc rien ne presse, mais
> c'est la dernière exception.

## [0.9.5] - 2026-08-30

### Ajouté

#### Le worker est arrêté pendant le déploiement, et relancé après
- `deploy-prod` arrête le worker Messenger avant de toucher au code, et le
  relance une fois le cache prod reconstruit. Sans ça le worker consomme des
  messages pendant que `vendor/` et `var/cache/` sont à moitié remplacés, et il
  garde son ancien code en mémoire après coup : un changement de `.env.local` ou
  de code ne l'atteignait qu'à son `--time-limit`, une heure plus tard.
- Un `trap` relance le worker même si le déploiement échoue en cours de route.
  Un déploiement interrompu qui laisse le worker éteint est pire que pas de
  déploiement du tout : la file se remplit en silence.
- Nouvelles cibles `worker-stop` et `worker-start`, pilotées par la variable
  `WORKER_SERVICE` (défaut `aurora-worker`). Elles ne font rien s'il n'y a pas
  de systemd, si l'unité n'existe pas, ou si la variable est vidée, ce qui
  garde le template agnostique de l'infra. Le nom se surcharge dans
  `Makefile.local`.

#### `make deploy-check`
- Nouvelle cible, lancée à la fin d'`install-prod` et de `deploy-prod`, qui dit
  ce qui va et ce qui ne va pas plutôt que d'afficher un `✅ Deployed` de
  principe :

```
🔎 Vérifications post-déploiement
  ✅ version déployée : v0.1.13
  ✅ l'application boote en prod
  ✅ aucune migration en attente
  ✅ worker aurora-worker actif (démarré Sun 2026-08-30 14:52:01 UTC)
  ✅ aucun message en échec
  ✅ https://app.example.com répond 200
✅ Tout est vert.
```

- Chaque ligne dégrade proprement : `➖` quand la vérification ne s'applique pas
  (pas de systemd, pas de `DEFAULT_URI`, pas de `curl`) plutôt qu'un faux
  négatif. La cible sort en 1 si au moins une vérification échoue.
- Elle est utilisable seule, à tout moment, pour savoir dans quel état est un
  serveur.

### Dans aurora-client

`make aurora-update` récupère le `Makefile` corrigé via `sync-makefile`.

`worker-stop` et `worker-start` appellent `sudo systemctl`. Sur un serveur où
le `sudo` de l'utilisateur de déploiement demande un mot de passe, le
déploiement le réclamera deux fois. Pour un déploiement non interactif, poser
une règle sudoers limitée à ce seul service :

```
# /etc/sudoers.d/aurora-worker
<user> ALL=(root) NOPASSWD: /usr/bin/systemctl start aurora-worker, /usr/bin/systemctl stop aurora-worker, /usr/bin/systemctl restart aurora-worker
```

## [0.9.4] - 2026-08-30

### Corrigé

#### `make deploy-prod` cassait la build et mentait sur le résultat
- La cible inlinait `pnpm --dir=vendor/axelraboit/aurora run build` au lieu
  d'appeler `make build`, et perdait au passage `$(AURORA_ENV)`, donc
  `AURORA_CLIENT_DIR`. C'est la variable que le hook `prebuild` d'aurora-core
  (`bin/dump-translations`) lit pour savoir quelle console lancer : sans elle il
  lance **celle d'aurora-core**, qui boote son propre kernel depuis son vendor
  imbriqué installé en `--no-dev`. La build mourait sur
  `Class "Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle" not found`,
  et les traductions JS des modules du client n'étaient de toute façon jamais
  dumpées.
- Les étapes étaient chaînées par `;`, donc l'échec n'arrêtait rien : le
  déploiement continuait et affichait `✅ Deployed vX.Y.Z`. Un `set -e` ouvre
  maintenant la séquence.
- `deploy-prod` passe par `make build-prod` et `make cc-prod` plutôt que de
  réécrire ces étapes à la main. C'était la cause racine : une copie qui dérive
  de l'original.

#### `install-prod` installait les linters sur le serveur de prod
- `make build` dépend d'`aurora-vendor-guard`, qui restaure les outils de lint
  d'aurora-core (php-cs-fixer, phpstan, rector, twig-cs-fixer) quand il ne les
  trouve pas. Sur un serveur neuf il ne les trouve jamais : le premier
  `install-prod` les installait tous.
- Nouvelle cible `build-prod`, même build sans le guard. `install-prod` et
  `deploy-prod` l'utilisent. Le seul vendor dont la build a besoin, celui
  imbriqué d'aurora-core, est restauré par leur étape dédiée.
- Constaté sur un serveur de production : `vendor/axelraboit/aurora/tools/*/vendor`
  était peuplé sur le serveur.

### Dans aurora-client

`make aurora-update` récupère le `Makefile` corrigé via `sync-makefile`.

Sur un serveur déjà déployé, les linters d'aurora-core sont peut-être déjà
installés. Ils ne gênent pas, mais ils n'ont rien à y faire :

```bash
rm -rf vendor/axelraboit/aurora/tools/*/vendor
```

## [0.9.3] - 2026-08-30

### Corrigé

#### Les fixtures sortent de `src/`, ce que 0.9.2 n'avait fait qu'à moitié
- 0.9.2 excluait les `DataFixtures/` du driver attribut de Doctrine. Le
  premier déploiement en production a montré que ça ne suffisait pas : un second
  scanner autoload les mêmes fichiers, le loader de routes attributaires, via le
  `resource: '../vendor/axelraboit/aurora/src/' type: attribute` du
  `routes.yaml` client. `cache:clear --env=prod` mourait donc toujours sur
  `Class "Doctrine\Bundle\FixturesBundle\Fixture" not found`.
- Excluer scanner par scanner est une liste à maintenir à chaque nouveau
  mécanisme qui parcourt `src/`. Les fixtures déménagent donc dans `fixtures/`,
  hors de `src/`, avec le namespace `Aurora\Fixtures\<Module>` et son propre
  PSR-4. Aucun scanner ne visite ce répertoire : le problème disparaît par
  construction plutôt que par énumération.
- `ExcludeDataFixturesFromMappingPass`, introduit en 0.9.2, est retiré : il
  n'a plus rien à exclure.
- `PlanningDemoFixtures` n'était enregistrée par aucun bloc `when@dev:` ; elle
  ne tenait que par le glob `Aurora\:` sur `src/`. Le nouveau glob
  `Aurora\Fixtures\: '../fixtures/'` la déclare explicitement, avec les
  quatre autres.
- PHPStan et Rector analysaient `src/` : les deux sont recâblés sur `fixtures/`
  pour ne pas perdre la couverture au passage. Leur config étant partagée avec
  les projets clients, qui n'ont pas ce répertoire, le chemin est ajouté en CLI
  côté core pour PHPStan et filtré par `is_dir()` pour Rector.

### Dans aurora-client

Rien à faire si les fixtures du projet sont dans `src/DataFixtures/`, comme le
veut la convention : aucun scanner ne visite ce répertoire.

En revanche, **une fixture posée sous `src/Module/<X>/DataFixtures/` casse la
prod**. `config/routes.yaml` importe `../src/Module/` en `type: attribute` et
`doctrine.yaml` mappe le même répertoire ; les deux autoloadent chaque classe
qu'ils croisent. La déplacer vers `src/DataFixtures/`, ou la supprimer si elle
ne sert plus.

> ⚠️ Le même trou reste ouvert pour les modules extraits en paquets séparés :
> leur `DataFixtures/` vit dans le répertoire du paquet, et
> `AuroraModuleRouteLoader` importe ce répertoire entier en `type: attribute`.
> Aucun module n'est extrait aujourd'hui, mais le premier qui ship des fixtures
> reproduira le bug.

## [0.9.2] - 2026-08-30

### Ajouté

#### Mémoire : Resend est le transport mail de la prod
- `MAILER_DSN` en production passe par `resend+api://API_KEY@default`. Le pont
  `symfony/resend-mailer` est déjà dans le `require` d'aurora-core, donc il
  survit à `composer install --no-dev` : un serveur neuf n'a besoin que de la
  clé.
- Le piège documenté est le défaut `smtp://localhost:1025` de `.env` : sur un
  serveur dont le `.env.local` ne redéfinit pas la variable, l'application
  démarre sans erreur et n'envoie rien, sans alerte. La réinitialisation de mot
  de passe est cassée en silence.
- La mémoire note aussi qu'après un changement de DSN il faut redémarrer le
  worker Messenger en plus de recharger PHP-FPM : le worker a lu `.env.local` à
  son boot, et l'essentiel des mails passe par le transport `async`.
- Nouveau fichier `.claude/memory/aurora-client/convention_mailer_resend_prod.md`,
  indexé dans `.claude/memory/aurora-client/MEMORY.md`.

### Corrigé

#### Un `composer install --no-dev` ne bootait pas
- `PdfThumbnailGenerator` type-hinte `Symfony\Component\Process\ExecutableFinder`
  en service autowiré, mais `symfony/process` n'était déclaré nulle part. Le
  paquet n'arrivait que par ricochet, via le `require-dev` d'un projet client
  (`symfony/maker-bundle`). En prod il disparaissait, et la compilation du
  container mourait sur `Class "Symfony\Component\Process\ExecutableFinder"
  not found`. `symfony/process` passe en `require`.
- Les fixtures vivent sous `src/Core/DataFixtures/` et `src/Module/*/DataFixtures/`,
  donc à l'intérieur des répertoires que le driver Doctrine parcourt. Or
  `getAllClassNames()` autoload chaque fichier avant de demander si c'est une
  entité, et ces classes étendent le `Fixture` de doctrine-fixtures-bundle,
  absent en prod. `doctrine:schema:create` et tout warmup touchant aux métadonnées
  ORM tombaient sur `Class "Doctrine\Bundle\FixturesBundle\Fixture" not found`.
- `config/services.yaml` posait déjà ce garde-fou côté container (exclusion du
  glob `Aurora\`, ré-enregistrement sous `when@dev`) ; il manquait la moitié
  mapping. `ExcludeDataFixturesFromMappingPass` appelle désormais
  `addExcludePaths()` sur le driver attribut. Les fixtures ne portent aucun
  attribut ORM, donc l'exclusion ne coûte rien en dev non plus.
- Les deux bugs ne se voyaient qu'au premier déploiement d'un client sur un
  serveur neuf. Constatés au premier déploiement en production le 30/08/2026.

#### `make install-prod` ne passait pas sur un serveur neuf
- La cible enchaînait `composer install --no-dev` puis
  `pnpm --dir=vendor/axelraboit/aurora install`. Le premier ré-extrait
  aurora-core et efface au passage son `vendor/` imbriqué, celui que son
  `package.json` vise avec `"@symfony/ux-vue": "file:vendor/symfony/ux-vue/assets"`.
  Le pnpm mourait donc sur un `ENOENT` désignant un chemin que personne n'a
  écrit à la main. `make build` réparait déjà le cas via `aurora-vendor-guard`,
  mais il tourne après. Le template restaure maintenant le vendor imbriqué
  entre les deux, sans les linters qui n'ont rien à faire sur un serveur de
  prod. `deploy-prod` avait le même trou, il est corrigé aussi.
- La cible appelait ensuite `make migrate-f`, alors que sur une base vierge la
  chaîne de migrations plante : Doctrine Migrations 3.x traite les namespaces
  dans leur ordre de déclaration et non par version, donc une
  `ClientMigrations` qui étend une table core passe avant l'`AuroraMigrations`
  qui la crée. Nouvelle cible `db-install-prod` : `schema:create`, marquage de
  toutes les migrations comme appliquées, puis `messenger:setup-transports`
  (`messenger_messages` vient d'une migration qu'on vient de marquer sans la
  jouer, et le DSN porte `auto_setup=0`, donc rien d'autre ne créerait la
  table). `deploy-prod` garde `migrations:migrate` : sur un serveur déjà
  installé la chaîne est incrémentale et le problème d'ordre ne se pose pas.
- `docs/aurora-client/deployment/README.md` §1 est aligné sur la nouvelle
  séquence.

### Dans aurora-client

`make aurora-update` suffit : il lance `sync-makefile`, qui recopie le template
corrigé sur le `Makefile` du projet. La mémoire arrive par le même chemin, via
son symlink. Les deux correctifs de dépendances sont internes au bundle.

Une réserve sur `sync-makefile` : il refuse d'écraser un `Makefile` qui porte
des modifications non commitées. Si c'est le cas, commiter ou déplacer les
cibles custom dans `Makefile.local` avant de lancer la mise à jour.

Si un projet a contourné l'un des deux bugs de packaging en ajoutant
`symfony/process` ou `doctrine/doctrine-fixtures-bundle` au `require` de son
`composer.json`, les deux lignes peuvent être retirées après
`make aurora-update`.

## [0.9.1] - 2026-08-30

### Corrigé

#### La suite de tests écrivait dans le vrai dossier d'uploads
- `app.upload_dir` avait la même valeur dans tous les environnements : les
  tests d'intégration déposaient leurs uploads dans `var/uploads`, celui que
  l'installation du développeur sert, et rien ne les retirait. 1184
  `pixel-*.png` et leurs variantes s'y étaient accumulés depuis août.
- On ne l'a vu que parce que `aurora:ged:prune-orphans`, sorti en 0.9.0, les a
  signalés : la commande écrite pour trouver les orphelins rapportait les
  déchets de la suite de tests.
- L'environnement de test écrit désormais dans `var/test-uploads`, vidé avant
  chaque classe. La surcharge est dans `config/services_test.yaml` et non
  `config/packages/test/` : MicroKernelTrait importe `config/services.yaml`
  **après** `config/packages/{env}/`, donc une surcharge placée là est lue puis
  aussitôt écrasée par la valeur par défaut. Le vidage vérifie le chemin avant
  d'effacer.

### Dans aurora-client

Rien à faire, sauf si le projet a ses propres tests d'intégration qui uploadent :
dans ce cas, copier `config/services_test.yaml`.

## [0.9.0] - 2026-08-30

### Ajouté

#### La colonne Aperçu ouvre la modale de prévisualisation
- En mode grille, cliquer une carte ouvrait déjà la modale du document. En
  mode liste, la colonne Aperçu montrait la même vignette et ne faisait rien.
- La cellule est maintenant un vrai bouton : accessible au clavier, anneau de
  focus, `aria-label` qui nomme le document. Elle respecte le mode sélection
  comme les cartes.

#### `aurora:ged:prune-orphans`
- Liste, et avec `--force` supprime, les fichiers de `var/uploads/ged/` qu'aucune
  ligne ne référence. Le correctif 0.8.1 arrête les nouveaux orphelins ; celui-ci
  s'occupe de ceux déjà présents.
- Refuse de toucher aux fichiers récents : l'endpoint d'upload écrit les octets
  avant la soumission du formulaire, donc un fichier sans ligne est peut-être
  simplement un formulaire ouvert. `--days` vaut 7 par défaut.
- À blanc par défaut ; rien n'est supprimé sans `--force`.

### Modifié

- Les fixtures de démonstration n'embarquent plus de photographies
  personnelles : `me.jpg` et `previous_job.jpg` sont remplacées par deux
  dégradés générés aux mêmes dimensions.

### Dans aurora-client

Pour récupérer l'espace disque des anciennes suppressions :

```bash
php bin/console aurora:ged:prune-orphans          # liste, ne supprime rien
php bin/console aurora:ged:prune-orphans --force  # supprime
```

## [0.8.2] - 2026-08-30

### Ajouté

- Un test d'intégration qui supprime un vrai document, avec ses vrais fichiers
  et une vraie ligne de version, contre le schéma réel. Les tests de 0.8.1
  couvraient la logique et les deux requêtes de garde, mais aucun n'exerçait
  le `ON DELETE CASCADE` - c'est-à-dire précisément ce qui rendait les
  fichiers de version irrécupérables.

### Dans aurora-client

Rien à faire.

## [0.8.1] - 2026-08-30

### Corrigé

#### Supprimer un document GED laissait ses fichiers sur le disque
- `DocumentManager::delete()` et `bulkDelete()` effacent maintenant le fichier
  du document, sa vignette générée, et le fichier de chacune de ses versions.
  Seules les variantes d'image étaient effacées ; tout le reste restait sur le
  disque indéfiniment. Les lignes de version disparaissant par un
  `ON DELETE CASCADE`, plus aucune ligne ne nommait ces fichiers : ils étaient
  irrécupérables autrement qu'à la main.
- Un garde-fou précède chaque effacement : un chemin encore référencé par une
  ligne survivante - document ou version - n'est pas touché. Ce n'est pas
  théorique, `recordVersion()` fait délibérément pointer une ligne de version
  sur le `filePath` du document vivant.
- L'effacement passe après le `flush` : un `flush` en échec ne coûte plus ses
  octets à personne.
- Neuf tests, dont trois d'intégration qui exécutent les deux requêtes de
  garde contre le schéma réel.

### Dans aurora-client

Rien à faire. Les fichiers déjà orphelins d'anciennes suppressions restent sur
le disque : ils ne sont plus référencés nulle part et se retirent à la main
dans `var/uploads/ged/`.

## [0.8.0] - 2026-08-30

### Ajouté

#### Premier compte d'une installation
- `aurora:user:create --dev` donne `ROLE_DEV`, à côté de `--admin` qui reste.
  `/dev` étant gardé par `ROLE_DEV`, un propriétaire créé avec `--admin` - ce
  que `aurora:install` recommandait - ne pouvait pas ouvrir le tableau de bord
  qui active les modules sur son propre site. Les deux options passées
  ensemble donnent `ROLE_DEV`, la plus large.
- `aurora:install` recommande désormais `--dev`.
- Quatre tests sur cette commande, qui n'en avait aucun.

### Dans aurora-client

Rien à faire. Sur une installation existante, un compte déjà créé en
administrateur se corrige avec :

```bash
php bin/console aurora:user:role <email> ROLE_DEV
php bin/console aurora:user:role <email> ROLE_ADMIN --remove
```

## [0.7.4] - 2026-08-29

### Corrigé

#### On ne pouvait créer le premier élément de rien

Sur quatre écrans, le bouton de l'état vide ne faisait rien : les modales
vivaient dans la branche `v-else` que cet état vide remplace, donc elles
n'existaient pas tant que la liste était vide. Le bouton levait son drapeau,
rien ne l'écoutait.

Seulement le premier élément était concerné : dès qu'un existe, la branche se
rend et tout fonctionne. C'est pourquoi ça a survécu - le défaut est invisible
sur toute base contenant déjà des données.

| Écran | Ce qui était impossible |
|---|---|
| Formulaires | créer le premier formulaire |
| Types de publication | créer le premier type |
| Taxonomies | créer la première taxonomie |
| Dossiers GED | créer le premier dossier |

### Ajouté

- `EmptyStateCanReachItsModalTest` : un état vide dont le bouton ouvre une
  modale absente de l'arbre fait échouer la CI.
- `FormsApp.test.js` : le composant monte sa modale même sans aucun formulaire.

### Dans aurora-client

Rien à faire.

## [0.7.3] - 2026-08-29

### Corrigé

#### Notes
- **Le partage d'une note échouait.** Les quatre chemins étaient produits par
  le générateur de vue, jamais passés par le gabarit, jamais déclarés par le
  composant : le front appelait une URL indéfinie. L'aperçu des notes liées
  restait vide pour la même raison.

#### GED
- **Créer, renommer, supprimer et déplacer un dossier depuis la barre latérale
  des documents ne faisait rien**, ainsi que le déplacement groupé. Même cause,
  trouvée par le garde-fou ajouté pour la précédente. Les props ont un défaut
  vide, donc Vue n'avertissait de rien.

#### Thèmes
- L'éditeur de thème proposait encore l'ancienne couleur indigo alors que
  l'application est verte : deux copies JS du défaut, dont une seule avait
  bougé.

### Ajouté

#### Garde-fous
- `ViewBuilderPropsReachTheTemplateTest` : une clé produite par un générateur
  de vue et jamais transmise fait échouer la CI.
- `ThemeDefaultColourMirrorTest` : la couleur par défaut doit être identique
  en PHP et dans les deux écrans de thème.

### Dans aurora-client

Rien à faire.

## [0.7.2] - 2026-08-29

### Changé

#### Accessibilité du frontend
- Une carte de publication placée dans la grille d'une page était un `<a>`
  enveloppant tout son contenu : un lecteur d'écran annonçait la vignette, le
  titre et le résumé comme un seul nom de lien. Le lien n'entoure plus que le
  titre et étire une surcouche sur la carte - toute la surface reste cliquable,
  le nom se limite au titre. Même montage que les cartes de liste.

### Dans aurora-client

Rien à faire.

## [0.7.1] - 2026-08-29

### Changé

#### Frontend
- Une carte de publication placée dans la grille d'une page survolait
  différemment de la carte identique des listes : bordure grise au lieu de
  foncer, titre vers la couleur du texte au lieu de l'accent, et aucun fond.
  Les deux se comportent désormais pareil.

#### Notes
- Le cadre de l'éditeur markdown est rétabli, ainsi que celui du champ
  d'étiquettes.

### Dans aurora-client

Rien à faire.

## [0.7.0] - 2026-08-29

Mineure et non patch : la couleur d'accentuation par défaut change, ce qui se
voit sur toute l'application d'un client qui n'a pas défini la sienne.

### Changé

#### Identité visuelle
- **La couleur d'accentuation par défaut passe de l'indigo au vert** (emerald,
  graine `#10b981`). Mesuré avant de choisir : le générateur de palette fige la
  luminosité de chaque palier, donc la teinte bouge sans emporter le contraste
  (blanc sur `accent-600` : 5,52:1 contre 6,76:1 auparavant, tous deux au-delà
  des 4,5:1 requis). L'indigo en dur des gabarits d'e-mail suit.
- **Le crédit du pied de page devient le copyright de l'application** :
  `© {année} {nom du site}`, lu depuis le réglage `site_name`. Il affichait
  « Propulsé par Aurora · © axelraboit » avec un lien vers le GitHub de
  l'auteur, ce qui crédite le mauvais produit sur le déploiement d'un client.

#### Notes
- Le mode scindé n'affichait que l'éditeur sur une fenêtre étroite : le volet
  prenait une largeur figée en pixels qui ne tenait plus une fois le menu et
  l'arborescence servis. Il est désormais plafonné à une part de la place
  disponible, et s'empile verticalement sous `md`.
- L'éditeur markdown n'a plus de cadre : il remplit son volet, dont les bords
  disent déjà où il commence.
- L'arborescence portait un `z-index` à toutes les tailles alors qu'elle n'en a
  besoin qu'en tiroir mobile ; elle passait par-dessus le fil d'Ariane au
  défilement.

### Ajouté

#### Garde-fous
- `SidemenuSectionThemeTest` : une section de menu sans couleur fait échouer la
  CI. `planning` et `notes` retombaient sur la palette d'accentuation et
  paraissaient oubliées.
- `StickyHeaderStackingTest` : un panneau qui garde son `z-index` au-delà du
  seuil où il cesse de flotter fait échouer la CI.
- `ClientReadmeLinksTest` : chaque chemin cité par le README client doit
  exister.

### Dans aurora-client

**Bumper la contrainte** dans `composer.json`, `^0.6` ne prend pas les 0.7 :

```bash
composer require axelraboit/aurora:^0.7
make aurora-update && make ft
```

Si le projet a défini sa propre couleur primaire dans un thème, rien ne change
visuellement. Sinon, l'application passe au vert. Pour rester en indigo,
renseigner `#6366f1` comme couleur primaire dans les réglages du thème.

## [0.6.2] - 2026-08-29

### Changé

#### Releases
- Le commentaire de `tag-guard.yml` disait que l'attente d'une minute évitait
  une fausse alerte sur les releases automatiques. C'est faux : GitHub ne
  déclenche aucun workflow sur un événement produit par le `GITHUB_TOKEN` par
  défaut, donc le garde-fou ne voit jamais ces tags. Il ne surveille que les
  tags poussés à la main, ce qui est précisément son rôle.

### Dans aurora-client

Rien à faire.

## [0.6.1] - 2026-08-29

### Changé

#### Releases
- `make tag` refuse désormais et affiche le flux. Il créait un tag sans
  release et sans préfixe `v` : Composer l'aurait proposé aux clients comme
  une version publiée, jamais passée par `master` ni par la CI.
- Nouveau garde-fou `.github/workflows/tag-guard.yml` : tout tag `v*` poussé
  sans release accompagnante fait échouer la CI, avec les deux issues
  (publier la release, ou supprimer le tag).

#### Documentation
- La doc de propagation, le skill `ship` et deux docs client décrivaient
  encore les releases taguées comme « plus tard, pas maintenant ». Elles
  décrivent le flux courant.
- La mémoire `project_notes_share_link_read_only`, citée par trois
  commentaires du code de partage, n'existait pas. Elle existe.

### Dans aurora-client

Rien à faire. `make tag` disparaît du Makefile client au prochain
`make aurora-update` ; s'il figurait dans un script de déploiement, le
remplacer par un merge `develop` -> `master`.

## [0.6.0] - 2026-08-29

> Première version publiée par le workflow `master`. Le changelog s'arrêtait
> à 0.3.0 ; les 0.4 et 0.5 n'ont jamais été taguées mais existent dans les
> migrations et la documentation (`MIGRATION_0.4.md`, « depuis 0.5 » dans le
> Makefile), d'où la reprise à 0.6.0 plutôt qu'à 0.4.0.

### ⚠️ Cassant - root `templates/` éliminé (sauf `bundles/`), tout sous `src/`

Le dossier `templates/` à la racine du bundle est éliminé. Tous les templates
sont désormais co-localisés sous `src/`, en miroir du refactor `assets/` :

| Avant | Après |
|---|---|
| `templates/Module/<X>/` | `src/Module/<X>/templates/` |
| `templates/Core/` | `src/Core/templates/Core/` |
| `templates/Shared/` | `src/Core/templates/Shared/` |
| `templates/Frontend/themes/default/` | `src/Core/templates/Frontend/themes/default/` |

**Seule exception** : `templates/bundles/TwigBundle/` reste à la racine du
projet - c'est une convention Symfony hardcodée dans `FilesystemLoader` pour
les overrides de templates de bundles tiers (error pages, …). Non négociable.

**Namespaces Twig inchangés côté API** : `@Editorial`, `@Crm`, `@Platform`,
`@Core`, `@Shared` etc. continuent de résoudre vers les bons emplacements.
Aucun `render(…)` ni `include`/`extends` n'est à modifier. Les références
sans namespace (`Frontend/themes/default/layout.html.twig`) résolvent toujours
via le null namespace, qui pointe désormais à la fois sur `src/Core/templates/`
(emplacement des templates bundle) et `templates/` (encore présent pour
`bundles/TwigBundle/` + overrides client à la racine du projet).

**Côté client** : compatibilité ascendante pour les trois familles.
Pour `@<Module>`, `@Core`, `@Shared`, `AuroraBundle::prependExtension` reconnaît
deux paths d'override (le nouveau co-localisé + le legacy top-level) :

| Namespace | Nouveau path client (recommandé) | Legacy path client (backward compat) |
|---|---|---|
| `@<Module>` | `<client>/src/Module/<X>/templates/` | `<client>/templates/Module/<X>/` |
| `@Core` | `<client>/src/Core/templates/Core/` | `<client>/templates/Core/` |
| `@Shared` | `<client>/src/Core/templates/Shared/` | `<client>/templates/Shared/` |

Pour les thèmes frontend custom : `<client>/templates/Frontend/themes/<slug>/`
**reste la convention canonique** (les thèmes sont de la data côté client,
pas du code de module). `ThemeManager.countTemplates()` accepte aussi
`<client>/src/Core/templates/Frontend/themes/<slug>/` en fallback (pour le
default theme livré par Aurora en mode core dev).

Aucune migration Doctrine ; clear cache + rebuild suffit.

### ⚠️ Cassant - root `assets/` supprimé, JS/Vue co-localisé sous `src/`

Le dossier `assets/` à la racine du repo a été éliminé. Tout le JS/Vue/CSS
est désormais co-localisé sous `src/`, en miroir de la structure PHP :

| Avant | Après |
|---|---|
| `assets/Module/<X>/...` | `src/Module/<X>/assets/...` |
| `assets/Core/backend/...` | `src/Core/Frontend/backend/...` |
| `assets/Core/frontend/...` | `src/Core/Frontend/frontend/...` |
| `assets/Core/utils/...` | `src/Core/Frontend/utils/...` |
| `assets/shared/...` | `src/Core/Frontend/shared/...` |
| `assets/locales/generated/...` | `src/Core/Frontend/locales/generated/...` |
| `assets/css/...` (sauf modules) | `src/Core/Frontend/css/...` |
| `assets/css/modules/notes/markdown/preview.css` | `src/Module/Notes/assets/backend/markdown/components/preview.css` |
| `assets/css/modules/editorial/prose.css` | `src/Module/Editorial/assets/backend/posts/prose.css` |
| `assets/css/core/sidemenu.css` | `src/Core/Frontend/backend/sidemenu/sidemenu.css` |
| `assets/controllers/` | `src/Core/Frontend/stimulus/` (renommé pour éviter le clash avec `Controller/` PHP) |
| `assets/controllers.json` | `src/Core/Frontend/stimulus.json` (override Symfony : `config/packages/stimulus.yaml`) |
| `assets/tests/` | `src/Core/Frontend/tests/` |
| `assets/.client-fallback/` | `src/Core/Frontend/.client-fallback/` |
| `assets/{app,flash,theme,guest,i18n,stimulus_bootstrap}.js` | `src/Core/Frontend/{app,flash,theme,guest,i18n,stimulus_bootstrap}.js` |

**Aliases Vite inchangés côté API** : `@vault`, `@editorial`, `@platform`,
`@configuration`, `@media`, `@general`, `@dev` etc. pointent toujours vers
les bons emplacements (`src/Module/<X>/assets/`). `@core`, `@`, `@shared`
résolvent sous `src/Core/Frontend/`. Les imports `@/css/...` ont été
remplacés par des chemins relatifs (`./preview.css`, `./sidemenu.css`)
car les CSS sont désormais co-localisés avec leur SFC.

**Stimulus** : le folder a été renommé pour éviter la confusion avec les
controllers PHP. La convention par défaut Symfony (`assets/controllers/`)
est overridée via `config/packages/stimulus.yaml`.

**Aucune migration Doctrine** ; côté front, rebuild Vite suffit.

Voir [`MIGRATION_0.4.md`](docs/aurora-client/MIGRATION_0.4.md) pour la note
détaillée côté client (rien ne change pour `aurora-client/assets/client/`).

### ⚠️ Cassant - namespaces Core déplacés sous leur module parent

Alignement de `src/Core/` sur la convention Vault-style déjà en place
côté `src/Module/` : les sous-modules Core vivent désormais dans un
sous-dossier de leur module parent (`Aurora\Core\Platform\User`,
`Aurora\Core\Configuration\Setting`, etc.). Voir
[`MIGRATION_0.4.md`](docs/aurora-client/MIGRATION_0.4.md) pour la table
de correspondance + le `sed` bulk.

| Avant | Après |
|---|---|
| `Aurora\Core\Dashboard\*` | `Aurora\Core\General\Dashboard\*` |
| `Aurora\Core\Profile\*` | `Aurora\Core\General\Profile\*` |
| `Aurora\Core\Search\*` | `Aurora\Core\General\Search\*` |
| `Aurora\Core\Audit\*` | `Aurora\Core\Dev\Audit\*` |
| `Aurora\Core\Setting\*` | `Aurora\Core\Configuration\Setting\*` |
| `Aurora\Core\Theme\*` | `Aurora\Core\Configuration\Theme\*` |
| `Aurora\Core\Media\*` | `Aurora\Core\Media\Library\*` |
| `Aurora\Core\User\*` | `Aurora\Core\Platform\User\*` |
| `Aurora\Core\Agency\*` | `Aurora\Core\Platform\Agency\*` |
| `Aurora\Core\Auth\*` | `Aurora\Core\Platform\Auth\*` |
| `Aurora\Core\Service\{Entity,Dto,Manager,Repository,Serializer,Controller,View}\*` | `Aurora\Core\Platform\Service\{...}\*` |
| `Aurora\Core\Service\{Platform,Media,Configuration,General}Context` | `Aurora\Core\{Platform,Media,Configuration,General}\{Same}Context` (racine du folder du module) |
| `Aurora\Module\<X>\Service\<X>Context` (12 business modules) | `Aurora\Module\<X>\<X>Context` (racine du folder du module) |
| `Aurora\Core\Menu\*` | `Aurora\Module\Editorial\Menu\*` (Menu = sous-module d'Editorial) |
| `Aurora\Core\MountPoint\*` | `Aurora\Module\Dev\MountPoint\*` |
| `Aurora\Core\Platform\*` | `Aurora\Module\Platform\*` (promotion Core → Module) |
| `Aurora\Core\Configuration\*` | `Aurora\Module\Configuration\*` |
| `Aurora\Core\Media\*` | `Aurora\Module\Media\*` |
| `Aurora\Core\General\*` | `Aurora\Module\General\*` |
| `Aurora\Core\Dev\*` | `Aurora\Module\Dev\*` |
| `Aurora\Core\{Platform,Configuration,Media,General,Dev}Module` | `Aurora\Module\<X>\<X>Module` |

**2e vague - templates + assets** : `templates/Core/backend/<X>/` et
`assets/Core/backend/<X>/` ont aussi été déplacés vers les modules promus
(`templates/Module/<NewModule>/backend/<X>/` et idem assets). 5 nouveaux
aliases Vite : `@platform`, `@configuration`, `@media`, `@general`, `@dev`.
Voir [MIGRATION_0.4.md](docs/aurora-client/MIGRATION_0.4.md) pour le sed bulk
côté client.

**Convention unique** : tout module (avec une entrée dans la sidemenu) vit
sous `src/Module/`. `src/Core/` ne contient plus **que** de l'infrastructure
cross-cutting (Encryption, Frontend, Locale, Mail, Notification, Module/Contract,
Repository, Scheduler, Sequence, Storage, Support, Twig, Validation, etc.).
Plus aucun `<X>Module.php` à la racine de `src/Core/`.

**Inchangé** (cross-cutting infra) : `Encryption`, `Frontend`, `Locale`,
`Mail`, `Menu`, `Migration`, `Module`, `MountPoint`, `Notification`,
`Repository`, `Scheduler`, `Sequence`, `Storage`, `Support`,
`Timestampable`, `Twig`, `Validation`.

**Aucune migration Doctrine** - les tables (`core_user`, `core_agency`,
`core_audit_log`, `core_media`, `core_setting`, etc.) gardent leur nom.

### ⚠️ Cassant - CLI wizards `aurora:make:module` + `aurora:make:entity` supprimés

Les deux commandes Symfony ajoutées plus tôt dans Unreleased ont été
**retirées**. Tout scaffolding passe désormais par les skills Claude
`/add-module` et `/add-entity`. Motivation : un dev pressé pouvait
exécuter le wizard CLI directement (`bin/console aurora:make:module
Loyalty`) et zapper les edits post-scaffold qui ne peuvent pas être
mécaniques :

- Patch sur `ModuleParameterEnum` (5 match arms à étendre côté core)
- Append sur `aliases.js` (côté core)
- Choix d'une icône Lucide pertinente (au lieu de `'flame'` par défaut)
- Polish des labels FR/EN (au lieu de `{{MODULE_LABEL}}`)
- Fleshing-out des fields sur `Abstract<Name>` (`make:entity`)

Le wizard CLI imprimait des hints textuels pour ces étapes - facilement
ignorés. Le skill Claude les exécute systématiquement, donc on supprime
l'entrée CLI pour fermer la porte aux dérives.

**Source de vérité unique** : les templates `.tpl` ont juste été
déplacés depuis `src/Core/Module/Command/templates/` vers
`.claude/skills/add-module/templates/` et `.claude/skills/add-entity/templates/`.
Le skill lit les `.tpl` via `Read`, substitue les `{{KEY}}` tokens, et
écrit le résultat via `Write` - aucune duplication.

**Migration** : si vous avez un script CI qui appelait
`bin/console aurora:make:*`, remplacez par une invocation Claude (par
ex. dans un agent CI), ou déclenchez le skill via le harness Claude
Code en mode batch.

### ⚠️ Cassant - `ApplicationParameterEnumInterface::getPlaceholder(): ?string`

Nouvelle méthode obligatoire sur l'interface. Tous les enums clients
implémentant `ApplicationParameterEnumInterface` (settings module) doivent
ajouter une implémentation par défaut :

```php
public function getPlaceholder(): ?string
{
    return null;
}
```

Override par case quand un exemple concret est plus parlant que la
description (`'INV-2026-000042'` pour un préfixe, `'admin@example.com'`
pour un email). Les 13 enums core ont déjà été migrés en interne.

Comportement runtime : si `getPlaceholder()` renvoie `null` ET que le
`defaultValue` du setting est non-trivial (non-vide, non-`'0'`),
`SettingsViewBuilder` utilise le défaut comme placeholder. Couvre la
mer de préfixes (`'INV'`, `'DEAL'`, `'ORD'`, …) et les défauts
Notes/Assistant (`'qwen3:8b'`, `'2048'`, …) sans wirage par-case.

### Dans aurora-client

Lancer après `make aurora-update` :

```bash
# 1. Déplacer les dossiers d'extension (Agency, User, …) sous Core/Platform/
git mv src/Module/Core/Agency src/Module/Core/Platform/Agency

# 2. Renommer les namespaces (sed bulk - voir MIGRATION_0.4.md pour la commande complète)
grep -rl 'Aurora\\Core\\Agency\\' src tests config | xargs sed -i 's|Aurora\\Core\\Agency\\|Aurora\\Core\\Platform\\Agency\\|g'

# 3. Ajouter getPlaceholder() sur les enums clients implémentant
#    ApplicationParameterEnumInterface (au minimum un `return null;`)
grep -rl "implements ApplicationParameterEnumInterface" src | xargs -I{} echo "Patch {} - add getPlaceholder(): ?string { return null; }"

# 4. (Optionnel) Câbler `placeholderKey: $case->getPlaceholder()` sur les
#    ConfigurationTabProvider clients pour forwarder les placeholders au
#    SettingFieldDescriptor.

# 5. Re-générer + valider
composer dump-autoload && make cc && make ft
```

Côté welding : `WeldingSettingEnum::getPlaceholder()` câblé avec 7 vrais
placeholders (`'WLD'`, `'WPDF'`, `'inspecteur@example.com'`, …) et
`WeldingModuleParameterEnum::getPlaceholder() => null` puisque les
toggles modules rendent en switch (pas d'input).

### Ajouté

#### Module Notes (Markdown, façon Obsidian)
- Nouveau module `Notes` et son sous-module `Markdown` : éditeur markdown,
  aperçu temps réel, liens `[[wiki]]` avec autocomplétion, backlinks et
  mentions non liées, graphe des connexions, callouts, commandes slash,
  arborescence, étiquettes, images collées et redimensionnables, sommaire,
  recherche. Rapatrié du paquet `aurora-notes`, archivé en août.
- Titre et contenu chiffrés au repos (`EncryptedTextType`). Conséquence à
  connaître : aucune recherche ni tri SQL possible sur ces colonnes, donc la
  recherche et le filtre par étiquette tournent en PHP, sur les notes d'une
  seule personne.
- Deux interrupteurs : `modules_notes_backend`, `modules_notes_markdown`.

#### Partage de note par lien (lecture seule)
- Un lien ouvre une note sans compte, avec échéance facultative et
  révocation. Sans destinataire c'est le « copier le lien » ; avec une
  adresse il part par mail et se révoque individuellement.
- Deux interrupteurs décident de ce qui accompagne la note : les sous-notes
  (arborescence) et les notes liées par `[[…]]`, suivies transitivement.
  L'écran liste les titres qui partiraient avant le clic, plutôt que de les
  compter.
- Un `[[lien]]` vers une note hors du partage devient du texte simple : un
  lien n'élargit jamais un partage tout seul.

#### Settings
- `ConfigurationTab::$moduleToggle` (`ModuleParameterEnum|string|null`) -
  cache l'onglet de `/backend/settings` quand le module est désactivé
  dans `/dev/dashboard/modules`. 5 tab providers core déjà câblés (Crm,
  Ecommerce, Notes, PersonalFinance, Assistant).
- `SettingFieldDescriptor::$placeholderKey` (`?string`) - clé i18n
  optionnelle pour le placeholder de l'input. `SettingsViewBuilder`
  traduit + transmet dans le payload Vue ; `SettingsApp.vue` consomme
  via `parameter.placeholder`. Si null + type `text`/`int`/`textarea`,
  fallback automatique sur `defaultValue` (couvre les ~20 préfixes
  sequences sans wirage par-case).
- 13 templates `.tpl` pour le scaffold entity 5 couches Sylius
  (Entity triplet + DTO quartet + Manager pair + Serializer pair +
  Repository + Controller) sous `.claude/skills/add-entity/templates/`.
  Le skill `/add-entity` les lit + applique les substitutions + patche
  `AuroraBundle::$resolve_target_entities` + flesh-out des fields.

#### Skills Claude
- `/audit-module-toggles` - audit read-only de tous les modules contre
  la convention toggle (20 critères : enum case, getToggles(), Context
  isBackendEnabled, NavSection gating, getCatalogNavSections unfiltered,
  sous-toggles, translations, ConfigurationTab.moduleToggle). Allowlist
  d'infra (Configuration / Platform / Dev / Media / General).
- Skills `/add-module` et `/add-entity` lisent désormais les templates
  `.tpl` co-localisés sous `.claude/skills/<skill>/templates/` et
  scaffoldent les fichiers directement (Read + substitution + Write),
  puis font les edits délicats (patch `ModuleParameterEnum`,
  `aliases.js`, fleshing-out AbstractX). Plus de CLI wizard
  intermédiaire - un seul point d'entrée, zéro risque de dérive.

#### Templates wizard
- `src/Core/Module/Command/templates/entity/*.tpl` (13 fichiers) - le
  pattern 5 couches Sylius vit là, plus en markdown dans le skill.
- `SettingEnum.php.tpl` et `ConfigurationTabProvider.php.tpl` du wizard
  `make:module` câblent désormais `getPlaceholder() => null` et
  `placeholderKey: $case->getPlaceholder()` + `moduleToggle:`
  context-aware (core enum case / client `BACKEND_KEY` / `null`).

### Changé

#### Publications
- Les cartes des listes publiques sont cliquables sur toute leur surface, et
  plus seulement sur le titre.

#### HTTP côté Vue
- Le `useRequest` **frontend** envoie enfin `X-Requested-With`. Il ne le
  faisait pas, alors que la convention l'affirmait : tous les appels des pages
  publiques partaient sans l'en-tête que Symfony lit pour répondre du JSON
  plutôt que du HTML. Cinq composables qui appelaient `fetch` à la main sont
  passés sur le wrapper partagé.

#### Menu latéral
- Les sections `planning` et `notes` ont leur propre couleur. Elles
  retombaient sur la palette d'accentuation et paraissaient oubliées à côté
  des six sections colorées.
- `extend-aurora-entity` skill : namespaces mis à jour
  (`Aurora\Module\Platform\Agency`, plus l'ancien `Aurora\Core\Agency`)
  + asset paths post-0.5 (`src/Module/<X>/assets/backend/`) + alias
  Vite par-module (`@platform/...` au lieu de `@aurora/Core/...`).
- `add-submodule` skill : asset paths post-0.5 alignés CORE+CLIENT.
- `check-extensibility` skill : check 17b (User-style `applyInput`
  absence légitime) + check 26 (audit des toggles de sous-modules).
- `make aurora-update` (Makefile distribué via `sync-makefile`) :
  enchaîne désormais `make translation && make build` à la fin pour
  régénérer le bundle Vite avec les i18n du nouveau core - plus de
  clés `backend.foo.bar` brutes affichées après bump.
- Commentaires aurora-core nettoyés de tous les exemples
  welding-internes (`WLD`, `modules_welding_backend`, `WeldingFoo`,
  etc.) - welding vit en client depuis 05e374ec, les exemples
  utilisent maintenant des valeurs neutres (`INV`,
  `modules_<module_id>_backend`, `MyEntity`).

---

## [0.3.0] - 2026-05-17

### Ajouté
- **Module Assistant IA** (Phase 1A + 1B) : chat synchrone avec un LLM local Ollama
  (qwen3:8b par défaut), tool-calling (`aurora_search`, `filesystem_read`,
  `filesystem_write`, `filesystem_search`, `image_read` via qwen2.5vl),
  mount-points configurables par utilisateur, flow de confirmation pour les
  actions destructives (write).
- **Onglet "Assistant" dans /backend/settings** : modèle chat, modèle vision,
  timeout HTTP, num_ctx, prompt système - tunables sans redéploiement (lecture DB
  avec fallback env).
- **`make sync-env`** + `bin/sync-client-env` : détecte les blocs
  `###> aurora/* ###` manquants dans `.env` et les insère au-dessus du divider
  CLIENT CUSTOM. Idempotent, valeurs existantes jamais touchées.
- **Divider `# === CLIENT CUSTOM ===`** dans `.env` aurora-client : sépare
  explicitement la zone gérée par aurora-core de la zone propriété du client.
- **`make sync-makefile` refusé** si Makefile a des edits non commités
  (`FORCE=1` pour forcer).
- **Tests** : +291 tests sur la période, total 2694.
- **`docs/aurora-shared/`** : nouveau dossier de docs transversales (form_validation,
  testing_php/vue, translations, scheduler, convention_seo_head) partagé entre
  aurora-core et aurora-client via vendor.
- **`docs/aurora-client/deployment/`** : guide principal + worker_systemd +
  apache_xsendfile + ocr_setup regroupés ici.
- **`docs/aurora-core/ops/prerequisites.md`** : checklist exhaustive des prérequis
  système, PHP, Ollama, vars d'env.

### Changé
- `Makefile` client : `README.md` n'est plus symlinké depuis le vendor - copié une
  seule fois à l'init, ensuite propriété du client.
- Docs : plus de symlinks `docs/aurora-*/` côté client - lecture directe dans
  `vendor/axelraboit/aurora/docs/`.
- Notes settings (Markdown + Block) : labels disambiguïsés
  ("Notes Markdown - Taille max…" vs "Notes Block - …").

### Dans aurora-client - à faire après `make aurora-update`

| Action | Commande / fichier |
|--------|-------------------|
| Ajouter les vars d'env `ASSISTANT_*` et `OCR_*` si absentes | `make sync-env` les ajoute automatiquement |
| Vérifier que `README.md` est bien un vrai fichier (plus un symlink) | `ls -la README.md` - si symlink, `make sync-claude-md` le remplace par une copie |
| Parcourir la section "CLIENT CUSTOM" de `.env` | `make sync-env` a ajouté le divider |

### Breaking changes
- Aucun changement d'API publique.

---

## [0.2.0] - 2026-05 (antérieur à ce changelog)

Établissement de la base : Posts avec éditeur bloc Editor.js, Notes Markdown
(wiki-links, graphe), Notes Block (EditorJS), Billing OCR (docTR + Ollama vision),
Galleries photo, Vault, Password Generator, extensibilité 5-couches Sylius sur 24
entités, conventions sync aurora-core → aurora-client (Makefile template, CLAUDE.md
symlink, jsconfig, security.yaml).

---

## [0.1.0] - avant 2026-05

Socle initial : Symfony 7 / PHP 8.4 / Vue 3 / Vite, modules Editorial CMS (Posts,
Taxonomies, Comments, Forms), CRM, ERP (Products), Ecommerce (Listings, Cart,
Orders), GED, HR, Planning, Project Management, auth (invitations, demandes
d'accès), thèmes, multi-langue.
