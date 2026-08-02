<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Seo;

use Aurora\Core\Frontend\Service\Context;
use Aurora\Core\Locale\Entity\Locale;
use Aurora\Core\Locale\Repository\LocaleRepository;
use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Seo\Dto\SitemapData;
use Aurora\Module\Editorial\Seo\Service\SitemapBuilder;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * `noindex` is a per-translation checkbox, and the sitemap has to read it
 * that way.
 *
 * The reference this was rebuilt from looked at the default locale's flag
 * first and skipped the whole post when it was set — so a post the editor
 * had hidden in French disappeared in English too, from a page they never
 * touched. The counter that reports how many URLs were withheld sat after
 * that skip, so it under-reported by exactly the URLs nobody meant to lose:
 * the screen said "1 excluded" while three were.
 */
final class SitemapBuilderTest extends TestCase
{
    public function testHidesOnlyTheTranslationThatAsksToBeHidden(): void
    {
        $data = $this->build([$this->post('article', ['fr' => 'bonjour', 'en' => 'hello'], noindex: ['fr'])]);

        self::assertStringNotContainsString('/fr/article/bonjour', $data->xml);
        self::assertStringContainsString('/en/article/hello', $data->xml);
    }

    public function testCountsEveryWithheldTranslation(): void
    {
        $data = $this->build([
            $this->post('article', ['fr' => 'un', 'en' => 'one'], noindex: ['fr', 'en']),
            $this->post('article', ['fr' => 'deux', 'en' => 'two'], noindex: ['fr']),
        ]);

        self::assertSame(3, $data->noindex);
        self::assertSame(1, $data->counts['posts']);
    }

    public function testSkipsATranslationWithNoSlug(): void
    {
        $data = $this->build([$this->post('article', ['fr' => 'ok', 'en' => ''])]);

        self::assertSame(1, $data->counts['posts']);
        self::assertSame(0, $data->noindex, 'a missing slug is not a withheld URL');
    }

    /** A locale switched off must not appear, whatever translations exist. */
    public function testSkipsAnInactiveLocale(): void
    {
        $data = $this->build(
            [$this->post('article', ['fr' => 'bonjour', 'de' => 'hallo'])],
            activeLocales: ['fr'],
        );

        self::assertStringContainsString('/fr/article/bonjour', $data->xml);
        self::assertStringNotContainsString('hallo', $data->xml);
    }

    public function testCountsPerLocaleAndPerPostType(): void
    {
        $data = $this->build([
            $this->post('article', ['fr' => 'a', 'en' => 'b']),
            $this->post('page', ['fr' => 'c']),
        ]);

        // Two home entries and two post-type archives are seeded by the stubs
        // below, on top of the three post URLs.
        self::assertSame(['article' => 2, 'page' => 1], $data->byPostType);
        self::assertSame(['en' => 3, 'fr' => 4], $data->byLocale);
    }

    /**
     * @param list<string>          $noindex locale codes whose translation is withheld
     * @param array<string, string> $slugs   locale → slug
     */
    private function post(string $postTypeSlug, array $slugs, array $noindex = []): PostInterface
    {
        $post = new Post()->setPostType(new PostType()->setSlug($postTypeSlug)->setLabel($postTypeSlug));

        // What Doctrine does on persist. The sitemap reads updatedAt for
        // <lastmod>, and a post that has never been flushed has none.
        $post->setCreatedAtValue();

        foreach ($slugs as $locale => $slug) {
            $post->translate($locale)
                ->setTitle($slug)
                ->setSlug($slug)
                ->setNoindex(in_array($locale, $noindex, true));
        }

        return $post;
    }

    /**
     * @param list<PostInterface> $posts
     * @param list<string>        $activeLocales
     */
    private function build(array $posts, array $activeLocales = ['fr', 'en']): SitemapData
    {
        $postRepository = $this->createStub(PostRepository::class);
        $postRepository->method('findAllPublishedForSitemap')->willReturn($posts);

        // One archived type, so the archive section is non-empty and the
        // per-locale counts have something other than posts in them.
        $postTypeRepository = $this->createStub(PostTypeRepository::class);
        $postTypeRepository->method('findAllWithRelations')->willReturn([
            new PostType()->setSlug('article')->setLabel('Articles')->setHasArchive(true),
            new PostType()->setSlug('page')->setLabel('Pages'),
        ]);

        $taxonomyRepository = $this->createStub(TaxonomyRepository::class);
        $taxonomyRepository->method('findAllForIndex')->willReturn([]);

        $context = $this->context($activeLocales);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static function (string $route, array $parameters = []): string {
                $locale = $parameters['locale'] ?? '';

                return match ($route) {
                    'editorial_home' => sprintf('https://example.org/%s', $locale),
                    'editorial_archive' => sprintf('https://example.org/%s/%s', $locale, $parameters['postTypeSlug'] ?? ''),
                    default => sprintf('https://example.org/%s/%s/%s', $locale, $parameters['postTypeSlug'] ?? '', $parameters['slug'] ?? ''),
                };
            },
        );

        return new SitemapBuilder(
            $postRepository,
            $postTypeRepository,
            $taxonomyRepository,
            $context,
            $urlGenerator,
        )->buildData();
    }

    /**
     * A real Context over stubbed repositories: the class is final, and
     * doubling what it reads is closer to the truth than doubling what it
     * answers — the locale filtering it applies is part of what is under test.
     *
     * @param list<string> $activeLocales
     */
    private function context(array $activeLocales): Context
    {
        $localeRepository = $this->createStub(LocaleRepository::class);
        $localeRepository->method('findBy')->willReturn(array_map(
            static fn (string $code): Locale => new Locale()->setCode($code)->setName($code),
            $activeLocales,
        ));

        $localeContext = $this->createStub(LocaleContextInterface::class);
        $localeContext->method('isSingleLocaleMode')->willReturn(false);
        $localeContext->method('getDefaultLocale')->willReturn($activeLocales[0] ?? 'fr');

        return new Context(
            $localeRepository,
            $this->createStub(SettingRepository::class),
            $localeContext,
            new RequestStack(),
        );
    }
}
