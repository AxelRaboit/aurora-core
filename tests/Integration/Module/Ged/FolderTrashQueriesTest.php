<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolder;
use Aurora\Module\Ged\DocumentFolder\Manager\DocumentFolderManagerInterface;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function array_reverse;
use function count;
use function str_contains;

/**
 * Trashing a folder walks its branch a level at a time, once.
 *
 * It asked every folder of the branch for its children, leaves included, and
 * did it twice: once for the folders, once more for the ids of the documents
 * under them.
 */
final class FolderTrashQueriesTest extends IntegrationTestCase
{
    /** @var list<DocumentFolder> */
    private array $created = [];

    protected function tearDown(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        foreach (array_reverse($this->created) as $folder) {
            $managed = $entityManager->find(DocumentFolder::class, $folder->getId());
            if (null !== $managed) {
                $entityManager->remove($managed);
            }
        }
        $entityManager->flush();

        parent::tearDown();
    }

    public function testTheBranchGoesWithOneQueryPerLevel(): void
    {
        static::bootKernel();
        $root = $this->folder('Racine', null);
        foreach (['Un', 'Deux', 'Trois'] as $name) {
            $child = $this->folder($name, $root);
            $this->folder($name.' bis', $child);
        }
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        static::getContainer()->get(DocumentFolderManagerInterface::class)->delete($root);

        foreach ($this->created as $folder) {
            self::assertTrue($folder->isTrashed(), $folder->getName().' went with the branch');
        }

        // Two levels below the root, plus the empty one that ends the walk.
        $walks = array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $query): bool => str_starts_with((string) $query['sql'], 'SELECT')
                && str_contains((string) $query['sql'], 'FROM core_ged_document_folders'),
        );
        self::assertSame(3, count($walks));
    }

    private function folder(string $name, ?DocumentFolder $parent): DocumentFolder
    {
        $folder = new DocumentFolder();
        $folder->setName($name)->setParent($parent)->setPosition(0);
        static::getContainer()->get(EntityManagerInterface::class)->persist($folder);
        $this->created[] = $folder;

        return $folder;
    }
}
