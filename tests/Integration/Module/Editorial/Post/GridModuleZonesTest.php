<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Studio\Deck\Entity\Deck;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

/**
 * The three zones that expose a module the product already ships.
 *
 * None of them invents anything: the search posts to the endpoint the sequence
 * search already uses, the thread mounts the component the foot of the page
 * already mounts, and the deck is shown through the share link somebody
 * published. What is worth testing is the edges - a thread that would appear
 * twice, and a deck nobody shared.
 */
final class GridModuleZonesTest extends IntegrationTestCase
{
    private GridViewBuilder $gridViewBuilder;

    private Environment $twig;

    private EntityManagerInterface $entityManager;

    /** @var list<object> */
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

    public function testASearchZoneAimsAtTheWholeSiteByDefault(): void
    {
        $html = $this->render(['id' => 'z1', 'type' => 'search']);

        // The props ride in a JSON attribute, so the slashes arrive escaped.
        self::assertStringContainsString('\\/fr\\/search', $html);
        self::assertStringNotContainsString('type=', $html);
    }

    public function testASearchZoneCanBeNarrowedToOneType(): void
    {
        $type = $this->postType('guides');

        $html = $this->render(['id' => 'z1', 'type' => 'search', 'postTypeId' => $type]);

        self::assertStringContainsString('type=guides', $html);
    }

    /**
     * The flag the post template reads. Without it a page carrying the zone
     * would show the same conversation twice, and the second one would be the
     * one nobody asked for.
     */
    public function testAGridSaysWhetherItPlacesTheThreadItself(): void
    {
        $withoutZone = $this->gridViewBuilder->build(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'separator']]],
            ['zones' => []],
            'fr',
        );

        self::assertNotNull($withoutZone);
        self::assertFalse($withoutZone['hasComments']);

        $withZone = $this->gridViewBuilder->build(
            ['enabled' => true, 'zones' => [[
                'id' => 'z1',
                'type' => 'stack',
                'children' => [['id' => 'c1', 'type' => 'comments']],
            ]]],
            ['zones' => []],
            'fr',
        );

        self::assertNotNull($withZone);
        // A thread tucked into a stack is still the thread.
        self::assertTrue($withZone['hasComments']);
    }

    /** A deck nobody has shared is an internal document, and draws nothing. */
    public function testADeckWithNoShareLinkDrawsNothing(): void
    {
        $deckId = $this->deck();

        self::assertSame('', mb_trim(strip_tags($this->render(['id' => 'z1', 'type' => 'deck', 'deckId' => $deckId]))));
    }

    public function testADeckZoneNamingNothingDrawsNothing(): void
    {
        self::assertSame('', mb_trim(strip_tags($this->render(['id' => 'z1', 'type' => 'deck']))));
    }

    private function deck(): int
    {
        $deck = new Deck();
        $deck->setTitle('Notre offre '.bin2hex(random_bytes(3)));

        $this->entityManager->persist($deck);
        $this->entityManager->flush();

        $this->created[] = $deck;

        return (int) $deck->getId();
    }

    private function postType(string $slug): int
    {
        $type = $this->entityManager->getRepository(PostType::class)->findOneBy(['slug' => $slug]);

        if (null === $type) {
            $type = new PostType();
            $type->setSlug($slug)->setLabel('Guides')->setIcon('file-text')->setHasArchive(false);
            $this->entityManager->persist($type);
            $this->entityManager->flush();
            $this->created[] = $type;
        }

        return (int) $type->getId();
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
        $this->created = [];

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $zone
     */
    private function render(array $zone): string
    {
        $grid = $this->gridViewBuilder->build(
            ['enabled' => true, 'zones' => [$zone]],
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
