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
        protected readonly CustomerSpaceRepository $spaces,
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
     * À la corbeille, pas détruit : il y reste le délai commun à toutes les
     * corbeilles, et tout revient s'il en sort.
     */
    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.delete')]
    public function delete(CustomerSpace $space): JsonResponse
    {
        $this->spaceManager->trash($space);

        return $this->jsonSuccess($this->viewBuilder->listPayload());
    }

    /**
     * Sortir un espace de la corbeille : c'est ce que l'écran de la corbeille
     * appelle. Le droit qui l'y a mis, sur un espace de son équipe ; un
     * espace qu'on ne verrait pas répond 404.
     */
    #[Route('/{id}/restore', name: '_restore', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.delete')]
    public function restore(int $id): JsonResponse
    {
        $this->spaceManager->restore($this->trashed($id));

        return $this->jsonSuccess();
    }

    /** Détruire pour de bon un espace de la corbeille, avec tout ce qu'il contient. */
    #[Route('/{id}/force-delete', name: '_force_delete', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.delete')]
    public function forceDelete(int $id): JsonResponse
    {
        $this->spaceManager->forceDelete($this->trashed($id));

        return $this->jsonSuccess();
    }

    /** Vider la corbeille des espaces : seulement ceux que la personne y voit. */
    #[Route('/empty-trash', name: '_empty_trash', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.delete')]
    public function emptyTrash(): JsonResponse
    {
        $deleted = 0;
        foreach ($this->spaces->findAllTrashed() as $space) {
            if (!$this->visibility->reaches($space)) {
                continue;
            }

            $this->spaceManager->forceDelete($space);
            ++$deleted;
        }

        return $this->jsonSuccess(['deleted' => $deleted]);
    }

    /** Un espace à la corbeille que la personne peut voir, ou 404. */
    private function trashed(int $id): CustomerSpaceInterface
    {
        $space = $this->spaces->findTrashed($id);
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
