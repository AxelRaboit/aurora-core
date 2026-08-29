---
name: project_notes_share_link_read_only
description: Les liens de partage de note sont en lecture seule par décision. Deux choses manquent volontairement et doivent être faites AVANT d'ouvrir l'écriture : la colonne `can_write` et une limite de débit sur les routes invitées.
metadata:
  type: project
---

## Où on en est

Le partage de note par lien (`core_notes_markdown_share_links`, 29/08/2026) est
**en lecture seule, et c'est un choix**. Un lien porte un libellé, une échéance
facultative, une date de révocation, un horodatage de dernier usage, un
destinataire facultatif, et deux interrupteurs pour ce qui accompagne la note :
les sous-notes (arborescence) et les notes liées par `[[…]]`.

Décision reprise telle quelle de [[project_planning_share_link_write_access]] :
même mécanique, même arbitrage, à quelques heures d'écart. Les deux devraient
évoluer ensemble le jour où l'écriture arrive.

## Les deux choses qui manquent volontairement

### 1. Pas de colonne `can_write`

Elle **n'existe pas**, et il ne faut pas l'ajouter « pour plus tard ».

**Why:** une colonne qui annonce « ce lien peut écrire » alors qu'aucune route
d'écriture n'existe est un piège. Quelqu'un la bascule, rien ne se passe, et il
cherche le bug ailleurs. Le projet préfère anticiper les abstractions saines
([[pref_think_long_term]]), mais une colonne inerte n'est pas une abstraction,
c'est une promesse fausse.

**How to apply:** l'ajouter dans la même migration que le premier endpoint
d'écriture, jamais avant.

### 2. Pas de limite de débit sur les routes invitées

`NoteShareController` est **non authentifié** et n'a aucune limite.

**Why:** acceptable en lecture, la pire conséquence d'un jeton qui a fuité étant
que quelqu'un lit une note, ce que la révocation règle. Ça cesse de l'être à la
première route d'écriture : un endpoint d'écriture non authentifié dont le jeton
a fuité se remplit sans qu'il y ait personne à bloquer, puisque le jeton *est*
l'identité. La révocation arrive après les dégâts.

**How to apply:** le projet a `config/packages/rate_limiter.yaml`. Poser la
limite sur la route d'écriture **dans le même commit** qu'elle.

## Ce qui est déjà tenu, et n'a pas besoin d'être refait

- **Rien dans la requête n'élargit la vue.** Les notes qu'un invité peut
  atteindre viennent de `SharedNoteScope`, calculé depuis le lien. Demander un
  autre identifiant répond 404, que la note existe ou non. Testé dans
  `NoteShareTest::testAnUnrelatedNoteIsNotReachableThroughTheToken`.
- **Un lien de la forme `[[Titre]]` n'élargit jamais un partage.** Les liens ne résolvent qu'à
  l'intérieur de la portée ; au-delà, l'ancre est transformée en texte simple.
- **Toutes les façons d'échouer rendent la même page.** Jeton inconnu, expiré ou
  révoqué : une seule réponse, parce que les distinguer dirait à un inconnu
  quelles suppositions étaient proches.
- **La page demande à ne pas être indexée** (`noindex, nofollow, noarchive`).
  Une adresse de partage est un secret ; un moteur qui l'aurait stockée l'aurait
  donnée à tout le monde, définitivement, par-delà toute révocation.
- **Les images passent par une route à jeton** qui résout depuis le propriétaire
  de la note, en réutilisant la protection anti-traversée de la route
  authentifiée plutôt qu'en la réécrivant.
- **La vue est vivante, pas figée** : modifier la note change ce que l'invité
  voit. C'est ce que font les applications de notes, et c'est aussi pourquoi
  `expiresAt` existe.
