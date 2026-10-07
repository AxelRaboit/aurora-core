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
 * A space's shared folder, and who has the right to name it.
 *
 * **Two things nobody ever checks again by hand.** Pasting the whole address
 * straight out of Drive is the natural action, and requiring the bare id
 * would make everyone fail one time out of two; it is a silent courtesy, so
 * a courtesy that breaks without anyone noticing.
 *
 * And naming the folder is configuration: it belongs to the lead. The field
 * used to live above the file list, where any team member could change it;
 * this test is what keeps it from going back there.
 */
final class SpaceDriveFolderTest extends IntegrationTestCase
{
    /** Obviously fake: this repository is public, and a real id would name real infrastructure in it. */
    private const string FOLDER = 'dossier-de-demonstration-aurora';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
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
            sprintf("DELETE FROM %s u WHERE u.email LIKE 'dossier-%%'", User::class)
        )->execute();

        parent::tearDown();
    }

    /** The whole address, as it is copied from Drive. */
    public function testTheWholeFolderAddressIsAccepted(): void
    {
        $space = $this->givenSpace();

        $this->post($space, ['folder' => sprintf('https://drive.google.com/drive/folders/%s?usp=sharing', self::FOLDER)]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(self::FOLDER, $this->reload($space)->getDriveFolderId());
    }

    /** The bare id too, for whoever already has it at hand. */
    public function testTheBareIdentifierIsAccepted(): void
    {
        $space = $this->givenSpace();

        $this->post($space, ['folder' => self::FOLDER]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(self::FOLDER, $this->reload($space)->getDriveFolderId());
    }

    /**
     * And what is neither one nor the other is refused.
     *
     * A readable refusal rather than a saved folder that will never answer: an
     * unexplained empty list gets investigated on Google's side, that is, on
     * the wrong side.
     */
    public function testSomethingThatIsNeitherIsRefused(): void
    {
        $space = $this->givenSpace();

        $this->post($space, ['folder' => 'mon dossier']);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->reload($space)->getDriveFolderId());
    }

    /** Clearing the field disconnects the folder, which is not an error. */
    public function testAnEmptyFieldUnplugsTheFolder(): void
    {
        $space = $this->givenSpace();
        $this->post($space, ['folder' => self::FOLDER]);

        $this->post($space, ['folder' => '']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->reload($space)->getDriveFolderId());
    }

    /** A team member who is not the lead does not name the folder. */
    public function testATeammateCannotChooseTheFolder(): void
    {
        $space = $this->givenSpace();

        $teammate = new User();
        $teammate
            ->setEmail('dossier-equipier@example.test')
            ->setName('Équipier')
            ->setType(UserTypeEnum::Suite)
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
