<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial;

use Aurora\Module\Editorial\EditorialModule;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function array_reverse;
use function bin2hex;
use function random_bytes;
use function str_contains;

/**
 * The Editorial menu counts a type's posts without loading them.
 *
 * A type with no description is labelled with its number of posts, and the
 * number came from the collection: every post of the type hydrated, on every
 * page of the Editorial back-office.
 */
final class EditorialNavQueriesTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
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
        parent::tearDown();
    }

    public function testTheMenuCountsPostsWithoutLoadingThem(): void
    {
        $type = new PostType();
        $type->setSlug('compte-'.bin2hex(random_bytes(4)))->setLabel('Compté')->setHasArchive(true);
        $this->persist($type);

        foreach (['Un', 'Deux', 'Trois'] as $title) {
            $post = new Post();
            $post->setPostType($type)->setStatus(PostStatusEnum::Draft);
            $post->translate('fr')->setTitle($title)->setSlug(mb_strtolower($title).'-'.$type->getSlug());
            $this->persist($post);
        }

        $this->entityManager->clear();
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $view = static::getContainer()->get(EditorialModule::class)->getModuleNavView();
        self::assertNotNull($view);

        $postLoads = array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $query): bool => str_contains((string) $query['sql'], 'FROM core_posts t0 '),
        );
        self::assertSame([], array_values($postLoads), 'no post is loaded to be counted');
    }

    private function persist(object $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        $this->created[] = $entity;
    }
}
