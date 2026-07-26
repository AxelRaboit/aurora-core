<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Controller\Frontend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Frontend\Controller\LocaleTrait;
use Aurora\Core\Frontend\Service\Context;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Editorial\Comment\Dto\CommentInputFactoryInterface;
use Aurora\Module\Editorial\Comment\Entity\CommentInterface;
use Aurora\Module\Editorial\Comment\Enum\ReactionTypeEnum;
use Aurora\Module\Editorial\Comment\Manager\CommentManagerInterface;
use Aurora\Module\Editorial\Comment\Manager\CommentReactionManagerInterface;
use Aurora\Module\Editorial\Comment\Repository\CommentReactionRepository;
use Aurora\Module\Editorial\Comment\Repository\CommentRepository;
use Aurora\Module\Editorial\Comment\Serializer\CommentSerializerInterface;
use Aurora\Module\Editorial\Comment\Service\CommentSubmissionValidator;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Service\PostPageRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CommentController extends AbstractController
{
    use LocaleTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly CommentRepository $commentRepository,
        private readonly CommentReactionRepository $commentReactionRepository,
        private readonly CommentManagerInterface $commentManager,
        private readonly CommentReactionManagerInterface $commentReactionManager,
        private readonly CommentSerializerInterface $commentSerializer,
        private readonly CommentSubmissionValidator $commentValidator,
        private readonly Context $context,
        private readonly PostPageRenderer $postPageRenderer,
        private readonly CommentInputFactoryInterface $commentInputFactory,
    ) {}

    #[Route('/{locale}/{postTypeSlug}/{slug}/comment', name: 'editorial_post_comment', requirements: ['locale' => '[a-z]{2}'], methods: [HttpMethodEnum::Post->value], priority: 6)]
    public function submit(string $locale, string $postTypeSlug, string $slug, Request $request): Response
    {
        $this->assertActiveLocale($this->context, $locale);
        $request->setLocale($locale);

        $post = $this->postRepository->findPublishedBySlug($slug, $locale);
        if (!$post instanceof Post) {
            throw $this->createNotFoundException();
        }

        if (!$this->commentManager->areCommentsEnabled($post)) {
            return $this->redirectToRoute('editorial_post', ['locale' => $locale, 'postTypeSlug' => $postTypeSlug, 'slug' => $slug]);
        }

        $isJson = str_contains((string) $request->headers->get('Content-Type', ''), 'application/json');
        $payload = $isJson ? $request->toArray() : $request->request->all();

        $input = $this->commentInputFactory->fromArray($payload);

        $errors = $this->commentValidator->validate($input->getAuthorName(), $input->getAuthorEmail(), $input->getContent());
        if ([] !== $errors) {
            return $isJson
                ? $this->jsonInvalidInput($errors)
                : $this->postPageRenderer->render($post, $locale, $errors);
        }

        $parentComment = $this->resolveParent($post, $input->getParentId() ?? 0);
        $this->commentManager->submit($post, $input, $parentComment);

        if ($isJson) {
            return $this->jsonSuccess();
        }

        $this->addFlash('commentSuccess', 'comment.success');

        return $this->redirectToRoute('editorial_post', ['locale' => $locale, 'postTypeSlug' => $postTypeSlug, 'slug' => $slug]);
    }

    #[Route('/{locale}/{postTypeSlug}/{slug}/comments', name: 'editorial_post_comments_list', requirements: ['locale' => '[a-z]{2}'], methods: [HttpMethodEnum::Get->value], priority: 5)]
    public function list(string $locale, string $postTypeSlug, string $slug): JsonResponse
    {
        $this->assertActiveLocale($this->context, $locale);

        $post = $this->postRepository->findPublishedBySlug($slug, $locale);
        if (!$post instanceof Post) {
            return $this->jsonNotFound();
        }

        if (!$this->commentManager->areCommentsEnabled($post)) {
            return $this->jsonSuccess(['roots' => [], 'replies' => [], 'reactionEmojis' => []]);
        }

        $allComments = $this->commentRepository->findApprovedByPost($post->getId());

        $allCommentIds = array_map(static fn (CommentInterface $comment): int => (int) $comment->getId(), $allComments);
        $reactionCountsMap = [] !== $allCommentIds
            ? $this->commentReactionRepository->countByComments($allCommentIds)
            : [];

        $tree = $this->commentSerializer->buildFrontTree($allComments, $reactionCountsMap);

        return $this->jsonSuccess($tree);
    }

    #[Route('/{locale}/{postTypeSlug}/{slug}/comment/{commentId}/react', name: 'editorial_comment_react', requirements: ['locale' => '[a-z]{2}'], methods: [HttpMethodEnum::Post->value], priority: 5)]
    public function react(string $locale, string $postTypeSlug, string $slug, int $commentId, Request $request): JsonResponse
    {
        $this->assertActiveLocale($this->context, $locale);

        $post = $this->postRepository->findPublishedBySlug($slug, $locale);
        if (!$post instanceof Post) {
            return $this->jsonNotFound();
        }

        $comment = $this->commentRepository->find($commentId);
        if (!$this->isPubliclyOnPost($comment, $post)) {
            return $this->jsonNotFound();
        }

        $typeValue = str_contains((string) $request->headers->get('Content-Type', ''), 'application/json')
            ? (string) ($request->toArray()['type'] ?? '')
            : (string) $request->request->get('type', '');

        $reactionType = ReactionTypeEnum::tryFrom($typeValue);
        if (null === $reactionType) {
            return $this->jsonFailure('Invalid reaction type');
        }

        $fingerprint = $this->commentReactionManager->generateFingerprint($request);
        $updatedCounts = $this->commentReactionManager->toggle($comment, $reactionType, $fingerprint);

        return $this->jsonSuccess(['counts' => $updatedCounts]);
    }

    private function resolveParent(Post $post, int $parentId): ?CommentInterface
    {
        if ($parentId <= 0) {
            return null;
        }

        $parent = $this->commentRepository->find($parentId);

        return $this->isPubliclyOnPost($parent, $post) ? $parent : null;
    }

    private function isPubliclyOnPost(?CommentInterface $comment, Post $post): bool
    {
        return $comment instanceof CommentInterface
            && $comment->getPost()->getId() === $post->getId()
            && 'approved' === $comment->getStatus()->value;
    }
}
