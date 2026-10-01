<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Menu\Entity\Menu;
use Aurora\Module\Editorial\Menu\Entity\MenuItem;
use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Menu\Service\MenuRenderer;
use Aurora\Module\Editorial\Menu\Service\MenuTargetFinder;
use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostSlugHistory;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Enum\PostVisibilityEnum;
use Aurora\Module\Editorial\Post\Grid\ZoneListingViews;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Serializer\PostSerializerInterface;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Seo\Service\RssFeedBuilder;
use Aurora\Module\Editorial\Seo\Service\SitemapBuilder;
use Aurora\Module\Editorial\Taxonomy\Entity\Taxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function array_column;
use function bin2hex;
use function random_bytes;
use function sprintf;

/**
 * A publication shared by link is published, and the site never names it.
 *
 * Every assertion here is about a surface of the site, and each one is made
 * beside a publication of the site that the same surface does show: a check
 * that a page lists nothing proves nothing about why. The surfaces are the ones
 * the design note listed - the address, the listings, search, the sitemap, the
 * feed, the menus, the cards a grid draws, the header an archive borrows, the
 * terms and the old addresses - and a new one belongs here when it appears.
 *
 * The client deliverable it was made for is confidential by nature. One
 * surface forgotten is an audit listed beside the articles.
 */
