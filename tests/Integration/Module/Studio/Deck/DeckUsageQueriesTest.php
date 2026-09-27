<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deck;

use Aurora\Module\Studio\Deck\Entity\Deck;
use Aurora\Module\Studio\Deck\Entity\Slide;
use Aurora\Module\Studio\Deck\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deck\Service\DeckDocumentUsageProvider;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_reverse;
use function count;

/**
 * The library's usage badges read the decks in one query, however many.
 *
 * They walk every deck's slides for the pictures they draw, and the slides
 * were lazy: a query per deck, each time the library opened.
 */
final class DeckUsageQueriesTest extends IntegrationTestCase
{
    private const int PICTURE = 2_000_000_001;

    private EntityManagerInterface $entityManager;

    /** @var list<Deck> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $deck) {
            $managed = $this->entityManager->find(Deck::class, $deck->getId());
            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }

        $this->entityManager->flush();
        parent::tearDown();
    }

    public function testTheBadgesDoNotGrowWithTheDecks(): void
    {
        $this->decks(3);
        [$withThree, $counts] = $this->queriesForTheBadges();
        self::assertSame(3, $counts[self::PICTURE] ?? 0);

        $this->decks(3);
        [$withSix, $counts] = $this->queriesForTheBadges();
        self::assertSame(6, $counts[self::PICTURE] ?? 0);

        self::assertSame($withThree, $withSix, 'three more decks, not one more query');
    }

    /** @return array{int, array<int, int>} */
    private function queriesForTheBadges(): array
    {
        $this->entityManager->clear();
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $counts = static::getContainer()->get(DeckDocumentUsageProvider::class)->countUsagesFor([self::PICTURE]);

        return [count($holder->getData()['default'] ?? []), $counts];
    }

    private function decks(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $deck = new Deck();
            $deck->setTitle('Diaporama '.$i);
            $slide = new Slide();
            $slide->setLayout(SlideLayoutEnum::cases()[0])->setContent(['mediaId' => self::PICTURE])->setPosition(0);
            $deck->addSlide($slide);
            $this->entityManager->persist($deck);
            $this->entityManager->persist($slide);
            $this->entityManager->flush();
            $this->created[] = $deck;
        }
    }
}
