---
name: decision_notes_realtime_coediting
description: La co-édition caractère par caractère d'une note passera par un service y-websocket, pas par Mercure ni par les navigateurs. L'arbitrage décisif n'est pas technique : un CRDT côté serveur et « seul PHP détient la clé » sont incompatibles, et c'est tranché par un opt-in au niveau de la note.
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

Cette mémoire existe pour que **l'arbitrage du chiffrement ne soit pas à
refaire**, parce qu'il est contre-intuitif et qu'il décide de tout le reste.

## L'arbitrage décisif, et il n'est pas technique

**Rule:** un CRDT côté serveur et « seul PHP détient la clé de chiffrement »
sont **incompatibles**. Il faut choisir, explicitement.

**Why:** `AbstractMarkdownNote` chiffre titre et corps par
`EncryptedTextType`, avec une clé d'installation unique dans
`AURORA_ENCRYPTION_KEY` que seul PHP lit. Un état Yjs, lui, se décode en texte
par quiconque a la bibliothèque. Donc :

- soit le service de co-édition détient le texte **en clair** - un process de
  plus dans le périmètre de confiance, et ça affaiblit une propriété que le
  produit annonce en toutes lettres (« people write things there they would not
  put in a document they know is shared ») ;
- soit on chiffre le blob d'état, et alors le service **ne peut plus le
  fusionner**, ce qui annule sa raison d'être.

Il n'y a pas de troisième voie. Ce n'est pas un obstacle d'ingénierie, c'est
une décision produit.

**How to apply:** rendre l'arbitrage visible **au niveau de la chose
partagée**. La co-édition est un opt-in par note ou par espace. Une note que
personne ne co-édite garde la propriété d'aujourd'hui ; une note ouverte à
l'équipe accepte déjà que d'autres la lisent, donc accepter qu'un service la
tienne en vie pendant une session est cohérent avec ce qui a déjà été décidé
d'elle. **Le carnet personnel ne change pas de promesse.**

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

1. **La fusion à trois branches** (~1 jour). Au 409, au lieu de demander
   « écraser ou recharger », fusionner : la base est le texte chargé par le
   formulaire, les deux autres branches sont le mien et le serveur. Le LCS est
   déjà écrit (`composables/noteLineDiff.js`). Deux personnes écrivent rarement
   dans la même phrase, donc ça fusionne tout seul dans le cas courant.
   **Ce n'est pas un détour : c'est le mode dégradé de la règle 2.** Sans lui,
   une installation sans le service garde un conflit à arbitrer à la main, et
   le service devient de fait obligatoire.
2. **Trancher l'arbitrage du chiffrement** ci-dessus et écrire le périmètre de
   l'opt-in.
3. **Le service** : liaison `y-text` ↔ textarea, awareness sur le salon
   existant, blob jetable, instantané en révision à la fin de session.
4. **Le déploiement** : supervision, proxy, cycle de mise à jour, doc côté
   `aurora-client`. C'est le coût permanent, et celui qu'on sous-estime.

## Ce qu'il ne faut surtout pas faire avant

**Ne pas ajouter la colonne d'état « pour plus tard ».** C'est exactement le
piège décrit pour `can_write` dans [[project_notes_collaboration]] : une
colonne qui annonce une capacité alors que rien ne la sert est un interrupteur
que quelqu'un bascule, et il va chercher le bug ailleurs. Elle arrive avec son
service ou pas du tout.

**Et le lien d'écriture invité reste dehors.** Un endpoint non authentifié plus
un droit de publication sur un bus, c'est la combinaison à ne pas faire : les
invités gardent la sauvegarde simple.

## Ce que ça fait perdre, et qu'il faut accepter

- Le conflit par version ne s'applique plus pendant une session CRDT. Il doit
  rester pour les invités et pour les installations sans le service, donc
  l'éditeur garde **deux modes** - ce qu'il a déjà, par construction du salon.
- Les révisions cessent d'avoir un sens par sauvegarde : il faut des
  instantanés sur minuterie ou en fin de session.
