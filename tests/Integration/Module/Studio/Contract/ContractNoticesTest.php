<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Contract;

use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Core\Notification\Entity\Notification;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Contract\Access\Manager\ContractAccessLinkManagerInterface;
use Aurora\Module\Studio\Contract\Access\Repository\ContractAccessLinkRepository;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInput;
use Aurora\Module\Studio\Contract\Entity\Contract;
use Aurora\Module\Studio\Contract\Entity\ContractTemplate;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManager;
use Aurora\Module\Studio\Contract\Message\NotifyEffectiveTerminationsMessage;
use Aurora\Module\Studio\Contract\MessageHandler\NotifyEffectiveTerminationsHandler;
use Aurora\Module\Studio\Contract\Preview\ContractTemplatePreviewer;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateVersionRepository;
use Aurora\Module\Studio\Contract\Service\ContractTeamNotifier;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_map;
use function array_values;
use function html_entity_decode;
use function json_decode;
use function parse_url;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_replace;

use const PHP_URL_PATH;

/**
 * Who hears about a contract that moves.
 *
 * Until 4.12 the customer heard of the link and of the conclusion, the
 * administrator address of a signature and a refusal, and nobody of anything
 * else: an expiry, a cancellation, a termination happened in silence, and the
 * customer closed the signing page with nothing in their mailbox. Each test
 * here is one of those silences, and who now breaks it.
 */
