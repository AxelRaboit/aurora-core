<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Studio\CustomerSpace\Dto\CustomerSpaceInputFactoryInterface;
use Aurora\Module\Studio\CustomerSpace\Dto\CustomerSpaceInputInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Manager\CustomerSpaceManagerInterface;
use Aurora\Module\Studio\CustomerSpace\View\CustomerSpacesViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/studio/spaces', name: 'backend_studio_spaces')]
#[IsGranted('studio.spaces.view')]
class CustomerSpacesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly CustomerSpaceManagerInterface $spaceManager,
        protected readonly CustomerSpaceInputFactoryInterface $spaceInputFactory,
        protected readonly CustomerSpacesViewBuilder $viewBuilder,
        protected readonly PayloadValidator $payloadValidator,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        return $this->render('@Studio/backend/spaces/index.html.twig', $this->viewBuilder->indexView());
    }

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.create')]
    public function create(Request $request): JsonResponse
    {
        return $this->withInput($request, fn ($input): JsonResponse => $this->jsonSuccess(
            $this->viewBuilder->spacePayload($this->spaceManager->create($input)),
        ));
    }

    #[Route('/{id}/update', name: '_update', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function update(CustomerSpace $space, Request $request): JsonResponse
    {
        return $this->withInput($request, function ($input) use ($space): JsonResponse {
            $this->spaceManager->update($space, $input);

            return $this->jsonSuccess($this->viewBuilder->spacePayload($space));
        });
    }

    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.delete')]
    public function delete(CustomerSpace $space): JsonResponse
    {
        try {
            $this->spaceManager->delete($space);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->listPayload());
    }

    /**
     * Validate, then save, then answer - and turn a Manager's field rejection
     * into the same shape a constraint violation takes, so the page pins both
     * kinds of error under the field they belong to.
     *
     * @param callable(CustomerSpaceInputInterface):JsonResponse $save
     */
    private function withInput(Request $request, callable $save): JsonResponse
    {
        $input = $this->spaceInputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            return $save($input);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }
    }
}
