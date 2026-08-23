<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged\DocumentCategory\Service;

use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentCategory\Service\DocumentCategoryFactory;
use Aurora\Module\Ged\DocumentCategory\Service\InlineUploadCategoryProvider;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/** A project's own entity, substituted through `resolve_target_entities`. */
final class SubstitutedDocumentCategory extends DocumentCategory {}

/**
 * The factory has to build whichever class is mapped, not the one it can see.
 *
 * This is the entity-extensibility contract, and it was broken: the factory said
 * `new DocumentCategory()` while the repository resolved the contract through
 * Doctrine. On a project that substitutes its own entity the two then addressed
 * different tables - the insert went to Aurora's, hit a leftover row on the unique
 * slug, and the recovery path looked for it in the project's table and found
 * nothing. `aurora:install` failed on aurora-client for exactly that reason.
 *
 * A unit test with a stubbed registry, because the substitution cannot be
 * reproduced inside aurora-core: here the mapped class *is* Aurora's, so an
 * integration test would pass against the bug it is meant to catch.
 */
final class DocumentCategoryFactoryTest extends TestCase
{
    public function testItBuildsTheClassDoctrineHasMapped(): void
    {
        $category = $this->factory(SubstitutedDocumentCategory::class)->createInlineUploadCategory();

        self::assertInstanceOf(SubstitutedDocumentCategory::class, $category);
    }

    /** Aurora's own class when nothing has been substituted, which is the norm. */
    public function testItBuildsAuroraSOwnClassByDefault(): void
    {
        $category = $this->factory(DocumentCategory::class)->createInlineUploadCategory();

        self::assertInstanceOf(DocumentCategory::class, $category);
        self::assertNotInstanceOf(SubstitutedDocumentCategory::class, $category);
    }

    /**
     * A missing manager means a misconfigured application, not a substitution, so
     * it falls back rather than failing - the same choice the repository base
     * makes.
     */
    public function testItFallsBackWhenNoManagerIsRegistered(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);

        $factory = new DocumentCategoryFactory($this->translator(), $registry);

        self::assertInstanceOf(DocumentCategory::class, $factory->createInlineUploadCategory());
    }

    /** The identity it stamps, whichever class carries it. */
    public function testItStampsTheSlugThatIdentifiesTheCategory(): void
    {
        $category = $this->factory(SubstitutedDocumentCategory::class)->createInlineUploadCategory();

        self::assertSame(InlineUploadCategoryProvider::SLUG, $category->getSlug());
        self::assertSame(InlineUploadCategoryProvider::NAME_KEY, $category->getName());
    }

    /**
     * @param class-string<DocumentCategoryInterface> $mapped
     */
    private function factory(string $mapped): DocumentCategoryFactory
    {
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getName')->willReturn($mapped);

        $manager = $this->createStub(ObjectManager::class);
        $manager->method('getClassMetadata')->willReturn($metadata);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);

        return new DocumentCategoryFactory($this->translator(), $registry);
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        // Echoes the key, so the assertions name what was asked for rather than a
        // translation that could change.
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }
}
