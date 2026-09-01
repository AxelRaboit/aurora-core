<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Support\TreeReorderParser;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Editorial\Menu\Dto\MenuInputFactoryInterface;
use Aurora\Module\Editorial\Menu\Dto\MenuItemInputFactoryInterface;
use Aurora\Module\Editorial\Menu\Dto\MenuItemInputInterface;
use Aurora\Module\Editorial\Menu\Entity\Menu;
use Aurora\Module\Editorial\Menu\Entity\MenuItemInterface;
use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Menu\Manager\MenuManagerInterface;
use Aurora\Module\Editorial\Menu\Serializer\MenuSerializerInterface;
use Aurora\Module\Editorial\Menu\Service\MenuTargetFinder;
use Aurora\Module\Editorial\Menu\View\MenusViewBuilder;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/editorial/menus', name: 'backend_editorial_menus')]
#[IsGranted('editorial.menus.view')]
class MenusController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly MenuManagerInterface $menuManager,
        private readonly MenuSerializerInterface $menuSerializer,
        private readonly PayloadValidator $payloadValidator,
        private readonly MenusViewBuilder $viewBuilder,
        private readonly MenuInputFactoryInterface $menuInputFactory,
        private readonly MenuItemInputFactoryInterface $itemInputFactory,
        private readonly MenuTargetFinder $targetFinder,
    ) {}

    /**
     * One address per menu: a bare `/menus` redirects to the first rather than
     * showing what `/menus/3` already shows.
     */
    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        $first = $this->viewBuilder->firstId();

        if (null !== $first) {
            return $this->redirectToRoute('backend_editorial_menus_show', ['id' => $first]);
        }

        return $this->render('@Editorial/backend/menus/index.html.twig', $this->viewBuilder->indexView());
    }

    /**
     * Digits only, and here it earns its keep rather than merely guarding the
     * future: `/menus/targets` is a literal GET route on this very controller,
     * and without the requirement `/{id}` would answer for it.
     */
    #[Route('/{id}', name: '_show', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function show(Menu $menu): Response
    {
        return $this->render('@Editorial/backend/menus/index.html.twig', $this->viewBuilder->indexView($menu->getId()));
    }

    /**
     * The "point at…" picker. A search endpoint rather than a list baked into
     * the page: a site with thousands of posts cannot ship them all to the
     * form, and the editor knows the title they are after.
     */
    #[Route('/targets', name: '_targets', methods: [HttpMethodEnum::Get->value])]
    public function targets(Request $request): JsonResponse
    {
        $targetType = MenuItemTargetTypeEnum::tryFrom($request->query->getString('type'));
        if (!$targetType instanceof MenuItemTargetTypeEnum) {
            return $this->jsonSuccess(['options' => []]);
        }

        return $this->jsonSuccess([
            'options' => $this->targetFinder->search(
                $targetType,
                mb_trim($request->query->getString('q')),
                $request->getLocale(),
            ),
        ]);
    }

    #[Route('/{id}/update', name: '_update', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.menus.edit')]
    public function edit(Menu $menu, Request $request): JsonResponse
    {
        $input = $this->menuInputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->menuManager->update($menu, $input);

        return $this->jsonSuccess(['menu' => $this->menuSerializer->serialize($menu)]);
    }

    #[Route('/{id}/items', name: '_item_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.menus.edit')]
    public function createItem(Menu $menu, Request $request): JsonResponse
    {
        return $this->withItemInput($request, function ($input) use ($menu): JsonResponse {
            $this->menuManager->createItem($menu, $input);

            return $this->jsonSuccess(['menu' => $this->menuSerializer->serialize($menu)]);
        });
    }

    #[Route('/{id}/items/{itemId}/edit', name: '_item_edit', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.menus.edit')]
    public function editItem(Menu $menu, int $itemId, Request $request): JsonResponse
    {
        $item = $menu->findItemById($itemId);
        if (!$item instanceof MenuItemInterface) {
            return $this->jsonNotFound();
        }

        return $this->withItemInput($request, function ($input) use ($menu, $item): JsonResponse {
            $this->menuManager->updateItem($item, $input);

            return $this->jsonSuccess(['menu' => $this->menuSerializer->serialize($menu)]);
        });
    }

    #[Route('/{id}/items/{itemId}/delete', name: '_item_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.menus.edit')]
    public function deleteItem(Menu $menu, int $itemId): JsonResponse
    {
        $item = $menu->findItemById($itemId);
        if (!$item instanceof MenuItemInterface) {
            return $this->jsonNotFound();
        }

        $this->menuManager->deleteItem($item);

        return $this->jsonSuccess(['menu' => $this->menuSerializer->serialize($menu)]);
    }

    #[Route('/{id}/items/reorder', name: '_item_reorder', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.menus.edit')]
    public function reorderItems(Menu $menu, Request $request): JsonResponse
    {
        $entries = TreeReorderParser::parse($this->decodeJson($request)['entries'] ?? null);

        try {
            $this->menuManager->reorderItems($menu, $entries);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonFailure($invalidArgumentException->getMessage(), HttpStatusEnum::Conflict->value);
        }

        return $this->jsonSuccess(['menu' => $this->menuSerializer->serialize($menu)]);
    }

    /**
     * Create and edit validate identically and fail identically; only the
     * Manager call between them differs.
     *
     * The factory throws on an unreadable target type rather than defaulting
     * to one; the Manager throws when a target or a parent will not do. Each
     * says which field it means, so the message lands under the input the
     * editor has to change.
     *
     * @param callable(MenuItemInputInterface):JsonResponse $save
     */
    private function withItemInput(Request $request, callable $save): JsonResponse
    {
        try {
            $input = $this->itemInputFactory->fromArray($this->decodeJson($request));
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonInvalidInput(['targetType' => $invalidArgumentException->getMessage()]);
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
