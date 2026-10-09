<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Core\Notification\Entity\Notification;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Reminder\Entity\NoteReminder;
use Aurora\Module\Notes\Reminder\Service\NoteReminders;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** « Remind me of this note » (09/10/2026): set, read, cleared, and rung once. */
final class NoteReminderTest extends IntegrationTestCase
{
    use PersonalSpaceTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    private User $owner;

    private MarkdownNote $note;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);

        $owner = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $owner);
        $this->owner = $owner;

        $note = new MarkdownNote();
        $note->setUser($owner);
        $note->setSpace($this->personalSpaceOf($owner));
        $note->setTitle('Relancer le client');
        $note->setContent('Du texte.');

        $this->entityManager->persist($note);
        $this->entityManager->flush();

        $this->note = $note;
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();
        foreach ($this->entityManager->getRepository(Notification::class)->findBy(['type' => 'notes.reminder']) as $notification) {
            $this->entityManager->remove($notification);
        }

        $note = $this->entityManager->find(MarkdownNote::class, $this->note->getId());
        if (null !== $note) {
            $this->entityManager->remove($note);
        }

        $this->entityManager->flush();

        parent::tearDown();
    }

    public function testAReminderIsSetReadAndCleared(): void
    {
        $this->client->loginUser($this->owner, 'admin');
        $path = $this->urlGenerator->generate('suite_notes_markdown_reminder', ['id' => $this->note->getId()]);

        $this->client->request('GET', $path);
        self::assertNull($this->body()['remindAt']);

        $this->post($path, ['remindAt' => '2030-01-02T09:00:00+01:00']);
        self::assertResponseIsSuccessful();
        self::assertSame('2030-01-02T08:00:00+00:00', $this->body()['remindAt']);

        // A second one replaces the first: one reminder waiting per person and per note.
        $this->post($path, ['remindAt' => '2030-01-03T10:00:00.000Z']);
        self::assertSame('2030-01-03T10:00:00+00:00', $this->body()['remindAt']);
        self::assertCount(1, $this->entityManager->getRepository(NoteReminder::class)->findBy(['note' => $this->note->getId()]));

        $this->post($path, ['remindAt' => '2030-01-03T10:00']);
        self::assertResponseStatusCodeSame(422);

        $this->post($path, ['remindAt' => null]);
        self::assertNull($this->body()['remindAt']);
        self::assertCount(0, $this->entityManager->getRepository(NoteReminder::class)->findBy(['note' => $this->note->getId()]));
    }

    public function testADueReminderRingsOnceAndAFutureOneWaits(): void
    {
        $reminders = self::getContainer()->get(NoteReminders::class);
        $reminders->set($this->owner, $this->note, new DateTimeImmutable('-5 minutes'));

        self::assertSame(1, $reminders->sendDue());
        self::assertSame(0, $reminders->sendDue(), 'a sent reminder does not ring again');

        $notifications = $this->entityManager->getRepository(Notification::class)->findBy(['type' => 'notes.reminder']);
        self::assertCount(1, $notifications);
        self::assertSame('Relancer le client', $notifications[0]->getTitle());

        $reminders->set($this->owner, $this->note, new DateTimeImmutable('+1 hour'));
        self::assertSame(0, $reminders->sendDue());
    }

    /** @param array<string, mixed> $payload */
    private function post(string $path, array $payload): void
    {
        $this->client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
