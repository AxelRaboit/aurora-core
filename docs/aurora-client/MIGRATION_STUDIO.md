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
> `Version20261006200000` (visibilité par le client, section 9), puis
> `Version20261006210000` (corbeille des espaces et des contenus, section 10),
> puis `Version20261006220000` (compte utilisateur d'un client retiré,
> section 11). Ce qui suit concerne
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
- **Espace client à la corbeille** : son espace de notes l'y suit, toujours
  réglé par lui (l'écran des notes refuse de le restaurer seul, 409), et
  revient avec lui (section 10). **Espace client supprimé définitivement** :
  son espace de notes reste à la corbeille des notes, redevenu un espace
  ordinaire sans propriétaire, que les administrateurs peuvent restaurer. Les administrateurs voient d'ailleurs
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

## 10. Espaces clients et contenus vont à la corbeille

Supprimer un espace client, ou un contenu de son tableau, le **met à la
corbeille** (`deletedAt`) au lieu de le détruire, comme les livrables. Il
apparaît dans l'écran commun de la corbeille (onglets Studio · Espaces
clients et Studio · Contenus), d'où il se restaure tel qu'il était ; la
suppression définitive et la purge planifiée (délai `TrashAutoPurgeDays`
commun à toutes les corbeilles) font ce que faisait la suppression d'avant.
Clients, trames, contrats et ressources ne changent pas.

- **Données** : `Version20261006210000` ajoute `deleted_at` (et son index) à
  `core_studio_customer_spaces` et `core_studio_space_content_items`. Rien
  d'existant ne passe à la corbeille.
