<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Module\Editorial\Post\Dto\PostGalleryInputFactoryInterface;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Manager\PostGalleryManagerInterface;
use Aurora\Module\Editorial\Post\Security\PostVoter;
use Aurora\Module\Editorial\Post\View\PostGalleriesViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The publications, and their galleries only.
 *
 * A second way in beside the editor, for somebody whose job is the pictures. It
 * exists because `/posts/{id}/edit#gallery` restricts nothing - a fragment picks a
 * tab in the browser, and `posts_update` accepts the whole post - so a contributor
 * sent there could publish a draft or rewrite the SEO.
 *
 * The restriction is not on this class. It is that `PostGalleryInput` can express
 * two fields and `PostGalleryManager` writes two columns; this controller only
 * resolves the user, loads the post, validates and delegates, per
 * `convention_thin_controller`.
 *
 * `editorial.posts.gallery` at the class level, and the voter on every action that
 * names a post: the privilege says somebody may use this screen, the voter says
 * whether they may use it on *this* publication.
 */
#[Route('/backend/editorial/post-galleries', name: 'backend_editorial_post_galleries')]
#[IsGranted('editorial.posts.gallery')]
class PostGalleriesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly PostGalleriesViewBuilder $viewBuilder,
        private readonly PostGalleryManagerInterface $galleryManager,
        private readonly PostGalleryInputFactoryInterface $inputFactory,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(Request $request): Response
    {
        $pagination = PaginationRequest::fromRequest($request);
        $list = $this->viewBuilder->listPayload($pagination);

        // The search box re-fetches this same payload, so the page render and the
        // refresh cannot disagree about the shape.
        if ($request->isXmlHttpRequest()) {
            return $this->json($list);
        }

        return $this->render(
            '@Editorial/backend/post_galleries/index.html.twig',
            $this->viewBuilder->indexView($list, $pagination),
        );
    }

    #[Route('/{id}/edit', name: '_edit', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Get->value])]
    public function editPage(Post $post): Response
    {
        $this->denyAccessUnlessGranted(PostVoter::GALLERY_EDIT, $post);

        return $this->render(
            '@Editorial/backend/post_galleries/edit.html.twig',
            $this->viewBuilder->editView($post),
        );
    }

    /**
     * The only write on this screen.
     *
     * No optimistic lock, unlike `posts_update`. A version conflict there means two
     * people rewrote the same article and one has to lose; here it would mean two
     * people arranged the same gallery, and the honest answer is that the last
     * arrangement stands - the pictures are all still in it either way. Adding a
     * lock would refuse a save whose worst case is a different order.
     */
    #[Route('/{id}/update', name: '_update', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Post->value])]
    public function update(Post $post, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(PostVoter::GALLERY_EDIT, $post);

        $this->galleryManager->update(
            $post,
            $this->inputFactory->fromArray($this->decodeJson($request)),
        );

        return $this->jsonSuccess($this->viewBuilder->editView($post));
    }
}
