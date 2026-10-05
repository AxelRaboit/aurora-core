<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Manager;

use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Manager\UserManagerInterface;
use Aurora\Tests\Integration\Concern\CreatesTestUsers;
use Aurora\Tests\Integration\IntegrationTestCase;
use InvalidArgumentException;

final class UserManagerDisabledModulesTest extends IntegrationTestCase
{
    use CreatesTestUsers;

    private UserManagerInterface $userManager;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->userManager = static::getContainer()->get(UserManagerInterface::class);
    }

    public function testUpdateDisabledModulesPersistsKnownEntries(): void
    {
        $user = $this->createTestUser('alice', role: UserRoleEnum::User);

        $this->userManager->updateDisabledModules($user, [
            ModuleParameterEnum::GedSuite->value,
            ModuleParameterEnum::PlatformSuite->value,
        ]);

        self::assertEqualsCanonicalizing(
            [ModuleParameterEnum::GedSuite->value, ModuleParameterEnum::PlatformSuite->value],
            $user->getDisabledModules(),
        );
    }

    public function testUnknownEntriesAreFilteredSilently(): void
    {
        $user = $this->createTestUser('bob', role: UserRoleEnum::User);

        $this->userManager->updateDisabledModules($user, [
            ModuleParameterEnum::GedSuite->value,
            'not_a_real_module',
        ]);

        self::assertSame([ModuleParameterEnum::GedSuite->value], $user->getDisabledModules());
    }

    public function testDuplicatesAreDeduplicated(): void
    {
        $user = $this->createTestUser('carol', role: UserRoleEnum::User);

        $this->userManager->updateDisabledModules($user, [
            ModuleParameterEnum::GedSuite->value,
            ModuleParameterEnum::GedSuite->value,
        ]);

        self::assertSame([ModuleParameterEnum::GedSuite->value], $user->getDisabledModules());
    }

    public function testAdminCannotMaskModulesForDev(): void
    {
        $admin = $this->createTestUser('admin', role: UserRoleEnum::Admin);
        $dev = $this->createTestUser('dev', role: UserRoleEnum::Dev);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('suite.users.errors.cannot_manage_target');

        $this->userManager->updateDisabledModules(
            $dev,
            [ModuleParameterEnum::GedSuite->value],
            $admin,
        );
    }

    public function testDevCanMaskModulesForAdmin(): void
    {
        $admin = $this->createTestUser('admin', role: UserRoleEnum::Admin);
        $dev = $this->createTestUser('dev', role: UserRoleEnum::Dev);

        $this->userManager->updateDisabledModules(
            $admin,
            [ModuleParameterEnum::GedSuite->value],
            $dev,
        );

        self::assertSame([ModuleParameterEnum::GedSuite->value], $admin->getDisabledModules());
    }

    public function testAdminCanMaskModulesForUserOfEqualOrLowerRank(): void
    {
        $admin = $this->createTestUser('admin', role: UserRoleEnum::Admin);
        $regular = $this->createTestUser('regular', role: UserRoleEnum::User);

        $this->userManager->updateDisabledModules(
            $regular,
            [ModuleParameterEnum::GedSuite->value],
            $admin,
        );

        self::assertSame([ModuleParameterEnum::GedSuite->value], $regular->getDisabledModules());
    }

    public function testEmptyListClearsAllOverrides(): void
    {
        $user = $this->createTestUser('dave', role: UserRoleEnum::User);
        $this->userManager->updateDisabledModules($user, [ModuleParameterEnum::GedSuite->value]);

        $this->userManager->updateDisabledModules($user, []);

        self::assertSame([], $user->getDisabledModules());
    }
}
