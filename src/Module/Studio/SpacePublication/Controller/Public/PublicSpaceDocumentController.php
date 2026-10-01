<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpacePublication\Controller\Public;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\PrivateAddressResponseTrait;
use Aurora\Module\Editorial\EditorialContext;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Reading\ReadingLocales;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Service\PostPageRenderer;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A document of the space, read by the client holding the space's link.
 *
 * **The space's link is the key, and no second one is asked for.** Whoever
 * holds it already reads the space; a document written for that space opens
 * from there without a reading link or a password of its own, in the same
 * reading layout a reading link gives.
 *
 * The space decides what opens: a published, untrashed publication attached
 * to the very space the link opens. An id from another space answers the same
 * 404 as an id that does not exist.
 */
#[Route('/spaces', name: 'public_space')]
final class PublicSpaceDocumentController extends AbstractController
{
    use PrivateAddressResponseTrait;

    public function __construct(
        private readonly SpaceAccessLinkManagerInterface $links,
        private readonly PostRepository $posts,
        private readonly PostPageRenderer $renderer,
        private readonly ReadingLocales $locales,
        private readonly EditorialContext $editorialContext,
    ) {}

    #[Route(
        '/{selector}/{token}/documents/{postId}',
        name: '_document',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[a-f0-9]{64}', 'postId' => '\d+'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function show(string $selector, string $token, int $postId, Request $request): Response
    {
        $link = $this->links->resolveUsable($selector, $token);
        $post = $this->posts->find($postId);

        if (!$link instanceof SpaceAccessLinkInterface
            || !$post instanceof PostInterface
            || !$this->editorialContext->isPostsEnabled()
            || $post->getCustomerSpaceId() !== $link->getSpace()->getId()
            || !$post->isPublished()
            || $post->isTrashed()
        ) {
            throw $this->createNotFoundException();
        }

        $locale = $this->locales->pick($post, $request->query->get('locale'));

        if (null === $locale) {
            throw $this->createNotFoundException();
        }

        $request->setLocale($locale);

        // Reading a document is reading the space: the link's own record of
        // its first and last opening says so, as the space's page does.
        $this->links->markOpened($link);

        $urls = [];
        foreach ($this->locales->writtenIn($post) as $code) {
            $urls[$code] = $this->generateUrl('public_space_document', [
                'selector' => $selector,
                'token' => $token,
                'postId' => $postId,
                'locale' => $code,
            ]);
        }

        // Back to the space: the client came from it and will want to return,
        // which a document opened from a mail has no reason to offer.
        $back = $this->generateUrl('public_space_show', ['selector' => $selector, 'token' => $token]);

        return $this->privately($this->renderer->renderForReading($post, $locale, $urls, $back));
    }
}
