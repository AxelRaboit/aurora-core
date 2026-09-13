<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deck;

use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Service\DocumentUsageService;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Studio\Deck\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deck\Manager\DeckManager;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_column;
use function array_merge;
use function uniqid;

/**
 * Deleting a picture says which decks were drawing it.
 *
 * The library's deletion screen asks every tagged provider "who is using
 * this", and nobody answered for decks: a photograph on four slides could be
 * deleted with the screen reporting no usage at all, and the four slides
 * would quietly draw nothing afterwards.
 *
 * Worth an integration test rather than a unit one because the point is the
 * wiring: the provider is found through a container tag, and a provider that
 * is written but not tagged is a provider that answers nobody.
 */
final class DeckDocumentUsageTest extends IntegrationTestCase
{
    public function testADeckDrawingAPictureIsReportedAsAUsage(): void
    {
        $picture = $this->picture();
        $decks = static::getContainer()->get(DeckManager::class);

        $deck = $decks->create('Audit de mars');
        $slide = $decks->addSlide($deck, SlideLayoutEnum::Image);
        $decks->writeContent($slide, ['mediaId' => (int) $picture->getId()]);
        $this->entityManager()->flush();

        $usages = static::getContainer()->get(DocumentUsageService::class)
            ->findUsages((int) $picture->getId());

        $items = array_merge(...array_column($usages['groups'], 'items'));

        self::assertSame(1, $usages['total']);
        self::assertSame('studio.deck', $items[0]['type']);
        self::assertSame('Audit de mars', $items[0]['label']);
        self::assertStringContainsString((string) $deck->getId(), (string) $items[0]['href']);
    }

    /** The backdrop and the logo count too: all three slots draw the file. */
    public function testTheBackdropAndTheLogoCountAsUsagesToo(): void
    {
        $backdrop = $this->picture();
        $logo = $this->picture();
        $decks = static::getContainer()->get(DeckManager::class);

        $deck = $decks->create('Proposition');
        $slide = $decks->addSlide($deck, SlideLayoutEnum::Section);
        $decks->writeContent($slide, ['title' => 'Partie 1', 'bgMediaId' => (int) $backdrop->getId()]);
        $decks->writeAppearance($deck, $deck->getTheme(), ['logoMediaId' => (int) $logo->getId(), 'logoPlacement' => 'every']);
        $this->entityManager()->flush();

        $usages = static::getContainer()->get(DocumentUsageService::class);

        self::assertSame(1, $usages->findUsages((int) $backdrop->getId())['total']);
        self::assertSame(1, $usages->findUsages((int) $logo->getId())['total']);
    }

    public function testAPictureNoDeckDrawsIsReportedByNobody(): void
    {
        $picture = $this->picture();

        $usages = static::getContainer()->get(DocumentUsageService::class)
            ->findUsages((int) $picture->getId());

        self::assertSame(0, $usages['total']);
    }

    private function picture(): DocumentInterface
    {
        $document = new Document();
        $document
            ->setTitle('Une photo')
            ->setOriginalName('photo.jpg')
            ->setFilePath('ged/2026/09/photo-'.uniqid().'.jpg')
            ->setMimeType('image/jpeg')
            ->setStatus(DocumentStatusEnum::Published);

        $this->entityManager()->persist($document);
        $this->entityManager()->flush();

        return $document;
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
