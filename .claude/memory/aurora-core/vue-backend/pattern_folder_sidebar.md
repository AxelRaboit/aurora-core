---
name: pattern-folder-sidebar
description: Pattern Media-style pour ajouter une sidebar arborescence de dossiers sur la page d'index d'une entité, sans casser la page d'admin /folders existante.
metadata:
  type: feedback
---

Quand une entité d'aurora-core a (a) une liste paginée d'items et (b) une notion
de dossier (Media, Ged/Document, etc.), la convention est d'offrir **deux points
d'accès** :

1. **Page d'admin dédiée** `/backend/<module>/folders` - gestion lourde
   (drag&drop reorder, modals create/edit/delete avec arborescence complète).
   Inchangée par cette convention.
2. **Sidebar sur la page liste** `/backend/<module>/<entities>` - accès rapide
   pour naviguer/filtrer + raccourcis CRUD légers.

**Why** : utilisateur peut naviguer par dossier sans quitter la liste (filtrage
visuel rapide via clic), tout en gardant la page d'admin pour la maintenance.
La duplication apparente est en fait un trade-off ergonomique validé (cf. choix
explicite de l'utilisateur en 2026-05-30 sur GED documents, option « complète
façon Media »).

**How to apply** :

**Côté backend** (PHP) :
- Sur le `Manager` de l'entité : ajouter `move(Entity, ?Folder)` + `bulkMove(ids, ?Folder)`
  + `auditMoved()` (les hooks `protected` doivent être surchargeables, cf
  [[convention_extensibility]]).
- Sur le `Controller` : routes `POST /{id}/move` et `POST /bulk-move`. Type-hint
  l'interface du manager (`<Entity>ManagerInterface`).
- Sur le `Repository` : `countGroupedByFolders(): array<int,int>` (map folderId → count).
- Sur le `Repository::findPaginated()` : flag `bool $rootOnly = false` pour
  filtrer `folder IS NULL` (la sidebar « Home » s'en sert).
- Sur le `FolderSerializer` : méthode `withDocumentCounts(array): static` qui
  retourne un clone avec le map de counts (mirror de `MediaFolderSerializer::withMediaCounts`).
  **Mettre à jour le test** `array_keys()` car la clé `documentCount` (ou
  `<entity>Count`) est ajoutée à la signature serializer.
- Sur le `ViewBuilder` : passer counts dans `indexView()` ET dans `buildListPayload()`
  (la sidebar refresh les badges via la réponse `/list`). Plus `movePath`,
  `bulkMovePath`, `folderCreatePath`/`folderEditPath`/`folderDeletePath`/`folderMovePath`
  (ces 4 derniers réutilisent les routes du `/backend/<module>/folders` existant).

**Côté Vue** : 5 composables co-localisés sous `assets/backend/<entities>/composables/` :
- `use<Entity>Navigation.js` - refs `currentFolderId`, `allDocumentsView`, `rootOnly`,
  history.pushState, `navigateTo/Root/All`, `onListResponse` callback pour
  `useListPage({ onData })`, et `extraParams()` qui s'intègre via
  `combinedExtraParams()` aux filtres existants (le sidebar gagne sur le filtre
  multiselect dossier, qui est retiré).
- `use<Entity>SidebarTree.js` - tree + flat list (collapse-aware), favoris,
  collapse persistés en `localStorage` sous `aurora-<module>-favourite-folders` /
  `aurora-<module>-collapsed-folders`. Réutilise `@/shared/utils/tree/folderTree.js`
  (`buildFolderTree` + `flattenFolders`).
- `use<Entity>SidebarFolders.js` - modal create/edit/delete des dossiers, branché
  sur les endpoints `/backend/<module>/folders/{create,update,delete}` existants.
  **Doit appeler `reload?.()`** après chaque save pour refresh les counts via le
  prochain payload `/list`.
- `use<Entity>DragDrop.js` - drag d'un item sur un dossier (MIME `application/x-aurora-<entity>`)
  + drag d'un dossier sur un dossier (MIME `application/x-aurora-<entity>-folder`).
  Utiliser **deux MIME distincts** pour ne pas confondre les drops.
- `use<Entity>BulkMove.js` - bulk-move modal indépendant du bulk-delete existant.

**Côté layout** (`<Entity>App.vue`) :
- Header sticky : breadcrumb (rebuilt depuis `currentFolder` + `folders`) ou
  badge « Tous les <entités> » + search + bouton primary create.
- `flex flex-col lg:flex-row gap-4` : `<aside class="lg:w-72 shrink-0">` avec
  favoris en haut + arbre + boutons CRUD au hover + `<main class="flex-1 min-w-0">`.
- Sur les cards items : `draggable="true"` + `v-on:dragstart`.
- Sur les rows folder : `draggable + dragstart + dragover + drop` ; sur
  l'`AppNavListItem` du folder, prop `:drag-over="dragOverFolderId === folder.id"`
  pour le ring d'indication.
- Le filtre multiselect dossier est **retiré** (la sidebar le remplace).

**Visibilité mobile des actions dossier** : les boutons hover-only (favori /
éditer / supprimer à droite de chaque row dossier) doivent utiliser
`opacity-100 lg:opacity-0 lg:group-hover:opacity-100` et **pas**
`opacity-0 group-hover:opacity-100` tout court. Raison : pas de hover sur
tactile, donc en mobile/tablette les actions seraient inatteignables. Le
breakpoint `lg:` matche celui qui fait passer la sidebar en colonne
(`lg:flex-row` côté layout), donc cohérent. La règle s'applique à toute
sidebar arborescente future ; le précédent `MediaApp.vue` qui l'avait
introduite a depuis été supprimé en Phase 5 du merge Media → GED.

**Filtres en mobile** : les chips de filtre (catégorie, tag, status, type,
bouton reset) doivent passer en `w-full sm:w-auto sm:min-w-44` dans un
container `flex flex-col sm:flex-row sm:flex-wrap`. Empile verticalement
en pleine largeur en dessous de `sm:`, retrouve le layout horizontal
à partir de `sm:`.

**Translations** (`backend.<module>.<entities>.*`) : `moved`, `bulk_moved`,
`bulk_move`, `all_documents`/`all_<entities>`, `root_folder`, `subfolders`,
`folders_section`, `favourites`, `favourite`, `unfavourite`, `new_folder`,
`edit_folder`, `delete_folder_confirm`, `folder_name`, `folder_name_placeholder`,
`parent_folder`, `expand`, `collapse`, `move`. Cf
`src/Module/Ged/translations/messages.{fr,en}.yaml` pour la liste canonique.

**Précédents** :
- `src/Module/Media/Library/` + `src/Module/Media/assets/backend/media/` - source du pattern.
- `src/Module/Ged/Document/` + `src/Module/Ged/assets/backend/documents/` -
  application du pattern en 2026-05-30 (gardant `/backend/ged/folders` intact).
