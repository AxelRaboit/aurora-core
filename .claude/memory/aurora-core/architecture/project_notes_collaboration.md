---
name: project_notes_collaboration
description: Une note Aurora se partage de deux façons - confiée à des comptes nommés, ou ouverte en écriture par lien, éventuellement en co-édition en direct (bêta, case par lien). Ce que chaque droit ouvre, ce qu'il n'ouvre surtout pas, et comment la présence se montre. Le protocole de co-édition vit dans decision_notes_realtime_coediting.
metadata:
  type: project
---

## Où on en est

Depuis le 08/10/2026, une note se partage de **deux** façons, et les deux
n'ouvrent pas la même chose :

1. **Confiée à des personnes nommées** - `core_notes_markdown_note_members`,
   rôle `reader` ou `editor`. L'invité ouvre **cette note et rien d'autre de
   son espace**. Géré depuis la modale de partage de la note.
2. **Ouverte en écriture par lien** - colonne `can_write` sur
   `core_notes_markdown_share_links`, route `notes_share_save`. Le porteur de
   l'adresse réécrit le texte, **sans compte**.

Remplace la mémoire `project_notes_share_link_read_only`, qui disait que le
partage de note était en lecture seule par décision. Les deux prérequis qu'elle
posait avant d'ouvrir l'écriture **ont été tenus dans le même commit** :
`can_write` est arrivée avec sa route, et `notes_share_write` a été posée dans
`config/packages/rate_limiter.yaml` en même temps.
[[project_planning_share_link_write_access]] garde la même décision pour les
calendriers : **Planning est toujours en lecture seule**, et c'est le seul
endroit où les deux partages ont cessé d'évoluer ensemble.

## La règle dure : écrire le texte ≠ décider du sort de la note

**Rule:** un droit accordé sur une note ne touche que **son texte** (titre,
corps, tags, bannière). Tout ce qui décide d'où elle vit - classer, déplacer,
mettre à la corbeille, purger, dupliquer, en faire un modèle - reste à
l'espace.

**Why:** un invité qui pourrait déplacer la note dans son propre espace
l'aurait simplement prise. Et un invité qui pourrait vider la corbeille
détruirait une ligne d'un espace qu'il ne voit même pas. La frontière n'est pas
cosmétique : c'est elle qui fait que « je t'ai partagé une page » ne veut pas
dire « tu peux l'emporter ».

**How to apply:** deux méthodes sur `NoteSpaceAccess`, et **une seule** par
route :
- `canWriteNote()` / `writableNote()` → le texte. Inclut le grant par note.
- `canAdministerNote()` / `administrableNote()` → le sort de la note. **Espace
  seul, jamais le grant.** Utilisée par move, delete, force-delete, duplicate,
  template, restore, le refresh Craft, `reorder()`, les renommages de tags en
  masse, et la création de liens de partage.

Se tromper de méthode ne casse aucun test existant : ça ouvre juste une porte.
Le test qui l'attrape est
`NoteSharedWithPeopleTest::testAnEditorGrantDecidesNothingAboutTheNote`.

## Les trois choses qu'un lien d'écriture ne peut pas faire

**Rule:** un lien en écriture écrit **sa propre note**, jamais sa portée.

**Why:** `includeLinked` suit les `[[liens]]` de façon transitive et atteint la
moitié d'un carnet en trois sauts. Cet interrupteur élargit ce qu'un partage
*montre* ; le donner aussi à l'écriture, c'est confier un carnet entier à une
case à cocher.

**How to apply:** `MarkdownNoteShareLinkInterface::canWriteNote()` est le seul
endroit qui répond, et il compare l'identifiant de la note à celle du lien.
Testé par `NoteShareWriteTest::testAWritingLinkWritesItsOwnNoteAndNoOtherInScope`.

Deux autres absences volontaires, pour la même raison - elles atteignent
d'autres lignes que la note :
- **Les `[[liens]]` ne sont pas réécrits** après un changement de titre par un
  invité : cette réécriture modifie *d'autres* notes, dans un espace que
  l'invité ne voit pas.
- **Les images orphelines ne sont pas effacées** : un invité qui supprime un
  paragraphe détruirait des fichiers pour de bon.

C'est pour ça que la route invitée passe par `MarkdownNoteManager::updateText()`
et pas par `update()` : `applyInput()` applique un input entier, donc la même
charge utile pourrait reclasser la note ou changer ses tags.

