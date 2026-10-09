---
name: decision_notes_realtime_coediting
description: La co-édition caractère par caractère d'une note se fera **sans service** : les clients tiennent le document, le bus sert de rendez-vous, et le chiffrement au repos est préservé. Activée par espace, jamais sur un espace personnel. Mesures de transfert d'état à l'appui. Tranché le 08/10/2026.
metadata:
  type: project
---

# Notes : la co-édition temps réel se fera sans service, et les clients tiennent le document

## Où on en est

Décidé le 08/10/2026, **rien n'est encore écrit**. Le partage de note (confier à
des personnes, ouvrir un lien en écriture, présence, poussée « la note a
bougé ») est livré - voir [[project_notes_collaboration]]. Ce qui manque est la
co-édition fine : voir le curseur de l'autre et son texte apparaître lettre par
lettre.

L'étape 1 de la feuille de route - la fusion à trois branches - **est livrée**
(commit `e8375deaa`) : deux personnes qui écrivent dans la même note ne se
marchent plus dessus, et c'est le mode dégradé sur lequel toute la suite
s'appuie. L'arbitrage du chiffrement **est tranché**, ci-dessous.

Cette mémoire existe pour que cet arbitrage ne soit pas à refaire, parce qu'il
est contre-intuitif et qu'il décide de tout le reste.

## L'arbitrage, tranché le 08/10/2026

### Ce qui avait été mal posé

Une première rédaction disait qu'un CRDT côté serveur et « seul PHP détient la
clé » sont **incompatibles**, et donc que la propriété « chiffré au repos »
devait tomber. C'est vrai du *merge* - un état Yjs se décode en texte par
quiconque a la bibliothèque, donc un blob chiffré est un blob infusionnable -
mais la conclusion était trop large.

### La règle 1 résout la moitié du problème

**Rule:** le service de co-édition **ne persiste rien**. Le markdown dérivé
repart dans Aurora par la route normale, et le document est jeté.

**Why:** c'est la règle 1 poussée au bout, et ça tient pour une raison propre
aux CRDT : **les clients sont la sauvegarde.** Chaque navigateur détient le
document complet, donc un service qui redémarre en pleine session est réamorcé
sans perte par n'importe quel client encore là. La persistance n'est pas
« acceptable à sacrifier », elle est **inutile** - et sans elle, rien de
lisible ne s'ajoute au repos : pas de second entrepôt contenant les notes en
clair.

### Ce qui reste comme exposition, et qui a été accepté

- **Un process tient en RAM le texte des notes ouvertes à cet instant.** À
  comparer à aujourd'hui : PHP-FPM tient déjà le texte déchiffré en RAM le
  temps d'une requête. L'écart est « une session » contre « une requête ».
- **Le trafic navigateur ↔ service transporte du clair** : TLS obligatoire,
  comme HTTP.
- **Le service n'a aucune idée des permissions d'Aurora.** C'est Aurora qui
  signe un jeton court par (personne, note), que le service vérifie - la forme
  exacte du cookie Mercure déjà construit, avec le même
  `aurora.mercure.token_factory`.

### Le piège que « zéro persistance » ouvre, et qui est refermé

**Rule:** zéro persistance dans le service **ne veut pas dire** écriture
seulement à la fin de la session. Le markdown est réécrit dans Aurora sur un
débounce, comme l'autosave d'aujourd'hui.

**Why:** sans ça, une session de deux heures laisse Postgres deux heures en
retard. Deux conséquences, et la seconde est la pire : l'extrait, la recherche,
la page publique et les liens de partage servent un texte périmé pendant tout
ce temps ; et si le service tombe au même moment que le dernier client, le
travail n'a jamais été écrit nulle part. L'écriture périodique rend ce trou
aussi petit que celui de l'autosave actuelle.

**How to apply:** le service réécrit par la route de sauvegarde normale, avec
la version qu'il a reçue en graine - donc le contrôle de version habituel
s'applique, et une graine périmée est rattrapée par la fusion à trois
branches.

### Le périmètre : par espace, et l'espace personnel jamais

**Rule:** la co-édition s'active **par espace**, et un espace personnel n'est
**jamais** éligible.

**Why:** un espace dit déjà « qui voit ce carnet ». Faire de la co-édition une
de ses propriétés, réglée une fois par qui le gère, évite une décision à
reprendre note par note - et surtout évite deux endroits à consulter pour
savoir si une note est co-éditable, ce qui est le genre de réglage dont on ne
sait plus lequel gagne six mois après. Une dérogation par note a été écartée
pour ça. Le carnet privé, lui, **ne change pas de promesse** : il n'est pas
éligible, point.

