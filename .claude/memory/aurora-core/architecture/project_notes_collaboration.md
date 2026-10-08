---
name: project_notes_collaboration
description: Une note Aurora se partage de deux façons - confiée à des comptes nommés, ou ouverte en écriture par lien. Ce que chaque droit ouvre, ce qu'il n'ouvre surtout pas, et pourquoi la co-édition caractère par caractère n'est pas faite.
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

## L'identité de l'invité

**Rule:** une révision écrite par un lien pointe sur le lien (`via_link_id`),
jamais sur une copie de son libellé.

**Why:** il n'y a pas de compte derrière - l'adresse *était* l'identité. Pointer
plutôt que recopier garde l'e-mail du destinataire dans une seule table, et
l'information survit à la révocation, qui est exactement le moment où quelqu'un
demande qui a écrit ça.

**How to apply:** `MarkdownNoteRevision::getAuthorLabel()` rend le nom du compte,
sinon les mots du lien. C'est ce que l'écran d'historique affiche.

## Le temps réel : facultatif, et le même arbitrage que SpaceChat

**Rule:** présence et poussée « la note a bougé » passent par Mercure quand un
hub tourne, et par un battement toutes les 20 secondes quand il n'y en a pas.
Jamais sur le chemin qui enregistre.

**Why:** Aurora est un bundle, et la plupart des installations n'auront jamais
de hub. Absent est un **état supporté**. Donner deux réponses à cette question -
une pour le chat, une pour les notes - serait une raison de se méfier des deux.

**How to apply:** `NoteLiveHub` copie `SpaceChatHub` à la lettre (sujet privé
`https://aurora.invalid/notes/markdown/{id}`, cookie JWT en abonnement seul,
échec avalé dans le log). Le navigateur n'a **jamais** le droit de publier : il
pourrait annoncer aux autres que la note a changé alors que non. Le motif de
publication est déclaré dans `config/services.yaml`, à côté de celui du chat -
les deux se lisent ensemble. `NotePresence` vit dans le cache, jamais en base :
la présence est vraie pendant quarante secondes.

Et la limite assumée : deux battements simultanés peuvent s'écraser l'un
l'autre. Le perdant revient au battement suivant, ce qui pour « qui regarde
cette page » est un clignotement que personne ne voit.

## Ce qui n'est pas fait, et pourquoi

**La co-édition caractère par caractère (curseurs, CRDT) n'est pas faite**, et
c'est une décision d'Axel du 08/10/2026 : *« pour le Niveau 3 on ne le fait pas
pour le moment »*.

Deux blocages réels si ça revient un jour :
- **Mercure n'est pas un serveur Yjs.** C'est de la diffusion serveur → client,
  et Aurora ne donne au navigateur qu'un droit d'abonnement. Faire remonter
  chaque frappe par POST PHP, c'est un aller-retour applicatif par caractère.
  Un `y-websocket` à côté marcherait, mais il ajoute un service obligatoire et
  casse le modèle « bundle Symfony qui s'installe sans rien d'autre ».
- **Le contenu est chiffré** (`EncryptedTextType`). Un état CRDT serait un bloc
  chiffré, mais chaque sauvegarde rechiffre tout le document.

La bonne nouvelle, si ça revient : l'éditeur est un `<textarea>` de markdown
brut, donc une séquence de texte plat - le cas facile pour un CRDT.

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
