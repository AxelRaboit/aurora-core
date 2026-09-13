<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Contract;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
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
use Aurora\Module\Studio\Contract\Signature\Entity\AbstractContractSignatureChallenge;
use Aurora\Module\Studio\Contract\Signature\Manager\ContractSignatureChallengeManager;
use Aurora\Module\Studio\Contract\Signature\Manager\ContractSignatureChallengeManagerInterface;
use Aurora\Module\Studio\Contract\Signature\Repository\ContractSignatureChallengeRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Contracts\Translation\TranslatorInterface;

use function json_decode;
use function sprintf;

/**
 * The code that turns "somebody who had the link" into "somebody who also
 * reads the mailbox the contract names".
 *
 * Every test here is a limit rather than a feature. Six digits is a million
 * possibilities, and the security is entirely in the ten minutes, the five
 * attempts, the single use and the ceiling on how many can be asked for.
 */
final class SignatureChallengeTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private ContractTemplateManager $templates;

    private ContractSignatureChallengeManagerInterface $challenges;

    private ContractSignatureChallengeRepository $challengeRepository;

    private ContractAccessLinkRepository $links;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->links = $container->get(ContractAccessLinkRepository::class);
        $this->challengeRepository = $container->get(ContractSignatureChallengeRepository::class);

        $this->templates = new ContractTemplateManager(
            $this->entityManager,
            $container->get(AuditLogger::class),
            $container->get(ContractTemplateVersionRepository::class),
            $container->get(TranslatorInterface::class),
            $container->get(ContractRepository::class),
        );

        // Built here: its own controller lands with the signing form, so the
        // container still removes it as an unused private service.
        $this->challenges = new ContractSignatureChallengeManager(
            $this->entityManager,
            $this->challengeRepository,
            $container->get(MailService::class),
            $container->get(TranslatorInterface::class),
        );
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Contract::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', ContractTemplate::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Customer::class))->execute();

        parent::tearDown();
    }

    public function testACodeIsMailedToTheContractsAddressAndVerifiesOnce(): void
    {
        $link = $this->sentLink();

        $challenge = $this->challenges->issue($link);
        $code = $challenge->getPlainCode();

        self::assertNotNull($code);
        // The contract's address, never one a request supplied: a code mailed
        // to an address the signer typed would prove they can read their own
        // mailbox, which is not the question.
        self::assertSame('contact@durand.test', $challenge->getSentTo());

        // The body of the code mail is NOT asserted here, and that is a
        // limitation rather than an omission: the mailer collector reads the
        // profile of the last HTTP request, and this code is issued by a direct
        // call afterwards, so the message never appears in it. The assertion
        // that the mail carries the code and not the link belongs with the
        // endpoint that issues it, which lands with the signing form.
        //
        // What is checked here is the part that matters for security and is
        // checkable now: the address it goes to, and every limit below.

        $verifiedAt = $this->challenges->verify($link, $code);
        self::assertNotNull($verifiedAt);

        // Single use: the same code a second later is dead.
        try {
            $this->challenges->verify($link, $code);
            self::fail('A consumed code was accepted twice.');
        } catch (FieldException $exception) {
            self::assertSame('code', $exception->getField());
        }
    }

    public function testFiveWrongAnswersBurnTheCode(): void
    {
        $link = $this->sentLink();
        $challenge = $this->challenges->issue($link);
        $code = $challenge->getPlainCode();

        $wrong = '000000' === $code ? '111111' : '000000';

        for ($i = 0; $i < AbstractContractSignatureChallenge::MAX_ATTEMPTS; ++$i) {
            try {
                $this->challenges->verify($link, $wrong);
                self::fail('A wrong code was accepted.');
            } catch (FieldException) {
                // Expected, and counted.
            }
        }

        // The right code, now refused: the attempts are what makes six digits
        // enough, so they have to outlive a correct guess arriving late.
        try {
            $this->challenges->verify($link, (string) $code);
            self::fail('A burnt code was accepted.');
        } catch (FieldException $exception) {
            self::assertSame('code', $exception->getField());
        }

        $this->entityManager->clear();
        $stored = $this->challengeRepository->findLatestFor($this->links->findAll()[0]);
        self::assertSame(AbstractContractSignatureChallenge::MAX_ATTEMPTS, $stored?->getAttempts());
    }

    /**
     * Failed attempts survive the refusal.
     *
     * A limit that only applies when the caller finishes politely is not a
     * limit: the counter is flushed before the exception is raised.
     */
    public function testAFailedAttemptIsPersistedEvenThoughTheCallThrows(): void
    {
        $link = $this->sentLink();
        $this->challenges->issue($link);

        try {
            $this->challenges->verify($link, '000000');
        } catch (FieldException) {
            // The point is what happened in the database, not the exception.
        }

        $this->entityManager->clear();
        $stored = $this->challengeRepository->findLatestFor($this->links->findAll()[0]);

        self::assertGreaterThanOrEqual(1, $stored?->getAttempts());
    }

    /**
     * Only the newest code is checked.
     *
     * Accepting any code still inside its window would multiply the guesses the
     * attempt limit exists to cap.
     */
    public function testAnEarlierCodeStopsWorkingAsSoonAsANewOneIsAskedFor(): void
    {
        $link = $this->sentLink();

        $first = $this->challenges->issue($link)->getPlainCode();
        $second = $this->challenges->issue($link)->getPlainCode();

        self::assertNotNull($first);
        self::assertNotNull($second);

        try {
            $this->challenges->verify($link, $first);
            self::fail('A superseded code was accepted.');
        } catch (FieldException $exception) {
            self::assertSame('code', $exception->getField());
        }

        self::assertNotNull($this->challenges->verify($link, $second));
    }

    public function testAPayloadThatIsNotACodeIsRefusedAndCounted(): void
    {
        $link = $this->sentLink();
        $this->challenges->issue($link);

        foreach (['', 'abcdef', '12345', '1234567', '12 34 56'] as $notACode) {
            try {
                $this->challenges->verify($link, $notACode);
                self::fail(sprintf('"%s" was accepted as a code.', $notACode));
            } catch (FieldException $exception) {
                self::assertSame('code', $exception->getField());
            }
        }
    }

    /**
     * The ceiling per address, on top of the rate limiter.
     *
     * The limiter caps requests per IP, which is the attacker's to rotate. This
     * one caps them per link, so nobody can mail a customer a hundred codes.
     */
    public function testAnAddressCannotAskForCodesWithoutEnd(): void
    {
        $link = $this->sentLink();

        for ($i = 0; $i < ContractSignatureChallengeManager::MAX_ISSUED_PER_HOUR; ++$i) {
            $this->challenges->issue($link);
        }

        try {
            $this->challenges->issue($link);
            self::fail('The ceiling on issued codes did not hold.');
        } catch (FieldException $exception) {
            self::assertSame('code', $exception->getField());
        }
    }

    /**
     * Codes hang off the link, not the contract.
     *
     * Revoking an address has to kill the codes issued through it, and a code
     * issued for a replaced address must not open the new one.
     */
    public function testResendingTheLinkLeavesTheOldCodesBehind(): void
    {
        $link = $this->sentLink();
        $code = $this->challenges->issue($link)->getPlainCode();
        $contractId = $link->getContract()->getId();

        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/send', $contractId));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();
        $fresh = $this->links->findActiveFor(
            $this->entityManager->find(Contract::class, $contractId),
        );

        self::assertInstanceOf(ContractAccessLinkInterface::class, $fresh);
        self::assertNotSame($link->getSelector(), $fresh->getSelector());

        // The new address has no code of its own yet, so the old one cannot be
        // replayed against it.
        try {
            $this->challenges->verify($fresh, (string) $code);
            self::fail('A code from a revoked address opened the new one.');
        } catch (FieldException $exception) {
            self::assertSame('code', $exception->getField());
        }
    }

    private function sentLink(): ContractAccessLinkInterface
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

        $link = $this->links->findAll()[0];
        self::assertInstanceOf(ContractAccessLinkInterface::class, $link);

        return $link;
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
