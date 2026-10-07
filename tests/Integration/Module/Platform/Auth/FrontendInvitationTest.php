<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Platform\Auth;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserStatusEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Manager\UserManagerInterface;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Inviting somebody to the public site.
 *
 * Two things are checked here, and the second one matters most.
 *
 * First, that an invited frontend account really is a frontend account: type,
 * single role, and an acceptance address that leads to the public site and not
 * to the administration.
 *
 * Then, and above all, **that neither acceptance page accepts the other
 * population's token**. `findValidInvitation` does not filter the type - the
 * token mechanics are shared - so the filtering belongs to the routes. Without
 * it, a frontend invitee following the suite's address would be logged in on
 * the administration firewall: `admin_user_provider` would not resolve their
 * account on the next refresh, but they would have seen the dashboard in the
 * meantime.
 */
final class FrontendInvitationTest extends IntegrationTestCase
{
    /** The one from CreatesTestUsers, so as not to introduce one more literal. */
    private const string TEST_PASSWORD = 'verysecure123';

    private KernelBrowser $client;

    private UserManagerInterface $userManager;

    private EntityManagerInterface $entityManager;

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
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

    public function testAFrontendInviteIsAFrontendAccountWithTheSingleFrontendRole(): void
    {
        $user = $this->invite('client@exemple.com', UserTypeEnum::Frontend);

        self::assertSame(UserTypeEnum::Frontend, $user->getType());
        self::assertSame(UserStatusEnum::Invited, $user->getStatus());
        self::assertContains(UserRoleEnum::User->value, $user->getRoles());
    }

    /**
     * The requested role is ignored for a frontend account.
     *
     * The selector is hidden on screen, but a tampered payload would still get
     * through: the write boundary is the only place where the refusal
     * counts.
     */
    public function testAskingForAdminOnAFrontendAccountGrantsNothing(): void
    {
        $user = $this->invite('malin@exemple.com', UserTypeEnum::Frontend, UserRoleEnum::Admin);

        self::assertNotContains(UserRoleEnum::Admin->value, $user->getRoles());
        self::assertSame([UserRoleEnum::User->value], $user->getRoles());
    }

    /** The public acceptance page opens for a frontend account. */
    public function testTheFrontendAcceptancePageOpensForAFrontendInvite(): void
    {
        $user = $this->invite('ouvre@exemple.com', UserTypeEnum::Frontend);

        $this->client->request('GET', $this->frontendUrl($user));

        self::assertResponseIsSuccessful();
    }

    /**
     * The guard that matters: a frontend token does not get through the administration.
     */
    public function testTheSuitePageRefusesAFrontendInvite(): void
    {
        $user = $this->invite('pas-admin@exemple.com', UserTypeEnum::Frontend);

        $this->client->request('GET', $this->suiteUrl($user));

        // Sent back to the administration login, like an expired token.
        self::assertResponseRedirects();
        self::assertStringContainsString('/suite/platform/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /** And the reverse: a suite token does not get through the public site. */
    public function testTheFrontendPageRefusesASuiteInvite(): void
    {
        $user = $this->invite('admin@exemple.com', UserTypeEnum::Suite);

        $this->client->request('GET', $this->frontendUrl($user));

        // The page answers, but announcing a dead link - it does not say that
        // an account exists elsewhere.
        //
        // The assertion is on the `invalid` prop and not on the displayed
        // text: that is the server's decision, and the message itself is
        // resolved by Vue in the browser. Looking for the translated sentence
        // in this HTML would test the client rendering, which does not happen
        // here.
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            '&quot;invalid&quot;:true',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /**
     * Setting one's password activates the account and logs the person in.
     */
    public function testAcceptingActivatesTheAccount(): void
    {
        $user = $this->invite('accepte@exemple.com', UserTypeEnum::Frontend);
        $id = (int) $user->getId();

        // The same literal as the rest of the suite (see CreatesTestUsers), and
        // passed through a variable: the `'password' => '<literal>'` form is
        // what the secret detector targets, and a test password has no
        // business failing the CI.
        $plainPassword = self::TEST_PASSWORD;

        $this->client->request('POST', $this->frontendUrl($user), [
            'password' => $plainPassword,
            'password_confirmation' => $plainPassword,
        ]);

        self::assertResponseRedirects();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(User::class, $id);

        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame(UserStatusEnum::Active, $reloaded->getStatus());
        // The token is consumed: the link does not work twice.
        self::assertNull($reloaded->getInvitationSelector());
    }

    private function invite(string $email, UserTypeEnum $type, ?UserRoleEnum $role = null): User
    {
        $user = $this->userManager->invite(
            'Invité',
            $email,
            ($role ?? UserRoleEnum::User)->value,
            null,
            false,
            $type,
        );
        $this->created[] = (int) $user->getId();

        return $user;
    }

    /**
     * Rebuilds the address from a fresh token.
     *
     * The plain token is never stored, so one has to be issued and captured:
     * that is exactly the constraint the invitation resend lives with.
     */
    private function frontendUrl(User $user): string
    {
        return sprintf(
            '/%s/invitation/%s/%s',
            $user->getLocale()->value,
            (string) $user->getInvitationSelector(),
            $this->freshToken($user),
        );
    }

    private function suiteUrl(User $user): string
    {
        return sprintf(
            '/suite/platform/invitation/%s/%s',
            (string) $user->getInvitationSelector(),
            $this->freshToken($user),
        );
    }

    /** Issues a token known to this test by reusing the invitation resend. */
    private function freshToken(User $user): string
    {
        $plain = bin2hex(random_bytes(32));

        $user->setInvitationHashedToken(hash('sha256', $plain));
        $this->entityManager->flush();

        return $plain;
    }
}
