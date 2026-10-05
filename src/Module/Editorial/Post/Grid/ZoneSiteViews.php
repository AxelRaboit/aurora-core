<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Grid;

use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Entity\FormTranslationInterface;
use Aurora\Module\Editorial\Form\Repository\FormRepository;
use Aurora\Module\Editorial\Form\Serializer\FormSerializer;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Entity\PostTranslationInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Service\BlocksRenderer;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermTranslationInterface;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyRepository;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyTermRepository;
use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Aurora\Module\Studio\Deck\Repository\DeckRepository;
use Aurora\Module\Studio\Deck\Share\Repository\DeckShareLinkRepository;
use DateTimeImmutable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The zones that plug a feature of the site into a page: a form, a search,
 * comments, a deck, taxonomy terms, and tabs.
 *
 * Split off {@see GridViewBuilder}, which orchestrates.
 */
final readonly class ZoneSiteViews
{
    public function __construct(
        private FormRepository $formRepository,
        private FormSerializer $formSerializer,
        private PostTypeRepository $postTypeRepository,
        private PostRepository $postRepository,
        private UrlGeneratorInterface $urlGenerator,
        private DeckRepository $deckRepository,
        private DeckShareLinkRepository $deckShareLinkRepository,
        private TaxonomyRepository $taxonomyRepository,
        private TaxonomyTermRepository $taxonomyTermRepository,
        private BlocksRenderer $blocksRenderer,
        private PathTemplateGenerator $pathTemplates,
    ) {}

    /**
     * A form, ready for the same Vue component its own page mounts.
     *
     * Null on every "no" - no form named, none found, switched off, or not
     * translated here - so the template leaves the zone out rather than
     * drawing an empty box. An inactive form is a draft the site has not
     * published: the page it would have had 404s, and a zone should not be a
     * way around that.
     *
     * @return array{title: string, description: string|null, data: array<string, mixed>, submitPath: string}|null
     */
    public function formView(?int $formId, string $locale): ?array
    {
        if (null === $formId) {
            return null;
        }

        $form = $this->formRepository->findForReader($formId);
        if (!$form instanceof FormInterface || !$form->isActive()) {
            return null;
        }

        $translation = $form->getTranslation($locale);
        if (!$translation instanceof FormTranslationInterface) {
            return null;
        }

        return [
            'title' => $translation->getTitle(),
            'description' => $translation->getDescription(),
            'data' => $this->formSerializer->serializeForReader($form, $locale),
            // The same route the form's own page posts to, so one endpoint
            // answers wherever the form is drawn - and its rate limit and its
            // validation come along unchanged.
            'submitPath' => $this->urlGenerator->generate('editorial_form_submit', [
                'locale' => $locale,
                'slug' => $translation->getSlug(),
            ]),
        ];
    }

    /**
     * Where a search field posts, and what it is allowed to find.
     *
     * The endpoint is the one the sequence search already uses, and so is the
     * component that draws it: a second search built for this zone would be a
     * second set of empty states, a second debounce and a second thing to keep
     * in step.
     *
     * An empty type means the whole site, which is the answer that needs no
     * setting up.
     *
     * @param array<string, mixed> $zone
     *
     * @return array{searchUrl: string}
     */
    public function searchView(array $zone, string $locale): array
    {
        $parameters = ['locale' => $locale];

        $postType = null === $zone['postTypeId']
            ? null
            : $this->postTypeRepository->find($zone['postTypeId']);

        if ($postType instanceof PostTypeInterface) {
            $parameters['type'] = $postType->getSlug();
        }

        return ['searchUrl' => $this->urlGenerator->generate('editorial_home_search', $parameters)];
    }

    /**
     * The three addresses the comment thread needs, for the page it sits on.
     *
     * Built from the current publication rather than from anything on the
     * zone: a thread belongs to the page it is drawn on, and offering an
     * author a choice there would be offering them a way to put one page's
     * replies under another.
     *
     * @return array{listPath: string, submitPath: string, reactPathTemplate: string}|null
     */
    public function commentsView(?int $currentPostId, string $locale): ?array
    {
        // Its own lookup rather than the shared prefetch: the prefetch gathers
        // what zones *name*, and this zone names nothing - it is about the
        // page it stands on. One query, and only on a page carrying the zone.
        $post = null === $currentPostId ? null : $this->postRepository->find($currentPostId);

        if (!$post instanceof PostInterface) {
            return null;
        }

        $translation = $post->getTranslation($locale);
        $typeSlug = $post->getPostType()->getSlug();

        if (!$translation instanceof PostTranslationInterface || '' === $translation->getSlug()) {
            return null;
        }

        $parameters = ['locale' => $locale, 'postTypeSlug' => $typeSlug, 'slug' => $translation->getSlug()];

        return [
            'listPath' => $this->urlGenerator->generate('editorial_post_comments', $parameters),
            'submitPath' => $this->urlGenerator->generate('editorial_post_comment_submit', $parameters),
            // A template with a hole in it, so through the generator that
            // allows one: `__commentId__` is not the `\d+` the route wants,
            // and the plain generator refused it while rendering the page -
            // a 500 in dev and in test, hidden in production by
            // `strict_requirements: null`.
            'reactPathTemplate' => $this->pathTemplates->generate(
                'editorial_comment_react',
                [...$parameters, 'commentId' => '__commentId__'],
            ),
        ];
    }

    /**
     * A presentation, but only one somebody has actually published.
     *
     * A deck is an internal document until a share link exists for it, so a
     * zone naming one with no live link draws nothing. A revoked link, an
     * expired one and one behind a password are all "no": the last because a
     * page cannot ask for a password on the deck's behalf, and an iframe onto
     * the unlock form would be a locked door drawn inside an article.
     *
     * Same origin, so nothing here loads a third party - this is the site
     * showing its own page inside its own page.
     *
     * @return array{url: string, title: string}|null
     */
    public function deckView(?int $deckId): ?array
    {
        if (null === $deckId) {
            return null;
        }

        $deck = $this->deckRepository->findLive($deckId);

        if (!$deck instanceof DeckInterface) {
            return null;
        }

        $now = new DateTimeImmutable();

        foreach ($this->deckShareLinkRepository->findForDeck($deck) as $link) {
            if (null !== $link->getRevokedAt()) {
                continue;
            }

            if (null !== $link->getPasswordHash()) {
                continue;
            }

            $expiresAt = $link->getExpiresAt();

            if (null !== $expiresAt && $expiresAt < $now) {
                continue;
            }

            return [
                'url' => $this->urlGenerator->generate('public_deck_show', ['token' => $link->getToken()]),
                'title' => $deck->getTitle(),
            ];
        }

        return null;
    }

    /**
     * The terms of one taxonomy, in the order the suite arranges them.
     *
     * One query per zone, like the list beside it, and for the same reason: a
     * page that answers the question on every render is a page nobody has to
     * remember to edit.
     *
     * A term with nothing written in this language is dropped rather than
     * shown under its slug. A word an author never wrote is not a word to put
     * in front of a reader, and a link labelled with a slug reads as a fault.
     *
     * @param array<string, mixed> $zone
     *
     * @return array{name: string, entries: list<array{label: string, url: string}>}
     */
    public function termsView(array $zone, string $locale): array
    {
        $taxonomy = null === $zone['taxonomyId']
            ? null
            : $this->taxonomyRepository->find($zone['taxonomyId']);

        if (!$taxonomy instanceof TaxonomyInterface) {
            return ['name' => '', 'entries' => []];
        }

        $entries = [];
        foreach ($this->taxonomyTermRepository->findByTaxonomyOrderedForDisplay($taxonomy) as $term) {
            $translation = $term->getTranslation($locale);
            if (!$translation instanceof TaxonomyTermTranslationInterface) {
                continue;
            }

            if ('' === $translation->getName()) {
                continue;
            }

            $entries[] = [
                'label' => $translation->getName(),
                'url' => $this->urlGenerator->generate('editorial_term', [
                    'locale' => $locale,
                    'taxonomySlug' => $taxonomy->getSlug(),
                    'termSlug' => $translation->getSlug(),
                ]),
            ];
        }

        return [
            // The taxonomy's own name in this language, for a zone that wants
            // to say what it is listing. Empty when untranslated, and the
            // template draws no heading rather than an empty one.
            'name' => $taxonomy->getTranslation($locale)?->getLabel() ?? '',
            'entries' => $entries,
        ];
    }

    /**
     * The panels of a tabs zone, each with its label and its body.
     *
     * The body goes through the same renderer a text zone's does, so a panel
     * is sanitised on exactly the path everything else already takes - there
     * is no second way into the markup here.
     *
     * A panel with no label and nothing written is dropped, for the reason a
     * blank item entry is: a row typed into tomorrow belongs in the editor and
     * not on the page. The editor keeps them, which is what `forEditor` is for.
     *
     * Ids come from the stored list rather than from a counter, because that
     * is what the labels and the panels are tied together by in the markup -
     * and a page with two tab zones must not have them fighting over `panel-1`.
     *
     * @param array<string, mixed> $zone
     * @param array<string, mixed> $held
     *
     * @return array{zoneId: string, panels: list<array{id: string, label: string, html: string}>}
     */
    public function tabsView(array $zone, array $held, string $locale, bool $forEditor): array
    {
        $texts = is_array($held['items'] ?? null) ? $held['items'] : [];
        $panels = [];

        foreach (is_array($zone['items'] ?? null) ? $zone['items'] : [] as $panel) {
            $id = $panel['id'] ?? null;

            if (!is_string($id)) {
                continue;
            }

            $words = is_array($texts[$id] ?? null) ? $texts[$id] : [];
            $label = (string) ($words['title'] ?? '');
            $html = $this->blocksRenderer->render(
                is_array($words['blocks'] ?? null) ? $words['blocks'] : [],
                $locale,
            );

            if (!$forEditor && '' === $label && '' === mb_trim(strip_tags($html))) {
                continue;
            }

            $panels[] = ['id' => $id, 'label' => $label, 'html' => $html];
        }

        return ['zoneId' => (string) $zone['id'], 'panels' => $panels];
    }
}
