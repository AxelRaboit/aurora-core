<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Reading\Entity\PostReadingLink;
use Aurora\Module\Editorial\Post\Reading\Repository\PostReadingLinkRepository;
use Aurora\Module\Editorial\Post\Reading\View\PostReadingLinksViewBuilder;
use Aurora\Module\Editorial\Post\Security\PostVoter;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_int;
use function is_string;
use function mb_substr;
use function mb_trim;
use function password_hash;
use function sprintf;

use const PASSWORD_DEFAULT;

/**
 * The links that open a publication outside the site.
 *
 * Gated on publishing, not on editing: handing a document to somebody outside
 * the application is publishing it to them, and an account allowed to fix a
 * typo is not thereby allowed to send a client's audit. The deck's share
 * links draw the same line with a privilege of their own.
 */
#[Route('/suite/editorial/posts/{id}/reading-links', name: 'suite_editorial_posts_reading_links', requirements: ['id' => '\\d+'])]
#[IsGranted('editorial.posts.publish')]
final class PostReadingLinksController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    private const int MAX_EXPIRY_DAYS = 365;

    public function __construct(
        private readonly PostReadingLinkRepository $postReadingLinkRepository,
        private readonly PostReadingLinksViewBuilder $viewBuilder,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(Post $post): JsonResponse
    {
        $this->denyAccessUnlessGranted(PostVoter::PUBLISH, $post);

        return $this->jsonSuccess($this->viewBuilder->payload($post));
    }

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    public function create(Post $post, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PostVoter::PUBLISH, $post);

        $payload = $this->decodeJson($request);

        $link = new PostReadingLink($post);
        $link->setLabel(is_string($payload['label'] ?? null) ? mb_substr(mb_trim($payload['label']), 0, 120) : '');

        $days = is_int($payload['expiresInDays'] ?? null) ? $payload['expiresInDays'] : null;
        if (null !== $days && $days > 0) {
            $link->setExpiresAt(new DateTimeImmutable(sprintf('+%d days', min($days, self::MAX_EXPIRY_DAYS))));
        }

        // `password_hash`, as on a deck: this is a phrase a person chose, and
        // people reuse phrases. What leaks here must not open anything else.
        $password = is_string($payload['password'] ?? null) ? mb_trim($payload['password']) : '';
        if ('' !== $password) {
            $link->setPasswordHash(password_hash($password, PASSWORD_DEFAULT));
        }

        $this->entityManager->persist($link);
        $this->entityManager->flush();

        return $this->jsonSuccess($this->viewBuilder->payload($post));
    }

    /** Revoking stamps a date; it never deletes the row. */
    #[Route('/{linkId}/revoke', name: '_revoke', requirements: ['linkId' => '\\d+'], methods: [HttpMethodEnum::Post->value])]
    public function revoke(Post $post, int $linkId): JsonResponse
    {
        $this->denyAccessUnlessGranted(PostVoter::PUBLISH, $post);

        $link = $this->postReadingLinkRepository->find($linkId);

        // Checked against the publication in the address: a link id from
        // another publication must not be revocable through this one.
        if (null === $link || $link->getPost()->getId() !== $post->getId()) {
            return $this->jsonNotFound();
        }

        $link->revoke(new DateTimeImmutable());
        $this->entityManager->flush();

        return $this->jsonSuccess($this->viewBuilder->payload($post));
    }
}
