# Migration : Studio après la 2.0.0 (livrables, notes d'espace)

**Version** : la release qui suit la 2.0.0.
**Type** : breaking - entités, droits, réglages, limiteur et routes retirés ;
namespaces, clés de traduction et tables déplacés.

Les présentations de Studio (`Deck`) sont fusionnées dans les livrables : une
présentation est désormais un livrable au format `slides`, à côté des livrables
au format `page`. Le moteur de diapositives (entité `Slide`, enums, services,
éditeur, lecteur) reste, mais vit sous `Deliverable/Slides`. Dans la même
release, les catégories de trames de contrat deviennent des lignes gérées à
l'écran, le calendrier éditorial devient une vue des espaces clients, les
trames un onglet des contrats, et **les notes d'un espace client passent dans
le module Notes**, avec l'import Craft (sections 7 et 8).

> **Données migrées sans intervention.** `make aurora-update` joue les
> migrations qui déplacent tout : `Version20261006100000` (catégories de
> trames), `Version20261006140000` (présentations en livrables, irréversible),
> `Version20261006150000` (table des diapositives), `Version20261006160000` à
> `Version20261006180000` (colonnes des notes, clés des réglages Craft, lien
> de l'espace client vers son espace de notes) et `Version20261006190000`
> (notes d'espace déplacées dans Notes, irréversible), puis
> `Version20261006200000` (visibilité par le client, section 9). Ce qui suit concerne
> **le code du projet client** : ce qu'il étend, appelle ou configure.
>
> `Version20261006190000` chiffre ce qu'elle écrit : **`AURORA_ENCRYPTION_KEY`
> doit être dans l'environnement de la commande**, sans quoi elle s'arrête
> avant d'avoir rien écrit. Ne pas la jouer en `--dry-run` : Doctrine exécute
> aussi son `postUp` à blanc.

## 1. Entités des présentations retirées

| Retiré | Remplacé par |
|---|---|
| `DeckInterface` / `Deck` | `DeliverableInterface` / `Deliverable` au format `slides` (`DeliverableFormatEnum::Slides`) |
| `DeckCategoryInterface` / `DeckCategory` | `DeliverableCategoryInterface` / `DeliverableCategory` (fusion par nom) |
| `DeckShareLinkInterface` / `DeckShareLink` | `DeliverableLinkInterface` / `DeliverableLink` (même jeton, même mot de passe) |

Leurs managers, repositories, sérialiseurs, contrôleurs, `DecksViewBuilder`,
source de corbeille, purge planifiée et fournisseur d'usage GED sont partis
avec elles, comme les trois entrées de `resolve_target_entities`. Un projet qui
substituait l'une de ces entités porte son extension sur l'entité de livrable
correspondante, et retire la ligne de son propre `resolve_target_entities`.

## 2. Le moteur de diapositives déménage

| Avant | Après |
|---|---|
| `Aurora\Module\Studio\Deck\Entity\{Slide,SlideInterface,AbstractSlide}` | `Aurora\Module\Studio\Deliverable\Slides\Entity\...` |
| `Aurora\Module\Studio\Deck\Enum\*` | `Aurora\Module\Studio\Deliverable\Slides\Enum\*` (noms et valeurs inchangés) |
| `Aurora\Module\Studio\Deck\Service\*` | `Aurora\Module\Studio\Deliverable\Slides\Service\*` |
| `Aurora\Module\Studio\Deck\Repository\SlideRepository` | `Aurora\Module\Studio\Deliverable\Slides\Repository\SlideRepository` |
| `Aurora\Module\Studio\Deck\Serializer\DeckSerializer` | `Aurora\Module\Studio\Deliverable\Slides\Serializer\SlidesSerializer` |
| `Aurora\Module\Studio\Deck\Import\DeckFromBlocks` | `Aurora\Module\Studio\Deliverable\Slides\Import\SlidesFromBlocks` |
| `src/Module/Studio/Deck/assets/suite/decks/` | `src/Module/Studio/Deliverable/assets/suite/slides/` |
| `vue_component('studio/suite/decks/X')` | `vue_component('studio/suite/slides/X')` |
| `@Studio/public/deck.html.twig` | `@Studio/public/deliverable_slides.html.twig` |
| `@Studio/suite/decks/presenter.html.twig` | `@Studio/suite/deliverables/slides_presenter.html.twig` |
| `@Studio/suite/decks/print.html.twig` | `@Studio/suite/deliverables/slides_print.html.twig` |
| clés `suite.studio.decks.*` | clés `suite.studio.deliverables.slides.*` (fr, en, es) |
| table `core_deck_slides` | table `core_studio_deliverable_slides` |
| séquence `seq_core_deck_slide_id` | séquence `seq_core_deliverable_slide_id` |

- Un projet qui substituait `Slide` met à jour l'import de `SlideInterface`
  dans son `resolve_target_entities`.
- Un gabarit surchargé côté client (`templates/Module/Studio/...` ou
  `src/Module/Studio/templates/...`) suit le nouveau chemin, sinon il n'est
  plus lu.
- Une traduction surchargée sous `suite.studio.decks.*` passe sous
  `suite.studio.deliverables.slides.*`, puis `make translation`.
- Du SQL écrit à la main qui nomme la table ou la séquence suit le nouveau
  nom. Les identifiants des diapositives n'ont pas changé.

## 3. Droits et interrupteur

- Les droits `studio.decks.*` n'existent plus : la migration les a convertis
  en `studio.deliverables.*`, sans doublon. `studio.deck_categories.manage`
  disparaît (les catégories suivent le droit de modifier les livrables). Tout
  `isGranted('studio.decks.…')`, `#[IsGranted]` ou `requiredPrivilege` du
  projet passe à `studio.deliverables.…`.
- L'interrupteur `modules_studio_decks` disparaît (`ModuleParameterEnum::StudioDecks`,
  `StudioContext::areDecksEnabled()`), du réglage général comme des modules
  coupés de chaque personne. Qui avait les présentations sans les livrables
  reçoit les livrables. L'entrée de menu `suite_studio_decks` est retirée des
  entrées masquées, de l'ordre et des alias.
- Le libellé de l'interrupteur `StudioContracts` est désormais
  `suite.nav.studio_contracts` / `suite.nav.studio_contracts_description`
  (au lieu des clés des trames) : une surcharge de traduction suit.

## 4. Limiteur `deck_share_password` retiré

Plus rien ne le câble. Retirer sa clé de `config/packages/rate_limiter.yaml`
du projet : la laisser configure un limiteur que personne n'utilise. Le
limiteur des liens de lecture reste `deliverable_password`.

## 5. Adresses et routes

- `/decks/{jeton}` et `/decks/{jeton}/unlock` répondent en **301** vers
  `/deliverables/{jeton}` : une adresse déjà envoyée continue de marcher. Les
  routes `public_deck_show` et `public_deck_unlock` n'existent plus.
- Les routes de la suite `suite_studio_decks*`, `suite_studio_deck*` et
  `suite_studio_deck_fonts` / `suite_studio_deck_font_upload` sont retirées ;
  l'écran des présentations est celui des livrables (`suite_studio_deliverables`,
  filtre « Présentations »). Les polices importées sont servies par
  `public_deliverable_font` (`/deliverables/fonts/{id}`).
