<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function array_map;
use function bin2hex;
use function random_bytes;

/**
 * The order a listing is read in.
 *
 * Everything was ordered by publication date, which is right for a blog and
 * wrong for anything read in sequence. The tour of the product already faked an
 * order by spacing its cards a minute apart in `published_at`, and a
 * documentation of a hundred pages cannot be held together that way.
 *
 * Two things are worth a test here, and they pull against each other. A
 * numbered publication has to come first, in the order it was numbered. And a
 * listing where nobody numbered anything has to be byte-identical to what it
 * was before the column existed - which is the assertion that lets this ship
 * to sites that will never number a thing.
 */
final class ReadingOrderTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    private PostRepository $posts;

    private PostType $type;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::createClient();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->posts = $container->get(PostRepository::class);

        $this->type = new PostType();
        $this->type->setSlug('ordered-type-'.bin2hex(random_bytes(3)))
            ->setLabel('Ordered type')
            ->setHasArchive(true);

        $this->entityManager->persist($this->type);
        $this->entityManager->flush();
        $this->created[] = $this->type;
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

    /** Untouched, a listing is what it always was: newest first. */
    public function testWithoutAPositionTheDateStillDecides(): void
    {
        $this->publish('Le plus ancien', '-3 days');
        $this->publish('Celui du milieu', '-2 days');
        $this->publish('Le plus récent', '-1 day');

        self::assertSame(
            ['Le plus récent', 'Celui du milieu', 'Le plus ancien'],
            $this->titles(),
        );
    }

    /** A number wins over the date, and the numbers run in their own order. */
    public function testANumberedPublicationComesFirst(): void
    {
        $this->publish('Le plus récent', '-1 day');
        $this->publish('Chapitre deux', '-2 days', 2);
        $this->publish('Chapitre un', '-3 days', 1);

        self::assertSame(
            ['Chapitre un', 'Chapitre deux', 'Le plus récent'],
            $this->titles(),
        );
    }

    /**
     * The behaviour the ordering leans on, pinned.
     *
     * `ORDER BY position ASC` puts the nulls last because PostgreSQL sorts
     * them that way in ascending order - a default, not a guarantee of the
     * standard. Reverse it and an author who numbers one page finds it last,
     * which is the opposite of what numbering means. So it is asserted rather
     * than assumed.
     */
    public function testTheUnnumberedFallInBehind(): void
    {
        $this->publish('Publiée hier', '-1 day');
        $this->publish('Publiée avant-hier', '-2 days');
        $this->publish('Épinglée', '-9 days', 1);

        self::assertSame(
            ['Épinglée', 'Publiée hier', 'Publiée avant-hier'],
            $this->titles(),
        );
    }

    /** Zero is not a position: a reading order starts at one. */
    public function testZeroIsRefusedRatherThanStored(): void
    {
        $post = $this->publish('Sans opinion', '-1 day', 0);

        self::assertNull($post->getPosition());
    }

    /** @return list<string> */
    private function titles(): array
    {
        $result = $this->posts->findPublishedByPostType((int) $this->type->getId(), 1, 20, 'fr');

        return array_map(
            static fn (Post $post): string => (string) $post->getTranslation('fr')?->getTitle(),
            $result['items'],
        );
    }

    private function publish(string $title, string $publishedAt, ?int $position = null): Post
    {
        $post = new Post();
        $post->setPostType($this->type)
            ->setStatus(PostStatusEnum::Published)
            ->setPublishedAt(new DateTimeImmutable($publishedAt))
            ->setPosition($position);

        $post->translate('fr')->setTitle($title)->setSlug('ordre-'.bin2hex(random_bytes(4)));

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->created[] = $post;

        return $post;
    }
}