- **Managers, rupture** : `CustomerSpaceManagerInterface::delete()` et
  `SpaceContentItemManagerInterface::delete()` sont retirés. Chacun gagne
  `trash()`, `restore()`, `forceDelete()` (l'ancienne destruction) et
  `purgeTrashedBefore(DateTimeImmutable): int`. Un projet qui appelait
  `delete()` choisit entre `trash()` (le geste de l'écran) et `forceDelete()`.
  Hooks d'audit ajoutés : `auditTrashed()` et `auditRestored()`, actions
  `customer_space.trashed` / `.restored` et `space_content_item.trashed` /
  `.restored` ; `.deleted` ne note plus que la destruction définitive.
- **Entités** : `CustomerSpaceInterface` et `SpaceContentItemInterface`
  gagnent `getDeletedAt()`, `setDeletedAt()` et `isTrashed()`. Une
  implémentation maison les ajoute.
- **Un espace à la corbeille** sort des listes, de la recherche, du tableau de
  bord, du calendrier éditorial et du Planning. Ses écrans répondent 404
  (`SpaceVisibility::canSee()` refuse un espace à la corbeille ;
  `SpaceVisibility::reaches()` est la moitié « appartenance », que la
  corbeille utilise), sa page client et ses liens d'accès répondent comme un
  lien inconnu (`SpaceAccessLinkManager::resolveUsable()`), et les liens de
  lecture de ses livrables aussi. Il **bloque toujours la suppression de son
  client** (`CustomerSpaceRepository::countForCustomer()` compte la
  corbeille) jusqu'à sa suppression définitive.
- **Un contenu à la corbeille** sort du tableau, de la liste, du calendrier,
  des comptes et de la page du client ; son fil et ses fichiers restent
  attachés (plus d'offre de jeter les fichiers à la suppression). Toute route
  qui reçoit le contenu, un de ses messages ou un de ses fichiers en argument
  répond 404 (`SpaceVisibilitySubscriber`). Supprimer une étape ne compte
  plus que les contenus vivants, et range dans la première étape restante
  ceux de la corbeille qui y étaient : c'est là qu'ils reviennent.
- **Routes** : `suite_studio_spaces_restore`, `_force_delete`,
  `_empty_trash` (droit `studio.spaces.delete`, sur un espace de son équipe) ;
  `suite_studio_space_contents_restore`, `_force_delete`, `_empty_trash`
  (`/suite/studio/space-contents/…`, droit `studio.spaces.edit`, sur un
  contenu d'un espace vivant qu'on voit). `suite_studio_spaces_delete` et
  `workspace_space_content_item_delete` mettent à la corbeille.
- **Repositories** : les finders de liste filtrent `deletedAt IS NULL`
  (`findAllOrdered`, `findVisibleTo`, `searchByName`, `findForSpace`,
  `findOnCalendar`, `workloadBySpace`, `searchByTitle`…) ; `findTrashed()`,
  `findAllTrashed()` et `findTrashedBefore()` lisent la corbeille. Une requête
  maison sur ces tables ajoute le filtre.
- **Traductions** : les confirmations de suppression disent « Mettre à la
  corbeille » (`suite.studio.spaces.delete_warning` réécrit,
  `suite.studio.spaces.trash_action`, `suite.studio.space_content.trash_action`,
  `suite.nav.studio_space_contents`). Une surcharge côté client de ces clés
  est à relire.

## 11. Une page par client, seul endroit où sa fiche s'écrit

La fiche d'un client avait **deux formulaires** qui ne portaient pas les mêmes
champs : la fenêtre de l'écran Clients (forme juridique, capital, RCS, TVA,
secteur, représentant, compte) et l'onglet Informations d'un espace (SIREN,
fixe, liens, notes). Le SIREN, le fixe, les liens et les notes ne se
saisissaient donc que depuis un espace. Elle a maintenant **sa page**,
`/suite/studio/customers/{id}` (route `suite_studio_customers_show`, droit
`studio.customers.view`), qui porte tous les champs dans un seul formulaire et
enregistre par `suite_studio_customers_update` (droit `studio.customers.edit`),
avec les mêmes règles qu'avant (email contractuel d'un client signé, SIRET
libre et valide, SIREN valide et accordé au SIRET, liens web). À droite, ses
espaces, ses contrats (avec leur statut) et ses livrables de Studio, selon les
droits du lecteur. On y convertit un prospect et on y supprime la fiche, avec
la garde existante. La liste y mène (nom cliquable, entrée « Ouvrir ») et ne
modifie plus ; la recherche de la suite aussi.

L'onglet Informations d'un espace devient **en lecture** : il montre la fiche
telle que le client la voit (rien ne change sur la page du client) et mène à la
page du client par « Modifier la fiche » à qui a `studio.customers.view` et
`studio.customers.edit`.

Le champ **« Compte utilisateur »** (`Customer.user`) est retiré : il
s'affichait et s'enregistrait, et rien ne le lisait.

- **Données** : `Version20261006220000` retire `core_customers.user_id`, son
  index et sa clé étrangère. Aucun compte n'est touché. Irréversible pour la
  donnée (le `down` recrée la colonne, vide).
- **Route retirée** : `workspace_space_information_save`
  (`POST /workspace/{id}/information/save`) répond 404, et son contrôleur
  `SpaceInformationController` n'existe plus. Un appel maison passe par
  `suite_studio_customers_update`.
- **Route ajoutée** : `suite_studio_customers_show` (`GET`). Le gabarit
  `@Studio/suite/customers/show.html.twig` monte
  `studio/suite/customers/CustomerPageApp`.
- **Réponse de `suite_studio_customers_update`** : `{success, customer}`
  seulement (elle portait aussi `customers`, la liste entière, que plus rien ne
  lisait depuis que la liste ne modifie plus). `_create` ne change pas.
- **DTO, rupture** : `CustomerInformationInput`, son interface, sa fabrique et
  l'interface de sa fabrique sont supprimés. `CustomerInputInterface` gagne
  `getSiren()`, `getLandline()`, `getLinks()` et `getInformationNotes()`, et
  perd `getUserId()` ; `CustomerInput` porte les contraintes (dont
  `validateNumbersAgree()`). Une saisie étendue côté client ajoute les quatre
  méthodes et retire la cinquième. Clés lues par la fabrique : `siren`,
  `landline`, `links` (`[{label, url}]`, une ligne vide tombe),
  `informationNotes` ; `userId` n'est plus lue.
- **Manager, rupture** : `CustomerManagerInterface::updateInformation()` est
  retirée (et l'action d'audit `customer.information_updated` n'est plus
  écrite ; son libellé reste pour l'historique). `applyInput()` écrit aussi le
  SIREN, le fixe, les liens et les notes. `CustomerManager::resolveUser()` est
  retirée et le constructeur ne reçoit plus `UserRepository` : une sous-classe
  qui le surchargeait l'adapte.
- **Entité, rupture** : `CustomerInterface::getUser()` / `setUser()` et la
  propriété `AbstractCustomer::$user` sont retirées.
- **Sérialiseur** : `CustomerSerializer` ne rend plus `userId`, `userName`,
  `userEmail`, et rend `links` et `informationNotes`.
  `CustomerInformationSerializer` (ce que lit le client) ne change pas.
- **Vues** : `CustomersViewBuilder::indexView()` ne rend plus `users` ni
  `updatePath`, mais `showPath` ; `showView()` et `showPayload()` sont
  ajoutées, et le constructeur ne reçoit plus `UserRepository` mais
  `CustomerRelatedViewBuilder`. `SpaceInformationViewBuilder::view()` rend
  `customerPath` (null sans les deux droits) au lieu de `informationSavePath`,
  et `payload()` est retirée. Ce qui entoure un client (contrats, livrables de
  Studio, espaces, chacun `null` quand le lecteur ne peut pas l'ouvrir) se
  calcule dans `CustomerRelatedViewBuilder::related($customer, $exceptSpace)`.
- **Vue.js** : `CustomersApp` perd les propriétés `users` et `updatePath` et
  gagne `showPath` ; la fenêtre de modification est retirée. `CustomerFormFields`
  perd `userOptions` et porte tous les champs ; `useCustomersForm(customers,
  createPath, deletePath)` ne gère plus la modification ;
  `useCustomerRowActions` prend `showPath` et non plus `openEdit` (entrée
  `open` au lieu de `edit`). `SpaceInformationView` prend `customerPath` au
  lieu de `savePath` et n'émet plus `saved`. Nouveaux : `CustomerPageApp`,
  `useCustomerPage`, `customerFormModel.js`, `CustomerDeleteModal`,
  `CustomerRelatedLists`.
- **Traductions** : clés ajoutées sous `suite.studio.customers.*` (`open`,
  `siren*`, `landline*`, `group_links*`, `links*`, `link_*`, `notes*`,
  `page_guide.*`, `group_related`, `related.*`, `read_only`,
  `row_actions.open_description`, et les erreurs `siren_invalid`,
  `siren_mismatch`, `phone_too_long`, `notes_too_long`, `links_too_many`,
  `link_*`). Retirées : `suite.studio.customers.edit`, `linked_account`,
  `account`, `account_none`, `account_hint`,
  `row_actions.edit_description`, et sous `suite.studio.space_information.*`
  tout sauf `scope` (réécrite), `what_the_client_sees`, `group_card`,
  `group_related` et `edit`. Les messages d'erreur de `Siren` et de
  `CustomerLinkInput` passent de `space_information.errors.*` à
  `customers.errors.*`. Une surcharge côté client de ces clés est à déplacer.


## 12. Les présentations entrent dans les espaces clients

Une présentation (livrable au format `slides`) n'était qu'un livrable de
Studio : la créer dans un espace était impossible et la copie vers un espace
répondait `errors.slides_not_in_space`. Elle vit maintenant aussi dans un
espace, avec les droits de l'espace, et le client la lit sur sa page quand on
la lui montre (même règle de visibilité que le reste, section 9).

- **Création dans un espace** : `workspace_space_deliverables_create` lit
  `format` (`page` par défaut, `slides`) et `fromTemplateId` (un modèle de
  Studio vivant, lisible, du même format, module Livrables allumé ; sinon le
  livrable part de zéro, sans refus). Partir d'un modèle passe par
  `DeliverableManager::copyToSpace()`. Nouvelle route
  `workspace_space_deliverables_import` (`POST {title, blocks}`), « Importer un
  texte » dans l'espace, même conversion que Studio (`SlidesFromBlocks`).
- **Copies** : `suite_studio_deliverables_copy_to_space` accepte une
  présentation (diapositives, notes de l'orateur, thème et style copiés), comme
  `workspace_space_deliverables_copy_to_studio` dans l'autre sens. La clé
  `suite.studio.deliverables.errors.slides_not_in_space` est retirée (fr, en,
  es), et `DeliverableManager::copyToSpace()` ne lève plus de `LogicException`
  pour une présentation.
- **Éditeur** : `workspace_space_deliverables_edit` d'une présentation rend
  `@Studio/suite/space-deliverables/slides.html.twig` (l'éditeur de
  diapositives dans la coquille de l'espace), avec
  `DeliverableSlidesViewBuilder::spaceEditorView()`. Les gestes vivent sous
  `/workspace/{id}/deliverables/{deliverableId}/…`, routes
  `workspace_space_deliverables_slides_{presenter,print,appearance,create,update,duplicate,delete,reorder,font_upload}`
  (nouveau `SpaceDeliverableSlidesController`) : `studio.spaces.view` pour lire,
  présenter et imprimer, `studio.spaces.edit` pour écrire, l'espace vu
  (`DeliverableAccess`). Les routes `suite_studio_deliverables_slides_*`
  restent réservées aux présentations de Studio (404 pour une présentation
  d'espace, et réciproquement).
- **Contrôleurs, rupture** : `DeliverableSlidesController` étend désormais
  `AbstractDeliverableSlidesController`, qui porte les gestes communs ; son
  constructeur change (dépôt et règle d'accès d'abord, puis les dépendances du
  parent). `SpaceDeliverablesController` reçoit `DeliverableSlidesViewBuilder`,
  `SlidesFromBlocks`, `EntityManagerInterface` et `StudioContext`, et
  `DeliverableSlidesViewBuilder` reçoit `SpaceDeliverablesViewBuilder`.
- **Aperçu** : `workspace_space_deliverables_preview` d'une présentation rend
  le lecteur de diapositives, sans les notes.
- **Page du client** : `public_space_deliverable` d'une présentation montrée
  rend `@Studio/public/deliverable_slides.html.twig` (sans les notes, avec un
  retour vers l'espace, variable `backUrl` et propriété `backUrl` de
  `PublicDeckApp`) ; une présentation cachée répond 404 comme une page. Les
  lignes `documents` de `PublicSpaceViewBuilder` portent `format`.
- **Liens de lecture** : inchangés, ils servaient déjà le lecteur de
  diapositives quel que soit l'endroit du livrable.
- **Onglet Livrables d'un espace** : `SpaceDeliverablesViewBuilder::view()`
  rend `deliverableImportPath` et `deliverableTemplates` (vide pour qui ne
  peut pas créer dans l'espace ou ne lit pas les livrables de Studio) ;
  `SpaceDeliverablesViewBuilder::templates()` et
  `DeliverableRepository::findLiveStandaloneTemplates()` sont ajoutées.
- **Polices** : `public_deliverable_font` répond tant que le module Livrables
  **ou** les espaces clients sont allumés (avant : le module Livrables seul).
- **Médiathèque** : `DeliverableDocumentUsageProvider` comptait déjà les
  images des diapositives, espace compris ; l'usage d'une présentation d'espace
  mène à son éditeur dans l'espace.
- **Vue.js** : nouveaux `DeliverableFormatFields` (format et modèle, partagé par
  les deux fenêtres de création) et `DeliverableImportModal` ;
  `SpaceDeliverablesView` gagne `importPath` et `templates` ;
  `DeliverableSlidesEditorApp` gagne `space` (réglages d'un livrable d'espace,
  case « Visible par le client » sous le droit de partager) ;
  `settingsPayload()` envoie la visibilité du formulaire au lieu de `false`.
- **Traductions** : `studio.public.space.document_presentation` ajoutée ;
  `suite.studio.deliverables.guide.step_1`, `intro` et `empty_hint` réécrites.
  Une surcharge côté client de ces clés est à relire.

## 13. Un espace se règle dans son onglet Réglages

Le nom, la description, le client, la couleur, le fuseau, le statut, l'équipe
et les rôles d'un espace se modifiaient dans une fenêtre de la liste des
espaces ; l'onglet Réglages de l'espace ne portait que le Drive, et seulement
pour le référent. **L'onglet Réglages rassemble tout** : une section
« Espace » (nom, description, client, couleur, fuseau, statut) pour qui a
`studio.spaces.edit` sur l'espace, une section « Équipe » et la section
« Google Drive » pour le référent et les administrateurs seulement
(`SpaceVisibility::canConfigure()`, la règle d'avant). L'enregistrement passe
par la même route qu'avant, `suite_studio_spaces_update`, donc par
`CustomerSpaceInputFactory`, le validateur, `CustomerSpaceManager::update()`
(équipe refusée à qui n'est pas référent, `SpaceNoteSpaceSync` qui renomme
l'espace de notes) et les mêmes droits. La liste garde « Modifier », devenu un
lien vers `/workspace/{id}?view=settings`, et sa fenêtre ne sert plus qu'à
créer.

- **Vue, ajout** : `CustomerSpacesViewBuilder::settingsView($space)` rend
  `spaceSettings` (espace sérialisé, `canConfigure`, options du formulaire,
  `updatePath`), `null` sans `studio.spaces.edit` ;
  `CustomerSpacesViewBuilder::formOptions()` rend les options du formulaire
  (clients, comptes, statuts, rôles, fuseaux, `canCreateCustomer`) pour la
  liste et l'onglet.
- **Vue, rupture** : `CustomerSpacesViewBuilder::indexView()` ne rend plus
  `updatePath`, et le gabarit `@Studio/suite/spaces/index.html.twig` ne le
  passe plus. Un gabarit surchargé côté client retire la ligne.
- **Contrôleur** : `SpaceContentController` prend un dernier argument
  optionnel `?CustomerSpacesViewBuilder $spacesViewBuilder = null` ; un projet
  qui l'étend avec son propre constructeur le transmet à
  `parent::__construct()`, sinon l'onglet ne porte que le Drive, comme avant.
  La page de l'espace reçoit `spaceSettings`.
- **Vue.js** : l'ancien `CustomerSpace/assets/suite/settings/SpaceSettingsView.vue`
  (le Drive) devient `SpaceDriveSettings.vue`, sans barre de sous-onglets ; le
  nouveau `SpaceSettingsView` porte les sous-onglets (Espace, Équipe, Google
  Drive) et prend `spaceSettings` et `canConfigure`. Nouveau composable
  `useSpaceSettingsForm` (la page se recharge après un enregistrement : l'en-tête
  de l'espace est rendu par le serveur). `CustomerSpaceFormFields` gagne
  `withIdentity` et `withTeam`. `useCustomerSpacesForm(spaces, customers, users,
  createPath, deletePath)` perd `updatePath` et tout l'état de modification
  (`showEdit`, `editForm`, `openEdit`, `submitEdit`…) ; il exporte `formFrom()`
  et `spaceFormRules()`. `useSpaceRowActions` prend `settingsHref` au lieu de
  `openEdit` (l'entrée `edit` est un lien). `CustomerSpacesApp` perd la propriété
  `updatePath`. `SpaceContentApp` gagne `spaceSettings` et montre l'onglet
  Réglages à qui modifie l'espace ou le configure.
- **Traductions** : ajoutées sous `suite.studio.spaces.settings.*`
  (`section_general`, `section_team`, `general_intro`, `team_intro`, `saved`) ;
  `suite.studio.spaces.row_actions.edit_description` réécrite ;
  `suite.studio.spaces.edit` (titre de la fenêtre retirée) supprimée.

## 14. La recherche globale entre dans les espaces

La recherche globale trouvait les espaces et leurs contenus ; elle trouve
maintenant aussi **les ressources** (libellé, texte, adresse), **les fichiers
d'un espace** (titre du document, nom du fichier) et **les messages de la
discussion**. Trois sections de plus, qui s'ouvrent avec celle des espaces
(interrupteur des espaces, droit `studio.spaces.view`) et suivent la même
règle d'appartenance (`SpaceVisibility::seesAll()`, sinon les espaces dont on
est membre). Les notes d'un espace n'y sont pas : elles vivent dans le module
Notes, dont la recherche couvre déjà les espaces de notes ouverts au lecteur.
Aucune migration.

- **Sections** : `space_resources`, `space_files`, `space_messages`, entre
  `space_contents` et `customers`. Un message n'est trouvé que dans un salon
  de la liste du lecteur (le canal « Général », ou un salon où il est invité
  et qu'il n'a pas rangé) : être membre d'un espace n'ouvre pas ses canaux
  internes ni les conversations privées des autres. Rien d'un espace à la
  corbeille, ni d'un document à la corbeille de la médiathèque.
- **Adresses** : une ressource ouvre `/workspace/{id}?view=resources`, un
  fichier `?view=files`, un message `?view=chat&channel={salon}&message={message}`.
  `SpaceContentController::content()` lit `?channel=` et
  `SpaceChatViewBuilder::view()` gagne un quatrième argument optionnel
  `?int $channelId` : le salon nommé s'ouvre s'il est dans la liste du
  lecteur, sinon le premier, comme avant. Le panneau `SpaceChatPanel` gagne la
  propriété `focusMessageId` (lue de `?message=` par `SpaceContentApp`) : le
  message est entouré et amené au milieu de la boîte.
- **Repositories, ajout** : `SpaceResourceRepository::search()`,
  `SpaceFileRepository::search()` (`$term`, `?array $spaceIds`, `$limit`) et
  `SpaceChatMessageRepository::search()` (`$term`, `?array $spaceIds`,
  `CoreUserInterface $reader`, `$limit`), bornés comme les autres sections
  et chargeant l'espace (et le document, le salon) dans la même requête.
- **Rupture** : `StudioSuiteSearchProvider` (classe `final`) prend quatre
  arguments de plus en fin de constructeur (`SpaceResourceRepository`,
  `SpaceFileRepository`, `SpaceChatMessageRepository`,
  `SearchSnippetBuilder`). Un projet qui l'instancie à la main, dans un test
  par exemple, les ajoute.
- **Chiffrement** : aucune colonne cherchée n'est chiffrée, d'où un `LIKE` en
  SQL. Une colonne qui le deviendrait sortirait de la requête plutôt que
  d'être déchiffrée en masse à chaque frappe.
- **Traductions** : `suite.search.sections.space_resources`,
  `.space_files`, `.space_messages` et `suite.studio.space_files.sent_by_client`
  (fr, en, es).

## 15. Le client envoie des fichiers à son espace

Le droit « envoyer des fichiers » d'un lien d'accès (`canUpload`) ne servait
qu'aux fichiers posés sur un contenu. Il ouvre maintenant aussi, sur la page
du client, **« Envoyer un fichier » dans l'onglet Fichiers** : le fichier va à
l'espace lui-même, signé du libellé du lien, rangé comme un dépôt du studio
(brouillon dans le dossier de l'espace de la médiathèque, par le support de
stockage), visible dans l'onglet Fichiers du studio avec la mention « Envoyé
par le client » et **toujours visible du client** (la règle de
`SpaceFileInterface::isShownToClient()`, que le studio ne peut pas défaire).
L'équipe de l'espace reçoit la notification des autres gestes du client et le
journal garde l'envoi. Aucune migration : la colonne `from_client`, la
relation `author_link` et le drapeau `visible_to_client` existaient.

- **Route** : `public_space_file_upload`, `POST /spaces/{selector}/{token}/files`
  (multipart, champ `file`). Mêmes murs que l'envoi sur un contenu : limiteur
  `space_guest_upload` par adresse, en-tête `X-Requested-With` exigé
  (`assertFromThisPage()`), puis 404 pour un lien inconnu, révoqué, expiré, sans
  `canUpload` **ou d'aperçu** (un aperçu recopie le droit pour montrer la même
  page, et n'envoie jamais rien), puis `UploadPolicy::forSpaceGuests()` sur les
  octets (422 avec `studio.public.space.errors.upload_*`). La réponse est
  `{success, spaceFiles}`, la liste telle que la page du client la lit.
- **Vue de la page** : `PublicSpaceViewBuilder::view()` rend
  `spaceFileUploadPath`, `null` sans `canUpload` (pas de bouton). Le gabarit
  `@Studio/public/space.html.twig` le passe à `PublicSpaceApp` ; un gabarit
  surchargé côté client ajoute la ligne. `PublicSpaceApp` gagne la propriété
  `spaceFileUploadPath`, montre l'onglet Fichiers dès qu'il est posé (même
  vide), désactive le bouton dans un aperçu, dit l'erreur de la politique et
  « Envoyé par {auteur} » sous un fichier venu du client ; le mode d'emploi
  gagne l'étape `files_upload`.
- **Manager, ajout** : `SpaceFileManagerInterface::uploadAsClient(SpaceAccessLinkInterface, UploadedFile)`.
  Une implémentation maison l'ajoute. `SpaceFileManager` prend un dernier
  argument optionnel `?SpaceActivityNotifier $notifier = null` ; un projet qui
  l'étend avec son propre constructeur le transmet à `parent::__construct()`,
  sinon le fichier est rangé et journalisé sans prévenir personne. Hook
  d'audit `auditSentByClient()`, action `space_file.sent_by_client`.
- **Notification** : `SpaceActivityNotifier::clientSentFile($space, $auteur, $titre)`,
  type `studio.space.upload` (celui des envois sur un contenu), lien
  `/workspace/{id}?view=files`, regroupée tant qu'elle n'est pas lue.
- **Entité** : `AbstractSpaceFile::addedByClient()` signe du libellé du lien
  (`SpaceAccessLinkLabel::of()`) et non plus de son adresse, comme les
  messages et les fichiers d'un contenu.
- **Écran du studio** : `SpaceFilesView` écrit « Envoyé par le client » à côté
  de l'auteur d'un fichier venu du client.
- **Traductions** : `suite.studio.space_notifications.space_upload`,
  `suite.audit.actions.studio.space_file.sent_by_client`,
  `studio.public.space.files_upload`, `.files_uploaded`, `.files_sent_by`,
  `.files_empty`, `studio.public.space.guide.step_files_upload` (fr, en, es).