- Le calendrier éditorial devient `suite_studio_spaces_calendar`
  (`/suite/studio/spaces/calendar`, données sous
  `suite_studio_spaces_calendar_items`). L'ancienne adresse
  `/suite/studio/calendar` répond en 301, paramètres compris. Il n'a plus
  d'entrée de menu : on l'ouvre depuis l'écran des espaces.
- Les trames de contrat n'ont plus d'entrée de menu (`suite_studio_contract_templates`
  reste une route) : elles sont un onglet de l'écran des contrats.

## 6. Catégories de trames : une entité au lieu d'un enum

`ContractTemplateCategoryEnum` est retiré. Les catégories sont des lignes
gérées à l'écran : `ContractTemplateCategoryInterface` /
`ContractTemplateCategory` (table `core_contract_template_categories`,
séquence `seq_core_contract_template_category_id`, entrée
`resolve_target_entities`).

- `ContractTemplateInterface::getCategory()` / `setCategory()` prennent et
  rendent un `?ContractTemplateCategoryInterface`.
- `ContractTemplateInputInterface::getCategory()` devient `getCategoryId(): ?int`.
- La migration crée une catégorie par métier encore utilisé par une trame
  (« CM », « Photographie », « Développement web ») et y range ses trames ;
  une installation neuve commence sans catégorie.

## 7. Les notes d'espace passent dans le module Notes

Les notes d'un espace client (`Studio/SpaceNote`, onglet Notes d'un espace)
n'étaient pas des notes du module Notes : un mur à part, en blocs Editor.js.
Elles vivent désormais dans un **espace de notes du module Notes**, un par
espace client, ouvert à son équipe : le référent le gère, un membre y écrit.
L'onglet Notes de l'espace client en liste les notes et mène à l'éditeur des
notes.

