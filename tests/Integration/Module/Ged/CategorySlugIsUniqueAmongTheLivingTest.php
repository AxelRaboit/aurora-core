<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Module\Ged\DocumentCategory\Dto\DocumentCategoryInputFactoryInterface;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Manager\DocumentCategoryManagerInterface;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The partial unique index, against a real PostgreSQL.
 *
 * The whole point of the trash here is that a category waiting in it holds no
 * name hostage, and that promise lives in one `WHERE deleted_at IS NULL` on an
 * index. A unit test cannot see it: only the database enforces it, and putting
 * `unique: true` back on the column would silently take it away.
 */
final class CategorySlugIsUniqueAmongTheLivingTest extends IntegrationTestCase
{
    public function testANameHeldByATrashedCategoryCanBeUsedAgain(): void
    {
        static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $manager = $container->get(DocumentCategoryManagerInterface::class);

        $slug = 'factures-'.uniqid();

        $first = (new DocumentCategory())->setName('Factures')->setSlug($slug);
        $entityManager->persist($first);
        $entityManager->flush();

        $manager->delete($first);

        // Same slug, while the first one waits in the trash under it.
        $second = (new DocumentCategory())->setName('Factures')->setSlug($slug);
        $entityManager->persist($second);
        $entityManager->flush();

        self::assertSame($slug, $first->getSlug(), 'the trashed one keeps its readable slug');
        self::assertSame($slug, $second->getSlug());
        self::assertNotSame($first->getId(), $second->getId());
    }

    public function testTwoTrashedCategoriesMayShareASlug(): void
    {
        static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $manager = $container->get(DocumentCategoryManagerInterface::class);

        $slug = 'archives-'.uniqid();

        $first = (new DocumentCategory())->setName('Archives')->setSlug($slug);
        $entityManager->persist($first);
        $entityManager->flush();
        $manager->delete($first);

        $second = (new DocumentCategory())->setName('Archives')->setSlug($slug);
        $entityManager->persist($second);
        $entityManager->flush();
        $manager->delete($second);

        self::assertTrue($first->isTrashed());
        self::assertTrue($second->isTrashed());
    }

    public function testRestoringYieldsAFreeSlugWhenTheNameWasTaken(): void
    {
        static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $manager = $container->get(DocumentCategoryManagerInterface::class);

        $name = 'Contrats '.uniqid();
        $slug = mb_strtolower(str_replace(' ', '-', $name));

        $trashed = (new DocumentCategory())->setName($name)->setSlug($slug);
        $entityManager->persist($trashed);
        $entityManager->flush();
        $manager->delete($trashed);

        $newcomer = (new DocumentCategory())->setName($name)->setSlug($slug);
        $entityManager->persist($newcomer);
        $entityManager->flush();

        $manager->restore($trashed);

        self::assertFalse($trashed->isTrashed());
        self::assertNotSame($slug, $trashed->getSlug(), 'the newcomer keeps the name it took');
        self::assertStringStartsWith($slug, $trashed->getSlug());
    }

    /**
     * Renaming keeps the identifier. Other parts of the application find a
     * category through it: "Médias éditoriaux", once renamed, was recreated
     * under the old name on the next image upload.
     */
    public function testRenamingKeepsTheSlug(): void
    {
        static::createClient();
        $container = static::getContainer();
        $manager = $container->get(DocumentCategoryManagerInterface::class);
        $inputs = $container->get(DocumentCategoryInputFactoryInterface::class);

        $category = $manager->create($inputs->fromArray(['name' => 'Rubrique '.uniqid()]));
        $slug = $category->getSlug();

        $manager->update($category, $inputs->fromArray(['name' => 'Tout autre nom']));

        self::assertSame($slug, $category->getSlug());
        self::assertSame('Tout autre nom', $category->getName());

        $manager->forceDelete($category);
    }
}
