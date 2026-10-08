<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Notes\Folder\Entity\NoteFolder;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Manager\MarkdownNoteManagerInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpace;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_column;
use function array_filter;
use function array_values;
use function bin2hex;
use function count;
use function in_array;
use function random_bytes;
use function sprintf;

/**
 * The graph draws families: a note takes the colour of the nearest folder up
 * its tree that has one, else its space's, else none, and says which space it
 * lives in so the screen can show one space at a time.
 */
final class NoteGraphTest extends IntegrationTestCase
{
    use PersonalSpaceTrait;

    private EntityManagerInterface $entityManager;

    private MarkdownNoteManagerInterface $manager;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->manager = static::getContainer()->get(MarkdownNoteManagerInterface::class);

        // Not an administrator: an administrator also reads the orphaned
        // spaces of the whole instance, and the graph would show them.
        $this->user = new User();
        $this->user->setEmail(sprintf('graphe-%s@aurora.test', bin2hex(random_bytes(4))))
            ->setName('graphe')
            ->setType(UserTypeEnum::Suite)
            ->setRoles([UserRoleEnum::User->value])
            ->setPrivileges(['notes.markdown.use'])
            ->setPassword('x');
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();

        foreach ($this->entityManager->getRepository(NoteSpace::class)->findBy(['owner' => $this->user->getId(), 'personalUser' => null]) as $space) {
            $this->entityManager->remove($space);
        }
        $this->entityManager->flush();

        $user = $this->entityManager->find(User::class, $this->user->getId());
        if (null !== $user) {
            $this->entityManager->remove($user);
            $this->entityManager->flush();
        }

        parent::tearDown();
    }

    public function testEachNoteTakesTheColourOfItsNearestColouredFolderThenOfItsSpace(): void
    {
        $team = $this->space('Équipe', '#22aa55');
        $personal = $this->personalSpaceOf($this->user);

        $coloured = $this->folder('Clients', $team, '#ff0000');
        $plainChild = $this->folder('Archives', $team, null, $coloured);
        $plainGrandChild = $this->folder('2025', $team, null, $plainChild);
        $recoloured = $this->folder('Urgent', $team, '#0000ff', $plainChild);
        $plainRoot = $this->folder('Divers', $team);
        $personalFolder = $this->folder('Brouillons', $personal);

        $inColoured = $this->note('Dans le dossier', $team, $coloured, '[[Petit-enfant]]');
        $inGrandChild = $this->note('Petit-enfant', $team, $plainGrandChild);
        $inRecoloured = $this->note('Recoloré', $team, $recoloured);
        $inPlainRoot = $this->note('Sans couleur de dossier', $team, $plainRoot);
        $atTeamRoot = $this->note('À la racine', $team);
        $personalNote = $this->note('Journal', $personal, $personalFolder, '[[Dans le dossier]]');

        $graph = $this->manager->graph($this->entityManager->find(User::class, $this->user->getId()));
        $nodes = array_column($graph['nodes'], null, 'id');

        self::assertSame('#ff0000', $nodes[$inColoured->getId()]['color'], 'its own folder');
        self::assertSame('#ff0000', $nodes[$inGrandChild->getId()]['color'], 'two folders up');
        self::assertSame('#0000ff', $nodes[$inRecoloured->getId()]['color'], 'the nearest wins over a farther one');
        self::assertSame('#22aa55', $nodes[$inPlainRoot->getId()]['color'], 'no folder colour: the space');
        self::assertSame('#22aa55', $nodes[$atTeamRoot->getId()]['color'], 'at the root: the space');
        self::assertNull($nodes[$personalNote->getId()]['color'], 'nothing coloured: the screen decides');

        self::assertSame($team->getId(), $nodes[$inGrandChild->getId()]['spaceId']);
        self::assertSame($personal->getId(), $nodes[$personalNote->getId()]['spaceId']);
        self::assertSame('Petit-enfant', $nodes[$inGrandChild->getId()]['title'], 'the title is still there');

        // The edges did not change.
        self::assertContains(['source' => $inColoured->getId(), 'target' => $inGrandChild->getId()], $graph['edges']);
        self::assertContains(['source' => $personalNote->getId(), 'target' => $inColoured->getId()], $graph['edges']);

        $spaces = array_column($graph['spaces'], null, 'id');
        self::assertSame(['id' => $team->getId(), 'name' => 'Équipe', 'color' => '#22aa55', 'personal' => false], $spaces[$team->getId()]);
        self::assertTrue($spaces[$personal->getId()]['personal']);

        // The personal space comes first, as in the panel.
        $ours = array_values(array_filter(
            array_column($graph['spaces'], 'id'),
            static fn (int $id): bool => in_array($id, [$team->getId(), $personal->getId()], true),
        ));
        self::assertSame([$personal->getId(), $team->getId()], $ours);
    }

    public function testASpaceWithoutNotesIsNotListed(): void
    {
        $empty = $this->space('Vide', '#123456');
        $this->note('Seule note', $this->personalSpaceOf($this->user));

        $graph = $this->manager->graph($this->entityManager->find(User::class, $this->user->getId()));

        self::assertNotContains($empty->getId(), array_column($graph['spaces'], 'id'));
    }

    /**
     * Climbing the tree must not ask the database once per level and per
     * note: deeper folders and more notes, the same number of queries.
     */
    public function testTheGraphDoesNotQueryPerFolderNorPerNote(): void
    {
        $team = $this->space('Profond', null);
        $top = $this->folder('Haut', $team, '#ff0000');
        $middle = $this->folder('Milieu', $team, null, $top);
        $this->note('Une', $team, $middle);

        $few = $this->queriesForGraph();

        $bottom = $this->folder('Bas', $team, null, $middle);
        $deeper = $this->folder('Plus bas', $team, null, $bottom);
        for ($noteNumber = 1; $noteNumber <= 5; ++$noteNumber) {
            $this->note('Note '.$noteNumber, $team, $deeper);
        }

        self::assertSame($few, $this->queriesForGraph());
    }

    private function queriesForGraph(): int
    {
        $this->entityManager->clear();
        $user = $this->entityManager->find(User::class, $this->user->getId());

        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();
        $graph = $this->manager->graph($user);
        self::assertNotEmpty($graph['nodes']);

        return count($holder->getData()['default'] ?? []);
    }

    private function space(string $name, ?string $color): NoteSpaceInterface
    {
        $space = new NoteSpace();
        $space->setOwner($this->managed($this->user))->setName($name)->setColor($color);
        $this->entityManager->persist($space);
        $this->entityManager->flush();

        return $space;
    }

    private function folder(string $name, NoteSpaceInterface $space, ?string $color = null, ?NoteFolder $parent = null): NoteFolder
    {
        $folder = new NoteFolder();
        $folder->setUser($this->managed($this->user))
            ->setSpace($this->managed($space))
            ->setName($name)
            ->setColor($color)
            ->setParent(null === $parent ? null : $this->managed($parent));
        $this->entityManager->persist($folder);
        $this->entityManager->flush();

        return $folder;
    }

    private function note(string $title, NoteSpaceInterface $space, ?NoteFolder $folder = null, string $content = ''): MarkdownNote
    {
        $note = new MarkdownNote();
        $note->setUser($this->managed($this->user))
            ->setSpace($this->managed($space))
            ->setTitle($title)
            ->setContent($content)
            ->setFolder(null === $folder ? null : $this->managed($folder));
        $this->entityManager->persist($note);
        $this->entityManager->flush();

        return $note;
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function managed(object $entity): object
    {
        return $this->entityManager->getReference($entity::class, $entity->getId());
    }
}
