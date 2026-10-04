<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

/**
 * A photo's author under it, unless the author of the page took it off.
 *
 * Pexels asks for no credit, and a report laid out like a slide deck has no
 * room for a line of small print under every phone. The credit stays on by
 * default, so a picture whose licence does ask keeps it.
 */
final class GridMediaCreditTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    private ?int $documentId = null;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if (null !== $this->documentId) {
            $document = $this->entityManager->find(Document::class, $this->documentId);
            if (null !== $document) {
                $this->entityManager->remove($document);
                $this->entityManager->flush();
            }
        }

        parent::tearDown();
    }

    public function testThePhotographerIsNamedByDefault(): void
    {
        self::assertStringContainsString('Sanket Mishra', $this->render([]));
    }

    public function testTheCreditCanBeTakenOff(): void
    {
        self::assertStringNotContainsString('Sanket Mishra', $this->render(['showCredit' => false]));
    }

    /** @param array<string, mixed> $options */
    private function render(array $options): string
    {
        $document = new Document();
        $document->setTitle('Un téléphone');
        $document->setMimeType('image/jpeg');
        $document->setFilePath('ged/2026/10/phone.jpg');
        $document->setAttributionName('Sanket Mishra');
        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->documentId = (int) $document->getId();

        $builder = static::getContainer()->get(GridViewBuilder::class);
        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $grid = $builder->build(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'media', 'mediaId' => $this->documentId, 'options' => $options]]],
            ['zones' => ['z1' => []]],
            'fr',
        );
        self::assertNotNull($grid);

        return $twig->render('Frontend/themes/default/editorial/post/_grid_zone.html.twig', ['zone' => $grid['zones'][0], 'locale' => 'fr']);
    }
}
