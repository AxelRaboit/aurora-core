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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function sprintf;

/**
 * Le dossier partagé d'un espace, et qui a le droit de le désigner.
 *
 * **Deux choses qu'on ne revérifie jamais à la main.** Coller l'adresse
 * entière sortie de Drive est le geste naturel, et exiger l'identifiant nu
 * ferait échouer tout le monde une fois sur deux ; c'est une politesse
 * silencieuse, donc une politesse qu'on casse sans s'en apercevoir.
 *
 * Et désigner le dossier est une configuration : elle appartient au référent.
 * Le champ a vécu au-dessus de la liste des fichiers, où n'importe quel
 * équipier le changeait ; ce test est ce qui empêche qu'il y retourne.
 */
final class SpaceDriveFolderTest extends IntegrationTestCase
{
    private const string FOLDER = '1bbo9FyKEudNl7oeyPX-R5uX41_cPZLk3';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ([CustomerSpaceMember::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        $this->entityManager->createQuery(
            sprintf("DELETE FROM %s u WHERE u.email LIKE 'dossier-%%'", User::class)
        )->execute();

        parent::tearDown();
    }

    /** L'adresse entière, telle qu'on la copie depuis Drive. */
    public function testTheWholeFolderAddressIsAccepted(): void
    {
        $space = $this->givenSpace();

        $this->post($space, ['folder' => sprintf('https://drive.google.com/drive/folders/%s?usp=sharing', self::FOLDER)]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(self::FOLDER, $this->reload($space)->getDriveFolderId());
    }

    /** L'identifiant nu aussi, pour qui l'a déjà sous la main. */
    public function testTheBareIdentifierIsAccepted(): void
    {
        $space = $this->givenSpace();

        $this->post($space, ['folder' => self::FOLDER]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(self::FOLDER, $this->reload($space)->getDriveFolderId());
    }

    /**
     * Et ce qui n'est ni l'un ni l'autre est refusé.
     *
     * Un refus lisible plutôt qu'un dossier enregistré qui ne répondra jamais :
     * une liste vide inexpliquée se cherche du côté de Google, c'est-à-dire du
     * mauvais côté.
     */
    public function testSomethingThatIsNeitherIsRefused(): void
    {
        $space = $this->givenSpace();

        $this->post($space, ['folder' => 'mon dossier']);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->reload($space)->getDriveFolderId());
    }

    /** Vider le champ débranche le dossier, ce qui n'est pas une erreur. */
    public function testAnEmptyFieldUnplugsTheFolder(): void
    {
        $space = $this->givenSpace();
        $this->post($space, ['folder' => self::FOLDER]);

        $this->post($space, ['folder' => '']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->reload($space)->getDriveFolderId());
    }

    /** Un équipier qui n'est pas référent ne désigne pas le dossier. */
    public function testATeammateCannotChooseTheFolder(): void
    {
        $space = $this->givenSpace();

        $teammate = new User();
        $teammate
            ->setEmail('dossier-equipier@example.test')
            ->setName('Équipier')
            ->setType(UserTypeEnum::Backend)
            ->setRoles([UserRoleEnum::User->value])
            ->setPrivileges(['studio.spaces.view', 'studio.spaces.edit'])
            ->setPassword('x');

        $this->entityManager->persist($teammate);

        $member = new CustomerSpaceMember();
        $member->setSpace($this->reload($space))->setUser($teammate)->setRole(CustomerSpaceMemberRoleEnum::Member);

        $this->entityManager->persist($member);
        $this->entityManager->flush();

        $this->client->loginUser($teammate, 'admin');
        $this->post($space, ['folder' => self::FOLDER]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->reload($space)->getDriveFolderId());
    }

    /** @param array<string, mixed> $payload */
    private function post(CustomerSpace $space, array $payload): void
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/settings/drive-folder', $space->getId()), $payload);
    }

    private function reload(CustomerSpace $space): CustomerSpace
    {
        $fresh = $this->entityManager->getRepository(CustomerSpace::class)->find($space->getId());
        self::assertInstanceOf(CustomerSpace::class, $fresh);
        $this->entityManager->refresh($fresh);

        return $fresh;
    }

    private function givenSpace(): CustomerSpace
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Client du dossier')
            ->setSiret('73282932000074')
            ->setContractualEmail('dossier@example.test');

        $this->entityManager->persist($customer);

        $space = new CustomerSpace();
        $space
            ->setName('Espace du dossier')
            ->setCustomer($customer)
            ->setTimezone('Europe/Paris');

        $this->entityManager->persist($space);
        $this->entityManager->flush();

        return $space;
    }
}
