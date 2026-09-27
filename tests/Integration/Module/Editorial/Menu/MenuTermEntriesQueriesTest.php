<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Menu;

use Aurora\Module\Editorial\Menu\Entity\Menu;
use Aurora\Module\Editorial\Menu\Entity\MenuItem;
use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Menu\Service\MenuRenderer;
use Aurora\Module\Editorial\Taxonomy\Entity\Taxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function array_reverse;
use function bin2hex;
use function count;
use function random_bytes;
use function str_contains;

/**
 * A menu of taxonomy terms reads them in one go.
 *
 * Loaded one by one, each term cost a query for its translations - on every
 * page, since the menu is on every page. The count is what regresses
 * silently, so it is what is held.
 */
final class MenuTermEntriesQueriesTest extends IntegrationTestCase
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

    public function testThreeTermEntriesCostOneQueryForTheirTranslations(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $taxonomy = $this->entityManager->getRepository(Taxonomy::class)->findOneBy(['slug' => 'category']);
        self::assertNotNull($taxonomy, 'the built-in category taxonomy is missing; run aurora:install');

        $location = 'test-terms-'.$suffix;
        $menu = new Menu();
        $menu->setName('Termes '.$suffix)->setLocation($location);
        $this->persist($menu);

        foreach (['un', 'deux', 'trois'] as $position => $name) {
            $term = new TaxonomyTerm();
            $term->setTaxonomy($taxonomy);
            $term->translate('fr')->setName('Terme '.$name)->setSlug('terme-'.$name.'-'.$suffix);
            $this->persist($term);

            $item = new MenuItem();
            $item->setMenu($menu)->setTargetType(MenuItemTargetTypeEnum::Term)->setTargetId($term->getId())->setPosition($position);
            $this->persist($item);
        }

        $this->entityManager->clear();
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $tree = static::getContainer()->get(MenuRenderer::class)->render($location, 'fr');

        self::assertCount(3, $tree);
        self::assertSame('Terme un', $tree[0]['label']);

        $translationQueries = array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $query): bool => str_contains((string) $query['sql'], 'core_taxonomy_term_translations'),
        );
        self::assertSame(1, count($translationQueries), 'the terms and their translations load together');
    }

    private function persist(object $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        $this->created[] = $entity;
    }
}
