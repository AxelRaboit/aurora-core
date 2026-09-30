<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Notes\Folder\Entity\NoteFolder;
use Aurora\Module\Notes\Markdown\Service\NoteReadScope;
use Aurora\Module\Notes\Space\Entity\NoteSpace;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Enum\NoteSpaceAccessEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\Concern\CreatesTestUsers;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function count;

/**
 * « Partagé avec moi » coûte le même nombre de requêtes quelle que soit la
 * taille de ce qui est partagé.
 *
 * L'ancien partage descendait l'arbre d'un dossier partagé niveau par niveau.
 * Un espace dit d'un coup ce qu'il contient : ce test tient que ça reste vrai,
 * et que ce qui entre dans un espace ne coûte rien de plus à l'ouverture.
 */
final class SharedWithMeQueriesTest extends IntegrationTestCase
{
    use CreatesTestUsers;

    private EntityManagerInterface $entityManager;

    private ?User $owner = null;

    private ?int $spaceId = null;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();

        $space = null === $this->spaceId ? null : $this->entityManager->find(NoteSpace::class, $this->spaceId);
        if (null !== $space) {
            $this->entityManager->remove($space);
            $this->entityManager->flush();
        }

        if ($this->owner instanceof User) {
            $owner = $this->entityManager->find(User::class, $this->owner->getId());
            if (null !== $owner) {
                $this->entityManager->remove($owner);
                $this->entityManager->flush();
            }
        }

        parent::tearDown();
    }

    public function testTheCostDoesNotGrowWithTheSharedTree(): void
    {
        $this->owner = $this->createTestUser('partageur');
        $space = new NoteSpace();
        $space->setOwner($this->owner)->setName('Équipe')->setAccess(NoteSpaceAccessEnum::Backoffice);
        $this->entityManager->persist($space);
        $this->entityManager->flush();
        $this->spaceId = (int) $space->getId();

        $this->branch($space, 2);
        $small = $this->measure();

        $this->branch($space, 8);
        $large = $this->measure();

        self::assertSame(10 * 2, $large['folders'], 'every folder of the space is shared');
        self::assertSame($small['queries'], $large['queries'], 'the same number of queries, whatever the size');
    }

    /** Ajoute des dossiers, chacun avec un sous-dossier. */
    private function branch(NoteSpaceInterface $space, int $count): void
    {
        // La mesure vide le suivi : on reprend l'espace et son propriétaire.
        $space = $this->entityManager->find(NoteSpace::class, $space->getId());
        self::assertInstanceOf(NoteSpace::class, $space);
        $this->owner = $this->entityManager->find(User::class, $this->owner?->getId());

        for ($i = 0; $i < $count; ++$i) {
            $parent = $this->folder($space, 'Dossier '.$i, null);
            $this->folder($space, 'Sous-dossier '.$i, $parent);
        }
        $this->entityManager->flush();
    }

    /** @return array{folders: int, queries: int} */
    private function measure(): array
    {
        $reader = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $reader);

        $this->entityManager->clear();
        $reader = $this->entityManager->find(User::class, $reader->getId());
        self::assertInstanceOf(User::class, $reader);

        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $shared = static::getContainer()->get(NoteReadScope::class)->sharedWith($reader);

        return [
            'folders' => count(array_filter($shared['folders'], fn ($folder): bool => $folder->getSpace()->getId() === $this->spaceId)),
            'queries' => count($holder->getData()['default'] ?? []),
        ];
    }

    private function folder(NoteSpaceInterface $space, string $name, ?NoteFolder $parent): NoteFolder
    {
        $folder = new NoteFolder();
        $folder->setUser($this->owner)->setSpace($space)->setParent($parent)->setName($name)->setPosition(0);
        $this->entityManager->persist($folder);

        return $folder;
    }
}
