<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Contract;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLink;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInput;
use Aurora\Module\Studio\Contract\Entity\Contract;
use Aurora\Module\Studio\Contract\Entity\ContractTemplate;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManager;
use Aurora\Module\Studio\Contract\Message\ExpireLapsedContractsMessage;
use Aurora\Module\Studio\Contract\Message\RemindUnsignedContractsMessage;
use Aurora\Module\Studio\Contract\MessageHandler\ExpireLapsedContractsHandler;
use Aurora\Module\Studio\Contract\MessageHandler\RemindUnsignedContractsHandler;
use Aurora\Module\Studio\Contract\Serializer\ContractSerializer;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

use function array_filter;
use function array_values;
use function count;
use function json_decode;
use function mb_substr;
use function sprintf;

/**
 * What runs every morning without anybody clicking: the reminders, the
 * expiry of lapsed links, and the retention report.
 *
 * None of the three had a test. They are the part of the module a reader
 * never watches happen, so the part where a regression lives longest.
 */
final class ContractScheduledJobsTest extends IntegrationTestCase
{
    private const array REMINDER_SETTINGS = [
        ApplicationParameterEnum::StudioContractReminderEnabled,
        ApplicationParameterEnum::StudioContractReminderDays,
        ApplicationParameterEnum::StudioContractReminderMax,
    ];

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SettingRepository $settings;

    /** @var array<string, string|null> */
    private array $originalSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->settings = $container->get(SettingRepository::class);

        foreach (self::REMINDER_SETTINGS as $parameter) {
            $this->originalSettings[$parameter->value] = $this->settings->get($parameter->value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalSettings as $key => $value) {
            $this->settings->set($key, $value);
        }

        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Contract::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', ContractTemplate::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Customer::class))->execute();

        parent::tearDown();
    }

    public function testAContractLeftUnansweredIsRemindedOnceADayAtMost(): void
    {
        $this->remindAfter(days: 3, max: 2);
        $id = $this->sentContract();
        $this->sentDaysAgo($id, 5);

        $before = $this->mailsTo('contact@durand.test');
        $this->runReminders();

        self::assertSame(1, $this->contract($id)->getReminderCount());
        self::assertSame($before + 1, $this->mailsTo('contact@durand.test'));

        // The next morning's run finds it reminded today, and waits.
        $this->runReminders();
        self::assertSame(1, $this->contract($id)->getReminderCount());
    }

    public function testNoReminderLeavesWhileTheSettingIsOff(): void
    {
        $this->settings->set(ApplicationParameterEnum::StudioContractReminderEnabled->value, '0');
        $id = $this->sentContract();
        $this->sentDaysAgo($id, 30);

        $this->runReminders();

        self::assertSame(0, $this->contract($id)->getReminderCount());
    }

    public function testAContractIsNotRemindedPastTheCeiling(): void
    {
        $this->remindAfter(days: 3, max: 1);
        $id = $this->sentContract();
        $this->sentDaysAgo($id, 10);
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE core_contracts SET reminder_count = 1, last_reminder_at = NOW() - INTERVAL '5 days' WHERE id = :id",
            ['id' => $id],
        );

        $this->runReminders();

