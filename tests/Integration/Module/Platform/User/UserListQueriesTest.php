<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Platform\User;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Platform\User\Serializer\UserSerializer;
use Aurora\Tests\Integration\Concern\CreatesTestUsers;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_map;
use function array_reverse;
use function bin2hex;
use function random_bytes;

/**
 * Each row of the users list names its manager without a query of its own.
 *
 * The manager was a lazy proxy, opened once per row to read a name. The count
 * is what regresses silently, so it is what is held.
 */
final class UserListQueriesTest extends IntegrationTestCase
{
    use CreatesTestUsers;

    private EntityManagerInterface $entityManager;

    /** @var list<User> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $user) {
            $managed = $this->entityManager->find(User::class, $user->getId());
            if (null !== $managed) {
                $managed->setManager(null);
                $this->entityManager->flush();
                $this->entityManager->remove($managed);
            }
        }

        $this->entityManager->flush();
        parent::tearDown();
    }

    public function testTheManagersComeWithThePage(): void
    {
        $tag = 'liste'.bin2hex(random_bytes(4));

        foreach (['une', 'deux', 'trois'] as $name) {
            $manager = $this->createTestUser($tag.'-chef-'.$name);
            $member = $this->createTestUser($tag.'-membre-'.$name);
            $member->setManager($manager);
            $this->created[] = $manager;
            $this->created[] = $member;
        }
        $this->entityManager->flush();

        $this->entityManager->clear();
        $page = static::getContainer()->get(UserRepository::class)->findPaginated(1, 10, $tag.'-membre');
        self::assertCount(3, $page['items']);

        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $rows = array_map(static::getContainer()->get(UserSerializer::class)->serialize(...), $page['items']);

        self::assertNotNull($rows[0]['manager']);
        self::assertSame([], $holder->getData()['default'] ?? [], 'the rows need nothing more from the database');
    }
}
