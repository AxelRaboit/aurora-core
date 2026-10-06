<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\View;

use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Aurora\Module\Studio\Deck\Repository\DeckCategoryRepository;
use Aurora\Module\Studio\Deck\Repository\DeckRepository;
use Aurora\Module\Studio\Deck\Serializer\DeckSerializer;
use Aurora\Module\Studio\Deck\Service\DeckPictures;
use Aurora\Module\Studio\Deck\Share\Entity\DeckShareLinkInterface;
use Aurora\Module\Studio\Deck\Share\Repository\DeckShareLinkRepository;
use Aurora\Module\Studio\Deliverable\Slides\SlideEditorOptions;
use Aurora\Module\Studio\StudioContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use const DATE_ATOM;

final readonly class DecksViewBuilder
{
    public function __construct(
        private DeckRepository $deckRepository,
        private DeckCategoryRepository $categoryRepository,
        private CustomerRepository $customerRepository,
        private DeckSerializer $serializer,
        private DeckShareLinkRepository $shareLinks,
        private DeckPictures $deckPictures,
        private StudioContext $studioContext,
        private PathTemplateGenerator $pathTemplates,
        private UrlGeneratorInterface $urlGenerator,
        private SlideEditorOptions $editorOptions,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {}

    /**
     * The list, whole, filtered in the page.
     *
     * Same sizing call as the customer list: a deck is looked for by eye among
     * a few dozen, and a round trip per keystroke would answer slower than the
     * page already can. The paginated shape is one repository method away.
     *
     * @return array<string, mixed>
     */
    public function indexView(): array
    {
        $counts = $this->deckRepository->countSlidesByDeck();

        return [
            'decks' => array_map(
                fn (DeckInterface $deck): array => $this->serializer->summary($deck, $counts[$deck->getId()] ?? 0),
                $this->deckRepository->findAllForList(),
            ),
            'categories' => array_map(
                $this->serializer->category(...),
                $this->categoryRepository->findOrdered(),
            ),
            'customers' => $this->customerOptions(),
            'layouts' => $this->editorOptions->layouts(),
            ...$this->paths(),
        ];
    }

    /**
     * One deck's own page: the deck, its slides, and where to write them.
     *
     * The layouts come along because the editor needs to know which fields a
     * shape offers before anybody picks it, and the list of pictures because a
     * media slot has to offer something to choose from.
     *
     * @return array<string, mixed>
     */
    public function showView(DeckInterface $deck): array
    {
        return [
            'deck' => $this->serializer->full($deck),
            ...$this->editorOptions->all(),
            'fontUploadPath' => $this->urlGenerator->generate('suite_studio_deck_font_upload'),
            'appearancePath' => $this->urlGenerator->generate('suite_studio_deck_appearance', ['id' => $deck->getId()]),
            'backPath' => $this->urlGenerator->generate('suite_studio_decks'),
            'printPath' => $this->urlGenerator->generate('suite_studio_deck_print', ['id' => $deck->getId()]),
            'presenterPath' => $this->urlGenerator->generate('suite_studio_deck_presenter', ['id' => $deck->getId()]),
            'shareCreatePath' => $this->urlGenerator->generate('suite_studio_deck_share_create', ['id' => $deck->getId()]),
            'shareDeletePath' => $this->pathTemplates->generate('suite_studio_deck_share_delete', ['id' => $deck->getId(), 'linkId' => '__linkId__']),
            'shareHidePath' => $this->pathTemplates->generate('suite_studio_deck_share_hide', ['id' => $deck->getId(), 'linkId' => '__linkId__']),
            'shareRevokePath' => $this->pathTemplates->generate('suite_studio_deck_share_revoke', ['id' => $deck->getId(), 'linkId' => '__linkId__']),
            ...$this->sharePayload($deck),
            'slideCreatePath' => $this->urlGenerator->generate('suite_studio_deck_slide_create', ['id' => $deck->getId()]),
            'slideUpdatePath' => $this->pathTemplates->generate('suite_studio_deck_slide_update', ['id' => $deck->getId(), 'slideId' => '__slideId__']),
            'slideDeletePath' => $this->pathTemplates->generate('suite_studio_deck_slide_delete', ['id' => $deck->getId(), 'slideId' => '__slideId__']),
            'slideDuplicatePath' => $this->pathTemplates->generate('suite_studio_deck_slide_duplicate', ['id' => $deck->getId(), 'slideId' => '__slideId__']),
            'slideReorderPath' => $this->urlGenerator->generate('suite_studio_deck_slide_reorder', ['id' => $deck->getId()]),
        ];
    }

    /** @return array<string, mixed> */
    public function deckPayload(DeckInterface $deck): array
    {
        return ['deck' => $this->serializer->full($deck)];
    }

    /**
     * A deck's look after a write, without its slides.
     *
     * The panel changes colours, not content, and the whole deck would be the
     * slides sent back for three hexadecimal strings the page then has to pick
     * out of them.
     *
     * @return array<string, mixed>
     */
    public function appearancePayload(DeckInterface $deck): array
    {
        return [
            'theme' => $deck->getTheme()->value,
            'style' => $deck->getStyle(),
            'appearance' => $this->serializer->appearanceOf($deck),
        ];
    }

    /**
     * One deck's share links, revoked ones included.
     *
     * The revoked are shown struck through rather than hidden: "this link no
     * longer works" is exactly the answer somebody is looking for when they
     * come back here after having sent one.
     *
     * @return array<string, mixed>
     */
    public function sharePayload(DeckInterface $deck): array
    {
        return [
            // Answered with the links rather than with the deck: it is only a
            // problem once there is somebody who cannot see the picture, and
            // the panel that creates links is where the author is standing
            // when that becomes true.
            'withheldPictures' => $this->deckPictures->withheldIn($deck),
            'shareLinks' => array_map(
                fn (DeckShareLinkInterface $link): array => [
                    'id' => $link->getId(),
                    'label' => $link->getLabel(),
                    'url' => $this->urlGenerator->generate(
                        'public_deck_show',
                        ['token' => $link->getToken()],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    ),
                    'expiresAt' => $link->getExpiresAt()?->format(DATE_ATOM),
                    'revokedAt' => $link->getRevokedAt()?->format(DATE_ATOM),
                    'hidden' => $link->isHidden(),
                    'lastUsedAt' => $link->getLastUsedAt()?->format(DATE_ATOM),
                    'openCount' => $link->getOpenCount(),
                    'locked' => $link->isLocked(),
                    'createdAt' => $link->getCreatedAt()->format(DATE_ATOM),
                ],
                $this->shareLinks->findForDeck($deck),
            ),
        ];
    }

    /**
     * The categories alone, for the answer to a category write.
     *
     * The whole index view would rebuild the deck list and the customer list
     * to send back a handful of rows the page already has.
     *
     * @return array<string, mixed>
     */
    public function categoriesPayload(): array
    {
        return [
            'categories' => array_map(
                $this->serializer->category(...),
                $this->categoryRepository->findOrdered(),
            ),
        ];
    }

    /**
     * The customers a deck can name, or an empty list when the module is off
     * or the reader may not see the customer list.
     *
     * Empty rather than absent: the page draws the picker either way and an
     * empty one reads as "nobody to pick", which is the truth when customers
     * are switched off. A missing key would be a page crashing on a module
     * somebody legitimately turned off.
     *
     * @return list<array{id: int, legalName: string}>
     */
    private function customerOptions(): array
    {
        if (!$this->studioContext->areCustomersEnabled()) {
            return [];
        }

        // The whole customer list is the customer screen's to show. Somebody
        // who may edit decks but not see customers was handed every company
        // name through this picker.
        if (!$this->authorizationChecker->isGranted('studio.customers.view')) {
            return [];
        }

        return array_map(
            static fn (CustomerInterface $customer): array => [
                'id' => (int) $customer->getId(),
                'legalName' => $customer->getLegalName(),
            ],
            $this->customerRepository->findAllOrdered(),
        );
    }

    /** @return array<string, string> */
    private function paths(): array
    {
        return [
            'showPath' => $this->pathTemplates->generate('suite_studio_deck', ['id' => '__id__']),
            'createPath' => $this->urlGenerator->generate('suite_studio_decks_create'),
            'importPath' => $this->urlGenerator->generate('suite_studio_decks_import'),
            'updatePath' => $this->pathTemplates->generate('suite_studio_decks_update', ['id' => '__id__']),
            'deletePath' => $this->pathTemplates->generate('suite_studio_decks_delete', ['id' => '__id__']),
            'duplicatePath' => $this->pathTemplates->generate('suite_studio_decks_duplicate', ['id' => '__id__']),
            'categoryCreatePath' => $this->urlGenerator->generate('suite_studio_decks_category_create'),
            'categoryUpdatePath' => $this->pathTemplates->generate('suite_studio_decks_category_update', ['id' => '__id__']),
            'categoryDeletePath' => $this->pathTemplates->generate('suite_studio_decks_category_delete', ['id' => '__id__']),
            'categoryReorderPath' => $this->urlGenerator->generate('suite_studio_decks_category_reorder'),
        ];
    }
}
