<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Notes\EventSubscriber\NotesRouteGateSubscriber;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceNote\Service\SpaceNoteSpaceProvider;
use Aurora\Module\Studio\SpaceNote\View\SpaceNotesViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A client space's notes, on the Studio side: a single route.
 *
 * **Notes are written in the Notes module**, in the client space's notes
 * space, and each note goes through that module's routes and rules. What is
 * left here is opening that notes space the first time it is needed, with the
 * space's team.
 *
 * **No public route, ever**: the client does not see the notes. The client
 * space comes in through the URL and goes through the spaces' visibility rule,
 * like every `workspace_*` route: a space one is not part of answers 404. And
 * the route closes with the Notes module ({@see NotesRouteGateSubscriber}).
 */
#[Route('/workspace/{id}/notes', name: 'workspace_space_notes', requirements: ['id' => '\d+'])]
#[IsGranted('studio.spaces.view')]
class SpaceNotesController extends AbstractController
{
    use JsonResponseTrait;

    public function __construct(
        protected readonly SpaceNoteSpaceProvider $provider,
        protected readonly SpaceNotesViewBuilder $viewBuilder,
    ) {}

    /**
     * This client space's notes space, opened if it is not.
     *
     * A write, hence the POST: the first note, or the first import, creates the
     * notes space and enrols the team in it. Returning the tab's state with the
     * response saves a second round trip.
     */
    #[Route('/open', name: '_open', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('notes.markdown.use')]
    public function open(CustomerSpace $space): JsonResponse
    {
        $this->provider->resolve($space);

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }
}
