<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Manager;

use Aurora\Module\Platform\Auth\Entity\ResetPasswordRequest;
use Aurora\Module\Platform\Auth\Manager\PasswordResetManager;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserStatusEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetManagerTest extends IntegrationTestCase
{
    private PasswordResetManager $manager;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->manager = static::getContainer()->get(PasswordResetManager::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testCreateRequestForUserReturnsTokenAndExpiry(): void
    {
        $user = $this->createUser(UserTypeEnum::Frontend);

        $result = $this->manager->createRequestForUser($user);

        self::assertArrayHasKey('selector', $result);
        self::assertArrayHasKey('plainToken', $result);
        self::assertArrayHasKey('expiresAt', $result);
        self::assertNotEmpty($result['selector']);
        self::assertNotEmpty($result['plainToken']);
    }

    public function testValidateTokenAcceptsValidToken(): void
    {
        $user = $this->createUser(UserTypeEnum::Frontend);
        $request = $this->manager->createRequestForUser($user);

        $resolved = $this->manager->validateToken($request['selector'], $request['plainToken'], UserTypeEnum::Frontend);

        self::assertInstanceOf(ResetPasswordRequest::class, $resolved);
        self::assertSame($user->getId(), $resolved->getUser()->getId());
    }

    public function testValidateTokenRejectsWrongToken(): void
    {
        $user = $this->createUser(UserTypeEnum::Frontend);
        $request = $this->manager->createRequestForUser($user);

        self::assertNull($this->manager->validateToken($request['selector'], 'wrong-token'));
    }

    public function testValidateTokenRejectsWrongUserType(): void
    {
        $user = $this->createUser(UserTypeEnum::Frontend);
        $request = $this->manager->createRequestForUser($user);

        self::assertNull($this->manager->validateToken($request['selector'], $request['plainToken'], UserTypeEnum::Suite));
    }

    public function testResetPasswordUpdatesHashAndConsumesRequest(): void
    {
        $user = $this->createUser(UserTypeEnum::Frontend);
        $oldHash = $user->getPassword();
        $request = $this->manager->createRequestForUser($user);
        $resetRequest = $this->manager->validateToken($request['selector'], $request['plainToken'], UserTypeEnum::Frontend);
        self::assertNotNull($resetRequest);

        $this->manager->resetPassword($resetRequest, 'brandnewpassword');

        self::assertNotSame($oldHash, $user->getPassword());
        self::assertNull($this->manager->validateToken($request['selector'], $request['plainToken'], UserTypeEnum::Frontend));
    }

    public function testCreateRequestReplacesPreviousOne(): void
    {
        $user = $this->createUser(UserTypeEnum::Frontend);
        $first = $this->manager->createRequestForUser($user);
        $second = $this->manager->createRequestForUser($user);

        self::assertNull($this->manager->validateToken($first['selector'], $first['plainToken'], UserTypeEnum::Frontend));
        self::assertInstanceOf(ResetPasswordRequest::class, $this->manager->validateToken($second['selector'], $second['plainToken'], UserTypeEnum::Frontend));
    }

    private function createUser(UserTypeEnum $type): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setName('Reset Tester');
        $user->setEmail('reset-'.uniqid().'@aurora.test');
        $user->setType($type);
        $user->setStatus(UserStatusEnum::Active);
        $user->setPassword($hasher->hashPassword($user, 'oldpassword'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
