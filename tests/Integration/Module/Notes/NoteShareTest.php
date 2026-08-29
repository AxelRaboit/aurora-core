<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLink;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLinkInterface;
use Aurora\Module\Notes\Share\Service\SharedNoteScope;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Reading a note through a share link.
 *
 * The tests are about the boundary, because that is the part with a
 * consequence: the address is the credential, so what a holder can reach has to
 * be decided by the link and by nothing they can type.
 */
final class NoteShareTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    private User $owner;

    private User $other;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);

        $users = static::getContainer()->get(UserRepository::class);
        $owner = $users->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $owner);
        $this->owner = $owner;

        $other = $users->findOneBy(['type' => UserTypeEnum::Backend->value, 'email' => 'partage-notes@aurora.test']);
        if (!$other instanceof User) {
            $other = new User();
            $other->setEmail('partage-notes@aurora.test');
            $other->setName('Autre');
            $other->setType(UserTypeEnum::Backend);
            $other->setPassword('x');
            $other->setRoles($owner->getRoles());
            $this->entityManager->persist($other);
            $this->entityManager->flush();
            $this->created[] = [User::class, (int) $other->getId()];
        }
        $this->other = $other;
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

    public function testTheLinkOpensTheNoteWithNoAccount(): void
    {
        $note = $this->note('Publique', 'Du contenu partagé.');
        $link = $this->link($note);

        $this->client->request('GET', $this->shareUrl($link));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Publique', (string) $this->client->getResponse()->getContent());
    }

    /**
     * A shared page must never be indexed.
     *
     * The address is the whole credential; a crawler that stored it would have
     * handed the note to everybody, permanently, past any revocation.
     */
    public function testTheSharedPageAsksNotToBeIndexed(): void
    {
        $link = $this->link($this->note('Secrète'));

        $this->client->request('GET', $this->shareUrl($link));

        self::assertStringContainsString('noindex', (string) $this->client->getResponse()->getContent());
    }

    /** Every way of failing looks the same to whoever is asking. */
    public function testRevokedExpiredAndUnknownTokensAreIndistinguishable(): void
    {
        $revoked = $this->link($this->note('Révoquée'));
        $revoked->revoke(new DateTimeImmutable('-1 hour'));

        $expired = $this->link($this->note('Expirée'));
        $expired->setExpiresAt(new DateTimeImmutable('-1 day'));
        $this->entityManager->flush();

        $bodies = [];
        foreach ([$revoked->getToken(), $expired->getToken(), str_repeat('f', 64)] as $token) {
            $this->client->request('GET', $this->urlGenerator->generate('notes_share', ['token' => $token]));
            self::assertResponseStatusCodeSame(404);
            $bodies[] = (string) $this->client->getResponse()->getContent();
        }

        self::assertSame([$bodies[0]], array_unique($bodies), 'The three failures render differently.');
    }

    /**
     * The share carries one note unless it was told otherwise.
     *
     * This is the decision the checkbox exists for: publishing a note and
     * publishing the branch under it are different acts.
     */
    public function testAChildNoteIsOutOfScopeUnlessDescendantsAreIncluded(): void
    {
        $parent = $this->note('Parent');
        $child = $this->note('Enfant', parent: $parent);

        $link = $this->link($parent);

        $this->client->request('GET', $this->urlGenerator->generate(
            'notes_share_note',
            ['token' => $link->getToken(), 'id' => $child->getId()],
        ));
        self::assertResponseStatusCodeSame(404);

        $link->setIncludeDescendants(true);
        $this->entityManager->flush();

        $this->client->request('GET', $this->urlGenerator->generate(
            'notes_share_note',
            ['token' => $link->getToken(), 'id' => $child->getId()],
        ));
        self::assertResponseIsSuccessful();
    }

    /**
     * A guest cannot widen their own view by naming another id.
     *
     * The note they ask for has to already be in the link's scope; asking for
     * one that is not gives the same answer whether or not it exists.
     */
    public function testAnUnrelatedNoteIsNotReachableThroughTheToken(): void
    {
        $shared = $this->note('Partagée');
        $unrelated = $this->note('Sans rapport');

        $link = $this->link($shared, includeDescendants: true);

        $this->client->request('GET', $this->urlGenerator->generate(
            'notes_share_note',
            ['token' => $link->getToken(), 'id' => $unrelated->getId()],
        ));

        self::assertResponseStatusCodeSame(404);
    }

    /** The scope resolves the tree, and survives a parent cycle rather than hanging. */
    public function testTheScopeWalksTheTreeAndSurvivesACycle(): void
    {
        $scope = static::getContainer()->get(SharedNoteScope::class);

        $a = $this->note('A');
        $b = $this->note('B', parent: $a);
        $c = $this->note('C', parent: $b);

        $link = $this->link($a, includeDescendants: true);
        self::assertCount(3, $scope->notesFor($link));
        self::assertCount(2, $scope->preview($a, descendants: true, linked: false));

        // A cycle cannot be built through the UI; a hand-edited row could make
        // one, and an endless loop in a public route is a denial of service
        // handed to whoever holds the link.
        $a->setParent($c);
        $this->entityManager->flush();

        self::assertCount(3, $scope->notesFor($link));
    }

    /** Only the owner may open a share on a note. */
    public function testSomebodyElseCannotShareYourNote(): void
    {
        $note = $this->note('Pas la sienne');

        $this->client->loginUser($this->other, 'admin');
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('backend_notes_markdown_shares_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['noteId' => $note->getId()], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
    }

    /** Revoking somebody else's link is a 404, not a permission message. */
    public function testSomebodyElseCannotRevokeYourLink(): void
    {
        $link = $this->link($this->note('À moi'));

        $this->client->loginUser($this->other, 'admin');
        $this->client->request(
            'POST',
            $this->urlGenerator->generate('backend_notes_markdown_shares_revoke', ['id' => $link->getId()]),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        self::assertResponseStatusCodeSame(404);
        $this->entityManager->refresh($link);
        self::assertNull($link->getRevokedAt());
    }

    /**
     * A `[[link]]` does not widen a share on its own.
     *
     * This is the property the whole design rests on: somebody sharing one note
     * must not be publishing whatever that note happens to mention.
     */
    public function testALinkedNoteIsOutOfScopeUnlessLinksAreFollowed(): void
    {
        $target = $this->note('Test 1', 'Le contenu lié.');
        $source = $this->note('Racine', 'Voir [[Test 1]] pour la suite.');

        $link = $this->link($source);

        $this->client->request('GET', $this->urlGenerator->generate(
            'notes_share_note',
            ['token' => $link->getToken(), 'id' => $target->getId()],
        ));
        self::assertResponseStatusCodeSame(404);

        $link->setIncludeLinked(true);
        $this->entityManager->flush();

        $this->client->request('GET', $this->urlGenerator->generate(
            'notes_share_note',
            ['token' => $link->getToken(), 'id' => $target->getId()],
        ));
        self::assertResponseIsSuccessful();
    }

    /** Links are followed through, not just one hop. */
    public function testLinksAreFollowedTransitively(): void
    {
        $scope = static::getContainer()->get(SharedNoteScope::class);

        $third = $this->note('Trois');
        $second = $this->note('Deux', 'Puis [[Trois]].');
        $first = $this->note('Un', 'Voir [[Deux]].');

        self::assertCount(3, $scope->walk($first, descendants: false, linked: true));
    }

    /**
     * Two notes citing each other is ordinary writing, not corruption.
     *
     * An endless walk here would be a denial of service anybody could trigger
     * by writing notes the normal way.
     */
    public function testMutualLinksTerminate(): void
    {
        $scope = static::getContainer()->get(SharedNoteScope::class);

        $a = $this->note('Aller', 'Vers [[Retour]].');
        $b = $this->note('Retour', 'Vers [[Aller]].');

        self::assertCount(2, $scope->walk($a, descendants: false, linked: true));
        self::assertCount(2, $scope->walk($b, descendants: false, linked: true));
    }

    /** A link naming a note that does not exist adds nothing and breaks nothing. */
    public function testALinkToANoteThatDoesNotExistIsIgnored(): void
    {
        $scope = static::getContainer()->get(SharedNoteScope::class);

        $note = $this->note('Seule', 'Voir [[Cette note na jamais existe]].');

        self::assertCount(1, $scope->walk($note, descendants: false, linked: true));
    }

    /**
     * The preview lists what the switches would add, and never the note itself.
     *
     * It is the screen's only defence: a count cannot be checked against what
     * somebody meant to share, a title can.
     */
    public function testThePreviewListsWhatWouldBeAddedWithoutTheRoot(): void
    {
        $scope = static::getContainer()->get(SharedNoteScope::class);

        $this->note('Cible', 'Contenu.');
        $root = $this->note('Source', 'Voir [[Cible]].');

        $preview = $scope->preview($root, descendants: false, linked: true);

        self::assertCount(1, $preview);
        self::assertSame('Cible', $preview[0]['title']);
    }

    /** An anchor points inside a note, not at another one. */
    public function testAHeadingAnchorResolvesToTheNoteItself(): void
    {
        $scope = static::getContainer()->get(SharedNoteScope::class);

        $this->note('Cible', 'Contenu.');
        $root = $this->note('Source', 'Voir [[Cible#un-titre]].');

        $preview = $scope->preview($root, descendants: false, linked: true);

        self::assertSame(['Cible'], array_column($preview, 'title'));
    }

    /**
     * The happy path through the route, which nothing covered.
     *
     * Every other test here proves a refusal. The one thing the screen actually
     * does - fill the form and press the button - was only ever exercised by
     * building the entity directly, which skips the controller, the DTO, the
     * manager and the serializer.
     */
    public function testTheRouteCreatesALinkFromWhatTheFormSends(): void
    {
        $this->client->loginUser($this->owner, 'admin');

        // One request before persisting anything encrypted. `EncryptedTextType`
        // gets its service from a `kernel.request` subscriber, and Doctrine
        // types are static for the whole process - so a test that writes an
        // encrypted column before any request has been made throws, and only
        // the first such test in a run does. Ordering luck is not a thing to
        // rely on.
        $this->client->request('GET', $this->urlGenerator->generate('backend_notes_markdown'));

        $note = $this->note('À partager', 'Voir [[Autre]].');

        $this->client->request(
            'POST',
            $this->urlGenerator->generate('backend_notes_markdown_shares_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            // Exactly what the modal posts, empty strings included.
            content: json_encode([
                'noteId' => $note->getId(),
                'includeDescendants' => false,
                'includeLinked' => true,
                'recipientEmail' => '',
                'label' => 'test',
                'expiresAt' => '',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('link', $body);
        self::assertStringContainsString('/notes/share/', (string) $body['link']['url']);
        self::assertTrue($body['link']['includeLinked']);
        self::assertNull($body['link']['recipientEmail']);

        $this->created[] = [MarkdownNoteShareLink::class, (int) $body['link']['id']];
    }

    private function note(string $title, string $content = '', ?MarkdownNote $parent = null): MarkdownNote
    {
        $note = new MarkdownNote();
        $note->setUser($this->owner);
        $note->setTitle($title);
        $note->setContent($content);
        if (null !== $parent) {
            $note->setParent($parent);
        }
        $this->entityManager->persist($note);
        $this->entityManager->flush();
        $this->created[] = [MarkdownNote::class, (int) $note->getId()];

        return $note;
    }

    private function link(MarkdownNote $note, bool $includeDescendants = false): MarkdownNoteShareLinkInterface
    {
        $link = new MarkdownNoteShareLink();
        $link->setNote($note);
        $link->setIncludeDescendants($includeDescendants);
        $this->entityManager->persist($link);
        $this->entityManager->flush();
        $this->created[] = [MarkdownNoteShareLink::class, (int) $link->getId()];

        return $link;
    }

    private function shareUrl(MarkdownNoteShareLinkInterface $link): string
    {
        return $this->urlGenerator->generate('notes_share', ['token' => $link->getToken()]);
    }
}
