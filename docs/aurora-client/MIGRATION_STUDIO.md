# Migration : les présentations de Studio deviennent des livrables

**Version** : la release qui suit la 2.0.0.
**Type** : breaking - entités, droits, réglage, limiteur et routes retirés ;
namespaces, clés de traduction et table déplacés.

Les présentations de Studio (`Deck`) sont fusionnées dans les livrables : une
présentation est désormais un livrable au format `slides`, à côté des livrables
au format `page`. Le moteur de diapositives (entité `Slide`, enums, services,
éditeur, lecteur) reste, mais vit sous `Deliverable/Slides`. Dans la même
release, les catégories de trames de contrat deviennent des lignes gérées à
l'écran, le calendrier éditorial devient une vue des espaces clients et les
trames un onglet des contrats.

> **Données migrées sans intervention.** `make aurora-update` joue les
> migrations qui déplacent tout : `Version20261006100000` (catégories de
> trames), `Version20261006140000` (présentations en livrables, irréversible)
> et `Version20261006150000` (table des diapositives). Ce qui suit concerne
> **le code du projet client** : ce qu'il étend, appelle ou configure.

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
