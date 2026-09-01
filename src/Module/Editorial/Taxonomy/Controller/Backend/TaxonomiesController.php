<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Support\TreeReorderParser;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Editorial\Taxonomy\Dto\TaxonomyInputFactoryInterface;
use Aurora\Module\Editorial\Taxonomy\Dto\TaxonomyTermInputFactoryInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\Taxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Aurora\Module\Editorial\Taxonomy\Manager\TaxonomyManagerInterface;
use Aurora\Module\Editorial\Taxonomy\Serializer\TaxonomySerializerInterface;
use Aurora\Module\Editorial\Taxonomy\View\TaxonomiesViewBuilder;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/editorial/taxonomies', name: 'backend_editorial_taxonomies')]
#[IsGranted('editorial.taxonomies.view')]
class TaxonomiesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly TaxonomyManagerInterface $taxonomyManager,
        private readonly TaxonomySerializerInterface $taxonomySerializer,
        private readonly PayloadValidator $payloadValidator,
        private readonly TaxonomiesViewBuilder $viewBuilder,
        private readonly TaxonomyInputFactoryInterface $taxonomyInputFactory,
        private readonly TaxonomyTermInputFactoryInterface $termInputFactory,
    ) {}

    /**
     * One address per taxonomy: a bare `/taxonomies` redirects to the first
     * rather than showing what `/taxonomies/3` already shows. The empty state
     * renders in place, having nowhere to redirect to.
     */
    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        $first = $this->viewBuilder->firstId();

        if (null !== $first) {
            return $this->redirectToRoute('backend_editorial_taxonomies_show', ['id' => $first]);
        }

        return $this->render('@Editorial/backend/taxonomies/index.html.twig', $this->viewBuilder->indexView());
    }

    /** Digits only, so a literal GET sub-route added later is not swallowed. */
    #[Route('/{id}', name: '_show', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function show(Taxonomy $taxonomy): Response
    {
        return $this->render('@Editorial/backend/taxonomies/index.html.twig', $this->viewBuilder->indexView($taxonomy->getId()));
    }

    #[Route('', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.taxonomies.create')]
    public function create(Request $request): JsonResponse
    {
        $input = $this->taxonomyInputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $taxonomy = $this->taxonomyManager->create($input);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonInvalidInput(['slug' => $invalidArgumentException->getMessage()]);
        }

        return $this->jsonSuccess(['taxonomy' => $this->taxonomySerializer->serializeFull($taxonomy)]);
    }

    #[Route('/{id}/update', name: '_update', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.taxonomies.edit')]
    public function edit(Taxonomy $taxonomy, Request $request): JsonResponse
    {
        $input = $this->taxonomyInputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $this->taxonomyManager->update($taxonomy, $input);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonInvalidInput(['slug' => $invalidArgumentException->getMessage()]);
        }

        return $this->jsonSuccess(['taxonomy' => $this->taxonomySerializer->serializeFull($taxonomy)]);
    }

    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.taxonomies.delete')]
    public function delete(Taxonomy $taxonomy): JsonResponse
    {
        try {
            $this->taxonomyManager->delete($taxonomy);
        } catch (RuntimeException $runtimeException) {
            return $this->jsonFailure($runtimeException->getMessage(), HttpStatusEnum::Conflict->value);
        }

        return $this->jsonSuccess();
    }

    #[Route('/{id}/terms', name: '_term_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.taxonomies.edit')]
    public function createTerm(Taxonomy $taxonomy, Request $request): JsonResponse
    {
        $input = $this->termInputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $this->taxonomyManager->createTerm($taxonomy, $input);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonInvalidInput(['parentId' => $invalidArgumentException->getMessage()]);
        }

        return $this->jsonSuccess(['taxonomy' => $this->taxonomySerializer->serializeFull($taxonomy)]);
    }

    #[Route('/{id}/terms/{termId}/edit', name: '_term_edit', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.taxonomies.edit')]
    public function editTerm(Taxonomy $taxonomy, int $termId, Request $request): JsonResponse
    {
        $term = $taxonomy->findTermById($termId);
        if (!$term instanceof TaxonomyTermInterface) {
            return $this->jsonNotFound();
        }

        $input = $this->termInputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $this->taxonomyManager->updateTerm($term, $input);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonInvalidInput(['parentId' => $invalidArgumentException->getMessage()]);
        }

        return $this->jsonSuccess(['taxonomy' => $this->taxonomySerializer->serializeFull($taxonomy)]);
    }

    #[Route('/{id}/terms/{termId}/delete', name: '_term_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.taxonomies.edit')]
    public function deleteTerm(Taxonomy $taxonomy, int $termId): JsonResponse
    {
        $term = $taxonomy->findTermById($termId);
        if (!$term instanceof TaxonomyTermInterface) {
            return $this->jsonNotFound();
        }

        $this->taxonomyManager->deleteTerm($term);

        return $this->jsonSuccess(['taxonomy' => $this->taxonomySerializer->serializeFull($taxonomy)]);
    }

    #[Route('/{id}/terms/reorder', name: '_term_reorder', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.taxonomies.edit')]
    public function reorderTerms(Taxonomy $taxonomy, Request $request): JsonResponse
    {
        $entries = TreeReorderParser::parse($this->decodeJson($request)['entries'] ?? null);

        try {
            $this->taxonomyManager->reorderTerms($taxonomy, $entries);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonFailure($invalidArgumentException->getMessage(), HttpStatusEnum::Conflict->value);
        }

        return $this->jsonSuccess(['taxonomy' => $this->taxonomySerializer->serializeFull($taxonomy)]);
    }
}
