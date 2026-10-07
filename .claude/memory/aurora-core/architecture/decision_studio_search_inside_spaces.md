---
name: Studio - la recherche globale entre dans les espaces
description: Ressources, fichiers et messages d'un espace dans la recherche globale, sous la règle d'appartenance ; un message seulement dans un salon de la liste du lecteur ; notes laissées au module Notes
type: project
---

## Règle

`StudioSuiteSearchProvider` a trois sections de plus, ouvertes avec celle des
espaces (interrupteur des espaces + `studio.spaces.view`) et bornées par les
mêmes identifiants d'espaces (`SpaceVisibility::seesAll()` ou
`findIdsWhereMember()`) : `space_resources` (libellé, texte, adresse),
`space_files` (titre du document, nom du fichier), `space_messages` (corps du
message). Chacune exclut les espaces à la corbeille ; les fichiers excluent
aussi un document à la corbeille de la médiathèque.

Un message n'est trouvé que dans un salon de **la liste du lecteur** (règle de
`SpaceChatChannelRepository::findForUser()` : canal principal, ou membre et
`hiddenAt IS NULL`), écrite en SQL dans `SpaceChatMessageRepository::search()`.

Les notes d'un espace **ne sont pas** dans Studio : `NotesSuiteSearchProvider`
couvre tous les espaces de notes lisibles (`NoteSpaceRepository::readableSubquery()`),
ceux synchronisés depuis les espaces clients compris.

## Pourquoi

Audit Studio du 06/10/2026 (p7). Être membre d'un espace n'ouvre ni ses canaux
internes ni les conversations privées des collègues : la recherche ne doit pas
montrer une phrase qu'aucun écran ne montrerait. Les notes deux fois auraient
mis la même note sous deux titres.

## Comment l'appliquer

- Liens : `workspace_space_content` avec `view=resources|files|chat` ; pour un
  message, `channel` et `message` en plus. `SpaceChatViewBuilder::view(..., $channelId)`
  n'ouvre le salon que s'il est dans `$rooms` ; `SpaceChatPanel` reçoit
  `focusMessageId` et fait défiler une fois, sur le même `ResizeObserver` que
  le bas de conversation.
- Une nouvelle section dans un espace : même borne (`$spaceIds`, `[]` = rien,
  `null` = tout), `s.deletedAt IS NULL`, `addSelect` de ce que la ligne nomme
  (pas de N+1), `LIMIT` 8, et une entrée dans `studio-search.register.js`
  (clé de libellé écrite en toutes lettres).
- Colonne chiffrée (`EncryptedTextType`) : pas de `LIKE` possible ; la sortir de
  la requête plutôt que tout déchiffrer.
- Tests : `StudioSearchTest` (sections, appartenance, salons, corbeille,
  ouverture du salon nommé).
