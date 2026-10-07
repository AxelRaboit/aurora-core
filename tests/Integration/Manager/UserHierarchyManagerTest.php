<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Manager;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Manager\UserHierarchyManager;
use Aurora\Tests\Integration\Concern\CreatesTestUsers;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final class UserHierarchyManagerTest extends IntegrationTestCase
{
    use CreatesTestUsers;

    private UserHierarchyManager $hierarchyManager;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->hierarchyManager = static::getContainer()->get(UserHierarchyManager::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testSettingNullClearsExistingManager(): void
    {
        $alice = $this->createTestUser('alice');
        $bob = $this->createTestUser('bob');
        $bob->setManager($alice);
        $this->entityManager->flush();

        $this->hierarchyManager->setManager($bob, null);

        self::assertNull($bob->getManager());
    }

    public function testAssignsValidManager(): void
    {
        $alice = $this->createTestUser('alice');
        $bob = $this->createTestUser('bob');

        $this->hierarchyManager->setManager($bob, $alice->getId());

        self::assertSame($alice->getId(), $bob->getManager()?->getId());
    }

    public function testRejectsSelfAsManager(): void
    {
        $alice = $this->createTestUser('alice');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('suite.users.errors.manager_self');
        $this->hierarchyManager->setManager($alice, $alice->getId());
    }

    public function testRejectsNonExistentManager(): void
    {
        $alice = $this->createTestUser('alice');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('suite.users.errors.manager_not_found');
        $this->hierarchyManager->setManager($alice, 999_999);
    }

    public function testRejectsDirectCycle(): void
    {
        $alice = $this->createTestUser('alice');
        $bob = $this->createTestUser('bob');
        $this->hierarchyManager->setManager($alice, $bob->getId());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('suite.users.errors.manager_cycle');
        $this->hierarchyManager->setManager($bob, $alice->getId());
    }

    public function testRejectsTransitiveCycle(): void
    {
        $alice = $this->createTestUser('alice');
        $bob = $this->createTestUser('bob');
        $carol = $this->createTestUser('carol');

        $this->hierarchyManager->setManager($alice, $bob->getId());
        $this->hierarchyManager->setManager($bob, $carol->getId());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('suite.users.errors.manager_cycle');
        $this->hierarchyManager->setManager($carol, $alice->getId());
    }

    public function testApplyManagerDoesNotFlush(): void
    {
        $alice = $this->createTestUser('alice');
        $bob = $this->createTestUser('bob');

        $this->hierarchyManager->applyManager($bob, $alice->getId());

        // In-memory state mutated
        self::assertSame($alice->getId(), $bob->getManager()?->getId());

        // …but no flush happened: clearing the EM and refetching shows the original null state
        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(User::class, $bob->getId());
        self::assertNull($refreshed->getManager());
    }

    public function testSubordinatesCollectionPopulatedAfterAssignment(): void
    {
        $alice = $this->createTestUser('alice');
        $bob = $this->createTestUser('bob');
        $carol = $this->createTestUser('carol');

        $this->hierarchyManager->setManager($bob, $alice->getId());
        $this->hierarchyManager->setManager($carol, $alice->getId());

        $this->entityManager->refresh($alice);
        $names = array_map(static fn (User $subordinate): string => $subordinate->getName(), $alice->getSubordinates()->toArray());
        self::assertCount(2, $names);
        self::assertContains('bob', $names);
        self::assertContains('carol', $names);
    }
}
