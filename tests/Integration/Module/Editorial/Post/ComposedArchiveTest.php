<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A listing page an author composed.
 *
 * An archive drew a header and a row of cards, and nothing else could be put
 * on it. It already borrows its banner and its summary from a publication it
 * designates, so it now borrows that publication's content grid too - which is
 * what lets a card sit beside the words that sell it rather than in a row with
 * its neighbours.
 *
 * The switch that comes with it is the part worth testing hardest: a composed
 * archive that also lists shows every entry twice, once arranged and once as a
 * card nobody arranged, and that is the failure this feature would otherwise
 * introduce rather than fix.
 */
final class ComposedArchiveTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $created = [];

    private PostType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
            $this->entityManager->remove($entity);
        }

        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    public function testTheArchiveDrawsTheContentItsPublicationCarries(): void
    {
        $this->publishArchive(showsList: true);

        $this->client->request('GET', '/fr/composed-type');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Ce que je prends en charge',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /** The point of the switch: composed and listed is every entry twice. */
    public function testTheListCanBeSwitchedOff(): void
    {
        $this->publishArchive(showsList: false);

        $this->client->request('GET', '/fr/composed-type');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Ce que je prends en charge', $html);
        self::assertStringNotContainsString('Une publication de la liste', $html);
    }

    /** Left on, an archive goes on listing exactly as it always has. */
    public function testTheListIsStillDrawnByDefault(): void
    {
        $this->publishArchive(showsList: true);

        $this->client->request('GET', '/fr/composed-type');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Une publication de la liste',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    private function publishArchive(bool $showsList): void
    {
        $this->type = new PostType();
        $this->type->setSlug('composed-type')
            ->setLabel('Composed type')
            ->setIcon('file-text')
            ->setHasArchive(true)
            ->setArchiveShowsList($showsList);

        $this->entityManager->persist($this->type);
        $this->entityManager->flush();
        $this->created[] = $this->type;

        // The publication the archive borrows from, and one the archive lists.
        $header = $this->publish('En-tête', 'compose-header', [
            'enabled' => true,
            'snap' => true,
            'zones' => [['id' => 'a1', 'type' => 'text', 'span' => ['base' => 48, 'md' => null, 'lg' => 48]]],
        ], ['zones' => ['a1' => ['blocks' => [
            ['type' => 'header', 'data' => ['text' => 'Ce que je prends en charge', 'level' => 2]],
        ]]]]);

        $this->publish('Une publication de la liste', 'compose-listed', [], []);

        $this->type->setArchivePostId($header->getId());
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $content
     */
    private function publish(string $title, string $slug, array $layout, array $content): Post
    {
        $suffix = bin2hex(random_bytes(4));

        $post = new Post();
        $post->setPostType($this->type)
            ->setStatus(PostStatusEnum::Published)
            ->setPublishedAt(new DateTimeImmutable('-1 day'))
            ->setGridLayout($layout);

        $post->translate('fr')->setTitle($title)->setSlug($slug.'-'.$suffix)->setGrid($content);

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->created[] = $post;

        return $post;
    }
}
