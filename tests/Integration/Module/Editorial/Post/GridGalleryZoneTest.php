<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

/**
 * A run of pictures in one zone, and the two things that make it more than six
 * media zones in a row.
 *
 * The layout falls out of the ratio rather than out of a setting of its own -
 * own proportions means columns, a shape means a cropped grid - and every
 * picture takes its place in the grid's single overlay, in the order it is
 * drawn. Both are easy to get subtly wrong and invisible when they are.
 */
final class GridGalleryZoneTest extends IntegrationTestCase
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

    public function testItDrawsEveryPictureInTheOrderItWasArranged(): void
    {
        $ids = [$this->picture('un.png'), $this->picture('deux.png'), $this->picture('trois.png')];

        $html = $this->render($ids);

        self::assertSame(3, mb_substr_count($html, '<img'));
        self::assertLessThan((int) mb_strpos($html, 'deux.png'), (int) mb_strpos($html, 'un.png'));
        self::assertLessThan((int) mb_strpos($html, 'trois.png'), (int) mb_strpos($html, 'deux.png'));
    }

    /**
     * The layout is the ratio's business. Own proportions flow down CSS
     * columns and nothing is cropped; a shape crops every tile into a real
     * grid. One setting, two mechanisms, no third field to explain.
     */
    public function testTheRatioDecidesWhetherPicturesFlowOrAreCropped(): void
    {
        $ids = [$this->picture('un.png'), $this->picture('deux.png')];

        $flowing = $this->render($ids, GridNormalizer::RATIO_NATURAL);
        self::assertStringContainsString('columns-', $flowing);
        self::assertStringNotContainsString('aspect-ratio', $flowing);

        $cropped = $this->render($ids, '1x1');
        self::assertStringContainsString('grid-cols-', $cropped);
        self::assertStringContainsString('aspect-ratio', $cropped);
    }

    /**
     * Each picture gets its own stop in the grid's overlay. Numbered here
     * rather than per zone, because the overlay is mounted once for the whole
     * grid and a reader steps from one picture to the next across zones.
     */
    public function testEveryPictureTakesItsPlaceInTheOverlay(): void
    {
        $html = $this->render([
            $this->picture('un.png'),
            $this->picture('deux.png'),
            $this->picture('trois.png'),
        ]);

        self::assertStringContainsString('data-grid-image-open="0"', $html);
        self::assertStringContainsString('data-grid-image-open="1"', $html);
        self::assertStringContainsString('data-grid-image-open="2"', $html);
    }

    /**
     * A document named here but since deleted, or replaced by something that
     * is not a picture, drops out. The alternative is a hole with a broken
     * image in it, which reads as a site that does not work.
     */
    public function testADocumentThatIsNoLongerAPictureDropsOut(): void
    {
        $html = $this->render([
            $this->picture('un.png'),
            $this->picture('plaquette.pdf', 'application/pdf'),
            999_999,
        ]);

        self::assertSame(1, mb_substr_count($html, '<img'));
        self::assertStringNotContainsString('plaquette.pdf', $html);
    }

    public function testAnEmptyGalleryDrawsNothing(): void
    {
        self::assertSame('', mb_trim(strip_tags($this->render([]))));
    }

    private function picture(string $fileName, string $mimeType = 'image/png'): int
    {
        $document = new Document();
        $document->setTitle('Photographie');
        $document->setMimeType($mimeType);
        $document->setFilePath('ged/2026/09/'.$fileName);

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
     * @param list<int> $mediaIds
     */
    private function render(array $mediaIds, string $ratio = GridNormalizer::RATIO_NATURAL): string
    {
        $grid = $this->gridViewBuilder->build(
            [
                'enabled' => true,
                'zones' => [[
                    'id' => 'z1',
                    'type' => 'gallery',
                    'mediaIds' => $mediaIds,
                    'ratio' => $ratio,
                    'columns' => 3,
                ]],
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