**Ce que la page de l'invité montre (09/10/2026).** Les trois vues de
l'éditeur - édition seule, découpé, aperçu seul - avec le même contrôle
(`AppTab` segmenté) et le même choix mémorisé (`useEditorPaneMode`), sans le
découpé sous 768 px (`share/shareEditorView.js`). L'aperçu se calcule dans le
navigateur : la charge envoyée reste `title`, `content`, `version`, donc rien
de plus n'est ouvert à une route sans compte.

## Un lien peut ouvrir la co-édition en direct (bêta, 09/10/2026)

**Rule:** une case par lien, **« Co-édition en direct »**, éteinte par défaut,
proposée seulement sous « Autoriser la modification » et marquée **Bêta**
(`AppBetaBadge`). Cochée, quiconque a l'adresse écrit la note avec les autres,
lettre par lettre, façon Google Docs - **même une note de l'espace
personnel**. Décochée, le lien écrit comme avant : taper, puis enregistrer.

**Why:** la même journée, Axel avait d'abord tranché « pas de direct par
lien », puis l'a demandé en voyant un invité taper sur un texte périmé - avec
une case par lien et désactivée par défaut, pour que ce soit un choix. Les
quatre objections d'origine ont chacune leur réponse :
- **pas de compte** → `NoteGuestIdentity` : un id **émis par le serveur**, au-dessus
  d'un milliard, dans un cookie limité au lien, posé **dès l'affichage de la
  page** (le battement le créait deux fois → un invité fantôme) ; un cookie hors
  plage est remplacé ;
- **les noms de l'équipe** visibles par l'invité → accepté, comme Google Docs ;
  l'invité, lui, n'a ni nom ni libellé de lien dans la salle (souvent une
  adresse), chaque écran dit « Invité » dans sa langue ;
- **la limite de débit** → `notes_share_coedit_write` (1 200/h) pour la
  réécriture d'une session, `notes_share_live` (600/h) pour le battement, toutes
  deux apportées par `AuroraBundle` ; la grande limite n'est honorée que sur un
  lien dont la case est cochée ;
- **l'élection** → les ids d'invités dépassant tous les comptes, **un compte est
  toujours élu avant un invité** : dès qu'un compte est dans la salle, c'est sa
  sauvegarde normale (fusion à trois branches) qui réécrit ; la route invitée
  n'écrit que pour une salle d'invités.

**How to apply:** `NoteCoediting::isCoeditable()` est la seule réponse - espace
qui l'autorise **ou** lien utilisable avec la case cochée - et elle part dans
les **deux** battements (`coediting`), pour que propriétaire et invités entrent
dans la même salle. La page de partage réutilise `useNoteLive` et
`useNoteCoedit` tels quels, avec `notes_share_live` comme battement : deux
copies d'un protocole finiraient par ne plus s'entendre. En direct, elle
s'ouvre sur le champ, le titre n'est pas modifiable (le document partagé est le
corps) et « Enregistrer » disparaît. Tenu par `NoteShareLiveTest` et
`tests/e2e/notes-share-coediting.spec.js`.

Écrire à plusieurs avec un **compte** reste possible et préférable quand on
connaît la personne : confier la note, rôle `editor`.

**Un conflit garde le brouillon à l'écran** (09/10/2026). Avant, la page fermait
le champ, et le « Recharger » proposé perdait ce que l'invité avait tapé. C'est
l'enregistrement qui se ferme : un nouvel essai serait refusé pareil. Les textes
d'aide (`share.editing_hint`, `share.conflict`) disent exactement ça.

## L'identité de l'invité

**Rule:** une révision écrite par un lien pointe sur le lien (`via_link_id`),
jamais sur une copie de son libellé.

**Why:** il n'y a pas de compte derrière - l'adresse *était* l'identité. Pointer
plutôt que recopier garde l'e-mail du destinataire dans une seule table, et
l'information survit à la révocation, qui est exactement le moment où quelqu'un
demande qui a écrit ça.

**How to apply:** il n'y a **pas** de `getAuthorLabel()` sur
`MarkdownNoteRevision`, et son absence est voulue : le nom du compte
(`getAuthor()`) est ouvert à qui peut lire la note, les mots du lien
(`getLinkLabel()`) seulement à qui peut l'administrer - l'adresse du
destinataire appartient à qui a créé le lien. C'est le contrôleur qui tranche,
avec `canAdministerNote()`, parce que c'est le seul endroit qui sait de quels
yeux il s'agit. Fondre les deux en une méthode est exactement le défaut que la
revue de sécurité du 08/10/2026 a corrigé.

