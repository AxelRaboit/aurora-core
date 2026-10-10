<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Repository\SpaceAccessLinkRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;

use function json_decode;
use function parse_url;
use function preg_match;
use function sprintf;

use const PHP_URL_PATH;

/**
 * The address of a client space, written by the application.
 *
 * It left by copy and paste until 4.12: the link named its recipient and
 * nothing wrote to them. These tests pin the invitation at creation, the
 * resend that revokes nothing, and the language of the customer's sheet.
 */
final class SpaceInvitationTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ([SpaceAccessLink::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testTheInvitationIsSentAtCreationAndOpensTheSpace(): void
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $space), [
            'recipientEmail' => 'camille@societe.test',
            'label' => 'Camille',
            'sendInvitation' => true,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->payload()['invited']);

        $mails = $this->mailerMessages();
        self::assertCount(1, $mails);
        self::assertSame('camille@societe.test', $mails[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('Votre espace', (string) $mails[0]->getSubject());
        // The address sent is the one the screen shows once.
        self::assertStringContainsString($this->payload()['url'], (string) $mails[0]->getHtmlBody());
    }

    public function testNothingIsSentWhenTheBoxIsUnticked(): void
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $space), [
            'recipientEmail' => 'camille@societe.test',
            'label' => 'Camille',
            'sendInvitation' => false,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->payload()['invited']);
        self::assertCount(0, $this->mailerMessages());
    }

    /**
     * A resend later carries an address the application rebuilt, which opens
     * the space, and revokes nothing: the first address keeps working.
     */
    public function testALinkIsSentAgainWithoutClosingTheFirstAddress(): void
    {
        $space = $this->givenSpace();
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $space), [
            'recipientEmail' => 'camille@societe.test',
            'label' => 'Camille',
        ]);
        $first = (string) parse_url($this->payload()['url'], PHP_URL_PATH);
        $linkId = static::getContainer()->get(SpaceAccessLinkRepository::class)->findForSpace($this->entityManager->find(CustomerSpace::class, $space))[0]->getId();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/%d/invite', $space, $linkId));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $mails = $this->mailerMessages();
        self::assertCount(1, $mails);
        self::assertSame(1, preg_match('#(/spaces/[a-f0-9]{32}/[a-f0-9]{64})#', (string) $mails[0]->getHtmlBody(), $matches));
        self::assertNotSame($first, $matches[1], 'Not the long address, which no longer exists in readable form.');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $matches[1]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', $first);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /** The customer's sheet says Spanish: the invitation is written in Spanish. */
    public function testTheInvitationIsWrittenInTheCustomersLanguage(): void
    {
        $space = $this->givenSpace('es');

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $space), [
            'recipientEmail' => 'camille@societe.test',
            'label' => 'Camille',
            'sendInvitation' => true,
        ]);

        $mails = $this->mailerMessages();
        self::assertCount(1, $mails);
        self::assertStringContainsString('Su espacio', (string) $mails[0]->getSubject());
    }

    /** A language the site does not speak is refused rather than silently ignored. */
    public function testAnInactiveLanguageIsRefusedOnTheSheet(): void
    {
        $this->client->jsonRequest('POST', '/suite/studio/customers/create', [
            'legalName' => 'Client polyglotte',
            'status' => 'prospect',
            'locale' => 'xx',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('locale', $this->payload()['errors'] ?? []);
    }

    private function givenSpace(?string $locale = null): int
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Client invité')
            ->setSiret('73282932000074')
            ->setContractualEmail('invite@example.test')
            ->setLocale($locale);

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', [
            'name' => 'Espace invité',
            'customerId' => $customer->getId(),
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return (int) $this->payload()['space']['id'];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return list<Email> */
    private function mailerMessages(): array
    {
        $messages = [];

        foreach ($this->getMailerEvents() as $event) {
            if (!$event->isQueued()) {
                $message = $event->getMessage();
                self::assertInstanceOf(Email::class, $message);
                $messages[] = $message;
            }
        }

        return $messages;
    }
}
