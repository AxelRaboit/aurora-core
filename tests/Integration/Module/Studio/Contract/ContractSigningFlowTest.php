<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Contract;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Repository\UserRepository;
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
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignature;
use Aurora\Module\Studio\Contract\Signature\Enum\ContractSignatureRoleEnum;
use Aurora\Module\Studio\Contract\Signature\Repository\ContractSignatureChallengeRepository;
use Aurora\Module\Studio\Contract\Signature\Repository\ContractSignatureRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

use function json_decode;
use function preg_match;
use function sprintf;

/**
 * The whole thing, from a link in a mailbox to a concluded contract.
 *
 * One test walks the flow end to end because that is the only way to know the
 * pieces fit; the rest are the refusals that make it safe. The order under test
 * is the one the module reversed on purpose: the customer signs first, and the
 * provider's countersignature concludes.
 */
final class ContractSigningFlowTest extends IntegrationTestCase
{
    private const string PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC';

    private KernelBrowser $client;

    private ?CoreUserInterface $admin = null;

    private ContractTemplateManager $templates;

    private ContractAccessLinkRepository $links;

    private ContractSignatureRepository $signatures;

    private ContractSignatureChallengeRepository $challenges;

    private ContractRepository $contracts;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();

        $this->admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        $this->login();

        // The signing endpoints are rate limited by IP, and every test in this
        // class comes from the same one. The limiter state lives in a shared
        // cache pool that outlives a test, so a class exercising the flow
        // several times trips a limit that is doing exactly its job. Cleared
        // here rather than raised in config: the limit is deliberate, and this
        // class is not what tests it.
        $container->get('cache.rate_limiter')->clear();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->links = $container->get(ContractAccessLinkRepository::class);
        $this->signatures = $container->get(ContractSignatureRepository::class);
        $this->challenges = $container->get(ContractSignatureChallengeRepository::class);
        $this->contracts = $container->get(ContractRepository::class);

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
        // Signatures point at contracts with RESTRICT, so they go first. That
        // ordering is the guard working as designed rather than a nuisance.
        $this->entityManager->createQuery('DELETE FROM '.ContractSignature::class)->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Contract::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', ContractTemplate::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Customer::class))->execute();

