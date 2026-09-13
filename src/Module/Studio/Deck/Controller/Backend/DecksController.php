<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\Deck\Dto\DeckCategoryInput;
use Aurora\Module\Studio\Deck\Dto\DeckInput;
use Aurora\Module\Studio\Deck\Duplicate\DeckDuplicator;
use Aurora\Module\Studio\Deck\Entity\Deck;
use Aurora\Module\Studio\Deck\Entity\DeckCategory;
use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Aurora\Module\Studio\Deck\Import\DeckFromBlocks;
use Aurora\Module\Studio\Deck\Manager\DeckManager;
use Aurora\Module\Studio\Deck\Repository\DeckCategoryRepository;
use Aurora\Module\Studio\Deck\Repository\DeckRepository;
use Aurora\Module\Studio\Deck\View\DecksViewBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_values;
use function is_array;
use function is_int;
use function is_string;

/**
 * The decks, their slides and the categories they are filed under.
 *
 * One controller for the three rather than one each, because they are one
 * screen: a deck is created, filed and filled without leaving the page, and
 * splitting the routes would only mean three classes sharing the same view
 * builder.
 */
#[Route('/backend/studio/decks', name: 'backend_studio_decks')]
#[IsGranted('studio.decks.view')]
class DecksController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly DeckManager $deckManager,
        protected readonly DeckDuplicator $deckDuplicator,
        protected readonly DeckFromBlocks $deckFromBlocks,
        protected readonly DeckCategoryRepository $categoryRepository,
        protected readonly DeckRepository $deckRepository,
        protected readonly CustomerRepository $customerRepository,
        protected readonly DecksViewBuilder $viewBuilder,
        protected readonly PayloadValidator $payloadValidator,
        protected readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        return $this->render('@Studio/backend/decks/index.html.twig', $this->viewBuilder->indexView());
    }

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.decks.create')]
    public function create(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $input = $this->toInput($payload);

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $deck = $this->deckManager->create($input->title);
        $this->applyInput($deck, $input);

        // Opened from a model: the shape is copied, the filing comes from the
        // form somebody has just filled. An id that no longer resolves opens an
        // empty deck rather than failing - the picker is fed from the list, so
        // the only way to send an unknown one is a model deleted between the
        // page load and the save, and refusing then would lose the title.
        $template = null === $input->fromTemplateId ? null : $this->deckRepository->find($input->fromTemplateId);

        if ($template instanceof DeckInterface) {
            $this->deckDuplicator->copyInto($deck, $template);
        }

        $this->entityManager->flush();

        return $this->jsonSuccess($this->viewBuilder->deckPayload($deck));
    }

    /**
     * A written document, in as a deck.
     *
     * The conversion lives on the server rather than in the modal, because the
     * rule it applies - a heading opens a slide, what follows fills it - is the
     * kind of thing a second implementation drifts on, and because every slide
     * it writes goes through `DeckManager` and is whitelisted there like any
     * other. A converter in the browser would be a second way into the content
     * column.
     */
    #[Route('/import', name: '_import', methods: [HttpMethodEnum::Post->value], priority: 10)]
    #[IsGranted('studio.decks.create')]
    public function import(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $input = $this->toInput($payload);

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $blocks = is_array($payload['blocks'] ?? null) ? array_values($payload['blocks']) : [];

        $deck = $this->deckManager->create($input->title);
        $this->applyInput($deck, $input);

        $written = $this->deckFromBlocks->fill($deck, $blocks);

        if (0 === $written) {
            return $this->jsonInvalidInput(['blocks' => 'backend.studio.decks.errors.import_empty']);
        }

        $this->entityManager->flush();

        return $this->jsonSuccess($this->viewBuilder->deckPayload($deck));
    }

    #[Route('/{id}/update', name: '_update', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.decks.edit')]
    public function update(Deck $deck, Request $request): JsonResponse
    {
        $input = $this->toInput($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $deck->setTitle($input->title);
        $this->applyInput($deck, $input);
        $this->entityManager->flush();

        return $this->jsonSuccess($this->viewBuilder->deckPayload($deck));
    }

    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.decks.delete')]
    public function delete(Deck $deck): JsonResponse
    {
        $this->entityManager->remove($deck);
        $this->entityManager->flush();

        return $this->jsonSuccess();
    }

    /**
     * Copy a deck, slide for slide.
     *
     * A `create` privilege rather than a `view` one: duplicating writes a new
     * row, whatever the gesture looks like from the list.
     */
    #[Route('/{id}/duplicate', name: '_duplicate', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.decks.create')]
    public function duplicate(Deck $deck): JsonResponse
    {
        $copy = $this->deckDuplicator->duplicate($deck);
        $this->entityManager->flush();

        return $this->jsonSuccess($this->viewBuilder->deckPayload($copy));
    }

    #[Route('/categories/create', name: '_category_create', methods: [HttpMethodEnum::Post->value], priority: 10)]
    #[IsGranted('studio.deck_categories.manage')]
    public function createCategory(Request $request): JsonResponse
    {
        return $this->writeCategory($request, null);
    }

    #[Route('/categories/{id}/update', name: '_category_update', methods: [HttpMethodEnum::Post->value], priority: 10)]
    #[IsGranted('studio.deck_categories.manage')]
    public function updateCategory(Request $request, int $id): JsonResponse
    {
        return $this->writeCategory($request, $id);
    }

    #[Route('/categories/{id}/delete', name: '_category_delete', methods: [HttpMethodEnum::Post->value], priority: 10)]
    #[IsGranted('studio.deck_categories.manage')]
    public function deleteCategory(int $id): JsonResponse
    {
        $category = $this->categoryRepository->find($id);

        if (null === $category) {
            return $this->jsonNotFound();
        }

        // The decks filed under it keep existing: the join column is
        // `ON DELETE SET NULL`, so they simply become unfiled.
        $this->entityManager->remove($category);
        $this->entityManager->flush();

        return $this->jsonSuccess();
    }

    private function writeCategory(Request $request, ?int $id): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $input = new DeckCategoryInput(
            is_string($payload['name'] ?? null) ? $payload['name'] : '',
            is_string($payload['color'] ?? null) ? $payload['color'] : null,
        );

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $category = null === $id ? null : $this->categoryRepository->find($id);

        if (null === $id) {
            $category = new DeckCategory();
            $category->setPosition($this->categoryRepository->nextPosition());
            $this->entityManager->persist($category);
        }

        if (null === $category) {
            return $this->jsonNotFound();
        }

        $category->setName($input->name);
        $category->setColor($input->color);

        $this->entityManager->flush();

        return $this->jsonSuccess($this->viewBuilder->categoriesPayload());
    }

    /** @param array<string, mixed> $payload */
    private function toInput(array $payload): DeckInput
    {
        return new DeckInput(
            is_string($payload['title'] ?? null) ? $payload['title'] : '',
            is_string($payload['description'] ?? null) ? $payload['description'] : null,
            is_int($payload['categoryId'] ?? null) ? $payload['categoryId'] : null,
            is_int($payload['customerId'] ?? null) ? $payload['customerId'] : null,
            true === ($payload['isTemplate'] ?? false),
            is_int($payload['fromTemplateId'] ?? null) ? $payload['fromTemplateId'] : null,
        );
    }

    /**
     * The two relations, resolved.
     *
     * An id nobody can resolve clears the field rather than failing: the only
     * way to get one is a category or a customer deleted between the page load
     * and the save, and refusing the whole save for it would lose the title
     * somebody just typed.
     */
    private function applyInput(DeckInterface $deck, DeckInput $input): void
    {
        $deck->setDescription($input->description);
        $deck->setTemplate($input->isTemplate);
        $deck->setCategory(null === $input->categoryId ? null : $this->categoryRepository->find($input->categoryId));
        $deck->setCustomer(null === $input->customerId ? null : $this->customerRepository->find($input->customerId));
    }
}
