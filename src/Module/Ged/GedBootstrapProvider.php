<?php

declare(strict_types=1);

namespace Aurora\Module\Ged;

use Aurora\Core\Bootstrap\BootstrapProviderInterface;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

/**
 * The one category GED cannot be installed without.
 *
 * Images uploaded from an editing form — a banner, a featured image, a custom
 * field — are content assets, not business documents. A project's own
 * categories describe its filing (contracts, invoices, HR), and dropping a
 * banner picture into any of them would be worse than not filing it at all.
 *
 * So they get a category of their own, seeded here rather than shipped as
 * demo data: every install has it, including one that never loads fixtures,
 * because the inline uploader has nowhere to put a file without it.
 *
 * Priority 50, alongside Editorial: nothing here needs the locales core seeds
 * at 100, but staying below it keeps the ordering honest if that changes.
 */
final readonly class GedBootstrapProvider implements BootstrapProviderInterface
{
    /**
     * The slug is the contract. Matched on it rather than on the name, which
     * an administrator is free to rename — and will, since it appears in their
     * own category list next to their own.
     */
    public const string INLINE_UPLOAD_CATEGORY = 'medias-editoriaux';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentCategoryRepository $documentCategoryRepository,
        private TranslatorInterface $translator,
    ) {}

    public function getPriority(): int
    {
        return 50;
    }

    public function bootstrap(): iterable
    {
        if ($this->documentCategoryRepository->findOneBy(['slug' => self::INLINE_UPLOAD_CATEGORY]) instanceof DocumentCategoryInterface) {
            return;
        }

        $this->entityManager->persist(
            new DocumentCategory()
                ->setSlug(self::INLINE_UPLOAD_CATEGORY)
                ->setName($this->translator->trans('backend.ged.bootstrap.categories.inline_uploads'))
                ->setDescription($this->translator->trans('backend.ged.bootstrap.categories.inline_uploads_description')),
        );

        $this->entityManager->flush();

        yield sprintf('catégorie %s', self::INLINE_UPLOAD_CATEGORY);
    }
}