**How to apply:** un booléen sur `NoteSpace`, l'inéligibilité du personnel
posée là où `isPersonal()` l'est déjà. Et **la colonne arrive avec le
service**, jamais avant - voir la section « ce qu'il ne faut surtout pas faire
avant ».

## L'architecture retenue : aucun service, et pourquoi elle a changé trois fois

**État au 08/10/2026 au soir : pas de service.** Cette section garde le
raisonnement qui menait à un service, parce qu'il reste juste *pour le
document tant qu'on le croyait persistant* - et parce que la façon dont il
est tombé est l'information la plus utile du fichier.

**Trois fois dans la journée, une contrainte réelle sur une chose a été
élargie à tout ce qui était à côté** : « le chiffrement interdit un CRDT
serveur » (faux dès qu'on ne persiste rien), « Mercure ne peut pas porter
l'awareness » (faux, un curseur n'a pas d'état autoritaire), « il faut un
service pour tenir le document » (faux, les clients le tiennent). À chaque
fois la correction a supprimé du travail. **Devant la prochaine affirmation de
cette forme, mesurer avant de planifier.**

### Le raisonnement d'origine, conservé



**Rule:** l'autorité sur le document vit dans **un process**, jamais répartie
entre des navigateurs.

**Why:** l'alternative tentante est d'utiliser Mercure comme bus et de laisser
les clients être autoritaires avec un bail. Le transport existe pour ça - le
hub **accepte** les publications d'un navigateur (`MERCURE_PUBLISHER_JWT_KEY`
est dans `compose.yaml`), Aurora ne lui a simplement jamais signé de jeton de
publication, et le modèle de confiance tiendrait : droit de publier sur le
sujet d'une note ⟺ droit d'écrire cette note. Mais ça fait de nous les auteurs
d'un problème de systèmes distribués (qui persiste, que se passe-t-il quand
l'hôte ferme son portable, deux hôtes à la fois, blob périmé) dont les pannes
sont celles qu'on ne reproduit jamais : « j'ai perdu mon paragraphe ».

Et conceptuellement, un second service optionnel ne coûte rien de nouveau :
**Aurora en a déjà un avec un mode dégradé documenté**, c'est Mercure. C'est le
même concept une seconde fois, pas un concept en plus.

**Écarté :** le CRDT côté PHP. Pas d'implémentation mûre.

## Les trois règles qui rendent ça pérenne

Elles comptent plus que le choix de la bibliothèque.

1. **Le markdown reste l'enregistrement ; l'état CRDT est un artefact de
   session.** Perdre ou corrompre le blob fait perdre la session, jamais la
   note. C'est ce qui garantit que le nouveau service ne peut pas détruire le
   travail de quelqu'un, et donc ce qui le rend déployable.
2. **Le service est optionnel, avec un vrai mode dégradé** - même forme que
   Mercure. C'est ce qui garde Aurora installable comme simple bundle composer
   chez un client qui n'en veut pas.
3. **Un seul propriétaire de l'état, et c'est un process.** Voir ci-dessus.

## Ce qui sert déjà, et n'est pas à refaire

- **Les droits** : qui peut écrire une note est résolu à la bonne granularité
  et en un seul endroit (`NoteSpaceAccess`). C'est la question la plus pénible
  d'une co-édition, et elle est réglée.
- **Le salon** : `NoteLiveHub` donne un sujet privé par note, un cookie en
  abonnement seul, un battement. C'est exactement le canal dont l'*awareness*
  Yjs (curseurs, sélections) a besoin - c'est le même objet.
- **L'éditeur est un `<textarea>` de markdown brut**, donc une séquence de
  texte plat : le cas facile pour un CRDT, une liaison `y-text` ↔ textarea.

## L'ordre du travail, et pourquoi le palier d'avant est un prérequis

1. ~~**La fusion à trois branches.**~~ **Faite** (`e8375deaa`,
   `composables/noteThreeWayMerge.js`, 17 tests). Ce n'était pas un détour :
   c'est le mode dégradé de la règle 2. Sans lui, une installation sans le
   service aurait gardé un conflit à arbitrer à la main, et le service serait
   devenu obligatoire en pratique.
2. ~~**Trancher l'arbitrage du chiffrement.**~~ **Fait** le 08/10/2026 : zéro
   persistance, périmètre par espace, personnel jamais éligible.
