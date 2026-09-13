<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentCategory\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Ged\DocumentCategory\Dto\DocumentCategoryInputFactoryInterface;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Manager\DocumentCategoryManagerInterface;
use Aurora\Module\Ged\DocumentCategory\Serializer\DocumentCategorySerializerInterface;
use Aurora\Module\Ged\DocumentCategory\View\DocumentCategoriesViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/ged/categories', name: 'backend_ged_categories')]
#[IsGranted('ged.categories.view')]
final class DocumentCategoriesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly DocumentCategorySerializerInterface $serializer,
        private readonly DocumentCategoryManagerInterface $manager,
        private readonly PayloadValidator $payloadValidator,
        private readonly DocumentCategoriesViewBuilder $viewBuilder,
        private readonly DocumentCategoryInputFactoryInterface $inputFactory,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(PaginationRequest $pagination): Response
    {
        return $this->render('@Ged/backend/categories/index.html.twig', $this->viewBuilder->indexView($pagination));
    }

    #[Route('/list', name: '_list', methods: [HttpMethodEnum::Get->value])]
    public function list(PaginationRequest $pagination, Request $request): JsonResponse
    {
        return $this->json($this->viewBuilder->buildListPayload($pagination, $request->query->getBoolean('trashed')));
    }

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.categories.create')]
    public function create(Request $request): JsonResponse
    {
        $input = $this->inputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $category = $this->manager->create($input);

        return $this->jsonSuccess(['category' => $this->serializer->serialize($category)]);
    }

    #[Route('/{id}/restore', name: '_restore', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.categories.delete')]
    public function restore(DocumentCategory $category): JsonResponse
    {
        $this->manager->restore($category);

        return $this->jsonSuccess();
    }

    #[Route('/{id}/force-delete', name: '_force_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.categories.delete')]
    public function forceDelete(DocumentCategory $category): JsonResponse
    {
        $this->manager->forceDelete($category);

        return $this->jsonSuccess();
    }

    #[Route('/empty-trash', name: '_empty_trash', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.categories.delete')]
    public function emptyTrash(): JsonResponse
    {
        return $this->jsonSuccess(['deleted' => $this->manager->emptyTrash()]);
    }

    #[Route('/{id}/update', name: '_update', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.categories.edit')]
    public function update(DocumentCategory $category, Request $request): JsonResponse
    {
        $input = $this->inputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->manager->update($category, $input);

        return $this->jsonSuccess(['category' => $this->serializer->serialize($category)]);
    }

    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.categories.delete')]
    public function delete(DocumentCategory $category): JsonResponse
    {
        $this->manager->delete($category);

        return $this->jsonSuccess();
    }
}