| Retiré | Remplacé par |
|---|---|
| `SpaceNoteInterface` / `SpaceNote` / `AbstractSpaceNote` | `MarkdownNoteInterface` / `MarkdownNote`, dans l'espace de notes de l'espace client |
| `SpaceNoteVisibilityEnum` | l'espace de notes (équipe) ou l'espace personnel de l'auteur (pour soi) |
| `SpaceNoteInput*`, `SpaceNoteManager*`, `SpaceNoteRepository`, `SpaceNoteSerializer*` | `MarkdownNoteInput*`, `MarkdownNoteManager*`, `MarkdownNoteRepository`, `MarkdownNoteSerializer*` |
| `SpaceNoteDocumentUsageProvider` (usage GED `studio.space_note`) | rien : une image citée par une note Markdown n'est plus comptée comme usage |
| `MarkdownToBlocks` | `Aurora\Module\Notes\Craft\Service\CraftMarkdown` (Markdown vers Markdown) |
| table `core_studio_space_notes`, séquence `seq_core_space_note_id` | tables du module Notes |
| entrée `SpaceNoteInterface` de `resolve_target_entities` | à retirer du `resolve_target_entities` du projet s'il la substituait |

- **Routes retirées** : `workspace_space_notes_create`, `_update`, `_pin`,
  `_delete`, `_image`, `_craft`, `_craft_import`, `_craft_refresh`. Il reste
  `workspace_space_notes_open` (POST), qui ouvre l'espace de notes la
  première fois ; elle se ferme avec le module Notes.
- **Nouveau** : `CustomerSpaceInterface::getNoteSpace()` / `setNoteSpace()`
  (colonne `note_space_id`), `SpaceNoteSpaceProvider` (ouvre l'espace de notes
  à la demande) et `SpaceNoteSpaceSync` (nom et équipe tenus à jour).
  `CustomerSpaceManager` prend un dernier argument optionnel
  `?SpaceNoteSpaceSync $noteSpaces = null` : un projet qui étend ce manager
  avec son propre constructeur le transmet à `parent::__construct()`, sinon
  l'espace de notes ne suit plus l'équipe.
- **Côté Notes** : `NoteSpaceInterface` gagne `getManagedBy()` /
  `setManagedBy()` / `isManaged()` (colonne `managed_by`), et
  `NoteSpaceManagerInterface` gagne `createManaged()`, `syncManaged()` et
  `releaseManaged()`. Un espace réglé d'ailleurs refuse, depuis l'écran des
  notes, qu'on le renomme, change son accès, y inscrive quelqu'un, le publie ou
  le retire (409 `notes.markdown.spaces.errors.managed`) ; on y écrit et on y
  partage une note par lien comme ailleurs. Une implémentation maison de
  `NoteSpaceInterface` ou de `NoteSpaceManagerInterface` ajoute ces méthodes.
- **Droits** : aucun n'est donné. Un membre d'équipe sans `notes.markdown.use`
  est inscrit à l'espace de notes mais ne voit pas l'onglet Notes ; le module
  Notes éteint, l'onglet disparaît pour tout le monde.
- **Espace client supprimé** : son espace de notes part à la corbeille des
  notes, redevenu un espace ordinaire sans propriétaire, que les
  administrateurs peuvent restaurer. Les administrateurs voient d'ailleurs
  tous les espaces de notes des espaces clients, comme ils voient tous les
  espaces clients.
- **Ce que la migration garde** : titre, texte (blocs convertis en Markdown),
  auteur, dates, document Craft d'origine, ordre du mur (épinglées d'abord).
  Une note épinglée devient un favori de son auteur. Une note personnelle va
  dans l'espace personnel de son auteur, dans un dossier au nom de l'espace
  client ; une note personnelle sans auteur (compte supprimé) est abandonnée,
  et la migration en donne le compte. **Perdus** : la couleur du post-it et le
  libellé d'auteur figé.
- **Traductions** : `suite.studio.space_notes.*` est réduit à l'onglet
  (`guide`, `add`, `untitled`, `open_in_notes`, `empty*`, `closed*`) ; les
  clés du mur, du formulaire, des visibilités et des erreurs sont retirées,
  comme les libellés d'audit `studio.space_note.*` et l'usage GED
  `studio_space_note`. Une surcharge côté client de ces clés est à retirer.

## 8. L'import Craft passe dans Notes

| Avant | Après |
|---|---|
| `Aurora\Module\Studio\SpaceNote\Craft\Service\CraftClient` | `Aurora\Module\Notes\Craft\Service\CraftClient` |
| `Aurora\Module\Studio\SpaceNote\Craft\Service\CraftNoteImporter` | `Aurora\Module\Notes\Craft\Service\CraftNoteImporter` (`import(space, folder, author, documentId, title)`, `refresh(note)`) |
| `Aurora\Module\Studio\SpaceNote\Craft\Setting\*` | `Aurora\Module\Notes\Craft\Setting\*` |
| `Aurora\Module\Studio\SpaceNote\Craft\Controller\Suite\CraftSettingsController` | `Aurora\Module\Notes\Craft\Controller\Suite\CraftSettingsController` |
| route `suite_studio_craft_settings` (`/suite/studio/craft/settings`) | `suite_notes_craft_settings` (`/suite/notes/craft/settings`) |
| routes d'import sous `workspace_space_notes_craft*` | `suite_notes_craft_documents`, `suite_notes_craft_import`, `suite_notes_craft_refresh` |
| réglages `suite_studio_craft_enabled`, `_endpoint`, `_token` | `suite_notes_craft_enabled`, `_endpoint`, `_token` |
| clés `suite.studio.craft.*` | `notes.craft.*` (fr, en, es) |
| `src/Module/Studio/assets/suite/settings/CraftTab.vue` | `src/Module/Notes/assets/suite/settings/CraftTab.vue` |

