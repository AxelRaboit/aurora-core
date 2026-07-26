<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Controller\Frontend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Frontend\Controller\LocaleTrait;
use Aurora\Core\Frontend\Service\Context;
use Aurora\Core\Frontend\Service\HttpCacheService;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Theme\Service\ThemeResolver;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostSlugHistory;
use Aurora\Module\Editorial\Post\Entity\PostTranslation;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Repository\PostSlugHistoryRepository;
use Aurora\Module\Editorial\Post\Service\PostPageRenderer;
use Aurora\Module\Editorial\Post\View\PageViewBuilder;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Taxonomy\Entity\Taxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PageController extends AbstractController
{
    use LocaleTrait;
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
        $this->assertActiveLocale($this->context, $locale);
        $request->setLocale($locale);

        $homepageId = $this->context->homepagePostId();
        if (null !== $homepageId) {
            $post = $this->postRepository->find($homepageId);
            $homepageTranslation = $post?->getTranslation($locale);
            if (null !== $post && $post->isPublished() && !$post->isTrashed() && $homepageTranslation instanceof PostTranslation) {
                return $this->postPageRenderer->render($post, $locale);
            }
        }

        $postType = $this->findArticlePostType();
        $result = $this->findPostsPage($postType, (int) $request->query->get('page', 1), $locale);

        $searchPath = $this->generateUrl('editorial_home_search', ['locale' => $locale]);
        $response = $this->render($this->themeResolver->resolve('editorial/home/index'), $this->viewBuilder->homeView($locale, $result, $postType, $searchPath));

        return $this->withI18nHeaders($response, $locale);
    }

    #[Route('/{locale}/search', name: 'editorial_home_search', requirements: ['locale' => '[a-z]{2}'], methods: [HttpMethodEnum::Get->value], priority: 10)]
    public function searchPosts(string $locale, Request $request): JsonResponse
    {
        $this->assertActiveLocale($this->context, $locale);

        $query = mb_trim($request->query->getString('q', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $postType = $this->findArticlePostType();
        $result = $this->findPostsPage($postType, $page, $locale, '' !== $query ? $query : null);

        return $this->jsonSuccess($this->viewBuilder->serializePageData($result, $locale));
    }

    #[Route('/{locale}/{postTypeSlug}/{slug}', name: 'editorial_post', requirements: ['locale' => '[a-z]{2}'], priority: 5)]
    public function post(string $locale, string $postTypeSlug, string $slug, Request $request): Response
    {
        $this->assertActiveLocale($this->context, $locale);
        $request->setLocale($locale);

        $post = $this->postRepository->findPublishedBySlug($slug, $locale);

        if (!$post instanceof Post) {
            $redirect = $this->tryRedirectFromHistory($locale, $slug);
            if ($redirect instanceof RedirectResponse) {
                return $redirect;
            }

            throw $this->createNotFoundException();
        }

        if ($post->getPostType()->getSlug() !== $postTypeSlug) {
            return $this->redirectToRoute('editorial_post', [
                'locale' => $locale,
                'postTypeSlug' => $post->getPostType()->getSlug(),
                'slug' => $slug,
            ], HttpStatusEnum::MovedPermanently->value);
        }

        $lastModified = $post->getUpdatedAt();
        if (($notModified = $this->httpCache->checkNotModified($request, $lastModified)) instanceof Response) {
            return $notModified;
        }

        $response = $this->postPageRenderer->render($post, $locale);
        $this->httpCache->setPublicCache($response, $lastModified);

        return $response;
    }

    #[Route('/{locale}/{postTypeSlug}', name: 'editorial_archive', requirements: ['locale' => '[a-z]{2}'], priority: 3)]
    public function archive(string $locale, string $postTypeSlug, Request $request): Response
    {
        $this->assertActiveLocale($this->context, $locale);
        $request->setLocale($locale);

        $postType = $this->postTypeRepository->findOneBy(['slug' => $postTypeSlug]);
        if (null === $postType || !$postType->hasArchive()) {
            throw $this->createNotFoundException();
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $result = $this->postRepository->findPublishedByPostTypeWithSearch($postType->getId(), $page, $this->postsPerPage(), $locale);

        $response = $this->render($this->themeResolver->resolve('editorial/archive/index'), $this->viewBuilder->archiveView($locale, $postType, $result));

        $this->httpCache->setSharedCache($response);

        return $this->withI18nHeaders($response, $locale);
    }

    #[Route('/{locale}/{taxonomySlug}/{termSlug}', name: 'editorial_term', requirements: ['locale' => '[a-z]{2}'], priority: 4)]
    public function term(string $locale, string $taxonomySlug, string $termSlug, Request $request): Response
    {
        $this->assertActiveLocale($this->context, $locale);
        $request->setLocale($locale);

        $taxonomy = $this->taxonomyRepository->findOneBySlug($taxonomySlug);
        if (!$taxonomy instanceof Taxonomy) {
            throw $this->createNotFoundException();
        }

        $term = null;
        foreach ($taxonomy->getTerms() as $candidate) {
            if ($candidate->getTranslation($locale)?->getSlug() === $termSlug) {
                $term = $candidate;
                break;
            }
        }

        if (!$term instanceof TaxonomyTerm) {
            throw $this->createNotFoundException();
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $result = $this->postRepository->findPublishedByTerm($term->getId(), $page, $this->postsPerPage(), $locale);

        $response = $this->render($this->themeResolver->resolve('editorial/term/index'), $this->viewBuilder->termView($locale, $taxonomy, $term, $result));

        $this->httpCache->setSharedCache($response);

        return $this->withI18nHeaders($response, $locale);
    }

    private function findArticlePostType(): ?PostTypeInterface
    {
        return $this->postTypeRepository->findOneBy(['slug' => 'article']);
    }

    /** @return array{items: list<Post>, total: int, page: int, totalPages: int} */
    private function findPostsPage(?PostTypeInterface $postType, int $page, string $locale, ?string $search = null): array
    {
        if (!$postType instanceof PostTypeInterface) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'totalPages' => 1];
        }

        return $this->postRepository->findPublishedByPostTypeWithSearch($postType->getId(), $page, $this->postsPerPage(), $locale, $search);
    }

    private function tryRedirectFromHistory(string $locale, string $slug): ?RedirectResponse
    {
        $historyEntry = $this->slugHistoryRepository->findOneByLocaleAndSlug($locale, $slug);
        if (!$historyEntry instanceof PostSlugHistory) {
            return null;
        }

        $currentSlug = $historyEntry->getPost()->getTranslation($locale)?->getSlug();
        if (null === $currentSlug || '' === $currentSlug) {
            return null;
        }

        return $this->redirectToRoute('editorial_post', [
            'locale' => $locale,
            'postTypeSlug' => $historyEntry->getPost()->getPostType()->getSlug(),
            'slug' => $currentSlug,
        ], HttpStatusEnum::MovedPermanently->value);
    }

    private function postsPerPage(): int
    {
        return (int) ($this->context->setting(ApplicationParameterEnum::PostsPerPage->value, '10') ?? 10);
    }
}
