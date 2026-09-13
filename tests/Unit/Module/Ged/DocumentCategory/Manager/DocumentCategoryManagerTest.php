<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged\DocumentCategory\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Ged\DocumentCategory\Dto\DocumentCategoryInputInterface;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentCategory\Manager\DocumentCategoryManager;
use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DocumentCategoryManagerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private DocumentCategoryRepository $categoryRepository;
    private DocumentCategoryManager $manager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->categoryRepository = $this->createMock(DocumentCategoryRepository::class);
        $this->stubSlugCount(0);
        $this->manager = new DocumentCategoryManager(
            $this->entityManager,
            $this->categoryRepository,
            $this->createStub(AuditLogger::class),
        );
    }

    private function makeInput(string $name, ?string $description = null): DocumentCategoryInputInterface
    {
        $input = $this->createStub(DocumentCategoryInputInterface::class);
        $input->method('getName')->willReturn($name);
        $input->method('getDescription')->willReturn($description);

        return $input;
    }

    /**
     * Stubs categoryRepository->createQueryBuilder() to return $count from getSingleScalarResult().
     * Used to control slug uniqueness: 0 = no collision, 1 = collision.
     */
    private function stubSlugCount(int $count): void
    {
        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn($count);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->categoryRepository->method('createQueryBuilder')->willReturn($qb);
    }

    private function captureCategory(mixed &$captured): void
    {
        $this->entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$captured): void {
                if ($entity instanceof DocumentCategory) {
                    $captured = $entity;
                }
            }
        );
    }

    public function testCreatePersistsCategoryWithName(): void
    {
        $this->stubSlugCount(0);
        $captured = null;
        $this->captureCategory($captured);

        $this->manager->create($this->makeInput('Legal Contracts'));

        self::assertInstanceOf(DocumentCategory::class, $captured);
        self::assertSame('Legal Contracts', $captured->getName());
    }

    public function testCreateGeneratesSlugFromName(): void
    {
        $captured = null;
        $this->captureCategory($captured);

        $this->manager->create($this->makeInput('Legal Contracts'));

        self::assertSame('legal-contracts', $captured->getSlug());
    }

    public function testCreateWithAsciiSpecialCharsGeneratesSlug(): void
    {
        $captured = null;
        $this->captureCategory($captured);

        $this->manager->create($this->makeInput('Contrats & Accords'));

        self::assertSame('contrats-accords', $captured->getSlug());
    }

    public function testCreateWithDescriptionSetsDescription(): void
    {
        $captured = null;
        $this->captureCategory($captured);

        $this->manager->create($this->makeInput('HR', 'Human Resources'));

        self::assertSame('Human Resources', $captured->getDescription());
    }

    public function testCreateCallsPersistAndFlush(): void
    {
        $this->entityManager->expects(self::atLeastOnce())->method('persist');
        $this->entityManager->expects(self::atLeastOnce())->method('flush');

        $this->manager->create($this->makeInput('Finance'));
    }

    public function testCreateReturnsPersistedCategory(): void
    {
        $result = $this->manager->create($this->makeInput('HR'));

        self::assertInstanceOf(DocumentCategoryInterface::class, $result);
        self::assertSame('HR', $result->getName());
    }

    public function testUpdateAppliesInputToCategory(): void
    {
        $category = new DocumentCategory();
        $category->setName('Old')->setSlug('old')->setDescription('Before');

        $this->manager->update($category, $this->makeInput('New', 'After'));

        self::assertSame('New', $category->getName());
        self::assertSame('After', $category->getDescription());
    }

    public function testUpdateCallsFlush(): void
    {
        $category = new DocumentCategory();
        $category->setName('X')->setSlug('x');

        $this->entityManager->expects(self::atLeastOnce())->method('flush');

        $this->manager->update($category, $this->makeInput('Y'));
    }

    public function testDeleteTrashesTheCategoryAndLeavesItsSlugAlone(): void
    {
        $category = new DocumentCategory();
        $category->setName('Factures')->setSlug('factures');

        $this->entityManager->expects(self::never())->method('remove');
        $this->entityManager->expects(self::atLeastOnce())->method('flush');

        $this->manager->delete($category);

        self::assertTrue($category->isTrashed());
        // Readable in the trash, and free for anybody else: the unique index
        // only looks at the rows that are still in the list.
        self::assertSame('factures', $category->getSlug());
    }

    public function testRestoreKeepsTheSlugWhenNobodyTookIt(): void
    {
        $category = new DocumentCategory();
        $category->setName('Factures')->setSlug('factures');

        $this->manager->delete($category);
        $this->manager->restore($category);

        self::assertFalse($category->isTrashed());
        self::assertSame('factures', $category->getSlug());
    }

    public function testForceDeleteRemovesTheRow(): void
    {
        $category = new DocumentCategory();
        $category->setName('Gone')->setSlug('gone');

        $this->entityManager->expects(self::once())->method('remove')->with($category);

        $this->manager->forceDelete($category);
    }

    /**
     * The nightly purge, which is emptying the trash with a date instead of a
     * button. Worth its own test because the overview screen counts the days
     * down in front of the reader, and a promise nothing keeps is worse than
     * no promise.
     */
    public function testThePurgeDestroysWhatHasWaitedLongEnough(): void
    {
        $cutoff = new DateTimeImmutable('-30 days');
        $category = new DocumentCategory();
        $category->setName('Anciennes')->setSlug('anciennes');

        $this->categoryRepository->expects(self::once())
            ->method('findTrashedBefore')
            ->with($cutoff)
            ->willReturn([$category]);

        $this->entityManager->expects(self::once())->method('remove')->with($category);
        $this->entityManager->expects(self::once())->method('flush');

        self::assertSame(1, $this->manager->purgeTrashedBefore($cutoff));
    }

    public function testThePurgeDoesNothingWhenTheTrashIsEmpty(): void
    {
        $this->categoryRepository->method('findTrashedBefore')->willReturn([]);
        $this->entityManager->expects(self::never())->method('flush');

        self::assertSame(0, $this->manager->purgeTrashedBefore(new DateTimeImmutable()));
    }
}
