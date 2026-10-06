<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateCategoryInput;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateCategory;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateCategoryRepository;
use Aurora\Module\Studio\Contract\View\ContractTemplatesViewBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_filter;
use function array_flip;
use function array_values;
use function count;
use function is_array;
use function is_int;
use function is_string;

/**
 * The categories trames are filed under, managed from the trames screen.
 *
 * The same four gestures as the decks' and the deliverables' categories, for
 * the shared management window: create, rename and colour, delete, reorder.
 * Under the right to edit trames, since filing one is editing it.
 */
#[Route('/suite/studio/contract-templates/categories', name: 'suite_studio_contract_templates_category')]
#[IsGranted('studio.contract_templates.edit')]
class ContractTemplateCategoriesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly ContractTemplateCategoryRepository $categoryRepository,
        protected readonly ContractTemplatesViewBuilder $viewBuilder,
        protected readonly PayloadValidator $payloadValidator,
        protected readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value], priority: 10)]
    public function create(Request $request): JsonResponse
    {
        return $this->write($request, null);
    }

    #[Route('/{id}/update', name: '_update', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value], priority: 10)]
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->write($request, $id);
    }

    #[Route('/{id}/delete', name: '_delete', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value], priority: 10)]
    public function delete(int $id): JsonResponse
    {
        $category = $this->categoryRepository->find($id);

        if (null === $category) {
            return $this->jsonNotFound();
        }

        // The trames filed under it keep existing: the join column is
        // `ON DELETE SET NULL`, so they simply become unfiled.
        $this->entityManager->remove($category);
        $this->entityManager->flush();

        return $this->jsonSuccess($this->viewBuilder->categoriesPayload());
    }

    /**
     * The order somebody arranged the categories in. An unknown id is
     * ignored; a category missing from the list goes after the others.
     */
    #[Route('/reorder', name: '_reorder', methods: [HttpMethodEnum::Post->value], priority: 10)]
    public function reorder(Request $request): JsonResponse
    {
        $ids = $this->decodeJson($request)['ids'] ?? null;
        $rank = array_flip(is_array($ids) ? array_values(array_filter($ids, is_int(...))) : []);
        $next = count($rank);

        foreach ($this->categoryRepository->findOrdered() as $category) {
            $category->setPosition($rank[$category->getId()] ?? $next++);
        }

        $this->entityManager->flush();

        return $this->jsonSuccess($this->viewBuilder->categoriesPayload());
    }

    private function write(Request $request, ?int $id): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $input = new ContractTemplateCategoryInput(
            is_string($payload['name'] ?? null) ? $payload['name'] : '',
            is_string($payload['color'] ?? null) && '' !== $payload['color'] ? $payload['color'] : null,
        );

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $category = null === $id ? null : $this->categoryRepository->find($id);

        if (null === $id) {
            $category = new ContractTemplateCategory();
            $category->setPosition($this->categoryRepository->nextPosition());
            $this->entityManager->persist($category);
        }

        if (null === $category) {
            return $this->jsonNotFound();
        }

        $category->setName($input->name);
        $category->setColor($input->color);

        $this->entityManager->flush();

        return $this->jsonSuccess(['categoryId' => $category->getId(), ...$this->viewBuilder->categoriesPayload()]);
    }
}
