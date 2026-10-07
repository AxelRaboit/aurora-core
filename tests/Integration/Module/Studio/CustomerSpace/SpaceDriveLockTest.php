<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function json_decode;
use function sprintf;
use function str_repeat;

/**
 * The Drive tab's lock.
 *
 * **Three rules, and each one is a decision that could be undone without
 * anyone noticing.** It applies to everyone, including the role that
 * bypasses every privilege; removing it requires typing it; and configuring
 * is not opening, so a team member who is not the lead can unlock without
 * being able to touch the password.
 *
 * Add to that the one a screen does not show: **setting the password locks
 * at once, for whoever sets it**. Keeping their session open was more
 * comfortable and read like a setting that had not taken.
 */
final class SpaceDriveLockTest extends IntegrationTestCase
{
    private const string PASSWORD = 'motdepasse42';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
        // The browser sets this header on every call, and public routes
        // require it: what protects them is a secret in the address, and an
        // address can be forwarded.
        $this->client->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');
    }

    protected function tearDown(): void
    {
        foreach ([CustomerSpaceMember::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        $this->entityManager->createQuery(
            sprintf("DELETE FROM %s u WHERE u.email LIKE 'serrure-%%'", User::class)
        )->execute();

        parent::tearDown();
    }

    /** A password that is too short is not a password. */
    public function testAShortPasswordIsRefusedAndClosesNothing(): void
    {
        $space = $this->givenSpace();

        $this->post($space, '/drive-password', ['password' => 'court']);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->reload($space)->isDriveLocked());
    }

    /**
     * Setting the password locks right away, including for oneself.
     *
     * That reversal is what matters: a lock that is set and changes nothing on
     * screen looks like a setting that has not taken.
     */
    public function testSettingThePasswordClosesTheTabForTheOneWhoSetIt(): void
    {
        $space = $this->givenSpace();

        $this->post($space, '/drive-password', ['password' => self::PASSWORD]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertFalse($this->driveOpen($space), "l'onglet reste ouvert");
    }

    /**
     * Ten tries per quarter of an hour, then nothing, not even the right one:
     * a password that can be tried endlessly is not one.
     */
    public function testGuessingThePasswordIsCutShort(): void
    {
        $space = $this->givenSpace();
        $this->post($space, '/drive-password', ['password' => self::PASSWORD]);

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $guess = str_repeat((string) $attempt, 3);
            $this->post($space, '/drive-unlock', ['password' => $guess]);
            self::assertNotSame(429, $this->client->getResponse()->getStatusCode(), sprintf('attempt %d', $attempt));
        }

        $this->post($space, '/drive-unlock', ['password' => self::PASSWORD]);
        self::assertSame(429, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->driveOpen($space));
    }

    /** Removing it requires typing it, otherwise it is only a speed bump. */
    public function testRemovingThePasswordRequiresTypingIt(): void
    {
        $space = $this->givenSpace();
        $this->post($space, '/drive-password', ['password' => self::PASSWORD]);

        $this->post($space, '/drive-password/clear', []);
        self::assertSame(400, $this->client->getResponse()->getStatusCode(), 'retiré sans rien saisir');

        $this->post($space, '/drive-password/clear', ['currentPassword' => 'faux']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode(), 'retiré avec un mauvais');

        $this->post($space, '/drive-password/clear', ['currentPassword' => self::PASSWORD]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->reload($space)->isDriveLocked());
    }

    /** Changing it too: a screen left open must not be enough. */
    public function testChangingThePasswordRequiresTheOldOne(): void
    {
        $space = $this->givenSpace();
        $this->post($space, '/drive-password', ['password' => self::PASSWORD]);

        $this->post($space, '/drive-password', ['password' => 'unautremotdepasse', 'currentPassword' => 'faux']);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Configuring is not opening.
     *
     * A team member who is a member but not the lead does not see the
     * settings, and can still open the tab if they know the password. That is
     * exactly what a password means.
     */
    public function testATeammateCannotConfigureButCanUnlock(): void
    {
        $space = $this->givenSpace();
        $this->post($space, '/drive-password', ['password' => self::PASSWORD]);

        $teammate = $this->givenTeammate();
        $this->givenMembership($space, $teammate, CustomerSpaceMemberRoleEnum::Member);
        $this->client->loginUser($teammate, 'admin');

        $this->client->request('GET', sprintf('/workspace/%d/settings', $space->getId()));
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'les réglages lui sont ouverts');

        $this->post($space, '/drive-unlock', ['password' => 'faux']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->post($space, '/drive-unlock', ['password' => self::PASSWORD]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The lead, on the other hand, configures.
     *
     * Without this test, the previous one would also pass if the settings
     * were closed to everyone.
     */
    public function testTheLeadCanOpenTheSettings(): void
    {
        $space = $this->givenSpace();

        $lead = $this->givenTeammate('serrure-referent@example.test');
        $this->givenMembership($space, $lead, CustomerSpaceMemberRoleEnum::Lead);
        $this->client->loginUser($lead, 'admin');

        $this->client->request('GET', sprintf('/workspace/%d/settings', $space->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Asking for the password again locks the sessions already open.
     *
     * **The guarantee no single session can observe on its own.** Whoever
     * presses it is locked out, which can be seen; the others are too, which
     * can only be seen by holding two sessions at once. Without this test,
     * the button could lock only its own session and nobody would notice.
     */
    public function testRevokingClosesSessionsThatWereAlreadyOpen(): void
    {
        $space = $this->givenSpace();
        $this->post($space, '/drive-password', ['password' => self::PASSWORD]);

        $this->post($space, '/drive-unlock', ['password' => self::PASSWORD]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->driveOpen($space), "la session n'était pas ouverte");

        $premiere = $this->currentSession();

        // Another session, which never had to type anything.
        $this->openAnotherSession();
        $this->post($space, '/drive-revoke', []);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->backTo($premiere);
        self::assertFalse($this->driveOpen($space), 'la première session est restée ouverte');

        // And the password has not changed: that is the whole point of the button.
        $this->post($space, '/drive-unlock', ['password' => self::PASSWORD]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'le mot de passe a changé');
    }

    /**
     * Changing the password also locks the sessions opened with the old one,
     * otherwise replacing a leaked password would be pointless.
     */
    public function testChangingThePasswordEvictsTheSessionsOpenedWithTheOldOne(): void
    {
        $space = $this->givenSpace();
        $this->post($space, '/drive-password', ['password' => self::PASSWORD]);

        $this->post($space, '/drive-unlock', ['password' => self::PASSWORD]);
        self::assertTrue($this->driveOpen($space));

        $ouverte = $this->currentSession();

        $this->openAnotherSession();
        $this->post($space, '/drive-password', ['password' => 'unautremotdepasse', 'currentPassword' => self::PASSWORD]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->backTo($ouverte);
        self::assertFalse($this->driveOpen($space));
    }

    /** On an open tab, there is nothing to ask for again. */
    public function testRevokingAnOpenTabIsRefused(): void
    {
        $space = $this->givenSpace();

        $this->post($space, '/drive-revoke', []);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The rescue, when the password is lost.
     *
     * **The only way out of a forgotten password**, and the one nobody ever
     * tries: when it is needed, a client is waiting, and finding out at that
     * moment that it does not work leaves no way out at all. It also locks the
     * current sessions, otherwise clearing a password from the server would
     * leave inside whoever was already there.
     */
    public function testTheRescueCommandReopensTheTabAndClosesTheSessions(): void
    {
        $space = $this->givenSpace();
        $this->post($space, '/drive-password', ['password' => self::PASSWORD]);
        $this->post($space, '/drive-unlock', ['password' => self::PASSWORD]);
        self::assertTrue($this->driveOpen($space));

        $generation = $this->reload($space)->getDriveLockGeneration();

        $command = new CommandTester(
            (new Application(static::$kernel))->find('aurora:space:drive-password:clear')
        );
        $command->execute(['space' => (string) $space->getId()]);

        self::assertSame(Command::SUCCESS, $command->getStatusCode());

        $fresh = $this->reload($space);
        self::assertFalse($fresh->isDriveLocked());
        self::assertNotSame($generation, $fresh->getDriveLockGeneration(), 'la génération n\'a pas bougé');
    }

    /** On a space that does not exist, it says so rather than doing nothing. */
    public function testTheRescueCommandFailsOnAnUnknownSpace(): void
    {
        $command = new CommandTester(
            (new Application(static::$kernel))->find('aurora:space:drive-password:clear')
        );
        $command->execute(['space' => '999999']);

        self::assertSame(Command::FAILURE, $command->getStatusCode());
    }

    /**
     * Two sessions with a single client.
     *
     * A second `createClient()` reboots the kernel, which this test case
     * forbids; the cookie jar, on the other hand, is the session. Emptying it
     * and logging in again opens another one, and putting the cookies back
     * restores the first.
     */
    private function currentSession(): array
    {
        return $this->client->getCookieJar()->all();
    }

    private function openAnotherSession(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($this->admin, 'admin');
    }

    /** @param array<int, Cookie> $cookies */
    private function backTo(array $cookies): void
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();

        foreach ($cookies as $cookie) {
            $jar->set($cookie);
        }
    }

    /**
     * The Drive tab, as the screen queries it.
     *
     * **The listing and not the archive.** The archive answers 404 as soon as
     * the Google integration is not connected, which is the case in tests:
     * querying it would give a green test whatever happens, including on a
     * lock that locks nothing. The listing is the only route the lock lets
     * through, precisely so that it can announce `locked`.
     */
    private function driveOpen(CustomerSpace $space): bool
    {
        $this->client->request('GET', sprintf('/workspace/%d/drive', $space->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        return true !== ($payload['locked'] ?? false);
    }

    private function post(CustomerSpace $space, string $suffix, array $payload): void
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/settings%s', $space->getId(), $suffix), $payload);
    }

    private function reload(CustomerSpace $space): CustomerSpace
    {
        $fresh = $this->entityManager->getRepository(CustomerSpace::class)->find($space->getId());
        self::assertInstanceOf(CustomerSpace::class, $fresh);
        $this->entityManager->refresh($fresh);

        return $fresh;
    }

    private function givenTeammate(string $email = 'serrure-equipier@example.test'): User
    {
        $user = new User();
        $user
            ->setEmail($email)
            ->setName('Équipier')
            ->setType(UserTypeEnum::Suite)
            ->setRoles([UserRoleEnum::User->value])
            ->setPrivileges(['studio.spaces.view', 'studio.spaces.edit'])
            ->setPassword('x');

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function givenMembership(CustomerSpace $space, User $user, CustomerSpaceMemberRoleEnum $role): void
    {
        $member = new CustomerSpaceMember();
        $member->setSpace($this->reload($space))->setUser($user)->setRole($role);

        $this->entityManager->persist($member);
        $this->entityManager->flush();
    }

    private function givenSpace(): CustomerSpace
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Client de la serrure')
            ->setSiret('73282932000074')
            ->setContractualEmail('serrure@example.test');

        $this->entityManager->persist($customer);

        $space = new CustomerSpace();
        $space
            ->setName('Espace de la serrure')
            ->setCustomer($customer)
            ->setTimezone('Europe/Paris')
            ->setDriveFolderId('un-dossier-partage');

        $this->entityManager->persist($space);
        $this->entityManager->flush();

        return $space;
    }
}
