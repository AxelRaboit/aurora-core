<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Platform\Auth\Service;

use Aurora\Module\Platform\Auth\Service\ImpersonationTokenService;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class ImpersonationTokenServiceTest extends TestCase
{
    private const SECRET = 'test-secret';

    private function makeFrontUser(int $id): User
    {
        $user = new User();
        (new ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        $user->setType(UserTypeEnum::Frontend);

        return $user;
    }

    public function testGenerateProducesThreePartToken(): void
    {
        $userRepository = $this->createStub(UserRepository::class);
        $service = new ImpersonationTokenService(self::SECRET, $userRepository);

        $user = $this->makeFrontUser(42);
        $token = $service->generate($user);

        self::assertCount(3, explode('|', $token));
    }

    public function testValidateReturnsUserForValidToken(): void
    {
        $user = $this->makeFrontUser(42);

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturn($user);

        $service = new ImpersonationTokenService(self::SECRET, $userRepository);

        $token = $service->generate($user);
        $result = $service->validate($token);

        self::assertSame($user, $result);
    }

    public function testValidateReturnsNullForMalformedToken(): void
    {
        $userRepository = $this->createStub(UserRepository::class);
        $service = new ImpersonationTokenService(self::SECRET, $userRepository);

        self::assertNull($service->validate('invalid'));
        self::assertNull($service->validate('a|b'));
        self::assertNull($service->validate('a|b|c|d'));
    }

    public function testValidateReturnsNullForBadSignature(): void
    {
        $userRepository = $this->createStub(UserRepository::class);
        $service = new ImpersonationTokenService(self::SECRET, $userRepository);

        self::assertNull($service->validate('42|'.time().'|tampered-signature'));
    }

    public function testValidateReturnsNullForExpiredToken(): void
    {
        $userRepository = $this->createStub(UserRepository::class);
        $service = new ImpersonationTokenService(self::SECRET, $userRepository);

        $expiredTime = time() - 3600;
        $payload = '42|'.$expiredTime;
        $sig = hash_hmac('sha256', $payload, self::SECRET);

        self::assertNull($service->validate($payload.'|'.$sig));
    }

    public function testValidateReturnsNullWhenUserNotFound(): void
    {
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturn(null);

        $service = new ImpersonationTokenService(self::SECRET, $userRepository);

        $token = $service->generate($this->makeFrontUser(99));

        self::assertNull($service->validate($token));
    }

    public function testValidateReturnsNullForSuiteUser(): void
    {
        $user = new User();
        (new ReflectionProperty(User::class, 'id'))->setValue($user, 42);
        $user->setType(UserTypeEnum::Suite);

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturn($user);

        $service = new ImpersonationTokenService(self::SECRET, $userRepository);
        $token = $service->generate($user);

        self::assertNull($service->validate($token));
    }
}
