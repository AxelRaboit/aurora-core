<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Notes\Folder\Entity\NoteFolder;
use Aurora\Module\Notes\Markdown\Service\NoteReadScope;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\Concern\CreatesTestUsers;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function count;
use function str_contains;

/**
 * "Shared with me" walks a shared tree a level at a time.
 *
 * It asked each folder for its children, leaves included: a shared branch of
 * thirty folders cost thirty queries, each time the notes screen opened.
 */
final class SharedWithMeQueriesTest extends IntegrationTestCase
{
    use CreatesTestUsers;

    private EntityManagerInterface $entityManager;

    private ?User $owner = null;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if ($this->owner instanceof User) {
            $owner = $this->entityManager->find(User::class, $this->owner->getId());
            if (null !== $owner) {
                $this->entityManager->remove($owner);
                $this->entityManager->flush();
            }
        }

        parent::tearDown();
    }

    public function testTheWalkCostsOneQueryPerLevelNotPerFolder(): void
    {
        $this->owner = $this->createTestUser('partageur');
        $root = $this->folder('Racine partagée', null);
        $root->setSharedAt(new DateTimeImmutable());

        foreach (['Un', 'Deux', 'Trois', 'Quatre'] as $name) {
            $child = $this->folder($name, $root);
            $this->folder($name.' bis', $child);
        }
        $this->entityManager->flush();

        $reader = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $reader);

        $this->entityManager->clear();
        $reader = $this->entityManager->find(User::class, $reader->getId());
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $shared = static::getContainer()->get(NoteReadScope::class)->sharedWith($reader);

        self::assertCount(9, array_filter($shared['folders'], fn ($folder): bool => $folder->getUser()->getId() === $this->owner->getId()));

        // Two levels below the root, plus the empty third that ends the walk.
        $walks = array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $query): bool => str_contains((string) $query['sql'], 'parent_id IN ('),
        );
        self::assertSame(3, count($walks), 'one query per level');
    }

    private function folder(string $name, ?NoteFolder $parent): NoteFolder
    {
        $folder = new NoteFolder();
        $folder->setUser($this->owner)->setParent($parent)->setName($name)->setPosition(0);
        $this->entityManager->persist($folder);

        return $folder;
    }
}
