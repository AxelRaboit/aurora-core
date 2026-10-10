---
name: Studio - avis aux deux côtés
description: Ce que fait le studio est noté pour le client (ClientNotice, par lien), mailé selon l'espace ; contrats et messagerie préviennent les deux côtés ; adresses recalculées par HMAC plutôt que réémises
type: project
---

## Règle

Depuis la 4.12.0 (10/10/2026), une nouvelle pour le client est un
`ClientNotice` écrit **par lien d'accès**, droits appliqués, par
`ClientNoticeRecorder` (`record()`, `recordForItem()` qui se tait si le client
ne voit pas la carte). La page du client les montre (« Depuis votre dernière
visite ») et les marque vues ; l'email dépend de
`CustomerSpace::clientDigest` (`off` par défaut, `delayed` = 30 min après le
dernier geste, `daily` = 8 h heure du site). Côté studio, rien ne change :
`SpaceActivityNotifier` reste le pendant client → studio.

Un email qui doit porter l'adresse d'un espace ou d'un contrat **ne réémet
jamais le lien** : il porte un jeton calculé, HMAC du secret de l'application
sur le sélecteur et le hash stocké (`SpaceAccessLinkManager::mailToken()`,
`ContractAccessLinkManager::spaceToken()`), accepté par `resolveUsable()`.
Même construction que `aliasToken()`.

## Pourquoi

L'audit du 10/10 (artifact « Avis du Studio ») : le client n'apprenait rien de
ce que faisait le studio, et « Envoyer en relecture » révoquait l'adresse
favorite du client à chaque envoi, parce que le jeton en clair n'existe qu'à la
création. Un récapitulatif toutes les 30 minutes ne pouvait pas faire pareil.

## Comment l'appliquer

- Un nouveau geste du studio qui intéresse le client : appeler
  `$this->clientNoticeRecorder?->record(...)` après le flush, avec un prédicat de
  droits (`approvers()`, `chatters()`, `commenters()`), et ajouter un cas à
  `ClientNoticeTypeEnum` (+ `lineKey()` traduite fr/en/es, `view()`).
- Les nouvelles dépendances des Managers extensibles sont **dernières et
  optionnelles** (`?X $x = null`, appels en `?->`) : `MIGRATION_STUDIO.md` §16.
- Lecture de la messagerie : `SpaceChatReadTracker::markRead()` (upsert
  `ON CONFLICT`, jamais find+persist), appelé par la route `.../read` quand le
  panneau montre un canal ; un compte studio sans marque ne compte que 7 jours.
- Ne pas écrire à un client sans que l'espace l'ait choisi : c'est la règle
  « montrer au client = droit de partager » ([[decision_client_visibility_one_rule]]).
