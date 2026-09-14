<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

/**
 * Before and after, and what it looks like before any script runs.
 *
 * That last part is the whole of what is tested here. The handle is drawn by
 * `compareSlider.js` and lives in the browser; what the server owes a reader is
 * a comparison that already works - two labelled pictures beside each other -
 * so this holds the markup that arrives, not the upgrade applied to it.
 */
final class GridCompareZoneTest extends IntegrationTestCase
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

    public function testWithoutAScriptItIsTwoLabelledPicturesSideBySide(): void
    {
        $html = $this->render([$this->picture('avant.png'), $this->picture('apres.png')]);

        self::assertSame(2, mb_substr_count($html, '<img'));
        self::assertStringContainsString('data-compare', $html);
        // Both readable on their own, which is what a screen reader is offered
        // and what anybody gets before the script arrives.
        self::assertStringContainsString('Avant', $html);
        self::assertStringContainsString('Après', $html);
        self::assertSame(2, mb_substr_count($html, '<figcaption'));
    }

    /** The typed words win over the defaults, and are translated. */
    public function testTheAuthorCanNameTheTwoSides(): void
    {
        $html = $this->render(
            [$this->picture('avant.png'), $this->picture('apres.png')],
            ['alt' => '2019', 'label' => 'Aujourd\'hui'],
        );

        self::assertStringContainsString('2019', $html);
        self::assertStringContainsString('Aujourd&#039;hui', $html);
        self::assertStringNotContainsString('>Avant<', $html);
    }

    /**
     * Both or neither. One picture of a pair is not a comparison, and a handle
     * with nothing on its right is a control that lies about what it does.
     */
    public function testOneSideAloneDrawsNothing(): void
    {
        self::assertSame('', mb_trim(strip_tags($this->render([$this->picture('avant.png')]))));
        self::assertSame('', mb_trim(strip_tags($this->render([]))));
    }

    /** A third picture has nowhere to be: the normaliser stops at two. */
    public function testAThirdPictureIsDropped(): void
    {
        $html = $this->render([
            $this->picture('avant.png'),
            $this->picture('apres.png'),
            $this->picture('troisieme.png'),
        ]);

        self::assertSame(2, mb_substr_count($html, '<img'));
        self::assertStringNotContainsString('troisieme.png', $html);
    }

    private function picture(string $fileName): int
    {
        $document = new Document();
        $document->setTitle('Photographie');
        $document->setMimeType('image/png');
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
     * @param list<int>            $mediaIds
     * @param array<string, mixed> $words
     */
    private function render(array $mediaIds, array $words = []): string
    {
        $grid = $this->gridViewBuilder->build(
            [
                'enabled' => true,
                'zones' => [['id' => 'z1', 'type' => 'compare', 'mediaIds' => $mediaIds]],
            ],
            ['zones' => ['z1' => $words]],
            'fr',
        );

        self::assertNotNull($grid);

        return $this->twig->render(
            'Frontend/themes/default/editorial/post/_grid_zone.html.twig',
            ['zone' => $grid['zones'][0], 'locale' => 'fr'],
        );
    }
}
