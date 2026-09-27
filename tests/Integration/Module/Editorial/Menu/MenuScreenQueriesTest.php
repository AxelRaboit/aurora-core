<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Menu;

use Aurora\Module\Editorial\Menu\Entity\Menu;
use Aurora\Module\Editorial\Menu\Entity\MenuItem;
use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Menu\Repository\MenuRepository;
use Aurora\Module\Editorial\Menu\Serializer\MenuSerializerInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\Taxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function array_reverse;
use function array_values;
use function bin2hex;
use function preg_match;
use function random_bytes;

/**
 * The menus screen names every entry's target without a query per entry.
 *
 * It looked each target up on its own, then loaded its translations for the
 * name: two queries an entry, on a screen that lists every menu. The public
 * rendering already prefetched them; the screen now does too.
 */
final class MenuScreenQueriesTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
            $managed = $this->entityManager->find($entity::class, $entity->getId());
            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }

        $this->entityManager->flush();
        parent::tearDown();
    }

    public function testEveryTargetIsNamedWithoutAQueryOfItsOwn(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $taxonomy = $this->entityManager->getRepository(Taxonomy::class)->findOneBy(['slug' => 'category']);
        self::assertNotNull($taxonomy, 'the built-in category taxonomy is missing; run aurora:install');

        $menu = new Menu();
        $menu->setName('Écran '.$suffix)->setLocation('test-screen-'.$suffix);
        $this->persist($menu);

        foreach (['un', 'deux', 'trois'] as $position => $name) {
            $term = new TaxonomyTerm();
            $term->setTaxonomy($taxonomy);
            $term->translate('fr')->setName('Cible '.$name)->setSlug('cible-'.$name.'-'.$suffix);
            $this->persist($term);

            $item = new MenuItem();
            $item->setMenu($menu)->setTargetType(MenuItemTargetTypeEnum::Term)->setTargetId($term->getId())->setPosition($position);
            $this->persist($item);
        }

        $this->entityManager->clear();
        $menus = static::getContainer()->get(MenuRepository::class)->findAllWithItems();
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $serializer = static::getContainer()->get(MenuSerializerInterface::class);
        $serialized = null;
        foreach ($menus as $candidate) {
            $shape = $serializer->serialize($candidate);
            if ($candidate->getId() === $menu->getId()) {
                $serialized = $shape;
            }
        }

        self::assertNotNull($serialized);
        self::assertSame('Cible un', $serialized['items'][0]['targetLabel']);

        // `t0` is the alias of Doctrine's one-row loads; the prefetch is DQL.
        $oneByOne = array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $query): bool => 1 === preg_match('/FROM (core_taxonomy_terms|core_taxonomy_term_translations|core_posts|core_post_translations) t0 /', (string) $query['sql']),
        );
        self::assertSame([], array_values($oneByOne), 'no target is looked up on its own');
    }

    private function persist(object $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        $this->created[] = $entity;
    }
}
