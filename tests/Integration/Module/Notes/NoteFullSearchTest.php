<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Notes\Comment\Entity\NoteComment;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** The notes search (10/10/2026): what it finds, in which order, and what it shows. */
final class NoteFullSearchTest extends IntegrationTestCase
{
    use PersonalSpaceTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    private User $owner;

    /** A word no demo note carries, so the results are this test's only. */
    private string $marker;

    /** @var list<int> */
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
        $this->marker = 'zorglub'.bin2hex(random_bytes(3));
        $this->client->loginUser($owner, 'admin');
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();
        foreach ($this->created as $id) {
            $note = $this->entityManager->find(MarkdownNote::class, $id);
            if (null !== $note) {
                $this->entityManager->remove($note);
            }
        }
        $this->entityManager->flush();

        parent::tearDown();
    }

    public function testTheTitleComesFirstAndAccentsAreIgnored(): void
    {
        $this->note('Échéancier '.$this->marker, 'Rien de plus.');
        $this->note('Compte rendu', "Le prochain échéancier {$this->marker} est en mars.");

        $body = $this->search('echeancier '.$this->marker);

        self::assertSame(['Échéancier '.$this->marker, 'Compte rendu'], array_column($body['results'], 'title'));
        $snippet = $body['results'][1]['snippets'][0];
        self::assertSame('content', $snippet['field']);
        self::assertSame('échéancier', mb_substr($snippet['text'], $snippet['ranges'][0][0], $snippet['ranges'][0][1]));
    }

    public function testEveryWordIsRequiredAPhraseIsExactAndAnExclusionExcludes(): void
    {
        $this->note('Un', "budget du chantier {$this->marker}");
        $this->note('Deux', "chantier {$this->marker}, le budget viendra");
        $this->note('Trois', "budget seulement {$this->marker}");
        $this->note('Quatre', "budget du chantier {$this->marker} brouillon");

        self::assertEqualsCanonicalizing(['Un', 'Deux', 'Quatre'], array_column($this->search("budget chantier {$this->marker}")['results'], 'title'));
        self::assertEqualsCanonicalizing(['Un', 'Quatre'], array_column($this->search("\"budget du chantier\" {$this->marker}")['results'], 'title'));
        self::assertSame(['Un'], array_column($this->search("\"budget du chantier\" {$this->marker} -brouillon")['results'], 'title'));
    }

    public function testFiltersByTagPropertyTaskAndComment(): void
    {
        $tagged = $this->note('Fiche client', "Le {$this->marker}", ['client']);
        $tagged->setProperties([['key' => 'Statut', 'type' => 'status', 'value' => 'En cours']]);
        $withTask = $this->note('Tâches', "- [ ] Appeler {$this->marker}\n- [x] Écrire");
        $commented = $this->note('Commentée', "Texte {$this->marker}");
        $comment = new NoteComment();
        $comment->setNote($commented)->setAuthor($this->owner)->setBody('Le devis est validé');
        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        self::assertSame(['Fiche client'], array_column($this->search("{$this->marker} tag:client")['results'], 'title'));
        self::assertSame(['Fiche client'], array_column($this->search("{$this->marker} statut:\"en cours\"")['results'], 'title'));
        self::assertSame(['Tâches'], array_column($this->search("{$this->marker} tâche:à-faire")['results'], 'title'));
        self::assertSame(['Commentée'], array_column($this->search("{$this->marker} a:commentaire")['results'], 'title'));
        $found = $this->search("{$this->marker} devis valide")['results'];
        self::assertSame(['Commentée'], array_column($found, 'title'));
        self::assertContains('comment', array_column($found[0]['snippets'], 'field'));
        self::assertSame(['client' => 1], $this->search("{$this->marker} tag:client")['facets']['tags']);
    }

    public function testTheTrashAndOtherPeoplesNotesStayOut(): void
    {
        $trashed = $this->note('Jetée', "Le {$this->marker}");
        $trashed->setDeletedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        self::assertSame(0, $this->search($this->marker)['total']);
    }

    public function testTheSidePanelGetsTheIdsAndAPassage(): void
    {
        $note = $this->note('Panneau', "Ligne avant.\nLe mot {$this->marker} ici.");

        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_search', ['q' => $this->marker]), server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame([$note->getId()], $body['ids']);
        self::assertStringContainsString($this->marker, $body['snippets'][$note->getId()]);
    }

    public function testReplacingAcrossNotesCountsFirstThenWritesAndLeavesLockedNotesAlone(): void
    {
        $first = $this->note('Un', "L'échéance {$this->marker}, puis l'Echeance.");
        $second = $this->note('Deux', "Échéance {$this->marker}");
        $locked = $this->note('Trois', "échéance {$this->marker}");
        $locked->setLocked(true);
        $this->entityManager->flush();
        $ids = [$first->getId(), $second->getId(), $locked->getId()];

        $preview = $this->replace(['ids' => $ids, 'find' => 'echeance', 'replacement' => 'date', 'dryRun' => true]);
        self::assertSame(3, $preview['occurrences']);
        self::assertSame(1, $preview['skipped']);
        $this->entityManager->clear();
        self::assertStringContainsString('échéance', (string) $this->entityManager->find(MarkdownNote::class, $first->getId())?->getContent());

        $done = $this->replace(['ids' => $ids, 'find' => 'echeance', 'replacement' => 'date']);
        self::assertSame(3, $done['occurrences']);
        $this->entityManager->clear();
        self::assertSame("L'date {$this->marker}, puis l'date.", $this->entityManager->find(MarkdownNote::class, $first->getId())?->getContent());
        self::assertSame("échéance {$this->marker}", $this->entityManager->find(MarkdownNote::class, $locked->getId())?->getContent());

        $exact = $this->replace(['ids' => $ids, 'find' => 'Date', 'replacement' => 'jour', 'exact' => true, 'dryRun' => true]);
        self::assertSame(0, $exact['occurrences']);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function replace(array $payload): array
    {
        $this->client->request('POST', $this->urlGenerator->generate('suite_notes_markdown_search_replace'), server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'CONTENT_TYPE' => 'application/json'], content: (string) json_encode($payload));
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array<string, mixed> */
    private function search(string $query): array
    {
        $this->client->request('GET', $this->urlGenerator->generate('suite_notes_markdown_search_full', ['q' => $query]), server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @param list<string> $tags */
    private function note(string $title, string $content, array $tags = []): MarkdownNote
    {
        $note = new MarkdownNote();
        $note->setUser($this->owner);
        $note->setSpace($this->personalSpaceOf($this->owner));
        $note->setTitle($title);
        $note->setContent($content);
        $note->setTags($tags);
        $this->entityManager->persist($note);
        $this->entityManager->flush();
        $this->created[] = (int) $note->getId();

        return $note;
    }
}
