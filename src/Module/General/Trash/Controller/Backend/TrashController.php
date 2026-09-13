<?php

declare(strict_types=1);

namespace Aurora\Module\General\Trash\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\General\Trash\View\TrashViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The one screen where deleted things live.
 *
 * It lists, and it points: restoring and destroying are posted to each
 * module's own endpoints, which is where the rules and the privileges are
 * written. This controller re-reads the whole list afterwards rather than
 * patching a row, because one trash can change another - releasing a folder
 * moves the documents that were in it.
 */
#[Route('/backend/trash', name: 'backend_general_trash')]
#[IsGranted('general.trash.view')]
class TrashController extends AbstractController
{
    use JsonResponseTrait;

    public function __construct(private readonly TrashViewBuilder $viewBuilder) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        return $this->render('@General/backend/trash/index.html.twig', $this->viewBuilder->indexView());
    }

    #[Route('/list', name: '_list', methods: [HttpMethodEnum::Get->value])]
    public function list(): JsonResponse
    {
        return $this->jsonSuccess(['trashes' => $this->viewBuilder->trashes()]);
    }
}
