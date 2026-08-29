<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Controller\Frontend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Editorial\EditorialContext;
use Aurora\Module\Editorial\Post\Preview\Entity\PostPreviewTokenInterface;
use Aurora\Module\Editorial\Post\Preview\Manager\PostPreviewTokenManagerInterface;
use Aurora\Module\Editorial\Post\Service\PostPageRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A publication as it will look, before it looks that way to anybody else.
 *
 * **Deliberately unauthenticated.** The point is to send the address to somebody
 * who cannot sign in - a reviewer without a backend account, a client waiting on a
 * page - and requiring a session would leave them exactly where they were. The URL
 * is the credential: 32 random bytes, minted on request, and good for a week.
 *
 * Rendered through the same `PostPageRenderer` the public page uses. A preview
 * drawn by a second renderer is a preview of something else, which is the one
 * thing it must never be.
 *
 * An unknown token and an expired one answer alike, and the answer is a 404: there
 * is nothing at this address, and saying which kind of nothing would confirm which
 * random strings had once been real.
 */
#[Route('/preview', name: 'editorial_post_preview')]
final class PostPreviewController extends AbstractController
{
    public function __construct(
        private readonly PostPreviewTokenManagerInterface $tokens,
        private readonly PostPageRenderer $renderer,
        private readonly LocaleContextInterface $localeContext,
        private readonly EditorialContext $editorialContext,
    ) {}

    #[Route(
        '/{token}',
        name: '_show',
        requirements: ['token' => '[a-f0-9]{64}'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function show(string $token, Request $request): Response
    {
        if (!$this->editorialContext->isPostsEnabled()) {
            throw $this->createNotFoundException();
        }

        $preview = $this->tokens->resolveUsable($token);

        if (!$preview instanceof PostPreviewTokenInterface) {
            throw $this->createNotFoundException();
        }

        $post = $preview->getPost();

        // A draft is often written in one language first. Asking for a locale it
        // has no translation for would throw out of the renderer, so the requested
        // one is honoured only when it exists and the post's own first is used
        // otherwise - a preview that shows *something* beats one that errors.
        $locale = $this->previewLocale($post->getTranslations()->getKeys(), $request->query->get('locale'));

        if (null === $locale) {
            throw $this->createNotFoundException();
        }

        $request->setLocale($locale);

        $response = $this->renderer->render($post, $locale);

        // Never cached and never indexed. This is an unpublished page behind a
        // secret: a shared cache would serve it to whoever asks next, and a crawler
        // that found the address would publish it on the owner's behalf.
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }

    /**
     * The locale to draw, or null when the post has no translation at all.
     *
     * @param list<string> $available
     */
    private function previewLocale(array $available, mixed $requested): ?string
    {
        if ([] === $available) {
            return null;
        }

        if (is_string($requested) && in_array($requested, $available, true)) {
            return $requested;
        }

        $default = $this->localeContext->getDefaultLocale();

        return in_array($default, $available, true) ? $default : $available[0];
    }
}
