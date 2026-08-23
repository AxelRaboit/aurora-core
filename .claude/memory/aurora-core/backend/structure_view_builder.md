# ViewBuilder - payload Twig pour les pages admin

## Règle

Pour chaque page admin Twig (typiquement la page liste + détail d'une
entité), il existe un **`<Plural>ViewBuilder`** sous `<Module>/<Feature>/View/`.
Le Controller délègue **toute la construction du payload Twig** au
ViewBuilder.

## Pourquoi

- Le Controller reste **focus sur le flow** (request → manager → response).
- Le ViewBuilder centralise la **récupération + sérialisation des données**
  pour la vue Twig (qui hydrate ensuite le composant Vue admin).
- Réutilisable : un même ViewBuilder peut servir la page index + un
  endpoint JSON `/list`.

## Squelette canonique

```php
<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Agency\View;

use Aurora\Module\Platform\Agency\Repository\AgencyRepository;
use Aurora\Module\Platform\Agency\Serializer\AgencySerializerInterface;

final readonly class AgenciesViewBuilder
{
    public function __construct(
        private AgencyRepository $agencyRepository,
        private AgencySerializerInterface $agencySerializer,
    ) {}

    /** @return array<string, mixed> */
    public function indexView(): array
    {
        return [
            'agencies' => array_map(
                $this->agencySerializer->serialize(...),
                $this->agencyRepository->findAllAlphabetical(),
            ),
            'createPath' => '/backend/platform/agencies',
            'updatePath' => '/backend/platform/agencies/__id__/edit',
            'deletePath' => '/backend/platform/agencies/__id__/delete',
        ];
    }
}
```

## Conventions

- **Nom** : `<Plural>ViewBuilder` (au pluriel, cohérent avec le Controller).
- **Class** : `final readonly` (pas un point d'extension Sylius - interne au
  module). Si l'utilisateur veut customiser un payload, il décore le
  ViewBuilder via `#[AsDecorator]` ou override le template Twig directement.
- **Méthodes** : nommées par "page" : `indexView()`, `detailView()`,
  `editView()`, `buildListPayload()` (pour les endpoints JSON).
- **Retour** : `array<string, mixed>` toujours - le Twig consomme
  directement, pas de DTO de view.

## Patterns courants

### List page avec pagination

```php
public function buildListPayload(PaginationRequest $pagination): array
{
    $result = $this->repo->findPaginated($pagination->page, $pagination->limit, $pagination->search);

    return [
        'success' => true,
        'items' => array_map($this->serializer->serialize(...), $result['items']),
        'total' => $result['total'],
        'page' => $result['page'],
        'totalPages' => $result['totalPages'],
    ];
}
```

Le Controller fait :
```php
public function list(PaginationRequest $pagination): JsonResponse
{
    return $this->json($this->viewBuilder->buildListPayload($pagination));
}
```

### Page index avec data initiale + paths

```php
public function indexView(PaginationRequest $pagination): array
{
    return [
        ...$this->buildListPayload($pagination),  // données initiales + paginées
        'createPath' => $this->urlGenerator->generate('backend_platform_agencies_create'),
        'updatePath' => $this->urlGenerator->generate('backend_platform_agencies_update', ['id' => '__id__']),
        // … autres paths utilisés par le composable Vue
    ];
}
```

Note l'astuce **`__id__`** - placeholder remplacé côté JS par `buildPath()`
quand un user clique sur edit/delete sur un item. Pas besoin de générer
une URL par item dans le ViewBuilder.

### Sub-payloads (referenceable enums, etc.)

```php
public function indexView(): array
{
    return [
        'projects' => […],
        'statuses' => array_map(
            fn (ProjectStatusEnum $case) => ['value' => $case->value, 'label' => $this->translator->trans($case->labelKey())],
            ProjectStatusEnum::cases(),
        ),
        'priorities' => array_map(/* idem */),
    ];
}
```

## Cas avec multiple entités liées

Un ViewBuilder peut consommer plusieurs Repositories + Serializers :

```php
final readonly class TaxonomiesViewBuilder
{
    public function __construct(
        private TaxonomyRepository $taxonomyRepository,
        private TaxonomySerializerInterface $taxonomySerializer,
        private PostTypeSerializerInterface $postTypeSerializer,
        private PostTypeRepository $postTypeRepository,
    ) {}
    // …
}
```

OK tant que les entités sont liées sémantiquement à la page (ici la page
admin Taxonomies a besoin des PostTypes pour le binding many-to-many).

## Frontend ViewBuilder (variante publique)

Pour les contrôleurs frontend (public), le ViewBuilder va dans
`View/Frontend/` et doit :

1. **Injecter `ViewBuilder` (core)** et appeler `$this->baseViewBuilder->baseView($locale)` - fournit `locale`, `context`, `themeContext`, `pageDescription`, `alternates` requis par le layout.
2. **Merger** avec les données de la page via `array_merge()` ou `+`.
3. **Séparer** payload Twig initial (`indexView`) de la logique de données réutilisable par un endpoint JSON (`pageData`).

**Ne pas injecter `Context` ni `ThemeContext`** dans le ViewBuilder frontend - `baseView()` les gère déjà en interne. Les injecter en plus produit des warnings PHPStan ("property only written").

```php
final readonly class DocumentsViewBuilder
{
    public function __construct(
        private DocumentRepository $documentRepository,
        private DocumentSerializerInterface $documentSerializer,
        private ViewBuilder $baseViewBuilder,  // Core\Frontend\View\ViewBuilder - suffit
    ) {}

    public function indexView(string $locale, int $page, string $searchPath): array
    {
        return array_merge($this->baseViewBuilder->baseView($locale), [
            ...$this->pageData($page, null),
            'searchPath' => $searchPath,
        ]);
    }

    public function pageData(int $page, ?string $search): array
    {
        $result = $this->repository->findPaginated($page, 20, search: $search, status: Published);
        return [
            'items' => array_map($this->serializer->serialize(...), $result['items']),
            'page'  => $result['page'],
            'totalPages' => $result['totalPages'],
            'total' => $result['total'],
        ];
    }
}
```

Le contrôleur frontend n'injecte plus ni repo ni serializer - juste le ViewBuilder frontend.

## Anti-patterns

- ❌ Construire le payload Twig **directement dans le Controller**. Le
  payload doit aller dans le ViewBuilder.
- ❌ ViewBuilder qui contient de la logique métier (calculs, transitions).
  Utiliser un Service ou Manager.
- ❌ ViewBuilder qui appelle l'EntityManager directement. Utiliser un
  Repository.
- ❌ Contrôleur frontend qui injecte repo + serializer directement sans
  ViewBuilder - viole la séparation HTTP flow / data preparation.
