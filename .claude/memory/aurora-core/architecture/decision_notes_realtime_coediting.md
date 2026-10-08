---
name: decision_notes_realtime_coediting
description: La co-édition caractère par caractère d'une note passera par un service y-websocket, qui ne persiste rien - les clients sont la sauvegarde, donc le chiffrement au repos est préservé. Activée par espace, jamais sur un espace personnel. Tranché le 08/10/2026.
metadata:
  type: project
---

# Notes : la co-édition temps réel passera par un service, et par un seul propriétaire de l'état

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

## L'architecture retenue : un service `y-websocket`

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
3. **Le service, et les curseurs** - déploiement compris. Le service Node
   arrive et ne relaie **que l'awareness** ; le texte continue de passer par
   la sauvegarde et la fusion de l'étape 1.

   **Rule:** la première livraison du service est celle qui ne transporte
   aucun texte.

   **Why:** un curseur est un décalage, pas du contenu. Rien ne persiste, rien
   ne se réécrit, aucun conflit de version - et le service **ne voit jamais le
   contenu d'une note**, donc l'arbitrage du chiffrement ne s'y applique même
   pas. Tout ce qui est risqué dans un service - le jeton, la reconnexion, la
   supervision, le proxy, le mode dégradé - se règle sur une charge utile qui
   ne peut rien casser. Le déploiement arrive ici parce qu'un service qui se
   livre doit être déployable.

4. **Le texte, lettre par lettre.** Liaison `y-text` ↔ textarea et le contrat
   fixé plus haut. C'est **ici** qu'arrive le booléen de co-édition sur
   l'espace, parce que c'est ici que le texte sort d'Aurora - donc la colonne
   n'est jamais inerte. Et ici que l'éditeur gagne ses deux modes.

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
