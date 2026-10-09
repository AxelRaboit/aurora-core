<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Notes\Live\Service\NoteGuestIdentity;
use Aurora\Module\Notes\Live\Service\NotePresence;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteRevision;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteHistory;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLink;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLinkInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_column;
use function json_decode;
use function json_encode;

/**
 * A writing link that opens its note to live co-editing, the Google Docs way.
 *
 * The room a guest walks into is the back office's own, so what is worth
 * proving here is everything that makes a guest a safe member of it: the link
 * has to ask for it, the guest's identity comes from the server and stays the
 * same, the guest shows as a guest with no name and no label, and an account
 * opening the note joins the same room - a personal note included.
 */
final class NoteShareLiveTest extends IntegrationTestCase
{
    use PersonalSpaceTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    private User $owner;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);

        $owner = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $owner);
        $this->owner = $owner;
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as [$class, $id]) {
            $entity = $this->entityManager->find($class, $id);
            if (null !== $entity) {
                $this->entityManager->remove($entity);
            }
        }
        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    /** A link that writes but did not ask for the live session has no room to enter. */
    public function testAWritingLinkWithoutLiveCoeditingHasNoRoom(): void
    {
        $note = $this->note();
        $link = $this->link($note, canWrite: true);

        $this->beat($link, $note);

        self::assertResponseStatusCodeSame(404);
    }

    /** The box means nothing on a link that does not write. */
    public function testAReadingLinkHasNoRoomEvenWithTheBoxTicked(): void
    {
        $note = $this->note();
        $link = $this->link($note, coediting: true);

        $this->beat($link, $note);

        self::assertResponseStatusCodeSame(404);
        self::assertFalse($link->allowsCoediting());
    }

    /**
     * A guest is in the room as a guest: an id above every account, and no
     * name - not the link's label either, which is often an address.
     */
    public function testAGuestIsInTheRoomAsAGuestWithNoName(): void
    {
        $note = $this->note();
        $link = $this->link($note, canWrite: true, coediting: true, label: 'marie@example.test');

        $body = $this->beat($link, $note);

        self::assertResponseIsSuccessful();
        self::assertTrue($body['coediting']);
        self::assertNull($body['selfName']);
        self::assertTrue(NoteGuestIdentity::isGuestId((int) $body['selfUserId']));

        $room = $this->presence()->on($note);
        self::assertCount(1, $room);
        self::assertTrue($room[0]['guest']);
        self::assertNull($room[0]['name']);
        self::assertSame((int) $body['selfUserId'], $room[0]['userId']);
    }

    /** The same browser is the same guest from one beat to the next. */
    public function testTheGuestKeepsTheSameIdentity(): void
    {
        $note = $this->note();
        $link = $this->link($note, canWrite: true, coediting: true);

        $first = $this->beat($link, $note);
        $second = $this->beat($link, $note);

        self::assertSame($first['selfUserId'], $second['selfUserId']);
        self::assertCount(1, $this->presence()->on($note));
    }

    /**
     * The page hands the identity out, before the first beat.
     *
     * The page beats twice as it starts; minted by the beat, the identity came
     * out twice and left a ghost guest in the room.
     */
    public function testThePageItselfHandsTheGuestItsIdentity(): void
    {
        $note = $this->note();
        $link = $this->link($note, canWrite: true, coediting: true);

        $this->client->request('GET', $this->urlGenerator->generate('notes_share', ['token' => $link->getToken()]));
        self::assertResponseIsSuccessful();
        $cookie = $this->client->getCookieJar()->get(NoteGuestIdentity::COOKIE_NAME, '/notes/share/'.$link->getToken());
        self::assertInstanceOf(Cookie::class, $cookie);

        $body = $this->beat($link, $note);

        self::assertSame((int) $cookie->getValue(), (int) $body['selfUserId']);
    }

    /**
     * Without a hub, a live link does not announce a live session.
     *
     * The test environment runs no hub, which is the case: the box is ticked,
     * nothing could carry the session, so the page is told to write the old
     * way - and the header no longer promises "live" at all, the page saying
     * it only once a session has really started.
     */
    public function testWithoutAHubTheLinkDoesNotAnnounceALiveSession(): void
    {
        $note = $this->note();
        $link = $this->link($note, canWrite: true, coediting: true);

        $this->client->request('GET', $this->urlGenerator->generate('notes_share', ['token' => $link->getToken()]));
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('&quot;canWrite&quot;:true', $html);
        self::assertStringContainsString('&quot;coediting&quot;:false', $html);

        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertStringNotContainsString($translator->trans('notes.markdown.share.coediting_heading'), $html);
    }

    /** A hand-written cookie cannot take an account's id. */
    public function testAnIdentityOutsideTheGuestRangeIsReplaced(): void
    {
        $note = $this->note();
        $link = $this->link($note, canWrite: true, coediting: true);
        $this->client->getCookieJar()->set(new Cookie(NoteGuestIdentity::COOKIE_NAME, (string) $this->owner->getId(), path: '/notes/share/'.$link->getToken()));

        $body = $this->beat($link, $note);

        self::assertNotSame($this->owner->getId(), $body['selfUserId']);
        self::assertTrue(NoteGuestIdentity::isGuestId((int) $body['selfUserId']));
    }

    /** A guest whose page says it leaves is out of the room at once. */
    public function testAGuestLeavingIsOutOfTheRoomAtOnce(): void
    {
        $note = $this->note();
        $link = $this->link($note, canWrite: true, coediting: true);

        $this->beat($link, $note);
        self::assertCount(1, $this->presence()->on($note));

        $this->beat($link, $note, ['leaving' => true]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->presence()->on($note));
    }

    /**
     * The owner joins the guests' room, on a personal note too.
     *
     * A personal space never allows co-editing by itself; a live link on one
     * of its notes does, or the guests would type in a room the owner could
     * not enter.
     */
    public function testTheOwnersBeatSaysAPersonalNoteIsCoeditableThroughALiveLink(): void
    {
        $note = $this->note();
        $this->link($note, canWrite: true);

        self::assertFalse($this->ownerBeat($note)['coediting']);

        $this->link($note, canWrite: true, coediting: true);

        self::assertTrue($this->ownerBeat($note)['coediting']);
    }

    /** The session's wider limit is only ever reachable on a live link. */
    public function testASessionWriteBackNeedsALiveLink(): void
    {
        $note = $this->note();
        $plain = $this->link($note, canWrite: true);
        $live = $this->link($note, canWrite: true, coediting: true);

        $this->save($plain, $note, ['content' => 'Par la session', 'coedit' => true]);
        self::assertResponseStatusCodeSame(404);

        $this->save($live, $note, ['content' => 'Par la session', 'coedit' => true]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertSame('Par la session', $this->entityManager->find(MarkdownNote::class, $note->getId())?->getContent());
    }

    /** The share screen creates the link with the box, and never on a reading link. */
    public function testTheShareScreenCreatesALiveLinkOnlyWhenItWrites(): void
    {
        $note = $this->note();
        $this->client->loginUser($this->owner, 'admin');

        $live = $this->createThroughTheScreen($note, ['canWrite' => true, 'coediting' => true]);
        $reading = $this->createThroughTheScreen($note, ['canWrite' => false, 'coediting' => true]);

        self::assertTrue($live['coediting']);
        self::assertFalse($reading['coediting']);
    }

    /** A version written with a guest names the guest, in the reader's language. */
    public function testTheHistoryNamesAGuestWhoWroteAlongside(): void
    {
        $note = $this->note();
        $owner = $this->entityManager->find(User::class, $this->owner->getId());
        self::assertInstanceOf(User::class, $owner);
        $this->presence()->beat($note, $owner, true);
        $this->presence()->beatAsGuest($note, NoteGuestIdentity::FLOOR + 7, true);

        $history = static::getContainer()->get(MarkdownNoteHistory::class);
        self::assertInstanceOf(MarkdownNoteHistory::class, $history);
        $revision = $history->keep($note, $owner);
        $this->created[] = [MarkdownNoteRevision::class, (int) $revision->getId()];

        self::assertSame([false, true], array_column($revision->getWrittenBy(), 'guest'));

        $this->client->loginUser($this->owner, 'admin');
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_revisions', ['id' => $note->getId()]));
        $body = json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];

        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertSame(
            [$owner->getName(), $translator->trans('notes.markdown.live.guest')],
            $body['revisions'][0]['writtenBy'],
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function beat(MarkdownNoteShareLinkInterface $link, MarkdownNote $note, array $payload = ['editing' => true]): array
    {
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('notes_share_live', ['token' => $link->getToken(), 'id' => $note->getId()]),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, mixed> */
    private function ownerBeat(MarkdownNote $note): array
    {
        $this->client->loginUser($this->owner, 'admin');
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('suite_notes_markdown_live_beat', ['id' => $note->getId()]),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['editing' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function save(MarkdownNoteShareLinkInterface $link, MarkdownNote $note, array $payload): array
    {
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('notes_share_save', ['token' => $link->getToken(), 'id' => $note->getId()]),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function createThroughTheScreen(MarkdownNote $note, array $options): array
    {
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('suite_notes_markdown_shares_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['noteId' => $note->getId()] + $options, JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $link = (json_decode((string) $this->client->getResponse()->getContent(), true) ?? [])['link'] ?? [];
        $this->created[] = [MarkdownNoteShareLink::class, (int) ($link['id'] ?? 0)];

        return $link;
    }

    private function presence(): NotePresence
    {
        $presence = static::getContainer()->get(NotePresence::class);
        self::assertInstanceOf(NotePresence::class, $presence);

        return $presence;
    }

    private function note(): MarkdownNote
    {
        $note = new MarkdownNote();
        $note->setUser($this->owner);
        $note->setSpace($this->personalSpaceOf($this->owner));
        $note->setTitle('Compte rendu');
        $note->setContent('Le texte');
        $this->entityManager->persist($note);
        $this->entityManager->flush();
        $this->created[] = [MarkdownNote::class, (int) $note->getId()];

        return $note;
    }

    private function link(MarkdownNote $note, bool $canWrite = false, bool $coediting = false, string $label = ''): MarkdownNoteShareLinkInterface
    {
        $link = new MarkdownNoteShareLink();
        $link->setNote($note);
        $link->setIncludeLinked(false);
        $link->setCanWrite($canWrite);
        $link->setCoediting($coediting);
        $link->setLabel($label);
        $this->entityManager->persist($link);
        $this->entityManager->flush();
        $this->created[] = [MarkdownNoteShareLink::class, (int) $link->getId()];

        return $link;
    }
}
