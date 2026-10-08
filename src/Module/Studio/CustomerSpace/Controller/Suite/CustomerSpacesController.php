<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Studio\CustomerSpace\Dto\CustomerSpaceInputFactoryInterface;
use Aurora\Module\Studio\CustomerSpace\Dto\CustomerSpaceInputInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Manager\CustomerSpaceManagerInterface;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\CustomerSpace\View\CustomerSpacesViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/suite/studio/spaces', name: 'suite_studio_spaces')]
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
        protected readonly CustomerSpaceRepository $spaceRepository,
        protected readonly SpaceVisibility $visibility,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        return $this->render('@Studio/suite/spaces/index.html.twig', $this->viewBuilder->indexView());
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
        return $this->withInput($request, function (CustomerSpaceInputInterface $input) use ($space): JsonResponse {
            $this->spaceManager->update($space, $input);

            return $this->jsonSuccess($this->viewBuilder->spacePayload($space));
        });
    }

    /**
     * To the trash, not destroyed: it stays there for the delay shared by
     * every trash, and everything comes back if it leaves it.
     */
    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.delete')]
    public function delete(CustomerSpace $space): JsonResponse
    {
        $this->spaceManager->trash($space);

        return $this->jsonSuccess($this->viewBuilder->listPayload());
    }

    /**
     * Take a space out of the trash: that is what the trash screen calls. The
     * right that put it there, on a space of one's team; a space that would
     * not be visible answers 404.
     */
    #[Route('/{id}/restore', name: '_restore', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.delete')]
    public function restore(int $id): JsonResponse
    {
        $this->spaceManager->restore($this->trashed($id));

        return $this->jsonSuccess();
    }

    /** Destroy a space in the trash for good, with everything it holds. */
    #[Route('/{id}/force-delete', name: '_force_delete', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.delete')]
    public function forceDelete(int $id): JsonResponse
    {
        $this->spaceManager->forceDelete($this->trashed($id));

        return $this->jsonSuccess();
    }

    /** Empty the spaces trash: only the ones the person sees there. */
    #[Route('/empty-trash', name: '_empty_trash', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.delete')]
    public function emptyTrash(): JsonResponse
    {
        $deleted = 0;
        foreach ($this->spaceRepository->findAllTrashed() as $space) {
            if (!$this->visibility->reaches($space)) {
                continue;
            }

            $this->spaceManager->forceDelete($space);
            ++$deleted;
        }

        return $this->jsonSuccess(['deleted' => $deleted]);
    }

    /** A space in the trash that the person can see, or 404. */
    private function trashed(int $id): CustomerSpaceInterface
    {
        $space = $this->spaceRepository->findTrashed($id);
        if (!$space instanceof CustomerSpaceInterface || !$this->visibility->reaches($space)) {
            throw $this->createNotFoundException();
        }

        return $space;
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

        // A space opened, or moved, onto somebody unknown creates their
        // customer record on the way. That is a customer created, so it asks
        // for the right to create one, as the customer screen does.
        if (null === $input->getCustomerId() && '' !== (string) $input->getProspectName() && !$this->isGranted('studio.customers.create')) {
            throw $this->createAccessDeniedException();
        }

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
