<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Pipeline;

use Aurora\Core\Contact\Prospect\ProspectDirectoryInterface;
use Aurora\Core\Contact\Prospect\WebsiteContact;
use Aurora\Core\Notification\Entity\Notification;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\CustomerInteraction\Entity\CustomerInteraction;
use Aurora\Module\Studio\CustomerInteraction\Repository\CustomerInteractionRepository;
use Aurora\Module\Studio\Pipeline\Entity\PipelineStage;
use Aurora\Module\Studio\Pipeline\Entity\PipelineStageInterface;
use Aurora\Module\Studio\Pipeline\FollowUp\FollowUpReminders;
use Aurora\Module\Studio\Pipeline\Manager\PipelineStageManagerInterface;
use Aurora\Module\Studio\Pipeline\Service\FollowUpCalendar;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;
use function sprintf;

/**
 * The prospect pipeline, end to end: its stages, moving customers through
 * them, the history of exchanges, the morning reminder and the prospect made
 * from a website message.
 *
 * Most of these are about refusals and side effects rather than saves: what
 * the pipeline is for is the rules around the two outcomes and the follow-up,
 * and a board that let a prospect into "won" without converting it would
 * show a client nobody can send a contract to.
 */
final class PipelineTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private CustomerRepository $customerRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->customerRepository = $container->get(CustomerRepository::class);
    }

    protected function tearDown(): void
    {
        foreach ([CustomerInteraction::class, Customer::class, PipelineStage::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }
        $this->entityManager->createQuery(sprintf("DELETE FROM %s n WHERE n.type = '%s'", Notification::class, FollowUpReminders::TYPE))->execute();
        static::getContainer()->get(SettingRepository::class)->set(ApplicationParameterEnum::StudioPipelineOutcomeDays->value, null);

        parent::tearDown();
    }

    public function testTheStagesAreSeededTheFirstTimeTheyAreRead(): void
    {
        $stages = $this->stages();

        self::assertCount(5, $stages);
        self::assertNull($stages[0]->getRole());
        self::assertTrue($stages[3]->isWon());
        self::assertTrue($stages[4]->isLost());
        // Read again, not seeded twice.
        self::assertCount(5, $this->stages());
    }

    public function testACardMovesToAnotherStageInTheOrderTheBoardSent(): void
    {
        [$new, $contacted] = $this->stages();
        $first = $this->prospect('Garage Moreau');
        $second = $this->prospect('Studio Yoga Lumen');

        $payload = $this->post('/suite/studio/customers/pipeline/move', [
            'stageId' => $contacted->getId(),
            'customerIds' => [$second->getId(), $first->getId()],
        ]);

        self::assertTrue($payload['success']);
        self::assertSame([(int) $second->getId(), (int) $first->getId()], $this->columnOf($payload, $contacted));
        self::assertSame([], $this->columnOf($payload, $new));

        $this->entityManager->clear();
        $moved = $this->customerRepository->find($first->getId());
        self::assertSame($contacted->getId(), $moved?->getPipelineStage()?->getId());
        self::assertNotNull($moved->getPipelineStageChangedAt());
    }

    public function testAProspectWithNoStageIsDrawnInTheFirstStage(): void
    {
        [$new] = $this->stages();
        $prospect = $this->prospect('Boulangerie Lemoine');

        $this->client->request('GET', '/suite/studio/customers');
        self::assertResponseIsSuccessful();

        $payload = $this->post('/suite/studio/customers/pipeline/stages/reorder', [
            'stageIds' => [],
        ]);
        self::assertContains((int) $prospect->getId(), $this->columnOf($payload, $new));
    }

    public function testLosingADealRecordsWhyAndDropsTheFollowUp(): void
    {
        $lost = $this->stages()[4];
        $prospect = $this->prospect('Bistrot du Canal');
        $prospect->setNextFollowUpOn(new DateTimeImmutable('+2 days'))->setFollowUpNote('Rappeler');
        $this->entityManager->flush();

        $payload = $this->post('/suite/studio/customers/pipeline/move', [
            'stageId' => $lost->getId(),
            'customerIds' => [$prospect->getId()],
            'lostReason' => "  Budget repoussé à l'an prochain  ",
        ]);

        self::assertTrue($payload['success']);
        $this->entityManager->clear();
        $saved = $this->customerRepository->find($prospect->getId());
        self::assertSame("Budget repoussé à l'an prochain", $saved?->getLostReason());
        self::assertNull($saved->getNextFollowUpOn());
        self::assertNull($saved->getFollowUpNote());
    }

    public function testTheOutcomeColumnsShowHowFarBackTheSettingSays(): void
    {
        $lost = $this->stages()[4];
        $prospect = $this->prospect('Bistrot du Canal');
        $this->post('/suite/studio/customers/pipeline/move', ['stageId' => $lost->getId(), 'customerIds' => [$prospect->getId()]]);
        $this->entityManager->clear();
        $this->customerRepository->find($prospect->getId())?->setPipelineStageChangedAt(new DateTimeImmutable('-5 days'));
        $this->entityManager->flush();

        $payload = $this->post('/suite/studio/customers/pipeline/stages/reorder', ['stageIds' => []]);
        self::assertSame([(int) $prospect->getId()], $this->columnOf($payload, $lost), 'within the default thirty days');
        self::assertSame(30, $payload['pipeline']['outcomeWindowDays']);

        static::getContainer()->get(SettingRepository::class)->set(ApplicationParameterEnum::StudioPipelineOutcomeDays->value, '3');

        $payload = $this->post('/suite/studio/customers/pipeline/stages/reorder', ['stageIds' => []]);
        self::assertSame([], $this->columnOf($payload, $lost), 'lost five days ago, the board shows three');
        self::assertSame(3, $payload['pipeline']['outcomeWindowDays']);
    }

    public function testAProspectIsNotDroppedOnWonWithoutBeingConverted(): void
    {
        $won = $this->stages()[3];
        $prospect = $this->prospect('Cabinet Vasseur');

        $this->client->jsonRequest('POST', '/suite/studio/customers/pipeline/move', [
            'stageId' => $won->getId(),
            'customerIds' => [$prospect->getId()],
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->entityManager->clear();
        self::assertNull($this->customerRepository->find($prospect->getId())?->getPipelineStage());
    }

    public function testConvertingAProspectFilesItUnderWon(): void
    {
        $won = $this->stages()[3];
        $prospect = $this->prospect('Fromagerie des Alpes');

        $payload = $this->post(sprintf('/suite/studio/customers/%d/convert', $prospect->getId()), [
            'contractualEmail' => 'contact@fromagerie.test',
        ]);

        self::assertTrue($payload['success']);
        self::assertSame((int) $prospect->getId(), $this->columnOf($payload, $won)[0] ?? null);
    }

    public function testAClientDoesNotLeaveTheWonStage(): void
    {
        [, $contacted, , $won] = $this->stages();
        $client = $this->prospect('Roux Photographie');
        $this->post(sprintf('/suite/studio/customers/%d/convert', $client->getId()), ['contractualEmail' => 'roux@example.test']);

        $this->client->jsonRequest('POST', '/suite/studio/customers/pipeline/move', [
            'stageId' => $contacted->getId(),
            'customerIds' => [$client->getId()],
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->entityManager->clear();
        self::assertSame($won->getId(), $this->customerRepository->find($client->getId())?->getPipelineStage()?->getId());
    }

    public function testAnOutcomeRoleIsHeldByOneStageOnly(): void
    {
        $this->stages();

        $this->client->jsonRequest('POST', '/suite/studio/customers/pipeline/stages/create', [
            'name' => 'Signé',
            'role' => 'won',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('role', $payload['errors']);
    }

    public function testAStageHoldingCustomersIsNotDeleted(): void
    {
        [, $contacted] = $this->stages();
        $prospect = $this->prospect('Menuiserie Fabre');
        $this->post('/suite/studio/customers/pipeline/move', ['stageId' => $contacted->getId(), 'customerIds' => [$prospect->getId()]]);

        $this->client->jsonRequest('POST', sprintf('/suite/studio/customers/pipeline/stages/%d/delete', $contacted->getId()));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testTheLastStageInProgressIsNotDeleted(): void
    {
        [$new, $contacted, $proposal] = $this->stages();

        $this->post(sprintf('/suite/studio/customers/pipeline/stages/%d/delete', $new->getId()), []);
        $this->post(sprintf('/suite/studio/customers/pipeline/stages/%d/delete', $contacted->getId()), []);
        $this->client->jsonRequest('POST', sprintf('/suite/studio/customers/pipeline/stages/%d/delete', $proposal->getId()));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testRecordingAnExchangeSettlesTheFollowUp(): void
    {
        $prospect = $this->prospect('Atelier Lumière');
        $prospect->setNextFollowUpOn(new DateTimeImmutable('-2 days'))->setFollowUpNote('Relancer');
        $this->entityManager->flush();

        $payload = $this->post(sprintf('/suite/studio/customers/%d/interactions/create', $prospect->getId()), [
            'kind' => 'call',
            'occurredAt' => '2026-10-10T14:30:00+02:00',
            'summary' => 'Ok pour la proposition.',
            'setsFollowUp' => true,
            'nextFollowUpOn' => '2026-10-20',
            'followUpNote' => 'Envoyer le contrat',
        ]);

        self::assertTrue($payload['success']);
        self::assertCount(1, $payload['interactions']);
        self::assertSame('call', $payload['interactions'][0]['kind']);
        self::assertSame('2026-10-20', $payload['customer']['nextFollowUpOn']);
        self::assertSame('Envoyer le contrat', $payload['customer']['followUpNote']);

        // An exchange recorded without a next date clears the follow-up.
        $payload = $this->post(sprintf('/suite/studio/customers/%d/interactions/create', $prospect->getId()), [
            'kind' => 'email',
            'occurredAt' => '2026-10-11T09:00:00+02:00',
            'summary' => 'Contrat envoyé.',
            'setsFollowUp' => true,
        ]);
        self::assertNull($payload['customer']['nextFollowUpOn']);
        self::assertSame('email', $payload['interactions'][0]['kind'], 'newest first');
    }

    public function testEditingAnOldExchangeLeavesTheFollowUpAlone(): void
    {
        $prospect = $this->prospect('Atelier Lumière');
        $payload = $this->post(sprintf('/suite/studio/customers/%d/interactions/create', $prospect->getId()), [
            'kind' => 'note',
            'occurredAt' => '2026-10-01T10:00:00+02:00',
            'summary' => 'Première note.',
            'setsFollowUp' => true,
            'nextFollowUpOn' => '2026-10-25',
        ]);
        $interactionId = $payload['interactions'][0]['id'];

        $payload = $this->post(sprintf('/suite/studio/customers/%d/interactions/%d/update', $prospect->getId(), $interactionId), [
            'kind' => 'meeting',
            'occurredAt' => '2026-10-01T10:00:00+02:00',
            'summary' => 'Première rencontre.',
        ]);

        self::assertSame('meeting', $payload['interactions'][0]['kind']);
        self::assertSame('2026-10-25', $payload['customer']['nextFollowUpOn']);
    }

    public function testAnExchangeIsOnlyReachedThroughItsOwnCustomer(): void
    {
        $owner = $this->prospect('Atelier Lumière');
        $other = $this->prospect('Garage Moreau');
        $payload = $this->post(sprintf('/suite/studio/customers/%d/interactions/create', $owner->getId()), [
            'kind' => 'note',
            'occurredAt' => '2026-10-01T10:00:00+02:00',
            'summary' => 'Note privée.',
        ]);

        $this->client->jsonRequest('POST', sprintf('/suite/studio/customers/%d/interactions/%d/delete', $other->getId(), $payload['interactions'][0]['id']));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, static::getContainer()->get(CustomerInteractionRepository::class)->findForCustomer($owner));
    }

    public function testAFollowUpRingsOnceForItsDateAndAgainForANewOne(): void
    {
        $prospect = $this->prospect('Atelier Lumière');
        $today = static::getContainer()->get(FollowUpCalendar::class)->today();
        $prospect->setNextFollowUpOn($today)->setFollowUpNote('Envoyer le devis');
        $this->entityManager->flush();

        $reminders = static::getContainer()->get(FollowUpReminders::class);

        $first = $reminders->sendDue();
        self::assertGreaterThan(0, $first);
        self::assertSame(0, $reminders->sendDue(), 'not twice for the same day');

        $notification = $this->entityManager->getRepository(Notification::class)->findOneBy(['type' => FollowUpReminders::TYPE]);
        self::assertSame('Envoyer le devis', $notification?->getBody());
        self::assertSame(sprintf('/suite/studio/customers/%d', $prospect->getId()), $notification->getUrl());

        // Moved to another day, it rings again on that day.
        $this->entityManager->clear();
        $reloaded = $this->customerRepository->find($prospect->getId());
        $reloaded?->setNextFollowUpOn($today->modify('-1 day'));
        $this->entityManager->flush();
        self::assertSame($first, $reminders->sendDue());
    }

    public function testAWebsiteMessageBecomesOneProspectWithItsMessageAsHistory(): void
    {
        $directory = static::getContainer()->get(ProspectDirectoryInterface::class);
        $contact = new WebsiteContact(
            name: 'Sofia Marchetti',
            email: 'sofia.marchetti@example.net',
            phone: '07 88 45 12 03',
            sourceReference: 'SUB-TEST-001',
            sourceLabel: 'Demande de devis',
            summary: "Nom complet : Sofia Marchetti\nProjet : application métier",
            receivedAt: new DateTimeImmutable('-1 hour'),
        );

        self::assertTrue($directory->isAvailable());
        $path = $directory->createFromWebsiteContact($contact);
        self::assertSame($path, $directory->createFromWebsiteContact($contact), 'one message, one prospect');
        self::assertSame(['SUB-TEST-001' => $path], $directory->pathsForSources(['SUB-TEST-001', 'SUB-UNKNOWN']));

        $prospect = $this->customerRepository->findOneBySourceReference('SUB-TEST-001');
        self::assertInstanceOf(CustomerInterface::class, $prospect);
        self::assertTrue($prospect->isProspect());
        self::assertSame('website_form', $prospect->getSource()?->value);
        self::assertSame('sofia.marchetti@example.net', $prospect->getContractualEmail());

        $history = static::getContainer()->get(CustomerInteractionRepository::class)->findForCustomer($prospect);
        self::assertCount(1, $history);
        self::assertSame('Demande de devis', $history[0]->getAuthorLabel());
        self::assertStringContainsString('application métier', $history[0]->getSummary());
    }

    public function testTheCustomersListOffersTheFollowUpsDue(): void
    {
        $prospect = $this->prospect('Atelier Lumière');
        $prospect->setNextFollowUpOn(new DateTimeImmutable('-3 days'));
        $this->entityManager->flush();

        $this->client->request('GET', '/suite/studio/customers?followUps=due');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->customerRepository->countFollowUpsDue(static::getContainer()->get(FollowUpCalendar::class)->today()));
    }

    /** @return list<PipelineStageInterface> */
    private function stages(): array
    {
        return static::getContainer()->get(PipelineStageManagerInterface::class)->stages();
    }

    private function prospect(string $legalName): CustomerInterface
    {
        $payload = $this->post('/suite/studio/customers/create', ['legalName' => $legalName, 'status' => 'prospect']);

        $customer = $this->customerRepository->find($payload['customer']['id']);
        self::assertInstanceOf(CustomerInterface::class, $customer);

        return $customer;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $url, array $body): array
    {
        $this->client->jsonRequest('POST', $url, $body);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<int>
     */
    private function columnOf(array $payload, PipelineStageInterface $stage): array
    {
        foreach ($payload['pipeline']['columns'] as $column) {
            if ($column['stageId'] === $stage->getId()) {
                return $column['customerIds'];
            }
        }

        return [];
    }
}
