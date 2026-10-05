<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Concern;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserStatusEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Helper trait for integration tests that need to spin up arbitrary users
 * (with a chosen role/type/status) on the fly. Centralises the boilerplate
 * to keep test cases focused on the assertion under test.
 *
 * Requires the consuming class to expose the Symfony container via the
 * {@see WebTestCase::getContainer()} static accessor.
 */
trait CreatesTestUsers
{
    protected function createTestUser(
        string $name,
        ?string $email = null,
        UserRoleEnum $role = UserRoleEnum::Admin,
        UserTypeEnum $type = UserTypeEnum::Suite,
        UserStatusEnum $status = UserStatusEnum::Active,
        string $plainPassword = 'verysecure123',
    ): User {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name);
        $user->setEmail($email ?? sprintf('%s-%s@aurora.test', $name, uniqid()));
        $user->setType($type);
        $user->setStatus($status);
        $user->setRoles([$role->value]);
        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