final class PostVisibilityTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $created = [];

    private string $suffix;

    private PostType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->suffix = bin2hex(random_bytes(4));
        $this->type = new PostType();
        $this->type->setSlug('dossiers-'.$this->suffix)->setLabel('Dossiers')->setHasArchive(true);
        $this->persist($this->type);
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();

        foreach (array_reverse($this->created) as $entity) {
            $managed = $this->entityManager->find($entity::class, $entity->getId());

            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }

        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    public function testANewPublicationIsOnTheSite(): void
    {
        $post = new Post();

        self::assertSame(PostVisibilityEnum::Site, $post->getVisibility());
    }

    public function testOnTheSiteMeansPublishedUntrashedAndOffered(): void
    {
        $site = $this->publish('Rapport du site');
        $link = $this->publish('Audit confidentiel', PostVisibilityEnum::Link);
        $draft = $this->publish('Brouillon du site');
        $draft->setStatus(PostStatusEnum::Draft);

        self::assertTrue($site->isOnSite());
        self::assertFalse($draft->isOnSite());
        self::assertFalse($link->isOnSite());
        // Shared by link is still published: its readers are visitors.
        self::assertTrue($link->isPublished());

        $site->setDeletedAt(new DateTimeImmutable());
        self::assertFalse($site->isOnSite());
    }

    public function testItHasNoAddressOnTheSite(): void
    {
        $this->publish('Rapport du site', slug: 'rapport-'.$this->suffix);
        $this->publish('Audit confidentiel', PostVisibilityEnum::Link, slug: 'audit-'.$this->suffix);

        $this->client->request('GET', sprintf('/fr/%s/rapport-%s', $this->type->getSlug(), $this->suffix));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/fr/%s/audit-%s', $this->type->getSlug(), $this->suffix));
        self::assertResponseStatusCodeSame(404);

        // Not under another type either: the look-up that ignores the type,
        // kept for publications that changed type, must not find it.
        $this->client->request('GET', sprintf('/fr/page/audit-%s', $this->suffix));
        self::assertResponseStatusCodeSame(404);
    }

    /** An old address of it leads nowhere rather than to its new one. */
    public function testAnOldAddressOfItDoesNotRedirect(): void
    {
        $link = $this->publish('Audit confidentiel', PostVisibilityEnum::Link, slug: 'audit-'.$this->suffix);

        $history = new PostSlugHistory();
        $history->setPost($link)->setLocale('fr')->setSlug('ancien-audit-'.$this->suffix);
        $this->persist($history);

        $this->client->request('GET', sprintf('/fr/%s/ancien-audit-%s', $this->type->getSlug(), $this->suffix));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Neither listed in its archive nor lending it its header.
     *
     * The archive borrows the title of the publication it names as its header;
     * naming one shared by link must not print its title on the archive.
     */
    public function testTheArchiveNeitherListsItNorBorrowsItsHeader(): void
    {
        $this->publish('Rapport du site');
        $link = $this->publish('Audit confidentiel', PostVisibilityEnum::Link);

        $this->type->setArchivePostId($link->getId());
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/fr/%s', $this->type->getSlug()));

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Rapport du site', $body);
        self::assertStringNotContainsString('Audit confidentiel', $body);
    }

    public function testTheSiteSearchDoesNotFindIt(): void
    {
        $this->publish('Rapport '.$this->suffix);
        $this->publish('Audit '.$this->suffix, PostVisibilityEnum::Link);

        $this->client->request('GET', sprintf('/fr/search?type=%s&q=%s', $this->type->getSlug(), $this->suffix));

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Rapport '.$this->suffix, $body);
        self::assertStringNotContainsString('Audit '.$this->suffix, $body);
    }

    /** Nor a term that only it carries: that term would be a page listing nothing. */
    public function testTheSitemapNamesNeitherItNorATermOnlyItCarries(): void
    {
        $this->publish('Rapport du site', slug: 'rapport-'.$this->suffix);
        $link = $this->publish('Audit confidentiel', PostVisibilityEnum::Link, slug: 'audit-'.$this->suffix);

        $taxonomy = new Taxonomy();
        $taxonomy->setSlug('clients-'.$this->suffix)->addPostType($this->type);
        $taxonomy->translate('fr')->setLabel('Clients');
        $this->persist($taxonomy);

        $term = new TaxonomyTerm();
        $term->setTaxonomy($taxonomy)->setPosition(1);
        $term->translate('fr')->setName('Client secret')->setSlug('client-secret-'.$this->suffix);
        $this->persist($term);

        $link->addTerm($term);
        $this->entityManager->flush();

        $xml = static::getContainer()->get(SitemapBuilder::class)->buildData()->xml;

        self::assertStringContainsString('rapport-'.$this->suffix, $xml);
        self::assertStringNotContainsString('audit-'.$this->suffix, $xml);
        self::assertStringNotContainsString('client-secret-'.$this->suffix, $xml);
        self::assertArrayNotHasKey(
            (int) $term->getId(),
            static::getContainer()->get(PostRepository::class)->findTermIdsWithPublishedPost(),
        );
    }

    public function testTheFeedLeavesItOut(): void
    {
        $article = static::getContainer()->get(PostTypeRepository::class)->findOneBySlug('article');
        self::assertInstanceOf(PostType::class, $article, 'the built-in article type is missing; run aurora:install');

        $this->publish('Article '.$this->suffix, type: $article);
        $this->publish('Audit '.$this->suffix, PostVisibilityEnum::Link, type: $article);

        $xml = static::getContainer()->get(RssFeedBuilder::class)->buildXml('fr');

        self::assertStringContainsString('Article '.$this->suffix, $xml);
        self::assertStringNotContainsString('Audit '.$this->suffix, $xml);
    }

    /**
     * Neither a list zone nor a card chosen by hand shows it.
     *
     * The hand-picked card also refuses a draft now: it used to check the
     * trash only, and drew a card linking to an address that answers 404.
     */
    public function testTheGridDrawsNoCardOfIt(): void
    {
        $site = $this->publish('Rapport du site');
        $link = $this->publish('Audit confidentiel', PostVisibilityEnum::Link);
        $draft = $this->publish('Brouillon du site');
        $draft->setStatus(PostStatusEnum::Draft);
        $this->entityManager->flush();

        $latest = static::getContainer()->get(PostRepository::class)->findLatestPublished('fr', 50, $this->type->getId());
        $ids = array_map(static fn (Post $post): int => (int) $post->getId(), $latest);
        self::assertSame([(int) $site->getId()], $ids);

        $views = static::getContainer()->get(ZoneListingViews::class);
        self::assertNotNull($views->postCard($site, 'fr'));
        self::assertNull($views->postCard($link, 'fr'));
        self::assertNull($views->postCard($draft, 'fr'));
    }

    /** A menu entry pointing at it disappears, and the menu editor does not offer it. */
    public function testMenusNeitherShowNorOfferIt(): void
    {
        $site = $this->publish('Rapport '.$this->suffix);
        $link = $this->publish('Audit '.$this->suffix, PostVisibilityEnum::Link);

        $location = 'test-visibility-'.$this->suffix;
        $menu = new Menu();
        $menu->setName('Test '.$this->suffix)->setLocation($location);
        $this->persist($menu);

        foreach ([$site, $link] as $position => $post) {
            $item = new MenuItem();
            $item->setMenu($menu)->setTargetType(MenuItemTargetTypeEnum::Post)->setTargetId($post->getId())->setPosition($position);
            $item->translate('fr')->setLabel((string) $post->getTranslation('fr')?->getTitle());
            $this->persist($item);
        }

        // The owning side was set, not the collection, and the renderer reads
        // the collection.
        $this->entityManager->refresh($menu);

        $tree = static::getContainer()->get(MenuRenderer::class)->render($location, 'fr');
        self::assertSame(['Rapport '.$this->suffix], array_column($tree, 'label'));

        $offered = static::getContainer()->get(MenuTargetFinder::class)->search(MenuItemTargetTypeEnum::Post, $this->suffix, 'fr');
        self::assertSame(['Rapport '.$this->suffix], array_column($offered, 'label'));
    }

    /** The archive header and the home page pick from this list. */
    public function testThePickerOfTheSiteDoesNotOfferIt(): void
    {
        $site = $this->publish('Rapport du site');
        $link = $this->publish('Audit confidentiel', PostVisibilityEnum::Link);

        $ids = array_map(
            static fn (Post $post): int => (int) $post->getId(),
            static::getContainer()->get(PostRepository::class)->findAllPublishedForPicker(),
        );

        self::assertContains((int) $site->getId(), $ids);
        self::assertNotContains((int) $link->getId(), $ids);
    }

    /**
     * What visitors are served includes it.
     *
     * The check of public documents asks this: a picture of an audit shared
     * by link is served to whoever holds the link, so it must be published.
     */
    public function testWhatVisitorsAreServedIncludesIt(): void
    {
        $link = $this->publish('Audit confidentiel', PostVisibilityEnum::Link);

        $ids = array_map(
            static fn (Post $post): int => (int) $post->getId(),
            static::getContainer()->get(PostRepository::class)->findAllPublished(),
        );

        self::assertContains((int) $link->getId(), $ids);
    }

    public function testTheChoiceIsSavedAndComesBackToTheEditor(): void
    {
        $container = static::getContainer();
        $post = $container->get(PostManagerInterface::class)->create($container->get(PostInputFactoryInterface::class)->fromArray([
            'postTypeId' => $this->type->getId(),
            'status' => 'draft',
            'visibility' => 'link',
            'translations' => ['fr' => ['title' => 'Audit confidentiel']],
        ]));
        $this->entityManager->flush();
        $this->created[] = $post;

        self::assertSame(PostVisibilityEnum::Link, $post->getVisibility());
        self::assertSame('link', $container->get(PostSerializerInterface::class)->serialize($post)['visibility']);
    }

    /**
     * An unknown value is refused, never read as "on the site".
     *
     * Falling back the way a thumbnail's fit does would be the one wrong
     * answer here: a typo would publish a client's audit on the site.
     */
    public function testAnUnknownValueIsRefused(): void
    {
        $input = static::getContainer()->get(PostInputFactoryInterface::class)->fromArray([
            'postTypeId' => $this->type->getId(),
            'status' => 'draft',
            'visibility' => 'public',
            'translations' => ['fr' => ['title' => 'Audit confidentiel']],
        ]);

        $violations = static::getContainer()->get(ValidatorInterface::class)->validate($input);

        self::assertGreaterThan(0, $violations->count());
    }

    /** The backend list can be narrowed to what is shared by link. */
    public function testTheBackendListFiltersOnIt(): void
    {
        $this->publish('Rapport '.$this->suffix);
        $link = $this->publish('Audit '.$this->suffix, PostVisibilityEnum::Link);

        $result = static::getContainer()->get(PostRepository::class)->findPaginated(
            page: 1,
            locale: 'fr',
            postTypeIds: [(int) $this->type->getId()],
            visibilities: ['link'],
        );

        self::assertSame([(int) $link->getId()], array_map(static fn (Post $post): int => (int) $post->getId(), $result['items']));
    }

    private function publish(
        string $title,
        PostVisibilityEnum $visibility = PostVisibilityEnum::Site,
        ?string $slug = null,
        ?PostType $type = null,
    ): Post {
        $post = new Post();
        $post->setPostType($type ?? $this->type)
            ->setStatus(PostStatusEnum::Published)
            ->setVisibility($visibility)
            ->setPublishedAt(new DateTimeImmutable('-1 day'));

        $post->translate('fr')->setTitle($title)->setSlug($slug ?? 'p-'.bin2hex(random_bytes(4)));

        $this->persist($post);

        return $post;
    }

    private function persist(object $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        $this->created[] = $entity;
    }
}
