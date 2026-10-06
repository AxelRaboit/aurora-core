---
name: Notes d'un espace client = un espace de notes du module Notes
description: Plus de mur de notes dans Studio - un espace de notes réglé d'ailleurs (managedBy) par espace client, l'équipe synchronisée, l'import Craft dans Notes ; pièges de droits et de migration
type: project
---

## Règle

Les notes d'un espace client **ne vivent plus dans Studio**. Il n'y a plus
d'entité `SpaceNote` : chaque `CustomerSpace` pointe (`noteSpace`, `SET NULL`,
unique) vers **un `NoteSpace` du module Notes**, marqué
`managedBy = 'studio.customer_space'`, sans propriétaire, accès `members`.

- `SpaceNoteSpaceProvider::resolve()` l'ouvre **à la demande** (première note,
  premier import, `POST workspace_space_notes_open`), jamais à la création de
  l'espace client.
- `SpaceNoteSpaceSync::sync()` tient son **nom et ses membres** : référent ->
  `Manager`, membre -> `Editor`, personne d'autre. Appelé par
  `CustomerSpaceManager::update()` ; `delete()` appelle `release()` : l'espace
  de notes part à la corbeille, `managedBy` remis à null, et les
  administrateurs le restaurent (règle « adopts » des espaces sans
  propriétaire).
- Côté Notes, un espace `isManaged()` refuse (409
  `notes.markdown.spaces.errors.managed`) renommer, accès, membres, publier,
  retirer ; le panneau cache ses réglages et porte un badge. On y écrit, on y
  range, on partage une note par lien normalement.
- L'onglet Notes de l'espace client (`SpaceNoteSpaceView.vue`) liste les notes
  et mène à `suite_notes_markdown_show`. **Caché** si le module Notes est
  éteint ou sans `notes.markdown.use` ; `workspace_space_notes_*` est fermé par
  `NotesRouteGateSubscriber`.
- L'import Craft vit dans `src/Module/Notes/Craft/` (routes
  `suite_notes_craft_*`, réglages `suite_notes_craft_*`). Le Markdown de Craft
  entre presque tel quel : `CraftMarkdown` ne défait que ses balises.

## Pourquoi

Le mur de Studio était un second outil de prise de notes, en blocs Editor.js,
à côté du module Notes : ni dossiers, ni liens entre notes, ni historique, ni
recherche. La règle du docblock de `StudioModule` dit qu'un outil appartient à
son module ; décision produit du 06/10/2026 (« un espace de notes par espace
client, ouvert à son équipe »).

## Comment l'appliquer

- **Ne jamais donner de privilège en passant.** Un équipier sans
  `notes.markdown.use` est inscrit à l'espace de notes mais ne voit pas
  l'onglet : c'est voulu, le droit se règle avec les autres.
- Les administrateurs voient **tous** les espaces de notes gérés (ils sont sans
  propriétaire, donc « adoptés ») - cohérent avec Studio où ils voient tous les
  espaces clients.
- Toucher à l'équipe d'un espace client ailleurs que par
  `CustomerSpaceManager` : appeler `SpaceNoteSpaceSync::sync()` après.
- Une note pour soi sur un client va dans **son espace personnel** ; la
  migration y a rangé les anciennes notes personnelles dans un dossier au nom
  de l'espace client.
- La migration `Version20261006190000` (postUp, chiffrement par
  `AURORA_ENCRYPTION_KEY`, blocs -> Markdown par `EditorBlocksToMarkdown`) est
  irréversible et ne se joue **pas** en `--dry-run` (postUp s'exécute quand
  même). Couleur du post-it et libellé d'auteur sont perdus ; une épinglée
  devient favori de son auteur ; une personnelle sans auteur est abandonnée.
- Note client : [docs/aurora-client/MIGRATION_STUDIO.md](../../../../docs/aurora-client/MIGRATION_STUDIO.md),
  sections 7 et 8.
