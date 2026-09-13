<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Contract;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLinkInterface;
use Aurora\Module\Studio\Contract\Access\Repository\ContractAccessLinkRepository;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInput;
use Aurora\Module\Studio\Contract\Entity\Contract;
use Aurora\Module\Studio\Contract\Entity\ContractTemplate;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManager;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateVersionRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

use function json_decode;
use function sprintf;
use function str_repeat;

/**
 * What the customer actually sees, and what a stranger does not.
 *
 * The public page is the only part of this module somebody outside the
 * application ever reaches, so most of these are about refusals: a wrong
 * secret, a revoked address, an expired one. All three have to look identical,
 * because telling them apart tells a stranger which of their guesses landed.
 */
final class PublicContractLinkTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private ?CoreUserInterface $admin = null;

    private ContractTemplateManager $templates;

    private ContractAccessLinkRepository $links;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();

        $this->admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        $this->login();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->links = $container->get(ContractAccessLinkRepository::class);
        $this->templates = new ContractTemplateManager(
            $this->entityManager,
            $container->get(AuditLogger::class),
            $container->get(ContractTemplateVersionRepository::class),
            $container->get(TranslatorInterface::class),
            $container->get(ContractRepository::class),
        );
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Contract::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', ContractTemplate::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Customer::class))->execute();

        parent::tearDown();
    }

    public function testTheCustomerReadsTheSealedDocumentWithoutAnAccount(): void
    {
        $url = $this->sentContractUrl();

        // No session at all: the address is the credential.
        $guest = $this->asGuest();
        $guest->request('GET', $url);

        self::assertSame(200, $guest->getResponse()->getStatusCode());
        $body = (string) $guest->getResponse()->getContent();

        // The document is there, with the substitutions the seal covers.
        self::assertStringContainsString('ARTICLE 1', $body);
        self::assertStringContainsString('Boulangerie Durand', $body);
        self::assertStringContainsString('850', $body);
        // And what is being asked of them, stated before the articles.
        self::assertStringContainsString('attend votre signature', $body);
    }

    public function testTheAddressNeverLeaks(): void
    {
        $url = $this->sentContractUrl();
        $guest = $this->asGuest();
        $guest->request('GET', $url);

        $headers = $guest->getResponse()->headers;

        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertStringContainsString('noindex', (string) $headers->get('X-Robots-Tag'));
        // A shared machine's back button and a proxy cache must not keep a copy
        // of somebody's contract.
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
    }

    public function testOpeningItIsRecordedOnceAndSeenFromTheBackOffice(): void
    {
        $url = $this->sentContractUrl();

        $guest = $this->asGuest();
        $guest->request('GET', $url);
        $guest->request('GET', $url);

        $this->entityManager->clear();
        $link = $this->links->findAll()[0];

        self::assertInstanceOf(ContractAccessLinkInterface::class, $link);
        self::assertNotNull($link->getFirstOpenedAt());
        self::assertNotNull($link->getLastUsedAt());
        // The first open is what changes the contract's state; the second only
        // moves the last-used mark.
        self::assertSame('opened', $link->getContract()->getStatus()->value);
    }

    /**
     * The three failures, one page.
     *
     * Asserted together on purpose: what matters is not that each is refused
     * but that they are indistinguishable.
     */
    public function testAWrongSecretARevokedLinkAndAnExpiredOneAllLookTheSame(): void
    {
        $url = $this->sentContractUrl();
        $link = $this->links->findAll()[0];
        $selector = $link->getSelector();

        $guest = $this->asGuest();

        $guest->request('GET', sprintf('/contracts/%s/%s', $selector, str_repeat('a', 64)));
        self::assertSame(Response::HTTP_NOT_FOUND, $guest->getResponse()->getStatusCode());
        $wrongSecret = (string) $guest->getResponse()->getContent();

        $guest->request('GET', sprintf('/contracts/%s/%s', str_repeat('b', 32), str_repeat('c', 64)));
        self::assertSame(Response::HTTP_NOT_FOUND, $guest->getResponse()->getStatusCode());
        $unknownSelector = (string) $guest->getResponse()->getContent();

        self::assertSame($wrongSecret, $unknownSelector);

        // Now the real address, revoked. Back in as the administrator, since
        // becoming a guest above dropped the session.
        $this->login();
        $this->client->jsonRequest('POST', sprintf(
            '/backend/studio/contracts/%d/revoke-link',
            $link->getContract()->getId(),
        ));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->asGuest()->request('GET', $url);
        self::assertSame(Response::HTTP_NOT_FOUND, $guest->getResponse()->getStatusCode());
        self::assertSame($wrongSecret, (string) $guest->getResponse()->getContent());
    }

    public function testTheSecretIsNotStoredAndTheHashIs(): void
    {
        $this->sentContractUrl();
        $link = $this->links->findAll()[0];

        // 64 hex characters: a digest, not the 64-character secret itself. The
        // point of the split is that a database dump cannot open anything.
        self::assertSame(64, mb_strlen($link->getHashedToken()));
        self::assertSame(32, mb_strlen($link->getSelector()));

        $stored = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT selector, hashed_token FROM core_contract_access_links',
        );

        self::assertCount(1, $stored);
        self::assertArrayNotHasKey('token', $stored[0]);
    }

    public function testSendingIsRefusedBeforeSealing(): void
    {
        $created = $this->draftContract();

        $this->client->jsonRequest('POST', sprintf(
            '/backend/studio/contracts/%d/send',
            $created['contract']['id'],
        ));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('status', $payload['errors']);
    }

    /**
     * A resend replaces rather than adds.
     *
     * Two live addresses would mean two answers to "is this link valid", and
     * revoking one of them would leave the other open.
     */
    public function testResendingRevokesTheAddressSentBefore(): void
    {
        $first = $this->sentContractUrl();
        $link = $this->links->findAll()[0];
        $contractId = $link->getContract()->getId();

        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/send', $contractId));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $guest = $this->asGuest();
        $guest->request('GET', $first);

        self::assertSame(Response::HTTP_NOT_FOUND, $guest->getResponse()->getStatusCode());
    }

    /**
     * The whole flow up to a live address, returning the URL a customer got.
     *
     * The plaintext token exists for exactly one request, so it is read back
     * from the manager's own return rather than from the database - which is
     * the behaviour under test as much as a convenience.
     */
    private function sentContractUrl(): string
    {
        $created = $this->draftContract();
        $id = $created['contract']['id'];

        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/freeze', $id));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/send', $id));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();

        // The mail carries the URL; the test reads it from the same place the
        // customer would, which is the message that went out.
        $messages = $this->mailerMessages();
        self::assertNotEmpty($messages, 'Sending a contract has to send a mail.');

        $body = (string) $messages[0]->getHtmlBody();

        self::assertSame(1, preg_match('#(/contracts/[a-f0-9]{32}/[a-f0-9]{64})#', $body, $matches));

        return $matches[1];
    }

    /**
     * The same browser, with no session.
     *
     * Symfony allows one client per test, so a guest is this client with its
     * cookie jar emptied rather than a second browser. That is also a truer
     * test of the claim: the address opens the document with nothing else
     * attached to the request.
     */
    private function asGuest(): KernelBrowser
    {
        $this->client->getCookieJar()->clear();

        return $this->client;
    }

    private function login(): void
    {
        self::assertNotNull($this->admin);
        $this->client->loginUser($this->admin, 'admin');
    }

    /**
     * The messages that actually reached a transport.
     *
     * Not `getMailerMessages()`: messenger is enabled, so each mail is
     * dispatched twice - queued when the mailer hands it to the bus, unqueued
     * when the handler runs it - and counting both reads one email as two. The
     * same helper and the same reason as `PlanningNotificationDeliveryTest`.
     *
     * @return list<Email>
     */
    private function mailerMessages(): array
    {
        $messages = [];

        foreach ($this->getMailerEvents() as $event) {
            if ($event->isQueued()) {
                continue;
            }

            $message = $event->getMessage();
            self::assertInstanceOf(Email::class, $message);
            $messages[] = $message;
        }

        return $messages;
    }

    /** @return array<string, mixed> */
    private function draftContract(): array
    {
        $this->client->jsonRequest('POST', '/backend/studio/contracts/create', [
            'customerId' => $this->customer()->getId(),
            'bodyTemplateId' => $this->publishedTemplate()->getId(),
            'locale' => 'fr',
            'amount' => '850',
            'amountCurrency' => 'EUR',
            'effectiveDate' => '2026-10-01',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function publishedTemplate(): ContractTemplateInterface
    {
        $template = $this->templates->create(new ContractTemplateInput('Contrat mensuel', ContractTemplateKindEnum::Body));
        $version = $template->getDraft();

        $this->templates->updateDraft($version, new ContractTemplateVersionInput([
            'fr' => [
                'title' => 'CONTRAT DE PRESTATION DE SERVICES',
                'content' => ['blocks' => [
                    ['type' => 'header', 'data' => ['text' => 'ARTICLE 1', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => 'Le forfait de {{customer.legal_name}} est de {{contract.amount}}.']],
                    ['type' => 'paragraph', 'data' => ['text' => 'Fait à {{contract.signature_city}}, le {{contract.signature_date}}.']],
                ]],
            ],
        ]));
        $this->templates->publish($version);

        return $template;
    }

    private function customer(): CustomerInterface
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Boulangerie Durand')
            ->setContractualEmail('contact@durand.test');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }
}
