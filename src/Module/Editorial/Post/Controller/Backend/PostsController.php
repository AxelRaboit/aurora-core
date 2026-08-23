<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Support\Arr;
use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Security\PostVoter;
use Aurora\Module\Editorial\Post\Serializer\PostSerializerInterface;
use Aurora\Module\Editorial\Post\Service\PostAccessService;
use Aurora\Module\Editorial\Post\View\PostsViewBuilder;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/editorial/posts', name: 'backend_editorial_posts')]
#[IsGranted('editorial.posts.view')]
class PostsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly PostManagerInterface $postManager,
        private readonly PostSerializerInterface $postSerializer,
        private readonly PostRepository $postRepository,
        private readonly PayloadValidator $payloadValidator,
        private readonly PostsViewBuilder $viewBuilder,
        private readonly PostInputFactoryInterface $postInputFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly LocaleContextInterface $localeContext,
        private readonly PostAccessService $postAccessService,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(Request $request): Response
    {
        $pagination = PaginationRequest::fromRequest($request);
        $filters = $this->readFilters($request);

        $list = $this->viewBuilder->buildListPayload(
            $pagination,
            $filters['postTypeIds'],
            $filters['trashed'],
            // Anyone but a dev or an admin sees only what they wrote. The
            // voter cannot help here - a list has no single post to vote on.
            authorId: $this->postAccessService->scopedAuthorId(),
            termIds: $filters['termIds'],
            statuses: $filters['statuses'],
        );

        // The filter bar re-fetches this same payload, so the page render and
        // the refresh cannot disagree about the shape.
        if ($request->isXmlHttpRequest()) {
            return $this->json($list);
        }

        return $this->render('@Editorial/backend/posts/index.html.twig', $this->viewBuilder->indexView(
            $list,
            $pagination,
            $filters['trashed'],
            $filters['postTypeIds'],
            $filters['termIds'],
            $filters['statuses'],
        ));
    }

    #[Route('/new', name: '_new', methods: [HttpMethodEnum::Get->value])]
    #[IsGranted('editorial.posts.create')]
    public function new(): Response
    {
        return $this->render('@Editorial/backend/posts/edit.html.twig', $this->viewBuilder->editView());
    }

    #[Route('/{id}/edit', name: '_edit', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Get->value])]
    #[IsGranted('editorial.posts.edit')]
    public function editPage(Post $post): Response
    {
        $this->denyAccessUnlessGranted(PostVoter::EDIT, $post);

        return $this->render('@Editorial/backend/posts/edit.html.twig', $this->viewBuilder->editView($post));
    }

    #[Route('/search', name: '_search', methods: [HttpMethodEnum::Get->value])]
    public function search(Request $request): JsonResponse
    {
        $query = mb_trim((string) $request->query->get('q', ''));
        $ids = Arr::positiveInts(explode(',', (string) $request->query->get('ids', '')));

        // Two callers, one endpoint: the picker searches by text, and re-opening
        // a saved post resolves the ids it already holds.
        $posts = [] !== $ids
            ? $this->postRepository->findBy(['id' => $ids])
            : ('' === $query
                ? []
                : $this->postRepository->findPaginated(
                    page: 1,
                    locale: $this->localeContext->getDefaultLocale(),
                    limit: 20,
                    search: $query,
                )['items']);

        return $this->jsonSuccess(['posts' => array_map($this->postSerializer->serializeReference(...), $posts)]);
    }

    #[Route('/{id}', name: '_show', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Get->value])]
    public function show(Post $post): JsonResponse
    {
        $this->denyAccessUnlessGranted(PostVoter::VIEW, $post);

        return $this->jsonSuccess(['post' => $this->postSerializer->serializeFull($post)]);
    }

    #[Route('', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.posts.create')]
    public function create(Request $request): JsonResponse
    {
        $input = $this->postManager->demoteIfNotPublishable(
            $this->postInputFactory->fromArray($this->decodeJson($request)),
        );

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $post = $this->postManager->create($input);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonInvalidInput(['postTypeId' => $invalidArgumentException->getMessage()]);
        }

        return $this->jsonSuccess(['post' => $this->postSerializer->serializeFull($post)]);
    }

    #[Route('/{id}/update', name: '_update', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.posts.edit')]
    public function update(Post $post, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PostVoter::EDIT, $post);

        $input = $this->postManager->demoteIfNotPublishable(
            $this->postInputFactory->fromArray($this->decodeJson($request)),
            $post,
        );

        // Checked before validation: telling someone their text is invalid is
        // wrong when the real answer is that someone else already saved.
        if (!$input->isForce() && null !== $input->getVersion()) {
            try {
                $this->entityManager->lock($post, LockMode::OPTIMISTIC, $input->getVersion());
            } catch (OptimisticLockException) {
                return $this->jsonFailure('conflict', HttpStatusEnum::Conflict->value, ['conflict' => true]);
            }
        }

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $this->postManager->update($post, $input);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonInvalidInput(['postTypeId' => $invalidArgumentException->getMessage()]);
        }

        return $this->jsonSuccess(['post' => $this->postSerializer->serializeFull($post)]);
    }

    #[Route('/{id}/delete', name: '_delete', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.posts.delete')]
    public function delete(Post $post): JsonResponse
    {
        $this->denyAccessUnlessGranted(PostVoter::DELETE, $post);

        $this->postManager->delete($post);

        return $this->jsonSuccess();
    }

    /**
     * @return array{postTypeIds: list<int>, termIds: list<int>, statuses: list<string>, trashed: bool}
     */
    private function readFilters(Request $request): array
    {
        return [
            'postTypeIds' => Arr::positiveInts(explode(',', (string) $request->query->get('postTypeIds', ''))),
            'termIds' => Arr::positiveInts(explode(',', (string) $request->query->get('termIds', ''))),
            // Filtered against the enum: the column is an enumType, and an
            // unknown value would reach Doctrine's converter rather than
            // simply matching nothing.
            'statuses' => array_values(array_intersect(
                PostStatusEnum::values(),
                explode(',', (string) $request->query->get('statuses', '')),
            )),
            'trashed' => $request->query->getBoolean('trashed'),
        ];
    }
}
