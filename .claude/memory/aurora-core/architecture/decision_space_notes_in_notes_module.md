---
name: Notes d'un espace client = espace de notes hébergé par Studio
description: Un moteur de notes (module Notes), des espaces hébergés par d'autres modules ; les notes d'un espace client s'écrivent dans l'espace client, par son équipe, sans le droit Notes ; la portée de requête les sépare du module Notes
type: project
---

## Règle

**Un seul moteur de notes, des notes qui vivent chez leur hôte** (décision du
10/10/2026, qui remplace « l'onglet est une porte vers le module Notes » du
06/10/2026).

- Chaque `CustomerSpace` pointe (`noteSpace`, `SET NULL`, unique) vers un
  `NoteSpace` marqué `managedBy = 'studio.customer_space'`, sans propriétaire,
  accès `members`, ouvert **à la demande** (`SpaceNoteSpaceProvider::resolve()`,
  `POST workspace_space_notes_open`), équipe synchronisée par
  `SpaceNoteSpaceSync` (référent -> `Manager`, membre -> `Editor`).
- **Contrat d'hôte** : `Notes\Space\Hosting\NoteSpaceHostInterface` (tag
  `aurora.notes.space_host`), implémenté par `Studio\SpaceNote\Service\CustomerSpaceNoteHost` :
  qui entre (`studio.spaces.view` + `SpaceVisibility::canSee`), le libellé
  (nom de l'espace client), les adresses de page
  (`/workspace/{id}?view=notes&note=__id__`). Notes ne connaît pas Studio.
- **Portée de requête** : `Notes\Space\Hosting\NoteSpaceScope`, posée par
  `NoteSpaceScopeSubscriber` sur toute route `suite_notes_*` :
  - paramètre `notesHost=<clé>:<référence>` -> **Hosted** : un seul espace,
    listes et accès par id (sinon 404) ;
  - images sans le droit Notes -> **Hosts** : espaces hébergés seulement ;
  - sinon -> **Module** : les listes excluent les espaces hébergés, l'accès par
    id reste ouvert (corbeille générale, notifications) et `show`/`read`
    redirigent vers la page de l'hôte ;
  - hors routes du moteur (commandes, corbeille, palette) -> **All**.
  La clause vit dans `readableSubquery`/`writableSubquery`/`grantedSubquery`,
  liée par `bindViewer(..., $scope)` ; `NoteSpaceAccess::roleWith` lit
  `admits()`.
- `HostedNoteSpaceVoter` accorde `notes.markdown.use` **à la requête** quand la
  portée est Hosted/Hosts ; il s'abstient sinon. Le droit n'est jamais donné à
  la personne.
- La section Notes de l'espace client (`SpaceNoteSpaceView.vue`) monte
  `MarkdownNotesApp` avec les props de `MarkdownNotesViewBuilder::indexView()`
  calculées dans `NoteSpaceScope::within(Hosted, …)` : chemins d'API avec
  `notesHost`, `pagePaths` de l'hôte, `fill`, pas de journal ni de page de
  lecture du module.
- Liens vers une note : toujours `NoteAddresses::noteUrl()` (notifications,
  palette), jamais `suite_notes_markdown_show` en dur.

## Pourquoi

Les notes d'un client se mêlaient au carnet personnel dans le module Notes, et
l'onglet de l'espace client renvoyait dans le module : deux modules qui se
partageaient un écran. Axel a choisi « un moteur commun, des modules qui
hébergent leurs notes » (10/10/2026). L'équipe d'un espace client y écrit parce
qu'elle travaille pour le client : l'accès suit l'espace client, plus le droit
Notes (qui reste celui du module).

## Comment l'appliquer

- **Nouveau module qui veut ses notes** : implémenter `NoteSpaceHostInterface`
  (clé = `managedBy`), créer l'espace avec `NoteSpaceManagerInterface::createManaged()`,
  construire l'éditeur dans `NoteSpaceScope::within(Hosted, …)`. Rien à toucher
  dans Notes.
- **Côté client JS** : une adresse d'API peut déjà porter une query ; ajouter
  des paramètres avec `withQuery()` (`@/shared/utils/http/withQuery.js`),
  jamais `` `${path}?q=` ``. Lire l'id d'une adresse avec `idFromAddress()`
  (`@notes/.../noteAddress.js`), jamais une regex sur `/markdown/`.
- Un espace hébergé refuse toujours d'être réglé depuis Notes (409
  `notes.markdown.spaces.errors.managed`).
- Toucher à l'équipe d'un espace client ailleurs que par
  `CustomerSpaceManager` : appeler `SpaceNoteSpaceSync::sync()` après.
- La migration `Version20261006190000` (postUp, irréversible, pas de
  `--dry-run`) reste décrite dans
  [docs/aurora-client/MIGRATION_STUDIO.md](../../../../docs/aurora-client/MIGRATION_STUDIO.md), sections 7 et 8.
