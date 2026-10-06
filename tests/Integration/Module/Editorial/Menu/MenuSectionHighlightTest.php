<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Menu;

use Aurora\Module\Editorial\Menu\Entity\Menu;
use Aurora\Module\Editorial\Menu\Entity\MenuItem;
use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Menu\Service\MenuRenderer;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A navigation entry that heads a section stays lit inside it.
 *
 * The highlight follows the address, which covers a section whose
 * publications live under the entry's own URL and nothing else. A hub page
 * introducing publications that live elsewhere is the case it misses: the
 * reader follows a card from the page the entry points at, and the navigation
 * goes dark on a page plainly inside that section.
 *
 * An integration test because what broke is the wiring between three things -
 * the route the reader is on, the type a publication belongs to, and the
 * section an entry declares - and no unit test of any one of them sees it.
 */
final class MenuSectionHighlightTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    private MenuRenderer $renderer;

    private RequestStack $requestStack;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->renderer = static::getContainer()->get(MenuRenderer::class);
        $this->requestStack = static::getContainer()->get(RequestStack::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
            $this->entityManager->remove($entity);
        }

        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    public function testAnEntryHeadingASectionStaysLitOnItsPublications(): void
    {
        [$location, , $type] = $this->seed();

        $this->standOn('/fr/guide/premier-sujet', ['locale' => 'fr', 'postTypeSlug' => $type->getSlug(), 'slug' => 'premier-sujet']);

        $tree = $this->renderer->render($location, 'fr');

        self::assertCount(1, $tree);
        self::assertTrue($tree[0]['isActive'], 'the entry heading the section should stay lit inside it');
        // Lit, and still not claiming to be the page: `aria-current` names one
        // entry, and the reader is on a publication rather than on the hub.
        self::assertFalse($tree[0]['isCurrent']);
        self::assertSame('Le guide', $tree[0]['label']);
    }

    /** Elsewhere on the site it goes quiet, which is the other half of useful. */
    public function testTheEntryIsQuietOutsideItsSection(): void
    {
        [$location] = $this->seed();

        $this->standOn('/fr/page/autre-chose', ['locale' => 'fr', 'postTypeSlug' => 'page', 'slug' => 'autre-chose']);

        $tree = $this->renderer->render($location, 'fr');

        self::assertCount(1, $tree);
        self::assertFalse($tree[0]['isActive']);
    }

    /**
     * @return array{string, Post, PostType}
     */
    private function seed(): array
    {
        $suffix = bin2hex(random_bytes(4));

        $type = new PostType();
        $type->setSlug('guide')->setLabel('Guide')->setIcon('file-text')->setHasArchive(false);
        $this->persist($type);

        $page = $this->entityManager->getRepository(PostType::class)->findOneBy(['slug' => 'page']);
        self::assertNotNull($page, 'the built-in page type is missing; run aurora:install');

        $hub = new Post();
        $hub->setPostType($page)
            ->setStatus(PostStatusEnum::Published)
            ->setPublishedAt(new DateTimeImmutable('-1 day'));
        $hub->translate('fr')->setTitle('Le guide')->setSlug('le-guide-'.$suffix);
        $this->persist($hub);

        $location = 'test-section-'.$suffix;

        $menu = new Menu();
        $menu->setName('Test '.$suffix)->setLocation($location);
        $this->persist($menu);

        $item = new MenuItem();
        $item->setMenu($menu)
            ->setTargetType(MenuItemTargetTypeEnum::Post)
            ->setTargetId($hub->getId())
            ->setSectionPostTypeId($type->getId())
            ->setPosition(0);
        $item->translate('fr')->setLabel('Le guide');
        $this->persist($item);

        // The owning side was set, not the collection, so the menu still holds
        // no items in memory - and the renderer reads the collection.
        $this->entityManager->refresh($menu);

        return [$location, $hub, $type];
    }

    private function persist(object $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        $this->created[] = $entity;
    }

    /** @param array<string, string> $routeParameters */
    private function standOn(string $path, array $routeParameters): void
    {
        $request = Request::create('https://example.test'.$path);
        $request->attributes->set('_route_params', $routeParameters);

        $this->requestStack->push($request);
    }
}
