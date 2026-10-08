<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Controller\Frontend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Frontend\Service\Context;
use Aurora\Core\Frontend\Service\HttpCacheService;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Theme\Service\ThemeResolver;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Entity\PostSlugHistoryInterface;
use Aurora\Module\Editorial\Post\Entity\PostTranslationInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Repository\PostSlugHistoryRepository;
use Aurora\Module\Editorial\Post\Service\PostPageRenderer;
use Aurora\Module\Editorial\Post\View\PageViewBuilder;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyRepository;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyTermRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public pages.
 *
 * The URL shapes overlap on purpose - a two-segment path is both a post
 * under its type and a term under its taxonomy - and routing cannot tell
 * them apart without asking the database. Priorities decide which is tried
 * first; see post() for how the other stays reachable.
 */
class PageController extends AbstractController
{
    use JsonResponseTrait;

    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly PostTypeRepository $postTypeRepository,
        private readonly PostSlugHistoryRepository $slugHistoryRepository,
        private readonly TaxonomyRepository $taxonomyRepository,
        private readonly TaxonomyTermRepository $taxonomyTermRepository,
        private readonly Context $context,
        private readonly ThemeResolver $themeResolver,
        private readonly HttpCacheService $httpCacheService,
        private readonly PostPageRenderer $postPageRenderer,
        private readonly PageViewBuilder $viewBuilder,
    ) {}

    #[Route('/{locale}', name: 'editorial_home', requirements: ['locale' => '[a-z]{2}'], priority: 9)]
    public function home(string $locale, Request $request): Response
    {
        $this->assertActiveLocale($locale);
        $request->setLocale($locale);

        // A site can nominate a post as its front page. When it is missing,
        // unpublished or untranslated here, the listing is the safe answer -
        // a blank home page would be the alternative.
        $homepage = $this->homepagePost($locale);
        if ($homepage instanceof PostInterface) {
            return $this->postPageRenderer->render($homepage, $locale);
        }

        $postType = $this->defaultPostType();
        $result = $this->publishedPage($postType, $this->page($request), $locale);

        return $this->withLocaleHeader($this->render(
            $this->themeResolver->resolve('editorial/home/index'),
            $this->viewBuilder->homeView(
                $locale,
                $result,
                $postType,
                $this->generateUrl('editorial_home_search', ['locale' => $locale]),
            ),
        ), $locale);
    }

    /**
     * The site's search, and the search of one type of content.
     *
     * `?type=documentation` scopes it to that type instead of the one the
     * home page lists. A documentation is read by looking something up, and
     * a search that answered with articles would answer beside the question.
     *
     * Named but unknown is a 404 rather than a silent fall-back to the
     * default type: a caller that asks for a type which does not exist has
     * made a mistake, and returning articles would hide it.
     */
    #[Route('/{locale}/search', name: 'editorial_home_search', requirements: ['locale' => '[a-z]{2}'], methods: [HttpMethodEnum::Get->value], priority: 10)]
    public function search(string $locale, Request $request): JsonResponse
    {
        $this->assertActiveLocale($locale);

        $typeSlug = mb_trim($request->query->getString('type', ''));
        $postType = '' === $typeSlug
            ? $this->defaultPostType()
            : $this->postTypeRepository->findOneBySlug($typeSlug);

        if ('' !== $typeSlug && !$postType instanceof PostTypeInterface) {
            throw $this->createNotFoundException();
        }

        $query = mb_trim($request->query->getString('q', ''));
        $result = $this->publishedPage(
            $postType,
            $this->page($request),
            $locale,
            '' !== $query ? $query : null,
        );

        return $this->jsonSuccess($this->viewBuilder->pageData($result, $locale));
    }

    #[Route('/{locale}/{postTypeSlug}/{slug}', name: 'editorial_post', requirements: ['locale' => '[a-z]{2}'], priority: 5)]
    public function post(string $locale, string $postTypeSlug, string $slug, Request $request): Response
    {
        $this->assertActiveLocale($locale);
        $request->setLocale($locale);

        // Asked inside the type the address names, first. An address is only
        // unique inside its type, and answering with a publication of another
        // one sends the reader off on a permanent redirect to a page they did
        // not ask for - which is what the fall-back below is for, and only
        // when nothing here matched.
        $postType = $this->postTypeRepository->findOneBySlug($postTypeSlug);
        $post = $postType instanceof PostTypeInterface
            ? $this->postRepository->findPublishedBySlug($slug, $locale, $postType->getId())
            : null;

        // `/{locale}/{a}/{b}` is a post under a type and equally a term under a
        // taxonomy. This route wins on priority, so the term route is never
        // reached on its own and the fall-through below is what answers it.
        //
        // It has to come before the unscoped look-up, not after: an address
        // that names a real taxonomy and a real term of it is that term's
        // page. Letting a publication of some unrelated type answer it on the
        // strength of a shared slug sent the reader off on a **permanent**
        // redirect to a page they never asked for, and browsers cache that.
        if (!$post instanceof PostInterface) {
            $found = $this->resolveTerm($postTypeSlug, $slug, $locale);
            if (null !== $found) {
                return $this->renderTerm($locale, $found[0], $found[1], $request);
            }
        }

        $post ??= $this->postRepository->findPublishedBySlug($slug, $locale);

        if (!$post instanceof PostInterface) {
            $redirect = $this->redirectFromSlugHistory($locale, $slug);
            if ($redirect instanceof RedirectResponse) {
                return $redirect;
            }

            // Neither a publication nor a term - the term was asked above -
            // so the same 404 the term route gives, whichever branch got here.
            throw $this->createNotFoundException();
        }

        // The type is part of the URL but not of the identity: a post moved
        // to another type keeps its slug, and the old address should lead
        // somewhere rather than lie.
        if ($post->getPostType()->getSlug() !== $postTypeSlug) {
            return $this->redirectToRoute('editorial_post', [
                'locale' => $locale,
                'postTypeSlug' => $post->getPostType()->getSlug(),
                'slug' => $slug,
            ], HttpStatusEnum::MovedPermanently->value);
        }

        $lastModified = $post->getUpdatedAt();
        $notModified = $this->httpCacheService->checkNotModified($request, $lastModified);
        if ($notModified instanceof Response) {
            return $notModified;
        }

        $response = $this->postPageRenderer->render($post, $locale);
        $this->httpCacheService->setPublicCache($response, $lastModified);

        return $response;
    }

    #[Route('/{locale}/{taxonomySlug}/{termSlug}', name: 'editorial_term', requirements: ['locale' => '[a-z]{2}'], priority: 4)]
    public function term(string $locale, string $taxonomySlug, string $termSlug, Request $request): Response
    {
        $this->assertActiveLocale($locale);
        $request->setLocale($locale);

        $found = $this->resolveTerm($taxonomySlug, $termSlug, $locale);
        if (null === $found) {
            throw $this->createNotFoundException();
        }

        return $this->renderTerm($locale, $found[0], $found[1], $request);
    }

    private function renderTerm(string $locale, TaxonomyInterface $taxonomy, TaxonomyTermInterface $term, Request $request): Response
    {
        // The term and everything under it. A publication is filed under a
        // leaf, so a section that only holds sub-sections holds none of its
        // own: `/fr/section/le-back-office` answered 200 and listed nothing,
        // which reads as a broken page rather than as an empty one. A flat
        // taxonomy has no descendants, so nothing changes for tags.
        $result = $this->postRepository->findPublishedByTerms(
            $this->taxonomyTermRepository->findSelfAndDescendantIds($term),
            $this->page($request),
            $this->postsPerPage(),
            $locale,
        );

        $response = $this->render(
            $this->themeResolver->resolve('editorial/term/index'),
            $this->viewBuilder->termView($locale, $taxonomy, $term, $result),
        );
        $this->httpCacheService->setSharedCache($response);

        return $this->withLocaleHeader($response, $locale);
    }

    #[Route('/{locale}/{postTypeSlug}', name: 'editorial_archive', requirements: ['locale' => '[a-z]{2}'], priority: 3)]
    public function archive(string $locale, string $postTypeSlug, Request $request): Response
    {
        $this->assertActiveLocale($locale);
        $request->setLocale($locale);

        $postType = $this->postTypeRepository->findOneBySlug($postTypeSlug);
        if (!$postType instanceof PostTypeInterface || !$postType->hasArchive()) {
            throw $this->createNotFoundException();
        }

        $result = $this->publishedPage($postType, $this->page($request), $locale);

        $response = $this->render(
            $this->themeResolver->resolve('editorial/archive/index'),
            $this->viewBuilder->archiveView($locale, $postType, $result),
        );
        $this->httpCacheService->setSharedCache($response);

        return $this->withLocaleHeader($response, $locale);
    }

    private function homepagePost(string $locale): ?PostInterface
    {
        $id = $this->context->homepagePostId();
        if (null === $id) {
            return null;
        }

        $post = $this->postRepository->find($id);
        if (!$post instanceof PostInterface || !$post->isOnSite()) {
            return null;
        }

        return $post->getTranslation($locale) instanceof PostTranslationInterface ? $post : null;
    }

    /**
     * A renamed post keeps answering on its old address, permanently
     * redirected - otherwise every link ever shared to it breaks.
     */
    private function redirectFromSlugHistory(string $locale, string $slug): ?RedirectResponse
    {
        $entry = $this->slugHistoryRepository->findOneByLocaleAndSlug($locale, $slug);
        // An old address of a publication the site no longer offers leads
        // nowhere: redirecting would name its new address, and that address
        // answers 404 anyway.
        if (!$entry instanceof PostSlugHistoryInterface || !$entry->getPost()->isOnSite()) {
            return null;
        }

        $current = $entry->getPost()->getTranslation($locale)?->getSlug();
        if (null === $current || '' === $current) {
            return null;
        }

        return $this->redirectToRoute('editorial_post', [
            'locale' => $locale,
            'postTypeSlug' => $entry->getPost()->getPostType()->getSlug(),
            'slug' => $current,
        ], HttpStatusEnum::MovedPermanently->value);
    }

    /**
     * The taxonomy and the term an address names, or null.
     *
     * Asked by the post route before its widest look-up, so the answer cannot
     * be a redirect to something else that happens to share the slug; and by
     * the term route. Once per request either way: the post route used to ask
     * whether the term existed, then hand over to the term route, which asked
     * again.
     *
     * @return array{0: TaxonomyInterface, 1: TaxonomyTermInterface}|null
     */
    private function resolveTerm(string $taxonomySlug, string $termSlug, string $locale): ?array
    {
        $taxonomy = $this->taxonomyRepository->findOneBySlug($taxonomySlug);
        if (!$taxonomy instanceof TaxonomyInterface) {
            return null;
        }

        $term = $this->taxonomyTermRepository->findOneBySlug($taxonomy, $termSlug, $locale);

        return $term instanceof TaxonomyTermInterface ? [$taxonomy, $term] : null;
    }

    /** @return array{items: list<PostInterface>, total: int, page: int, totalPages: int} */
    private function publishedPage(?PostTypeInterface $postType, int $page, string $locale, ?string $search = null): array
    {
        if (!$postType instanceof PostTypeInterface) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'totalPages' => 1];
        }

        return $this->postRepository->findPublishedByPostType(
            (int) $postType->getId(),
            $page,
            $this->postsPerPage(),
            $locale,
            $search,
        );
    }

    /** What the home page and the search box list when no type is named. */
    private function defaultPostType(): ?PostTypeInterface
    {
        return $this->postTypeRepository->findOneBySlug('article');
    }

    private function page(Request $request): int
    {
        return max(1, $request->query->getInt('page', 1));
    }

    private function postsPerPage(): int
    {
        return (int) ($this->context->setting(ApplicationParameterEnum::PostsPerPage->value, '10') ?? 10);
    }

    private function assertActiveLocale(string $locale): void
    {
        if (!$this->context->isLocaleActive($locale)) {
            throw $this->createNotFoundException();
        }
    }

    private function withLocaleHeader(Response $response, string $locale): Response
    {
        $response->headers->set('Content-Language', $locale);

        return $response;
    }
}
