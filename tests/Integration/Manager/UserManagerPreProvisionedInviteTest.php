<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Manager;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserStatusEnum;
use Aurora\Module\Platform\User\Manager\UserManagerInterface;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Create an account for someone arriving later.
 *
 * What is checked here is a state machine, not a field: a pre-provisioned
 * account and an account disabled after being used carry the **same** status
 * `Disabled`, and `invitedAt` is what tells them apart. Opening it therefore
 * does not do the same thing in both cases - in the first the invitation must
 * be sent, in the second it must definitely not.
 *
 * Without these tests, the confusion would be silent: the account would go
 * `Active` with a random password nobody knows, so usable by nobody while
 * looking open in the list.
 */
final class UserManagerPreProvisionedInviteTest extends IntegrationTestCase
{
    /** The one from CreatesTestUsers, so as not to introduce one more literal. */
    private const string TEST_PASSWORD = 'verysecure123';

    private UserManagerInterface $userManager;

    private EntityManagerInterface $entityManager;

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->userManager = static::getContainer()->get(UserManagerInterface::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $user = $this->entityManager->find(User::class, $id);
            if (null !== $user) {
                $this->entityManager->remove($user);
            }
        }
        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    /** The ordinary case, to have the point of comparison. */
    public function testAnOrdinaryInviteIsInvitedAndStamped(): void
    {
        $user = $this->invite('ordinaire@exemple.com');

        self::assertSame(UserStatusEnum::Invited, $user->getStatus());
        self::assertInstanceOf(DateTimeImmutable::class, $user->getInvitedAt());
        self::assertNotNull($user->getInvitationSelector());
    }

    /**
     * The core: nothing is issued, nothing is sent, and `invitedAt` stays null.
     *
     * That null is the only thing that will later say "nobody was ever
     * contacted". A token issued here would expire in 48 hours for nothing.
     */
    public function testAPreProvisionedAccountEmitsNothing(): void
    {
        $user = $this->invite('plus-tard@exemple.com', disabled: true);

        self::assertSame(UserStatusEnum::Disabled, $user->getStatus());
        self::assertNull($user->getInvitedAt());
        self::assertNull($user->getInvitationSelector());
        self::assertNull($user->getInvitationHashedToken());
    }

    /**
     * Opening it sends the invitation and moves it to `Invited`, not `Active`.
     *
     * `Active` would be the bug: its password is a random value nobody knows,
     * so the account would look open without anyone being able to use it.
     */
    public function testOpeningAPreProvisionedAccountSendsItsInvitation(): void
    {
        $user = $this->invite('ouverture@exemple.com', disabled: true);

        $opened = $this->userManager->toggleDisabled($user);

        self::assertTrue($opened);
        self::assertSame(UserStatusEnum::Invited, $user->getStatus());
        self::assertInstanceOf(DateTimeImmutable::class, $user->getInvitedAt());
        self::assertNotNull($user->getInvitationSelector());
    }

    /**
     * An account that has already been used gets its access back, and nothing
     * is sent again.
     *
     * It is the other half of the distinction: here `invitedAt` is set, so the
     * toggle must give access back without issuing a new token - otherwise
     * disabling then reactivating someone would send them a baffling
     * invitation email and invalidate the password they use.
     */
    public function testReopeningAnAccountThatHasServedDoesNotReInvite(): void
    {
        $user = $this->invite('deja-servi@exemple.com');
        $this->userManager->consumeInvitation($user, self::TEST_PASSWORD);

        self::assertSame(UserStatusEnum::Active, $user->getStatus());
        $invitedAt = $user->getInvitedAt();

        self::assertFalse($this->userManager->toggleDisabled($user));
        self::assertSame(UserStatusEnum::Disabled, $user->getStatus());

        self::assertTrue($this->userManager->toggleDisabled($user));
        self::assertSame(UserStatusEnum::Active, $user->getStatus());
        // No new token: the current password stays the right one.
        self::assertNull($user->getInvitationSelector());
        self::assertEquals($invitedAt, $user->getInvitedAt());
    }

    /** Closing a pre-provisioned account just opened sends it back to `Disabled`. */
    public function testItCanBeClosedAgainAfterBeingOpened(): void
    {
        $user = $this->invite('refermer@exemple.com', disabled: true);
        $this->userManager->toggleDisabled($user);

        self::assertFalse($this->userManager->toggleDisabled($user));
        self::assertSame(UserStatusEnum::Disabled, $user->getStatus());
        // It has been contacted: reopening it will not invite it again.
        self::assertInstanceOf(DateTimeImmutable::class, $user->getInvitedAt());
    }

    private function invite(string $email, bool $disabled = false): User
    {
        $user = $this->userManager->invite('Comptable', $email, UserRoleEnum::User->value, null, $disabled);
        $this->created[] = (int) $user->getId();

        return $user;
    }
}
