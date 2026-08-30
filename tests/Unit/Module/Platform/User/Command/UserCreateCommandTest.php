<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Platform\User\Command;

use Aurora\Module\Platform\User\Command\UserCreateCommand;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The role the first account of an installation gets.
 *
 * `/dev` is gated on `ROLE_DEV`, and `aurora:install` used to point people at
 * `aurora:user:create --admin` for their first account - which left the owner
 * of a fresh site unable to open the dashboard that toggles modules or lists
 * mount points. Following the documented path put you outside your own
 * installation.
 */
#[AllowMockObjectsWithoutExpectations]
final class UserCreateCommandTest extends TestCase
{
    /** @param array<string, mixed> $input */
    private function createUser(array $input, ?User &$saved = null): CommandTester
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$saved): void {
                if ($entity instanceof User) {
                    $saved = $entity;
                }
            }
        );

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $tester = new CommandTester(new UserCreateCommand($repository, $entityManager, $hasher));
        $tester->execute($input);

        return $tester;
    }

    public function testDevGivesTheRoleThatOpensTheDevDashboard(): void
    {
        $saved = null;
        $this->createUser(['--email' => 'owner@example.com', '--password' => 'a-long-enough-secret', '--dev' => true], $saved);

        self::assertInstanceOf(User::class, $saved);
        // `getRoles()` always appends ROLE_USER, so this asks what was granted
        // rather than comparing the whole list.
        self::assertContains(UserRoleEnum::Dev->value, $saved->getRoles());
    }

    public function testAdminStillGivesTheAdministratorRole(): void
    {
        $saved = null;
        $this->createUser(['--email' => 'owner@example.com', '--password' => 'a-long-enough-secret', '--admin' => true], $saved);

        self::assertInstanceOf(User::class, $saved);
        self::assertContains(UserRoleEnum::Admin->value, $saved->getRoles());
        self::assertNotContains(UserRoleEnum::Dev->value, $saved->getRoles());
    }

    /**
     * Asking for both is asking for the wider one, not a conflict: the security
     * hierarchy already puts `ROLE_ADMIN` inside `ROLE_DEV`.
     */
    public function testBothFlagsResolveToDev(): void
    {
        $saved = null;
        $this->createUser(['--email' => 'owner@example.com', '--password' => 'a-long-enough-secret', '--admin' => true, '--dev' => true], $saved);

        self::assertInstanceOf(User::class, $saved);
        self::assertContains(UserRoleEnum::Dev->value, $saved->getRoles());
    }

    public function testNeitherFlagGivesAnOrdinaryUser(): void
    {
        $saved = null;
        $this->createUser(['--email' => 'owner@example.com', '--password' => 'a-long-enough-secret'], $saved);

        self::assertInstanceOf(User::class, $saved);
        self::assertNotContains(UserRoleEnum::Admin->value, $saved->getRoles());
        self::assertNotContains(UserRoleEnum::Dev->value, $saved->getRoles());
    }
}
