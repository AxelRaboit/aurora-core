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
 * Les notes d'un espace client, côté Studio : une seule route.
 *
 * **Les notes s'écrivent dans le module Notes**, dans l'espace de notes de
 * l'espace client, et chaque note passe par les routes et les règles de ce
 * module. Ce qui reste ici, c'est d'ouvrir cet espace de notes la première
 * fois qu'on en a besoin, avec l'équipe de l'espace.
 *
 * **Aucune route publique, toujours** : le client ne voit pas les notes.
 * L'espace client arrive par l'URL et passe par la règle de visibilité des
 * espaces, comme toutes les routes `workspace_*` : un espace dont on n'est pas
 * répond 404. Et la route se ferme avec le module Notes
 * ({@see NotesRouteGateSubscriber}).
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
     * L'espace de notes de cet espace client, ouvert s'il ne l'est pas.
     *
     * Une écriture, d'où le POST : la première note, ou le premier import,
     * crée l'espace de notes et y inscrit l'équipe. Rendre l'état de l'onglet
     * avec la réponse évite un second aller-retour.
     */
    #[Route('/open', name: '_open', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('notes.markdown.use')]
    public function open(CustomerSpace $space): JsonResponse
    {
        $this->provider->resolve($space);

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }
}
