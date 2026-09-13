<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\View;

use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Aurora\Module\Studio\Deck\Enum\DeckFontPairEnum;
use Aurora\Module\Studio\Deck\Enum\DeckLogoPlacementEnum;
use Aurora\Module\Studio\Deck\Enum\DeckThemeEnum;
use Aurora\Module\Studio\Deck\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deck\Repository\DeckCategoryRepository;
use Aurora\Module\Studio\Deck\Repository\DeckRepository;
use Aurora\Module\Studio\Deck\Serializer\DeckSerializer;
use Aurora\Module\Studio\Deck\Share\Entity\DeckShareLinkInterface;
use Aurora\Module\Studio\Deck\Share\Repository\DeckShareLinkRepository;
use Aurora\Module\Studio\StudioContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use const DATE_ATOM;

final readonly class DecksViewBuilder
{
    public function __construct(
        private DeckRepository $deckRepository,
        private DeckCategoryRepository $categoryRepository,
        private CustomerRepository $customerRepository,
        private DeckSerializer $serializer,
        private DeckShareLinkRepository $shareLinks,
        private StudioContext $studioContext,
        private PathTemplateGenerator $pathTemplates,
        private UrlGeneratorInterface $urlGenerator,
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
            'layouts' => $this->layoutOptions(),
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
            'layouts' => $this->layoutOptions(),
            'commonSlots' => SlideLayoutEnum::commonSlots(),
            'listSlots' => SlideLayoutEnum::listSlots(),
            'themes' => $this->themeOptions(),
            'fontPairs' => $this->fontPairOptions(),
            'logoPlacements' => $this->logoPlacementOptions(),
            'appearancePath' => $this->urlGenerator->generate('backend_studio_deck_appearance', ['id' => $deck->getId()]),
            'backPath' => $this->urlGenerator->generate('backend_studio_decks'),
            'printPath' => $this->urlGenerator->generate('backend_studio_deck_print', ['id' => $deck->getId()]),
            'presenterPath' => $this->urlGenerator->generate('backend_studio_deck_presenter', ['id' => $deck->getId()]),
            'shareCreatePath' => $this->urlGenerator->generate('backend_studio_deck_share_create', ['id' => $deck->getId()]),
            'shareRevokePath' => $this->pathTemplates->generate('backend_studio_deck_share_revoke', ['id' => $deck->getId(), 'linkId' => '__linkId__']),
            ...$this->sharePayload($deck),
            'slideCreatePath' => $this->urlGenerator->generate('backend_studio_deck_slide_create', ['id' => $deck->getId()]),
            'slideUpdatePath' => $this->pathTemplates->generate('backend_studio_deck_slide_update', ['id' => $deck->getId(), 'slideId' => '__slideId__']),
            'slideDeletePath' => $this->pathTemplates->generate('backend_studio_deck_slide_delete', ['id' => $deck->getId(), 'slideId' => '__slideId__']),
            'slideDuplicatePath' => $this->pathTemplates->generate('backend_studio_deck_slide_duplicate', ['id' => $deck->getId(), 'slideId' => '__slideId__']),
            'slideReorderPath' => $this->urlGenerator->generate('backend_studio_deck_slide_reorder', ['id' => $deck->getId()]),
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
                    'lastUsedAt' => $link->getLastUsedAt()?->format(DATE_ATOM),
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
     * The customers a deck can name, or an empty list when the module is off.
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

        return array_map(
            static fn (CustomerInterface $customer): array => [
                'id' => (int) $customer->getId(),
                'legalName' => $customer->getLegalName(),
            ],
            $this->customerRepository->findAllOrdered(),
        );
    }

    /** @return list<array{value: string, labelKey: string, slots: list<string>}> */
    private function layoutOptions(): array
    {
        return array_map(
            static fn (SlideLayoutEnum $layout): array => [
                'value' => $layout->value,
                'labelKey' => $layout->labelKey(),
                'slots' => $layout->slots(),
            ],
            SlideLayoutEnum::cases(),
        );
    }

    /**
     * The themes, each carrying the colours it starts from.
     *
     * The palette travels with the option so the picker can draw the theme
     * rather than name it: five words in a select say nothing about what they
     * look like, and the whole point of the list is the look.
     *
     * @return list<array{value: string, labelKey: string, palette: array{background: string, ink: string, accent: string}}>
     */
    private function themeOptions(): array
    {
        return array_map(
            static fn (DeckThemeEnum $theme): array => [
                'value' => $theme->value,
                'labelKey' => $theme->labelKey(),
                'palette' => $theme->palette(),
            ],
            DeckThemeEnum::cases(),
        );
    }

    /** @return list<array{value: string, labelKey: string, heading: string, body: string}> */
    private function fontPairOptions(): array
    {
        return array_map(
            static fn (DeckFontPairEnum $pair): array => [
                'value' => $pair->value,
                'labelKey' => $pair->labelKey(),
                'heading' => $pair->heading(),
                'body' => $pair->body(),
            ],
            DeckFontPairEnum::cases(),
        );
    }

    /** @return list<array{value: string, labelKey: string}> */
    private function logoPlacementOptions(): array
    {
        return array_map(
            static fn (DeckLogoPlacementEnum $placement): array => [
                'value' => $placement->value,
                'labelKey' => $placement->labelKey(),
            ],
            DeckLogoPlacementEnum::cases(),
        );
    }

    /** @return array<string, string> */
    private function paths(): array
    {
        return [
            'showPath' => $this->pathTemplates->generate('backend_studio_deck', ['id' => '__id__']),
            'createPath' => $this->urlGenerator->generate('backend_studio_decks_create'),
            'updatePath' => $this->pathTemplates->generate('backend_studio_decks_update', ['id' => '__id__']),
            'deletePath' => $this->pathTemplates->generate('backend_studio_decks_delete', ['id' => '__id__']),
            'duplicatePath' => $this->pathTemplates->generate('backend_studio_decks_duplicate', ['id' => '__id__']),
            'categoryCreatePath' => $this->urlGenerator->generate('backend_studio_decks_category_create'),
            'categoryUpdatePath' => $this->pathTemplates->generate('backend_studio_decks_category_update', ['id' => '__id__']),
            'categoryDeletePath' => $this->pathTemplates->generate('backend_studio_decks_category_delete', ['id' => '__id__']),
        ];
    }
}