Une troisième colonne depuis le 08/10/2026 : `writtenBy`, la liste des
personnes qui écrivaient au moment où la version a été gardée. Remplie côté
serveur depuis `NotePresence`, jamais depuis la requête, et seulement quand
elles étaient plusieurs. Voir [[decision_notes_realtime_coediting]].

## Le temps réel : facultatif, et le même arbitrage que SpaceChat

**Rule:** présence et poussée « la note a bougé » passent par Mercure quand un
hub tourne, et par un battement toutes les 20 secondes quand il n'y en a pas.
Jamais sur le chemin qui enregistre.

**Why:** Aurora est un bundle, et la plupart des installations n'auront jamais
de hub. Absent est un **état supporté**. Donner deux réponses à cette question -
une pour le chat, une pour les notes - serait une raison de se méfier des deux.

**How to apply:** `NoteLiveHub` copie `SpaceChatHub` à la lettre (sujet privé
`https://aurora.invalid/notes/markdown/{id}`, cookie JWT en abonnement seul,
échec avalé dans le log). Le navigateur ne publie **jamais** sur le sujet de la
note : il pourrait annoncer aux autres que la note a changé alors que non.
Depuis la 4.1.0 il publie sur un second sujet, `…/{id}/awareness`, et seulement
là (curseurs et document partagé) - voir [[decision_notes_realtime_coediting]].
Le motif de publication est déclaré dans `config/services.yaml`, à côté de
celui du chat - les deux se lisent ensemble. `NotePresence` vit dans le cache, jamais en base :
la présence est vraie pendant quarante secondes.

Et la limite assumée : deux battements simultanés peuvent s'écraser l'un
l'autre. Le perdant revient au battement suivant, ce qui pour « qui regarde
cette page » est un clignotement que personne ne voit.

## La présence à l'écran

**Rule:** qui d'autre a la note ouverte se montre en **pile de visages**
(`NoteCollaborators.vue`, sur le modèle de la cellule d'équipe de Studio et
d'`AppAvatar`), jamais en compte. Chaque visage porte la couleur de
l'étiquette du curseur de la personne, et son infobulle dit son nom, si elle
écrit ou lit, et si la salle est en direct ou sondée.

**Why:** le but est d'éviter que deux personnes réécrivent le même paragraphe,
et « 2 personnes » ne le permet pas. La couleur partagée relie un visage de
l'en-tête et un curseur dans le texte sans lire de nom.

**How to apply:** une seule source pour la couleur,
`composables/collaboratorColor.js`. `collaboratorColor()` (28 % de luminosité)
pour tout ce qui porte du texte blanc - le blanc y tient 4,5:1 sur les 360
teintes, mesuré par le test ; `collaboratorCaretColor()` (45 %) pour la barre
seule, qui doit se voir sur le thème sombre. À 45 %, une étiquette jaune
tombait à 1,9:1.

## La co-édition caractère par caractère

**Faite et livrée en 4.1.0 (08/10/2026)**, sans service, activée par espace.
Les deux blocages qu'énumérait cette section (« Mercure n'est pas un serveur
Yjs », « le contenu est chiffré ») ont été levés ou contournés : tout le
raisonnement est dans [[decision_notes_realtime_coediting]].

## Ce qui est déjà tenu, et n'a pas besoin d'être refait

- **La corbeille ne s'élargit pas.** `MarkdownNoteRepository::trashOf()` utilise
  la règle de l'espace seule : une note confiée n'apparaît jamais dans la
  corbeille de l'invité, qui pourrait sinon la détruire - et à qui ça
  révélerait l'existence de ce que l'espace lui cache.
- **Un invité ne peut pas re-partager.** Ni ajouter quelqu'un, ni créer un
  lien : le partage ne se propage pas.
- **Les deux règles de visibilité sont définies une fois chacune**, et jointes
  par un `OR` dans `MarkdownNoteRepository::visibleTo()` : les espaces par
  `NoteSpaceRepository::readableSubquery()`, les notes confiées par
  `MarkdownNoteMemberRepository::grantedSubquery()`.
- **L'écran sait quels droits il a par note.** Le serveur envoie
  `sharedNotes` (`id => rôle`) : avant ça la page retombait sur « éditable »
  par défaut pour un espace inconnu, donc une sauvegarde qui échoue après la
  frappe.
- **Le toggle `modules_notes_collaboration`** ferme la liste d'invités et la
  route d'écriture invitée. Il ne retire **pas** une note à quelqu'un à qui
  elle a déjà été confiée.