        parent::tearDown();
    }

    public function testACustomerSignsAndTheProviderConcludes(): void
    {
        $url = $this->sentContractUrl();
        $contractId = $this->links->findAll()[0]->getContract()->getId();

        $guest = $this->asGuest();

        // The code, asked for from the page rather than mailed with the link:
        // one sent a week earlier would have expired long before anybody read
        // nineteen articles.
        $guest->jsonRequest('POST', $url.'/code');
        self::assertSame(200, $guest->getResponse()->getStatusCode());

        $payload = json_decode((string) $guest->getResponse()->getContent(), true);
        // Masked, because this reply goes to whoever holds the link.
        self::assertStringContainsString('*', (string) $payload['sentTo']);
        self::assertStringContainsString('@durand.test', (string) $payload['sentTo']);

        $code = $this->latestCode();

        $guest->jsonRequest('POST', $url.'/sign', $this->signPayload($code));
        self::assertSame(200, $guest->getResponse()->getStatusCode());

        $this->entityManager->clear();
        $contract = $this->contracts->find($contractId);

        self::assertSame('signed_by_customer', $contract->getStatus()->value);

        $signature = $this->signatures->findOneForRole($contract, ContractSignatureRoleEnum::Customer);
        self::assertNotNull($signature);

        // The three groups of evidence, all present.
        self::assertSame('Camille Durand', $signature->getDeclaredFullName());
        self::assertSame('Lyon', $signature->getDeclaredPlace());
        self::assertSame('2026-09-08', $signature->getDeclaredDate()->format('Y-m-d'));
        self::assertNotNull($signature->getIpAddress());
        self::assertNotNull($signature->getChallengeVerifiedAt());
        self::assertNotNull($signature->getLinkSelector());
        // The hash was copied at signing, so the row says what was agreed to
        // whatever happens to the contract afterwards.
        self::assertSame($contract->getContentHash(), $signature->getSignedContentHash());
        self::assertTrue($signature->coversCurrentDocument());
        // Encrypted at rest, and readable back through the type.
        self::assertSame(self::PNG, $signature->getSignatureImage());

        // Now the countersignature, from the back office.
        $this->login();
        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/countersign', $contractId), [
            'firstName' => 'Axel',
            'lastName' => 'Raboit',
            'email' => 'axel@example.test',
            'place' => 'Grenoble',
            'date' => '2026-09-09',
            'signatureImage' => self::PNG,
            'consent' => true,
            // No code: the provider is authenticated, and asking them for one
            // mailed to themselves would add a step and no evidence.
            'code' => 'unused',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();
        $contract = $this->contracts->find($contractId);

        self::assertSame('countersigned', $contract->getStatus()->value);

        $provider = $this->signatures->findOneForRole($contract, ContractSignatureRoleEnum::Provider);
        self::assertNotNull($provider);
        // A session rather than a mailbox: a stronger link to a person, and
        // free.
        self::assertNotNull($provider->getUser());
        self::assertNull($provider->getChallengeVerifiedAt());
    }

    /**
     * Article 13 is satisfied on the page that collects, or nowhere.
     *
     * Asserted on the served HTML rather than on the service that builds it:
     * a notice that exists in a class and never reaches the reader is the
     * failure this is guarding against, and it is invisible in a unit test.
     * The retention figure is checked too, because the whole point of reading
     * it from the settings is that the page cannot promise one number while
     * the deletion guard enforces another.
     */
    public function testThePublicPageTellsTheSignerWhatIsCollected(): void
    {
        $url = $this->sentContractUrl();

        $guest = $this->asGuest();
        $guest->request('GET', $url);

        self::assertSame(200, $guest->getResponse()->getStatusCode());

        $html = (string) $guest->getResponse()->getContent();

        self::assertStringContainsString('Vos données', $html);
        // What is recorded without being typed is the part somebody would not
        // guess, so it is the part that has to be there.
        self::assertStringContainsString('adresse IP', $html);
        self::assertStringContainsString('6.1.b', $html);
        self::assertStringContainsString('6.1.f', $html);
        self::assertStringContainsString('10 ans', $html);
        self::assertStringContainsString('CNIL', $html);
    }

    public function testTheCodeIsSpentOnlyOnceAValidPayloadArrives(): void
    {
        $url = $this->sentContractUrl();
        $guest = $this->asGuest();

        $guest->jsonRequest('POST', $url.'/code');
        $code = $this->latestCode();

        // A payload that fails validation: the code must survive it, or a typo
        // in a name would send somebody back to their mailbox.
        $guest->jsonRequest('POST', $url.'/sign', [
            ...$this->signPayload($code),
            'firstName' => '',
        ]);

        self::assertSame(422, $guest->getResponse()->getStatusCode());
        $errors = json_decode((string) $guest->getResponse()->getContent(), true)['errors'];
        self::assertArrayHasKey('firstName', $errors);

        $this->entityManager->clear();
        $challenge = $this->challenges->findLatestFor($this->links->findAll()[0]);
        self::assertFalse($challenge->isConsumed(), 'A form error must not spend a credential.');

        // The same code, with a valid payload, still works.
        $guest->jsonRequest('POST', $url.'/sign', $this->signPayload($code));
        self::assertSame(200, $guest->getResponse()->getStatusCode());
    }

    public function testSigningWithoutConsentIsRefused(): void
    {
        $url = $this->sentContractUrl();
        $guest = $this->asGuest();

        $guest->jsonRequest('POST', $url.'/code');

        $guest->jsonRequest('POST', $url.'/sign', [
            ...$this->signPayload($this->latestCode()),
            'consent' => false,
        ]);

        self::assertSame(422, $guest->getResponse()->getStatusCode());
        $errors = json_decode((string) $guest->getResponse()->getContent(), true)['errors'];
        self::assertArrayHasKey('consent', $errors);
    }

    public function testSigningWithoutASignatureIsRefused(): void
    {
        $url = $this->sentContractUrl();
        $guest = $this->asGuest();

        $guest->jsonRequest('POST', $url.'/code');

        $guest->jsonRequest('POST', $url.'/sign', [
            ...$this->signPayload($this->latestCode()),
            'signatureImage' => '',
        ]);

        self::assertSame(422, $guest->getResponse()->getStatusCode());
        $errors = json_decode((string) $guest->getResponse()->getContent(), true)['errors'];
        self::assertArrayHasKey('signatureImage', $errors);
    }

    /**
     * Anything but a PNG data URI is refused.
     *
     * The column is encrypted and printed into a PDF later, so what goes in it
     * has to be one shape.
     */
    public function testSomethingOtherThanAPngIsRefused(): void
    {
        $url = $this->sentContractUrl();
        $guest = $this->asGuest();

        $guest->jsonRequest('POST', $url.'/code');

        $guest->jsonRequest('POST', $url.'/sign', [
            ...$this->signPayload($this->latestCode()),
            'signatureImage' => 'data:text/html;base64,PHNjcmlwdD4=',
        ]);

        self::assertSame(422, $guest->getResponse()->getStatusCode());
        $errors = json_decode((string) $guest->getResponse()->getContent(), true)['errors'];
        self::assertArrayHasKey('signatureImage', $errors);
    }

    public function testTheSameCustomerCannotSignTwice(): void
    {
        $url = $this->sentContractUrl();
        $guest = $this->asGuest();

        $guest->jsonRequest('POST', $url.'/code');
        $guest->jsonRequest('POST', $url.'/sign', $this->signPayload($this->latestCode()));
        self::assertSame(200, $guest->getResponse()->getStatusCode());

        // A second code, and a second attempt at the same role.
        $guest->jsonRequest('POST', $url.'/code');
        self::assertSame(200, $guest->getResponse()->getStatusCode());

        $guest->jsonRequest('POST', $url.'/sign', $this->signPayload($this->latestCode()));

        self::assertSame(422, $guest->getResponse()->getStatusCode());
        $errors = json_decode((string) $guest->getResponse()->getContent(), true)['errors'];
        self::assertArrayHasKey('status', $errors);
    }

    /**
     * The order, enforced rather than assumed.
     *
     * The countersignature is what concludes, so there has to be something to
     * conclude.
     */
    public function testTheProviderCannotCountersignBeforeTheCustomerSigns(): void
    {
        $this->sentContractUrl();
        $contractId = $this->links->findAll()[0]->getContract()->getId();

        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/countersign', $contractId), [
            'firstName' => 'Axel',
            'lastName' => 'Raboit',
            'email' => 'axel@example.test',
            'place' => 'Grenoble',
            'date' => '2026-09-09',
            'signatureImage' => self::PNG,
            'consent' => true,
            'code' => 'unused',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $errors = json_decode((string) $this->client->getResponse()->getContent(), true)['errors'];
        self::assertArrayHasKey('status', $errors);
    }

    /**
     * A signed contract is not re-sendable.
     *
     * Re-sending would hand a fresh address to a document that is already
     * committed, which is not a resend but a second chance to sign the same
     * thing.
     */
    public function testASignedContractCannotBeSentAgain(): void
    {
        $url = $this->sentContractUrl();
        $contractId = $this->links->findAll()[0]->getContract()->getId();

        $guest = $this->asGuest();
        $guest->jsonRequest('POST', $url.'/code');
        $guest->jsonRequest('POST', $url.'/sign', $this->signPayload($this->latestCode()));
        self::assertSame(200, $guest->getResponse()->getStatusCode());

        $this->login();
        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/send', $contractId));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function signPayload(string $code): array
    {
        return [
            'firstName' => 'Camille',
            'lastName' => 'Durand',
            'email' => 'camille@durand.test',
            'place' => 'Lyon',
            // Yesterday: the declared date and the observed one are kept apart,
            // and a signer writing a different day is a fact to preserve.
            'date' => '2026-09-08',
            'signatureImage' => self::PNG,
            'consent' => true,
            'code' => $code,
        ];
    }

    /**
     * The code that was just mailed.
     *
     * Read from the challenge the request created rather than from the mail:
     * the plaintext lives for one request, and this is the same lookup the
     * verification does.
     */
    private function latestCode(): string
    {
        $this->entityManager->clear();
        $link = $this->links->findAll()[0];
        $challenge = $this->challenges->findLatestFor($link);

        self::assertNotNull($challenge);

        // The code itself is not stored, so the test brute-forces the six
        // digits against the stored hash. A million hashes is a second, and it
        // proves the stored form really is a digest of what was mailed.
        for ($candidate = 0; $candidate <= 999999; ++$candidate) {
            $guess = sprintf('%06d', $candidate);

            if (hash('sha256', $guess) === $challenge->getHashedCode()) {
                return $guess;
            }
        }

        self::fail('The stored hash matches no six-digit code.');
    }

    private function sentContractUrl(): string
    {
        $this->client->jsonRequest('POST', '/backend/studio/contracts/create', [
            'customerId' => $this->customer()->getId(),
            'bodyTemplateId' => $this->publishedTemplate()->getId(),
            'locale' => 'fr',
            'amount' => '850',
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        $id = $created['contract']['id'];

        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/freeze', $id));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/send', $id));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        foreach ($this->mailerMessages() as $message) {
            if (1 === preg_match('#(/contracts/[a-f0-9]{32}/[a-f0-9]{64})#', (string) $message->getHtmlBody(), $matches)) {
                return $matches[1];
            }
        }

        self::fail('No mail carried the signing address.');
    }

    /** @return list<Email> */
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

    private function publishedTemplate(): ContractTemplateInterface
    {
        $template = $this->templates->create(new ContractTemplateInput('Contrat mensuel', ContractTemplateKindEnum::Body));
        $version = $template->getDraft();

        $this->templates->updateDraft($version, new ContractTemplateVersionInput([
            'fr' => [
                'title' => 'CONTRAT DE PRESTATION DE SERVICES',
                'content' => ['blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'Le forfait est de {{contract.amount}}.']],
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
