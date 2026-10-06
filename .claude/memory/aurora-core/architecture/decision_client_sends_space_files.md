---
name: Studio - le client envoie des fichiers à son espace
description: « Envoyer un fichier » de l'onglet Fichiers de la page client, derrière canUpload ; mêmes murs que l'envoi sur un contenu, jamais depuis un aperçu ; rangé comme un dépôt du studio, toujours visible du client, notifié et journalisé
type: project
---

## Règle

Le droit `canUpload` d'un lien d'accès ouvre deux envois : sur un contenu
(`public_space_attachment`) et, depuis le 06/10/2026, **à l'espace lui-même**
(`public_space_file_upload`, `POST /spaces/{selector}/{token}/files`).
L'ordre des murs est le même partout : limiteur `space_guest_upload`,
`assertFromThisPage()` (en-tête `X-Requested-With`), 404 si le lien n'est pas
utilisable, **est un aperçu** ou n'a pas le droit, puis
`UploadPolicy::forSpaceGuests()` (422 avec une phrase).

`SpaceFileManager::uploadAsClient()` range par `SpaceAttachmentUploader` (même
dossier d'espace, brouillon, même support de stockage que le studio), signe par
`addedByClient()` (libellé du lien, `fromClient`, `visibleToClient = true`),
journalise `space_file.sent_by_client`, puis prévient l'équipe par
`SpaceActivityNotifier::clientSentFile()` (type `studio.space.upload`, lien
`?view=files`) après le flush.

## Pourquoi

Audit Studio p8 : la colonne et la relation existaient, la route manquait.
Décision d'Axel : la brancher. Le client envoyait ses logos par courriel faute
de mieux. Un aperçu recopie les droits pour montrer la même page mais ne doit
jamais écrire au nom du client (même règle que les six autres écritures).

## Comment l'appliquer

- Page : `spaceFileUploadPath` dans `PublicSpaceViewBuilder::view()`, `null`
  sans le droit (pas de bouton) ; rendu aussi pour un aperçu, où `PublicSpaceApp`
  désactive le bouton. L'onglet Fichiers existe dès que le chemin est posé.
- Un fichier du client ne se cache pas (`SpaceFileManager::setVisibleToClient()`
  refuse) ; l'écran du studio le marque « Envoyé par le client ».
- Tests : `SpaceClientFileUploadTest` (droit, aperçu, en-tête, politique,
  rangement, deux côtés, notification, journal) ; vitest dans
  `PublicSpaceApp.test.js` et `SpaceFilesView.test.js`.
- Démo : `StudioDemoFixtures::seedClientFile()`, hors `seedSpaces()` pour
  qu'une démo existante le gagne au prochain `make demo`.