final class ContractNoticesTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private User $admin;

    private LocaleEnum $adminLocale;

    private EntityManagerInterface $entityManager;

    private ContractTemplateManager $contractTemplateManager;

    private ?CustomerInterface $customer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;
        $this->adminLocale = $admin->getLocale();
        $this->client->loginUser($admin, 'admin');

        $container->get('cache.rate_limiter')->clear();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->contractTemplateManager = new ContractTemplateManager(
            $this->entityManager,
            $container->get(AuditLogger::class),
            $container->get(ContractTemplateVersionRepository::class),
            $container->get(TranslatorInterface::class),
            $container->get(ContractRepository::class),
            $container->get(ContractTemplatePreviewer::class),
        );
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf('DELETE FROM %s n WHERE n.type LIKE :prefix', Notification::class))
            ->setParameter('prefix', 'studio.contract.%')
            ->execute();

        foreach ([SpaceAccessLink::class, CustomerSpaceMember::class, CustomerSpace::class, Contract::class, ContractTemplate::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        $this->entityManager->createQuery(sprintf('UPDATE %s u SET u.locale = :locale WHERE u.id = :id', User::class))
            ->setParameter('locale', $this->adminLocale)
            ->setParameter('id', $this->admin->getId())
            ->execute();

        parent::tearDown();
    }

    /**
     * The customer keeps a copy of their refusal, and the team of the
     * customer's spaces hears of it in the bell, in each member's language
     * rather than in the language of the page the refusal came from.
     */
    public function testARefusalIsAcknowledgedAndToldToTheTeamInItsLanguage(): void
    {
        $this->givenSpaceForTheCustomer();
        $this->entityManager->createQuery(sprintf('UPDATE %s u SET u.locale = :locale WHERE u.id = :id', User::class))
            ->setParameter('locale', LocaleEnum::English)
            ->setParameter('id', $this->admin->getId())
            ->execute();

        [, $url] = $this->sentContract();

        $this->client->getCookieJar()->clear();
        $this->client->jsonRequest('POST', $url.'/refuse', ['reason' => 'Trop cher']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $receipt = $this->mailTo('contact@durand.test');
        self::assertNotNull($receipt, 'The customer receives a copy of their refusal.');
        self::assertStringContainsString('refus', (string) $receipt->getSubject());
        self::assertStringContainsString('Trop cher', (string) $receipt->getHtmlBody());

        $titles = $this->bellTitles(ContractTeamNotifier::TYPE_REFUSED);
        self::assertCount(1, $titles);
        self::assertStringContainsString('refused by the customer', $titles[0], 'In the member\'s language, not the customer\'s.');
    }

    /** A customer with no space has no team: the administrator mail alone, and nothing breaks. */
    public function testARefusalWithoutASpaceNotifiesNobodyInTheBell(): void
    {
        [, $url] = $this->sentContract();

        $this->client->getCookieJar()->clear();
        $this->client->jsonRequest('POST', $url.'/refuse', ['reason' => '']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertSame([], $this->bellTitles(ContractTeamNotifier::TYPE_REFUSED));
    }

    /** An expiry used to happen in silence. The provider is told now, by mail and in the bell. */
    public function testAnExpiryIsToldToTheProvider(): void
    {
        $this->givenSpaceForTheCustomer();
        [$id] = $this->sentContract();

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE core_contract_access_links SET expires_at = :past WHERE contract_id = :id',
            ['past' => new DateTimeImmutable('-1 day')->format('Y-m-d H:i:s'), 'id' => $id],
        );
        $this->entityManager->clear();

        static::getContainer()->get(ContractAccessLinkManagerInterface::class)->expireLapsed();

        // The sending's mail is still in the collector: no request ran in
        // between to clear it. The expiry adds one, to the administrator.
        $expiryMails = array_values(array_filter(
            $this->mailerMessages(),
            static fn (Email $email): bool => str_contains((string) $email->getSubject(), 'expiré'),
        ));
        self::assertCount(1, $expiryMails);
        self::assertNull($this->mailTo('contact@durand.test', 'expiré'), 'The customer is not told: the next thing they hear is a new link.');
        self::assertCount(1, $this->bellTitles(ContractTeamNotifier::TYPE_EXPIRED));
    }

    /** A cancelled contract the customer had received is told to them when the provider asks. */
    public function testACancellationIsSentWhenAsked(): void
    {
        [$id, $url] = $this->sentContract();
        $this->client->jsonRequest('POST', sprintf('/suite/studio/contracts/%d/revoke-link', $id));

        $this->client->jsonRequest('POST', sprintf('/suite/studio/contracts/%d/cancel', $id), ['notifyCustomer' => true]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $mail = $this->mailTo('contact@durand.test');
        self::assertNotNull($mail);
        self::assertStringContainsString('annulé', (string) $mail->getSubject());
        self::assertNotSame('', $url);
    }

    /**
     * A contract still « Scellé » never reached the customer: asked or not,
     * nothing is sent, because the cancellation would be the first they hear
     * of it.
     */
    public function testANeverSentContractIsCancelledInSilence(): void
    {
        $id = $this->sealedContract();

        $this->client->jsonRequest('POST', sprintf('/suite/studio/contracts/%d/cancel', $id), ['notifyCustomer' => true]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertNull($this->mailTo('contact@durand.test'));
    }

    /** A termination carries its effective date to the customer, and only when asked. */
    public function testATerminationIsSentOnlyWhenAsked(): void
    {
        $first = $this->concludedContract();
        $second = $this->concludedContract();
        $payload = ['noticedAt' => '2026-10-01', 'effectiveAt' => '2026-12-31', 'origin' => 'provider', 'reason' => ''];

        $this->client->jsonRequest('POST', sprintf('/suite/studio/contracts/%d/terminate', $first), $payload);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->mailTo('contact@durand.test'), 'Not asked, not sent.');

        $this->client->jsonRequest('POST', sprintf('/suite/studio/contracts/%d/terminate', $second), [...$payload, 'notifyCustomer' => true]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $mail = $this->mailTo('contact@durand.test');
        self::assertNotNull($mail);
        self::assertStringContainsString('31/12/2026', (string) $mail->getHtmlBody());
    }

    /** A send unanswered for days, and a link about to lapse, are counted. */
    public function testLongWaitsAndLapsingLinksAreCounted(): void
    {
        [$id] = $this->sentContract();
        $links = static::getContainer()->get(ContractAccessLinkRepository::class);

        self::assertCount(0, $links->findWaitingSentBefore(new DateTimeImmutable('-3 days')));
        self::assertSame(0, $links->countWaitingWithLinkExpiringBefore(new DateTimeImmutable('+3 days')));

        $this->entityManager->getConnection()->executeStatement(
            "UPDATE core_contract_access_links SET sent_at = NOW() - INTERVAL '4 days', expires_at = NOW() + INTERVAL '1 day' WHERE contract_id = :id",
            ['id' => $id],
        );

        self::assertCount(1, $links->findWaitingSentBefore(new DateTimeImmutable('-3 days')));
        self::assertSame(1, $links->countWaitingWithLinkExpiringBefore(new DateTimeImmutable('+3 days')));
    }

    /** The morning a termination takes effect, the customer's team hears of it. */
    public function testATerminationTakingEffectTodayIsTold(): void
    {
        $this->givenSpaceForTheCustomer();
        $id = $this->concludedContract();
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE core_contracts SET termination_noticed_at = CURRENT_DATE, termination_effective_at = CURRENT_DATE, termination_origin = :origin WHERE id = :id',
            ['origin' => 'provider', 'id' => $id],
        );

        static::getContainer()->get(NotifyEffectiveTerminationsHandler::class)(new NotifyEffectiveTerminationsMessage());

        self::assertCount(1, $this->bellTitles(ContractTeamNotifier::TYPE_TERMINATION_EFFECTIVE));
    }

    /**
     * A link that may see contracts lists the one sent, and the way to sign
     * opens the contract's page without revoking the link in the customer's
     * mailbox. A link without the right sees nothing of it.
     */
    public function testTheClientSpaceShowsTheContractToSign(): void
    {
        $spaceId = $this->givenSpaceForTheCustomer();
        [, $mailedUrl] = $this->sentContract();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $spaceId), [
            'recipientEmail' => 'camille@durand.test', 'label' => 'Camille', 'canSeeContracts' => true,
        ]);
        $withContracts = (string) parse_url(json_decode((string) $this->client->getResponse()->getContent(), true)['url'], PHP_URL_PATH);
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $spaceId), [
            'recipientEmail' => 'lecteur@durand.test', 'label' => 'Lecteur',
        ]);
        $without = (string) parse_url(json_decode((string) $this->client->getResponse()->getContent(), true)['url'], PHP_URL_PATH);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $without);
        self::assertStringNotContainsString('to_sign', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', $withContracts);
        $page = html_entity_decode((string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('"step":"to_sign"', $page);
        self::assertSame(1, preg_match('#"signUrl":"([^"]+)"#', $page, $matches));

        $this->client->request('GET', str_replace('\/', '/', $matches[1]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        // The mailed address still works: nothing was revoked.
        $this->client->request('GET', $mailedUrl);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * A space for the customer, with the signed-in account on its team.
     *
     * Put on it by hand: an administrator who creates a space sees every
     * space already, and is not made its lead.
     */
    private function givenSpaceForTheCustomer(): int
    {
        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', [
            'name' => 'Espace Durand',
            'customerId' => $this->customer()->getId(),
            'timezone' => 'Europe/Paris',
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $spaceId = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['space']['id'];

        $member = new CustomerSpaceMember();
        $member
            ->setSpace($this->entityManager->getReference(CustomerSpace::class, $spaceId))
            ->setUser($this->entityManager->getReference(User::class, $this->admin->getId()))
            ->setRole(CustomerSpaceMemberRoleEnum::Lead);

        $this->entityManager->persist($member);
        $this->entityManager->flush();

        return $spaceId;
    }

    /** @return array{0: int, 1: string} the contract and the path of its signing page */
    private function sentContract(): array
    {
        $id = $this->sealedContract();

        $this->client->jsonRequest('POST', sprintf('/suite/studio/contracts/%d/send', $id));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        foreach ($this->mailerMessages() as $message) {
            if (1 === preg_match('#(/contracts/[a-f0-9]{32}/[a-f0-9]{64})#', (string) $message->getHtmlBody(), $matches)) {
                return [$id, $matches[1]];
            }
        }

        self::fail('No mail carried the signing address.');
    }

    private function sealedContract(): int
    {
        $this->client->jsonRequest('POST', '/suite/studio/contracts/create', [
            'customerId' => $this->customer()->getId(),
            'bodyTemplateId' => $this->publishedTemplate()->getId(),
            'locale' => 'fr',
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $id = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['contract']['id'];

        $this->client->jsonRequest('POST', sprintf('/suite/studio/contracts/%d/freeze', $id));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $id;
    }

    /**
     * Concluded by writing the status: the countersignature has its own
     * tests, and what is under test here is what follows it.
     */
    private function concludedContract(): int
    {
        $id = $this->sealedContract();

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE core_contracts SET status = :status WHERE id = :id',
            ['status' => ContractStatusEnum::Countersigned->value, 'id' => $id],
        );
        $this->entityManager->clear();

        return $id;
    }

    /** @return list<string> */
    private function bellTitles(string $type): array
    {
        return array_map(
            static fn (Notification $notification): string => $notification->getTitle(),
            $this->entityManager->getRepository(Notification::class)->findBy(['type' => $type]),
        );
    }

    private function mailTo(string $address, string $inSubject = ''): ?Email
    {
        foreach ($this->mailerMessages() as $message) {
            if ('' !== $inSubject && !str_contains((string) $message->getSubject(), $inSubject)) {
                continue;
            }

            foreach ($message->getTo() as $recipient) {
                if ($address === $recipient->getAddress()) {
                    return $message;
                }
            }
        }

        return null;
    }

    /** @return list<Email> the mails of the last request, queued copies left out */
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

    private function publishedTemplate(): ContractTemplateInterface
    {
        $template = $this->contractTemplateManager->create(new ContractTemplateInput('Contrat mensuel', ContractTemplateKindEnum::Body));
        $version = $template->getDraft();
        self::assertNotNull($version);

        $this->contractTemplateManager->updateDraft($version, new ContractTemplateVersionInput([
            'fr' => [
                'title' => 'CONTRAT DE PRESTATION DE SERVICES',
                'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Le forfait est payable d\'avance.']]]],
            ],
        ]));
        $this->contractTemplateManager->publish($version);

        return $template;
    }

    private function customer(): CustomerInterface
    {
        if ($this->customer instanceof CustomerInterface) {
            return $this->customer;
        }

        $customer = new Customer();
        $customer
            ->setLegalName('Boulangerie Durand')
            ->setSiret('73282932000074')
            ->setContractualEmail('contact@durand.test')
            ->setRepresentativeFirstName('Camille')
            ->setRepresentativeLastName('Durand');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $this->customer = $customer;
    }
}
