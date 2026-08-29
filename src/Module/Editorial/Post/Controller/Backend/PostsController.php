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
use Aurora\Module\Editorial\Post\Duplicate\PostDuplicator;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\Post\Preview\Manager\PostPreviewTokenManagerInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Review\PostReviewManagerInterface;
use Aurora\Module\Editorial\Post\Security\PostVoter;
use Aurora\Module\Editorial\Post\Serializer\PostSerializerInterface;
use Aurora\Module\Editorial\Post\Service\PostAccessService;
use Aurora\Module\Editorial\Post\View\PostsViewBuilder;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
        private readonly PostPreviewTokenManagerInterface $previewTokens,
        private readonly PostReviewManagerInterface $reviewManager,
        private readonly PostDuplicator $duplicator,
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

        // Somebody tried to publish and may not, so the post is now waiting. Told
        // here rather than inside the manager: the notification is about the
        // *request*, and a save that happened to demote is a different event from a
        // save that was already a draft.
        if ($this->postManager->wasDemotedToReview()) {
            /** @var CoreUserInterface $author */
            $author = $this->getUser();
            $this->reviewManager->submit($post, $author);
        }

        return $this->jsonSuccess(['post' => $this->postSerializer->serializeFull($post)]);
    }

    /**
     * Copies a publication into a new draft, and hands back where it went.
     *
     * `editorial.posts.create` rather than the right to edit the original:
     * duplicating makes a *new* post, and somebody allowed to read one they may not
     * edit should still be able to start from it. The copy is theirs.
     */
    #[Route('/{id}/duplicate', name: '_duplicate', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.posts.create')]
    public function duplicate(Post $post): JsonResponse
    {
        $this->denyAccessUnlessGranted(PostVoter::VIEW, $post);

        $copy = $this->duplicator->duplicate($post);

        return $this->jsonSuccess([
            'post' => $this->postSerializer->serialize($copy),
            'editPath' => $this->generateUrl('backend_editorial_posts_edit', ['id' => $copy->getId()]),
        ]);
    }

    /**
     * Approves a publication waiting for review, and publishes it.
     *
     * `PostVoter::PUBLISH` rather than a status check: the voter is what decides who
     * may publish anything, and a second rule here would be a second answer to the
     * same question.
     */
    #[Route('/{id}/review/approve', name: '_review_approve', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Post->value])]
    public function approveReview(Post $post): JsonResponse
    {
        $this->denyAccessUnlessGranted(PostVoter::PUBLISH, $post);

        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $this->reviewManager->approve($post, $user);

        return $this->jsonSuccess(['post' => $this->postSerializer->serializeFull($post)]);
    }

    /**
     * Sends it back with the reason.
     *
     * The reason is required. A rejection with no note leaves the author guessing at
     * what to change, which is the whole thing a review is supposed to save them.
     */
    #[Route('/{id}/review/reject', name: '_review_reject', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Post->value])]
    public function rejectReview(Post $post, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PostVoter::PUBLISH, $post);

        $note = $this->decodeJson($request)['note'] ?? '';
        $note = is_string($note) ? mb_trim($note) : '';

        if ('' === $note) {
            return $this->jsonInvalidInput(['note' => 'backend.posts.review.errors.note_required']);
        }

        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $this->reviewManager->reject($post, $user, $note);

        return $this->jsonSuccess(['post' => $this->postSerializer->serializeFull($post)]);
    }

    /**
     * The address that shows this post before it is published.
     *
     * One route, and it hands back the live preview rather than minting a second:
     * a button that produces a new secret on every press leaves a trail of working
     * addresses behind, and the person pressing it has no idea they are
     * accumulating.
     *
     * Gated on `PostVoter::EDIT`, not on publishing. Somebody who may work on a
     * draft may show it - that is what a review is - and requiring the right to
     * publish would leave exactly the person who needs the link unable to make one.
     */
    #[Route('/{id}/preview', name: '_preview', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.posts.edit')]
    public function preview(Post $post): JsonResponse
    {
        $this->denyAccessUnlessGranted(PostVoter::EDIT, $post);

        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $token = $this->previewTokens->resolveOrCreate($post, $user);

        return $this->jsonSuccess([
            'url' => $this->generateUrl(
                'editorial_post_preview_show',
                ['token' => $token->getToken()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            'expiresAt' => $token->getExpiresAt()->format(DateTimeInterface::ATOM),
        ]);
    }

    /** Ends the current preview, for a draft that should stop being visible. */
    #[Route('/{id}/preview/revoke', name: '_preview_revoke', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.posts.edit')]
    public function revokePreview(Post $post): JsonResponse
    {
        $this->denyAccessUnlessGranted(PostVoter::EDIT, $post);

        $this->previewTokens->revoke($post);

        return $this->jsonSuccess();
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
