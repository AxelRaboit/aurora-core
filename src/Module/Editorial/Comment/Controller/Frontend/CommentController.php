<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Controller\Frontend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Frontend\Service\Context;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Routing\RouteRequirement;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Editorial\Comment\Dto\CommentInputFactoryInterface;
use Aurora\Module\Editorial\Comment\Entity\CommentInterface;
use Aurora\Module\Editorial\Comment\Enum\ReactionTypeEnum;
use Aurora\Module\Editorial\Comment\Manager\CommentManagerInterface;
use Aurora\Module\Editorial\Comment\Manager\CommentReactionManagerInterface;
use Aurora\Module\Editorial\Comment\Repository\CommentReactionRepository;
use Aurora\Module\Editorial\Comment\Repository\CommentRepository;
use Aurora\Module\Editorial\Comment\Serializer\CommentSerializerInterface;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public comment endpoints.
 *
 * All three sit under the post's own URL and above it in priority: the post
 * route matches `/{locale}/{a}/{b}`, so a four-segment path has to be tried
 * first or it never gets here at all.
 *
 * These are the only routes in Editorial that an unauthenticated stranger can
 * write through, which is why the flood check and the honeypot exist, and why
 * a rejection never explains which of the two it was.
 */
class CommentController extends AbstractController
{
    use JsonResponseTrait;

    /** How many comments one address may leave in an hour. */
    private const int MAX_PER_HOUR = 5;

    /** @see RouteRequirement — the Vue side asks for this path with a placeholder in it. */
    private const string COMMENT_ID = '\\d+|__commentId__';

    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly CommentRepository $commentRepository,
        private readonly CommentReactionRepository $reactionRepository,
        private readonly CommentManagerInterface $commentManager,
        private readonly CommentReactionManagerInterface $reactionManager,
        private readonly CommentSerializerInterface $commentSerializer,
        private readonly CommentInputFactoryInterface $inputFactory,
        private readonly PayloadValidator $payloadValidator,
        private readonly Context $context,
    ) {}

    #[Route(
        '/{locale}/{postTypeSlug}/{slug}/comments',
        name: 'editorial_post_comments',
        requirements: ['locale' => '[a-z]{2}'],
        methods: [HttpMethodEnum::Get->value],
        priority: 6,
    )]
    public function list(string $locale, string $postTypeSlug, string $slug): JsonResponse
    {
        $post = $this->publishedPost($locale, $slug);
        if (!$post instanceof PostInterface || !$this->commentManager->areCommentsEnabled($post)) {
            return $this->jsonSuccess(['comments' => [], 'total' => 0, 'reactionTypes' => []]);
        }

        return $this->jsonSuccess($this->thread($post));
    }

    #[Route(
        '/{locale}/{postTypeSlug}/{slug}/comments',
        name: 'editorial_post_comment_submit',
        requirements: ['locale' => '[a-z]{2}'],
        methods: [HttpMethodEnum::Post->value],
        priority: 6,
    )]
    public function submit(string $locale, string $postTypeSlug, string $slug, Request $request): JsonResponse
    {
        $post = $this->publishedPost($locale, $slug);
        if (!$post instanceof PostInterface) {
            return $this->jsonNotFound();
        }

        if (!$this->commentManager->areCommentsEnabled($post)) {
            return $this->jsonFailure('frontend.editorial.comments.errors.closed', HttpStatusEnum::Conflict->value);
        }

        $input = $this->inputFactory->fromArray($this->payload($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        if ($this->isFlooding($input->getAuthorEmail())) {
            return $this->jsonFailure('frontend.editorial.comments.errors.too_many', HttpStatusEnum::TooManyRequests->value);
        }

        $comment = $this->commentManager->submit($post, $input, $this->resolveParent($post, $input->getParentId()));

        // The same answer whether the comment is live, waiting for a moderator
        // or filed as spam. Telling a spammer which of the three happened is
        // telling them what to change.
        return $this->jsonSuccess([
            'pending' => !$comment->isApproved(),
            'thread' => $this->thread($post),
        ]);
    }

    #[Route(
        '/{locale}/{postTypeSlug}/{slug}/comments/{commentId}/react',
        name: 'editorial_comment_react',
        requirements: ['locale' => '[a-z]{2}', 'commentId' => self::COMMENT_ID],
        methods: [HttpMethodEnum::Post->value],
        priority: 7,
    )]
    public function react(string $locale, string $postTypeSlug, string $slug, int $commentId, Request $request): JsonResponse
    {
        $post = $this->publishedPost($locale, $slug);
        if (!$post instanceof PostInterface || !$this->commentManager->areCommentsEnabled($post)) {
            return $this->jsonNotFound();
        }

        $comment = $this->commentRepository->find($commentId);
        if (!$this->isReadableOn($comment, $post)) {
            return $this->jsonNotFound();
        }

        $type = ReactionTypeEnum::tryFrom((string) ($this->payload($request)['type'] ?? ''));
        if (!$type instanceof ReactionTypeEnum) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess([
            'counts' => $this->reactionManager->toggle($comment, $type, $this->reactionManager->fingerprint($request)),
        ]);
    }

    /** @return array<string, mixed> */
    private function thread(PostInterface $post): array
    {
        $comments = $this->commentRepository->findApprovedByPost((int) $post->getId());

        $ids = array_map(static fn (CommentInterface $comment): int => (int) $comment->getId(), $comments);

        return $this->commentSerializer->serializeThread($comments, $this->reactionRepository->countByComments($ids));
    }

    private function publishedPost(string $locale, string $slug): ?PostInterface
    {
        if (!$this->context->isLocaleActive($locale)) {
            return null;
        }

        return $this->postRepository->findPublishedBySlug($slug, $locale);
    }

    /**
     * A reply must answer something on this post that a reader can already
     * see. Anything else — another post's comment, one awaiting moderation,
     * an id that never existed — becomes a root comment rather than an error:
     * the reader's words are worth keeping either way.
     */
    private function resolveParent(PostInterface $post, ?int $parentId): ?CommentInterface
    {
        if (null === $parentId) {
            return null;
        }

        $parent = $this->commentRepository->find($parentId);

        return $this->isReadableOn($parent, $post) ? $parent : null;
    }

    private function isReadableOn(?CommentInterface $comment, PostInterface $post): bool
    {
        return $comment instanceof CommentInterface
            && $comment->isApproved()
            && $comment->getPost()->getId() === $post->getId();
    }

    /**
     * Counted per address rather than per connection: an address is what the
     * comment is attributed to, and it is the thing a moderator will act on.
     * It is a speed bump, not a wall — the honeypot and the content filter do
     * the rest.
     */
    private function isFlooding(string $email): bool
    {
        return $this->commentRepository->countRecentByEmail(
            $email,
            new DateTimeImmutable('-1 hour'),
        ) >= self::MAX_PER_HOUR;
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        if (!str_contains((string) $request->headers->get('Content-Type', ''), 'application/json')) {
            return $request->request->all();
        }

        // Decoded rather than `toArray()`, which raises on malformed input.
        // Unparseable JSON becomes an empty payload: the validator below
        // already has the right words for "nothing was filled in", and this
        // endpoint answers strangers, who should get a form error rather than
        // a stack trace.
        $decoded = json_decode((string) $request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
