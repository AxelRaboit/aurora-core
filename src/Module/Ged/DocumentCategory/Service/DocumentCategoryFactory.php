<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentCategory\Service;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the categories Aurora creates for itself.
 *
 * One place so the slug, the name and the description of the inline-upload
 * category are written once: the bootstrap provider seeds it at install and the
 * uploader creates it on demand, and two constructors drifting apart would mean
 * two categories that look the same and are not.
 *
 * **The class comes from Doctrine, not from this file.** It used to be a literal
 * `new DocumentCategory()`, which is correct until a project substitutes its own
 * entity through `resolve_target_entities` - and then this factory and the
 * repository disagree about which table they are talking about. What that looked
 * like on aurora-client: the insert went to `core_ged_document_categories`, where
 * a `medias-editoriaux` row from before the substitution still sat, so it raised a
 * unique-constraint violation; the recovery path then looked the row up through
 * the repository, which reads the *substituted* table, found nothing, and threw.
 * `aurora:install` failed on a project whose only mistake was using the extension
 * point as documented.
 *
 * Resolved the same way {@see ResolveTargetEntityRepository} resolves it, so the
 * two agree by construction rather than by both being kept up to date. They do not
 * share the code because that base needs the class name before
 * `parent::__construct`, where an injected collaborator does not exist yet.
 */
final readonly class DocumentCategoryFactory
{
    public function __construct(
        private TranslatorInterface $translator,
        private ManagerRegistry $managerRegistry,
    ) {}

    public function createInlineUploadCategory(): DocumentCategoryInterface
    {
        $class = $this->entityClass();

        $category = new $class();

        return $category
            ->setSlug(InlineUploadCategoryProvider::SLUG)
            ->setName($this->translator->trans(InlineUploadCategoryProvider::NAME_KEY))
            ->setDescription($this->translator->trans(InlineUploadCategoryProvider::DESCRIPTION_KEY));
    }

    /**
     * Whichever concrete class is currently mapped for the contract.
     *
     * Falls back to Aurora's own when no manager is registered - the same
     * fallback the repository base takes, and for the same reason: a missing
     * manager is a misconfigured application, not a substitution.
     *
     * @return class-string<DocumentCategoryInterface>
     */
    private function entityClass(): string
    {
        $manager = $this->managerRegistry->getManagerForClass(DocumentCategory::class);

        if (!$manager instanceof ObjectManager) {
            return DocumentCategory::class;
        }

        /** @var class-string<DocumentCategoryInterface> $class */
        $class = $manager->getClassMetadata(DocumentCategoryInterface::class)->getName();

        return $class;
    }
}
