<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Support\Arr;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Editorial\PostType\Dto\PostTypeFieldInputFactoryInterface;
use Aurora\Module\Editorial\PostType\Dto\PostTypeInputFactoryInterface;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Editorial\PostType\Entity\PostTypeFieldInterface;
use Aurora\Module\Editorial\PostType\Manager\PostTypeManagerInterface;
use Aurora\Module\Editorial\PostType\Serializer\PostTypeSerializerInterface;
use Aurora\Module\Editorial\PostType\View\PostTypesViewBuilder;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/editorial/post-types', name: 'backend_editorial_post_types')]
#[IsGranted('editorial.post_types.view')]
class PostTypesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly PostTypeManagerInterface $postTypeManager,
        private readonly PostTypeSerializerInterface $postTypeSerializer,
        private readonly PayloadValidator $payloadValidator,
        private readonly PostTypesViewBuilder $viewBuilder,
        private readonly PostTypeInputFactoryInterface $postTypeInputFactory,
        private readonly PostTypeFieldInputFactoryInterface $postTypeFieldInputFactory,
    ) {}

    /**
     * Sends the reader to the first post type, so the listing has exactly one
     * address per record rather than a bare `/post-types` that shows the same
     * thing as `/post-types/3`. Same arrangement the settings page took in
     * 0.9.29, and for the same reason: two ways to address one view is one too
     * many.
     *
     * Renders the empty state instead when there is nothing to redirect to.
     */
    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        $first = $this->viewBuilder->firstId();

        if (null !== $first) {
            return $this->redirectToRoute('backend_editorial_post_types_show', ['id' => $first]);
        }

        return $this->render('@Editorial/backend/post-types/index.html.twig', $this->viewBuilder->indexView());
    }

    /**
     * The digit requirement is not decoration: `/{id}` with none would swallow
     * any literal GET sub-route added here later, silently, because Symfony
     * matches in declaration order.
     */
    #[Route('/{id}', name: '_show', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function show(PostType $postType): Response
    {
        return $this->render('@Editorial/backend/post-types/index.html.twig', $this->viewBuilder->indexView($postType->getId()));
    }

    #[Route('', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.post_types.create')]
    public function create(Request $request): JsonResponse
    {
        $input = $this->postTypeInputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $postType = $this->postTypeManager->create($input);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonInvalidInput(['slug' => $invalidArgumentException->getMessage()]);
        }

        return $this->jsonSuccess(['postType' => $this->postTypeSerializer->serialize($postType)]);
    }

    #[Route('/{id}/update', name: '_update', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.post_types.edit')]
    public function edit(PostType $postType, Request $request): JsonResponse
    {
        $input = $this->postTypeInputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $this->postTypeManager->update($postType, $input);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonInvalidInput(['slug' => $invalidArgumentException->getMessage()]);
        }

        return $this->jsonSuccess(['postType' => $this->postTypeSerializer->serialize($postType)]);
    }

    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.post_types.delete')]
    public function delete(PostType $postType): JsonResponse
    {
        try {
            $this->postTypeManager->delete($postType);
        } catch (RuntimeException $runtimeException) {
            return $this->jsonFailure($runtimeException->getMessage(), HttpStatusEnum::Conflict->value);
        }

        return $this->jsonSuccess();
    }

    #[Route('/{id}/fields', name: '_field_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.post_types.edit')]
    public function createField(PostType $postType, Request $request): JsonResponse
    {
        $input = $this->postTypeFieldInputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $this->postTypeManager->createField($postType, $input);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonInvalidInput(['name' => $invalidArgumentException->getMessage()]);
        }

        return $this->jsonSuccess(['postType' => $this->postTypeSerializer->serialize($postType)]);
    }

    #[Route('/{id}/fields/{fieldId}/edit', name: '_field_edit', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.post_types.edit')]
    public function editField(PostType $postType, int $fieldId, Request $request): JsonResponse
    {
        $field = $postType->findFieldById($fieldId);
        if (!$field instanceof PostTypeFieldInterface) {
            return $this->jsonNotFound();
        }

        $input = $this->postTypeFieldInputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $this->postTypeManager->updateField($field, $input);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonInvalidInput(['name' => $invalidArgumentException->getMessage()]);
        }

        return $this->jsonSuccess(['postType' => $this->postTypeSerializer->serialize($postType)]);
    }

    #[Route('/{id}/fields/{fieldId}/delete', name: '_field_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.post_types.edit')]
    public function deleteField(PostType $postType, int $fieldId): JsonResponse
    {
        $field = $postType->findFieldById($fieldId);
        if (!$field instanceof PostTypeFieldInterface) {
            return $this->jsonNotFound();
        }

        $this->postTypeManager->deleteField($field);

        return $this->jsonSuccess(['postType' => $this->postTypeSerializer->serialize($postType)]);
    }

    #[Route('/{id}/fields/reorder', name: '_field_reorder', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.post_types.edit')]
    public function reorderFields(PostType $postType, Request $request): JsonResponse
    {
        $this->postTypeManager->reorderFields($postType, Arr::positiveInts($this->decodeJson($request)['orderedIds'] ?? null));

        return $this->jsonSuccess(['postType' => $this->postTypeSerializer->serialize($postType)]);
    }
}