- **Les réglages survivent** : `Version20261006170000` renomme les trois clés
  en gardant leurs valeurs (le jeton reste chiffré avec la même clé). Un
  déploiement qui écrit ces réglages hors de l'écran (script, `.env` relu par
  une commande maison) suit les nouveaux noms.
- **L'import arrive dans une note Markdown** : le Markdown de Craft entre
  presque tel quel (ses balises propres sont défaites par `CraftMarkdown`), et
  les images sont recopiées dans le compartiment de l'espace de notes, plus
  dans la médiathèque. Rafraîchir une note la remet sur la version actuelle du
  document en gardant titre, dossier, étiquettes et bandeau ; le texte remplacé
  entre dans l'historique de la note.
- **Où** : « Importer depuis Craft » dans le menu de chaque espace de notes du
  panneau des notes, et dans l'onglet Notes d'un espace client ; « Mettre à
  jour depuis Craft » dans le menu d'une note copiée de Craft. L'onglet Craft
  des réglages n'est montré que tant que le module Notes est allumé.
- `MarkdownNoteInterface` gagne `getCraftDocumentId()` / `setCraftDocumentId()`
  (colonne `craft_document_id`) et `MarkdownNoteManagerInterface`
  `markImportedFromCraft()` : une implémentation maison les ajoute.

## 9. Une seule règle de visibilité par le client

Tout ce qu'un espace client peut montrer au client naît **caché**, et le
montrer ou le cacher demande le droit `studio.spaces.share` (celui des liens
d'accès) **en plus** de `studio.spaces.edit`. La règle a un nom :
`Aurora\Module\Studio\CustomerSpace\Security\ClientVisibility`
(`PRIVILEGE`, `canShowOrHide()`, `allowsChange()`).

| Élément | Avant | Après |
|---|---|---|
| Étape du tableau | visible par défaut, droit `edit` | cachée par défaut ; un espace neuf montre ses étapes « À valider » (Relecture), « Programmé » et « Publié » ; droit `share` |
| Ressource | cachée par défaut, droit `edit` | cachée par défaut, droit `share` |
| Livrable d'espace | caché par défaut, droit `edit` | caché par défaut, droit `share` (bouton de la liste et case de l'éditeur) |
| Canal de discussion | interne par défaut, droit `edit` | interne par défaut (« Général » reste montré), droit `share` |
| Fichier d'espace | toujours visible | `visibleToClient`, caché par défaut ; un fichier envoyé par le client reste visible |

- **Données** : `Version20261006200000` passe le défaut de
  `core_studio_space_content_columns.visible_to_client` à `false` (les étapes
  existantes gardent leur état) et ajoute
  `core_studio_space_files.visible_to_client`, écrit `true` sur les lignes
  existantes puis `false` par défaut. Rien de ce que le client voyait ne
  disparaît.
- **Routes** : la visibilité d'une ressource, d'un livrable d'espace, d'un
  canal (`_channel_audience`) et d'un fichier (nouvelle route
  `workspace_space_files_visibility`, `POST {visible: bool}`) répond 403 sans
  `studio.spaces.share`. Créer une étape, une ressource ou un canal déjà
  montré, ou changer la visibilité par le formulaire d'une étape, d'une
  ressource ou par l'éditeur d'un livrable, répond 403 de la même façon ;
  un enregistrement qui ne change pas la visibilité passe avec `edit`.
- **Valeur par défaut des DTO** : `SpaceContentColumnInput::$visibleToClient`
  vaut `false`, et la fabrique ne lit `true` que d'un vrai booléen. Un appel
  maison qui créait une étape sans le champ obtenait une étape visible ; il
  obtient une étape cachée.
- **Interfaces** : `SpaceFileInterface` gagne `isVisibleToClient()`,
  `setVisibleToClient()` et `isShownToClient()` ;
  `SpaceFileManagerInterface` gagne `setVisibleToClient()`. Une implémentation
  maison les ajoute. `SpaceFileRepository::findShownForSpace()` est ce que lit
  la page du client.
- **Projet client** : un rôle qui modifiait les espaces sans `share` ne montre
  plus rien au client ; donner `studio.spaces.share` à qui doit le faire.