3. ~~**Le service, et les curseurs.**~~ **Les curseurs sont faits**
   (`e27bb8cf1`), et **sans service Node** - la planification lui en
   réservait un, et il n'en avait pas besoin.

   **Rule:** l'awareness passe par Mercure, pas par un service. Le navigateur
   publie son propre curseur, sur un **sujet distinct** de celui du serveur.

   **Why:** « Mercure n'est pas un serveur Yjs » est vrai du *document* - un
   état Yjs doit avoir un propriétaire autoritaire - et ne dit rien d'un
   curseur, qui n'a aucun état dont il faille être autoritaire. Un caret est
   auto-déclaré par nature : seul le navigateur qui le porte sait où il est.
   C'est du pub/sub éphémère, ce qu'est Mercure, et le hub accepte les
   publications d'un navigateur (`MERCURE_PUBLISHER_JWT_KEY`). J'avais tiré du
   blocage sur le document une conclusion trop large, pour la deuxième fois -
   c'est le même biais que sur le chiffrement.

   **Deux sujets, séparés par qui a le droit d'y publier.** Un navigateur qui
   pourrait publier sur le sujet de la note pourrait forger un `changed` avec
   une version bidon ou une liste de présences inventée : rien de destructeur,
   mais deux mensonges que la page croirait. Le grant confié au navigateur ne
   nomme donc que `.../notes/markdown/{id}/awareness`. Ce qu'un client peut y
   forger, c'est son propre curseur, dans une note qu'il écrit déjà.

   **How to apply:** `NoteLiveHub::awarenessGrant()` rend l'adresse, le sujet
   et un jeton de publication de 15 minutes - court parce qu'il vit dans le
   JavaScript de la page et non dans un cookie http-only (publier est un
   `fetch`, pas un `EventSource`). Le cookie d'abonnement couvre **les deux**
   sujets, sinon les navigateurs ne s'entendent pas. Et le dessin passe par
   `caretPositionIn`, sorti de `positionFloatingMenu` : un textarea ne peut
   pas afficher un second caret, donc ils sont dessinés par-dessus, et la
   mesure est la même que celle de la palette slash.

   **Ce qui reste donc à l'étape 4 :** tout le service, son déploiement
   compris. L'étape des curseurs a prouvé le jeton, la reconnexion et le mode
   dégradé sur une charge utile qui ne pouvait rien casser.

4. **Le texte, lettre par lettre** - et **sans service**, décidé le
   08/10/2026 après mesure.

   **Rule:** l'amorçage se fait entre pairs sur le bus. Pas de service Node.

   **Why:** la zéro-persistance a vidé le service de son travail. S'il ne
   garde rien, son seul rôle restant est de tenir le document pendant que des
   gens sont connectés - et **les clients le tiennent déjà, chacun en
   entier**, ce qui est précisément la propriété qui justifiait la
   zéro-persistance. Il ne lui restait qu'à être le point de rendez-vous, et
   on en a un : le bus.

   **Mesuré avant de s'engager** (`yjs` 13.6.33, insertions d'un caractère à
   des positions aléatoires, 3/s, sans pause ni suppression - donc une borne
   haute et non une estimation) :

   | état transféré à un arrivant | octets | en base64 |
   |---|---|---|
   | amorçage, note de 1,8 ko | 1 838 | 2 452 |
   | + 5 min de frappe | 22 503 | 30 004 |
   | + 30 min | 121 004 | 161 340 |
   | + 2 h | 497 759 | 663 680 |
   | recompacté depuis le texte | 29 738 | - |

   La croissance est bornée par la **durée d'une session**, pas par l'âge de
   la note : chaque session repart du markdown, donc d'un état minuscule. Le
   recompactage récupère 94 % mais ne peut se faire que si *tout le monde*
   repart de la nouvelle identité - sinon c'est le piège des historiques
   incompatibles - donc il arrive tout seul, à la session suivante.

   **Et le service n'aurait rien changé à ces octets** : sans persistance il
   tiendrait le même document qui grossit, et l'arrivant tirerait les mêmes
   octets depuis lui au lieu d'un pair. Le risque invoqué pour le garder en
   repli n'est pas un risque qu'il traite.

   **Ce qu'un service achèterait encore**, si le repli devait servir : un
   rendez-vous qui ne dépend pas d'un pair réactif (l'onglet du pair désigné
   peut être gelé), et un tuyau qui n'est pas un POST derrière Apache pour un
   transfert de quelques centaines de kilo-octets.

   C'est **ici** qu'arrive le booléen de co-édition sur l'espace, parce que
   c'est ici que le texte sort d'Aurora - donc la colonne n'est jamais
   inerte. Et ici que l'éditeur gagne ses deux modes.

