<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Studio\Deck\Entity\Deck;
use Aurora\Module\Studio\Deck\Entity\SlideInterface;
use Aurora\Module\Studio\Deck\Enum\DeckThemeEnum;
use Aurora\Module\Studio\Deck\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deck\Manager\DeckManager;
use Aurora\Module\Studio\Deck\Serializer\DeckSerializer;
use Aurora\Module\Studio\Deck\Share\Entity\DeckShareLink;
use Aurora\Module\Studio\Deck\Share\Repository\DeckShareLinkRepository;
use Aurora\Module\Studio\Deck\View\DecksViewBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_array;
use function is_int;
use function is_string;
use function mb_substr;

/**
 * One deck's slides: the page that composes them, and the four writes it makes.
 *
 * A controller of its own rather than more methods on `DecksController`,
 * because these routes are nested under a deck and every one of them has to
 * check that the slide it was handed belongs to the deck in the address. That
 * check is the reason the class exists, and it is cheap to forget once.
 */
#[Route('/backend/studio/decks/{id}', name: 'backend_studio_deck', requirements: ['id' => '\d+'])]
#[IsGranted('studio.decks.view')]
class SlidesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly DeckManager $deckManager,
        protected readonly DeckSerializer $serializer,
        protected readonly DecksViewBuilder $viewBuilder,
        protected readonly DeckShareLinkRepository $shareLinks,
        protected readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function show(Deck $deck): Response
    {
        return $this->render('@Studio/backend/decks/show.html.twig', $this->viewBuilder->showView($deck));
    }

    /**
     * The deck on paper, on a page that carries nothing else.
     *
     * `?print=1` when the editor opens it, so the dialog is already up; without
     * it the page is a readable stack of the whole deck, which is a use of its
     * own.
     */
    #[Route('/print', name: '_print', methods: [HttpMethodEnum::Get->value])]
    public function print(Deck $deck, Request $request): Response
    {
        return $this->render('@Studio/backend/decks/print.html.twig', [
            'deck' => $this->serializer->full($deck),
            'autoPrint' => $request->query->getBoolean('print'),
        ]);
    }

    /**
     * The deck's appearance, written from the panel on its own page.
     *
     * Its own route rather than a field on the deck update, because the two are
     * edited from two different screens: the title and the client are set in
     * the list's modal, the look is set while looking at a slide. Posting the
     * whole deck from here would mean the panel carrying a title it never shows
     * and overwriting whatever the list had just changed.
     */
    #[Route('/appearance', name: '_appearance', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.decks.edit')]
    public function appearance(Deck $deck, Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);

        $theme = DeckThemeEnum::tryFrom(is_string($payload['theme'] ?? null) ? $payload['theme'] : '');

        if (null === $theme) {
            return $this->jsonInvalidInput(['theme' => 'backend.studio.decks.errors.theme_unknown']);
        }

        $this->deckManager->writeAppearance($deck, $theme, is_array($payload['style'] ?? null) ? $payload['style'] : []);
        $this->entityManager->flush();

        return $this->jsonSuccess($this->viewBuilder->appearancePayload($deck));
    }

    /**
     * The presenter's own screen.
     *
     * Behind `studio.decks.view` like the rest of this controller, and that is
     * the whole difference with the public share link: the notes are the
     * presenter's, so the page that shows them is one somebody signed in to
     * reach. `PublicDeckController` strips them from its payload for the same
     * reason, one door further out.
     */
    #[Route('/presenter', name: '_presenter', methods: [HttpMethodEnum::Get->value])]
    public function presenter(Deck $deck): Response
    {
        return $this->render('@Studio/backend/decks/presenter.html.twig', [
            'deck' => $this->serializer->full($deck),
        ]);
    }

    #[Route('/slides/create', name: '_slide_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.decks.edit')]
    public function create(Deck $deck, Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $layout = SlideLayoutEnum::tryFrom(is_string($payload['layout'] ?? null) ? $payload['layout'] : '');

        if (null === $layout) {
            return $this->jsonInvalidInput(['layout' => 'backend.studio.decks.errors.layout_unknown']);
        }

        $slide = $this->deckManager->addSlide($deck, $layout);
        $this->entityManager->flush();

        return $this->jsonSuccess(['slide' => $this->serializer->slide($slide)]);
    }

    #[Route('/slides/{slideId}/update', name: '_slide_update', requirements: ['slideId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.decks.edit')]
    public function update(Deck $deck, int $slideId, Request $request): JsonResponse
    {
        $slide = $this->slideOf($deck, $slideId);

        if (!$slide instanceof SlideInterface) {
            return $this->jsonNotFound();
        }

        $payload = $this->decodeJson($request);

        // The layout may change on an existing slide, and that is on purpose:
        // a slide written as bullets often wants to become a section divider
        // once the deck has a shape. The content is then whitelisted against
        // the *new* layout, so the slots it no longer has simply go.
        $layout = SlideLayoutEnum::tryFrom(is_string($payload['layout'] ?? null) ? $payload['layout'] : '');
        if (null !== $layout) {
            $slide->setLayout($layout);
        }

        $this->deckManager->writeContent($slide, is_array($payload['content'] ?? null) ? $payload['content'] : []);
        $slide->setSpeakerNotes(is_string($payload['speakerNotes'] ?? null) && '' !== $payload['speakerNotes'] ? $payload['speakerNotes'] : null);

        $this->entityManager->flush();

        return $this->jsonSuccess(['slide' => $this->serializer->slide($slide)]);
    }

    /**
     * Copy a slide inside its own deck.
     *
     * The `edit` privilege and not `create`: the deck already exists and its
     * row count does not change, unlike duplicating a whole deck, which writes
     * a new one and asks for `create` accordingly.
     */
    #[Route('/slides/{slideId}/duplicate', name: '_slide_duplicate', requirements: ['slideId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.decks.edit')]
    public function duplicateSlide(Deck $deck, int $slideId): JsonResponse
    {
        $slide = $this->slideOf($deck, $slideId);

        if (!$slide instanceof SlideInterface) {
            return $this->jsonNotFound();
        }

        $copy = $this->deckManager->duplicateSlide($slide);
        $this->entityManager->flush();

        return $this->jsonSuccess(['slide' => $this->serializer->slide($copy)]);
    }

    #[Route('/slides/{slideId}/delete', name: '_slide_delete', requirements: ['slideId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.decks.edit')]
    public function delete(Deck $deck, int $slideId): JsonResponse
    {
        $slide = $this->slideOf($deck, $slideId);

        if (!$slide instanceof SlideInterface) {
            return $this->jsonNotFound();
        }

        $deck->removeSlide($slide);
        $this->entityManager->remove($slide);
        $this->entityManager->flush();

        return $this->jsonSuccess();
    }

    /**
     * The whole order, sent at once.
     *
     * Not "move this slide up": a drag lands somewhere arbitrary, and a
     * sequence of swaps to describe it is a sequence that can arrive out of
     * order. The page already knows the order it is showing, so it says it.
     */
    #[Route('/slides/reorder', name: '_slide_reorder', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.decks.edit')]
    public function reorder(Deck $deck, Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $ids = is_array($payload['orderedIds'] ?? null) ? $payload['orderedIds'] : [];

        $this->deckManager->reorderSlides($deck, array_values(array_filter($ids, is_int(...))));
        $this->entityManager->flush();

        return $this->jsonSuccess(['deck' => $this->serializer->full($deck)]);
    }

    /**
     * Mint an address that opens this deck without an account.
     *
     * The privilege is `share`, not `edit`: handing a document to somebody
     * outside the application is a different act from writing it, and an
     * account allowed to correct a typo is not automatically allowed to
     * publish the deck.
     */
    #[Route('/share/create', name: '_share_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.decks.share')]
    public function createShare(Deck $deck, Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);

        $link = new DeckShareLink($deck);
        $link->setLabel(is_string($payload['label'] ?? null) ? mb_substr($payload['label'], 0, 120) : '');

        $days = is_int($payload['expiresInDays'] ?? null) ? $payload['expiresInDays'] : null;
        if (null !== $days && $days > 0) {
            $link->setExpiresAt(new DateTimeImmutable(sprintf('+%d days', $days)));
        }

        $this->entityManager->persist($link);
        $this->entityManager->flush();

        return $this->jsonSuccess($this->viewBuilder->sharePayload($deck));
    }

    /**
     * Revoking stamps a date; it never deletes the row.
     *
     * "Who could open this, and until when" is a question worth being able to
     * answer after the fact, and a deleted row answers nothing.
     */
    #[Route('/share/{linkId}/revoke', name: '_share_revoke', requirements: ['linkId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.decks.share')]
    public function revokeShare(Deck $deck, int $linkId): JsonResponse
    {
        $link = $this->shareLinks->find($linkId);

        // Checked against the deck in the address, like a slide: a link id from
        // another deck must not be revocable through a deck the reader happens
        // to hold.
        if (null === $link || $link->getDeck()->getId() !== $deck->getId()) {
            return $this->jsonNotFound();
        }

        $link->revoke(new DateTimeImmutable());
        $this->entityManager->flush();

        return $this->jsonSuccess($this->viewBuilder->sharePayload($deck));
    }

    /**
     * The slide, only if it is this deck's.
     *
     * A slide id from another deck must not be writable through a deck the
     * reader happens to be allowed to open. Reading it off the deck rather
     * than from the repository is what makes that structural rather than a
     * check somebody has to remember.
     */
    private function slideOf(Deck $deck, int $slideId): ?SlideInterface
    {
        foreach ($deck->getSlides() as $slide) {
            if ($slide->getId() === $slideId) {
                return $slide;
            }
        }

        return null;
    }
}
