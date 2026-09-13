<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged\DocumentFolder\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\DocumentFolder\Dto\DocumentFolderInputInterface;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolder;
use Aurora\Module\Ged\DocumentFolder\Manager\DocumentFolderManager;
use Aurora\Module\Ged\DocumentFolder\Repository\DocumentFolderRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DocumentFolderManagerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private DocumentFolderRepository $folderRepository;
    private DocumentRepository $documentRepository;
    private DocumentFolderManager $manager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->folderRepository = $this->createMock(DocumentFolderRepository::class);
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->manager = new DocumentFolderManager(
            $this->entityManager,
            $this->folderRepository,
            $this->documentRepository,
            $this->createStub(AuditLogger::class),
        );
    }

    private function makeInput(string $name, ?int $parentId = null): DocumentFolderInputInterface
    {
        $input = $this->createStub(DocumentFolderInputInterface::class);
        $input->method('getName')->willReturn($name);
        $input->method('getParentId')->willReturn($parentId);

        return $input;
    }

    private function captureFolder(mixed &$captured): void
    {
        $this->entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$captured): void {
                if ($entity instanceof DocumentFolder) {
                    $captured = $entity;
                }
            }
        );
    }

    public function testCreatePersistsFolderWithName(): void
    {
        $captured = null;
        $this->captureFolder($captured);

        $this->manager->create($this->makeInput('Contracts'));

        self::assertInstanceOf(DocumentFolder::class, $captured);
        self::assertSame('Contracts', $captured->getName());
    }

    public function testCreateWithNullParentSetsNoParent(): void
    {
        $captured = null;
        $this->captureFolder($captured);

        $this->manager->create($this->makeInput('Root', null));

        self::assertNull($captured->getParent());
    }

    public function testCreateWithParentIdResolvesParentFromRepository(): void
    {
        $parent = new DocumentFolder();
        $parent->setName('Parent');

        $this->folderRepository->method('find')->willReturn($parent);

        $captured = null;
        $this->captureFolder($captured);

        $this->manager->create($this->makeInput('Child', 5));

        self::assertSame($parent, $captured->getParent());
    }

    public function testCreateCallsPersistAndFlush(): void
    {
        $this->entityManager->expects(self::atLeastOnce())->method('persist');
        $this->entityManager->expects(self::atLeastOnce())->method('flush');

        $this->manager->create($this->makeInput('Folder'));
    }

    public function testUpdateAppliesNewName(): void
    {
        $folder = new DocumentFolder();
        $folder->setName('Old');

        $this->manager->update($folder, $this->makeInput('New'));

        self::assertSame('New', $folder->getName());
    }

    public function testUpdateCallsFlush(): void
    {
        $this->entityManager->expects(self::atLeastOnce())->method('flush');

        $this->manager->update(new DocumentFolder(), $this->makeInput('X'));
    }

    public function testDeleteTrashesTheFolderWithoutRemovingIt(): void
    {
        $folder = new DocumentFolder();
        $folder->setName('ToDelete');

        $this->entityManager->expects(self::never())->method('remove');
        $this->entityManager->expects(self::atLeastOnce())->method('flush');

        $this->manager->delete($folder);

        self::assertTrue($folder->isTrashed());
        self::assertNull($folder->getTrashedWithFolderId());
    }

    public function testDeleteWithoutCascadeReleasesTheChildrenToTheRoot(): void
    {
        $parent = new DocumentFolder();
        $parent->setName('Parent');
        $child = new DocumentFolder();
        $child->setName('Child')->setParent($parent);
        $parent->getChildren()->add($child);

        $this->manager->delete($parent, false);

        self::assertTrue($parent->isTrashed());
        self::assertFalse($child->isTrashed());
        self::assertNull($child->getParent());
    }

    public function testRestoreBringsBackWhatFellWithTheFolder(): void
    {
        $folder = new DocumentFolder();
        $folder->setName('Branch');
        $fallen = new DocumentFolder();
        $fallen->setName('Fallen')->setDeletedAt(new DateTimeImmutable())->setTrashedWithFolderId(0);

        $this->folderRepository->method('findTrashedWith')->willReturn([$fallen]);
        $this->documentRepository->method('findTrashedWith')->willReturn([]);

        $this->manager->delete($folder);
        $this->manager->restore($folder);

        self::assertFalse($folder->isTrashed());
        self::assertFalse($fallen->isTrashed());
        self::assertNull($fallen->getTrashedWithFolderId());
    }

    public function testForceDeleteReleasesWhatFellWithTheFolderInsteadOfDestroyingIt(): void
    {
        $folder = new DocumentFolder();
        $folder->setName('Gone');
        $fallen = new DocumentFolder();
        $fallen->setName('Fallen')->setParent($folder)->setDeletedAt(new DateTimeImmutable())->setTrashedWithFolderId(0);

        $this->folderRepository->method('findTrashedWith')->willReturn([$fallen]);
        $this->documentRepository->method('findTrashedWith')->willReturn([]);
        $this->entityManager->expects(self::once())->method('remove')->with($folder);

        $this->manager->forceDelete($folder);

        self::assertFalse($fallen->isTrashed());
        self::assertNull($fallen->getParent());
    }

    public function testMoveReparentsFolderToNewParent(): void
    {
        $folder = new DocumentFolder();
        $newParent = new DocumentFolder();

        $this->manager->move($folder, $newParent);

        self::assertSame($newParent, $folder->getParent());
    }

    public function testMoveToRootSetsNullParent(): void
    {
        $folder = new DocumentFolder();
        $parent = new DocumentFolder();
        $folder->setParent($parent);

        $this->manager->move($folder, null);

        self::assertNull($folder->getParent());
    }

    /**
     * The move that takes folders off every screen: filing one inside its own
     * child points the two at each other, so neither can be reached from a
     * root and every tree-building client stops drawing them - along with
     * everything beneath. The rows stay in the table, unreachable by any
     * interface, which is why this has to be refused rather than repaired.
     */
    public function testMoveRefusesToFileAFolderInsideItsOwnDescendant(): void
    {
        $parent = new DocumentFolder();
        $child = new DocumentFolder();
        $child->setParent($parent);
        $grandChild = new DocumentFolder();
        $grandChild->setParent($child);

        $this->entityManager->expects(self::never())->method('flush');

        self::assertFalse($this->manager->move($parent, $grandChild));
        self::assertNull($parent->getParent());
    }

    public function testMoveRefusesToFileAFolderInsideItself(): void
    {
        $folder = new DocumentFolder();

        self::assertFalse($this->manager->move($folder, $folder));
    }

    public function testMoveAllowsASiblingBranch(): void
    {
        $parent = new DocumentFolder();
        $child = new DocumentFolder();
        $child->setParent($parent);
        $elsewhere = new DocumentFolder();

        self::assertTrue($this->manager->move($child, $elsewhere));
        self::assertSame($elsewhere, $child->getParent());
    }

    public function testMoveCallsFlush(): void
    {
        $this->entityManager->expects(self::once())->method('flush');

        $this->manager->move(new DocumentFolder(), null);
    }

    /**
     * A folder with a known identifier, since reordering now looks folders up by id.
     */
    private function folderWithId(int $id): DocumentFolder
    {
        return new class($id) extends DocumentFolder {
            public function __construct(private readonly int $identifier) {}

            public function getId(): ?int
            {
                return $this->identifier;
            }
        };
    }

    public function testReorderAssignsPositionsInTheOrderGiven(): void
    {
        $folder1 = $this->folderWithId(10);
        $folder2 = $this->folderWithId(20);
        $folder3 = $this->folderWithId(30);

        // Returned out of order on purpose: the database has no reason to hand rows
        // back in the order the ids were listed, and the position must follow the
        // list the client sent, not the row order.
        $this->folderRepository->method('findBy')->willReturn([$folder3, $folder1, $folder2]);

        $this->manager->reorder([10, 20, 30]);

        self::assertSame(0, $folder1->getPosition());
        self::assertSame(1, $folder2->getPosition());
        self::assertSame(2, $folder3->getPosition());
    }

    /**
     * The whole list is fetched in a single query.
     *
     * Dragging a folder sends every sibling, so a per-id lookup meant one query per
     * row on each drop, on a screen people use by dragging repeatedly.
     */
    public function testReorderFetchesEveryFolderInOneQuery(): void
    {
        $this->folderRepository->expects(self::once())
            ->method('findBy')
            ->with(['id' => [10, 20, 30]])
            ->willReturn([]);

        $this->manager->reorder([10, 20, 30]);
    }

    public function testReorderSkipsUnresolvableIds(): void
    {
        $folder = $this->folderWithId(1);

        $this->folderRepository->method('findBy')->willReturn([$folder]);

        $this->manager->reorder([1, 99]);

        self::assertSame(0, $folder->getPosition());
    }

    public function testReorderCallsFlush(): void
    {
        $this->folderRepository->method('findBy')->willReturn([]);
        $this->entityManager->expects(self::once())->method('flush');

        $this->manager->reorder([]);
    }

    /**
     * The purge destroys, where `forceDelete` releases.
     *
     * This is the distinction the whole method exists for: `forceDelete` puts
     * a folder's contents back at the root because somebody asked to lose the
     * folder and not its documents. Reusing it here would clear `deletedAt` on
     * documents the purge is about to take, and they would walk back into the
     * library thirty days after being deleted.
     */
    public function testThePurgeDestroysFoldersWithoutTouchingTheirDocuments(): void
    {
        $cutoff = new DateTimeImmutable('-30 days');
        // Named, because the purge writes an audit line naming what it took.
        $folder = $this->folderWithId(4);
        $folder->setName('Archives 2024');

        $this->folderRepository->expects(self::once())
            ->method('findTrashedBefore')
            ->with($cutoff)
            ->willReturn([$folder]);

        // The releasing finders are the ones `forceDelete` uses. Never called.
        $this->folderRepository->expects(self::never())->method('findTrashedWith');
        $this->documentRepository->expects(self::never())->method('findTrashedWith');

        $this->entityManager->expects(self::once())->method('remove')->with($folder);
        $this->entityManager->expects(self::once())->method('flush');

        self::assertSame(1, $this->manager->purgeTrashedBefore($cutoff));
    }

    public function testThePurgeDoesNothingWhenTheTrashIsEmpty(): void
    {
        $this->folderRepository->method('findTrashedBefore')->willReturn([]);
        $this->entityManager->expects(self::never())->method('flush');

        self::assertSame(0, $this->manager->purgeTrashedBefore(new DateTimeImmutable()));
    }
}
