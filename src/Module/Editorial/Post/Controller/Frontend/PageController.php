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
        private readonly Context $context,
        private readonly ThemeResolver $themeResolver,
        private readonly HttpCacheService $httpCache,
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

    #[Route('/{locale}/search', name: 'editorial_home_search', requirements: ['locale' => '[a-z]{2}'], methods: [HttpMethodEnum::Get->value], priority: 10)]
    public function search(string $locale, Request $request): JsonResponse
    {
        $this->assertActiveLocale($locale);

        $query = mb_trim($request->query->getString('q', ''));
        $result = $this->publishedPage(
            $this->defaultPostType(),
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

        $post = $this->postRepository->findPublishedBySlug($slug, $locale);

        if (!$post instanceof PostInterface) {
            $redirect = $this->redirectFromSlugHistory($locale, $slug);
            if ($redirect instanceof RedirectResponse) {
                return $redirect;
            }

            // `/{locale}/{a}/{b}` is a post under a type and equally a term
            // under a taxonomy. This route wins on priority, so the term
            // route was simply never reached - every taxonomy page answered
            // 404. Falling through in code keeps both readable, and a post
            // still wins a slug collision, which is the right way round: it
            // is the more specific page.
            return $this->term($locale, $postTypeSlug, $slug, $request);
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
        $notModified = $this->httpCache->checkNotModified($request, $lastModified);
        if ($notModified instanceof Response) {
            return $notModified;
        }

        $response = $this->postPageRenderer->render($post, $locale);
        $this->httpCache->setPublicCache($response, $lastModified);

        return $response;
    }

    #[Route('/{locale}/{taxonomySlug}/{termSlug}', name: 'editorial_term', requirements: ['locale' => '[a-z]{2}'], priority: 4)]
    public function term(string $locale, string $taxonomySlug, string $termSlug, Request $request): Response
    {
        $this->assertActiveLocale($locale);
        $request->setLocale($locale);

        $taxonomy = $this->taxonomyRepository->findOneBySlug($taxonomySlug);
        if (!$taxonomy instanceof TaxonomyInterface) {
            throw $this->createNotFoundException();
        }

        $term = $this->findTermBySlug($taxonomy, $termSlug, $locale);
        if (!$term instanceof TaxonomyTermInterface) {
            throw $this->createNotFoundException();
        }

        $result = $this->postRepository->findPublishedByTerm(
            (int) $term->getId(),
            $this->page($request),
            $this->postsPerPage(),
            $locale,
        );

        $response = $this->render(
            $this->themeResolver->resolve('editorial/term/index'),
            $this->viewBuilder->termView($locale, $taxonomy, $term, $result),
        );
        $this->httpCache->setSharedCache($response);

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
        $this->httpCache->setSharedCache($response);

        return $this->withLocaleHeader($response, $locale);
    }

    private function homepagePost(string $locale): ?PostInterface
    {
        $id = $this->context->homepagePostId();
        if (null === $id) {
            return null;
        }

        $post = $this->postRepository->find($id);
        if (!$post instanceof PostInterface || !$post->isPublished() || $post->isTrashed()) {
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
        if (!$entry instanceof PostSlugHistoryInterface) {
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

    private function findTermBySlug(TaxonomyInterface $taxonomy, string $slug, string $locale): ?TaxonomyTermInterface
    {
        foreach ($taxonomy->getTerms() as $term) {
            if ($term->getTranslation($locale)?->getSlug() === $slug) {
                return $term;
            }
        }

        return null;
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
