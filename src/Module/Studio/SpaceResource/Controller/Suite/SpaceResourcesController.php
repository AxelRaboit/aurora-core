<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceResource\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Studio\CustomerSpace\Controller\SpaceOwnershipTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Security\ClientVisibility;
use Aurora\Module\Studio\SpaceResource\Dto\SpaceResourceInputFactoryInterface;
use Aurora\Module\Studio\SpaceResource\Entity\SpaceResource;
use Aurora\Module\Studio\SpaceResource\Manager\SpaceResourceManagerInterface;
use Aurora\Module\Studio\SpaceResource\View\SpaceResourcesViewBuilder;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_array;
use function is_numeric;

/**
 * The resources of a space.
 *
 * **No public route.** The client reads theirs with the rest of their page,
 * and nothing here opens without an account: a public route that took a
 * resource by its identifier would be a second path to rows half of which
 * are closed.
 *
 * Each route names the space and each handler checks that the resource it
 * was given belongs to it - nothing prevents a forged request from pointing
 * to one client's resource under another's space, and `assertOwned` is what
 * stops it.
 */
#[Route('/workspace/{id}/resources', name: 'workspace_space_resources', requirements: ['id' => '\d+'])]
#[IsGranted('studio.spaces.view')]
class SpaceResourcesController extends AbstractController
{
    use SpaceOwnershipTrait;
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly SpaceResourceManagerInterface $resources,
        protected readonly SpaceResourceInputFactoryInterface $inputFactory,
        protected readonly SpaceResourcesViewBuilder $viewBuilder,
        protected readonly PayloadValidator $payloadValidator,
        protected readonly ClientVisibility $clientVisibility,
    ) {}

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function create(CustomerSpace $space, Request $request): JsonResponse
    {
        $input = $this->inputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);

        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        // Creating a resource that is already shown is showing it: the right to
        // share the space, like the button of the list.
        if (!$this->clientVisibility->allowsChange(false, $input->isVisibleToClient())) {
            return $this->jsonForbidden();
        }

        $this->resources->create($space, $input);

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }

    #[Route('/{resourceId}/update', name: '_update', requirements: ['resourceId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function update(
        CustomerSpace $space,
        #[MapEntity(id: 'resourceId')]
        SpaceResource $resource,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $resource->getSpace()->getId());

        $input = $this->inputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);

        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        if (!$this->clientVisibility->allowsChange($resource->isVisibleToClient(), $input->isVisibleToClient())) {
            return $this->jsonForbidden();
        }

        $this->resources->update($resource, $input);

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }

    #[Route('/{resourceId}/visibility', name: '_visibility', requirements: ['resourceId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    #[IsGranted(ClientVisibility::PRIVILEGE)]
    public function visibility(
        CustomerSpace $space,
        #[MapEntity(id: 'resourceId')]
        SpaceResource $resource,
    ): JsonResponse {
        $this->assertOwned($space, $resource->getSpace()->getId());

        $this->resources->toggleVisibility($resource);

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }

    #[Route('/reorder', name: '_reorder', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function reorder(CustomerSpace $space, Request $request): JsonResponse
    {
        $raw = $this->decodeJson($request)['ids'] ?? [];

        if (!is_array($raw)) {
            return $this->jsonFailure('suite.studio.space_resources.errors.order_invalid');
        }

        $ids = [];

        foreach ($raw as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        $this->resources->reorder($space, $ids);

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }

    #[Route('/{resourceId}/delete', name: '_delete', requirements: ['resourceId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function delete(
        CustomerSpace $space,
        #[MapEntity(id: 'resourceId')]
        SpaceResource $resource,
    ): JsonResponse {
        $this->assertOwned($space, $resource->getSpace()->getId());

        $this->resources->delete($resource);

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }
}
