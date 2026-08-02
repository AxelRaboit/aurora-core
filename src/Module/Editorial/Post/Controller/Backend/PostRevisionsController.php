<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostRevisionInterface;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\Post\Repository\PostRevisionRepository;
use Aurora\Module\Editorial\Post\Security\PostVoter;
use Aurora\Module\Editorial\Post\Serializer\PostRevisionSerializerInterface;
use Aurora\Module\Editorial\Post\Serializer\PostSerializerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/editorial/posts', name: 'backend_editorial_posts')]
#[IsGranted('editorial.posts.view')]
class PostRevisionsController extends AbstractController
{
    use JsonResponseTrait;

    /** @see PostsController::ID for why the placeholder is allowed. */
    private const string ID = '\d+|__id__';

    public function __construct(
        private readonly PostManagerInterface $postManager,
        private readonly PostRevisionRepository $revisionRepository,
        private readonly PostRevisionSerializerInterface $revisionSerializer,
        private readonly PostSerializerInterface $postSerializer,
    ) {}

    #[Route('/{id}/revisions', name: '_revisions', requirements: ['id' => self::ID], methods: [HttpMethodEnum::Get->value])]
    public function list(Post $post): JsonResponse
    {
        $this->denyAccessUnlessGranted(PostVoter::VIEW, $post);

        return $this->jsonSuccess([
            'revisions' => array_map(
                $this->revisionSerializer->serialize(...),
                $this->revisionRepository->findForPost($post),
            ),
        ]);
    }

    #[Route('/{id}/revisions/{revisionId}', name: '_revision_show', requirements: ['id' => self::ID, 'revisionId' => self::ID], methods: [HttpMethodEnum::Get->value])]
    public function show(Post $post, int $revisionId): JsonResponse
    {
        $this->denyAccessUnlessGranted(PostVoter::VIEW, $post);

        $revision = $this->findRevision($post, $revisionId);
        if (!$revision instanceof PostRevisionInterface) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess(['revision' => $this->revisionSerializer->serializeFull($revision)]);
    }

    #[Route('/{id}/revisions/{revisionId}/restore', name: '_revision_restore', requirements: ['id' => self::ID, 'revisionId' => self::ID], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.posts.edit')]
    public function restore(Post $post, int $revisionId): JsonResponse
    {
        $this->denyAccessUnlessGranted(PostVoter::EDIT, $post);

        $revision = $this->findRevision($post, $revisionId);
        if (!$revision instanceof PostRevisionInterface) {
            return $this->jsonNotFound();
        }

        $this->postManager->restoreRevision($post, $revision);

        return $this->jsonSuccess(['post' => $this->postSerializer->serializeFull($post)]);
    }

    /**
     * Looked up through the post rather than by id alone, so a revision id
     * from another post cannot be restored onto this one.
     */
    private function findRevision(Post $post, int $revisionId): ?PostRevisionInterface
    {
        foreach ($post->getRevisions() as $revision) {
            if ($revision->getId() === $revisionId) {
                return $revision;
            }
        }

        return null;
    }
}
