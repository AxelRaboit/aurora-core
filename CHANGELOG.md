# Changelog Aurora-core

Format : [SemVer](https://semver.org). Section **"Dans aurora-client"** = ce que les
projets clients doivent répercuter après avoir lancé `make aurora-update`.

---

## [0.9.223] - 2026-09-22

### Ajouté

#### Le module Deck sait enfin faire de jolies slides
Un deck se dessinait sur un fond plat, centrait tout verticalement et n'offrait
qu'une seule forme d'image. Ce qui manquait n'était pas la liberté de poser les
choses où on veut, c'était le vocabulaire. Le cadre reste en 16:9 avec ses
formes déclarées, il n'y a toujours ni canevas libre ni boîte posée à la main,
et le texte continue d'être mesuré pour tenir.

**Le fond.** Cinq dégradés, dont un qui finit sur une seconde teinte plutôt que
dans le vide. Trois motifs posés sous l'image, donc invisibles sur une slide à
photo. Un voile qui descend d'un seul côté au lieu de ternir toute la
photographie, quatre traitements d'image (flou, noir et blanc, duotone, grain),
un vignettage, et une slide qui peut se poser sur l'une des trois couleurs du
deck.

**La composition.** Position verticale, alignement, largeur de la colonne de
texte, marges du cadre, aplat de couleur contre un bord, filet de cadre, image
qui sort de la marge pour toucher le bord.

**La typographie.** `==un mot==` le peint dans la couleur d'accent, ce qui est
le geste le plus courant de la mise en page de deck et n'existait pas. Casse
des titres, échelle par slide, cinq formes de puces, chiffre de section en
filigrane.

**Sept gabarits de plus.** Comparaison, chiffres en grille, sommaire numéroté,
citation avec portrait, grille de logos, mosaïque d'images, slide de fin. Les
deux derniers ont demandé de savoir poser plusieurs images sur une slide, ce
que le module ne savait pas faire.

**Quatre looks nommés.** Scène, Atelier, Dossier, Galerie appliquent un thème et
ses réglages d'un coup. Au lieu de huit questions posées à quelqu'un qui n'est
pas graphiste, une seule. Tout reste modifiable ensuite.

**Trois thèmes de plus**, Terre, Craie et Deux tons, qui arrivent déjà composés
avec leur lavis. Les cinq anciens n'en portent aucun, exprès : un thème qui se
mettrait à porter un dégradé repeindrait tous les decks déjà dessinés avec.

#### Le panneau dit quand un deck a cessé d'être lisible
À force d'empiler des lavis, des aplats et des inversions, il devenait facile
d'écrire un texte que personne ne lira sans s'en apercevoir sur un portable.
Le panneau calcule le rapport entre l'encre et le fond et le classe en trois
bandes, selon les seuils d'accessibilité. Il ne refuse rien et ne corrige rien.
À côté, les teintes dominantes du logo sont proposées comme accent.

#### Un deck s'imprime dans ses couleurs
Le fond ne sortait que si le lecteur avait coché « imprimer les arrière-plans »
dans son navigateur, donc personne ne savait à quoi ressemblerait un PDF, son
auteur le premier. Un deck qui arrive gris sur blanc est un livrable cassé.

#### Les lignes d'une liste peuvent arriver une à une
Au plein écran, une pression fait apparaître la ligne suivante avant de passer
à la slide d'après. Reculer retire la dernière ligne avant de retirer la slide.
Avec, une transition propre à une slide et un travelling très lent sur une
photo de fond.

### Corrigé

#### Un deck ne se dessinait pas pareil sur Mac et sur Windows
Trois des quatre paires de polices partaient d'une pile système, qui ne donne
pas la même fonte selon la machine : le même deck coupait ses lignes ailleurs
pour son auteur et pour le client qui ouvre le lien de partage. Les paires
nomment maintenant des familles servies par l'application elle-même.

### Note technique

Aucune migration : tout est stocké dans le JSON de contenu des slides et dans
le style du deck, et un deck qui ne porte aucune des nouvelles clés se dessine
exactement comme avant.

## [0.9.222] - 2026-09-22

### Corrigé

#### Un fichier servi depuis un bucket dit enfin ce qu'il est
Quand les documents sont stockés à distance et rendus à travers l'application
(mode « proxy »), la réponse ne portait aucun type. Une réponse sans type vaut
`text/html`, et le navigateur s'en sortait pour une photo, qu'il reconnaît à ses
octets. Il ne s'en sort jamais pour une image vectorielle : un SVG est du
balisage, et le deviner est précisément ce qu'un navigateur refuse de faire.

Conséquence : un pictogramme déposé dans la médiathèque ne s'affichait nulle
part, pendant que la photo posée à côté s'affichait très bien. Rien dans les
journaux, la requête répondait 200 avec les bons octets.

Le type est désormais posé, avec `X-Content-Type-Options: nosniff` pour qu'il
fasse foi, et les types qu'un navigateur exécuterait (SVG, HTML, XML) repartent
en pièce jointe, exactement comme le fait déjà le service des fichiers locaux.
Une image dans une page continue de s'afficher, c'est la navigation directe vers
l'adresse qui est fermée.

## [0.9.221] - 2026-09-22

### Modifié

#### L'icône d'une carte d'offre se lit à côté du nom
Une carte d'offre pouvait déjà porter un pictogramme, dessiné au-dessus de son
nom. Sur une carte qui ne contient qu'un nom et une ligne ou deux, cette ligne
supplémentaire poussait les mots vers le bas sans rien apporter : l'icône et le
nom disent la même chose et gagnent à être lus d'un seul coup d'oeil.

Les deux sont maintenant sur une même rangée, l'image d'abord. Un nom long passe
à la ligne à côté de l'icône plutôt que de la comprimer. Une carte sans image
n'est pas touchée.

## [0.9.220] - 2026-09-22

### Ajouté

#### Un client est prévenu de ce qui attend son avis, et répond en lot
L'espace client savait déjà montrer un calendrier, recueillir une validation et
porter un fil de commentaires. Ce qui manquait n'était pas la mécanique, c'était
tout ce qui fait qu'on s'en sert.

**Le studio invite à relire, à la main.** Un bouton sur le tableau, qui annonce
combien de publications attendent, et qui prévient par courriel tous les
destinataires pouvant valider. À la main et pas automatiquement : celui qui sait
quand un lot est prêt est celui qui l'a préparé, et un envoi déclenché à chaque
carte devenue visible remplirait la boîte du client pendant que le lot se
construit encore. Rien ne part s'il n'y a rien à relire.

Le courriel porte une adresse neuve et révoque la précédente, parce que le jeton
d'un lien n'existe en clair qu'à sa création : seul son condensé est stocké,
pour qu'une base volée n'ouvre pas le plan de contenu d'un client. Le client a
donc toujours exactement une adresse valide, la dernière reçue. Et le courriel
part **avant** la révocation : un serveur de messagerie muet laisserait sinon
quelqu'un dehors, sans adresse et sans le message qui la remplaçait.

**Le client voit ce qui l'attend sans ouvrir une carte.** Un compteur en tête du
calendrier, qui sert aussi de filtre et réduit le mois à ce qui demande une
réponse. Le calendrier lui-même n'a pas changé : il est partagé par toute
l'application, et ses pastilles portent déjà la couleur de leur étape. Un second
code couleur par-dessus ne se serait pas lu.

**Il répond en lot.** Cases à cocher et « Valider la sélection » sur cette
liste. L'accord se donne en lot, la demande de modification non : dix
approbations disent une seule chose dix fois, tandis que dix reprises sans un
mot n'apprennent rien au studio et l'obligent à rappeler pour comprendre.

**Une date de réponse, distincte de la date de parution.** Une publication
prévue le 30 ne se valide pas le 30 : il faut le temps de produire et parfois de
reprendre. Le champ est facultatif, il s'affiche chez le client, et le tableau
annonce combien de cartes ont dépassé leur échéance. Rien ne se bloque ni ne se
déprogramme pour autant : une date passée est une information, comme
l'approbation est un avis et non un automate.

### Dans aurora-client

Une migration ajoute la colonne `review_by` à
`core_studio_space_content_items`. Rien à répercuter à la main.

---

## [0.9.219] - 2026-09-21

### Ajouté

#### Un thème choisit sa police d'écriture
L'écran de thème gagne un sélecteur, sous la largeur de contenu : cinq
familles, une géométrique, une neutre d'interface, une grotesque, une arrondie
et une à empattements. Le choix couvre tout, back-office et site public, parce
qu'une application composée dans deux typographies ne ressemble plus à une
application.

**La police appartient au thème, pas aux réglages.** Comme la largeur de
contenu et la couleur principale : changer de thème emporte sa typographie
plutôt que d'hériter de celle du précédent, ce qui est le seul sens qui rende
un thème essayable.

**Cinq, et pas un champ où taper un nom.** La CSP du produit n'autorise les
polices que depuis le site lui-même, donc une famille qui n'est pas embarquée
n'arrive jamais : elle serait résolue par le système du visiteur, ou pas du
tout. Les cinq sont auto-hébergées, et un `@font-face` que personne n'utilise
n'est jamais téléchargé - proposer le choix ne pèse donc rien sur les pages des
sites qui n'en changent pas.

Le sélecteur montre la famille sélectionnée en situation, composée dans la
police proposée, et non dans une liste de noms qui se ressemblent tous.

### Dans aurora-client

Rien à répercuter : pas de migration, le choix vit dans la configuration JSON
du thème. Un projet qui n'y touche pas reste composé comme avant.

---

## [0.9.218] - 2026-09-21

### Corrigé

#### Une image qui remplit sa zone débordait sur la ligne suivante
Une image réglée sur `fill` prend la hauteur de la zone qui la porte et se
recadre dedans. Quand cette image est aussi cliquable, la loupe l'enveloppe
d'un bouton, et ce bouton restait un bloc : il prenait bien la hauteur que la
grille lui donnait, mais l'image à l'intérieur gardait ses propres proportions
et sortait par le bas. Sur la page de démonstration elle dépassait de 79
pixels, passait par dessus sa propre légende et entrait dans la ligne suivante.

Le `flex-1` porté par l'image ne servait à rien, faute de parent flex : le
bouton s'interposait entre elle et la figure. **Une image cliquable et une
image simple ne se posaient donc pas de la même façon, alors que rien dans les
réglages ne le laissait deviner.** Le bouton devient une colonne quand la zone
est en `fill`. Mesuré sur la même page : l'image passe de 760 à 653 pixels, la
hauteur exacte de son bouton, et plus rien ne sort de la zone.

### Modifié

#### La page de démonstration n'a plus de vidéo
La zone vidéo de la page « Bienvenue » pointait vers Big Buck Bunny sur
YouTube. Elle est retirée, avec ses légendes dans les trois langues. La carte
qui l'accompagnait garde son tiers de largeur et reste seule sur sa ligne.

---

## [0.9.217] - 2026-09-21

### Corrigé

#### Un tiers des images du tour ne se refaisait pas
Le scénario de capture prenait vingt-huit écrans et la table d'envoi n'en
connaissait que vingt-quatre, parce qu'elle supposait une image par carte. Une
carte en porte souvent plusieurs : douze images vivaient donc hors du script.

Elles ont vieilli sans que rien ne le dise. Celle du côté client montrait
encore une page qui empilait le calendrier, la discussion et les fichiers, et
un invité signé de son adresse e-mail - deux versions après que l'une et
l'autre aient disparu. **Un envoi qui laisse une image sur trois périmée est
pire qu'un envoi qui n'a pas lieu : on croit la page à jour.**

Le scénario prend désormais les dix écrans qui manquaient - les autres vues
d'un espace, une fiche ouverte, les prospects, la page du client, la fiche
d'un document - et la table porte les trente-huit, sans plus aucune entrée
sans carte. Trois images ont reçu leur emplacement au passage : la liste des
clients, que le texte de sa carte nommait en premier sans jamais la montrer,
et les deux onglets Informations et Liens.

La page du client est prise par un lien émis pour l'occasion plutôt que par
l'aperçu du studio : l'aperçu porte un bandeau qui prévient que ce n'est pas
ce que le client a reçu, ce qui est vrai dans l'application et trompeur sur
une carte qui promet de montrer ce que le client voit.

---

## [0.9.216] - 2026-09-20

### Ajouté

#### Un espace client porte la fiche de son client
Un nouvel onglet **Informations**, à gauche des réglages : nom de l'entreprise,
SIRET, SIREN, portable, fixe, email, adresse postale, liens et un champ libre.
Le studio voit un formulaire, puis en dessous le récapitulatif ; le client, lui,
ne voit que le récapitulatif. C'est le même composant des deux côtés, donc ce
que le studio relit est littéralement ce que son client a sous les yeux.

**La fiche appartient au client, pas au projet.** Deux espaces ouverts pour la
même société montrent la même fiche et se modifient au même endroit : un SIRET
est celui d'une entreprise, et une copie par espace se serait contredite dès le
deuxième projet. L'écran le dit, parce que modifier depuis un projet quelque
chose qui vaut pour tous doit être annoncé.

**Elle ne touche pas à l'identité contractuelle.** Capital, RCS, TVA et
représentant ne sont pas sur cet écran et ne sont pas réécrits : c'est une
saisie à part, sans quoi un enregistrement depuis un projet les aurait effacés
et le contrat suivant serait parti incomplet.

Le SIREN est vérifié comme le SIRET l'était déjà - neuf chiffres et sa clé - et
les deux doivent s'accorder quand ils sont tous les deux saisis : un SIRET
commence par son SIREN, et deux numéros qui se contredisent donnent une fiche
qui porte deux identités.

Écrire demande le droit sur les clients, pas celui sur les espaces : tenir le
tableau d'un projet n'autorise pas à renommer la société.

#### Un espace épingle ce qui vit ailleurs
Un onglet **Liens** : un lien, un texte ou un contact, ajoutés par une modale
où le genre se choisit d'abord et commande le reste. Une maquette Canva, le
tableau de bord de l'hébergeur, la personne qui valide chez le client - ce
qu'on cherchait dans ses favoris à chaque fois.

**Chaque élément se montre ou se cache au client, un par un, et fermé par
défaut.** C'est la fonctionnalité autant que la liste : on range au même
endroit ce qui se partage et ce qui ne se partage pas, et c'est une case qui
décide. L'écran dessine les deux groupes séparément, parce que « ce que le
client voit » se lit d'un coup d'œil ou ne se lit pas du tout ; chaque
ouverture et chaque fermeture est journalisée, puisque c'est le geste qui
publie.

Le filtre est appliqué dans la requête : un élément fermé ne sort pas du
serveur, ni son libellé ni son adresse. Le cacher dans la page en aurait fait
une préférence d'affichage.

Il n'y a délibérément pas de genre « identifiants ». Un mot de passe rangé dans
un espace client est un mot de passe en clair dans une base, dans les
sauvegardes et à l'écran de qui l'ouvre ; le champ manquant est ce qui empêche
l'habitude de se prendre. Le lien vers le coffre est, lui, un lien.

### Dans aurora-client

Une migration ajoute quatre colonnes à `core_customers` et crée
`core_studio_space_resources`. Rien à répercuter à la main.

---

## [0.9.215] - 2026-09-20

### Ajouté

#### Les deux écrans de branchement disent le chemin complet
Les réglages Drive et Craft annonçaient quoi coller sans dire où aller le
chercher. Chaque étape porte maintenant son lien direct - créer le projet
Google, activer l'API Drive, ouvrir les comptes de service - et les trois
questions qui se posent vraiment sont écrites : ce que la connexion permet,
ce qu'il reste à faire une fois la clé en place, et quoi regarder quand ça ne
répond pas.

L'étape qui manquait côté Craft est ajoutée : le mode de partage d'un espace,
sans lequel la clé est valide et ne lit rien.

#### Un `demo-reset` ne débranche plus rien
Reconstruire le jeu de démonstration vidait la base, et la configuration y
vit : la clé du compte de service Google, la connexion Craft. Il fallait
retourner chercher un fichier JSON que Google ne redonne pas.

`aurora:settings:preserve --dump|--restore`, appelé de part et d'autre du
`demo-reset`, met les réglages de côté et les repose. La règle n'est pas une
liste de clés, qui se périmerait au premier réglage ajouté : **un réglage
repose s'il revient vide**, et il est recréé s'il a disparu - les réglages
d'une intégration n'existent pas tant que personne ne les a enregistrés. Ce
que les fixtures réécrivent gagne donc toujours.

Les valeurs transitent telles qu'elles sont stockées, sans passer par le
service qui les déchiffre, et le fichier de transit est supprimé après
restitution.

---

## [0.9.214] - 2026-09-20

### Corrigé

#### L'identifiant d'un vrai dossier Drive était dans le dépôt
Relevé d'un écran de réglages pendant une session de travail, il est entré dans
les fixtures et dans un test, et il y est resté jusqu'à ce qu'un œil le
remarque - après avoir été poussé, tagué et publié.

**Ce n'est pas une fuite de secret** : un identifiant de dossier n'ouvre rien à
lui seul, il faut être partagé pour le lire. C'est une trace, et ce dépôt est
public : elle nomme une infrastructure qui appartient à quelqu'un.

Un identifiant qui se lit le remplace. Google le refusera, et l'écran dira que
le dossier ne répond pas - un état que la démonstration doit de toute façon
savoir montrer.

Un test de convention refuse désormais toute suite d'au moins vingt-cinq
caractères mêlant chiffres, majuscules et minuscules dans `src/`, `fixtures/`,
`tests/` et `tools/`. Il ne cherche pas « un secret », ce qui serait sans fin :
il cherche la forme précise qui est déjà passée.

---

## [0.9.213] - 2026-09-20

### Ajouté

#### Les cartes du tour public se remplacent par une commande
Refaire les images de `/fr/page/aurora` demandait trois gestes manuels répétés
vingt-quatre fois : prendre la capture, la copier sur le serveur, appeler
`aurora:ged:replace` avec le bon identifiant. **Le dernier demandait de
connaître cet identifiant, et il ne vivait nulle part dans le dépôt.**

Le scénario affirmait que « la carte n'a rien à savoir », parce qu'elle montre
un document nommé d'après la prise. C'est faux depuis que `aurora:ged:replace`
existe : elle change le nom stocké à chaque remplacement, donc le nom d'origine
ne se retrouve plus. La table nom → document ne survivait que dans la mémoire de
celui qui avait fait l'envoi la fois d'avant.

Elle est maintenant dans `tools/screenshots/tour-cards.json`, relevée depuis la
production et vérifiée contre trois cartes en ligne. `push-tour.mjs` enchaîne
les trois gestes, refuse de partir si une capture manque, et nomme les prises
qu'aucune carte ne montre.

### Corrigé

#### Une prise du tour était restée hors convention
`customers` avait été oubliée lors du renommage en `tour-*`, elle ne
correspondait donc à aucune carte et cassait la règle sur laquelle repose tout
le circuit. Le fichier compte **vingt-huit prises pour vingt-quatre cartes** :
les quatre orphelines sont désormais nommées comme telles, au lieu d'être un
écart que trois comptes différents contredisaient.

---

## [0.9.212] - 2026-09-20

### Corrigé

#### Il en manquait un, et le test qui l'aurait dit n'existait pas
La 0.9.211 a déplacé les limiteurs de débit dans le paquet pour qu'un projet
client n'ait plus à les recopier. Six sur sept : `form_submission`, demandé par
le contrôleur des formulaires du site public, a été oublié.

Le même symptôme que la veille, à un fichier près - un conteneur qui refuse de
se construire au premier déploiement. Trouvé en vidant pour de bon la
configuration d'un projet client, ce qui était le seul moyen de vérifier que le
correctif en tenait la promesse.

Un test de convention lit maintenant les deux listes : tout argument nommé
`$…Limiter` dans `src/` doit avoir son entrée dans le paquet. Il nomme celui qui
manque, ce qu'aucun message d'erreur de conteneur ne fait depuis l'autre bout
d'un déploiement.

### Dans aurora-client
`make aurora-update`. Le bloc `framework.rate_limiter` peut maintenant être vidé
pour de bon.

---

## [0.9.211] - 2026-09-20

### Corrigé

#### Les limiteurs de débit arrivent maintenant avec le paquet
Les contrôleurs d'aurora-core câblent six limiteurs par leur nom, et c'était au
projet client de les répéter dans sa propre configuration. **Rien ne le disait**,
sinon un conteneur qui refuse de se construire au premier déploiement, sur un
service dont le projet n'a jamais entendu parler.

Constaté en livrant la 0.9.210 : le lot du Drive a reçu son limiteur, et la mise
à jour du client s'est arrêtée net. Le commentaire du routage messenger, juste
au-dessus dans le même fichier, notait déjà que les limiteurs avaient cette
forme ; il aura fallu en ajouter un pour que ça se voie.

Un client qui veut d'autres chiffres redéclare la clé : sa configuration est
chargée après celle du paquet, donc elle gagne.

### Dans aurora-client
`make aurora-update`. Le bloc `framework.rate_limiter` de
`config/packages/rate_limiter.yaml` peut être allégé de tout ce qui ne fait que
répéter les valeurs d'aurora-core ; le garder ne casse rien.

---

## [0.9.210] - 2026-09-20

### Corrigé

#### Une étape interne laissait passer son fil et ses fichiers
Les fiches d'une colonne marquée interne étaient retirées de la page du client.
Leurs **commentaires et leurs pièces jointes, non** : les deux listes partaient
entières, et la route qui sert les octets ne vérifiait que l'appartenance à
l'espace.

L'écran n'en montrait rien, puisqu'il ne connaissait pas la fiche. C'est la pire
forme de fuite : invisible à l'usage, entière dans la source, et l'identifiant
d'une pièce jointe est un petit entier.

Les trois listes traversent maintenant le même tamis, calculé une fois, et
l'adresse d'un fichier de colonne interne rend 404. Mesuré avant d'alarmer : la
production ne contient aucun espace client, donc rien n'a jamais fuité.

### Modifié

#### Un invité n'est plus signé par son adresse
Ses messages, ses commentaires, ses fichiers et ses validations portaient son
**adresse email**. Sur un espace qui compte plusieurs liens, chaque invité lisait
donc les adresses des autres, sans l'avoir demandé ni pouvoir l'empêcher.

Le lien portait pourtant déjà un libellé humain, qui ne servait nulle part. Il
devient **obligatoire** à l'émission, et c'est lui qui signe. Une migration
réécrit les lignes déjà posées ; pour un lien émis avant la règle, le nom se
dérive de la partie gauche de l'adresse. Le studio, lui, continue de voir à qui
il parle.

#### Pas de compte, pas de conversation privée
Un invité pouvait en ouvrir une avec n'importe quel membre de l'équipe, et
recevait pour cela **l'annuaire nominatif de l'espace** avec les identifiants
internes des comptes. Le droit qui l'autorisait était « peut commenter » :
cocher une case pour permettre une remarque sous une publication ouvrait en
réalité une messagerie vers les collaborateurs et livrait leurs noms.

Ce qu'un client a à dire passe par un canal, que le studio ouvre quand il le
décide. La garde vit dans le dépôt et non dans la route, donc elle vaut aussi
pour les conversations ouvertes avant ce changement. Le studio garde les
siennes entre collaborateurs.

Deux routes publiques disparaissent avec, dont la seule écriture d'invité qui
n'avait pas de limite de débit.

#### L'audience d'un canal se décide en le créant
Elle se réglait après coup. Le raisonnement tenait - décider une fois qu'il y a
quelque chose dedans - mais il laissait la question sans réponse au moment où on
se la pose, c'est-à-dire en nommant la pièce : « Le mois prochain » et « Entre
nous » ne se nomment pas pareil selon qui les lit.

La case reste décochée par défaut. Et la ligne d'un canal dessine désormais
**les deux états** : un canal ouvert au client se déduisait d'une absence
d'icône, ce qui se confond avec une icône qu'on n'a pas vue.

#### La page d'un client se lit par onglets
Calendrier, discussion et documents s'empilaient sur près de deux mille pixels :
lire un message demandait de dépasser un mois entier, et retrouver un fichier de
dépasser les deux. Sur téléphone, la page était un couloir.

Trois onglets, du même vocabulaire que l'espace côté studio - c'est la même
matière, et un client qui verrait son prestataire travailler ne devrait pas
découvrir une seconde langue. Un onglet sans contenu n'existe pas, et une page à
un seul onglet n'en dessine aucun. **Mille soixante-sept pixels** au lieu de
mille sept cent quatre-vingt-un.

#### Des identifiants internes voyageaient jusqu'à la page d'un client
L'identifiant d'un document dans la médiathèque accompagnait chaque fichier et
chaque pièce jointe ; il ne sert qu'au studio, qui l'ouvre depuis la fiche. Et
chaque colonne portait « visible par le client », qui ne pouvait dire que
« oui » puisque les autres ne partaient déjà plus : un drapeau à une seule
valeur n'informe personne et fait croire qu'il en a deux.

#### Écrire dans la discussion devient un droit à part
Un seul droit commandait deux conversations : commenter une fiche, et parler
dans le salon de l'espace. Cocher une case pour autoriser une remarque sous une
publication ouvrait donc aussi le fil de la relation, qui ne se donne pas au
même monde - une agence partenaire annote un plan sans avoir à parler dans le
salon du client.

La colonne est recopiée depuis « peut commenter » : un lien déjà émis se
comporte exactement comme avant. Et le formulaire d'émission propose enfin les
deux cases, qu'il n'offrait ni l'une ni l'autre.

#### Le lot du Drive n'avait aucune limite de débit
C'est pourtant la route publique la plus chère : elle télécharge chaque fichier
chez Google et construit un zip avant d'envoyer le premier octet. Cinq par heure
et par adresse, séparément des autres gestes pour qu'un lot n'épuise pas le
droit d'écrire.

#### Une écriture d'invité pouvait être déclenchée depuis n'importe quel site
Ce qui protège ces routes est un secret dans l'adresse, et une adresse se
transfère. Un formulaire hébergé ailleurs pouvait donc faire poster le
navigateur d'un client vers elles, du moment que son type de contenu est
ordinaire - le dépôt de fichier, en `multipart`, est exactement ce cas. Les
routes JSON étaient déjà retenues par le contrôle préalable du navigateur.

Les quatre écritures exigent maintenant l'en-tête que le composant de requête
pose déjà : un formulaire ne peut pas le poser, et un `fetch` qui le pose
déclenche ce même contrôle préalable.

### Ajouté

#### La page d'un client a enfin des tests
Elle n'en avait aucun. Cinq désormais, sur les règles que les onglets ont
rendues cassables en silence : l'onglet d'arrivée, celui qui n'existe pas faute
de contenu, la barre qui ne se dessine pas pour un seul choix, et le fait qu'une
seule section s'affiche.

Quatre classes de tests écrivant comme un invité ont aussi reçu la remise à zéro
de leur limiteur. Leur compteur survit au processus : elles viraient au rouge au
troisième lancement de l'heure, par un 429 sur une route qu'elles ne voulaient
pas éprouver.

#### Les listes de fichiers tiennent sur un téléphone
Nom, poids et bouton sur une ligne de trois cent soixante-quinze pixels
tronquaient toujours la même chose : le nom du fichier, la seule qu'on lit. Ils
passent en colonne sous `sm`, et le bouton prend toute la largeur.

### Dans aurora-client
`make aurora-update`, puis `make migrate` : la 0.9.210 réécrit les noms d'auteur
des lignes écrites par un invité.

Le **libellé d'un lien d'accès devient obligatoire**. Les liens déjà émis
continuent de fonctionner ; seule l'émission d'un nouveau lien réclame un nom.

---

## [0.9.209] - 2026-09-20

### Supprimé

#### Le manuel du back-office
Cent dix pages et deux cent soixante-trois captures, trente-trois mégaoctets dont
quatre-vingt-dix-sept pour cent d'images, s'en vont avec l'écran qui les servait.

**Il coûtait plus qu'il ne rapportait, et la dernière régénération l'a montré en
trois temps.** Deux cent deux captures sur deux cent soixante-trois ont changé alors
que presque aucun écran n'avait bougé - des dates, des pastilles, des données de
démonstration qui dérivent : un diff que personne ne peut relire. L'une d'elles
s'était dégradée sans bruit, une image nommée « le bas de l'onglet » montrant le
haut, parce que les défilements du scénario agissaient sur le menu de gauche et non
sur la page. Et un parcours ne passait plus du tout, faute d'un contrat au bon
statut que les parcours précédents avaient consommé.

Trois défauts, trois natures, sur un actif que personne ne relit. Un manuel qu'on
n'ouvre pas et qui retarde sur le produit apprend surtout à se méfier de lui.

Ce qui le remplace s'écrit **dans** les écrans, avec ce que le produit a déjà : la
modale d'aide d'un sujet, la ligne d'explication sous un champ, et l'état vide qui
dit quoi faire. Rien à photographier, rien à tenir en phase, et l'explication est là
où la question se pose.

Partent avec lui l'outillage de capture (`tools/doc-screenshots/`), les trois
fichiers de tests du module, son guide de rédaction et la convention qui déclarait
une fonctionnalité inachevée tant qu'une page ne la décrivait pas. Les captures de
la page publique de présentation, elles, restent : ce sont d'autres images, et un
autre outil.

Aucune migration, aucun privilège, aucun réglage, aucun lien à réparer : le module ne
tenait au reste que par une entrée de menu, une couleur de section et deux
paramètres.

### Modifié

#### Le jeu de démonstration devient ce qui explique le produit
C'est la contrepartie du manuel qui s'en va : **ce qui n'est plus décrit par écrit
doit être visible à l'écran du premier coup**.

**Les neuf états d'un contrat existent maintenant**, contre quatre. Scellé, Ouvert,
Signé par le client, Expiré et Révoqué ne se voyaient nulle part : ni en apprenant le
module, ni sur une capture, ni dans un parcours automatisé - c'est d'ailleurs
l'absence d'un contrat « envoyé mais pas encore ouvert » qui faisait échouer un
parcours entier, faute de ligne à cliquer. Un enum de statut est une promesse faite
au lecteur, et chacun de ses cas doit être représentable.

**Les liens d'accès client montrent chaque droit dans les deux positions**, plus un
lien révoqué et un lien expiré. L'écran en affichait deux, aux mêmes droits, valides
tous les deux : il n'apprenait ni ce qu'un lien retiré devient, ni qu'un lecteur peut
n'avoir que le droit de lire, ni qu'on peut ouvrir un espace sans ouvrir son Drive.

**Un espace porte un dossier Drive et un mot de passe.** Un réglage qu'aucune donnée
ne porte est un réglage que personne ne voit : l'écran des réglages sortait toujours
vide et l'onglet Drive toujours ouvert.

#### `make demo-reset`, pour repartir de rien
`make demo` est idempotent **au point de ne rien rafraîchir** : les fixtures
retrouvent un document par son titre, un espace par son nom, et les laissent tels
quels. Comme toutes les dates sont relatives à aujourd'hui, la démonstration se
décale d'un jour par jour sans qu'aucun rechargement ne la remette d'aplomb.

La nouvelle cible repart de la base vide, des fichiers déposés et des séquences.
Vérifié : deux passages de suite rendent exactement le même état.

### Corrigé

#### Quinze documents de démonstration sur trente-trois n'avaient aucun fichier
Une ligne, et un chiffre. `dirname(__DIR__, 4)` visait `~/dev/test_files`, quatre
niveaux au-dessus du dépôt, là où la bonne résolution se trouvait vingt lignes plus
haut dans le même fichier. Aucune source n'était donc trouvée : ni fichier, ni
poids, ni vignette, une médiathèque de démonstration faite de lignes vides. Les onze
PDF ont aujourd'hui leur vignette, qu'aucun n'avait.

Le test censé l'attraper ne lisait que **l'autre moitié** de la liste. Il lit
maintenant les deux, et vérifie surtout que chaque racine construite par la fixture
existe - la garantie qui manquait, puisqu'une liste de fichiers ne peut rien révéler
quand ils sont tous introuvables ensemble.

Au passage, le commentaire qui expliquait le repli affirmait que `test_files/` n'est
pas livré avec le dépôt. C'était faux, six fichiers y sont suivis : le raisonnement
partait du symptôme.

#### Sept tests de bout en bout sur huit étaient morts
Ils se connectaient à `admin@aurora.app`, qu'aucune fixture ne sème, puis visitaient
`/login` et `/admin/*` - **sept adresses qui rendent 404 depuis le renommage
d'avril**, dont `/admin/crm/contacts`, un module qui n'existe plus du tout. Rien ne
les lançait, ni la porte ni la CI, donc rien ne l'avait jamais dit.

Lancés pour en avoir le cœur net : **dix-sept échecs, quatre succès**, et les quatre
sont tous dans le même fichier, celui qui teste le site public. Les sept autres
partent. `make test-e2e` passe maintenant, en six secondes au lieu d'une minute
d'attentes à vide.

Un test qui ne tourne jamais ne protège de rien, et un test qui échouerait s'il
tournait apprend à ignorer les échecs.

#### La couverture de code était calculée à chaque lancement de la suite
Le bloc `coverage` de `phpunit.dist.xml` déclarait deux rapports. Un rapport
déclaré dans la configuration est un rapport **demandé à chaque appel**, donc la
couverture était collectée et deux fichiers écrits pour lancer un seul fichier de
test.

Mesuré sur la suite unitaire : **trente-quatre secondes avec, quatorze sans**. Le
rapport HTML finissait aussi par épuiser les cinq cent douze mégaoctets, ce qui
faisait échouer la porte sur autre chose qu'un test - un échec qui se lit comme une
régression et n'en est pas une, ce qui est la pire espèce.

La couverture se demande maintenant quand on la veut, par `make coverage`, qui
allume pcov, lève le plafond mémoire et nomme ses rapports.

### Dans aurora-client
`make aurora-update`. Rien d'autre : l'entrée « Documentation » disparaît du menu, et
son adresse ne répond plus.

Le jeu de démonstration ne concerne que le développement local. Pour le remettre à
neuf : `make demo-reset`, qui vide la base **et les fichiers déposés**.

---

## [0.9.208] - 2026-09-20

### Ajouté

#### Un onglet Réglages, ouvert au référent
Tout ce qui se décide une fois pour un espace y vit : le dossier Drive que ce
client a partagé, et le mot de passe qui ferme cet onglet. Des sous-onglets
dès le premier sujet, parce qu'un écran de réglages grandit toujours, et mal.

**C'est ce qui donne enfin un sens au rôle de responsable.** Il ne disait
jusqu'ici qu'à qui s'adresser ; il ouvre maintenant une porte que ses
coéquipiers n'ont pas. Le reste du travail ne bouge pas : un équipier écrit,
commente et programme comme avant.

Le champ du dossier partagé y déménage. Il vivait au-dessus de la liste des
fichiers, où n'importe quel équipier le changeait, et où il occupait une place
sur un écran qu'on vient consulter.

#### Fermer l'onglet Drive par un mot de passe
Huit caractères au minimum, saisis deux fois, avec l'œil qui dévoile la saisie
- une faute de frappe ici ferme une porte dont plus personne n'a la clé.

**Il vaut pour tout le monde, y compris le rôle qui court-circuite tous les
privilèges.** Un mot de passe que le rôle le plus élevé contourne ne protège
de personne : il décore un écran. Et il ferme aussitôt pour celui qui vient de
le poser, parce qu'on ferme une porte pour la voir se fermer.

La serrure est vérifiée sur les routes, pas seulement à l'écran : un onglet
masqué n'a jamais fermé une adresse. Elle est posée sur les arguments de
contrôleur plutôt que route par route, pour que la prochaine route du Drive
soit couverte sans qu'on y pense.

**Ouvert pour une session, et par espace.** Se fermer avec le navigateur est
ce qu'on attend d'une serrure ; en ouvrir un n'ouvre pas les autres.

Le retirer demande de le saisir, sans quoi quiconque atteint cet écran
l'enlève en un clic. Le prix est qu'un oubli bloque : le secours est une
commande sur le serveur, ce qui est un vrai niveau d'autorité et pas une case
à cocher.

#### Redemander le mot de passe à tout le monde
Un bouton qui referme toutes les sessions ouvertes, la sienne comprise, **sans
changer le mot de passe**. C'est la réponse au doute ordinaire - un écran
resté ouvert ailleurs, quelqu'un à qui on a montré l'onglet : ceux qui le
connaissent le retapent, et il n'y a pas de nouveau mot de passe à
communiquer.

Une session ne retient plus « ouvert » mais quelle génération elle a ouverte,
et l'espace en porte une. C'est aussi ce qui fait que **changer le mot de
passe évince enfin** ceux qui étaient entrés avec l'ancien, ce qu'il ne
faisait pas.

#### Voir ce qu'un lien d'accès montre, sans répondre à la place du client
L'aperçu est un vrai lien, et c'est la seule façon qu'il dise vrai : le jeton
en clair n'existe qu'à la création, donc une page reconstruite avec un jeton
inventé s'affiche et ne répond à rien - ni dossier Drive, ni fichiers,
c'est-à-dire justement ce qu'on venait vérifier.

Il recopie les droits pour que l'écran soit le même, et refuse malgré tout
toute réponse : le refus tient à la nature du lien, pas à ses droits. Il
expire en quinze minutes, ne figure dans aucune liste, et le précédent est
supprimé quand on en demande un autre.

#### Deux droits de plus sur un lien, et une étape qu'on garde pour soi
Un lien d'accès peut voir ou non le dossier Drive. Et une étape du tableau se
marque **interne** : elle disparaît de la page du client, ses fiches avec
elle - la moitié qu'on oublie, et celle qui les laisserait dans son
calendrier, qui les lit par leur date et non par leur étape.

#### Deux pages de documentation
« Les réglages d'un espace » décrit l'onglet, le mot de passe et ses trois
boutons. La page « Dossier Google Drive » suit le champ qui a déménagé.

### Modifié

#### Un espace ne se voit plus par défaut
La liste montrait tous les espaces à qui avait le privilège, et
l'appartenance à un espace n'accordait rien. Elle décide maintenant : on voit
les siens, l'administration voit tout. Le contrôle est posé sur les arguments
de contrôleur plutôt qu'à chaque entrée, pour que le onzième contrôleur ne
puisse pas l'oublier.

### Corrigé

#### Trois écrans se taisaient en cas de refus
Le composant de requête rend l'enveloppe d'un 400 sans rien annoncer, et les
appels des réglages n'en lisaient que le succès. Un mot de passe erroné, un
mot de passe trop court, une adresse de dossier mal collée ne produisaient
**rien du tout** à l'écran, ce qui se lit comme un bouton mort.

#### Le poids d'un fichier s'écrivait de trois façons
Trois formateurs écrits à la main avaient poussé à côté du composable qui
existait déjà. Ils passent tous par lui, et un poids n'est affiché que
lorsqu'il y en a un : un dossier Drive n'en a pas, et « 0 o » se serait
affiché sous chacun.

#### Une route morte contournait le nouveau contrôle
Le déménagement du champ de dossier avait laissé derrière lui la route qui le
servait, ouverte à tout équipier. Elle disparaît.

#### La commande de secours n'effaçait qu'à moitié
Elle retirait le mot de passe à la main plutôt que de le demander à la
serrure, donc ne tirait pas de nouvelle génération : effacer depuis le serveur
laissait dedans ceux qui y étaient déjà.

### Dans aurora-client
`make aurora-update`, puis `make migrate` : la 0.9.208 ajoute une colonne aux
espaces.

Rien d'autre n'est requis. Aucun onglet Drive n'est fermé tant que personne ne
pose un mot de passe, et les espaces restent visibles comme avant pour les
comptes d'administration.

---

## [0.9.207] - 2026-09-20

### Corrigé

#### Le lot du Drive promettait une archive que le serveur coupait
La borne était posée pour le disque : cinq cents mégaoctets. Elle aurait dû
l'être pour le temps.

L'archive est écrite en entier avant que le premier octet ne parte, donc tant
qu'elle se construit le serveur web attend sans rien recevoir, et il finit par
abandonner. Apache coupe à trois cents secondes.

Mesuré sur le serveur contre un vrai dossier partagé : **1,41 Mo par seconde**
et **0,64 seconde par fichier**, cette seconde étant l'aller-retour vers
Google, que le fichier pèse trois kilo-octets ou trois mégaoctets. Deux cents
fichiers coûtent donc cent vingt-sept secondes avant le premier octet
transféré. Cinq cents mégaoctets demandaient trois cent cinquante-cinq
secondes de transfert à eux seuls : la borne annonçait un lot qui ne pouvait
pas aboutir, et l'attente se terminait par une erreur au bout de cinq minutes.

La borne passe à **cent cinquante mégaoctets**. Le pire cas admis, deux cents
fichiers et cent cinquante mégaoctets, demande deux cent trente-quatre
secondes, ce qui laisse un cinquième de marge.

#### Un refus s'affichait en JSON à la place de la page
Le bouton du lot est un lien, donc le navigateur navigue vers son adresse. Le
refus répondait en JSON, qui s'affichait alors en toutes lettres.

L'écran connaît le poids du dossier avant de dessiner le bouton : il ne le
propose plus au-delà de la borne, et dit ce qu'il faut faire à la place.
L'adresse, elle, rend un 404 - une adresse tapée à la main n'a pas à recevoir
d'explication.

### Ajouté

#### Une page de déploiement sur les délais du serveur web
`docs/aurora-client/deployment/web_server_timeouts.md` : ce qui se coupe au
bout de cinq minutes chez Apache et pourquoi `max_execution_time` ne rattrape
rien, l'équivalent nginx, et le fait que **Caddy n'a pas le problème** - son
`read_timeout` FastCGI et ses délais de serveur sont illimités par défaut,
seul `dial_timeout` vaut trois secondes.

La page dit aussi comment vérifier que le réglage gouverne vraiment, parce que
sa présence dans un fichier ne le prouve pas : suivant la façon dont la
distribution déclare le connecteur FastCGI, le délai peut venir d'ailleurs.

### Modifié

#### La page du tableau de bord annonçait quatre modules
Il y en a cinq depuis que le Studio a le sien. Capture refaite.

---

## [0.9.206] - 2026-09-19

### Ajouté

#### Emporter ce qui est partagé dans le Drive
Un dossier Drive branché sur un espace se regardait ; il s'emporte
maintenant. Chaque fichier a son téléchargement, et le dossier entier tient
dans une archive.

**Le nom du fichier vient de Google, jamais du navigateur.** Sa réponse au
contenu porte bien un `Content-Disposition: attachment`, mais sans `filename` :
relayée telle quelle, elle faisait atterrir « 1BxY_…Kp3 » dans le dossier de
téléchargement du client. Le nom se redemande donc à part, et seulement quand
il sert - un aperçu ne paie pas cet appel. Le laisser voyager dans l'adresse
aurait été écrire un en-tête de réponse à partir de ce qu'un visiteur envoie.

**Le lot répond à la seule question que se pose un client** à qui on partage
trente visuels : je prends tout. L'archive garde l'arborescence, descend
chaque fichier par morceaux dans un temporaire plutôt que de le tenir en
mémoire, et ne recompresse rien - ce sont des photos et des vidéos, déjà
compressées. Deux cas qu'un lot ne peut pas emporter sont nommés au lieu de
disparaître : ce qui dépasse cinq cents mégaoctets, refusé avant de commencer,
et les documents Google, qui n'ont pas d'octets à télécharger et sont listés
dans un fichier posé à la racine de l'archive. Drive accepte deux fichiers du
même nom dans un dossier ; le second prend un suffixe, faute de quoi
l'extraction en écrasait un.

**Les deux écrans relaient par le même service.** Le studio et la page qu'un
client ouvre par son lien d'accès servaient le même flux, écrit deux fois ; ce
qui les distingue est le contrôle qui précède l'appel, pas la façon de servir.
Écrit à deux endroits, l'en-tête de sécurité aurait fini par n'exister que
d'un côté.

Et cet en-tête manquait. Un fichier du Drive sort sous le domaine de
l'application : un HTML ou un SVG posé dans un dossier partagé, ouvert dans un
onglet, aurait exécuté son script sur la page d'un espace avec la session de
celui qui regarde. Ces types redeviennent des fichiers, qu'on ait demandé le
téléchargement ou non, et `nosniff` accompagne tout le reste.

#### Accrocher un fichier du Drive à une fiche, et le ranger dans la médiathèque
Un fichier partagé par le client se met maintenant sur une fiche, depuis le
formulaire de la fiche, par un sélecteur qui montre la même arborescence que
la vue Drive. Le calendrier suit sans rien faire : il affiche les mêmes
fiches.

**C'est le seul endroit de cette intégration qui recopie, et c'est voulu.** Le
reste ne recopie rien, parce qu'un dossier partagé est une étagère vivante et
que ce qu'on y retire disparaît de l'espace. Une pièce jointe sur une fiche
est l'inverse : une décision prise à un moment. Le brief qu'on épingle doit
rester celui dont on a parlé, pas un lien qui se vide le jour où le client
fait le ménage dans son Drive.

**Et c'est ce qui le rend utilisable partout sans rien inventer.** Une fois
dans la médiathèque, le fichier est un document comme un autre : les notes
savent déjà en afficher, les galeries en puiser, les fiches en accrocher. Un
troisième genre de pièce jointe, qui aurait pointé vers Google, aurait demandé
à chacun de ces écrans de connaître l'intégration. D'où le bouton « Ranger
dans la médiathèque » dans l'aperçu d'un fichier du Drive : c'est le passage
vers tout le reste, et c'est par là qu'une note prend une image du Drive.

L'accrochage tient en deux appels et non un : le premier recopie, le second
accroche par la route qui accroche déjà n'importe quel document. Une route qui
aurait fait les deux aurait ajouté un chemin de plus vers une pièce jointe,
alors que la moitié intéressante est qu'il n'y en ait pas.

Le nom vient de Google, comme pour un téléchargement, et le vrai déposeur est
monté dans le test pour cette raison : un double aurait rendu le nom qu'on lui
aurait appris.

#### Deux pages de documentation sur les connexions
La rubrique Configuration explique maintenant comment brancher Craft et
comment brancher un dossier Google Drive : ce que chaque connexion fait, ce
qu'elle ne fait pas, la marche à suivre chez le fournisseur, et ce qu'on
regarde quand rien n'apparaît.

La page sur Craft insiste sur un point mesuré plutôt que supposé : une
connexion en mode « Publique » répond à qui connaît son adresse, sans
authentification, et annonce elle-même les opérations d'écriture et de
suppression qu'elle accepte. Le mode « Clé API » est le seul qui referme ça.

### Modifié

#### Le Studio entre sur le tableau de bord
L'écran d'arrivée montrait l'éditorial, la médiathèque, le calendrier et les
comptes. Les espaces clients, les contrats et les présentations, c'est-à-dire
ce qu'on ouvre tous les jours, n'avaient aucun panneau. Chaque module apporte
le sien, et celui-là manquait : ça ne se voit pas, un tableau de bord
incomplet ressemblant à un tableau de bord.

Deux chiffres sont mis en avant plutôt que tous, parce que ce sont les seuls
sur lesquels on agit en arrivant : ce qui attend une réponse du client, et où
en sont les contrats. Le reste situe, une carte en attente sur trois n'étant
pas la même nouvelle qu'une sur quarante.

#### Le panneau Calendrier annonçait zéro partout
Il fournissait ses chiffres depuis le début sans être inscrit dans la table
qui décide à qui on les demande. Un panneau absent de cette table s'affiche
quand même - elle ne masque que ce qu'elle dit faux - mais ses chiffres ne
sont jamais calculés. Il annonçait donc zéro calendrier et zéro retard, ce qui
ressemble à une installation vide plutôt qu'à un panneau débranché. Sept
calendriers et quatre retards après correction.

#### Les chiffres d'une rangée s'alignent
Chaque panneau redessinait sa tuile, et le détail perdu en route était
l'alignement : un libellé qui passe sur deux lignes poussait son nombre d'une
ligne vers le bas, et deux tuiles côte à côte affichaient leurs chiffres à
deux hauteurs. Une rangée de tuiles est un objet répété, donc une seule tuile
partagée, où le libellé prend la place qu'il lui faut et le nombre se pose au
bas de la carte.

Un lien du panneau Calendrier faisait seize pixels de haut. Il ne s'était
jamais montré : le panneau était vide.

#### Une carte peut porter une date sans paraître dans le calendrier
Une date et une parution ne sont pas la même chose. Une carte peut porter une
échéance qui regarde le studio et personne d'autre : une relance à préparer, un
tournage à caler, un envoi à vérifier. Datées, elles s'invitaient toutes dans
le mois et noyaient ce que le calendrier existe pour montrer.

Une case sous le champ de date, cochée par défaut. Décochée, la carte garde sa
date, reste sur le tableau, et disparaît du mois.

**Des deux calendriers, et c'est le second qui se serait oublié.** Une carte
datée n'alimente pas seulement le mois de son espace : elle est annoncée à
l'agenda partagé du studio, celui qui rassemble tous les clients. Retirée d'un
seul des deux, la case aurait menti. La règle est donc dite à l'endroit où
l'annonce est faite, une fois pour les deux.

Vrai par défaut y compris pour un appel qui ne porte pas le champ. C'est le
défaut qu'un booléen ajouté à une entrée existante produit : absent du corps,
il vaudrait faux, et toutes les cartes enregistrées par un écran plus ancien
auraient quitté le calendrier en silence.

#### La démonstration n'envoie plus deux fois le même lien d'accès
Chaque `make demo` émettait un nouveau lien pour un destinataire qu'il avait
déjà. Trois passages listaient Camille trois fois dans l'écran d'accès client,
ce qui se lit comme un défaut du produit plutôt que comme une fixture jouée
deux fois.

#### Les captures de la documentation suivent la barre d'un espace
Vingt-cinq captures dataient d'avant la vue Drive, et montraient une barre à
cinq onglets là où un lecteur en voit six. Refaites.

Les deux parcours ajoutés prennent la même précaution que celui du stockage :
l'adresse du compte de service, celle de la connexion Craft, l'identifiant du
dossier et la liste des fichiers sont remplacés par des valeurs de
démonstration avant la prise. Ces images partent dans un dépôt public et sur
une page que n'importe qui peut ouvrir ; sans cela, elles auraient emporté le
contenu d'un Drive personnel.

---

## [0.9.205] - 2026-09-19

### Ajouté

#### Brancher un dossier Google Drive sur un espace client
Le dernier chantier du backlog. Un espace peut désigner un dossier Drive que
son client a partagé, et les fichiers apparaissent dans sa propre vue Drive -
côté studio, et côté client dans la page qu'il ouvre par son lien d'accès.

**À côté de Fichiers, et non dedans.** La barre d'un espace sépare déjà par
origine - ce qui est posé sur les fiches, ce qui appartient à l'espace - et un
dossier qui vit chez le client en est une troisième. Rangé en section sous les
fichiers, il fallait faire défiler tout le reste pour l'atteindre.

**Rien n'est recopié.** Le fichier reste chez le client : il est lu chez Google
au moment où quelqu'un le regarde, et servi sous une adresse d'ici. Retirer un
fichier du dossier partagé le retire de l'espace, ce qu'on attend d'un dossier
partagé et non d'une copie qui vieillit. C'est aussi ce qui fait que le client,
qui n'a pas de compte Google, voit les fichiers : une adresse Drive lui
donnerait un mur d'authentification.

**Un compte de service plutôt qu'OAuth**, et ça change la forme de tout. Le
chemin habituel demande à une personne d'autoriser l'application dans son
navigateur : un écran de consentement, une route de retour, un jeton de
rafraîchissement à garder vivant, et une réautorisation le jour où il expire.
Un compte de service a une adresse, et le client partage un dossier avec elle
comme il le ferait avec un collègue. La portée se décide donc chez Google, pas
ici : ce qui n'est pas partagé n'existe pas pour ce compte, et retirer le
partage referme la porte sans toucher à un réglage.

La clé est posée une fois pour l'installation ; chaque espace ne désigne
ensuite que son dossier. Le partage se transmettant aux sous-dossiers, un
dossier parent partagé une fois suffit pour tous les clients.

**La descente est récursive, et coûte un appel par étage.** Google accepte
plusieurs parents dans la même requête : une arborescence de trois niveaux
coûte trois appels quel que soit le nombre de dossiers qu'elle porte, là où
descendre dossier par dossier en aurait fait un chacun. Le chemin voyage avec
le fichier - « Contrats/2026 » devant son nom. Deux bornes : cinq étages,
parce qu'un raccourci circulaire dans un Drive ferait tourner la descente sans
fin, et deux cents fichiers, parce qu'au-delà le dossier partagé était trop
large.

**L'arbre se reconstruit dans le navigateur, pas chez Google.** La descente
ayant déjà ramené chaque fichier avec son chemin, les dossiers se déduisent de
cette liste : un dossier s'ouvre, un fil d'Ariane en remonte, et ni l'un ni
l'autre ne coûte un appel. Redemander le contenu d'un dossier à chaque fois
qu'on l'ouvre aurait payé deux fois ce qu'on avait déjà en main.

Les fichiers s'affichent en cartes ou en liste, au choix qui se retient, et en
liste d'office quand la colonne est trop étroite pour une vignette. Un clic
ouvre l'aperçu, le même composant que pour les fichiers de l'espace, et il lit
par l'adresse d'ici : c'est ce qui le rend identique pour le studio et pour un
client sans compte Google. Les vignettes sont petites - elles servent à
reconnaître un fichier, pas à le lire - et viennent du CDN de Google, qui les
sert sans authentification : les relayer aurait coûté un appel par image pour
moins d'un kilo-octet.

**Rien n'a été emprunté pour signer.** `google/apiclient` aurait amené des
centaines de définitions de services dans un dépôt public livré à des clients,
pour une assertion de trois champs ; `openssl` la signe en trente lignes, et
les tests vérifient la signature contre une vraie clé RSA plutôt que de la
comparer à un littéral - ce qui n'aurait prouvé que son immuabilité.

Lecture seule, demandée à Google dans la portée du jeton : une requête
d'écriture écrite par erreur plus tard serait refusée par Google lui-même. Les
deux routes publiques sont derrière le même contrôle que la page d'un lien
d'accès, donc révoquer un lien referme le dossier à l'instant où il referme la
page. Et rien n'est gardé par un intermédiaire, puisque le client peut
départager à tout moment.

Trois pièges que les tests ont attrapés avant le premier appel réel : un
dossier partagé depuis un Drive partagé rend une liste vide et muette sans deux
drapeaux, la corbeille d'un Drive ressortirait comme un fichier vivant, et un
jeton refusé ne doit pas être mis en cache - le contrat du cache garde aussi
les `null`, donc une clé fausse aurait fait taire l'intégration cinquante
minutes au lieu de marcher au rechargement suivant.

### Dans aurora-client
`make aurora-update`, puis `make migrate` : la 0.9.205 ajoute une colonne aux
espaces.

L'intégration reste éteinte tant que personne ne colle une clé de compte de
service dans les réglages, onglet Google Drive.

---

## [0.9.204] - 2026-09-19

### Modifié

#### Le calendrier que voit le client prend sa forme de téléphone
Les quatre calendriers d'Aurora dessinent la même grille. Trois d'entre eux
passent en index à pastilles sous cinq cent soixante pixels, avec la liste du
jour choisi en dessous : sept colonnes dans trois cent soixante-quinze pixels
font des cases de cinquante, la place d'un numéro de jour et pas celle d'un
titre. Le quatrième, celui que le client ouvre depuis son lien d'accès, ne le
faisait pas.

Il montrait donc des pastilles d'événement de trente-six pixels sur seize, la
hauteur d'une ligne de texte. Elles deviennent des lignes de trois cent
quarante et un sur quarante dans la liste du jour. La page raccourcit de deux
cent vingt et un pixels au passage, et rien ne change à partir de la largeur
d'un écran.

Trois commandes de la vue client tombaient aussi sous les vingt-huit pixels
d'une icône d'Aurora : les trois points d'un salon, le titre qui ouvre la liste
des canaux, et « Ouvrir » sous un fichier. Passage mesuré depuis un vrai lien
d'accès, sans compte : plus une seule cible sous vingt-huit pixels, et rien ne
déborde.

#### Trente pixels sous le pouce dans la barre d'un espace
Passage mesuré sur soixante-sept écrans et états à trois cent soixante-quinze
pixels : rien ne déborde, et la gouttière vaut huit partout dans le back-office.
Trois commandes tombaient sous la taille des autres : les cinq onglets de
l'espace client, la flèche de retour et « Ajouter un contenu » faisaient
trente pixels sur vingt-deux, quand la moindre icône d'Aurora en fait
vingt-huit sur vingt-huit. Une icône de quatorze pixels avec quatre pixels
d'air au-dessus et en dessous, c'est ce qu'on rate au doigt.

Elles passent à trente sur trente sous `sm`, taille inchangée au-dessus. La
barre de l'espace y gagne huit pixels de haut, et la discussion tient toujours
dans l'écran sans faire défiler la page.

La plus petite cible de toute l'application était ailleurs : sous une vignette
de fichier, le lien vers le contenu auquel elle appartient faisait seize pixels
de haut, la hauteur de sa ligne de texte. Sa zone sensible monte à vingt-huit
sans que la carte bouge.

#### Les boutons des formulaires publics prennent la ligne
Les pages d'authentification l'avaient déjà ; les deux formulaires écrits à la
main du site ne l'avaient pas. « Publier » sous un commentaire faisait quatre-
vingts pixels collés au bord gauche d'un écran de trois cent soixante-quinze, et
le bouton d'un formulaire de contact autant. Ils prennent les trois cent
quarante-trois pixels de la ligne sous `sm`.

Le formulaire à étapes suit la même règle que les cartes : seul, le bouton prend
la ligne ; à deux, « Précédent » et « Envoyer » s'en partagent les moitiés.

Les quatre boutons passent au composant commun plutôt qu'à des classes recopiées
à la main : ils gagnent l'anneau de focus au clavier, le survol, et le sablier
pendant l'envoi, qui n'existaient sur aucun des deux formulaires publics. Au
passage, « Précédent » portait un filet et son voisin non : deux pixels d'écart,
et les deux boutons d'une rangée se décalaient. Ils s'étirent maintenant à la
même hauteur.

Ce qui reste écrit à la main : les pastilles de réaction sous un commentaire et
les deux mots soulignés qui répondent ou annulent. Ce ne sont pas des boutons du
jeu commun, et les y forcer demanderait plus de classes que d'en garder aucune.

#### Huit pixels aussi entre les blocs
La marge autour était à huit, le rythme entre les blocs restait à seize : sur la
liste des publications, la barre de recherche, le bouton d'actions, le panneau
de filtres et la liste étaient séparés de seize pixels chacun, et cela
s'ajoutait aux seize de marge intérieure des cartes - trente-deux de vide entre
deux contenus. Vingt-six écrans passent à huit sous `sm`, mesuré : les trois
écarts de la liste des publications font maintenant huit, huit, huit.

Les formulaires gardent leurs seize : entre deux champs, c'est un rythme de
lecture, pas une gouttière, et les serrer ferait un mur.

Dernier passage sur les boutons : sept commandes en icône seule portaient
encore la variante fantôme et prenaient donc une surface sur téléphone, pour
rien. Elles passent à `icon`, dont la transparence est voulue.

#### Un bouton n'est jamais transparent sur téléphone
Le bouton fantôme se lit à la souris : il attend le survol pour exister, et sa
place dans une barre dense dit déjà que c'en est un. Au doigt, il n'y a pas de
survol - et pleine largeur, sans fond, il ressemble à une ligne de texte. On ne
sait pas qu'on peut appuyer.

Sous `sm`, il porte donc une surface sourde et un filet ; au-dessus, il redevient
le fantôme qu'il doit être. Deux cent dix-neuf boutons y gagnent d'un coup, dans
tout le back-office.

Les commandes en icône seule d'une barre gardent leur transparence : leur place
dit ce qu'elles sont, et trois cadres alignés en haut de l'écran seraient du
bruit. Elles passent à la variante `icon`, qui existe pour ça.

#### Le même défaut cherché partout où il pouvait vivre
Un geste principal coincé à côté d'un sélecteur de vue ou d'une feuille
d'actions. Vingt-cinq lignes de ce genre relues dans le back-office ; trois
portaient le défaut :

- **Les fichiers d'un espace** : « Déposer un fichier » tombait à cent
  dix-sept pixels et « Choisir dans la médiathèque » se repliait sur deux
  lignes. Les deux prennent la ligne, le sélecteur passe dessous.
- **Le document d'un contrat** : « Contresigner », le geste de la page, se
  retrouvait entre un retour et une feuille d'actions. Les trois s'empilent.
- **La présentation qu'un client ouvre** : « Présenter » prend la ligne, et la
  page resserre sa gouttière comme les autres pages publiques.

Les vingt-deux autres sont des en-têtes de carte où un titre partage la ligne
avec une petite action, ou des paires qui tiennent déjà : « Présenter » et
« Actions » font deux cent trente pixels sur trois cent cinquante-neuf.

#### « Nouvelle note » prend la ligne
Cent vingt-huit pixels à côté d'une bande d'onglets qui en fait deux cent
soixante-quatorze : le seul geste de l'écran passait pour un détail de la bande.
Il prend la ligne entière sur téléphone, les onglets la suivante.

#### Les panneaux de la discussion et les voiles rendent leurs bords
Suite du relevé, cinquante-neuf endroits relus. Ce qui bouge : les trois
panneaux d'une discussion - l'en-tête, le fil et la zone d'écriture - où une
bulle gagne seize pixels de largeur ; la gouttière autour des panneaux flottants
(recherche, notifications, visionneuse) ; la barre du haut sur téléphone, qui
s'aligne enfin sur la page qu'elle coiffe ; et l'aperçu d'une grille dans sa
modale, où seize plus seize faisaient trente-deux.

Ce qui ne bouge pas, et pourquoi : vingt-quatre cellules de tableau, que le
téléphone ne voit jamais ; les blocs de texte d'une carte, qui sont la marge
validée à seize ; les entrées de menu et les lignes de liste, où seize pixels ne
sont pas de l'espace perdu mais la cible que vise le pouce.

#### Huit pixels devient la marge par défaut sur téléphone
Les six coquilles avaient huit pixels sur les côtés et gardaient seize ou
trente-deux en haut et en bas : deux valeurs pour une même marge, sur l'appareil
qui a le moins de hauteur. Elles passent à huit dans les deux sens - coquille
d'administration, espace client, les trois pages publiques et les écrans
d'entrée.

Les trois panneaux du carnet de notes suivent : trente-deux pixels de largeur
rendus à la zone où l'on écrit.

La règle qui en sort : **sur téléphone, huit est la marge par défaut, pas
seize.** Seize ne se garde que là où du texte touche un bord dessiné -
l'intérieur d'une carte, d'une modale. Une coquille, un panneau, une zone de
travail n'ont rien à protéger.

Les quarante-et-une cartes qui portaient encore seize pixels ont été relues une
par une : huit enveloppaient une liste, une grille ou une barre d'outils et
n'avaient rien à protéger - la barre de fil d'Ariane de la médiathèque, ses
barres de sélection, celle de la corbeille, les panneaux de calendriers, la
grille de contenu, l'éditeur de présentation. Elles passent à huit. Les
trente-trois autres portent du texte contre un bord dessiné et gardent leurs
seize, qui sont ceux de la carte validée sur les espaces clients.

La règle ne vaut que sous `sm` : sur un grand écran, seize ou vingt-quatre
pixels ne coûtent rien et aèrent, donc chaque réduction s'écrit avec sa
contrepartie `sm:` et le bureau ne bouge pas.

Reste au relevé : vingt-huit blocs intérieurs et trente-et-un divers, suivis
dans le plan. Les cellules de tableau en comptent trois cents de plus, hors
sujet : le téléphone ne voit jamais un tableau, il reçoit des cartes.

#### La page de signature et l'administration, relues au doigt
Les deux derniers écrans de la conversion. Pour voir le premier, il a fallu
envoyer un contrat depuis l'interface et relever le lien dans la boîte de test :
la page tient à trois cent soixante-quinze, « Signer le contrat » prend la
ligne, et « Recevoir le code » la prend aussi maintenant - c'est l'étape
d'avant, sur une page qu'un client ouvre presque toujours au téléphone.

Côté administration, les cinq tableaux tiennent chacun dans leur propre boîte
qui défile, la gouttière est celle de tout le monde, et les sept onglets vivent
dans le tiroir du menu, qui les donne sur un téléphone.

#### La même gouttière sur chaque écran, vérifiée écran par écran
Vingt-deux pages du back-office relevées une par une : toutes à huit pixels de
gouttière, la carte à huit du bord et seize de marge intérieure - la mesure
prise sur les espaces clients, qui sert de référence. Les quatre adresses
restantes du relevé ne sont pas des pages mais des réponses JSON.

Ce qui manquait à l'appel : les **écrans d'entrée**, restés à seize pixels, et
le bouton d'actions des thèmes comme les boutons d'enregistrement des réglages,
qui gardaient leur largeur naturelle dans un coin.

Et le graphe des notes mesurait sa hauteur en `vh` : sur un téléphone, cette
unité compte la barre d'adresse qui n'est pas là, et le graphe sortait de
l'écran par le bas.

#### D'un client à ses espaces, en une entrée
Un client existe pour ce qu'on fait avec lui, et ce qu'on fait avec lui vit
dans ses espaces : depuis la liste des clients, aucun chemin n'y menait, ni sur
téléphone ni sur un écran large. Il fallait passer par le menu, ouvrir la liste
des espaces et retaper le nom. **Ses espaces** le tape pour vous.

Ce qui rend le lien possible : une liste filtrée lit maintenant `?search=` dans
son adresse au premier rendu. `useUrlSearchSync` écrivait ce paramètre pour
qu'une liste filtrée se partage, mais personne ne le relisait - le lien arrivait
sur la liste entière et le mot tapé ne servait qu'à celui qui l'avait tapé.
Toutes les listes filtrées de l'application deviennent adressables du même coup.

#### Contrats et présentations passent au dessin commun des cartes
Leurs gestes étaient repliés à leur taille naturelle, deux par ligne, avec une
colonne ragoteuse au milieu. Ils prennent le dessin décidé pour les autres
cartes - à deux ils partagent la ligne, à trois et plus ils s'empilent - et
sceller un contrat garde son ambre, que la liste reprend de la feuille
d'actions.

#### L'éditeur de publication faisait défiler la page de côté
Ses sept onglets - paramétrage, apparence, en-tête, contenu, galerie, SEO,
traductions - font sept cents pixels. Sans boîte pour les tenir, c'est la page
entière qui partait de côté sur téléphone, éditeur compris : on écrivait dans
un champ qu'il fallait ramener du doigt. La bande défile seule maintenant, et
la page ne bouge plus - mesuré à trois cent soixante-quinze, sur les sept
onglets.

#### Les cartes et les modales rendent leurs bords sur téléphone
La gouttière de la page était passée à huit pixels, mais ce qui est dedans
gardait ses vingt-quatre : sur trois cent soixante-quinze, le texte d'une carte
commençait à trente-trois pixels du bord de l'écran. Trente-quatre cartes
passent à douze ou seize sous `sm` et retrouvent leur aise à partir de là.

Deux endroits partagés comptent plus que les autres : **toutes les modales** de
l'application, dont le champ gagne seize pixels, et la carte de connexion.

#### La corbeille rendait trois pixels au nom de ce qu'elle contient
« Restaurer » et « Supprimer définitivement » font trois cents pixels à eux
deux ; sur trois cent cinquante-neuf, il en restait **trois** pour le nom de la
ligne, qui se pliait en colonne d'une lettre par ligne. Le nom prend sa ligne,
les deux gestes la suivante, moitié-moitié.

Sur le profil, les trois boutons « Enregistrer » et la suppression du compte
prennent aussi la ligne entière, et les règles du mot de passe passent à une par
ligne : à deux colonnes, « Une lettre majuscule » tombait dans cent
quarante-six pixels et repassait à la ligne, ce qui donnait quatre règles sur
six lignes mal alignées.

#### Partager ou renommer un calendrier, au doigt
Les deux boutons d'une ligne de calendrier n'apparaissaient qu'au survol. Sur un
téléphone, où le survol n'existe pas, il n'y avait aucun moyen de partager un
calendrier ni de le renommer. Sous `md` ils restent affichés, à côté du compte
plutôt qu'à sa place, et leur cible passe de quatorze à vingt-deux pixels sans
que la ligne bouge. Au-dessus, le survol reprend la main.

#### Le planning partagé se lit vraiment sur un téléphone
Trois choses manquaient sur la page qu'un invité ouvre sans compte :

- **Une case du mois ne répondait pas.** Elle tient trois pastilles et un
  nombre : on voyait qu'il se passait quelque chose, jamais quoi. Taper un jour
  ouvre maintenant sa liste, comme côté studio.
- **L'onglet « Semaine » mentait.** Sous `md`, sept colonnes ne tiennent pas et
  la vue retombe sur le jour - mais l'onglet restait allumé en montrant un seul
  jour. Il s'efface là où il est refusé, et revient avec la place, le choix
  gardé. Même correction sur le planning du studio.
- **Un rappel offrait sa case à cocher** à quelqu'un qui n'a le droit de rien
  cocher. Il prend la barre de couleur d'un événement : pour qui lit, c'est
  quelque chose à vingt heures. Un rappel déjà fait garde sa coche, qui est un
  fait.

#### Le nombre d'événements d'une case pesait quatre fois ses pastilles
Dans la grille compacte du mois, au-delà de trois événements, les pastilles
laissent place à un nombre. Il s'affichait en seize pixels à côté de pastilles
de quatre, plus gros que le numéro du jour : la classe `text-3xs` qu'il portait
n'existe dans aucun thème, donc le nombre héritait simplement de la taille du
corps. Dix pixels, et il repasse sous le numéro du jour, qui en fait douze.

#### Se connecter sans faire défiler une page de vente
Sur téléphone, les huit écrans d'entrée montraient d'abord l'argumentaire - le
titre, la phrase et les quatre atouts, près de cinq cents pixels - et le champ
e-mail arrivait après. Quelqu'un qui vient se connecter fait maintenant face au
formulaire : le logo, la carte, puis l'argumentaire en dessous. À partir de
`lg`, les deux colonnes reprennent leur place et l'ordre écrit redevient l'ordre
lu.

Les trois pages publiques - planning partagé, note partagée, espace client -
resserrent leur gouttière comme le reste, et signent au centre : « Aurora » était
collé au bord gauche là où la page de connexion le centrait depuis toujours.

#### Éditorial passe au gabarit mobile
Six écrans relus à trois cent soixante-quinze pixels, et ce qui s'y repliait
corrigé :

- **Les commentaires** écrivent leurs gestes sous la carte - approuver,
  indésirable, supprimer - et l'adresse passe sous le nom : les deux sur une
  ligne donnaient « Best SEO Offer <contact@e… », où le nom perd sa fin et
  l'adresse n'a jamais commencé. Les trois points restent là où il y a une
  souris.
- **Les galeries** empilent leur ligne : le titre tombait à cent trente-deux
  pixels pour laisser la place à « Aucune photo » et au bouton. Il prend la
  ligne, ils prennent la suivante.
- **Taxonomies, types de contenu et formulaires** donnent la ligne entière à
  leur bouton d'actions. La largeur est posée sur l'enfant rendu et non sur le
  composant : une feuille d'actions a deux racines, le déclencheur et sa
  modale, et Vue laisse alors tomber les attributs qu'on lui passe - ce qui
  explique pourquoi la même classe fonctionnait ailleurs, dans une barre de
  liste qui visait déjà l'enfant.
- **« Ajouter un champ »** se réduit à son plus en face du titre de son bloc.
- **Le tableau de bord** ne fait plus défiler sa bande d'onglets : cinq
  libellés font quatre cent quarante-neuf pixels pour trois cent cinquante-neuf
  de bande, sur l'écran d'arrivée. Seul l'onglet ouvert garde son nom, les
  autres leur icône - deux cent quarante-sept pixels, et plus rien à faire
  glisser.

Rien à corriger sur les menus, qui tenaient déjà.

#### La médiathèque se laissait regarder, pas manipuler, au doigt
Les trois gestes d'une vignette - voir, modifier, QR code - vivaient dans un
voile qui n'apparaît qu'au survol. Sur un téléphone, où le survol n'existe pas,
modifier un document depuis la médiathèque était **impossible** : la carte
entière ouvrait l'aperçu, et rien d'autre n'était atteignable. Sous `sm`, un
bouton visible ouvre donc la feuille des six gestes ; au-dessus, le survol
reste.

Dans la foulée, deux choses qui mentaient sur cet écran. L'interrupteur
vignettes / liste disparaît là où le conteneur impose déjà les vignettes. Et le
bloc de cartes qui attendait « liste **et** étroit » a été retiré : les deux ne
sont jamais vrais ensemble, il ne s'est donc jamais affiché - soixante-cinq
lignes qui décrivaient un écran que personne n'a vu.

Les cartes des clients, des espaces et de la médiathèque écrivent maintenant
leurs gestes comme celles des publications.

#### Sur une carte, les gestes sont écrits
Une carte de téléphone est déjà la feuille d'actions ouverte : elle occupe
toute la ligne, la place de nommer ce qu'on peut faire ne manque pas. Les
cacher derrière trois points y ajoutait un geste sans économiser un pixel, et
posait une modale par-dessus la liste qu'on était en train de lire.

`AppCardActions` dessine donc les mêmes lignes que la feuille, sans la modale :
même ordre, mêmes couleurs, le rouge sur ce qui détruit, une cible de quarante
pixels de haut sur toute la largeur. Les descriptions, elles, restent à la
feuille : une phrase sous chaque geste a sa place devant une décision, pas
répétée sous les neuf cartes d'une liste, où elle doublait la hauteur de la
page pour redire neuf fois ce qu'on avait lu la première.

Sur une carte, chaque rangée centre son libellé et son icône ensemble : à
pleine largeur c'est un bouton qu'on vise, pas une entrée de liste qu'on
parcourt. La feuille, elle, garde son bord gauche, parce qu'on y cherche un
geste parmi d'autres et que ses lignes portent une phrase d'explication. Ce
qu'on ne fait nulle part : centrer le texte en laissant l'icône accrochée au
bord, où l'écart entre les deux change à chaque libellé.

Deux gestes se partagent la ligne, trois et plus s'empilent : à deux, chaque
moitié fait cent soixante-dix pixels, assez pour « Supprimer » et juste ce qu'il
faut pour que la rangée remplisse la carte au lieu d'étirer deux mots sur trois
cent quarante. À trois, les colonnes tomberaient à cent dix et
« Prévisualiser » n'y tiendrait plus.

En place sur les publications, les catégories et les étiquettes de la
médiathèque.

#### Le back-office entier récupère seize pixels sur téléphone
La coquille d'administration donnait seize pixels de gouttière de chaque côté
et trente-deux en haut, sur le seul appareil qui n'en a pas à donner. Huit et
seize suffisent : les cartes gardent leurs coins arrondis, donc elles se lisent
toujours comme des blocs posés. C'est la seule marge que les quarante-six
écrans partagent, donc la seule qui se corrige une fois.

Dans la même veine, la barre de liste partagée met ses actions dans leur propre
boîte : posées directement dans la grille, deux boutons devenaient deux cellules
et cassaient la colonne de droite, et chacun gardait sa largeur naturelle,
collé à gauche d'un vide de deux cent cinquante pixels. Ils prennent la ligne
sous `sm` et retrouvent leur taille au-dessus, dans les neuf listes qui en
héritent : Publications, Galeries, Catégories et Étiquettes de la médiathèque,
Contrats, Modèles, Clients, Espaces, Présentations.

Et sur la fiche d'une publication lue au téléphone, les quatre gestes -
modifier, prévisualiser, dupliquer, supprimer - prennent chacun leur ligne
entière au lieu de se replier deux par deux en laissant une colonne ragoteuse
au milieu de la carte.

#### La discussion tient dans l'écran, sans faire défiler la page
Sur téléphone, la conversation dépassait de soixante pixels : assez pour que la
page bouge, pas assez pour que ça serve. Elle prend maintenant **exactement** la
place laissée par l'en-tête, quelle que soit la hauteur de celui-ci, et c'est le
fil qui défile en dedans pendant que le champ d'écriture reste sous le pouce.

La coquille ne devine plus : une soustraction en dur - la fenêtre moins huit rem
et demie - valait pour un en-tête sur une ligne, et sur téléphone il en prend
deux. L'écran qui veut tenir dedans le demande, et la page lui donne la hauteur
ferme sans laquelle une colonne flex n'a rien à distribuer. Sur un écran
vraiment bas, la page redéfile plutôt que de couper le champ d'écriture.

#### Ce qui prenait de la place pour ne rien dire
- **L'état du direct** est une icône. « reconnexion… » prenait cent trente
  pixels au nom du salon, qui passait à la ligne pour un mot qu'on lit une fois
  par heure ; l'antenne barrée et sa couleur disent la même chose, et le mot
  reste à l'infobulle et au lecteur d'écran.
- **Le champ d'écriture** dit « Écrire un message… ». Le rappel des raccourcis
  quittait soixante caractères de la phrase qu'on lit avant d'écrire ; il
  occupe maintenant le vide à gauche du bouton Envoyer, à partir de `md` et
  donc sur les écrans qui ont un clavier.
- **« Ajouter un contenu »** se réduit à son plus en tête de colonne, où le
  libellé se repliait sur trois lignes en face du nom de la colonne.
- **Le choix kanban / liste** disparaît là où il est déjà refusé : un conteneur
  étroit impose la liste, et un interrupteur qui ne change rien à l'écran se lit
  comme un interrupteur cassé. Ce qui a été choisi est gardé, et le bouton
  revient avec la place.
- **La pastille « interne »** ne s'affiche plus sur une conversation privée, où
  elle répondait à une question que personne ne se pose.
- **« Ce que vous écrivez ici est lu par le client »** ne s'affiche plus sous la
  zone de saisie du salon principal. Les deux phrases qui disent l'inverse - un
  canal interne, une conversation privée - restent : ce sont celles où se
  tromper coûte quelque chose.
- **Révoquer et supprimer un lien d'accès** ont la même forme : chacun porte son
  icône, son nom et son cadre, et ils se partagent la ligne. L'un était un mot
  sans contour, l'autre une corbeille sans mot, avec vingt-six pixels de cible
  sur téléphone.
- **La date d'un contenu** repart du bord gauche de sa carte. Elle était calée
  sous le titre, donc décalée de la largeur d'une vignette, y compris sur les
  fiches qui n'en ont pas : la date partait seule vers le milieu.

#### Les gestes se prennent la ligne entière sur téléphone
Envoyer un message, créer un lien d'accès, ouvrir un canal au client, ajouter
quelqu'un, supprimer : chacun prend toute la largeur sous `sm`, et retrouve sa
taille naturelle dès qu'il y a la place. Trois boutons côte à côte dans trois
cents pixels tenaient chacun sur deux lignes sans offrir de cible franche.

L'accès client s'empile pour la même raison : le nom, l'adresse, l'échéance et
l'état avaient une ligne à se partager, et « Camille, g… » était tout ce qu'on
en lisait.

#### Ouvrir un canal, parler à quelqu'un : deux modales
Le rail dépliait ses formulaires en dessous de lui, dans deux cents pixels de
large, et sur téléphone dans un tiroir qui couvre déjà la conversation. Le nom
d'un nouveau canal et le choix d'une personne se demandent maintenant au milieu
de l'écran, et le tiroir se referme derrière.

#### Un fichier par ligne sur téléphone
Deux colonnes donnaient des vignettes de cent soixante-treize pixels : une image
qu'on devine plutôt qu'on ne la reconnaît, alors que c'est tout ce qu'on demande
à cette vue. Celle qui reste prend la largeur, en quatre tiers plutôt qu'en
carré pour qu'il en tienne deux et demie par écran, et le nom sous elle cesse
d'être coupé au troisième mot. Qui veut voir beaucoup de fichiers d'un coup a la
liste, juste à côté.

#### L'espace respire mieux sur un téléphone
Seize pixels de gouttière de chaque côté sur trois cent soixante-quinze, c'est
près d'un dixième de la largeur donné à du vide, sur le seul appareil qui n'en
a pas à donner. La coquille d'un espace en met **huit** sous `sm` : les cartes
gardent leurs coins arrondis, donc elles se lisent toujours comme des blocs
posés, et elles récupèrent seize pixels de contenu au passage.

La règle vaut pour le reste de l'application : sur téléphone, la gouttière se
réduit, elle ne disparaît pas, et rien ne change au dessin des blocs.

**La liste des contenus met sa date sous le titre** sur téléphone. La date et
la pastille d'approbation tiennent leur largeur quoi qu'il arrive, donc sur un
écran étroit c'était le titre qui payait : « Témoignage client » s'affichait
« Témoignag… ». Descendues sur leur propre ligne, alignées sous le titre et non
sous la vignette, elles rendent au titre la largeur de la carte : de cent dix
pixels à deux cent trente-sept. À partir de `sm`, la ligne reste une ligne.

**La barre du haut suit.** « Retour aux espaces » prenait cent soixante-treize
pixels sur trois cent soixante-quinze, près de la moitié de la barre pour un mot
que le chevron dit déjà, et le nom de l'espace se retrouvait dans les cent
soixante-dix qui restaient. Le libellé disparaît sous `sm`, le nom récupère
trois cent vingt et un pixels et tient en entier, et les deux onglets passent en
pleine largeur sur leur ligne : deux cibles de doigt plutôt que deux mots serrés
à gauche d'un vide.

#### Le calendrier d'un espace se lit comme celui d'un téléphone
Sept colonnes dans 375 pixels font des cases de cinquante : la place d'un
numéro de jour, pas celle d'un titre. Sous 560 pixels de conteneur, la grille
devient donc un index à pastilles et les publications passent dans une liste
sous elle, celle du jour choisi - ce que font Google et Apple, pour la même
raison arithmétique.

La grille compacte existait déjà, écrite pour le module Calendrier ; elle sert
ici telle quelle, avec un mode « collé aux bords » en plus. La liste du jour,
elle, est écrite dans la vue de l'espace plutôt qu'empruntée au calendrier :
trente lignes valent mieux qu'un couplage qui casserait l'espace le jour où le
module Calendrier n'est pas installé.

### Corrigé

#### Plus une seule commande sous vingt-huit pixels
Après les boutons d'icône, le reste : les cent soixante-quatre en-têtes de
section du menu latéral - qui ouvrent et ferment une section, donc des
commandes et non des titres -, les onglets de tri de la médiathèque, le
sélecteur de vue du calendrier, les étiquettes de l'arborescence des notes, les
interrupteurs, les pastilles de couleur du menu, et le sélecteur de langue du
site public.

Les interrupteurs et les pastilles gardent leur taille dessinée et gagnent une
zone sensible par une marge négative : la cible grandit, le dessin ne bouge pas.

Compté sur quarante-huit écrans à trois cent soixante-quinze pixels : cinq cent
quarante-six cibles sous vingt-huit pixels au début de la journée, soixante-deux
à la fin. Ce qui reste n'est plus une commande - des cases à cocher à la taille
du navigateur, des fils d'Ariane, des liens dans une phrase, et la page
d'erreur de Symfony.

La taille `compact` d'`AppIconButton` disparaît : zéro appel sur
quatre-vingt-dix-sept boutons, donc un choix que personne ne faisait et qu'il
aurait fallu maintenir.

#### Quatre-vingt-dix-sept boutons d'icône passent à trente pixels sur téléphone
Six pixels de rembourrage autour d'une icône de quatorze font une cible de
vingt-six : très bien pour un curseur, mal pour un doigt. C'est la mesure qui a
fait passer les onglets d'un espace à trente ; `AppIconButton` y échappait
encore, et avec lui quatre-vingt-dix-sept boutons dans trente-sept fichiers.

Un minimum et non une taille fixe, pour qu'une icône de seize pixels garde son
air autour d'elle. Et seulement sous `sm` : grossir toutes les barres d'outils
du back-office pour une précision que la souris a déjà serait payer un problème
que personne n'a. Mesuré : trente sur trente à trois cent soixante-quinze
pixels, vingt-six sur vingt-six à mille deux cent quatre-vingts.

Repasse complète après coup sur quarante-huit écrans, parce qu'un composant
partagé qui grossit peut faire déborder ailleurs : rien ne déborde.

#### Onze blocs de commandes n'existaient qu'au survol
Un survol n'existe pas au doigt. Ces commandes n'étaient pas seulement
invisibles sur téléphone : elles restaient cliquables, donc on appuyait à
l'aveugle sur quelque chose qu'on ne voyait pas. Les actions d'une note, la
suppression d'un message dans une discussion ou dans le fil d'une fiche, le
retrait d'une pièce jointe, les commandes d'une diapositive, les lignes de
l'arborescence des notes et des dossiers, le renvoi d'une notification, les
images d'une galerie, une couleur du thème.

Elles sont visibles sous `sm` et redeviennent des commandes au survol
au-dessus : le bureau ne bouge pas. Mesuré sur les deux largeurs - opacité 1 à
trois cent soixante-quinze pixels, opacité 0 à mille deux cent quatre-vingts.

Restent au survol ce qui n'est pas une commande : la flèche qui glisse sur une
carte d'article, le voile « changer l'image » d'un champ dont le bouton entier
est déjà cliquable, et les poignées de déplacement - déplacer à la souris est
un geste de souris.

#### La médiathèque annonçait des poids qui n'étaient pas ceux des fichiers
Le poids d'un document est relevé à l'arrivée du fichier. Pour une source
JPEG, ce nombre cesse d'être vrai une ligne plus tard : la fabrication des
variantes ré-encode la source en place à la qualité 85 et lui retire ses
métadonnées. Trouvé en important une photographie depuis Craft - mille cinq
cent trente-deux mille quatre cent soixante-sept octets annoncés, deux cent
treize mille neuf cent un sur le disque, un facteur sept.

Le poids est donc relu après coup, et demandé à l'adaptateur de stockage
plutôt qu'au disque local : la source peut vivre dans un stockage objet, où
seul un `stat` répond honnêtement.

Ce qui était déjà enregistré se rattrape avec `aurora:ged:sizes:refresh`,
idempotente, avec un `--dry-run` qui compte sans écrire. Sur la démonstration
locale : les huit images de la bibliothèque étaient fausses, deux mégaoctets et
demi d'écart cumulé. Un document dont le fichier a disparu garde son poids -
c'est un autre problème, et l'écraser à zéro le cacherait.

#### L'espace débordait sur les écrans étroits
Trois causes, trouvées en mesurant chaque élément de chaque vue plutôt qu'à
l'œil, à 250 puis à 375 pixels.

**La barre d'onglets**
Cinq libellés côte à côte font 520 pixels : sur un écran de 375, la barre
poussait **toute la page** à défiler de côté, pas seulement elle-même. Seul
l'onglet ouvert porte désormais son nom sous 640 pixels ; les autres gardent
leur icône, et leur libellé reste lisible par un lecteur d'écran, où il n'a
jamais coûté de place. La barre passe de 523 à 245 pixels et la page ne défile
plus latéralement.

**La ligne de signature d'un message** - le nom, l'heure et la pastille
« client » - faisait 130 pixels sur une seule ligne : dans une fenêtre de 250,
la pastille sortait par la droite. Elle se replie maintenant.

**La barre des notes** et **les cartes du mur** poussaient la page de 40
pixels : un enfant de flex ne descend pas sous la largeur de son contenu tant
qu'on ne l'y autorise pas, donc `max-w-full` seul n'y changeait rien. Les
onglets « Partagées / Personnelles » défilent, les cartes suivent leur colonne,
et un mot trop long se coupe au lieu d'élargir la carte.

Résultat mesuré : plus aucune page plus large que la fenêtre, à 375 comme à
250, côté back-office comme sur la page que lit le client.

**Le rail des canaux devient un tiroir** sous 768 pixels, au lieu d'une bande
au-dessus de la conversation : la bande prenait 107 pixels de haut à ce qu'on
était venu lire, et une liste de salons n'est pas quelque chose qu'on lit, c'est
quelque chose qu'on ouvre, où l'on choisit, et qui se referme. Le nom du salon
sert de poignée, le tiroir glisse depuis la gauche, un voile couvre la
conversation derrière, et choisir un salon referme le tout. La conversation
passe de 629 à 674 pixels sur un écran de 812.

### Ajouté

#### Importer une note écrite dans Craft
Craft garde le savoir durable, celui qui survit à un projet ; les notes d'un
espace gardent ce qui est attaché à un travail en cours et meurt avec lui.
Restait à faire passer un brief de l'un à l'autre sans le recopier à la main.

Un bouton « Importer depuis Craft » dans les notes d'un espace ouvre la liste
des documents, on en choisit un, il devient une note que le client lit sans
compte Craft. **C'est une copie, pas un lien vivant** : rien à synchroniser,
aucun conflit à arbitrer, et aucune note qui change toute seule sous les yeux
d'un client. La note se souvient malgré tout du document d'origine, pour le
studio seul.

Deux appels HTTP, pas un client MCP en PHP. Craft expose bien son serveur MCP
derrière un OAuth complet, mais il donne aussi, par connexion, une adresse REST
et un jeton : `GET /documents` pour la liste, `GET /blocks` avec un en-tête
`Accept: text/markdown` pour le contenu. C'est Craft qui rend le Markdown,
puisque c'est lui qui connaît ses blocs.

**La connexion ne porte que les documents choisis.** Craft propose les deux, et
c'est la sélection qui est documentée dans l'écran de réglages : une
installation vit sur un serveur loué, et un jeton qui y dort ne doit pas ouvrir
l'intégralité d'un savoir personnel pour qu'un brief atterrisse dans un espace.
L'intégration est éteinte tant que personne ne l'allume, et le jeton est chiffré
au repos comme les mots de passe des points de montage.

La conversion suit ce que l'éditeur accepte, et non ce que le Markdown permet :
ses niveaux de titre sont deux, trois et quatre, donc un `#` de Craft écrit tel
quel aurait donné un bloc que le back-office refuse d'ouvrir. Les tableaux
survivent, les cases à cocher gardent leur état, une bascule devient son titre
suivi de son contenu et un encadré devient une citation - faute d'outil
équivalent monté dans l'éditeur, et le texte reste dans les deux cas.

Les images sont recopiées dans le dossier de l'espace. Ce n'est pas du confort :
une image servie depuis un espace Craft privé ne s'afficherait pas chez le
client.

**Réglé sur la vraie API, pas sur sa documentation.** La connexion publie sa
propre spécification OpenAPI : la liste arrive sous `items` et non `documents`,
un document supprimé garde sa ligne avec un `isDeleted` qu'il faut filtrer, et
la clé voyage en `Authorization: Bearer`. Les balises que Craft ajoute au
Markdown y sont aussi documentées, et l'écran d'import les traduit plutôt que
de les laisser ressortir en `&lt;page&gt;` au milieu d'une note : le titre
d'une page imbriquée devient un titre, un surlignage devient un surlignage, un
fil de commentaire rend son mot, et les renvois `block://` et `date://`
gardent leur texte et perdent une adresse qui ne mène nulle part hors de Craft.

Un avertissement, écrit dans l'écran de réglages : une connexion Craft laissée
en mode « Publique » n'est protégée par rien d'autre que le secret de son
adresse, et sa spécification déclare dix-neuf opérations d'écriture. Mesuré sur
une vraie connexion : `GET /documents` répondait 200 sans le moindre en-tête.
Le mode « Clé API » et la lecture seule sont donc demandés dès la marche à
suivre, avant même les deux champs.

Deux défauts que seul un vrai document pouvait montrer. Craft indente le corps
d'un document dans son `<content>`, et deux espaces valent chez lui un niveau
d'imbrication : une liste à plat arrivait empilée sous sa première entrée. Le
retrait commun est retiré à l'intérieur du `<content>` seulement, donc
l'imbrication voulue survit. Et l'adresse publique d'un document se lit sous
`fileUrl`, pas sous `url` : l'image partait bien dans l'espace pendant que la
note continuait de pointer vers Craft, et le repli prévu pour un vrai échec
masquait l'erreur de clé. Un test monte maintenant le déposeur réel plutôt
qu'un double, puisqu'un double aurait rendu la clé qu'on lui aurait apprise.

**Une note importée sait d'où elle vient, et se remet à jour d'un clic.** Le
bouton n'apparaît que sur une note venue de Craft, et il remplace : un document
Craft et une note d'Aurora sont deux textes que deux personnes peuvent avoir
touchés, et décider lequel gagne ligne à ligne demanderait d'arbitrer des
conflits - ce que ce chantier a écarté dès le départ. L'écran le dit avant de
le faire.

Ce qui appartient à Aurora survit : la couleur, l'épingle et la visibilité ne
sont pas dans le document Craft et n'ont aucune raison d'être remises à zéro
parce qu'un texte a changé ailleurs. Les images d'avant restent dans la
médiathèque et celles que plus personne n'utilise sont proposées à la
corbeille, la même règle que la suppression d'une note : sans elle, rafraîchir
cinq fois laisserait cinq exemplaires de chaque image derrière.

Rien n'est compressé au passage, et c'est voulu : `ImageVariantGenerator`
traite déjà tout document déposé. Mesuré sur un import réel, une image de
1 532 467 octets servie par Craft est stockée à 213 901, avec trois variantes
WebP par-dessus.

Le titre d'un document n'est écrit qu'une fois. Craft enveloppe un document
dans une page dont la balise de titre porte son nom, et la conversion en
faisait un titre de section - juste pour une page imbriquée, doublon pour le
document lui-même, puisque la note le porte déjà. Vu sur le premier import
réel, pas sur un exemple.

Et l'écran d'import distingue trois états au lieu de deux : éteinte,
injoignable, et ouverte mais vide. Les deux derniers se ressemblaient, et ne se
réparent pas au même endroit - une connexion sans document s'arrange dans
Craft, une clé fausse dans les réglages. Dire « aucun document » quand on n'a
rien entendu envoie chercher au mauvais endroit.

#### Un tiroir de navigation sur le site public
Sept entrées faisaient mille vingt-huit pixels de liens pour une ligne qui en
offre trois cent cinquante-neuf : la barre du site se repliait sur deux lignes
et prenait cent trente-sept pixels avant que la page commence, et la première
page ajoutée par un client en aurait fait trois. Sous `md`, le menu passe
derrière un bouton et la barre retombe à soixante-treize pixels.

Plus gênant que la hauteur : une entrée qui a des enfants ouvrait son panneau
au survol, et le survol n'existe pas au doigt. Les sous-entrées d'un menu
étaient donc inatteignables sur téléphone. Dans le tiroir, elles se déplient
sur place, et l'entrée qui a elle-même une adresse garde sa page en première
ligne du repli.

Le tiroir est un `<details>` : le navigateur donne l'ouverture, le clavier et
l'état annoncé, et tout fonctionne avant qu'une ligne de JavaScript arrive -
celle-ci n'ajoute que la fermeture au clic sur le voile. La langue reste dans
la barre, visible, et le compte se range en bas du tiroir, derrière un filet.

Au passage, le flou de l'en-tête déménage sur une couche à lui : `backdrop-filter`
fait de son élément le bloc conteneur de ses descendants `fixed`, et le panneau
s'arrêtait à la hauteur de la barre au lieu de tenir l'écran. Le rendu ne bouge
pas.

#### Des canaux dans la discussion d'un espace
Un espace n'avait qu'un seul fil, et tout ce qui n'appartenait à aucune fiche y
tombait : le brief du mois, une campagne qui bouge, la logistique. Le brief
défilait sous le reste.

La discussion a maintenant des **canaux**. Le studio les ouvre, les renomme et
les supprime ; le client, lui, n'en crée pas : les canaux servent à ranger le
travail de l'agence, et un canal ouvert par le client rangerait celui de
quelqu'un d'autre. Chaque canal porte la liste des gens qui y sont, et un
réglage décide si le client le lit. **Un canal est fermé au client tant que
personne ne l'ouvre**, et le canal principal, lui, ne se ferme pas : c'est la
conversation que l'espace avait avant.

Ce que le client ne lit pas, il ne le voit pas : ni le nom, ni le nombre. Son
jeton d'abonnement nomme ses canaux un par un, et demander un canal interne par
son identifiant répond 404 comme pour un inconnu - dire « ce canal existe mais
pas pour vous » renseigne déjà celui qui a récupéré une adresse.

**La liste est à gauche**, en colonne, comme dans les applications faites pour
ça : une barre horizontale grandit jusqu'à passer à la ligne dans la
conversation, et elle met les canaux sur la même ligne que le nom du canal
ouvert, si bien que rien ne dit laquelle des deux est la liste. Sous 768
pixels elle repasse au-dessus, faute de gauche disponible.

Un espace naît avec son canal principal, et les conversations existantes y ont
été versées par la migration.

#### On peut retirer quelqu'un d'un canal
Le pendant d'« Ajouter quelqu'un », qui manquait : une équipe change, et un
canal dont la liste ne peut que grossir finit par n'en être plus un. Le geste
est sur la ligne de la personne, dans les réglages du salon, là où on la
cherche.

**Rien ne s'efface.** Ce qu'elle a écrit reste dans le canal, avec son nom : un
message est un fait daté, pas une propriété qu'on emporte en partant. Elle cesse
de voir le salon et d'y écrire, et la réinviter la remet où elle était.

Deux refus, pour les mêmes raisons qu'ailleurs : une conversation privée garde
ses deux personnes, et un membre nommé sous un autre salon que le sien répond
404 plutôt que de se laisser retirer.

#### Supprimer un canal demande confirmation
C'est le seul geste de ce panneau qui ne se rattrape pas : retirer une
conversation la garde, retirer quelqu'un garde ce qu'il a écrit, supprimer un
canal emporte ses messages. Un bouton rouge dans une liste de réglages n'est pas
une question posée ; la question l'est maintenant, avec le nom du canal en
titre.

#### Les gestes d'un salon passent par une modale
Quatre boutons vivaient sous le titre - renommer, ouvrir au client, ajouter
quelqu'un, supprimer - pour des gestes qu'on fait une fois par mois, et sur
téléphone ils passaient à la ligne. Ils sont maintenant derrière **trois
points**, dans une modale qui a la place de dire ce que chacun fait.

La même modale montre **qui est dans le salon**. « 2 personnes » était un
chiffre sans réponse à la seule question qu'il pose.

Choisir quelqu'un - pour l'ajouter à un canal ou pour lui parler en privé -
passe aussi par une modale, avec les noms lisibles au milieu de l'écran, au lieu
d'une liste qui se dépliait dans un rail de deux cents pixels.

#### Retirer une conversation sans rien effacer
Comme dans Messenger : la conversation quitte **votre** liste, l'autre continue
de la voir, rien n'est supprimé, et la rouvrir avec la même personne la rend
entière, historique compris. Le rangement est porté par la personne et non par
le salon - l'un range, l'autre pas.

#### L'historique d'une discussion arrive par tranches
Une conversation de deux ans ne se charge plus d'un bloc. La page s'ouvre sur sa
fenêtre, et remonter en fait venir cinquante de plus, avant que le pouce ait
fini son geste. **Le repère est un message, jamais un numéro de page** : dans
une conversation où quelqu'un écrit pendant qu'on remonte, un décalage compté
depuis le début ferait voir deux fois la même ligne ou en sauterait une.

La position du lecteur est rendue au pixel : on mesure la hauteur avant, on la
remesure après, et on redonne la différence au défilement. Sans ça, l'écran
saute au moment précis où l'on cherchait quelque chose.

#### Des conversations privées dans un espace
La seconde moitié de la discussion. **Deux personnes, et personne d'autre.** Le
studio en ouvre une avec quelqu'un de l'équipe de l'espace ; le client en ouvre
une avec quelqu'un du studio, au titre du même droit qui lui permet de répondre
dans la discussion - il parle à une personne plutôt qu'à l'espace. Le rail les
range dans leur propre section, sous les canaux : un canal est une pièce où
l'on entre, une conversation privée est quelqu'un à qui l'on parle.

Sous le capot c'est un salon à deux, comme chez Slack, et c'est volontairement
invisible : personne ne pense « le salon à deux avec Marie ». Une conversation
porte le nom de **l'autre**, donc pas le même des deux côtés, et il n'y en a
jamais qu'une entre deux personnes : redemander rouvre la première.

**L'avertissement sous la zone de saisie suit le salon.** « Ce que vous écrivez
ici est lu par le client » est vrai du canal principal et faux des deux autres
sortes ; un canal interne et une conversation privée le disent maintenant
chacun à sa façon. Une phrase fausse sous une zone de saisie fait taire ceux
qui la croient et délie la langue de ceux qui ne la lisent plus.

### Dans aurora-client
`make aurora-update`, puis `make migrate` : la 0.9.204 ajoute une colonne aux
notes d'un espace.

Puis **une fois**, `php bin/console aurora:ged:sizes:refresh`. Le poids
enregistré d'un document était celui du fichier reçu et non celui du fichier
rangé, et une source JPEG est ré-encodée en place au dépôt : la bibliothèque
annonçait des tailles sans rapport avec ce qui est stocké. La commande est
idempotente et accepte `--dry-run` pour compter d'abord.

Rien d'autre n'est requis. L'import depuis Craft reste éteint tant que personne
n'ouvre une connexion dans les réglages, et le tiroir de navigation du site
public arrive avec le thème.

---

## [0.9.203] - 2026-09-18

### Ajouté

#### Remplacer le fichier d'un document depuis la console
`aurora:ged:replace <id> <fichier>` échange les octets d'un document de la
médiathèque sans toucher à sa ligne. C'est ce qui manquait pour reprendre une
capture publiée : l'import crée un *nouveau* document, donc il fallait ensuite
rouvrir chaque page qui pointait sur l'ancien, retaper le texte alternatif et
la légende, puis jeter l'ancienne ligne - quatre occasions de laisser le site
sur une image qui ne montre plus ce qu'elle annonce.

Là, la ligne survit : les pages continuent de pointer dessus, les mots écrits
sur l'image restent écrits, et le fichier précédent devient une version. La
commande prend la même route que le formulaire du navigateur - l'upload écrit
sur le disque actif, le manager supprime les anciennes variantes, reconstruit
les nouvelles, enregistre la version et écrit la ligne d'audit.

Un `--dry-run` dit ce qui serait remplacé et s'arrête.

### Corrigé

#### Vider la corbeille de la médiathèque échouait sur un toast
Une corbeille qui contenait un document ayant des versions ne se vidait
jamais : le bouton répondait « une erreur est survenue », rien n'était
supprimé, et la suppression d'un document seul continuait de marcher - ce qui
rendait la panne difficile à lire.

La cause est un flush au mauvais moment. `AuditLogger::log()` écrit sa ligne et
flush ; la boucle qui vide la corbeille auditait et supprimait dans le même
passage, donc l'audit du deuxième document flushait alors que le premier était
déjà marqué pour suppression. Le premier document quittait l'unité de travail,
ses lignes de version - chargées juste avant pour lire les chemins de leurs
fichiers - y restaient en pointant sur lui, et le flush final s'arrêtait sur
« a new entity was found through the relationship `DocumentVersion#document` ».

La boucle est maintenant en deux passages : on audite et on relève les fichiers,
puis on supprime, puis on flush une fois. Un test d'intégration reproduit la
forme exacte qui échouait, deux documents dont le premier versionné, parce que
c'est la seule qui échoue.

Au passage, un test pour la corbeille des publications, qui n'en avait aucun :
elle résiste déjà, une traduction partant avec sa publication par le mapping.

## [0.9.202] - 2026-09-18

### Ajouté

#### Emporter son carnet de notes, et le rendre
Un carnet rangé dans une base est un enfermement tant qu'on ne peut pas le
reprendre. **Exporter le carnet** rend un zip de fichiers `.md` dans
l'arborescence des notes : un éditeur de texte les ouvre, Obsidian les lit, et
rien là-dedans n'est propre à Aurora. Les étiquettes voyagent en préambule,
dans la forme que les mêmes outils connaissent. Une note seule s'exporte aussi,
depuis son en-tête.

**Importer** relit des `.md` ou un zip entier, sous la note ouverte ou à la
racine, et reconstruit l'arborescence à partir des dossiers.

Deux décisions valent d'être dites. **Rien n'est écrasé** : une note du même
nom donne une seconde note, parce que fusionner demanderait de décider ce qui
gagne et que personne ne l'a demandé à ce moment-là. Et **un chemin désigne une
note, pas deux** : l'export d'une note qui a des enfants écrit un fichier pour
elle et un dossier pour eux, et l'import les recolle - sans quoi un
aller-retour rendait deux notes du même nom, l'une avec le texte, l'autre avec
les enfants.

L'aller-retour est tenu par un test, parce que c'est la seule preuve qu'une
exportation vaut quelque chose.

#### Une démonstration qui a la forme d'un site
La démo s'ouvrait sur deux types de contenu, sept publications et un menu de
trois entrées : de quoi montrer l'éditeur, pas de quoi montrer un site. Elle a
maintenant la forme de la production. **Deux types de contenu maison**,
Services et Projets, créés par la démo et non par l'installation, ce qui est le
seul moyen de voir à quoi ressemble un type qu'on a fait soi-même. **Trois
pages de service** sur un gabarit image/texte alterné, **deux réalisations**
avec une image large et des chiffres, et **trois pages institutionnelles** :
à propos, contact et mentions légales. Le menu mène aux deux archives et aux
nouvelles pages, la page à propos liste ses services toute seule, et la page de
contact pose le formulaire de devis au lieu d'un lien vers lui.

**Les titres sont vrais, les textes sont du faux latin.** Un menu qui dit
« Lorem ipsum » n'apprend rien sur un menu ; un paragraphe de démonstration qui
raconte quelque chose finit cité comme s'il le pensait. Les images de la
médiathèque sont désormais des **aplats de couleur**, sans dégradé ni motif :
il s'agit de distinguer les vignettes, pas d'imiter une photographie.

Les écrans qui s'ouvraient vides se remplissent aussi. **Des publications
liées** entre elles, **un partage de notes** avec ses sous-notes, **un lien de
partage** sur le deck d'audit, et **deux fiches commentées** sur le tableau
d'un espace client, studio et client mêlés. Deux fiches sur huit seulement :
une fiche sans discussion est l'état le plus courant du tableau, et il faut
qu'il se voie aussi.

### Corrigé

#### La fin d'une note Markdown se lisait hors de l'écran
La carte qui tient l'éditeur avait une hauteur devinée, `100vh - 8rem`, fausse
d'une trentaine de pixels : elle dépassait le bas de la fenêtre. Et la colonne
qui la remplit ne pouvait pas rétrécir, un enfant de flex refusant de descendre
sous la hauteur de son contenu, donc une note longue poussait le volet au lieu
de le faire défiler. Les deux ensemble donnaient une note dont on ne voyait pas
le bout, côté éditeur comme côté aperçu.

La hauteur se dit maintenant avec les valeurs qui la font, la barre du haut et
les marges de la zone de contenu, et chaque volet défile chez lui.

#### Le titre d'une note prend sa ligne
Il partageait une ligne avec six boutons et deux mentions d'état, et n'avait
donc que ce qui restait. Il est seul sur la sienne, pleine largeur ; le reste
descend d'un cran.

#### Le champ de recherche de l'arborescence est aussi large que l'arborescence
Il était rentré de douze pixels de chaque côté, donc plus étroit que ce qu'il
sert à filtrer.

#### La flèche du bloc du bas de la colonne pointe vers le haut
Fermé, ce bloc annonçait une ouverture vers la droite, qui n'arrive pas : il
est en bas de la colonne et son contenu monte.

#### Une seule colonne pour une personne, dans la liste des comptes
L'adresse avait sa colonne, qui disparaissait sous 1024 pixels : l'écran où
l'on cherche quelqu'un par son mail était justement celui qui ne le montrait
pas. Elle est sous le nom, avec la phrase d'accroche, dans une colonne
« Utilisateur ».

#### Plus d'info-bulle au survol dans la colonne de menu
Les lignes de navigation avaient perdu la leur le jour où l'interrupteur
« afficher les descriptions » est arrivé. Le bloc du compte et le lien vers le
site gardaient la leur, qui répétait leur propre libellé.

#### La date d'un formulaire public se saisit comme partout ailleurs
Un champ de type date rendait le `<input type="date">` du navigateur, avec son
petit calendrier système et son format à lui. Sur une machine réglée en
anglais, un formulaire français demandait une date en `mm/dd/yyyy` : ce n'est
pas une date illisible, c'est une date comprise à l'envers. C'est exactement le
raisonnement qui avait déjà sorti les `datetime-local` natifs du back-office.

Le formulaire public utilise maintenant le même sélecteur que le reste
d'Aurora : le format du site, le thème du site, et la saisie au clavier dans
les cinq formats qu'il tolère déjà.

**Chargé à la demande.** Ce composant pèse deux cents kilo-octets avec son
calendrier et ses langues ; un formulaire de contact qui ne demande aucune date
ne les télécharge pas. Ce qui est stocké ne change pas d'un caractère, le
composant rend la même chaîne `AAAA-MM-JJ` que le champ natif.

---

## [0.9.201] - 2026-09-18

### Ajouté

#### Les fichiers d'un espace, ceux qui ne sont sur aucune fiche
La vue Fichiers rassemblait ce qui avait été déposé sur les contenus. Restait
sans place ce qui n'illustre rien : la charte, les logos, le brief, un contrat
signé. Ça finissait épinglé à la fiche qui se trouvait ouverte, exactement
comme les messages avant que la discussion existe.

Deux onglets, donc : **Sur les fiches** et **De l'espace**. Le second porte
« Déposer un fichier » et « Choisir dans la médiathèque » ; sur une fiche, un
fichier s'ajoute depuis la fiche, là où l'on voit ce qu'il illustre.

**Le client les voit**, sur sa page, sous la discussion. Un espace est partagé :
ses fiches, ses fichiers et sa conversation se lisent des deux côtés. Ce que le
studio garde pour lui, ce sont les notes, qui n'ont aucune adresse publique.

**Sa propre table, pas une fiche facultative.** Une ligne de pièce jointe dit
« ce document est sur cette fiche » et casse des deux côtés ; un `null` lui
aurait fait dire deux choses, et chaque lecteur aurait dû demander laquelle.

**Le fichier, lui, reste dans la médiathèque**, en brouillon et dans le dossier
de l'espace, comme tout ce qu'un espace reçoit. C'est ce qui lui garde ses
vignettes, sa corbeille, son registre d'usages et l'absence d'adresse publique
devinable. Un second stockage aurait voulu dire deux téléversements, deux
corbeilles et deux réponses à « qui utilise ce fichier ». La médiathèque sait
donc dire qu'un espace porte une image avant qu'on ne la supprime.

Retirer un fichier de l'espace ne le supprime pas, et ce que plus rien
n'utilise vous est proposé à la corbeille - la même règle que les pièces
jointes d'une fiche et les images d'une note.

### Modifié

#### La discussion d'un espace prend l'écran
Elle tenait dans une boîte de hauteur fixe, posée au milieu d'un écran vide :
une conversation de trente messages en montrait trois, et il fallait faire
défiler dans une fenêtre grande comme une carte pendant que le reste de la page
ne servait à rien. C'est le seul onglet d'un espace qu'on lit de haut en bas
sans rien d'autre autour, donc il prend la hauteur disponible.

**Et une conversation courte se pose en bas**, au-dessus de la zone de saisie,
comme dans une messagerie - plutôt que de flotter en haut d'un grand vide. Dès
qu'elle déborde, le défilement redevient ordinaire.

C'est le conteneur qui décide, pas le panneau : sur la page du client, la
discussion reste un bloc parmi d'autres sous son calendrier, et elle garde sa
taille. Le même composant, deux places, et la place qui tranche.

#### On voit enfin ce que le stockage pèse
L'écran Stockage disait où les nouveaux fichiers vont ; il ne disait pas où ils
sont. Il porte maintenant **ce qui est stocké de chaque côté**, le poids et le
nombre, le disque du serveur et le bucket, les deux toujours affichés même à
zéro : ce qu'on vient vérifier après une bascule, c'est justement qu'il ne
reste plus rien de l'autre côté.

Et la liste des espaces clients porte une colonne **Poids** : ce que chaque
espace a fait déposer, mesuré sur son dossier dans la médiathèque. Un document
choisi dans la bibliothèque n'y compte pas, il était déjà là. Le jour où le
disque se remplit, c'est cette colonne qui dit chez quel client.

Mesuré sur la table des documents plutôt que sur le disque : ce qui compte est
ce dont l'application répond, pas les restes d'un import raté qu'une purge n'a
pas encore ramassés.

#### Servir un fichier stocké ne s'écrit plus qu'une fois
Quatre contrôleurs portaient la même quarantaine de lignes, au caractère près :
trouver l'adaptateur qui détient la clé, servir le fichier local tel quel,
diffuser le distant par morceaux, privé une heure. La médiathèque, les photos
de profil, le tableau d'un espace et la page du client.

C'est un service maintenant, et les quatre s'y raccrochent. Ce qui n'y est pas
entré, volontairement : **qui a le droit de lire**. La médiathèque autorise par
son privilège, un espace par le sien, un client par son lien, et un service qui
trancherait à leur place ferait de ces trois règles une seule.

`UploadsServeController` reste à part, lui aussi volontairement : il sert ce qui
est public, avec un cache partagé et une durée longue, et c'est le contraire
exact de ce que fait ce service.

#### Ajouter une étape n'est proposé que sur les contenus
Le bouton suivait la barre de vues et se retrouvait au-dessus des fichiers,
où il voisinait avec leurs propres actions sans rien avoir à voir avec elles.
Une étape est une colonne du kanban.

#### Ce qu'une passe de relecture a rendu
Le dépôt d'un fichier par le studio, sur une fiche comme sur un espace, ne
passait par **aucune politique de téléversement** : le seul mur était celui de
PHP, dont le refus ressort sans phrase. Les deux routes consultent maintenant
celle de l'administrateur, comme le faisaient déjà l'image d'une note et le
dépôt d'un invité.

**La garde qui sépare deux clients ne s'écrit plus qu'une fois.** Elle vivait
recopiée dans cinq contrôleurs d'espace ; c'est un trait maintenant. Et l'offre
de corbeille, recopiée trois fois, est un service - celui des trois où le
contrôle de privilège avait déjà été oublié une fois.

Les notes d'audit d'un fichier d'espace passent par des hooks surchargeables,
comme la convention d'extensibilité le demande. Une méthode publique du
gestionnaire qu'aucune route n'atteignait est partie. Et la médiathèque nomme
l'espace plutôt que le document dans la liste de ce qui utilise un fichier :
l'écran de suppression dit déjà lequel part.

### Dans aurora-client
`make aurora-update` puis `make migrate`.

---

## [0.9.200] - 2026-09-17

### Ajouté

#### Des notes sur un espace, la seule surface que le client ne voit pas
Le fil d'une fiche et la discussion sont partagés avec le client, et les écrans
le disent là où l'on tape. Il manquait l'autre moitié : le brief pris au
téléphone, l'idée pas encore présentable, ce qui a mal tourné le mois dernier.
L'onglet **Notes** arrive après Discussion.

Le client ne les voit pas, et pas seulement à l'affichage : le module n'expose
aucune adresse publique qui les servirait. C'est cette absence qui permet d'y
écrire ce qu'on n'écrirait pas ailleurs.

**Deux lectures de la même chose.** Le mur répond à « qu'est-ce qu'il y a sur
cet espace », la liste à « où est celle que je cherche ». Même ordre de part et
d'autre - épinglées devant, puis les plus récemment modifiées - et le choix
reste dans l'adresse de la page, donc un lien partagé ouvre la vue qu'on
regardait.

**Le même éditeur que les publications**, avec ses titres, ses listes, ses
tableaux et ses images. Un second éditeur aurait été un second jeu d'habitudes
pour la même geste.

#### Une note est partagée avec l'équipe, ou personnelle
Deux onglets sur le mur, et ils ne parlent pas du client : aucune note ne lui
est montrée, dans un onglet comme dans l'autre. Ce qui se sépare là, c'est
l'équipe et la personne. Une note partagée est la mémoire de l'espace ; une
note personnelle est celle de son auteur, et elle existe parce qu'on écrit des
choses avant d'être prêt à les dire.

**« Personnelle » n'est pas un affichage.** Elle ne sort pas du serveur pour
quelqu'un d'autre que son auteur : le filtre est dans la requête qui lit le
mur, et les routes qui reçoivent une note par son identifiant reposent la même
question. Trier dans la page aurait fait d'une confidence une préférence
d'affichage.

Partagée par défaut, parce qu'une note prise sur l'espace d'un client parle en
général du travail, et qu'un mur que personne d'autre ne lit cesse d'être la
mémoire de l'espace. L'onglet ouvert décide de ce que sera la prochaine note :
on écrit là où on regarde.

La médiathèque continue de compter les notes personnelles des autres quand elle
dit ce qui utilise une image - sinon supprimer l'image viderait la note de
quelqu'un sans que rien ne s'y oppose - mais elle ne les nomme plus : « la note
personnelle de quelqu'un » suffit à refuser.

#### Les images d'une note sont rangées avec l'espace, pas dans le tas d'édition
Une image collée dans une note va dans le dossier de son espace, à côté des
fichiers échangés sur ses fiches, et elle est enregistrée **en brouillon**.
Trois conséquences, toutes voulues : aucune adresse publique devinable ; une
place dans la médiathèque sous la catégorie des espaces clients ; et une absence
du sélecteur d'images d'une publication, qui ne liste que les documents publiés.
La capture d'un chantier n'est pas du mobilier de site.

**La médiathèque sait quelles notes utilisent une image**, comme elle le sait
déjà pour les publications et les pages. Sans ça, supprimer une image viderait
une note en silence, et une note vidée a toujours l'air entière - ce qui est
pire que cassée. Supprimer une note, à l'inverse, ne supprime aucune image :
celles que plus rien n'utilise sont proposées à la corbeille, avec le même
écran que partout ailleurs.

Les images des **publications** sont enregistrées au passage : l'éditeur ne
renvoyait qu'une URL, donc rien ne pouvait répondre « qui utilise cette
image ». Il renvoie maintenant aussi l'identifiant du document.

### Modifié

#### Le tableau et la liste sont une seule entrée, avec deux formes
Le sélecteur d'un espace offrait Tableau et Liste côte à côte, à côté de
Calendrier, Fichiers, Discussion et Notes. Les quatre derniers sont quatre
questions différentes ; les deux premiers étaient deux orthographes du même
endroit. Ils deviennent **Contenus**, avec deux boutons de forme en haut à
droite - le kanban et la liste - comme la vue Fichiers choisit déjà entre ses
cartes et ses lignes.

La forme voyage dans l'adresse de la page, donc un lien l'emmène avec lui ; ce
qui reste retenu d'une visite à l'autre, c'est l'onglet, c'est-à-dire le sujet
qu'on était en train de lire. Et un écran étroit affiche la liste quoi qu'en
dise le lien, sans effacer le choix : un kanban sur un téléphone demande de
défiler de côté pour voir sa deuxième colonne.

#### Un fichier s'ouvre sur place
Dans la vue Fichiers, **Ouvrir** posait le fichier dans un nouvel onglet.
Parcourir ce qu'un espace a échangé est un balayage - laquelle était-ce, quand
est-elle arrivée - et un onglet par fichier en fait une pile d'onglets à
refermer. Le fichier s'affiche maintenant dans un panneau par-dessus la liste :
une image s'affiche, un PDF se feuillette, le reste annonce ce qu'il est.

L'adresse réelle est offerte en bas du panneau, pour le télécharger ou le garder
ouvert à côté, et le nom de la fiche y reste cliquable.

#### Les cartes de notes se tiennent
La signature d'une note - qui l'a prise, quand - était collée sous son texte,
donc à une hauteur différente sur chaque carte d'une même ligne. Elle est
maintenant en bas de la carte, où elle s'aligne avec ses voisines.

La couleur, elle, ne cerne plus les quatre côtés : un filet sur la tranche et
un voile qui se dissout vers le bas. Elle sert à repérer une note d'un coup
d'œil, pas à encadrer son texte.

#### L'espace de démonstration montre tout ce qu'un espace sait faire
Le jeu de données d'exemple ouvrait un espace à moitié rempli, ce qui est le
plus mauvais moment pour découvrir un produit. Il porte désormais son tableau
complet, son calendrier, ses fichiers, sa discussion, ses notes et son lien
d'accès, dont une note personnelle qui montre à quoi sert le second onglet - et
un **espace prospect** à côté, avec ce qu'on a vraiment à ce stade : un nom,
trois cartes, quelques notes, et rien d'autre.

### Dans aurora-client
`make aurora-update` puis `make migrate`.

---

## [0.9.199] - 2026-09-17

### Ajouté

#### Un espace pour un prospect, converti en un clic quand il signe
On travaille avec une société bien avant d'avoir son SIRET, et ouvrir un espace
pour elle demandait d'aller d'abord lui inventer une identité légale sur une
fiche client. Le formulaire d'un espace accepte désormais **« + Nouveau
prospect »** : un nom, et l'espace s'ouvre avec une vraie fiche derrière lui.

**Un nom suffit.** L'adresse, la forme juridique, le siège, le représentant, le
SIRET : tout attend. Les liens d'accès d'un espace portent chacun leur propre
destinataire, donc rien sur cet écran ne dépend de l'adresse de la fiche.

**Un prospect est une fiche client avec une colonne de plus**, pas un second
type d'enregistrement. Tout ce qui pend en dessous — l'espace, son tableau, ses
fichiers, plus tard un contrat — pointe sur le client : deux tables auraient
voulu dire tout déménager le jour de la signature, c'est-à-dire le pire jour
pour déplacer des lignes.

#### Convertir depuis l'espace, pas seulement depuis la fiche
« Convertir en client » est dans le menu Actions de l'**espace** autant que de
la fiche. C'est dans la liste des espaces qu'on voit le chantier avancer, donc
c'est là qu'on apprend que la société a dit oui ; aller la chercher ailleurs
pour changer une colonne est le détour que ce bouton supprime.

La fenêtre ne demande **qu'une chose, l'adresse contractuelle**, parce que
c'est le seul champ que le statut impose : c'est là que part le contrat.
Réclamer le SIRET et le siège au même moment reviendrait à redemander la fiche
entière pour changer une colonne, alors qu'on convertit quand la personne dit
oui, pas quand on a fini de la classer. Une fiche qui porte déjà une adresse se
convertit sans rien saisir.

Rien n'est interdit à un prospect, contrat compris : une règle qui le refuserait
n'apprendrait qu'à basculer le statut d'abord, et ne voudrait alors plus rien
dire. Le seul mur est à l'envoi — on n'envoie pas un contrat à personne.

#### Deux onglets sur les deux listes
Espaces et clients se séparent en **Clients** et **Prospects**. « Ce sur quoi je
travaille » et « ce que j'essaie de décrocher » ne se lisent pas dans la même
minute, et un statut à deux valeurs sur lequel on veut filtrer est un filtre
plutôt qu'une colonne. Le compte porté par chaque onglet est ce qui rend l'autre
visible.

#### Ouvrir un espace depuis son menu
La liste des espaces offre **Voir** dans le menu Actions, en plus du nom
cliquable. Un menu qui liste tout ce qu'on peut faire à une ligne sauf ce qu'on
lui fait tous les jours répond à côté, et pour un lecteur qui consulte sans
modifier il ne contenait rien.

### Corrigé

#### Les avatars d'équipe ne se traversent plus
Posé sur son voisin, un avatar laissait passer le rond du dessous et deux paires
d'initiales se chevauchaient dans la place d'une. Chaque rond est maintenant sur
un disque opaque de la couleur de la ligne.

### Dans aurora-client
`make aurora-update` puis `make migrate`. Les clients existants sont migrés en
« client » : ils ont été saisis pour être contractualisés, les appeler prospects
serait dire quelque chose de faux sur le fichier.

---

## [0.9.198] - 2026-09-17

### Ajouté

#### Ouvrir un espace depuis le menu Actions
Le nom de l'espace a toujours été le chemin pour y entrer, et il le reste. Mais
un menu appelé Actions qui liste tout ce qu'on peut faire à une ligne, sauf
justement ce qu'on lui fait tous les jours, répond à côté de la question. Et
pour quelqu'un qui peut consulter les espaces sans les modifier, il ne
contenait rien du tout.

**Voir** arrive en premier, avant Modifier et Supprimer. C'est un lien et pas un
gestionnaire, donc l'espace s'ouvre aussi dans un autre onglet - ce qu'on fait
quand on en compare deux.

### Corrigé

#### Les avatars d'équipe ne se traversent plus
Un avatar est peint dans un accent translucide, ce qui est juste tout seul et
faux en pile : posé sur son voisin, il laissait passer le rond du dessous et
deux paires d'initiales se chevauchaient dans la place d'une.

Chaque rond est désormais posé sur un disque opaque de la couleur de la ligne :
il rend exactement ce qu'il rend seul, et celui du dessus masque la part qu'il
recouvre. Le visuel est inchangé. La transparence du variant `soft` de
`AppAvatar` n'est pas touchée, elle sert dans tout le back-office ailleurs
qu'en pile.

---

## [0.9.197] - 2026-09-17

### Modifié

#### La discussion se lit comme une conversation
Tout était collé à gauche, ce qui donne une liste de messages plutôt qu'un
échange. Vos messages sont maintenant à droite et ceux d'en face à gauche -
et sur la page du client, c'est l'inverse : chacun se voit à droite. C'est le
même fil, regardé de deux endroits, et le composant reçoit désormais de quel
côté on le regarde plutôt que de le deviner.

La mention « client » ne s'affiche plus que là où elle apprend quelque chose :
sur sa propre page, le client n'a pas besoin qu'on lui dise qu'il est le
client.

#### Le panneau s'ouvre sur le dernier message, partout
Il s'ouvrait en haut de la conversation sur la page du client, avec le message
le plus récent sous le pli. Le défilement était déclenché après le montage, à
un instant où la boîte n'a pas encore sa hauteur : faire défiler ce qui n'est
pas encore défilable ne fait rien. Attendre une image réglait le cas du
back-office, attendre les polices un troisième cas, et chacune de ces attentes
est une supposition sur le moment où la mise en page se stabilise.

Le contenu est observé à la place. La boîte qui prend sa hauteur, une police
qui recompose le texte, un message qui arrive : un seul événement, une seule
réponse - si le lecteur était à la fin, l'y garder. Remonter dans l'historique
suspend le suivi, redescendre le reprend.

---

## [0.9.196] - 2026-09-17

### Modifié

#### Le hub local se monte avec le serveur de dev
`make start` monte le hub Mercure en même temps que le reste, et `make
hub-start`, `make hub-stop`, `make hub-logs` le pilotent seul - on le redémarre
plus souvent qu'on ne redémarre tout. Ses valeurs vivent dans un `.env.dev`
suivi en git : la discussion arrive en direct sans que personne ait rien à
recopier, et un `.env.local` garde la main dessus.

**Sans Docker, rien ne casse et la commande le dit** plutôt que de laisser
quelqu'un chercher pourquoi le voyant est orange : les messages sont
enregistrés et postés normalement, la page les redemande toutes les vingt
secondes.

Le service déclare aussi les quatre origines locales - `localhost` et
`127.0.0.1`, en http et en https - parce que le serveur de développement se
sert sous les quatre et qu'une seule d'entre elles fait échouer la connexion
sous les trois autres, ce qui ressemble à un hub en panne.

#### Les variables du hub atteignent les projets consommateurs
`sync-client-env` ne recopie que les blocs marqués `aurora/<nom>`, donc le bloc
nommé d'après le paquet Flex était une documentation que personne en aval
n'aurait jamais vue - alors que c'est le seul endroit qui dit à quoi servent
les quatre variables, dont trois doivent s'accorder avec la configuration du
hub.

### Dans aurora-client
`make aurora-update`. Le bloc `aurora/mercure` apparaît dans le `.env` au
prochain `make sync-env` ; le renseigner n'est nécessaire que pour le temps
réel.

---

## [0.9.195] - 2026-09-17

### Ajouté

#### Une discussion sur l'espace, en direct
Chaque fiche portait déjà ses échanges, et c'est là que doit rester ce qui
concerne un contenu précis : une objection écrite à côté du texte qu'elle vise
se retrouve un mois plus tard. Ce qui n'avait nulle part où aller, c'est tout
le reste - le brief du mois, une campagne qui se décale, « qui m'envoie le
logo » - et ça atterrissait sur la fiche qui se trouvait ouverte, où ça
devenait introuvable.

Une cinquième vue dans l'espace, et un panneau sous le calendrier sur la page
du client. **Un seul fil, lu des deux côtés**, comme les échanges de fiche : ce
que le studio écrit, le client le lit, et la phrase sous la zone de saisie le
dit. Le droit d'écrire est celui de commenter porté par le lien d'accès, pas
une quatrième case : un client qui peut vous répondre sur une publication peut
vous répondre tout court.

**En direct avec un hub Mercure, juste avec un peu de retard sans.** Les
messages sont enregistrés et postés en HTTP ordinaire ; le hub ne fait que les
faire arriver sans rafraîchir. Sans `MERCURE_URL`, rien n'est publié, les pages
ne reçoivent aucune adresse à laquelle se connecter, et le panneau interroge le
serveur toutes les vingt secondes. Un test le verrouille, parce que la plupart
des projets qui consomment cette librairie n'auront jamais de hub.

Le protocole 1.0, qui n'est pas le défaut du composant Symfony et n'était pas
un choix : un hub 1.0 refuse les jetons de l'ancien protocole, mesuré et pas
supposé.

#### On vous prévient quand le client fait quelque chose
Un client pouvait valider, demander une reprise, envoyer un fichier et
désormais écrire un message, et personne n'était prévenu : on l'apprenait en
ouvrant l'espace. Tout était construit autour de la boucle de validation, et la
boucle ne se fermait que si quelqu'un pensait à aller voir.

Les quatre écritures du client notifient **les membres de l'espace**. Un espace
sans membre ne prévient personne : s'ajouter à un espace, c'est s'y abonner.

**Une notification, pas une par message.** Trois messages d'affilée font une
seule ligne tant qu'elle n'est pas lue. Un avis fait exception et n'est jamais
replié : répondre deux fois, c'est changer d'avis.

**Et un email quand personne n'est devant l'écran**, sur la forme qu'emploient
les grosses messageries : attendre cinq minutes, regarder à nouveau, et n'écrire
qu'à quelqu'un qui n'est pas là. Si la notification a été lue entre-temps, rien
ne part. Sinon un seul mail, qui liste ce qui s'est passé, puis le silence
jusqu'à ce que la personne revienne et lise.

### Dans aurora-client
`make aurora-update` puis `make migrate`.

**Enregistrer le bundle Mercure** dans `config/bundles.php` :
`Symfony\Bundle\MercureBundle\MercureBundle::class => ['all' => true]`. Il
arrive en dépendance transitive et Flex ne l'inscrit pas tout seul chez le
consommateur.

**Le chat fonctionne sans rien d'autre.** Pour le temps réel, installer un hub
Mercure et renseigner `MERCURE_URL`, `MERCURE_PUBLIC_URL`, `MERCURE_JWT_SECRET`
et `MERCURE_ISSUER`. Le hub doit épingler son `resource_identifier` sur
`MERCURE_PUBLIC_URL` et déclarer l'application comme émetteur de confiance.

---

## [0.9.194] - 2026-09-16

### Ajouté

#### Un espace client range ses fichiers dans son propre dossier
Jusqu'ici tout ce qu'un espace recevait atterrissait dans une seule catégorie
partagée, `espaces-clients`, et `folder_id` n'était jamais renseigné. Dès le
deuxième client, la bibliothèque tenait un tas indifférencié : rien sur un
document ne disait de quel espace il venait, et le seul fil restant était de
cliquer dessus pour lire son panneau d'usages, ce qui est une consultation, pas
un rangement.

Un espace ouvre désormais son dossier **au premier envoi**, pas à sa création :
un espace qui ne reçoit jamais de fichier ne laisse pas de dossier vide
derrière lui, et un dossier vide par prospect est du déchet dans un écran qu'on
lit. Au premier niveau plutôt que sous un parent commun, parce qu'un dossier de
regroupement devrait être retrouvé par son nom à chaque envoi et que les noms
de dossiers ne portent aucun index unique.

**Rattacher un document existant ne le déplace pas.** Classer est ce que fait
un envoi en entrant ; déplacer le fichier de quelqu'un parce qu'une carte l'a
référencé serait un effet de bord que personne n'a demandé, et le même document
peut pendre à deux espaces. Pour la même raison le nom du dossier ne suit pas
celui de l'espace : dès qu'il existe, c'est un dossier ordinaire que le studio
peut renommer, déplacer ou imbriquer.

Rien n'est repris rétroactivement. Les fichiers déjà classés gardent leur
dossier, c'est-à-dire aucun.

#### Un onglet Fichiers sur l'espace, en liste ou en cartes
Le tableau, la liste et le calendrier lisent les mêmes cartes et montrent les
fichiers de chacune sur la carte. Aucun ne répondait « qu'est-ce que ce client
nous a envoyé », question posée quand un fichier est arrivé la semaine dernière
et que personne ne se souvient pour quel post.

Une quatrième entrée du sélecteur liste tout l'espace, du plus récent au plus
ancien, en nommant la carte de chaque ligne. Chronologique et non groupée par
étape : grouper aurait donné une seconde vue liste et enterré une photo arrivée
ce matin sous une étape pleine d'idées que personne n'a écrites.

Deux formes, parce que les deux questions diffèrent : les lignes répondent
« quand est-ce arrivé et de qui », les cartes répondent « c'était quelle
photo ». Le choix vit dans l'URL, donc un lien vers cette vue le transporte, et
un conteneur trop étroit rend des cartes sans effacer le choix.

#### Retirer la dernière référence à un fichier propose de le mettre à la corbeille
Détacher un fichier, supprimer une carte ou supprimer un espace laissent chacun
un brouillon que plus rien ne référence. Il n'est pas perdu, mais rien ne le
remonte non plus, le sélecteur de la bibliothèque ne listant que les documents
publiés.

Le geste se fait, **puis** la proposition arrive à côté en toast ; ne rien
faire suffit à la refuser. Pas de boîte de dialogue devant un geste à un clic.

Trois conditions décident, et le serveur les tranche toutes : le fichier doit
être dans le dossier de l'espace, ce qui est fidèle puisque seuls les envois y
atterrissent ; il ne doit être utilisé nulle part, ce que le registre d'usages
répond pour tous les modules à la fois ; et l'appelant doit détenir
`ged.documents.delete`, qu'une personne gérant les espaces n'a pas forcément.
Rien n'est détruit : c'est la corbeille, donc le pire d'un clic trop rapide est
une restauration.

**Ce n'est pas une règle qui range toute seule**, et c'était la première idée.
Elle se serait déclenchée sur la suppression d'une carte et pas sur le
détachement, qui est le geste le plus courant : l'état aurait paru géré sans
l'être.

### Modifié

#### `useDelete` passe la réponse à son callback
`onSuccess` reçoit désormais `(id, data)`. Quatorze pages l'utilisent et
toutes ignorent un second argument, mais sans lui une suppression n'a aucun
moyen de dire ce qu'elle a laissé derrière.

#### Les données de démonstration de la GED sont neutres
Deux sources avaient un sujet : un chat généré en IA servait de
`hero-banner.jpg` et un drapeau canadien de `landscape.jpg`, et une facture
commerciale à sociétés fictives portait le copyright d'un tiers. Une démo se
montre à des prospects, et une image qui a un sujet raconte autre chose que le
produit. Remplacées par des dégradés du même langage visuel que les
placeholders déjà présents.

La vidéo de démonstration part avec, faute d'encodeur pour la remplacer et
parce qu'une vidéo a forcément un sujet. Sa ligne survit sans fichier, ce qui
est le cas que le fixture documente déjà comme intéressant, et ses 18 Mo
quittent un dépôt public.

### Documentation

Une page neuve, « Les fichiers d'un espace », sur les trois façons d'en
déposer, ce que le client peut envoyer, la vue Fichiers et le dossier de la
médiathèque. Et « Le tableau d'un espace » cesse d'annoncer trois vues : il y
en a quatre, et la quatrième n'est pas une façon de plus de lire les mêmes
fiches.

### Dans aurora-client
`make aurora-update` puis `make migrate`.

---

## [0.9.193] - 2026-09-16

### Sécurité

#### Les 71 avis de sécurité ouverts sont traités
Neuf côté composer, tous en runtime et tous corrigeables : `symfony/security-http`
et `twig/twig` en gravité haute, `symfony/http-foundation`, `symfony/routing` et
`symfony/polyfill-intl-idn` derrière. `composer update` suffisait, les contraintes
les admettaient déjà.

Soixante-deux côté npm, concentrés sur cinq paquets : `axios`, `form-data`,
`nanoid`, `postcss` et `pdfjs-dist`. `pnpm update` en a réglé cinquante-neuf.

Restaient trois cas qui demandaient chacun autre chose qu'une montée de version.

**`pdfjs-dist` est retiré, pas mis à jour.** L'avis est sérieux, exécution de
JavaScript arbitraire à l'ouverture d'un PDF piégé, et le correctif demandait un
passage de la majeure 5 à la 6. Sauf que le paquet n'est **importé nulle part** :
ni dans `src`, ni dans la configuration Vite, ni dans aucun bundle construit. Il
était déclaré et pesait un avis de gravité haute sans rien rendre.

**`ws` est forcé par un override**, à `>=8.21.0`. Il arrive sous
`vitest > happy-dom`, donc de l'outillage de test, et rien dans l'arbre n'en
proposait un plus récent de lui-même.

**L'override vit dans `pnpm-workspace.yaml`**, pas dans `package.json` : pnpm 11
ne lit plus la clé `pnpm` de `package.json` et l'ignore avec un avertissement.
Un réglage ignoré est pire qu'un réglage absent.

### Corrigé

#### `bump-after-update` resserrait les contraintes d'une bibliothèque
La configuration portait `"bump-after-update": true`, donc chaque `composer update`
réécrivait `composer.json` en remontant les minimums : `doctrine/orm` de `^3.6.6`
à `^3.7.1`, `symfony/ux-vue` de `^3.0` à `^3.4`, et huit autres. Composer le
déconseille lui-même pour une bibliothèque, dans le message qu'il affiche en le
faisant, parce que ça restreint sans raison ce qu'un projet consommateur peut
installer.

Le réglage part. Les contraintes larges reviennent ; seul `composer.lock` bouge,
ce qui est exactement ce qu'on veut : il ne sert qu'à la CI d'aurora-core, un
consommateur résolvant les siennes.

### Dans aurora-client
`make aurora-update`. Aucune migration.

---

## [0.9.192] - 2026-09-16

### Corrigé

#### La bibliothèque annonçait « aucun usage » sur des documents utilisés
Trois modules pointent vers un document GED, un seul répondait au registre
`DocumentUsageProviderInterface`. L'écran de suppression, qui est le seul
avertissement existant, déclarait donc le fichier libre.

Ce que le silence coûtait dépendait du module. Une pièce jointe d'espace client
tient son document en `onDelete: CASCADE` : supprimer le fichier n'effaçait pas
une vignette, ça emportait la ligne, et le fichier quittait l'espace du client
sans rien laisser. Un billet pointe vers un document trois fois, sa couverture
et l'image sociale de chaque langue en `SET NULL`, plus les identifiants dans
`galleryLayout` : couvertures vidées, emplacements de galerie dessinant du vide.

Deux fournisseurs ajoutés, et un test unitaire qui fait échouer la porte si un
module référence un `Document` sans répondre. Vérifié en retirant les trois
fournisseurs : il nomme les deux modules fautifs.

#### La taille max d'upload affichée n'était pas celle qui s'appliquait
`max_upload_size_mb` était enregistré, étiqueté, décrit et modifiable depuis que
l'écran des paramètres existe, et aucune ligne de code ne le lisait. Les
plafonds étaient écrits en dur dans `UploadPolicy` : 100 Mo pour la
bibliothèque, 25 Mo pour un invité d'espace. Descendre le réglage à 5 pour
protéger un petit disque donnait un écran qui acceptait la modification et un
serveur qui continuait à prendre des fichiers de 100 Mo.

`UploadPolicyProvider` le lit et construit la politique ; les deux contrôleurs
qui appelaient les fabriques statiques la reçoivent par injection.

Les invités sont plafonnés dans les deux sens, mais pas symétriquement. Baisser
le réglage les baisse avec tout le monde, c'est ce que veut dire un plafond.
Le monter ne les fait pas dépasser 25 Mo : celui qui tient un lien d'espace
tient une adresse secrète et non un compte, et la part de disque qu'il peut
remplir n'est pas une préférence d'administrateur. Un champ vidé donnerait un
plafond de zéro et refuserait tout, partout, sans rien dire : le plancher est
à 1 Mo.

**Le défaut passe de 20 à 100**, ce que faisait la bibliothèque avant que le
réglage soit branché, donc une installation neuve se comporte comme avant.
Une installation existante, non : sa valeur enregistrée s'applique enfin.

### Supprimé

#### Le réglage « Extensions autorisées », que rien ne lisait
`allowed_upload_extensions` était déclaré, étiqueté, doté d'un défaut et d'un
espace réservé, affiché dans le groupe media, modifié et enregistré, et lu par
aucune ligne de code. Retirer `svg` de la liste ne changeait rien, ce qui est
pire que ne pas avoir de réglage : le `file_versions_limit` voisin fonctionne,
donc le groupe inspire confiance.

Retiré plutôt que branché, contrairement à son voisin, parce que la
bibliothèque refuse d'avoir une liste de types autorisés à dessein :
`UploadPolicy` l'argumente, une liste se contourne en renommant les fichiers et
le mur est à la lecture, où `BinaryFileServer` envoie `nosniff` et force le
téléchargement des types qu'un navigateur exécuterait. Le brancher aurait
imposé une restriction que personne n'a décidée, à partir d'une liste
enregistrée avant que la décision existe.

**La liste des invités reste dans le code**, et c'est le fond de l'affaire :
une version modifiable serait un moyen de remettre `image/svg+xml` depuis un
formulaire.

### Dans aurora-client
`make aurora-update` puis `make migrate`.

**Vérifier la valeur de « Taille max d'upload »** dans les paramètres : elle
s'applique désormais pour de bon. Une installation qui porte encore l'ancien
défaut de 20 verra la bibliothèque passer de 100 Mo à 20 Mo.

---

## [0.9.191] - 2026-09-16

### Corrigé

#### L'écran d'audit affichait une clé de traduction
`AuditTab.vue` rendait le nom du module sans repli. Un module renommé laisse
derrière lui des lignes qui portent son ancien nom, et celles-là s'affichaient
littéralement `backend.modules.accounting`. **Mesuré : vingt-sept lignes**,
toutes des actions Studio écrites avant que le module ne soit renommé.

Le repli existait déjà quelques fichiers plus loin, dans l'écran des
utilisateurs : `t(clé, nomBrut)`. C'est maintenant le cas ici et sur l'écran des
permissions, donc un module renommé affiche son nom plutôt qu'une clé, quel que
soit le renommage à venir.

### Supprimé

#### Les deux bascules d'un module qui n'existe plus
`ModuleParameterEnum` gardait `MediaBackend` et `MediaLibrary` après le retrait
du module Media en juillet 2026, et le fournisseur yield **tous** les cas — donc
chaque installation depuis créait `modules_media_backend` et
`modules_media_library` dans `core_settings`, et les gardait.

L'une des deux était pire que morte : `MediaLibrary` pointait son libellé vers
`backend.nav.media`, une clé inexistante — la vraie est
`backend.nav.sections.media`. Ce qui l'affichait montrait une clé brute.

Partent avec : sept branches de `match`, deux libellés de bascule, quatre
assertions de test, et les deux lignes de `core_settings` par migration.

**Les libellés `backend.modules.media`, `hr` et `project` restent.** Ils ne
servent plus à aucune bascule, mais ils nomment encore d'éventuelles lignes
d'audit historiques. Les supprimer aurait échangé « Médias » contre « media »
sans rien gagner.

### Dans aurora-client
`make aurora-update` puis `make migrate`.

---

## [0.9.190] - 2026-09-16

### Corrigé

#### Un lecteur ne pouvait pas ouvrir une fiche
Le menu d'une carte offrait « Modifier » et « Supprimer », tous deux derrière
`studio.spaces.edit`. Pour quelqu'un qui peut voir un espace sans le modifier,
ce menu était donc **vide** — et la carte du tableau n'était pas cliquable. Il
voyait un titre et une date, et n'atteignait jamais le texte, le fil d'échanges
ni les fichiers, sur un écran qui existe pour être lu par les gens qu'un
chantier client concerne.

**« Voir » apparaît exactement quand « Modifier » ne peut pas.** Pas les deux :
deux entrées qui ouvrent le même panneau, dont l'une désactive ses champs, est
un menu qui fait deviner au lecteur laquelle il veut.

#### Le tableau se comportait autrement que la liste
La liste et le calendrier ouvraient une fiche au clic, le tableau non : même
contenu, deux comportements. La carte du tableau est cliquable, et le clic
ignore les boutons et la poignée de glisser — un glisser qui démarre sur le
corps de la carte se disputait sinon avec le clic, et l'un des deux perdait au
hasard.

#### La modale proposait d'enregistrer ce que le serveur aurait refusé
En lecture seule, les champs sont désactivés mais **restent lisibles** : ce que
dit une fiche est justement ce que ce lecteur vient chercher, et cacher le
texte pour signaler qu'il n'est pas modifiable répondrait à la mauvaise
question. Ce qui disparaît, c'est tout ce qui écrit — le bouton Enregistrer, la
zone de dépôt de fichier, la suppression d'un message. Proposer un bouton que
le serveur refuse est la façon dont un écran apprend à ne plus être cru.

Le titre dit « Voir » plutôt que « Modifier », et la sortie dit « Fermer »
plutôt que « Annuler ».

### Dans aurora-client
`make aurora-update`. Rien d'autre.

---

## [0.9.189] - 2026-09-16

### Sécurité

#### La troisième couche : une Content-Security-Policy
Il n'y en avait aucune, nulle part. Chaque page HTML en porte une désormais.

Elle complète les deux autres plutôt qu'elle ne les répète : une liste blanche
décide de ce qui peut être **stocké**, `BinaryFileServer` de ce qui peut être
**rendu comme un document**, et celle-ci de ce qu'une page peut **exécuter**.
Les deux premières ne disent rien d'un script injecté dans le corps d'un
article ou d'un paramètre réfléchi.

**Les scripts par nonce, les styles par `unsafe-inline`**, et l'asymétrie n'est
pas de la paresse. Une poignée de scripts doivent tourner avant le premier
rendu — le thème, pour que la page ne clignote pas en blanc, et les globales
que lit chaque écran d'équipe — et ils portent le nonce. Les styles ne le
peuvent pas : Vue injecte ceux d'un composant depuis JavaScript, et une balise
injectée ne porte aucun nonce. Pire, un nonce présent dans `style-src` fait
ignorer `unsafe-inline` aux navigateurs, donc en ajouter un — ce qui ressemble
à un durcissement — casserait tous les composants stylés. L'exposition n'est
pas la même non plus : un style injecté rhabille une page, un script injecté
agit à la place de qui la lit.

**`frame-src` lit la liste d'hôtes du sanitiseur** au lieu d'en tenir une
seconde. Elle décide déjà quels `<iframe>` survivent à l'enregistrement ; deux
listes auraient divergé, et la divergence se serait vue le jour où une
intégration s'enregistre puis refuse de s'afficher.

`script-src` ne contient ni `unsafe-inline` ni `unsafe-eval`, et un test le
vérifie dans les deux environnements. C'est la façon dont une CSP se vide en
silence : quelqu'un bute sur un script bloqué, ajoute le mot-clé, et l'en-tête
reste en place sans plus rien dire.

### Modifié

#### Le champ montant n'évalue plus par `new Function`
Un seul `new Function` dans toute la base de code, dans l'évaluateur du champ
montant — celui qui accepte `12+3`. Il était sûr au sens étroit : la chaîne
était déjà réduite aux chiffres et aux opérateurs, il n'y avait rien à
injecter. Et fatal au sens large : **un seul suffit à imposer
`script-src 'unsafe-eval'` sur toutes les pages**, ce qui rendrait la politique
ci-dessus largement décorative.

Remplacé par une descente récursive sur trois niveaux, qui fait les quatre
opérations et les parenthèses et rien d'autre. Ce qu'elle ne sait pas lire
revient intact, ce dont le champ a besoin : quelqu'un en train de taper ne doit
pas voir sa saisie réécrite.

### Dans aurora-client
`make aurora-update` puis `make cc`.

**Un thème ou un gabarit surchargé qui contient un `<script>` inline devra
porter `nonce="{{ csp_nonce() }}"`**, sinon il cessera de s'exécuter. C'est le
seul point de rupture. Les styles inline ne changent pas.

Si un projet client charge des scripts depuis une autre origine, il faut lui
donner sa propre politique : l'en-tête déjà posé n'est jamais écrasé, donc un
souscripteur client qui s'exécute avant celui-ci a le dernier mot.

---

## [0.9.188] - 2026-09-16

### Sécurité

Un audit des sept portes d'entrée de fichiers a remonté cinq défauts. Tous
corrigés ici, et chacun avec le test qui l'aurait attrapé.

#### Deux zones privées étaient lisibles par n'importe qui
`UploadAccessDecider` laisse anonyme un préfixe que personne ne revendique.
C'est délibéré et documenté : refuser l'inconnu casserait un client qui range
ses fichiers sous un préfixe qu'aurora-core ne connaît pas. Le prix, c'est
qu'une zone privée que personne ne revendique est publique en silence.

Deux l'étaient. **Mesuré : un fichier sous `profile-photos/` comme sous
`notes-markdown/` répondait 200 sans aucune session.** Pour les notes c'est
l'inverse exact de ce que leur service revendique dans son propre docblock, où
il explique ranger ses images hors de la racine publique justement pour forcer
la lecture par un contrôleur qui vérifie à qui elles appartiennent : la route
fourre-tout `/uploads/{path}` passait à côté de tout ça.

Les deux zones ont maintenant leur garde. Les avatars se lisent par
`/backend/platform/profile-photos/…`, sous le pare-feu, et un avatar ne peut
donc plus être rendu sur une page publique. Rien n'en affiche aujourd'hui.

#### Les noms de fichiers étaient devinables
Toute cette sécurité repose sur « l'adresse est indevinable ». Elle l'était
mal : les noms se composaient de `slug(nom d'origine) + uniqid()`, dont la
première moitié est souvent devinable (`logo.png`, `cv.pdf`) et la seconde
n'est pas aléatoire mais **dérivée de l'horloge à la microseconde**. Une photo
de profil était pire : l'identifiant du compte suivi du même horodatage.

`StoredFileName` produit désormais 16 octets du CSPRNG et rien d'autre. Le nom
lisible n'est pas perdu, il est rangé : `originalName` le garde en base, où un
téléchargement s'en sert. Sur le disque, il ne faisait que renseigner.

#### Révoquer un accès client ne révoquait pas ses fichiers
Un fichier déposé sur une fiche d'espace était un document GED **publié**, donc
servi par la route fourre-tout à qui connaissait l'adresse. Le lien expirait,
les adresses des visuels restaient valides indéfiniment.

Ces fichiers sont classés en **brouillon**, ce qui ferme le fourre-tout, et
chaque surface lit les siens par une route adossée à ce qui lui donne accès au
tableau : le studio par `/workspace/{id}/attachments/…` sous son propre
privilège, le client par `/spaces/{selector}/{token}/attachments/…` derrière la
même résolution de lien que la page. Révoquer atteint les fichiers au moment où
ça atteint la page.

Le coût est réel : le sélecteur de la GED ne liste que les documents publiés,
donc un fichier arrivé par un espace n'est plus proposé comme bannière
ailleurs. Ça se lit comme la bonne réponse plutôt qu'un compromis — la photo
d'un client n'est pas du mobilier de site — et le studio peut le publier
délibérément. Il reste listé dans la GED, classé sous « Espaces clients ».

#### La GED n'imposait aucune limite à ses propres dépôts
Ni type ni taille sur les deux endpoints : le seul mur était le privilège. La
politique écrite pour les invités est généralisée en `UploadPolicy`, avec deux
profils. Celui d'équipe accepte **tous** les types et c'est voulu : une
bibliothèque documentaire qui refuse des formats est une bibliothèque qu'on
contourne en renommant, et ce qui rendait ces formats dangereux est fermé au
moment où le fichier est servi. Ce qu'il fait, c'est plafonner le disque, ce
que personne ne faisait.

#### Le nettoyage des orphelins ne voyait qu'une zone sur quatre
`aurora:ged:prune-orphans` balayait `ged/`. Une photo de profil remplacée, le
PDF d'un contrat supprimé : rien ne les comptait. C'est maintenant
`aurora:storage:prune-orphans` (l'ancien nom reste un alias), et chaque zone
répond d'elle-même par un `ReferencedKeysProviderInterface`.

**Une zone sans fournisseur est sautée, pas balayée**, et le sens compte : un
balayage incapable de nommer ce qu'un module référence supprimerait les
fichiers de ce module. `--verbose` dit laquelle et pourquoi. Les images de
notes sont l'exemple : elles sont référencées depuis le corps des notes, qui
est chiffré, donc les nommer voudrait dire déchiffrer toute la base. Le module
nettoie derrière ses propres éditions à la place.

### Dans aurora-client
`make aurora-update`, puis `make cc`.

**Les fichiers déjà en place ne bougent pas** et gardent leur ancien nom : la
correction porte sur ce qui est écrit à partir de maintenant. Deux
conséquences immédiates en revanche, à vérifier après mise à jour :

- **les avatars changent d'adresse.** Toute surface publique qui en afficherait
  un cesserait de fonctionner. Aucune ne le fait dans aurora-core ;
- **les anciens fichiers d'espace client restent publiés**, donc encore
  lisibles par leur adresse. Pour les fermer, il faut les repasser en brouillon
  dans la GED. Les nouveaux dépôts sont corrects sans rien faire.

---

## [0.9.187] - 2026-09-16

### Ajouté

#### La vignette sur la carte, sans coûter une ligne
Le tableau et la liste montrent maintenant le premier visuel d'une fiche : un
carré à gauche du titre, avec un « +2 » quand il y en a d'autres.

Pour un calendrier éditorial c'est le manque le plus visible qu'il restait.
Quelqu'un qui planifie un mois de publications les reconnaît à leur image bien
avant de lire un titre, et un tableau qui la cachait obligeait à ouvrir chaque
fiche pour savoir ce qu'il y avait dedans.

**Un carré et pas une bande de vignettes**, parce que la carte a une règle
écrite dans son propre docblock : chaque ligne en plus coûte au lecteur une
carte de tableau visible. Le carré tient à côté des deux lignes de texte et n'en
ajoute aucune ; une bande en aurait montré plus et coûté deux cartes. Une fiche
qui ne porte qu'un PDF ne dessine rien du tout et garde toute sa largeur pour son
titre : il n'y a rien à reconnaître.

Les deux vues lisent la même source, donc elles ne peuvent pas être en désaccord
sur ce qu'il y a sur un contenu.

---

## [0.9.186] - 2026-09-16

### Ajouté

#### Un client peut envoyer ses propres fichiers
Troisième droit sur un lien d'espace, à côté de « peut valider » et « peut
commenter » : **peut envoyer des fichiers**. Coché, le client dépose ses photos
et documents directement sur les contenus, depuis sa page, sans compte.

**Éteint par défaut, contrairement aux deux autres, et c'est le point.**
Répondre et commenter sont ce à quoi sert un lien, donc ils arrivent allumés.
Envoyer écrit des octets dans notre stockage depuis une adresse sans compte
derrière : l'accorder à tous les liens déjà émis, le jour du déploiement, n'est
pas un choix que quelqu'un a fait.

**Trois murs, et ils ne sont pas interchangeables.** Un limiteur de débit
`space_guest_upload` à part, plus bas que celui des avis parce qu'un fichier ne
coûte pas ce que coûte un clic. Puis le droit du lien, qui répond comme à un
inconnu : dire « vous pouvez lire mais pas envoyer » renseignerait celui qui
détient une adresse fuitée. Puis `SpaceGuestUploadPolicy`, le seul à regarder le
fichier : liste blanche de types inertes, vérifiée sur le type **sniffé dans les
octets** et jamais sur celui annoncé par le navigateur.

Le SVG est refusé nommément. Ce n'est pas une image, c'est un document qui peut
porter du script.

### Sécurité

#### Un fichier déposé ne s'exécute plus sur notre domaine
Vaut pour **tous** les fichiers de la GED, pas seulement les nouveaux dépôts.
`BinaryFileServer` envoie désormais `X-Content-Type-Options: nosniff` sur chaque
réponse, et force le téléchargement pour les types qu'un navigateur exécute
comme un document : SVG, HTML, XHTML, XML, XSL.

Le garde-fou est étroit exprès : une image reste affichée en ligne, sinon toutes
les images de toutes les pages publiques se seraient mises à se télécharger. Et
un `Content-Disposition` sur une sous-ressource est ignoré par les navigateurs,
donc une balise image pointant vers un SVG s'affiche toujours. Ce qui change,
c'est la navigation directe vers l'adresse, qui est le vecteur.

Cela méritait déjà d'exister quand seule l'équipe pouvait déposer. Ça cesse
d'être optionnel dès qu'un porteur de lien le peut.

### Dans aurora-client
`make aurora-update` puis `make migrate`.

**Déclarer `space_guest_upload` dans `config/packages/rate_limiter.yaml`** : le
contrôleur public le câble par nom d'argument, donc sans cette entrée le
conteneur ne se construit plus.

**Vérifier `upload_max_filesize` et `post_max_size`.** Un PHP par défaut plafonne
à 2 Mo et 8 Mo, soit moins qu'une photo de téléphone : le plafond applicatif de
25 Mo ne veut rien dire tant que PHP refuse avant. Le message reste juste dans
les deux cas, mais le client ne pourra rien envoyer.

---

## [0.9.185] - 2026-09-16

### Ajouté

#### Des fichiers sur une fiche de contenu
Une fiche d'espace client porte ses visuels. Glisser-déposer, choisir dans la
GED, retirer. Les vignettes s'affichent dans le formulaire de la fiche, juste
au-dessus du fil d'échanges.

**Sur la fiche et pas sur l'espace**, parce que c'est le tour de validation qui
en a besoin : un client à qui on demande d'approuver un texte sans voir l'image
répond à la moitié de la question. Une étagère par espace pour la charte et les
logos reste possible, et sera un autre lot.

**Le fichier vit dans la GED, la ligne ne fait que le désigner.** Pas d'adresse,
pas de vignette, pas de taille recopiées : tout est résolu au moment du rendu,
parce que l'adresse d'un fichier change quand on remplace le fichier et qu'une
copie deviendrait fausse en silence. Retirer un fichier d'une fiche ne supprime
donc rien : le document reste dans la GED, classé sous une catégorie
« Espaces clients » créée au premier dépôt.

**Une table de liaison et pas une colonne**, parce que la moitié visuelle d'une
publication est rarement un seul fichier. Un carrousel, une vidéo et sa
couverture, un communiqué et sa photo : avec un seul `document_id` il aurait
fallu en faire plusieurs publications.

**L'auteur est stocké deux fois**, comme pour les messages. Les relations
disent qui tant qu'elles durent et sont en `SET NULL` ; le libellé et le côté
sont écrits une fois au dépôt. « Qui a envoyé cette photo » se demande longtemps
après, et doit survivre à un compte supprimé ou un lien révoqué.

Un aperçu seulement pour ce qui s'aperçoit : une image a sa vignette, un PDF ou
une vidéo ont une icône tirée du type MIME. Pointer une balise image vers un PDF
est la façon la plus sûre de faire passer un envoi réussi pour un échec.

### Modifié

#### La GED sait classer pour n'importe quel module
« Trouver ou créer la catégorie », avec sa reprise sur collision quand deux
premiers dépôts arrivent ensemble, vivait dans le fournisseur des médias
éditoriaux. Un deuxième module en avait besoin. C'est maintenant
`DocumentCategoryResolver`, que les deux partagent : la logique délicate est à
un seul endroit, donc elle se corrige une fois.

### Dans aurora-client
`make aurora-update` puis `make migrate`.

---

## [0.9.184] - 2026-09-16

### Supprimé

#### Le schéma des treize modules partis
La migration initiale créait les tables de Crm, Ecommerce, Billing, Photo,
Project, Erp, Hr, Vault, Assistant, PersonalFinance, Tools, PdfForm et des
anciennes notes, et ne les supprimait que dans son `down()`. Les modules sont
sortis du dépôt entre juillet et août ; aucune migration depuis n'y avait
touché. **Toute base construite depuis cette suite portait encore 65 tables, 58
séquences et deux colonnes sur `core_users`** que plus aucune entité ne mappait.

`Version20260916120000` les supprime. `core_users.agency_id` et `service_id`
partent d'abord avec leurs contraintes : ce sont les deux seuls liens entre le
schéma vivant et le mort, et c'est le geste que `Version20260823140000` avait
déjà fait pour `core_plannings.agency_id`. `manager_id` reste, il est mappé.

**La migration refuse de tourner si une de ces tables contient une ligne.** Ici
elles étaient toutes vides, et c'est la seule base que quiconque ait comptée.
Un client qui s'est servi d'un module avant son extraction a des lignes dedans,
et un `DROP TABLE` les emporterait sans retour. Un déploiement qui s'arrête en
nommant les tables est un problème qu'on résout ; un déploiement qui réussit et
efface les factures d'un client, non.

Le cas le plus probable est `core_markdown_notes` : les notes Markdown ont été
refaites en août dans `core_notes_markdown_notes`, **créée vide**, sans reprise
des anciennes. Si des notes d'avant août existent encore quelque part, elles
sont dans l'ancienne table.

Pas de `down()`. Recréer 65 tables vides dont le code est parti ne rendrait pas
une seule ligne au seul cas où ça compterait.

### Modifié

#### `doctrine:migrations:diff` redevient lisible
Il proposait de supprimer une centaine de tables à chaque appel, ce qui obligeait
à écrire toutes les migrations à la main depuis la première extraction. Il ne
reste qu'un bruit de fond d'une vingtaine d'instructions, d'une autre nature :
des index nommés à la main dans les migrations que le mapping ne déclare pas, et
deux index partiels que Doctrine ne sait pas exprimer. Le diff reste donc à
relire, mais il est devenu un outil au lieu d'un mur.

### Dans aurora-client
`make aurora-update` puis `make migrate`. **Avant de migrer la production**,
compter les lignes des tables concernées : si la migration s'arrête, elle
nomme celles qui ne sont pas vides, et rien n'est supprimé.

---

## [0.9.183] - 2026-09-15

### Ajouté

#### Trois vues du même contenu, au choix du lecteur
L'onglet Contenu porte un sélecteur : **Tableau**, **Liste**, **Calendrier**.
Ce ne sont pas trois fonctionnalités, ce sont trois lectures des mêmes fiches -
le tableau dit où en est chaque chose, la liste dit ce qu'il y a, le calendrier
dit quand ça sort.

La **liste** est nouvelle. Elle groupe par étape, met une fiche par ligne avec sa
date et l'avis du client, et c'est la seule des trois qui tient sur un téléphone
sans défiler de côté. Elle existe parce qu'un tableau n'est pas la façon de
penser de tout le monde.

**Le choix est retenu, par personne et pour tous les espaces.** Quelqu'un qui ne
pense pas en colonnes choisit la liste une fois et ne revoit plus le tableau. Une
mémoire par espace l'aurait obligé à rechoisir à chaque nouveau client, ce qui
est précisément ce que cette fonctionnalité sert à éviter.

### Modifié

#### Deux onglets au lieu de trois
Le calendrier était un onglet à côté du tableau, la liste aurait été un sélecteur
à l'intérieur : deux mécanismes pour choisir comment lire le même contenu, et un
lecteur obligé de savoir lequel cache quoi. Il reste **Contenu** et **Accès
client**, et les trois vues sont un sélecteur.

Ce qui est à l'écran - quel espace - reste dans l'adresse. Comment il est dessiné
appartient à la personne qui le dessine, donc ne s'y trouve pas. Une seule route
sert le contenu, `/workspace/{id}`, et changer de vue ne coûte aucune requête :
les trois reçoivent la même charge utile, envoyée une fois.

#### Le tableau, la liste et le calendrier ne possèdent plus rien
Les deux composables d'écran fusionnent en un seul, `useSpaceContent`. Les trois
vues sont présentationnelles : elles reçoivent tout en propriétés et rendent tout
en événements. C'est ce qui fait qu'une fiche modifiée dans l'une est juste dans
les deux autres, sans que personne n'ait à les tenir en accord.

### Ajouté

#### Quatre pages de documentation pour les espaces clients
« Un espace client », « Le tableau d'un espace », « Le calendrier d'un espace » et
« L'accès d'un client à son espace », avec dix-sept captures produites par
`tools/doc-screenshots`. Elles ouvrent la rubrique Studio, avant la fiche client.

Le parcours de l'accès client suit vraiment le lien émis, dans un contexte sans
session : une capture de ce que voit le client prise en étant connecté ne
prouverait rien.

### Dans aurora-client
`make aurora-update`. Rien d'autre : la route du calendrier disparaît, mais elle
n'est référencée que par le module.

---

## [0.9.182] - 2026-09-15

### Ajouté

#### Un fil d'échanges sur chaque contenu, partagé avec le client
Ce que le studio écrit, le client le lit, et inversement. **Un seul fil et pas
deux boîtes aux lettres** : un échange dont les moitiés ne se voient pas, ce sont
deux personnes qui se parlent à côté avec des étapes en plus. Rien n'est interne
ici, et l'écran le dit là où on tape, parce qu'une note destinée à un collègue
écrite dans le mauvais champ est une note que le client lit.

Le composant est le même des deux côtés, avec les mêmes messages dans le même
ordre. Une conversation qui se rendrait différemment selon qui la regarde, c'est
ainsi que deux personnes finissent par se disputer sur ce qui a été dit.

#### Deux droits distincts sur un lien
« Peut commenter » et « peut valider », cochés par défaut tous les deux. Ils
répondent à deux questions différentes : une agence partenaire peut mériter d'être
entendue sans être celle qui décide, et un plan montré à un prospect ne veut ni
l'un ni l'autre. Chacun est appliqué, et un lien qui n'a ni l'un ni l'autre est
le lien en lecture seule.

#### Une seule zone de saisie sur l'écran du client
Le fil remplace le champ « Commentaire » qui accompagnait les deux boutons. Il
finissait de toute façon en message, donc c'était une seconde porte vers la même
chose, posée à côté de celle que le lecteur venait d'utiliser. Il reste les deux
verdicts et une phrase qui dit où écrire.

L'endpoint de réponse perd du même coup son paramètre `note` : aucun écran ne
l'envoyait plus, et un paramètre que personne ne poste est un interrupteur qui ne
fait rien.

### Corrigé

#### Le commentaire du client disparaissait au moment où on s'en servait
La 0.9.181 rangeait ses mots dans `approval_note`, sur le verdict. Or modifier le
texte efface le verdict - ce qui est juste - et emportait donc la phrase avec lui.
Le client écrivait « le ton est trop formel », le studio corrigeait le ton, et
**l'instruction disparaissait exactement pendant qu'on l'appliquait.**

Un verdict est un état et se réinitialise ; des mots sont des événements et se
gardent. Les messages sont donc des lignes à part, et effacer une validation ne
détruit plus rien. La migration recopie chaque note existante en premier message
du fil, signée du lien qui avait répondu et datée de sa réponse.

### Interne

#### L'auteur d'un message est stocké deux fois, exprès
Les relations disent qui tant qu'elles durent - un compte se supprime, une
adresse se révoque puis se supprime - et les deux sont en `SET NULL`, parce que
retirer une personne ne doit pas retirer ce qu'elle a dit. Mais un message dont
l'auteur est devenu nul est un message que personne n'a écrit, ce qui est pire
qu'inutile dans un fil qu'on relit pour trancher un désaccord. `author_label` et
`from_client` sont donc écrits une fois, à la publication, et ne dépendent
d'aucune ligne qui puisse partir.

#### Un message du client ne se supprime pas
Le studio peut retirer les siens. Ce qu'un client a écrit est ce qu'on lui a
demandé d'appliquer, et un prestataire capable d'effacer une réclamation a une
trace de la mission qui ne prouve rien.

#### Le composant et ses mots ont quitté `backend`
`SpaceContentThread.vue` vit dans `assets/shared/` du sous-domaine, parce que les
deux surfaces le montent, et ses propres libellés sont passés en `shared.thread.*`
dans Core, **espagnol compris** : une clé sous `backend.` rendue sur une page que
lit un client est un namespace qui a cessé de vouloir dire quelque chose.

### Dans aurora-client
`make aurora-update` puis la migration.

---

## [0.9.181] - 2026-09-15

### Ajouté

#### Le client répond : « Validé » ou « À revoir »
Depuis sa page, en ouvrant une publication. C'est la boucle que tout le reste
préparait : le prestataire envoie un lien, le client parcourt son mois et répond,
et le studio voit la réponse sur la carte.

Le pourquoi s'écrit dans le fil d'échanges, juste au-dessus des deux boutons (voir
0.9.182). La réponse n'a pas de champ à elle : deux zones de saisie sur un même
écran, c'est le lecteur qui devine laquelle sera lue.

**La réponse ne déplace jamais la carte.** C'est un avis, pas une machine à
états : un client qui clique par erreur aurait sinon programmé ou déprogrammé une
publication. Le tableau montre la réponse, un humain déplace.

**Et « en attente » n'est pas « refusé ».** Le silence veut dire que personne n'a
rien dit, ce qui n'est pas la même chose ; une carte sans réponse ne porte donc
aucun badge, parce qu'un badge sur chacune serait une colonne de bruit qui
cacherait les deux qui comptent.

#### Modifier le texte efface la réponse
Une validation porte sur une formulation. La réécrire rend la validation preuve
de rien, et la garder dirait au tableau qu'un client a approuvé quelque chose
qu'il n'a jamais lu. Déplacer la carte, la reprogrammer ou changer son étape ne
touche à rien : seuls le titre et le texte comptent, parce que c'est ce qui a été
montré.

Le formulaire le dit sous la réponse, parce que personne ne le devinerait.

#### Un lien peut être en lecture seule
La case « Peut valider » est cochée par défaut, parce que c'est à ça qu'un lien
sert. Décochée, elle est pour le second lecteur - un collègue du client, une
agence partenaire - qui regarde le plan sans décider. Sa page ne reçoit alors
**aucune adresse d'écriture** : ce n'est pas un bouton caché, c'est un point
d'entrée qui n'arrive pas.

Un lien sans le droit qui tente de répondre reçoit le 404 d'un inconnu. Répondre
« vous pouvez lire mais pas valider » dirait à qui tient une adresse fuitée
exactement ce qu'il a.

### Interne

#### Les deux prérequis à une écriture invitée, dans le même lot
La colonne `can_approve` arrive **avec** l'écriture qu'elle gouverne, pas avant :
une colonne qui représente une permission que personne n'applique est un
interrupteur qui ne fait rien. Et la route est limitée en débit, parce qu'un
jeton qui fuite n'a personne à bloquer. Ce sont les deux choses que les liens de
partage du calendrier sont documentés comme n'ayant volontairement pas.

La limite est calée sur l'IP, donc mur extérieur : les murs qui tiennent sont le
droit porté par le lien et le fait qu'un lien révoqué ou expiré ne résout rien.
Quarante réponses par heure, plus haut que les limiteurs de signature, parce que
le geste est différent - on signe un contrat une fois, on valide six publications
d'affilée.

### Dans aurora-client
`make aurora-update`, la migration, puis **ajouter le limiteur
`space_guest_write`** à `config/packages/rate_limiter.yaml`. Le contrôleur public
le câble par nom : sans l'entrée, le conteneur ne se construit pas. L'entrée est
déjà écrite dans le dépôt client, avec son commentaire.

```yaml
space_guest_write:
    policy: sliding_window
    limit: 40
    interval: '1 hour'
```

---

## [0.9.180] - 2026-09-15

### Ajouté

#### Une étape de tableau peut porter une couleur
Facultative, choisie dans la palette partagée, et **nulle est une vraie
réponse** : un tableau où chaque étape est colorée est un tableau où la couleur
ne veut plus rien dire. L'intérêt est que deux ou trois ressortent.

Les cinq étapes d'un nouvel espace arrivent colorées, sauf « Idées ». Une étape
qui contient tout ce qui n'est pas commencé est le fond du tableau plutôt qu'un
état à signaler ; les quatre suivantes sont jaune pour ce qui attend quelqu'un,
aqua pour ce qui est calé, vert pour ce qui est sorti.

**Le gain est au calendrier.** Une carte y porte désormais la couleur de son
étape, pas celle de l'espace : à l'intérieur d'un espace, toutes les cartes sont
du même client, donc la couleur de l'espace n'y dit rien, alors qu'on parcourt un
mois pour voir ce qui attend encore une validation. L'agenda de l'équipe garde
la couleur de l'espace, parce que la question y est l'inverse - de quel client
relève cette date. Deux surfaces, deux questions, les mêmes lignes.

La page que lit le client suit la même règle.

### Interne

#### Données de démonstration
Le tableau rempli reçoit une **sixième étape, « Relecture juridique »**, ajoutée
après coup et colorée en rouge. Les cinq premières montrent les valeurs par
défaut ; celle-ci montre la décision derrière elles - les étapes appartiennent à
l'espace, donc un client dont les publications passent par un avocat en a une que
personne d'autre n'a. Une démo qui n'aurait jamais montré que les cinq
enseignerait qu'elles appartiennent au produit, ce qui est l'inverse de ce à
quoi la table sert.

#### Le plafond de la palette était écrit à quatre endroits
`AbstractPlanning`, `AbstractCustomerSpace`, un composable Vue et bientôt les
étapes portaient chacun leur `8`. C'est une propriété des huit jetons
`--chart-cat-*` du thème, pas d'un module qui les lit. Un
`Aurora\Core\Support\ChartPalette` le porte maintenant, avec son `clamp()`,
et `@/shared/composables/chart/paletteSlots.js` côté navigateur.

Les deux constantes d'entité restent, pointant sur la nouvelle : un projet
client qui référençait `AbstractPlanning::MAX_COLOUR_SLOT` continue de marcher.

#### Un sélecteur de couleur partagé
`AppColourSlotPicker` remplace les pastilles recopiées dans le calendrier et
dans les espaces, et ajoute ce qu'aucune des deux copies ne savait faire :
proposer « aucune couleur » comme choix.

### Dans aurora-client
`make aurora-update` puis la migration. Les étapes déjà créées gardent l'absence
de couleur, ce qui est le défaut honnête : personne n'en a choisi une pour elles.

---

## [0.9.179] - 2026-09-15

### Ajouté

#### Un client peut voir son plan de contenu, sans compte
Un troisième onglet, **Accès client**, émet une adresse secrète qui ouvre
l'espace en lecture seule. Elle est personnelle, elle expire (90 jours par
défaut, un an au maximum) et elle se révoque. Le client y voit le mois, ses
publications aux jours prévus, et le texte de chacune en cliquant.

**C'est la même grille que celle de l'agence**, pas une seconde implémentation :
le composant est partagé depuis la 0.9.178, donc ce que lit un client est ce que
regarde son prestataire, et les deux ne peuvent pas diverger sur le mardi où un
contenu tombe.

**L'adresse n'est affichée qu'une fois.** Seule son empreinte SHA-256 est
conservée, donc personne ne peut la retrouver, pas même celui qui l'a créée.
L'écran le dit au lieu de proposer un bouton de copie qui n'aurait rien à
copier. C'est le schéma des contrats, repris tel quel : un sélecteur identifie
la ligne, un secret prouve le porteur, et une base volée donne le premier sans
le second.

Toutes les raisons d'un refus rendent la même page : adresse inconnue, secret
faux, lien révoqué, lien expiré. Les distinguer dirait à un inconnu laquelle de
ses tentatives a porté.

**En lecture seule, et c'est une décision de périmètre plutôt qu'une étape.** Les
droits de commenter et de valider arrivent avec les colonnes qui les portent et
la limite de débit qu'une écriture invitée réclame - les deux choses que les
liens de partage du calendrier sont documentés comme n'ayant volontairement pas.
Livrer la moitié qui lit d'abord donne au client ce qu'il réclame chaque semaine
sans que personne ne bâcle la moitié qui a un attaquant en face.

La page ne transporte aucune adresse d'écriture. Un écran sans écriture n'a pas
à porter les URL de six points d'entrée, et il n'y a donc rien qu'une erreur de
gabarit puisse appeler.

### Corrigé

#### La coquille de l'espace n'armait pas `usePrivileges`
Le layout du back-office pose `window.__isAdmin__`, `__isDev__` et
`__privileges__` ; la coquille autonome introduite à la 0.9.177 ne les posait
pas. `can()` répondait donc « non » à tout, et **un administrateur voyait un
tableau sans aucun de ses boutons**, en silence et sans erreur nulle part.

Les six lignes deviennent un `@Shared/components/backend_globals.html.twig`
inclus par les deux gabarits. C'est la deuxième surface qui a prouvé qu'elles ne
devaient pas être recopiées.

#### La date de publication utilisait un champ natif
Le formulaire d'un contenu posait un `<input type="datetime-local">` au lieu
d'`AppDatePicker`. Le contrôle natif prend son format du système et non de
l'application : sur une machine en anglais, un back-office en français affichait
une date à l'américaine. Le composant du dépôt lit la locale de l'application,
accepte plusieurs formats tapés, et rend exactement l'horaire mural que le champ
transporte.

#### `/workspace` n'avait pas son second mur
Le contrôleur porte son `#[IsGranted]`, donc un anonyme était bien refusé. Mais
`access_control` n'avait aucune règle pour ce préfixe, et le fourre-tout `^/`
l'aurait rendu public le jour où une action y arrive sans attribut. `/backend` a
cette ceinture depuis toujours ; `/workspace` en est sorti sans elle à la
0.9.177. Un test le vérifie maintenant pour les trois préfixes réservés à
l'équipe.

### Dans aurora-client
`make aurora-update`, la migration, puis **`make sync-security`** : une règle
`access_control` s'ajoute pour `/workspace`.

---

## [0.9.178] - 2026-09-15

### Ajouté

#### L'espace client a son calendrier, et c'est le même contenu
L'onglet Calendrier montre les mêmes cartes que le tableau, rangées par leur
date. Glisser une carte d'un jour à l'autre la reprogramme ; cliquer un jour
vide ouvre un contenu déjà daté ; cliquer une carte l'ouvre.

**Aucune des deux vues ne possède quoi que ce soit.** C'est le pari annoncé à la
0.9.177 et il tient : le tableau lit l'étape, le calendrier lit la date, et il
n'y a qu'une ligne en base. Il n'y a donc rien à tenir en accord, et rien à
saisir deux fois.

La colonne de droite est la moitié qu'un calendrier cache d'habitude : **les
contenus sans date**. C'est le travail qui attend d'être programmé, et un mois
qui ne montrerait que la moitié programmée dirait qu'une semaine est vide alors
qu'il y a six idées à y placer.

#### Les dates d'un espace apparaissent dans le calendrier de l'équipe
Sans double saisie et sans que les deux modules se connaissent : l'espace
annonce ses dates à travers l'événement que Editorial utilise déjà, et si le
module Calendrier est absent ou désactivé, personne n'écoute.

**Un seul calendrier pour tous les espaces, pas un par espace.** Un par espace
aurait créé un calendrier partagé et sans propriétaire par client, et
`findVisibleTo` rend tout ce qui est partagé à tout le monde : quinze clients
auraient mis quinze lignes dans la barre latérale de chaque membre de l'équipe.
Ce qui les distingue, c'est la couleur, et la provenance nomme l'espace plutôt
que le module - un lecteur qui regarde une semaine chargée a besoin de savoir de
quel client une date relève, pas de lire huit fois « Espaces clients ».

### Modifié

#### `EntityScheduledEvent` transporte une couleur
Facultative, et nulle pour un producteur qui n'a rien à dire là-dessus : l'entrée
porte alors la couleur de son calendrier, comme avant. Elle existe pour le
producteur qui annonce au nom de plusieurs choses à la fois, ce qu'un espace
client est par construction. Sans elle, cinq clients arrivaient de la même
couleur, c'est-à-dire que le calendrier disait « c'est la même chose » de cinq
travaux différents.

#### La grille du mois passe dans `@shared`
`CalendarMonth.vue` et l'arithmétique qui la nourrit - `monthGrid.js`,
`timeGrid.js`, `useMonthDrag.js`, `usePointerDrag.js` - quittent le module
Calendrier pour `src/Core/assets/shared/`. Le composant était déjà purement
présentationnel et `monthGrid.js` n'avait aucun import : il n'y avait rien à
découpler, seulement un dossier à changer.

C'était la condition pour que l'espace client dessine un mois sans réécrire un
moteur ni importer depuis un autre module - il n'existe aujourd'hui **aucun**
import Vue croisé entre modules, et Studio doit continuer à se construire sans
le module Calendrier. Leurs tests suivent, et le module Calendrier consomme
désormais la version partagée.

### Interne

#### Le formulaire d'un contenu est partagé par les deux vues
Extrait au moment où le calendrier en a eu besoin, plutôt que copié : les deux
vues modifient les mêmes lignes, et deux copies d'un formulaire divergent à la
première modification faite d'un seul côté.

#### Une heure ne fait jamais l'aller-retour
Une carte glissée dans le mois envoie son propre horaire décalé du même nombre
de jours, pas l'instant que la grille a calculé. Passer par l'instant voudrait
dire convertir vers le fuseau de l'espace et en revenir à chaque glissement, et
l'heure promise à un client n'est pas une valeur à convertir deux fois. Le
décalage est calculé en UTC, sinon un changement d'heure transforme « +1 jour »
en « +23 heures » et repose la carte sur le jour d'où elle vient.

### Dans aurora-client
`make aurora-update`. Un projet client qui importerait `CalendarMonth.vue` ou
`monthGrid.js` depuis `@planning` doit pointer sur `@/shared/components/calendar/`
et `@/shared/composables/calendar/`.

---

## [0.9.177] - 2026-09-15

### Ajouté

#### Un espace client a un tableau, et c'est là que son contenu vit
Chaque espace arrive avec cinq étapes - Idées, En rédaction, À valider,
Programmé, Publié - créées avec lui et renommables. **Les étapes appartiennent à
l'espace, pas au produit** : un client dont les publications passent par une
relecture juridique a une étape que personne d'autre n'a, et un client qui
publie le jour où c'est écrit en a moins. Un jeu figé aurait été une table de
moins et faux dès le deuxième client.

Un contenu porte un titre, son texte, son étape et **une date facultative**.
C'est cette nullité qui compte : une idée existe avant que quiconque sache
quand elle sort, elle vit sur le tableau, et elle rejoindra le calendrier le
jour où on la programme.

**Le pari que tout le reste suivra** : le tableau et le calendrier à venir ne
sont pas deux fonctionnalités, ce sont deux lectures des mêmes lignes. L'étape
dit où en est un contenu, la date dit quand il sort, et aucune des deux vues ne
possède quoi que ce soit. Un calendrier d'« événements » à côté d'un tableau de
« tâches », ce sont deux endroits où saisir la même publication, et ils se
désaccordent à la première semaine chargée.

L'heure tapée est lue **dans le fuseau de l'espace**, pas dans celui du lecteur.
« Mardi 9h » est une promesse faite à un client, et quelqu'un en déplacement à
l'étranger ne doit pas la décaler en ouvrant la page.

#### L'espace s'ouvre dans sa propre coquille, ni back-office ni site public
Un espace n'est pas un écran qu'on traverse, c'est un endroit où l'on reste une
heure. Il a donc son gabarit : un bandeau fin avec le retour, la couleur de
l'espace, son nom et son client, une barre d'onglets. Pas de menu latéral, pas
de fil d'Ariane d'administration.

Le menu à lui seul coûtait 260 pixels sur des colonnes qui en font 288, soit une
colonne entière donnée à une navigation dont personne ne se sert en train de
glisser une carte. C'est le choix que le gabarit public des contrats avait déjà
fait un écran plus tôt, pour la même raison.

**L'adresse sort de `/backend` avec lui**, parce qu'une coquille autonome à une
adresse d'administration reste une page d'administration pour qui lit la barre
du navigateur. Un espace s'ouvre donc sur `/workspace/{id}`.

Ce qu'il ne devait pas perdre en quittant ce préfixe, c'est l'identité : le
pare-feu d'administration est un motif de chemin, et en dehors aucune session
backend n'est restaurée - un membre de l'équipe serait arrivé en visiteur
anonyme sur le tableau de son propre client, sans erreur ni message, juste une
redirection vers le formulaire de connexion du site. Le motif devient donc
`^/(backend|dev|workspace)`. Les deux questions sont séparées et se répondent
séparément : ce qui est dessiné, et qui est reconnu.

Un test échoue désormais si un chemin réservé à l'équipe sort de ce motif, parce
que c'est le genre d'erreur qu'une suite verte ne remarque pas : le client de
test s'authentifie lui-même.

Ce que le client verra plus tard, c'est cette même coquille en lecture seule,
atteinte par un lien signé sur une route publique. Deux adresses, deux façons de
prouver qui on est, un seul écran : c'est pour ça qu'elle est construite
maintenant plutôt qu'à l'arrivée du portail.

### Modifié

#### L'équipe d'un espace se lit en visages, plus en texte
La colonne affichait trois noms et un « +2 », ce qui répond à « est-ce que
quelqu'un est dessus » et à rien d'autre : la quatrième personne était invisible,
les rôles n'étaient nulle part, et le libellé passait à la ligne dans la cellule.

La cellule porte maintenant une pile d'avatars, trois au plus puis un `+n`, qui
ouvre la liste complète avec les rôles et les adresses. Une initiale se
reconnaît là où « 2 personnes » doit se lire, et c'est ce qu'on fait en
descendant une colonne. Le libellé accessible est au passage passé au vrai
pluriel plutôt qu'à « 1 personne(s) ».

Le nom d'un espace est désormais un lien vers son tableau.

### Interne

#### Ce qu'une clé étrangère ne peut pas dire à la place du Manager
Le lien d'une carte vers son étape est en `CASCADE`, là où `RESTRICT` semble le
choix prudent. Supprimer un espace cascade vers ses étapes **et** ses cartes
sans ordre garanti : une restriction aurait refusé une suppression légitime une
fois sur deux. La règle « une étape qui contient des cartes ne se supprime pas »
est donc énoncée dans le Manager, seul endroit qui sait distinguer les deux cas,
et un test couvre la suppression d'un espace au tableau rempli.

#### Le tableau répond toujours entier
Chaque écriture renvoie les étapes et les cartes au complet plutôt que la ligne
modifiée. Un glissement renumérote une colonne, la suppression d'une étape
renumérote le reste, et une page qui réconcilierait elle-même dériverait du
serveur en trois gestes.

#### Données de démonstration
Un seul des cinq espaces reçoit un tableau rempli, huit contenus répartis sur les
cinq étapes dont trois sans date. Une démo où chaque espace porte les mêmes
cartes enseigne que les cartes viennent avec le produit ; un tableau chargé à
côté de quatre vides montre les deux états.

### Dans aurora-client
`make aurora-update`, puis la migration, puis **`make sync-security`** : le motif
du pare-feu d'administration a changé pour couvrir `/workspace`. La cible écrase
`config/packages/security.yaml` depuis le vendor, donc il n'y a rien à recopier
à la main - mais sans elle, les espaces clients redirigent vers la connexion du
site public.

---

## [0.9.176] - 2026-09-15

### Ajouté

#### Studio gagne les espaces clients
Un **espace** est le contenant du travail mené pour un client : un nom lisible,
le client à qui il appartient, une équipe, une couleur et un fuseau horaire.
La liste s'ouvre sur « Espaces clients », au-dessus de la fiche client dans le
menu, parce qu'un espace s'ouvre tous les jours et qu'une identité légale se
remplit une fois.

**Un client peut en avoir plusieurs**, et c'est la décision sur laquelle repose
tout ce qui suivra. Un client avec deux marques fait tourner deux calendriers
éditoriaux ; un client qui signe pour un site puis pour du social a deux
chantiers de rythmes différents. La contrainte inverse aurait eu l'air plus
propre et se serait payée à la première société qui en demande deux.

L'équipe d'un espace n'est **pas un droit d'accès**. Qui peut ouvrir un espace
est décidé par `studio.spaces.view` sur le compte, comme partout ailleurs dans
le back-office ; la liste des membres dit qui travaille dessus, ce qui est le
nom qu'on donne au client. Deux sources de vérité pour l'accès auraient
divergé au premier cas limite.

Un espace **archivé** sort de la liste sans rien perdre : le filtre qui les
ramène n'apparaît que lorsqu'il y a quelque chose derrière, un interrupteur qui
ne fait rien étant un interrupteur qu'on apprend à ne plus croire. Archiver
n'est pas supprimer, et les deux colonnes répondent à deux questions.

La bascule `studio_spaces` est en cascade **sous les clients**, pas sous le
module : un espace est l'espace de quelqu'un, donc l'écran client en est un
prérequis réel. C'est le même raisonnement que pour les contrats.

#### Supprimer un client qui a encore un espace est refusé en toutes lettres
La clé étrangère disait déjà non, mais elle le disait comme une erreur SQL au
milieu d'une requête, qui arrivait à l'écran en 500. Le refus est maintenant
une phrase sous le champ, qui dit combien d'espaces sont ouverts.

Les contrats sont vérifiés en premier quand les deux refus sont vrais à la
fois : un espace se supprime, un contrat signé non.

### Modifié

#### La règle du module Studio admet la surface de livraison
Le docblock de `StudioModule` disait « ce qui est vendu et ce qui est livré, pas
les outils avec lesquels on le fait ». Un espace client n'est ni vendu ni
livré : c'est la surface sur laquelle on livre, et les premiers mots de la règle
ne pouvaient pas l'accueillir. La règle devient : Studio tient ce qui est vendu,
ce qui est livré, **et la surface sur laquelle on le livre**.

La frontière qu'elle refuse ne bouge pas : un outil appartient à son module.
C'est pour cette raison que la suite annoncera ses dates au Calendrier au lieu
de dessiner son propre calendrier, et rangera ses fichiers dans la Médiathèque
au lieu d'en tenir une seconde.

Un module `Workspace` séparé a été écarté pour la raison exacte qui avait déjà
coûté le renommage d'Accounting en Studio : un module ne peut pas porter de
relation vers l'entité d'un autre module, et un espace sans relation vers un
client n'est pas un espace client.

### Interne

#### Données de démonstration
Cinq espaces sur les trois clients existants, choisis pour les états que
l'écran sait dessiner : deux sur le même client, un sans personne dans
l'équipe, un archivé dans un fuseau étranger. Ils passent par le Manager et non
par un `persist()` direct, donc la démo emprunte le chemin d'un humain, couleur
étalée sur la palette et journal d'audit compris.

#### La mémoire projet décrivait des enums de modules qui n'existent plus
`architecture_module_parameter_enum` affirmait que chaque module portait son
propre `<Module>ModuleParameterEnum` et qu'ajouter un toggle métier dans l'enum
central était « la régression #1 ». Il n'existe qu'un seul enum, et il contient
tous les modules métier : la distribution est partie avec le split abandonné en
août. La mémoire est corrigée.

Les skills `/add-module`, `/add-submodule`, `/register-module-toggle` et
`/audit-module-toggles` décrivent encore ce monde disparu. Ils restent à
reprendre.

### Dans aurora-client
`make aurora-update` puis la migration. La bascule arrive activée : un projet
qui ne veut pas des espaces la coupe depuis l'écran d'accès aux modules.

---

## [0.9.175] - 2026-09-14

### Ajouté

#### Pexels depuis la console, sans navigateur
Le module Pexels n'avait qu'une porte : le sélecteur de la médiathèque,
derrière une session. Tout ce qui n'a pas de navigateur — l'amorçage d'une
démo, un script qui reconstruit une page, un assistant à qui on demande
d'illustrer trois sections — devait contourner le module, déchiffrer la clé
lui-même et rappeler le fournisseur à la main. C'est une deuxième
implémentation de la seule chose que ce module est, et c'est elle qui dérive.

Deux commandes :

- `aurora:ged:pexels:search "<requête>"` liste les résultats avec leurs
  identifiants, leurs dimensions et leur auteur. `--page`, `--limit`. Rien
  n'est téléchargé.
- `aurora:ged:pexels:import <id> [<id>…]` dépose les photos choisies en
  passant par le **vrai `PexelsImporter`**, celui que le navigateur appelle.
  `--dry-run` regarde et s'arrête avant de dépenser quoi que ce soit.

Un document déposé par la commande est indiscernable d'un document cliqué :
même largeur de téléchargement, mêmes variantes, même catégorie *Médias
éditoriaux*, et surtout **les mêmes colonnes `source_url` et d'attribution**,
dont le crédit sous l'image est rendu. Pexels demande ce crédit : un
contournement qui l'oublie n'est pas un défaut cosmétique, c'est un problème
de licence. C'est aussi la catégorie qui manquait le plus souvent, sans que
rien n'ait l'air cassé.

Un identifiant inconnu est signalé et n'interrompt pas les suivants, mais le
code de sortie le dit. Une intégration éteinte renvoie vers l'écran de
réglages plutôt que vers une trace d'exception.

### Interne

#### `PexelsClient` sait chercher une photo par son identifiant
`photo(string $id)` interroge `/v1/photos/{id}` et rend la même forme
normalisée que la recherche, pour un appelant qui a un identifiant et pas un
mot-clé. Rend `null` aussi bien pour « cette photo n'existe pas » que pour
« le fournisseur est injoignable » : la différence demande justement que le
fournisseur réponde, et la suite est la même dans les deux cas. C'est la
ligne de journal qui les distingue.

### Dans aurora-client
Rien à répercuter : les deux commandes arrivent avec le bundle et
n'apparaissent que si le module Pexels est activé et sa clé renseignée.

---

## [0.9.174] - 2026-09-14

### Ajouté

#### Basculer toute la médiathèque d'un stockage à l'autre, en un geste
La barre d'actions de la médiathèque savait déplacer une sélection ; elle ne
savait pas répondre à « désormais tout vit là-bas ». Cocher huit cents lignes
page par page n'est pas une sélection, c'est une corvée, et une corvée dont on
perd le compte.

Deux entrées dans le menu **Actions** de la liste des documents, à côté de
« Ajouter un document » : *Tout basculer sur le stockage distant* et *Tout
basculer sur le serveur*. Les documents déjà à destination sont ignorés sans
qu'on leur demande rien, et la corbeille n'est pas déplacée : ses fichiers sont
en partance, les copier dans un bucket facturé au volume reviendrait à payer
pour stocker ce qu'on s'apprête à jeter.

Une confirmation avant, ce que le déplacement d'une seule ligne n'a pas : on
défait un document en appuyant sur l'autre bouton, on ne défait pas huit cents
de la même façon. **Tout part au worker**, jamais dans la requête. Les deux
autres points d'entrée déplacent un petit document sur place pour que la ligne
se mette à jour sous les yeux du lecteur ; ce compromis ne tient que parce
qu'ils savent combien de documents on leur a confiés. Ici la réponse est « tous
ceux que la médiathèque contient », et une requête qui copie huit cents
fichiers vers un bucket est une requête qui meurt en chemin.

Derrière le bouton : `POST /backend/ged/documents/relocate-all`, sous le
privilège `ged.documents.relocate` déjà en place.

### Corrigé

#### Sur téléphone, une zone et une photo par ligne
Une page de portfolio dont les images étaient rangées en demi-largeur sortait
en deux colonnes sur un écran de 390 px : environ 170 px par photo, gouttière
déduite. Personne n'a choisi ça pour un téléphone, c'est la mise en page de
bureau qui survivait jusqu'à une largeur qui ne l'attendait pas.

L'éditeur décrit depuis toujours l'arrangement inverse — son contrôle de
largeur n'écrit que `span.lg` et dit qu'en dessous du grand palier une zone
reste pleine largeur — mais le front honorait le `base` des mises en page plus
anciennes. Le normaliseur tranche maintenant : sur téléphone une zone est
seule sur sa ligne, quoi qu'elle ait stocké. **La tablette et le bureau ne
bougent pas** : l'ancienne largeur est recopiée dans `md`, que `lg` continue
d'hériter exactement comme avant.

Même règle pour les galeries, celle de la publication et celle en zone de
grille : le nombre de colonnes choisi est ce vers quoi la mise en page monte,
non ce dont elle part. Une galerie à trois colonnes en montre une sur
téléphone, trois à partir de `sm`.

### Dans aurora-client
Rien à répercuter : les deux changements vivent dans le bundle. Le bouton
n'apparaît que sur les installations qui ont un second stockage configuré et
vérifié, comme les autres actions de déplacement.

---

## [0.9.173] - 2026-09-14

### Modifié

#### La documentation montre l'interface telle qu'elle est
Les 0.9.172 avait déplacé les actions derrière un bouton unique sur dix-neuf
écrans, et les 225 captures qui illustrent les pages de documentation
montraient encore la rangée de boutons d'avant. 177 d'entre elles sont
reprises.

Dix pages nommaient un bouton qui a bougé, et disaient donc faux : « Le bouton
Créer une présentation », « Le bouton Apparence, en haut de la page », « Le
bouton Historique ». Elles nomment maintenant le menu qui les contient. Les
légendes d'images suivent.

### Interne

#### Les scénarios de capture savent ouvrir la feuille
`tools/doc-screenshots/capture-steps.mjs` pilote le produit en cliquant, donc
dix parcours visaient un bouton qui n'est plus sur la barre. Un `pageAction()`
ouvre la feuille et y lit le verbe, ce qui garde le nom du geste dans le
scénario et ne met le chemin qu'à un seul endroit.

Deux corrections de solidité au passage. La promesse d'ouverture d'onglet du
parcours de prévisualisation est désormais attrapée même quand le clic échoue :
son rejet, vingt secondes plus tard, tuait le script entier et les parcours
suivants n'étaient jamais photographiés. Et la fermeture d'une modale est
attendue détachée plutôt que seulement demandée.

La photo des actions en masse ouvre maintenant la feuille avant de déclencher :
une page qui parle d'actions en masse ne pouvait pas s'illustrer d'un bouton
fermé.

### Dans aurora-client
Rien à répercuter : la documentation est servie depuis le bundle.

---

## [0.9.172] - 2026-09-14

### Modifié

#### Un bouton « Actions » partout, au lieu d'une rangée de boutons
Le menu d'actions en modal existait déjà et il était bon, mais il s'arrêtait à
la ligne de tableau. Les en-têtes de page, eux, gagnaient un bouton par
capacité jusqu'à en aligner six : l'éditeur d'article portait retour, statut,
refuser, approuver, révisions, aperçu et enregistrer sur une seule rangée.

Un même bouton « Actions » les rassemble maintenant, sur dix-neuf écrans. Ce
qui reste dehors est le geste pour lequel la page existe, Enregistrer sur un
éditeur, Présenter sur un deck, Contresigner sur un contrat, et le retour vers
la liste, qui est de la navigation. Dans la feuille, chaque action a sa ligne,
son nom en toutes lettres et sa couleur, la destructrice en dernier.

Les toolbars de liste vont plus loin : ils passent au bouton « Actions » même
quand ils n'avaient qu'une entrée, pour que toutes les listes du back-office
s'ouvrent de la même façon. L'état vide, lui, garde son vrai bouton : une
liste sans rien dedans n'a qu'une chose à proposer.

Les cartes de thèmes suivent la même règle : « Activer » garde son bouton
pleine largeur, éditer et supprimer passent derrière les trois points, ce qui
éloigne la corbeille du bouton qu'on presse exprès.

### Corrigé

#### Deux en-têtes s'écrasaient sur téléphone
L'éditeur d'article et la fiche document plaçaient leurs boutons dans une
rangée qui ne pouvait pas se replier. Sur un écran étroit, un article en
attente de relecture tassait sept éléments sur une ligne, jusqu'à les rendre
illisibles. La rangée se replie désormais, et il ne lui reste de toute façon
que deux boutons.

### Interne

#### La feuille d'actions a été sortie de son déclencheur
`AppActionSheet` porte la mécanique, la modale, l'ordre des lignes et la
fermeture avant exécution. `AppRowActions` et `AppPageActions` en sont les deux
façades, la première avec ses trois points dans une ligne de tableau, la
seconde avec le mot « Actions » en haut d'une page, là où aucun en-tête de
colonne ne le dit à sa place.

Les actions d'en-tête sont asynchrones là où celles d'une ligne ne l'étaient
pas : `AppActionButton` accepte `loading`, et le déclencheur de page `busy`,
puisque la feuille se ferme en lançant l'action et que le bouton qui l'a
lancée n'est plus à l'écran.

La fiche document lit désormais la même liste que les lignes de la GED plutôt
que de réécrire sa propre direction de relocalisation.

### Dans aurora-client
Rien à répercuter : les pages de liste distribuées par core portent le nouveau
bouton sans intervention. Un projet client qui a écrit ses propres pages de
liste peut suivre la même règle avec `AppPageActions`, importé depuis
`@shared/components/action/AppPageActions.vue`.

---

## [0.9.171] - 2026-09-14

### Corrigé

#### Un serveur mail en panne répondait au visiteur, pas au site
Une soumission de formulaire déclenchait deux mails et un webhook en ligne,
dans la requête du visiteur, juste après l'enregistrement. Le commentaire
au-dessus du code promettait qu'un serveur mail injoignable ne coûterait pas
au visiteur ce qu'il avait tapé : c'était vrai de la base, pas de la réponse.
`MailService` n'attrape rien, donc l'exception remontait jusqu'au contrôleur
et le visiteur lisait une erreur pour un message pourtant enregistré. Il le
réécrivait, et le site recevait la même demande deux ou trois fois. Le
webhook, lui, attrapait déjà ce qui le concernait : seuls les mails avaient
été oubliés.

Tout cela part maintenant sur la file `async`. Ce qui échoue est retenté trois
fois puis conservé dans `failed` au lieu d'être perdu, et le visiteur n'attend
plus deux allers-retours vers le serveur mail. Le signal contact, lui, reste
en ligne : c'est un événement interne qu'un CRM écoute, pas un appel sortant.

#### Le routage Messenger n'arrivait pas jusqu'aux projets clients
Il était déclaré dans le `config/packages/messenger.yaml` de core, c'est-à-dire
dans la configuration de son application de développement, qui n'est pas
distribuée. Et rien ne le signalait : un message sans route n'est pas une
erreur, Symfony l'exécute en ligne. La file existait donc en développement et
nulle part ailleurs. Le déplacement d'un document GED tournait ainsi dans la
requête qui l'avait demandé. `AuroraBundle` porte désormais le routage des
messages d'Aurora ; le client ne fournit que le transport.

#### Répondre à une soumission demandait de rouvrir le back-office
La notification arrivait de l'adresse d'envoi du site, sans `Reply-To`.
Répondre depuis sa boîte écrivait donc à `noreply`, et la seule façon de
joindre la personne était d'ouvrir le formulaire dans le back-office et de
recopier son adresse à la main, une fois par message. Elle porte maintenant
l'adresse de qui a écrit.

#### La notification partait dans la langue du visiteur
Une soumission par la version espagnole d'un formulaire arrivait en espagnol,
sujet compris, ce qui ne dit rien du message et rend la boîte de réception
intriable. Elle suit désormais la langue des e-mails du site, avec repli sur
la langue par défaut. La confirmation envoyée au visiteur, elle, reste dans
la sienne.

### Ajouté

#### La notification dit de quelle soumission il s'agit
Elle ne portait que les réponses : ni référence, ni date, ni lien. Retrouver
une demande deux semaines plus tard se faisait à la main dans le back-office.
Elle indique maintenant la référence, la date, la langue du formulaire, et
porte un bouton vers la fiche.

#### Une durée de conservation pour les soumissions
Une soumission garde un nom, une adresse, ce qui a été écrit et l'IP d'où cela
venait. Rien n'expirait : un formulaire laissé en ligne collectait des données
personnelles aussi longtemps que le site vivait, et aucune durée ne pouvait
être annoncée dans une politique de confidentialité.

Le réglage **Conservation des soumissions (jours)** répond à la question. Il
part à 0, c'est-à-dire sans limite : une version qui se mettrait à effacer le
courrier d'un client le jour de son installation serait pire que le problème.
La purge tourne à 3 h avec les autres, par lots de 500, et journalise ce
qu'elle efface sans copier les réponses.

#### Un formulaire dit si sa page propre doit être référencée
`/{locale}/forms/{slug}` porte les mêmes questions que le contenu où le
formulaire est posé, sous une seconde adresse. Aujourd'hui rien n'y mène et
elle est hors du sitemap, donc le doublon est théorique ; il cesse de l'être
le jour où une entrée de menu pointe dessus.

La case **Référencer la page du formulaire** est décochée par défaut, y compris
sur les formulaires existants : c'est déjà ce que ces sites font de cette page.
Elle continue de répondre dans tous les cas, c'est ce sur quoi tombe un lien
dans un mail ou derrière un QR code.

### Dans aurora-client
Rien à répercuter. Le routage Messenger arrive maintenant par le bundle, donc
le `messenger.yaml` du client n'a rien à gagner ; il lui faut toujours le
transport `async`, qu'il déclare déjà.

Vérifier en revanche que le worker tourne, puisque les mails de formulaire en
dépendent désormais : `systemctl status aurora-worker`.

---

## [0.9.170] - 2026-09-14

### Corrigé

#### Les cartes des contrats en préparation avaient le même plancher que celles des trames
Le défaut corrigé sur la liste des trames en 0.9.168 se trouvait aussi sur la
liste des contrats, au même endroit : sur un téléphone, la colonne unique se
dimensionnait sur le contenu des cartes plutôt que sur la place disponible.
Mesuré sur une carte dont le titre ne peut pas se couper, dans une boîte de
288 px : la carte sortait à 470 px, soit 182 px hors de la page.

Rien ne débordait encore sur cette liste, faute d'un texte assez long dans ces
cartes. Mais un nom de client d'un seul tenant suffisait, et le jour où cela
arrive ce ne sont pas une carte mais toutes les cartes de la liste qui sortent
de la page ensemble. Corriger une liste et laisser sa voisine revenait à
attendre le prochain nom trop long.

Les deux listes sont désormais tenues par le même mécanisme, celui déjà en
place sur les trames depuis la 0.9.168. Elles portaient chacune le sien, ce qui
laissait deux corrections pour un seul défaut : personne relisant l'une des
deux ne pouvait dire laquelle tenait, donc aucune n'était sûre à toucher.

### Dans aurora-client
Rien à répercuter : la correction est dans le back-office.

---

## [0.9.169] - 2026-09-14

### Ajouté

#### Douze types de zone de plus sur la grille de contenu
La grille passe de 12 à 24 types. Ce qui arrive, dans l'ordre où on les
rencontrera sans doute :

**Des fichiers.** *Audio* joue un enregistrement de la médiathèque. *Document*
offre un fichier à emporter, avec son format et son poids, sans passer par un
bouton vers une adresse écrite à la main qui casse au premier remplacement.
*Galerie* pose jusqu'à vingt-quatre photos dans une seule zone, entre deux
paragraphes, là où la galerie de publication est unique et reste sous la page.

**Des choses à regarder.** *Avant / après* superpose deux images avec une
poignée qu'on tire. *Présentation* montre un deck Studio dans la page par son
lien de partage. *Intégration* accepte une adresse Spotify, SoundCloud, CodePen
ou Calendly, et rien d'autre.

**Des choses qui se remplissent toutes seules.** *Termes* déroule les termes
d'une taxonomie, chacun vers sa page, et un terme ajouté plus tard apparaît de
lui-même. *Recherche* pose un champ limité à un type de contenu.
*Commentaires* place le fil là où vous le voulez plutôt qu'au pied de la page.

**Du texte autrement.** *Onglets* met plusieurs corps de texte dans une zone,
un affiché à la fois. *Adresse* écrit une adresse avec un lien d'itinéraire,
sans charger de carte chez personne. *Bloc partagé* dessine ici la grille d'une
autre publication : le bandeau de contact au pied de douze pages se corrige
désormais une fois.

La liste d'une zone Liste gagne un costume, *Équipe*. Et *Questions fréquentes*,
qui repliait déjà ses entrées, sait maintenant n'en garder qu'une ouverte à la
fois : c'est une case à cocher sur ce costume, pas une entrée de plus dans le
menu.

#### Chaque zone peut attendre sa date, ou n'être vue que des membres
Un nouveau bloc « Quand et pour qui » sur toutes les zones : une date de début,
une date de fin, et un public. Une promotion s'éteint donc toute seule, et un
tarif peut n'apparaître qu'aux visiteurs connectés. Les deux dates sont
inclusives : « jusqu'au 31 » comprend le 31. Le panneau, lui, vous montre
toujours la zone, sinon vous ne pourriez pas la modifier en attendant.

#### La médiathèque sait filtrer les fichiers son
Un filtre « Audio » s'ajoute aux images, vidéos, PDF et autres, et le tableau de
bord compte cette part à son tour.

### Dans aurora-client
Rien à répercuter : les nouveaux types apparaissent d'eux-mêmes dans le menu de
zones après la mise à jour. Une page qui affichait déjà ses commentaires en pied
continue de le faire tant qu'aucune zone Commentaires n'est posée dessus.

---

## [0.9.168] - 2026-09-14

### Corrigé

#### Les cartes des trames de contrat débordaient de l'écran
Sur un téléphone étroit, la liste des trames de contrat passe en cartes, et les
cartes sortaient de la page par la droite : à 320 px de large, la carte
mesurait 322 px dans une colonne qui en offrait 288, et la page gagnait un
défilement horizontal dont personne ne voulait.

La cause tient en un mot : une carte est un élément de grille, donc sa largeur
minimale est celle de son contenu, et la colonne se dimensionnait dessus plutôt
que sur la place disponible. Rien à l'intérieur n'avait besoin de cette
largeur ; une fois le plancher levé, la carte se remet en forme toute seule et
plus rien ne dépasse. Les écrans plus larges ne bougent pas : à partir de
375 px il y avait déjà la place, ce qui explique que le défaut se soit vu
seulement sur les petits appareils.

#### Le filtre par catégorie des trames n'était pas le même que partout ailleurs
Toutes les autres listes filtrent avec un select cherchable ; celle des trames
avait un menu déroulant simple. C'est maintenant le même composant, avec la
frappe pour retrouver une catégorie, ce qui importera le jour où la
bibliothèque en comptera trente plutôt que trois.

« Toutes les catégories » reste une ligne de la liste et pas seulement un
état : le composant accepte de se vider, mais n'affiche rien pour le faire sur
un choix unique, et un filtre qu'on ne sait pas enlever est un filtre qui
reste. Les liens existants continuent de fonctionner, `?category=none` compris.

#### Le titre d'une bannière était coupé sur un téléphone
L'échelle des tailles de titre n'avait que deux paliers, et le premier n'était
pas un palier mobile : en `xl`, un titre démarrait à 48 px sous 640 px de
large. Mesuré sur une page réelle, un titre en trois segments occupait 343 px
dans une colonne de 343 px : ça tenait par chance sur un téléphone de 375 px et
débordait en dessous.

Les quatre tailles gagnent un vrai palier mobile, et l'ancienne taille devient
celle des écrans moyens. Pas de césure des mots : couper un titre au milieu
d'un mot se lit comme un dégât, le réduire se lit simplement. Toutes les
bannières de tous les sites sont concernées, c'est voulu.

### Interne

#### Le troisième cas d'accès aux uploads n'était traversé par aucun test
`UploadAccessEnum::Restricted` - servi, mais par l'application et à ce
visiteur-là seulement - n'est produit par aucun des deux guards livrés : la GED
répond publié ou refusé, les contrats refusent toujours. Le cas n'est pas du
code mort pour autant, c'est le vocabulaire offert à l'aire d'un projet client,
et le jour où ce client écrit son guard, c'est cette branche qui garde son
fichier hors des caches partagés.

La suite enregistre donc son propre producteur sous `when@test` et vérifie ce
qui fait l'intérêt du cas : le fichier est servi, la réponse est `private`, et
elle n'est jamais `immutable`. Un test voisin sur une clé que personne ne
réclame garde la comparaison honnête.

La moitié distante reste non couverte, et la raison est notée dans la mémoire
projet : le contrôleur se restreint sur la classe concrète `R2StorageAdapter`,
qui est `final readonly`, donc aucun double ne peut passer le `instanceof` et
un test écrit avec un faux adaptateur passerait même si la règle disparaissait.

---

## [0.9.167] - 2026-09-13

### Corrigé

#### Le limiteur du partage de présentation était à déclarer côté client
La 0.9.166 a ajouté un mot de passe sur les liens de partage, et son contrôleur
public câble le limiteur `deck_share_password` par son nom. Or
`config/packages/rate_limiter.yaml` d'aurora-core est la config de son
application de développement : composer ne la distribue pas. Le premier
`make aurora-update` d'un projet client échouait donc sur un conteneur qui ne
se construit pas, avec un message qui parle d'autowiring et pas de limiteur.

Rien à corriger dans le code : c'est une ligne de documentation qui manquait.
La voici, et la mémoire projet correspondante existe désormais pour que le
prochain contrôleur public l'ajoute des deux côtés du premier coup.

### Dans aurora-client
**À ajouter dans `config/packages/rate_limiter.yaml`**, à côté des deux
limiteurs de contrat, si ce n'est pas déjà fait :

```yaml
        deck_share_password:
            policy: sliding_window
            limit: 20
            interval: '1 hour'
```

Sans cette entrée, le conteneur ne se construit pas.

---

## [0.9.166] - 2026-09-13

### Ajouté

#### Une présentation a sa propre apparence
Toutes les présentations étaient dessinées dans le gris du back-office, et rien
dans le modèle ne pouvait dire autrement : le cadre qui dessine une slide
écrivait ses couleurs en constantes. Deux présentations ne pouvaient pas
différer, ce qui pour un document remis à un client est tout le problème.

Le bouton « Apparence » ouvre un panneau où l'on choisit un thème parmi cinq
(Ardoise, Encre, Papier, Affirmé, Minuit), puis, si on le souhaite, on remplace
une à une ses trois couleurs, sa paire de polices, et on ajoute un logo, une
mention de pied de page et la numérotation des slides.

**Une couleur laissée au thème continue de suivre le thème**, y compris s'il est
retouché dans une version ultérieure ; une couleur choisie est gelée à ce
choix. C'est la raison pour laquelle rien n'est enregistré par défaut : stocker
le bleu du thème comme le bleu de la présentation le figerait sans que personne
le sache.

Le panneau montre en permanence une vraie slide de la présentation, redessinée à
chaque changement : la question posée est visuelle, la réponse est dessinée.

L'apparence suit la présentation partout où elle est dessinée, y compris à
l'impression et dans un lien de partage, et une présentation dupliquée garde la
sienne.

#### Cinq gabarits de plus, et un graphique
Chiffre clé, image et texte, cartes, frise, tableau, plus un gabarit graphique
qui dessine des barres, une courbe ou un anneau dans la couleur d'accent de la
présentation.

Cartes, frise, tableau et graphique se remplissent comme les puces, une ligne
par élément, avec une **barre verticale** entre les valeurs d'une même ligne :
`Cache | Listes et fiches produit`. Un sous-formulaire répétable par gabarit
aurait été trois éditeurs de plus à construire et à maintenir.

#### Une présentation peut être un modèle
« C'est un modèle » range une présentation parmi celles qu'on propose au moment
d'en créer une nouvelle. Partir d'un modèle reprend ses slides et son
apparence ; le titre, la rubrique et le client sont ceux qu'on vient de saisir,
ce qui est la différence avec la duplication. La présentation ainsi créée n'est
pas elle-même un modèle, sans quoi une liste de trois en compte trente au bout
d'un mois.

Un modèle reste une présentation ordinaire : le jour où on veut le montrer, il
n'y a rien à convertir.

#### Une présentation longue se replie par chapitres
Sur la vignette d'un intercalaire, un chevron cache les slides qui le suivent et
affiche leur nombre. Les chapitres sont lus dans les intercalaires eux-mêmes :
rien de plus à créer, et une présentation qui n'en utilise pas a simplement un
seul chapitre.

#### Un lien de partage peut demander un mot de passe, et compte ses ouvertures
Facultatif : une adresse impossible à deviner et une expiration suffisent le
plus souvent. Quand il y en a un, le destinataire tombe d'abord sur une page qui
ne dit rien de la présentation, et un mot de passe faux répond exactement ce que
répond une adresse fausse, pour qu'une adresse devinée n'apprenne pas qu'elle
est bonne.

Le mot de passe est haché, là où le jeton ne l'est pas, et la différence est le
raisonnement : l'adresse **est** le secret, donc la hacher n'achèterait rien ;
un mot de passe est une phrase choisie par quelqu'un, et les phrases se
réutilisent. Ce qui fuit ici ne doit ouvrir rien d'autre.

Chaque lien affiche aussi combien de fois il a été ouvert. Un compteur et pas un
journal : « est-ce qu'ils l'ont lu, et est-ce qu'ils y sont revenus » se répond
par un nombre, là où une ligne par ouverture serait un relevé des habitudes de
lecture de quelqu'un.

#### Faire une présentation à partir d'un document écrit
« Importer un document », dans la liste, ouvre l'éditeur de blocs que vous
connaissez déjà. Vous y écrivez ou vous y collez, et le document devient des
slides.

La règle tient en une phrase : **un titre ouvre une slide, et ce qui suit la
remplit**. Une liste devient une slide à puces, un tableau un tableau, une
citation une citation, une image de la médiathèque une slide image. Plusieurs
blocs sous un même titre font plusieurs slides qui le répètent, et rien n'est
jeté pour rendre la conversion plus propre. Le gras et l'italique arrivent
jusqu'à la slide ; les liens y perdent leur adresse et gardent leurs mots.

La conversion est faite par le serveur, où la règle est écrite une fois et où
chaque slide produite passe par le même filtrage que les autres.

L'éditeur n'est pas dans la slide, et c'est un choix : un cadre 16/9 ne peut
promettre que ce qu'on y arrange arrive au mur que parce que ses gabarits sont
déclarés, ce qui est aussi ce qui permet de mesurer le texte et de le réduire.
Un document de hauteur quelconque ne se mesure pas. L'éditeur est donc là où le
texte qui coule a raison, l'écriture, et passe la main à la projection.

#### Cadrer une image dans sa slide
Une image peut désormais **remplir son cadre** au lieu d'y tenir en entier, et
on choisit le point qui doit survivre au rognage en cliquant dessus : le
drapeau, le visage, le coin de la capture. Par défaut, c'est le point de visée
que porte le document lui-même, donc une photo cadrée une fois garde son
cadrage dans toutes les présentations qui l'utilisent.

#### Un fond et un sur-titre sur n'importe quel gabarit
L'image était enfermée dans son gabarit : une présentation qui voulait une photo
derrière un intercalaire devait choisir entre la photo et les mots. Trois
réglages sont désormais communs à tous les gabarits : un sur-titre, une image de
fond, et le voile qui assombrit cette image vers la couleur de fond de la
présentation pour que le texte reste lisible.

#### Du gras et de l'italique dans le texte d'une slide
`**gras**`, `*italique*` et `` `code` `` sont reconnus dans le texte d'une
slide. Rien d'autre : un titre ou une liste tapés dans un champ restent les
caractères tapés, parce que la mise en page d'une slide est décidée par son
gabarit. Les liens et les images sont retirés, le texte qu'ils entouraient
reste.

#### Un sommaire, un pointeur, et des transitions
**O** ouvre une grille de toutes les slides, où un clic saute. **P** allume un
cercle rouge qui suit la souris : un interrupteur et non une touche à maintenir,
parce qu'une touche maintenue se répète et qu'un pointeur qui clignote au rythme
du clavier est pire que pas de pointeur. Et le curseur de la souris disparaît
quand elle ne bouge plus, une flèche garée au milieu d'une slide projetée étant
la tache que personne ne voit sur sa propre présentation.

Entre deux slides, un fondu. C'est le seul réglage du module dont la valeur par
défaut n'est pas le comportement d'avant, et le raisonnement est qu'une
transition n'appartient pas au document : le papier et le lien de partage n'en
portent pas, donc aucune présentation déjà écrite ne change d'aspect. Aucune,
fondu ou glissement, dans « Apparence ». Un système réglé sur « réduire les
animations » n'en voit aucune, quel que soit le choix de la présentation.

#### Une vue présentateur
Les notes d'orateur avaient une colonne à elles depuis l'origine, précisément
pour qu'aucun gabarit ne puisse les mettre au mur par accident. Rien ne les
lisait.

« Vue présentateur » ouvre une seconde fenêtre, à poser sur son propre écran :
la slide en cours, la suivante, les notes en gros caractères et un minuteur. Les
deux fenêtres avancent ensemble, dans les deux sens. La liaison passe par un
canal du navigateur, de même origine et en mémoire : les notes ne quittent pas
la machine où elles sont lues.

#### Glisser une vignette, dupliquer une slide
Une poignée sur chaque vignette permet de la déplacer à la souris ; les flèches
restent, parce qu'elles sont le seul chemin au clavier. La copie d'une slide se
pose juste après celle qu'elle copie.

### Corrigé

#### Une image non publiée ne part plus en silence dans un lien de partage
Depuis la 0.9.163, `/uploads` ne sert un document à un visiteur sans compte que
s'il est publié, ce qui est voulu et ce qui est bien. Mais un téléversement est
un brouillon tant que personne n'en décide autrement : une présentation partagée
pouvait donc arriver avec des images manquantes, et l'auteur l'apprenait de la
personne à qui il avait envoyé le lien.

Le panneau de partage nomme désormais ces images avant qu'on crée le lien. Rien
n'est publié automatiquement : ce qu'on fait d'un fichier retenu est un
jugement, et une présentation n'est pas une raison de publier une image à toute
la médiathèque.

Dans le back-office rien ne change : l'éditeur, le plein écran, la vue
présentateur et l'impression passent par la porte qui demande un compte.

#### Supprimer une image dit enfin qu'une présentation s'en sert
L'écran de la médiathèque demande à chaque module « qui utilise ce document » et
personne ne répondait pour les présentations : une photo posée sur quatre slides
se supprimait sans un mot, et les quatre slides ne dessinaient plus rien. Le
point d'extension existait et n'avait aucune implémentation ; c'est la première.

#### Le texte ne sort plus de la slide
Une colonne centrée dans une boîte qu'elle déborde sort par les **deux** bouts :
une slide à puces avec une puce de trop perdait son titre au-dessus du cadre et
sa dernière ligne en dessous, sans rien dire, `overflow: hidden` faisant la
coupe. Mesuré sur le cas signalé : 659 px de contenu dans une boîte de 409.

La typographie se réduit désormais jusqu'à ce que la slide tienne. Toutes les
tailles du cadre sont une fraction de la largeur de la slide, donc un seul
facteur appliqué à toutes est la même décision en vignette et au mur. Et si les
mots sont vraiment trop nombreux, la slide s'aligne en haut plutôt que d'être
coupée aux deux extrémités : on lit à partir du début.

#### Une image en « sans rognage » n'est plus rognée
Le cadre de l'image était une grille dont la hauteur était décidée par l'image,
qui elle-même demandait 100 % de cette hauteur. Le navigateur rompait le cycle
en revenant à la taille naturelle : une photo carrée de 1280 px se dessinait sur
815 px de haut dans une boîte de 293, et se faisait couper en haut et en bas.

#### Le gras se voit dans un titre
Un titre est déjà en graisse 600, et en chasse fixe l'écart jusqu'à 700 était
invisible. Dans un titre, le gras prend la couleur d'accent ; dans le texte
courant, où l'on part de 400, la graisse suffit.

### Dans aurora-client
**Trois migrations à passer** après `make aurora-update` : `core_decks` gagne
`theme`, `style` et `template`, `core_deck_share_links` gagne `open_count` et
`password_hash`. Toutes ont pour valeur par défaut exactement le comportement
d'avant : aucune présentation déjà composée ne change d'aspect, aucune ne
devient un modèle, aucun lien ne se met à demander un mot de passe. Les liens
déjà ouverts voient leur compteur mis à un, zéro à côté d'une date d'ouverture
se lisant comme un bug.

Rien d'autre à répercuter à la main. Un projet client qui aurait surchargé
`SlideFrame.vue` doit en revanche reprendre les propriétés personnalisées
`--slide-*` : le cadre lit désormais ses couleurs plutôt que de les écrire.

---

## [0.9.165] - 2026-09-13

### Corrigé

#### Les images publiques sont enfin gardées en cache
Une image posée dans une page publique repartait avec
`Cache-Control: immutable, max-age=0, must-revalidate, private`, alors que le
code demandait un cache public d'une journée. Le navigateur la redemandait donc
à chaque affichage, et aucun cache partagé ne pouvait la garder. Mesuré sur la
production le 13/09/2026, et reproduit à l'identique en local.

La cause n'est pas celle qu'on croyait. Le `SessionListener` de Symfony
réécrit l'en-tête à la fin de toute requête qui **lit** la session, et non
seulement de celles qui en ouvrent une : la condition est `getUsageIndex()`,
pas `isStarted()`. Ici la session n'était jamais démarrée, jamais écrite,
aucun cookie n'était envoyé, mais `LocaleSubscriber` la consulte à chaque
requête pour savoir dans quelle langue répondre, et cette seule lecture
suffisait.

Le détail cruel est que demander un cache public aggravait les choses : le
listener calcule `max-age = 0` quand la réponse se déclare `public`, et
conserve le `max-age` existant sinon. La ligne censée rendre la réponse
cachable est donc exactement celle qui ramenait la durée à zéro.

`BinaryFileServer::servePublic()` pose désormais l'en-tête d'échappement que
Symfony documente pour ce cas, et que Symfony retire avant l'envoi. Ces
octets sont un fichier sur disque, identique pour tout le monde, et qui a le
droit de le lire a déjà été décidé avant d'arriver là.

Rien ne change pour un fichier retenu : il reste `private`, il reste streamé
par l'application, et l'échappement n'est volontairement pas posé sur la
variante gatée de `BinaryFileServer`.

### Interne

#### Les tests assertaient la survie d'un mot, pas l'en-tête envoyé
`UploadsServeControllerTest` n'assertait que la présence de `immutable`, avec
un commentaire expliquant que le reste était écrasé par Symfony. C'était
exact, et c'est passé au vert pendant tout le temps où les images publiques
n'étaient pas cachables. Les tests portent maintenant sur l'en-tête réellement
émis, `public` et `max-age` compris, et vérifient au passage que la session
est bien lue sur ces requêtes : sans cela l'assertion deviendrait verte pour
une autre raison le jour où plus personne ne la lit.

## [0.9.164] - 2026-09-13

### Corrigé

#### Un document de la GED ne se télécharge plus en devinant son adresse
`/uploads/{path}` servait n'importe quel fichier déposé dans l'application à
n'importe quel visiteur, sans session. Un contrat, une pièce d'identité, un
document interne était donc lisible par qui devinait son chemin, ou son
identifiant : `/document/{id}` est une séquence, elle s'énumère en comptant.

L'entité portait pourtant déjà un statut, `draft` / `published` / `archived`,
que personne ne consultait au moment de servir. C'est lui qui décide
désormais : sans session, seuls les documents publiés sortent. Un brouillon,
un document archivé, un document à la corbeille et un fichier qu'aucune ligne
ne réclame répondent 404, le même 404 qu'un fichier absent, pour ne pas
confirmer que l'adresse nomme quelque chose.

Les fichiers dérivés suivent leur source. Une vignette et chacun des variants
responsive ont leur propre adresse, dans leur propre dossier : retenir l'image
en laissant sortir sa version « medium » aurait rouvert le trou un répertoire
plus bas.

#### Les PDF de contrats signés sortaient par la même porte
Le module des contrats sert ses PDF par une route autorisée sous `/backend`,
et le chemin de stockage est `contracts/<année>/<référence>.pdf`, une
référence séquentielle. Cette autorisation se contournait donc en demandant le
fichier au fourre-tout, qui ne demandait rien à personne. L'aire `contracts`
n'est plus servie par cette adresse, pour personne : le module garde sa route,
qui reste la seule.

### Ajouté

#### Savoir avant de livrer si une image publique va disparaître
`php bin/console aurora:ged:audit-public-documents` liste, en lecture seule,
les documents qu'une page publique référence sans qu'ils soient publiés : le
logo, le favicon, l'image de partage par défaut, la vignette et l'`og:image`
d'une publication, et les photos posées dans un bandeau, une grille ou une
galerie.

À lancer avant de déployer cette version. Le statut par défaut d'un envoi est
`draft`, et le logo comme le favicon sont résolus par identifiant sans regarder
le statut : un visuel choisi alors qu'il était encore en brouillon est le cas
attendu, pas l'exception. La commande ne corrige rien, elle dit quoi regarder.

### Interne

#### Une aire de stockage déclare qui peut la lire
`UploadsServeController` interroge `UploadAccessDecider` avant de répondre, et
une aire pose sa règle en enregistrant un `UploadAccessGuardInterface` (tag
`aurora.upload_access_guard`). Une aire que personne ne réclame reste
anonyme : c'est le comportement d'avant, et il évite de casser un projet
client qui range ses fichiers sous son propre préfixe.

Le personnel lit les fichiers retenus par `backend_ged_files`, et cette route
n'est pas un choix de style. Le pare-feu `admin` couvre `^/(backend|dev)` :
sur `/uploads/…` aucune identité de back-office n'est restaurée, donc un
contrôle de privilège posé là aurait refusé le back-office exactement comme un
inconnu. `DocumentUrlGenerator` choisit la route selon le statut du document,
si bien qu'aucun appelant n'a eu à changer.

Un fichier retenu n'est jamais redirigé vers un lien signé ni vers un domaine
public, quel que soit le mode de livraison configuré : un lien qui survit au
contrôle qui l'a produit n'est pas un contrôle.

### Dans aurora-client
Rien à répercuter dans le code. En revanche, lancer
`php bin/console aurora:ged:audit-public-documents` **avant** de basculer sur
cette version, et publier les documents qu'elle remonte : sinon ils cessent
d'être servis aux visiteurs.

## [0.9.163] - 2026-09-13

### Interne

#### Le contrôleur des contrats ne demande plus le serveur de fichiers
`ContractsController` recevait un `BinaryFileServer` que plus aucune de ses
méthodes n'appelait. Le PDF signé part par une `StreamedResponse` qui lit
`ContractPdfGenerator::readStream()` : les octets passent par l'application,
c'est ce qui les garde derrière l'autorisation, et le serveur de fichiers n'a
jamais eu de rôle là-dedans. La dépendance est retirée, avec son import.

Le constructeur perd donc un argument. La classe est un point d'extension, mais
aucune sous-classe n'existe, ni ici ni dans les projets clients, et rien ne
l'instancie à la main : l'autowiring fait le reste. `BinaryFileServer` reste le
service qui sert `/uploads/{path}` via `UploadsServeController`.

## [0.9.162] - 2026-09-13

### Ajouté

#### Sortir un contrat en PDF, à n'importe quel moment
La liste des contrats a une action « Exporter en PDF », sur les brouillons
comme sur les contrats scellés. Jusqu'ici le seul PDF du module était celui
frappé à la contresignature : pour faire relire un contrat avant de le sceller,
ou pour en classer un exemplaire papier, il n'y avait rien.

L'action répond trois choses selon l'état, et l'ordre des tests est la sécurité :

- **contrat conclu** : le PDF signé, octet pour octet. Rien n'est régénéré, et
  si le fichier stocké manque c'est un 404, jamais un re-rendu.
- **contrat scellé** : le document tel qu'il a été scellé, imprimé verbatim,
  avec son empreinte.
- **brouillon** : ce que le document dirait aujourd'hui, mentions entre
  crochets comprises.

Les deux derniers sont des copies de travail et le disent : bandeau en première
page, pas de cadre de signature vide, et un nom de fichier suffixé
(`CTR-2026-0001-projet.pdf`). Rien n'est écrit sur le disque, et
`renderProvisional()` refuse un contrat qui a déjà son fichier signé : c'est
la garde qui empêche qu'une route serve un sosie du document signé.

L'adresse `/pdf` garde son sens exact, le fichier signé ou 404, parce que
c'est elle que la page document annonce comme « le PDF signé ».

Vérifié sur les trois états : brouillon rendu avec son bandeau, contrat scellé
rendu avec son bloc d'empreinte et sans cadre de signature, contrat conclu
identique au fichier stocké.

---

## [0.9.161] - 2026-09-13

### Ajouté

#### Chaque liste sait se montrer en cartes
Cinq listes n'avaient aucune forme de rechange : Publications, Présentations,
les contrats scellés, Utilisateurs et Clients. Elles en ont une, qui porte les
mêmes faits dans le même ordre que la ligne qu'elle remplace, avec les actions
dépliées plutôt que repliées derrière un bouton. Une carte a la place, et un
menu dans un menu sur un téléphone est un geste de trop.

Utilisateurs et Clients avaient déjà un repli, qui basculait à 640 pixels de
**fenêtre** : c'est pour cette raison qu'ils débordaient encore de 1024 à 1440.
Les deux suivent maintenant le conteneur comme le reste.

Résultat vérifié sur les sept listes à cinq largeurs : cartes jusqu'à 1280,
tableau à partir de 1440, et aucune liste ne perd ses actions.

### Modifié

#### La documentation dit ce que les écrans font aujourd'hui
La page des trames annonçait cinq actions, il y en a six depuis que
« Consulter » existe, et elle décrivait une colonne Langues qui a laissé la
place à la catégorie. Elle explique maintenant à quoi sert une catégorie, et
pourquoi une trame peut n'en avoir aucune.

Les pages « Écrire une version » et « Sceller un contrat » décrivent l'aperçu :
d'où viennent ses valeurs, pourquoi celles du prestataire sont les vraies, et
pourquoi un champ vide s'affiche vide.

Sept captures d'écran ont été refaites.

### Dans aurora-client
Rien à répercuter.

---

## [0.9.160] - 2026-09-13

### Corrigé

#### Les listes du back-office tiennent enfin dans la place qu'on leur donne
Le menu latéral fait 480 pixels fixes à partir de 1024 et disparaît en dessous.
Une fenêtre de 768 laisse donc 718 pixels à une liste, et une fenêtre de 1024
n'en laisse que 478 : élargir l'écran de 256 pixels coûte 240 pixels au
contenu. Une liste était plus à l'aise sur une tablette que sur un petit
portable.

Conséquence mesurée sur les dix listes : cinq largeurs d'écran sur sept
voyaient au moins un tableau déborder de son cadre, et la colonne qui tombait
dehors était toujours la dernière, Actions, c'est-à-dire la seule raison
d'ouvrir une ligne. Elle restait atteignable par un défilement horizontal que
personne ne pense à essayer.

**Une liste interroge maintenant son conteneur, plus jamais la fenêtre.**
`useListViewMode` mesure l'élément qui porte réellement la liste et bascule en
cartes sous 768 pixels de conteneur. Le seuil est écrit une fois pour toute
l'application : un écran ajouté demain en hérite, et le jour où le menu latéral
change de largeur, aucune page ne casse en silence. Le choix de vue n'est pas
effacé, il est outrepassé tant qu'il ne peut pas être honoré, et revient dès
qu'il y a la place.

**La colonne Actions est épinglée à droite** sur les sept tableaux qui
restent. Le menu d'une ligne est atteignable à toute largeur, y compris quand
le reste du tableau défile.

La bascule entre tableau et cartes disparaît là où elle ne peut rien changer,
plutôt que de rester affichée sans effet.

### Dans aurora-client
Rien à répercuter.

---

## [0.9.159] - 2026-09-13

### Ajouté

#### Une catégorie sur les trames de contrat
Une trame disait de quel **type** elle est, corps ou annexe. Elle dit maintenant
aussi pour quel **métier** : community management, photographie, développement
web. Les deux ne se remplacent pas. Le type décide comment un contrat
s'assemble, la catégorie décide ce qui se range ensemble.

La question se pose le jour où une activité en devient deux. Une bibliothèque
de vingt trames qui couvrent trois métiers ne se lit plus : la liste est une
suite alphabétique où rien n'est à côté de ce qui lui ressemble, et le
sélecteur du contrat propose l'annexe photo pendant qu'on rédige un contrat de
site.

La liste gagne une colonne et un filtre, où « sans catégorie » est un choix à
part entière : « qu'est-ce que je n'ai pas encore rangé » est la question qu'on
se pose en premier. À la création d'un contrat, les trames sont **regroupées**
par métier et non filtrées : un corps écrit pour une activité est parfois le
bon point de départ pour une autre, et un sélecteur qui l'aurait caché serait
un cul-de-sac.

Une trame peut n'avoir aucune catégorie, et c'est volontaire. Une catégorie est
une classification que quelqu'un applique ; une valeur par défaut ferait naître
chaque trame dans un métier que personne ne lui a donné. La migration laisse
donc la colonne vide partout : deviner reviendrait à décider, pour chaque
installation, de quel métier relèvent les contrats de quelqu'un d'autre.

#### Voir une trame comme le client la lira
Écrire un contrat, c'est écrire `{{customer.legal_name}}` et faire confiance.
Ça marche presque toujours, et les fois où ça ne marche pas coûtent cher : un
jeton mal orthographié s'imprime tel quel dans le document signé, une clause
qui se lit bien avec un nom court se casse avec un nom long, une variable qui
ne résout rien laisse un trou que personne ne voit avant le client.

Un bouton **Aperçu** dans l'éditeur ouvre le document rendu, variables
remplacées, exactement comme le scellement l'assemblera. Jusqu'ici la seule
façon de le savoir était de fabriquer un vrai contrat et de le sceller, ce qui
tire une référence, écrit une ligne d'audit et laisse un document à supprimer.

Les valeurs sont celles que le panneau des variables affiche déjà, donc l'aperçu
et le panneau ne peuvent pas se contredire. Sauf celles de votre entreprise,
qui sont les vraies : elles viennent des réglages et c'est ce que le client
lira. Les champs propres à chaque contrat apparaissent entre crochets, parce
qu'il n'y a pas d'exemple à donner pour une case remplie à la création.

Le brouillon est enregistré avant l'ouverture : l'aperçu montre ce qui est à
l'écran, pas ce qui était en base il y a une heure. Un bloc que l'aperçu refuse
de dessiner est un bloc que le scellement aurait refusé aussi, et le rencontrer
ici est précisément l'intérêt.

#### Relire un contrat avant de le sceller
Le même bouton, sur la liste des contrats, pour un contrat en préparation.
Cette fois les valeurs ne sont pas des exemples : ce contrat a un client, un
montant et une date, donc il n'y a rien à inventer. Un champ que la fiche
client laisse vide s'affiche vide, parce que c'est ce que le signataire
lirait, et le voir est précisément l'intérêt.

Seules deux choses ne peuvent pas encore être réelles et s'affichent entre
crochets : la référence, tirée au scellement, et les mentions écrites à la
signature. Un blanc à ces endroits se lirait comme un défaut plutôt que comme
une étape qui n'a pas eu lieu.

L'aperçu emprunte le chemin du scellement : les mêmes parties dans le même
ordre, le même moteur de rendu, la clause de langue ajoutée au même endroit.
Il n'écrit rien et ne tire aucune référence. Les variables inconnues, celles
qui bloqueront le scellement, sont **nommées** au lieu d'être refusées : les
trouver ici ne coûte rien, les trouver au scellement coûte un aller-retour.

Un contrat déjà scellé n'est pas concerné : son document est stocké et haché,
et l'écran qui le montre imprime ces octets plutôt que de les recalculer.

### Dans aurora-client
Une migration à jouer, ce que `make deploy-prod` fait déjà.

---

## [0.9.158] - 2026-09-13

### Ajouté

#### Consulter une trame de contrat sans l'ouvrir pour la modifier
La liste des trames savait tout faire sauf lire. On pouvait ouvrir un
brouillon, dupliquer, renommer, archiver, supprimer, mais la version en vigueur
- celle qui sert à fabriquer les contrats, donc la plus intéressante à relire -
n'avait aucune porte d'entrée.

Les actions d'une ligne commencent maintenant par « Consulter », qui ouvre cette
version en lecture seule. C'est un lien : on peut l'ouvrir dans un nouvel onglet
et garder sa place dans la liste. Le badge vert de la version en vigueur y mène
aussi, comme le badge orange menait déjà au brouillon.

L'action demande le privilège de consultation, pas celui de modification : lire
une trame et la réécrire ne sont pas le même droit.

#### Importer des fichiers dans la médiathèque depuis la ligne de commande
`aurora:ged:import` verse dans la médiathèque des fichiers déjà présents sur le
serveur : un dossier de photographies, le contenu d'un site qu'on déménage, ce
qu'un script a produit.

Le chemin emprunté est exactement celui d'un dépôt par le navigateur : les
octets partent sur le disque actif, la référence est tirée, les variantes
responsives sont fabriquées, la première version est enregistrée et la ligne
d'audit est écrite. Un document importé est indistinguable d'un document
déposé, ce qui n'était pas le cas d'un INSERT écrit à la main.

Le titre est le nom du fichier, rendu lisible. Le texte alternatif n'est pas
deviné : un nom de fichier fait un titre passable et une description inutile, et
un mauvais texte alternatif est pire que pas de texte alternatif, parce qu'un
lecteur d'écran l'annonce avec aplomb. `--dry-run` liste sans rien écrire.

### Dans aurora-client
Rien à répercuter.

---

## [0.9.157] - 2026-09-13

### Modifié

#### Un onglet de la corbeille dit de quel module il parle
« Dossiers », seul, peut désigner trois choses. Dans le menu latéral le mot est
lisible parce qu'il est rangé sous un intitulé de section ; sur un écran qui
montre cinq corbeilles côte à côte, cet intitulé a disparu.

Chaque onglet porte donc le nom de sa section : « GED · Dossiers », « Éditorial
· Publications ». C'est le nom que le menu affiche, alias compris : renommer une
section dans les réglages renomme les onglets. Quand le préfixe répète le
libellé, il n'apparaît pas : « Notes Markdown » reste « Notes Markdown ».

### Ajouté

#### Un lien vers la liste, depuis la corbeille
Restaurer un élément sans voir l'endroit où il revient était ce que la
centralisation avait coûté. Le bandeau de chaque onglet porte maintenant un lien
vers l'écran concerné, et un onglet vide le porte aussi, à la place d'un état
vide qui ne proposait rien.

Le lien n'est montré que si le module en a un : il vient de la source, comme le
reste, et le privilège est déjà celui de l'onglet - il ne peut donc pas mener à
un refus.

---

## [0.9.156] - 2026-09-13

### Corrigé

#### La médiathèque de démonstration montrait des images inventées
Les quatre photos livrées avec le dépôt n'ont jamais été copiées : la fixture
les cherchait deux niveaux au-dessus du projet, ne trouvait rien, et dessinait
un dégradé à la place. Sur toutes les machines, depuis que le dossier est
livré avec le dépôt. Le test voisin, écrit précisément pour attraper ce genre
de dérive, vérifie que les fichiers nommés existent, et il résout la racine
correctement : il passait pendant que la fixture lisait ailleurs.

Le logo et le favicon de démonstration pointent désormais sur un dégradé
plutôt que sur une des photos : un logo est une marque, et un drapeau dans le
coin de chaque écran du manuel se lit comme l'identité du produit.

#### La documentation décrivait une corbeille qui n'existe plus
La centralisation livrée en 0.9.155 a retiré l'onglet Corbeille des
publications ; le manuel l'expliquait encore en cinq étapes, et la capture qui
l'illustrait échouait à se reprendre. La page a déménagé dans **Général**, où
vit maintenant la corbeille, et dit ce qu'elle fait vraiment : supprimer depuis
l'écran où l'on travaille, restaurer depuis un seul endroit.

### Modifié

#### Une présentation de démonstration qu'on peut montrer
Deux decks existaient, six slides en tout, sans accents et avec une slide image
sans image. Le premier est maintenant un vrai exposé de onze slides, avec un
début, une thèse et une fin, et la slide image porte une photo de la
médiathèque. Le second reste court : c'est une trame à dupliquer, et l'habiller
cacherait ce qu'elle est.

Les decks étaient aussi semés après le garde qui arrête la fixture quand des
contrats existent, donc jamais semés sur une instance qui avait déjà tourné une
fois. Ils le sont avant, et une seconde fois ne les double plus.

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
