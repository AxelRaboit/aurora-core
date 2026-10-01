<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Controller\Frontend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\PrivateAddressResponseTrait;
use Aurora\Module\Editorial\EditorialContext;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Reading\Entity\PostReadingLinkInterface;
use Aurora\Module\Editorial\Post\Reading\ReadingLocales;
use Aurora\Module\Editorial\Post\Reading\Repository\PostReadingLinkRepository;
use Aurora\Module\Editorial\Post\Service\PostPageRenderer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

use function is_array;
use function password_verify;

/**
 * A publication, read by somebody holding its link and nothing else.
 *
 * **Everything this page can reach is decided by the link**, as on a deck's:
 * there is one publication behind one token, and no route here takes a
 * publication id.
 *
 * Named outside `editorial_` on purpose. The routes under that prefix go dark
 * when the site's front is switched off, and an instance with no public site
 * is still one that sends its clients an audit. Switching the Posts module
 * off does close it, as it closes the preview: the module owns the
 * publication.
 */
#[Route('/read', name: 'post_reading')]
final class PostReadingController extends AbstractController
{
    use PrivateAddressResponseTrait;

    /**
     * Which links this browser has already unlocked, in its own session - the
     * deck's reasoning: a reader holding three protected links should not type
     * three passwords again because one of them expired.
     */
    private const string UNLOCKED = 'editorial.post_reading.unlocked';

    public function __construct(
        private readonly PostReadingLinkRepository $links,
        private readonly PostPageRenderer $renderer,
        private readonly ReadingLocales $locales,
        private readonly EditorialContext $editorialContext,
        private readonly EntityManagerInterface $entityManager,
        // The `post_reading_password` limiter declared in config, autowired by
        // name the way the deck's controller takes its own.
        private readonly RateLimiterFactoryInterface $postReadingPasswordLimiter,
    ) {}

    #[Route('/{token}', name: '_show', requirements: ['token' => '[a-f0-9]{64}'], methods: [HttpMethodEnum::Get->value])]
    public function show(string $token, Request $request): Response
    {
        $link = $this->readable($token);

        // Locked and not yet opened in this session: the password page, which
        // says nothing of the publication behind it - not its title, not who
        // it was prepared for.
        if ($link->isLocked() && !$this->isUnlocked($request, $token)) {
            return $this->privately($this->render('@Editorial/public/reading_locked.html.twig', [
                'token' => $token,
                'failed' => false,
            ]));
        }

        $post = $link->getPost();
        $locale = $this->locales->pick($post, $request->query->get('locale'));

        if (null === $locale) {
            throw $this->createNotFoundException();
        }

        $request->setLocale($locale);

        $link->touch(new DateTimeImmutable());
        $this->entityManager->flush();

        return $this->privately($this->renderer->renderForReading($post, $locale, $this->localeUrls($post, $token)));
    }

    /**
     * The password, checked.
     *
     * A wrong password answers exactly what a wrong address answers: the same
     * page, the same words.
     */
    #[Route('/{token}/unlock', name: '_unlock', requirements: ['token' => '[a-f0-9]{64}'], methods: [HttpMethodEnum::Post->value])]
    public function unlock(string $token, Request $request): Response
    {
        if (false === $this->postReadingPasswordLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        $link = $this->links->findByToken($token);
        $password = (string) $request->request->get('password', '');

        if (!$link instanceof PostReadingLinkInterface
            || !$this->isReadable($link)
            || !$link->isLocked()
            || !password_verify($password, (string) $link->getPasswordHash())
        ) {
            return $this->privately($this->render('@Editorial/public/reading_locked.html.twig', [
                'token' => $token,
                'failed' => true,
            ]));
        }

        $unlocked = $request->getSession()->get(self::UNLOCKED, []);
        $unlocked = is_array($unlocked) ? $unlocked : [];
        $unlocked[$token] = true;
        $request->getSession()->set(self::UNLOCKED, $unlocked);

        return $this->privately($this->redirectToRoute('post_reading_show', ['token' => $token]));
    }

    /**
     * The link, when it leads somewhere; otherwise the same 404 a wrong
     * address gets.
     *
     * One answer for "no such link", "revoked", "expired", "not published"
     * and "in the trash". Telling a holder which of them it is would confirm
     * that the address was real.
     */
    private function readable(string $token): PostReadingLinkInterface
    {
        $link = $this->links->findByToken($token);

        if (!$link instanceof PostReadingLinkInterface || !$this->isReadable($link)) {
            throw $this->createNotFoundException();
        }

        return $link;
    }

    /**
     * Usable, its publication published and not trashed, the module on.
     *
     * Published, not "on the site": a link may open a page of the site too,
     * in the reading layout. What it must never open is a draft - the
     * preview is for that, and it expires.
     */
    private function isReadable(PostReadingLinkInterface $link): bool
    {
        $post = $link->getPost();

        return $this->editorialContext->isPostsEnabled()
            && $link->isUsable(new DateTimeImmutable())
            && $post->isPublished()
            && !$post->isTrashed();
    }

    private function isUnlocked(Request $request, string $token): bool
    {
        if (!$request->hasSession()) {
            return false;
        }

        $unlocked = $request->getSession()->get(self::UNLOCKED, []);

        return is_array($unlocked) && true === ($unlocked[$token] ?? false);
    }

    /**
     * This same link in each language the publication is written in.
     *
     * @return array<string, string>
     */
    private function localeUrls(PostInterface $post, string $token): array
    {
        $urls = [];

        foreach ($this->locales->writtenIn($post) as $code) {
            $urls[$code] = $this->generateUrl('post_reading_show', ['token' => $token, 'locale' => $code]);
        }

        return $urls;
    }
}
