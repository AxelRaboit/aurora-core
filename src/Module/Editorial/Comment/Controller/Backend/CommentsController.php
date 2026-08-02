<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Module\Editorial\Comment\Entity\Comment;
use Aurora\Module\Editorial\Comment\Entity\CommentInterface;
use Aurora\Module\Editorial\Comment\Enum\CommentStatusEnum;
use Aurora\Module\Editorial\Comment\Manager\CommentManagerInterface;
use Aurora\Module\Editorial\Comment\Repository\CommentReactionRepository;
use Aurora\Module\Editorial\Comment\Repository\CommentRepository;
use Aurora\Module\Editorial\Comment\Serializer\CommentSerializerInterface;
use Aurora\Module\Editorial\Comment\View\CommentsViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/editorial/comments', name: 'backend_editorial_comments')]
#[IsGranted('editorial.comments.view')]
class CommentsController extends AbstractController
{
    use JsonResponseTrait;

    public function __construct(
        private readonly CommentRepository $commentRepository,
        private readonly CommentReactionRepository $reactionRepository,
        private readonly CommentManagerInterface $commentManager,
        private readonly CommentSerializerInterface $commentSerializer,
        private readonly CommentsViewBuilder $viewBuilder,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        return $this->render('@Editorial/backend/comments/index.html.twig', $this->viewBuilder->indexView());
    }

    #[Route('/list', name: '_list', methods: [HttpMethodEnum::Get->value])]
    public function list(Request $request): JsonResponse
    {
        $pagination = PaginationRequest::fromRequest($request);

        $result = $this->commentRepository->findPaginatedForAdmin(
            $pagination->page,
            $pagination->limit,
            CommentStatusEnum::tryFrom($request->query->getString('status')),
            mb_trim($request->query->getString('search')),
        );

        // Tallies for the whole page in one query rather than one per row.
        $ids = array_map(static fn (CommentInterface $comment): int => (int) $comment->getId(), $result['items']);
        $reactionCounts = $this->reactionRepository->countByComments($ids);

        return $this->jsonSuccess([
            'comments' => array_map(
                fn (CommentInterface $comment): array => $this->commentSerializer->serialize($comment, $reactionCounts),
                $result['items'],
            ),
            'total' => $result['total'],
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
            'counts' => $this->commentRepository->countByStatus(),
        ]);
    }

    #[Route('/{id}/approve', name: '_approve', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.comments.moderate')]
    public function approve(Comment $comment): JsonResponse
    {
        $this->commentManager->approve($comment);

        return $this->jsonSuccess(['comment' => $this->commentSerializer->serialize($comment)]);
    }

    #[Route('/{id}/spam', name: '_spam', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.comments.moderate')]
    public function spam(Comment $comment): JsonResponse
    {
        $this->commentManager->markAsSpam($comment);

        return $this->jsonSuccess(['comment' => $this->commentSerializer->serialize($comment)]);
    }

    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.comments.delete')]
    public function delete(Comment $comment): JsonResponse
    {
        $this->commentManager->delete($comment);

        return $this->jsonSuccess();
    }
}
