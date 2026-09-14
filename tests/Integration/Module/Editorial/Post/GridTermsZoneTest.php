<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Editorial\Taxonomy\Entity\Taxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

/**
 * The zone that draws the doors rather than what is behind them.
 *
 * The mirror of the automatic list, and it is answered from the database on
 * every render for the same reason: a term added next month has to appear
 * without anybody editing the page. So the two things worth holding are that
 * the order is the backend's, and that a term nobody has translated stays out
 * rather than turning up labelled with its slug.
 */
final class GridTermsZoneTest extends IntegrationTestCase
{
    private GridViewBuilder $gridViewBuilder;

    private Environment $twig;

    private EntityManagerInterface $entityManager;

    private ?Taxonomy $taxonomy = null;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->gridViewBuilder = static::getContainer()->get(GridViewBuilder::class);
        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $this->twig = $twig;
    }

    public function testItDrawsEveryTermInTheBackendsOrder(): void
    {
        $id = $this->taxonomyWith([
            ['position' => 2, 'fr' => ['Portraits', 'portraits']],
            ['position' => 1, 'fr' => ['Mariages', 'mariages']],
        ]);

        $html = $this->render($id);

        self::assertStringContainsString('Mariages', $html);
        self::assertStringContainsString('Portraits', $html);
        // Position 1 before position 2, whatever order they were created in.
        self::assertLessThan(
            (int) mb_strpos($html, 'Portraits'),
            (int) mb_strpos($html, 'Mariages'),
        );
    }

    public function testEachTermLinksToItsOwnArchive(): void
    {
        $html = $this->render($this->taxonomyWith([
            ['position' => 1, 'fr' => ['Mariages', 'mariages']],
        ]));

        self::assertStringContainsString('/fr/prestations/mariages', $html);
    }

    /**
     * A term with nothing written in this language stays out. A link labelled
     * with a slug reads as a fault, and a word its author never wrote is not a
     * word to put in front of a reader.
     */
    public function testATermWithNoWordsInThisLanguageIsLeftOut(): void
    {
        $html = $this->render($this->taxonomyWith([
            ['position' => 1, 'fr' => ['Mariages', 'mariages']],
            ['position' => 2, 'en' => ['Portraits', 'portraits']],
        ]));

        self::assertStringContainsString('Mariages', $html);
        self::assertStringNotContainsString('Portraits', $html);
    }

    /** A taxonomy nobody picked, or one since deleted, draws nothing at all. */
    public function testAZoneNamingNoTaxonomyDrawsNothing(): void
    {
        self::assertSame('', mb_trim(strip_tags($this->render(null))));
    }

    /**
     * @param list<array{position: int, fr?: array{0: string, 1: string}, en?: array{0: string, 1: string}}> $terms
     */
    private function taxonomyWith(array $terms): int
    {
        $taxonomy = new Taxonomy();
        $taxonomy->setSlug('prestations');
        // `translate()` rather than pushing onto the collection: the
        // translations are keyed by locale, so an `add()` files them under a
        // number and `getTranslation('fr')` comes back empty.
        $taxonomy->translate('fr')->setLabel('Prestations');

        $this->entityManager->persist($taxonomy);
        $this->entityManager->persist($taxonomy->translate('fr'));

        foreach ($terms as $spec) {
            $term = new TaxonomyTerm();
            $term->setTaxonomy($taxonomy);
            $term->setPosition($spec['position']);
            $this->entityManager->persist($term);

            foreach (['fr', 'en'] as $locale) {
                if (!isset($spec[$locale])) {
                    continue;
                }

                [$name, $slug] = $spec[$locale];

                $translation = $term->translate($locale);
                $translation->setName($name);
                $translation->setSlug($slug);
                $this->entityManager->persist($translation);
            }
        }

        $this->entityManager->flush();
        $this->taxonomy = $taxonomy;

        return (int) $taxonomy->getId();
    }

    protected function tearDown(): void
    {
        if ($this->taxonomy instanceof Taxonomy) {
            $taxonomy = $this->entityManager->find(Taxonomy::class, $this->taxonomy->getId());

            if (null !== $taxonomy) {
                $this->entityManager->remove($taxonomy);
                $this->entityManager->flush();
            }

            $this->taxonomy = null;
        }

        parent::tearDown();
    }

    private function render(?int $taxonomyId): string
    {
        $grid = $this->gridViewBuilder->build(
            [
                'enabled' => true,
                'zones' => [['id' => 'z1', 'type' => 'terms', 'taxonomyId' => $taxonomyId]],
            ],
            ['zones' => []],
            'fr',
        );

        self::assertNotNull($grid);

        return $this->twig->render(
            'Frontend/themes/default/editorial/post/_grid_zone.html.twig',
            ['zone' => $grid['zones'][0], 'locale' => 'fr'],
        );
    }
}
