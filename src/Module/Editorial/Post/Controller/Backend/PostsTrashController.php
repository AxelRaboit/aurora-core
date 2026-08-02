<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Routing\RouteRequirement;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\Post\Security\PostVoter;
use Aurora\Module\Editorial\Post\Serializer\PostSerializerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The trash lives on the posts list behind a filter rather than on a screen
 * of its own, so these are endpoints without a page.
 */
#[Route('/backend/editorial/posts', name: 'backend_editorial_posts')]
#[IsGranted('editorial.posts.view')]
class PostsTrashController extends AbstractController
{
    use JsonResponseTrait;

    /** @see PostsController::ID for why the placeholder is allowed. */
    /** @see RouteRequirement::ID for why a bare `\d+` breaks the screen. */
    private const string ID = RouteRequirement::ID;

    public function __construct(
        private readonly PostManagerInterface $postManager,
        private readonly PostSerializerInterface $postSerializer,
    ) {}

    #[Route('/{id}/restore', name: '_restore', requirements: ['id' => self::ID], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.posts.delete')]
    public function restore(Post $post): JsonResponse
    {
        $this->denyAccessUnlessGranted(PostVoter::DELETE, $post);

        $this->postManager->restore($post);

        return $this->jsonSuccess(['post' => $this->postSerializer->serialize($post)]);
    }

    #[Route('/{id}/force-delete', name: '_force_delete', requirements: ['id' => self::ID], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.posts.delete')]
    public function forceDelete(Post $post): JsonResponse
    {
        $this->denyAccessUnlessGranted(PostVoter::DELETE, $post);

        $this->postManager->forceDelete($post);

        return $this->jsonSuccess();
    }

    /**
     * Declared before the /{id} routes of the sibling controller would not
     * help — Symfony matches on the pattern, and `empty-trash` cannot pass
     * the numeric id requirement, so there is no ambiguity to resolve.
     */
    #[Route('/empty-trash', name: '_empty_trash', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.posts.delete')]
    public function emptyTrash(): JsonResponse
    {
        return $this->jsonSuccess(['deleted' => $this->postManager->emptyTrash()]);
    }
}
