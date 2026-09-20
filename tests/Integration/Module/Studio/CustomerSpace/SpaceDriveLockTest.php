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

/**
 * La serrure de l'onglet Drive.
 *
 * **Trois règles, et chacune est une décision qu'on pourrait défaire sans
 * s'en apercevoir.** Elle vaut pour tout le monde, y compris le rôle qui
 * court-circuite tous les privilèges ; la retirer demande de la saisir ; et
 * configurer n'est pas ouvrir, donc un équipier qui n'est pas référent peut
 * déverrouiller sans pouvoir toucher au mot de passe.
 *
 * S'y ajoute celle qu'un écran ne montre pas : **poser le mot de passe ferme
 * aussitôt, pour celui qui le pose**. Garder sa session ouverte était plus
 * confortable et se lisait comme un réglage qui n'avait pas pris.
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
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
        // Le navigateur pose cet en-tête sur chaque appel, et les routes
        // publiques l'exigent : ce qui les protège est un secret dans
        // l'adresse, et une adresse se transfère.
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

    /** Un mot de passe trop court n'est pas un mot de passe. */
    public function testAShortPasswordIsRefusedAndClosesNothing(): void
    {
        $space = $this->givenSpace();

        $this->post($space, '/drive-password', ['password' => 'court']);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->reload($space)->isDriveLocked());
    }

    /**
     * Poser le mot de passe ferme tout de suite, y compris pour soi.
     *
     * C'est le revirement qui compte : une serrure qu'on pose et qui ne change
     * rien à l'écran ressemble à un réglage qui n'a pas pris.
     */
    public function testSettingThePasswordClosesTheTabForTheOneWhoSetIt(): void
    {
        $space = $this->givenSpace();

        $this->post($space, '/drive-password', ['password' => self::PASSWORD]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertFalse($this->driveOpen($space), "l'onglet reste ouvert");
    }

    /** Le retirer demande de le saisir, sinon ce n'est qu'un ralentisseur. */
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

    /** Le changer aussi : un écran laissé ouvert ne doit pas suffire. */
    public function testChangingThePasswordRequiresTheOldOne(): void
    {
        $space = $this->givenSpace();
        $this->post($space, '/drive-password', ['password' => self::PASSWORD]);

        $this->post($space, '/drive-password', ['password' => 'unautremotdepasse', 'currentPassword' => 'faux']);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Configurer n'est pas ouvrir.
     *
     * Un équipier membre mais pas référent ne voit pas les réglages, et peut
     * malgré tout ouvrir l'onglet s'il connaît le mot de passe. C'est
     * exactement ce qu'un mot de passe veut dire.
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
     * Le référent, lui, configure.
     *
     * Sans ce test, le précédent passerait aussi si les réglages étaient
     * fermés à tout le monde.
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
     * Redemander le mot de passe referme les sessions déjà ouvertes.
     *
     * **La garantie qu'aucune session ne peut constater seule.** Celui qui
     * appuie est refermé, ce qui se voit ; les autres le sont aussi, ce qui ne
     * se voit qu'en tenant deux sessions à la fois. Sans ce test, le bouton
     * pourrait ne refermer que la sienne et personne ne s'en apercevrait.
     */
    public function testRevokingClosesSessionsThatWereAlreadyOpen(): void
    {
        $space = $this->givenSpace();
        $this->post($space, '/drive-password', ['password' => self::PASSWORD]);

        $this->post($space, '/drive-unlock', ['password' => self::PASSWORD]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->driveOpen($space), "la session n'était pas ouverte");

        $premiere = $this->currentSession();

        // Une autre session, qui n'a jamais eu à saisir quoi que ce soit.
        $this->openAnotherSession();
        $this->post($space, '/drive-revoke', []);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->backTo($premiere);
        self::assertFalse($this->driveOpen($space), 'la première session est restée ouverte');

        // Et le mot de passe n'a pas bougé : c'est tout l'intérêt du bouton.
        $this->post($space, '/drive-unlock', ['password' => self::PASSWORD]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'le mot de passe a changé');
    }

    /**
     * Changer le mot de passe referme aussi les sessions ouvertes avec
     * l'ancien, sans quoi remplacer un mot de passe éventé ne servirait à rien.
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

    /** Sur un onglet ouvert, il n'y a rien à redemander. */
    public function testRevokingAnOpenTabIsRefused(): void
    {
        $space = $this->givenSpace();

        $this->post($space, '/drive-revoke', []);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Le secours, quand le mot de passe est perdu.
     *
     * **La seule porte de sortie d'un oubli**, et celle qu'on n'essaie
     * jamais : quand on en a besoin, un client attend, et découvrir à ce
     * moment-là qu'elle ne marche pas ne laisse plus aucune issue. Elle
     * referme aussi les sessions en cours, sans quoi effacer un mot de passe
     * depuis le serveur laisserait dedans ceux qui y étaient.
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

    /** Sur un espace qui n\'existe pas, elle le dit plutôt que de ne rien faire. */
    public function testTheRescueCommandFailsOnAnUnknownSpace(): void
    {
        $command = new CommandTester(
            (new Application(static::$kernel))->find('aurora:space:drive-password:clear')
        );
        $command->execute(['space' => '999999']);

        self::assertSame(Command::FAILURE, $command->getStatusCode());
    }

    /**
     * Deux sessions avec un seul client.
     *
     * Un second `createClient()` redémarre le noyau, ce que ce cas de test
     * interdit ; le pot à biscuits, lui, est la session. Le vider et se
     * reconnecter en ouvre une autre, et remettre les biscuits de côté rend
     * la première.
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
     * L'onglet Drive, tel que l'écran l'interroge.
     *
     * **La liste et non l'archive.** L'archive répond 404 dès que
     * l'intégration Google n'est pas branchée, ce qui est le cas en test :
     * l'interroger donnerait un test vert quoi qu'il arrive, y compris sur
     * une serrure qui ne ferme rien. La liste, elle, est la seule route que
     * la serrure laisse passer, justement pour qu'elle annonce `locked`.
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
            ->setType(UserTypeEnum::Backend)
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
