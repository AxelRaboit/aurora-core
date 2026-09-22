<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

/**
 * The two costumes an item list gained, from the stored entry to the markup.
 *
 * Both reuse the four fields the other five already share, so what is worth
 * checking is not that the words travel - the builder is tested for that - but
 * that each costume draws the thing it promises: a date in the margin of a
 * history, a price and a recommended card on a page of offers.
 */
final class GridItemDisplaysTest extends IntegrationTestCase
{
    private GridViewBuilder $gridViewBuilder;

    private Environment $twig;

    private EntityManagerInterface $entityManager;

    /** @var list<int> */
    private array $created = [];

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

    public function testATimelinePutsItsDateInTheMargin(): void
    {
        $html = $this->render('timeline', [
            ['id' => 'i1'],
        ], ['i1' => ['caption' => '2024', 'title' => 'Lancement', 'description' => 'Le studio ouvre.']]);

        self::assertStringContainsString('2024', $html);
        self::assertStringContainsString('Lancement', $html);
        // The date leads, above the title it belongs to.
        self::assertLessThan(mb_strpos($html, 'Lancement'), (int) mb_strpos($html, '2024'));
    }

    /** One line per line: a list of what is included has to read as a list. */
    public function testAnOfferTurnsEachLineIntoABullet(): void
    {
        $html = $this->render('offers', [
            ['id' => 'i1'],
        ], ['i1' => [
            'title' => 'Site vitrine',
            'caption' => 'à partir de 1 500 €',
            'description' => "Cinq pages\nUn formulaire\n",
        ]]);

        self::assertSame(2, mb_substr_count($html, '<li'), 'a blank line is not an item');
        self::assertStringContainsString('Cinq pages', $html);
        self::assertStringContainsString('à partir de 1 500 €', $html);
    }

    public function testTheRecommendedOfferIsDrawnLouderThanTheOthers(): void
    {
        $plain = $this->render('offers', [['id' => 'i1']], ['i1' => ['title' => 'Simple']]);
        $featured = $this->render(
            'offers',
            [['id' => 'i1', 'featured' => true]],
            ['i1' => ['title' => 'Simple']],
        );

        self::assertStringNotContainsString('border-accent', $plain);
        self::assertStringContainsString('border-accent', $featured);
        self::assertStringContainsString('Recommandé', $featured);
    }

    /**
     * An offer with a pictogram draws it, beside the name it belongs to. The
     * picture has always been on the entry - the editor offers one for every
     * item - and this was the one costume that ignored it, so a card could be
     * given an icon and show nothing.
     */
    public function testAnOfferDrawsThePictureItWasGiven(): void
    {
        $mediaId = $this->picture();

        $html = $this->render(
            'offers',
            [['id' => 'i1', 'mediaId' => $mediaId]],
            ['i1' => ['title' => 'Stratégie de contenu']],
        );

        self::assertStringContainsString('object-contain', $html);
        self::assertStringContainsString('picto.png', $html);
        // Beside the name, not over it: both sit on one row, the picture
        // first because that is the order the pair is read in.
        self::assertStringContainsString('flex items-center gap-3', $html);
        self::assertLessThan(
            (int) mb_strpos($html, 'Stratégie de contenu'),
            (int) mb_strpos($html, 'picto.png'),
        );
    }

    /** And an offer without one is unchanged. */
    public function testAnOfferWithoutAPictureDrawsNoImage(): void
    {
        $html = $this->render('offers', [['id' => 'i1']], ['i1' => ['title' => 'Simple']]);

        self::assertStringNotContainsString('<img', $html);
    }

    /**
     * One panel at a time, when the author asks for it. `name` is what makes
     * the browser close the others, with no script - and its absence is what
     * keeps every list already published behaving as it did.
     */
    public function testAFoldingListCanKeepOnlyOnePanelOpen(): void
    {
        $plain = $this->render('faq', [['id' => 'i1']], ['i1' => ['title' => 'Combien ça coûte ?']]);

        self::assertStringContainsString('<details', $plain);
        self::assertStringNotContainsString('name="fold-', $plain);

        $exclusive = $this->render(
            'faq',
            [['id' => 'i1'], ['id' => 'i2']],
            ['i1' => ['title' => 'Combien ça coûte ?'], 'i2' => ['title' => 'En combien de temps ?']],
            exclusiveOpen: true,
        );

        // Both panels in the same group, named after the zone so a second list
        // on the page does not close this one's answers.
        self::assertSame(2, mb_substr_count($exclusive, 'name="fold-z1"'));
    }

    private function picture(): int
    {
        $document = new Document();
        $document->setTitle('Pictogramme');
        $document->setMimeType('image/png');
        $document->setFilePath('ged/2026/09/picto.png');

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $id = (int) $document->getId();
        $this->created[] = $id;

        return $id;
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $document = $this->entityManager->find(Document::class, $id);

            if (null !== $document) {
                $this->entityManager->remove($document);
            }
        }

        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    /**
     * @param list<array<string, mixed>>          $items
     * @param array<string, array<string, mixed>> $words
     */
    private function render(string $display, array $items, array $words, bool $exclusiveOpen = false): string
    {
        $grid = $this->gridViewBuilder->build(
            [
                'enabled' => true,
                'zones' => [[
                    'id' => 'z1',
                    'type' => 'items',
                    'display' => $display,
                    'items' => $items,
                    'exclusiveOpen' => $exclusiveOpen,
                ]],
            ],
            ['zones' => ['z1' => ['items' => $words]]],
            'fr',
        );

        self::assertNotNull($grid);

        return $this->twig->render(
            'Frontend/themes/default/editorial/post/_grid_zone.html.twig',
            ['zone' => $grid['zones'][0], 'locale' => 'fr'],
        );
    }
}