        self::assertSame(1, $this->contract($id)->getReminderCount());
    }

    public function testALapsedLinkTurnsTheContractExpired(): void
    {
        $id = $this->sentContract();
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE core_contract_access_links SET sent_at = NOW() - INTERVAL '40 days', expires_at = NOW() - INTERVAL '10 days' WHERE contract_id = :id",
            ['id' => $id],
        );
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE core_contracts SET created_at = NOW() - INTERVAL '41 days', frozen_at = NOW() - INTERVAL '40 days' WHERE id = :id",
            ['id' => $id],
        );

        static::getContainer()->get(ExpireLapsedContractsHandler::class)(new ExpireLapsedContractsMessage());

        self::assertSame('expired', $this->contract($id)->getStatus()->value);

        // The lapse is the last thing that happened to it, for the list.
        $row = static::getContainer()->get(ContractSerializer::class)->serializeMany([$this->contract($id)])[0];
        self::assertSame(new DateTimeImmutable('-10 days')->format('Y-m-d'), mb_substr((string) $row['lastActivityAt'], 0, 10));
    }

    public function testAContractStillOutWithTheCustomerIsNotExpired(): void
    {
        $id = $this->sentContract();

        static::getContainer()->get(ExpireLapsedContractsHandler::class)(new ExpireLapsedContractsMessage());

        self::assertSame('sent', $this->contract($id)->getStatus()->value);
    }

    public function testTheRetentionReportListsEverySealedContract(): void
    {
        $id = $this->sentContract();
        $reference = (string) $this->contract($id)->getReference();

        $tester = new CommandTester((new Application(static::$kernel))->find('aurora:contracts:retention'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString($reference, $tester->getDisplay());
        self::assertStringContainsString('en cours', $tester->getDisplay());
    }

    private function remindAfter(int $days, int $max): void
    {
        $this->settings->set(ApplicationParameterEnum::StudioContractReminderEnabled->value, '1');
        $this->settings->set(ApplicationParameterEnum::StudioContractReminderDays->value, (string) $days);
        $this->settings->set(ApplicationParameterEnum::StudioContractReminderMax->value, (string) $max);
    }

    private function runReminders(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        static::getContainer()->get(RemindUnsignedContractsHandler::class)(new RemindUnsignedContractsMessage());
    }

    private function sentDaysAgo(int $contractId, int $days): void
    {
        $this->entityManager->getConnection()->executeStatement(
            sprintf("UPDATE core_contract_access_links SET sent_at = NOW() - INTERVAL '%d days' WHERE contract_id = :id", $days),
            ['id' => $contractId],
        );
    }

    private function contract(int $id): Contract
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $contract = $entityManager->getRepository(Contract::class)->find($id);
        self::assertInstanceOf(Contract::class, $contract);

        return $contract;
    }

    private function mailsTo(string $address): int
    {
        $sent = array_filter(
            $this->getMailerEvents(),
            static function ($event) use ($address): bool {
                $message = $event->getMessage();

                return !$event->isQueued()
                    && $message instanceof Email
                    && $address === ($message->getTo()[0] ?? null)?->getAddress();
            },
        );

        return count(array_values($sent));
    }

    /** Sealed and sent through the routes the screen calls. */
    private function sentContract(): int
    {
        $this->client->jsonRequest('POST', '/backend/studio/contracts/create', [
            'customerId' => $this->customer()->getId(),
            'bodyTemplateId' => $this->publishedTemplate()->getId(),
            'locale' => 'fr',
            'amount' => '850',
            'amountCurrency' => 'EUR',
            'effectiveDate' => '2026-10-01',
        ]);
        $id = (int) json_decode((string) $this->client->getResponse()->getContent(), true)['contract']['id'];

        foreach (['freeze', 'send'] as $gesture) {
            $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/%s', $id, $gesture));
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $gesture);
        }

        self::assertCount(1, static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(ContractAccessLink::class)->findBy(['contract' => $id]));

        return $id;
    }

    private function publishedTemplate(): ContractTemplate
    {
        $templates = static::getContainer()->get(ContractTemplateManager::class);
        $template = $templates->create(new ContractTemplateInput('Contrat mensuel', ContractTemplateKindEnum::Body));
        $version = $template->getDraft();

        $templates->updateDraft($version, new ContractTemplateVersionInput([
            'fr' => [
                'title' => 'CONTRAT DE PRESTATION DE SERVICES',
                'content' => ['blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'Le forfait est de {{contract.amount}} pour {{customer.legal_name}}.']],
                ]],
            ],
        ]));
        $templates->publish($version);

        return $template;
    }

    private function customer(): Customer
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
