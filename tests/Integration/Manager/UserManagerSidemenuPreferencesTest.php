<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Manager;

use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Manager\UserManagerInterface;
use Aurora\Tests\Integration\Concern\CreatesTestUsers;
use Aurora\Tests\Integration\IntegrationTestCase;

final class UserManagerSidemenuPreferencesTest extends IntegrationTestCase
{
    use CreatesTestUsers;

    private UserManagerInterface $userManager;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->userManager = static::getContainer()->get(UserManagerInterface::class);
    }

    public function testDefaultsToEmptyArrays(): void
    {
        $user = $this->createTestUser('default', role: UserRoleEnum::User);

        self::assertSame([], $user->getHiddenNavSections());
        self::assertSame([], $user->getHiddenNavItems());
    }

    public function testUnknownTokensAreFilteredSilently(): void
    {
        $user = $this->createTestUser('filter', role: UserRoleEnum::User);

        $this->userManager->updateSidemenuPreferences(
            $user,
            ['not_a_real_section', 'another_bogus_section'],
            ['not_a_real_route'],
        );

        self::assertSame([], $user->getHiddenNavSections());
        self::assertSame([], $user->getHiddenNavItems());
    }

    public function testResetClearsAllPreferences(): void
    {
        $user = $this->createTestUser('reset', role: UserRoleEnum::User);

        // Seed directly via the entity setter so we don't depend on which
        // section/route ids are exposed by the running module set in tests.
        $user->setHiddenNavSections(['crm', 'billing']);
        $user->setHiddenNavItems(['suite_crm_contacts']);

        $this->userManager->resetSidemenuPreferences($user);

        self::assertSame([], $user->getHiddenNavSections());
        self::assertSame([], $user->getHiddenNavItems());
    }

    public function testDuplicatesAreDeduplicatedByEntitySetter(): void
    {
        $user = $this->createTestUser('dedup', role: UserRoleEnum::User);

        $user->setHiddenNavSections(['crm', 'crm', 'billing']);
        $user->setHiddenNavItems(['suite_route_a', 'suite_route_a']);

        self::assertSame(['crm', 'billing'], $user->getHiddenNavSections());
        self::assertSame(['suite_route_a'], $user->getHiddenNavItems());
    }

    public function testEntitySettersAlwaysReturnListShapes(): void
    {
        $user = $this->createTestUser('shape', role: UserRoleEnum::User);

        $user->setHiddenNavSections([5 => 'crm', 10 => 'billing']);

        // array_values()-wrapped : guarantees 0-indexed list semantics for JSON column.
        self::assertSame(['crm', 'billing'], $user->getHiddenNavSections());
    }
}