5. **L'historique d'une session** - finition, **non bloquante**. Instantané
   sur minuterie ou en fin de session, attribution à plusieurs auteurs. Ça ne
   bloque pas l'étape 4 : la réécriture sur débounce passe par la route
   normale, donc `MarkdownNoteHistory::beforeChange()` continue de garder une
   version tous les N minutes. L'historique ne casse pas, il devient seulement
   grossier.

## Ce qu'il ne faut surtout pas faire avant

**Ne pas ajouter les colonnes « pour plus tard »** - ni un état CRDT sur la
note, ni le booléen de co-édition sur `NoteSpace`. C'est exactement le piège
décrit pour `can_write` dans [[project_notes_collaboration]] : une colonne qui
annonce une capacité alors que rien ne la sert est un interrupteur que
quelqu'un bascule, et il va chercher le bug ailleurs. Et dans le cas de la
co-édition, un espace coché « co-éditable » sans service derrière promet aussi
une exposition qui n'existe pas, ce qui est pire qu'une promesse vide. Elles
arrivent avec le service ou pas du tout.

**Et le lien d'écriture invité reste dehors.** Un endpoint non authentifié plus
un droit de publication sur un bus, c'est la combinaison à ne pas faire : les
invités gardent la sauvegarde simple.

## Ce que ça fait perdre, et qu'il faut accepter

- Le conflit par version ne s'applique plus pendant une session CRDT. Il doit
  rester pour les invités et pour les installations sans le service, donc
  l'éditeur garde **deux modes** - ce qu'il a déjà, par construction du salon.
- Les révisions cessent d'avoir un sens par sauvegarde : il faut des
  instantanés sur minuterie ou en fin de session.

## Ce que seuls deux navigateurs ont pu trouver (08/10/2026)

La co-édition a été écrite, relue, couverte par 21 tests unitaires sur le
protocole, un test d'intégration contre un vrai hub Mercure, et elle ne
transportait **aucune frappe**. Les trois défauts, dans l'ordre où ils sont
tombés, et ce qu'ils disent :

1. **La direction manquante.** `useNoteCoedit` écrivait le document vers le
   formulaire et jamais l'inverse. La zone de texte est liée au formulaire,
   pas au document : sans un `watch(text)` qui transforme la frappe en
   opération sur le `Y.Text`, la session démarrait, élisait un client,
   amorçait le document et réécrivait en base - en ne véhiculant rien. Chaque
   pièce était juste **séparément**, et c'est précisément pourquoi aucun test
   sans navigateur ne pouvait le voir. La leçon : quand une fonctionnalité est
   un aller-retour, un test qui n'en parcourt qu'un sens ne prouve pas le
   trajet.

2. **L'interblocage du rendez-vous.** `joinAction(room)` n'amorçait que sur
   une salle vide. Deux personnes qui ouvrent la note avant que l'une ait le
   droit de co-éditer se voient mutuellement : chacune demande l'état, aucune
   n'en a, et l'attente dure autant que la note reste ouverte - sans rien à
   l'écran pour le dire, puisque l'éditeur retombe proprement sur son
   enregistrement automatique. **Le correctif est l'élection** : le client
   désigné amorce quand personne ne répond, et lui seul, parce que deux
   histoires fusionnées dupliquent chaque caractère. Demander d'abord reste
   indispensable : sans cela, un arrivant désigné écraserait l'histoire de
   celui qui tape déjà.

3. **Un test vert pour la mauvaise raison.** La première version passait avec
   la co-édition débranchée : la frappe partait par l'enregistrement
   automatique, le hub poussait « la note a changé », et l'éditeur de l'autre
   rechargeait. Le niveau 2 faisait son travail et le test applaudissait. Il
   faut **fermer les autres routes** - ici refuser
   `POST /suite/notes/markdown/*/update` pendant la frappe - puis vérifier que
   le test rougit quand on retire le correctif. Un test qu'on n'a pas vu
   échouer ne mesure rien de connu.

**Et un piège de mesure, trouvé le 09/10/2026.** Un onglet resté ouvert avec le
compte A répondait aux `doc-request` à la place du A du test : B recevait un
document que le test n'avait jamais amorcé, et la frappe de A semblait ne pas
partir. Le protocole ignore par construction un compte présent deux fois - les
élections comparent des identifiants de compte. Avant de conclure à un défaut,
fermer tout autre onglet ouvert avec les comptes du test, puis écouter le fil
(un `EventSource` enveloppé qui journalise chaque `kind`) plutôt que deviner.

