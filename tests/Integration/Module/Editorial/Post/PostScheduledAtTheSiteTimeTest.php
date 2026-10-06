<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Configuration\Setting\Service\SiteTimezone;
use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

use function bin2hex;
use function random_bytes;

/**
 * "Scheduled for 9am" means 9am in the site's time.
 *
 * The picker sent a bare time, which the server (in UTC) read as a UTC time: a
 * publication scheduled for 9am in Paris went out at 11am in summer. The
 * editor now sends the time with the site's offset, and a bare time coming
 * from an old client is read in the site's time. In both cases the column
 * receives the instant in UTC.
 */
final class PostScheduledAtTheSiteTimeTest extends IntegrationTestCase
{
    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function tearDown(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach (array_reverse($this->created) as [$class, $id]) {
            $entity = $entityManager->find($class, $id);
            if (null !== $entity) {
                $entityManager->remove($entity);
            }
        }

        $entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    /** @return iterable<string, array{bool}> */
    public static function sentForms(): iterable
    {
        yield 'with the offset, as the editor sends it' => [true];
        yield 'bare, as an older client sends it' => [false];
    }

    #[DataProvider('sentForms')]
    public function testNineOClockIsNineOClockAtTheSite(bool $withOffset): void
    {
        $zone = self::getContainer()->get(SiteTimezone::class)->get();
        $local = new DateTimeImmutable('+5 days', $zone)->setTime(9, 0);
        $sent = $withOffset ? $local->format('Y-m-d\TH:i:sP') : $local->format('Y-m-d\TH:i');

        $post = $this->create($sent);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $stored = $entityManager->find(Post::class, $post->getId());
        self::assertInstanceOf(Post::class, $stored);

        self::assertSame(
            $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'),
            $stored->getScheduledAt()?->format('Y-m-d H:i'),
        );
    }

    private function create(string $scheduledAt): Post
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $postType = new PostType();
        $postType->setSlug('site-time-'.bin2hex(random_bytes(4)));
        $postType->setLabel('Site time');

        $entityManager->persist($postType);
        $entityManager->flush();
        $this->created[] = [PostType::class, (int) $postType->getId()];

        $post = self::getContainer()->get(PostManagerInterface::class)->create(
            self::getContainer()->get(PostInputFactoryInterface::class)->fromArray([
                'postTypeId' => $postType->getId(),
                'status' => 'scheduled',
                'scheduledAt' => $scheduledAt,
                'translations' => ['fr' => ['title' => 'Programmée à 9 h']],
            ]),
        );
        self::assertInstanceOf(Post::class, $post);
        $this->created[] = [Post::class, (int) $post->getId()];

        return $post;
    }
}
