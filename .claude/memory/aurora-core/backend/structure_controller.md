# Controllers - où va quoi

## Règle

### Localisation
- `Controller/Backend/` : endpoints admin sous `/backend/...`
  protégés par `IsGranted` (rôle ou privilege).
- `Controller/Frontend/` : endpoints publics frontend (sans `/backend`),
  pas d'auth requise (souvent locale-aware via `{locale}` dans la route).

### Naming
- `<Plural>Controller` (au pluriel) : `AgenciesController`,
  `PostsController`. Cohérent avec le pluriel de la route
  `/backend/platform/agencies`, `/backend/editorial/posts`.
- Exception : un singulier si le controller gère une seule ressource
  spécifique (ex: `ProfileController`, `DashboardController`).

### Responsabilités du Controller

**Faire** :
- Décoder le JSON de la request (`$this->decodeJson($request)`).
- Appeler la **Factory** du DTO (jamais `XxxInput::fromArray()`).
- Valider via `PayloadValidator->errors($input)`.
- Appeler le **Manager** pour l'action métier.
- Sérialiser la réponse via le **Serializer** (jamais inline).
- Pour les pages admin : déléguer le payload Twig au **ViewBuilder**.

**Ne pas faire** :
- Logique métier (ça va dans le Manager).
- Sérialisation inline `['id' => $entity->getId(), …]` (ça va dans le
  Serializer).
- Appels Doctrine directs (ça va via Repository ou EntityManager dans le
  Manager).
- Hydratation manuelle de DTOs (ça va dans la Factory).

## Squelette canonique

```php
<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Agency\Controller\Backend;

use Aurora\Module\Platform\Agency\Dto\AgencyInputFactoryInterface;
use Aurora\Module\Platform\Agency\Entity\AgencyInterface;
use Aurora\Module\Platform\Agency\Manager\AgencyManagerInterface;
use Aurora\Module\Platform\Agency\Repository\AgencyRepository;
use Aurora\Module\Platform\Agency\Serializer\AgencySerializerInterface;
use Aurora\Module\Platform\Agency\View\AgenciesViewBuilder;
use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/platform/agencies', name: 'backend_platform_agencies')]
#[IsGranted('ROLE_ADMIN')]
class AgenciesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly AgencyRepository $agencyRepository,
        private readonly AgencySerializerInterface $agencySerializer,
        private readonly AgencyManagerInterface $agencyManager,
        private readonly PayloadValidator $payloadValidator,
        private readonly AgenciesViewBuilder $viewBuilder,
        private readonly AgencyInputFactoryInterface $agencyInputFactory,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        return $this->render('@Core/backend/agencies/index.html.twig', $this->viewBuilder->indexView());
    }

    #[Route('', name: '_create', methods: [HttpMethodEnum::Post->value])]
    public function create(Request $request): JsonResponse
    {
        $input = $this->agencyInputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $agency = $this->agencyManager->create($input);

        return $this->jsonSuccess(['agency' => $this->agencySerializer->serialize($agency)]);
    }

    #[Route('/{id}/edit', name: '_update', methods: [HttpMethodEnum::Post->value])]
    public function update(AgencyInterface $agency, Request $request): JsonResponse
    {
        $input = $this->agencyInputFactory->fromArray($this->decodeJson($request));
        // … pareil que create
    }

    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    public function delete(AgencyInterface $agency): JsonResponse
    {
        $this->agencyManager->delete($agency);
        return $this->jsonSuccess();
    }
}
```

## Conventions de routes

- **Backend admin** : **toute** route backend est préfixée par son module
  → `/backend/<module>/` + nom au pluriel (`/backend/editorial/posts`,
  `/backend/crm/contacts`, `/backend/ged/documents`). Le **nom de route**
  suit le même namespacing (`backend_editorial_posts`, `backend_crm_contacts`).
  **Décision 2026-05 (user) : on namespace TOUT**, y compris les pages
  transverses/auth (`/backend/platform/users`, `/backend/configuration/settings`,
  `/backend/platform/login`). Pas d'exception "core reste plat".
  ✅ Migration terminée (2026-05) : tous les modules namespacés. Seules
  exceptions assumées : `backend_dashboard` reste à `/backend` (home), et
  `/backend/password-generator` (outil sans entité). Détail + méthode :
  [[project_url_namespacing_backlog]].
- **Frontend public** : `{locale}` souvent en premier segment
  (`/{locale}/editorial/{postTypeSlug}/{slug}`).
- **Action atomique** : suffixe POST `/_create`, `/_update`, `/_delete`
  pour éviter les méthodes PUT/PATCH (l'admin Aurora utilise POST partout
  pour simplifier les forms).
- **Name de route** : `backend_<plural>_<action>` (ex:
  `backend_platform_agencies_create`).

## Type-hints à respecter

- **Manager** : `<Name>ManagerInterface` (jamais la concrete).
- **Serializer** : `<Name>SerializerInterface`.
- **Input Factory** : `<Name>InputFactoryInterface`.
- **Repository** : `<Name>Repository` (concrete - pas d'interface
  aurora-core, cf [`decision_repository_no_interface.md`](decision_repository_no_interface.md)).
- **Entity dans param converter** : `<Name>Interface` (Doctrine resolve
  fait son boulot via `resolve_target_entities`).

## Traits utiles

- `JsonRequestTrait` : `$this->decodeJson($request)` (decode + 422 si JSON
  invalide).
- `JsonResponseTrait` : `$this->jsonSuccess()`, `$this->jsonInvalidInput()`,
  `$this->jsonFailure()`, `$this->jsonForbidden()`. Statuts HTTP
  standardisés.
- `LocaleTrait` (Front controllers) : `$this->assertActiveLocale($this->context, $locale)`.

## Contrôleurs Frontend - conventions spécifiques

Tout contrôleur `Controller/Frontend/` doit respecter ces règles :

### Route
```php
#[Route('/{locale}/ged', name: 'frontend_ged', requirements: ['locale' => '[a-z]{2}'])]
```
- Préfixe `/{locale}/` obligatoire (2 lettres) - pas de route frontend sans locale.
- `$request->setLocale($locale)` dans chaque action.

### ViewBuilder obligatoire
Le contrôleur injecte un `<Feature>ViewBuilder` frontend (dans `View/Frontend/`) qui :
- appelle `ViewBuilder::baseView($locale)` - fournit `context`, `themeContext`, `locale`, `pageDescription`, `alternates`
- merge avec les données de la page

**Ne jamais** passer `$this->viewBuilder->baseView($locale)` partiel et construire le reste dans le controller.

### `showFrontMenus`
`ViewBuilder::baseView()` a `showFrontMenus: bool = false`. Passer `true` uniquement pour les modules intégrés à la nav principale du site (Editorial, Ecommerce). Modules autonomes (GED, Photo) : laisser à `false`.

### Traits
```php
use LocaleTrait;        // assertActiveLocale($this->context, $locale)
use JsonResponseTrait;  // si endpoint JSON
```
