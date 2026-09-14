<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Form;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Editorial\Form\Entity\Form;
use Aurora\Module\Editorial\Form\Entity\FormSubmission;
use Aurora\Module\Editorial\Form\Message\PurgeFormSubmissionsMessage;
use Aurora\Module\Editorial\Form\MessageHandler\PurgeFormSubmissionsHandler;
use Aurora\Module\Editorial\Form\Repository\FormSubmissionRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * How long form answers are kept.
 *
 * A submission holds a name, an address, a message and the IP it came from,
 * and nothing here expired: a form left running collected personal data for
 * as long as the site lived. The duration is now the site's to set, and the
 * two cases worth proving are the two mistakes: never forgetting, and
 * forgetting what was asked to be kept.
 */
final class FormSubmissionRetentionTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    private PurgeFormSubmissionsHandler $handler;

    private FormSubmissionRepository $submissions;

    private Form $form;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->handler = static::getContainer()->get(PurgeFormSubmissionsHandler::class);
        $this->submissions = static::getContainer()->get(FormSubmissionRepository::class);

        $this->form = new Form();
        $this->form->setActive(true);
        $this->form->translate('fr')->setTitle('Me contacter')->setSlug('contact-'.bin2hex(random_bytes(4)));

        $this->entityManager->persist($this->form);
        $this->entityManager->flush();
        $this->created[] = $this->form;
    }

    protected function tearDown(): void
    {
        $this->retention(0);

        foreach (array_reverse($this->created) as $entity) {
            $managed = $this->entityManager->find($entity::class, $entity->getId());
            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }

        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    /** The shipped default. Nothing is deleted until a site says how long. */
    public function testNothingExpiresWhileNoDurationIsSet(): void
    {
        $old = $this->submissionAgedInDays(400);
        $this->retention(0);

        ($this->handler)(new PurgeFormSubmissionsMessage());

        self::assertNotNull($this->submissions->find($old), 'a submission was deleted without a retention being set');
    }

    public function testWhatIsOlderThanTheDurationIsForgotten(): void
    {
        $old = $this->submissionAgedInDays(40);
        $this->retention(30);

        ($this->handler)(new PurgeFormSubmissionsMessage());

        self::assertNull($this->submissions->find($old));
    }

    /** The other half of the same rule: the cutoff has to hold. */
    public function testWhatIsInsideTheDurationIsKept(): void
    {
        $recent = $this->submissionAgedInDays(10);
        $this->retention(30);

        ($this->handler)(new PurgeFormSubmissionsMessage());

        self::assertNotNull($this->submissions->find($recent));
    }

    private function retention(int $days): void
    {
        static::getContainer()->get(SettingRepository::class)
            ->set(ApplicationParameterEnum::FormSubmissionRetentionDays->value, (string) $days);
    }

    /**
     * The submission date is stamped by the constructor and has no setter, on
     * purpose: nothing in the application may move it. The test backdates the
     * row itself rather than opening that door for everyone.
     */
    private function submissionAgedInDays(int $days): int
    {
        $submission = new FormSubmission();
        $submission->setForm($this->form);
        $submission->setData(['1' => 'Camille']);
        $submission->setLocale('fr');

        $this->entityManager->persist($submission);
        $this->entityManager->flush();

        $id = (int) $submission->getId();
        $this->created[] = $submission;

        $connection = $this->entityManager->getConnection();
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement(
            'UPDATE core_form_submissions SET submitted_at = :date WHERE id = :id',
            ['date' => date('Y-m-d H:i:s', strtotime(sprintf('-%d days', $days))), 'id' => $id],
        );

        $this->entityManager->clear();

        return $id;
    }
}
