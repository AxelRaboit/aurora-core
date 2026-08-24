---
name: project_planning_share_link_write_access
description: Les liens de partage de calendrier sont en lecture seule par décision. Deux choses manquent volontairement et doivent être faites AVANT d'ouvrir l'écriture : la colonne `can_write` et une limite de débit sur les routes invitées.
metadata:
  type: project
---

## Où on en est

Le partage de calendrier par lien (`core_planning_share_links`, août 2026) est
**en lecture seule, et c'est un choix**, pas un oubli. Un lien porte un libellé,
une échéance, une date de révocation, un horodatage de dernier usage, et pointe
vers un ou plusieurs calendriers. Deux surfaces : une page web
(`/planning/share/{token}`) et un flux `.ics` (`/planning/feed/{token}.ics`).

L'écriture a été explicitement écartée par l'utilisateur : *« enfaite pour le
moment, seulement la lecture, pas l'écriture »*.

## Les deux choses qui manquent volontairement

### 1. Pas de colonne `can_write`

Elle **n'existe pas** dans `core_planning_share_links`, et il ne faut pas
l'ajouter « pour plus tard ».

**Why:** une colonne qui annonce « ce lien peut écrire » alors qu'aucune route
d'écriture n'existe est un piège. Quelqu'un la bascule, rien ne se passe, et il
cherche le bug ailleurs. Le projet préfère anticiper les abstractions saines
([[pref_think_long_term]]), mais une colonne inerte n'est pas une abstraction,
c'est une promesse fausse.

**How to apply:** l'ajouter dans la même migration que le premier endpoint
d'écriture, jamais avant.

### 2. Pas de limite de débit sur les routes invitées

`PlanningShareController` et `PlanningFeedController` sont **non authentifiés**, et
n'ont aucune limite. Acceptable en lecture : la pire conséquence d'un jeton qui a
fuité est que quelqu'un lit un planning, ce que la révocation règle.

**Why:** ça cesse d'être acceptable à la première route d'écriture. Un endpoint
d'écriture non authentifié dont le jeton a fuité se remplit de dix mille
événements, et il n'y a personne à bloquer - le jeton *est* l'identité. La
révocation arrive après les dégâts.

**How to apply:** le projet a déjà `config/packages/rate_limiter.yaml`. Poser la
limite sur la route d'écriture **dans le même commit** qu'elle, pas dans un
suivant.

## Ce qui est déjà tenu, et n'a pas besoin d'être refait

- Le mode fait partie de la recherche : `resolveUsable($token, $mode)` refuse un
  jeton `web` sur la route `.ics` et l'inverse. Sans ça, l'adresse d'une page
  invitée serait aussi un abonnement permanent.
- Les identifiants de calendriers viennent **du lien, jamais de la requête**. Un
  invité ne peut pas élargir sa propre vue. Testé dans
  `PlanningSharePageTest::testAGuestCannotWidenTheirOwnView`.
- Créer un lien exige d'être **propriétaire**, pas d'avoir le droit d'écrire :
  quelqu'un avec qui un calendrier est partagé n'a pas à le publier.
- Toutes les façons d'échouer (inconnu, expiré, révoqué, mauvais mode) rendent la
  même page avec le même 404, pour ne pas confirmer quels jetons ont existé.
- Le jeton n'est jamais journalisé dans l'audit.

## Pourquoi cette mémoire existe

Ces deux manques étaient consignés à un seul endroit chacun : `can_write` dans le
docblock de `Version20260824100000`, et la limite de débit **nulle part** - juste
dite à l'oral. Personne ne lit une migration de six mois avant d'ajouter une
fonctionnalité, et rien ne rappelle une contrainte qui n'est écrite dans aucun
fichier.

Voir aussi : `PlanningShareController`, qui porte un rappel de la limite de débit
à l'endroit où on ajouterait la route.
