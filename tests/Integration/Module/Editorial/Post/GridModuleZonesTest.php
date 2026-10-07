<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLink;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableFormatEnum;
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
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->gridViewBuilder = self::getContainer()->get(GridViewBuilder::class);
        $twig = self::getContainer()->get(Environment::class);
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

    /** A presentation nobody has shared is an internal document, and draws nothing. */
    public function testAPresentationWithNoReadingLinkDrawsNothing(): void
    {
        $deliverableId = $this->presentation();

        self::assertSame('', mb_trim(strip_tags($this->render(['id' => 'z1', 'type' => 'deck', 'deliverableId' => $deliverableId]))));
    }

    /**
     * A presentation is a slides deliverable: the zone keeps its type and
     * frames the deliverable's reading page, through a link that is live and
     * open. A link behind a password is a locked door, and draws nothing.
     */
    public function testAPresentationIsFramedThroughItsLiveReadingLink(): void
    {
        $deliverableId = $this->presentation(link: true);

        $html = $this->render(['id' => 'z1', 'type' => 'deck', 'deliverableId' => $deliverableId]);

        self::assertMatchesRegularExpression('#<iframe[^>]+src="/deliverables/[a-f0-9]{64}"#', $html);

        self::assertSame('', mb_trim(strip_tags($this->render(['id' => 'z1', 'type' => 'deck', 'deliverableId' => $this->presentation(link: true, locked: true)]))));
    }

    /** A page is not a presentation, even with a reading link. */
    public function testAPageDeliverableIsNotDrawnByADeckZone(): void
    {
        $deliverableId = $this->presentation(link: true, format: DeliverableFormatEnum::Page);

        self::assertSame('', mb_trim(strip_tags($this->render(['id' => 'z1', 'type' => 'deck', 'deliverableId' => $deliverableId]))));
    }

    public function testADeckZoneNamingNothingDrawsNothing(): void
    {
        self::assertSame('', mb_trim(strip_tags($this->render(['id' => 'z1', 'type' => 'deck']))));
    }

    private function presentation(bool $link = false, bool $locked = false, DeliverableFormatEnum $format = DeliverableFormatEnum::Slides): int
    {
        $deliverable = new Deliverable(null, 'Notre offre '.bin2hex(random_bytes(3)), 'fr', $format);

        $this->entityManager->persist($deliverable);
        $this->created[] = $deliverable;

        if ($link) {
            $reading = new DeliverableLink($deliverable);
            if ($locked) {
                $reading->setPasswordHash(password_hash('secret', PASSWORD_DEFAULT));
            }

            $this->entityManager->persist($reading);
        }

        $this->entityManager->flush();

        return (int) $deliverable->getId();
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
