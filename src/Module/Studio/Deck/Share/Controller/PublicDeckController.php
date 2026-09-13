<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Share\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Module\Studio\Deck\Serializer\DeckSerializer;
use Aurora\Module\Studio\Deck\Share\Entity\DeckShareLinkInterface;
use Aurora\Module\Studio\Deck\Share\Repository\DeckShareLinkRepository;
use Aurora\Module\Studio\StudioContext;
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
 * A deck, read by somebody holding its address and nothing else.
 *
 * **Everything this page can reach is decided by the link**, the way
 * `SharedNoteScope` decides it for a note: no identifier from the request ever
 * widens the set. There is one deck behind one token, and no route here takes
 * a deck id at all.
 *
 * Outside the `/backend` firewall on purpose - the reader has no account, and
 * that is the point of the address existing.
 */
#[Route('/decks', name: 'public_deck')]
final class PublicDeckController extends AbstractController
{
    /**
     * Which links this browser has already unlocked, in its own session.
     *
     * A session key rather than a cookie on the link: a reader who opened three
     * protected decks should not have to type three passwords again because one
     * of them expired, and the session is already the thing that ends when they
     * close the browser.
     */
    private const string UNLOCKED = 'studio.deck_share.unlocked';

    public function __construct(
        private readonly DeckShareLinkRepository $links,
        private readonly DeckSerializer $serializer,
        private readonly StudioContext $studioContext,
        private readonly EntityManagerInterface $entityManager,
        // The `deck_share_password` limiter declared in config, autowired by
        // name the way the contract controller takes its two.
        private readonly RateLimiterFactoryInterface $deckSharePasswordLimiter,
    ) {}

    #[Route('/{token}', name: '_show', requirements: ['token' => '[a-f0-9]{64}'], methods: [HttpMethodEnum::Get->value])]
    public function show(string $token, Request $request): Response
    {
        $link = $this->links->findByToken($token);
        $now = new DateTimeImmutable();

        // One answer for "no such link", "revoked" and "expired", and it is the
        // same 404 a wrong address gets. Telling a holder which of the three it
        // is would confirm that the address was real, which is the one thing a
        // guessed token must not learn.
        if (!$link instanceof DeckShareLinkInterface || !$link->isUsable($now)) {
            throw $this->createNotFoundException();
        }

        // A module switched off takes its public pages with it, like every
        // other front in Aurora: leaving them up would publish decks from a
        // module the owner believes is closed.
        if (!$this->studioContext->isBackendEnabled() || !$this->studioContext->areDecksEnabled()) {
            throw $this->createNotFoundException();
        }

        // Locked and not yet opened in this session: the password page, which
        // says nothing about the deck behind it - not its title, not its
        // author, not how many slides it holds.
        if ($link->isLocked() && !$this->isUnlocked($request, $token)) {
            return $this->render('@Studio/public/deck_locked.html.twig', [
                'token' => $token,
                'failed' => false,
            ]);
        }

        $link->touch($now);
        $this->entityManager->flush();

        // `full()` carries the speaker notes, which are the presenter's and
        // never the audience's, so they are dropped here rather than trusted
        // to stay out of the template.
        $deck = $this->serializer->full($link->getDeck());
        $deck['slides'] = array_map(
            static function (array $slide): array {
                unset($slide['speakerNotes']);

                return $slide;
            },
            $deck['slides'],
        );

        return $this->render('@Studio/public/deck.html.twig', [
            'deck' => $deck,
            'expiresAt' => $link->getExpiresAt(),
        ]);
    }

    /**
     * The password, checked.
     *
     * A wrong password answers exactly what a wrong address answers: the same
     * page, the same words. Telling the two apart would confirm to somebody
     * guessing addresses that this one is real, which is the single thing a
     * guessed token must not learn.
     */
    #[Route('/{token}/unlock', name: '_unlock', requirements: ['token' => '[a-f0-9]{64}'], methods: [HttpMethodEnum::Post->value])]
    public function unlock(string $token, Request $request): Response
    {
        if (false === $this->deckSharePasswordLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        $link = $this->links->findByToken($token);
        $password = (string) $request->request->get('password', '');

        if (!$link instanceof DeckShareLinkInterface
            || !$link->isUsable(new DateTimeImmutable())
            || !$link->isLocked()
            || !password_verify($password, (string) $link->getPasswordHash())
        ) {
            return $this->render('@Studio/public/deck_locked.html.twig', [
                'token' => $token,
                'failed' => true,
            ]);
        }

        $unlocked = $request->getSession()->get(self::UNLOCKED, []);
        $unlocked[$token] = true;
        $request->getSession()->set(self::UNLOCKED, $unlocked);

        return $this->redirectToRoute('public_deck_show', ['token' => $token]);
    }

    private function isUnlocked(Request $request, string $token): bool
    {
        if (!$request->hasSession()) {
            return false;
        }

        $unlocked = $request->getSession()->get(self::UNLOCKED, []);

        return is_array($unlocked) && true === ($unlocked[$token] ?? false);
    }
}