## Le piège d'environnement, qui n'est pas propre aux notes

`symfony server:start` expose lui-même les services de `compose.yaml` à PHP et
**écrase `.env.dev`** : il impose `http://127.0.0.1:3000/...` pour
`MERCURE_URL` *et* `MERCURE_PUBLIC_URL`. Le hub était épinglé sur `localhost`
par son `resource_identifier`, donc l'audience des jetons ne concordait pas et
le hub répondait **401 à tout**. La discussion des espaces clients était
cassée en local pour la même raison, en silence, puisqu'elle retombe sur son
sondage de vingt secondes.

Les trois valeurs doivent dire la même adresse : `resource_identifier` dans
`compose.yaml`, et les deux variables de `.env.dev`. Et un 403 du hub n'est pas
un 401 : jeton valide mais sujet hors de la concession donne **403**.

**L'hôte du cookie, aussi (09/10/2026).** Le jeton d'abonnement voyage dans un
cookie posé par la page : une page servie sur `localhost` ne l'envoie pas à un
hub sur `127.0.0.1`, et le hub répond 401 sans autre indice. Une seconde
instance locale ouverte sur `localhost` (un client à côté de la démo) a donc
besoin d'un hub sur `localhost` ; la démo reste sur `127.0.0.1`, comme son hub.

## L'étape 5, et les deux tiers qui n'étaient pas à faire (08/10/2026)

La feuille de route demandait « un instantané sur minuterie ou en fin de
session, et une attribution à plusieurs à inventer ». À l'examen, deux des
trois morceaux n'avaient rien à construire :

- **L'instantané sur minuterie existait déjà.** La réécriture d'une session
  passe par la route de sauvegarde ordinaire, donc par
  `MarkdownNoteHistory::beforeChange()`, qui ne garde une version que si la
  dernière a plus de `RevisionIntervalMinutes`. Une session d'une heure laisse
  donc une version toutes les N minutes - précisément l'instantané demandé,
  sans une ligne de mécanique en plus. C'est l'intervalle qui rend la session
  supportable pour l'historique : sans lui, une heure ferait mille versions.
- **L'instantané de fin de session n'est pas nécessaire.** Le texte final est
  dans la note, et la prochaine modification le garde en version. Un signal
  « la session est terminée » serait un mécanisme sans utilisateur, et il
  partirait d'un onglet qui se ferme - le moment où une requête part le moins
  bien.
- **L'attribution, elle, était fausse.** Un seul client élu envoie la
  réécriture pour toute la salle : chaque version gardée pendant une session
  portait le nom de celui qui a enregistré, qui peut n'avoir tapé aucun des
  caractères conservés. Ce n'est pas une approximation, c'est le mauvais nom
  sur la pièce.

**Rule:** `MarkdownNoteRevision::$writtenBy` (JSON, nullable) liste
`{id, name}` de tout le monde qui *écrivait* au moment de la version, rempli
par `MarkdownNoteHistory::handsOn()`.

**Why:** trois décisions à ne pas refaire.

1. **Lu de `NotePresence`, jamais de la requête.** « Qui d'autre était là »
   est exactement le genre d'affirmation qu'un navigateur ne doit pas pouvoir
   faire sur le compte d'autrui : une page pourrait sinon mettre le nom d'un
   collègue sur une version qu'il n'a jamais vue. La présence est écrite par le
   battement que chaque page envoie, donc ça marche avec ou sans hub.
2. **Les noms sont recopiés, pas reliés.** Une version est la trace d'un
   instant : le nom qu'on veut est le nom de ce moment-là, et fermer un compte
   ne doit pas retransformer « écrit par deux personnes » en « écrit par une ».
   C'est le raisonnement du libellé de lien de partage, qui aboutit à
   l'inverse - un lien est une ligne qui survit à sa révocation, une salle est
   une liste qui n'existe nulle part ailleurs.
3. **Les lecteurs n'en font pas partie.** La salle contient aussi ceux qui
   lisent, et mettre leur nom sur une version serait une trace pire que pas de
   trace. Seul `editing: true` compte.

**How to apply:** `wasWrittenBySeveralHands()` pour l'affichage,
`getWrittenBy()` pour les noms. Null pour une sauvegarde ordinaire, qui est la
quasi-totalité des lignes - `author` dit déjà qui a enregistré, et répéter ce
nom ailleurs allongerait la trace sans la rendre plus vraie. La démo en montre
une, écrite à deux mains, parce qu'un état qu'on ne voit jamais est un état
dont personne ne connaît l'allure.
