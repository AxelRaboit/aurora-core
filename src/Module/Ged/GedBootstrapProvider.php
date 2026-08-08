<?php

declare(strict_types=1);

namespace Aurora\Module\Ged;

use Aurora\Core\Bootstrap\BootstrapProviderInterface;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentCategory\Service\InlineUploadCategoryProvider;

use function sprintf;

/**
 * Seeds the category images uploaded from an editing form are filed under.
 *
 * Those images are content assets, not business documents. A project's own
 * categories describe its filing — contracts, invoices, HR — and dropping a
 * banner picture into any of them would be worse than not filing it at all.
 *
 * Seeding it here is a convenience, not the guarantee: the category shows up
 * in the list before anyone has uploaded anything, and an administrator can
 * rename it on a fresh install. The uploader creates it on demand if this
 * never ran, which is what covers a project upgrading without `aurora:install`
 * — see {@see InlineUploadCategoryProvider}.
 *
 * Priority 50, alongside Editorial: nothing here needs the locales core seeds
 * at 100, but staying below it keeps the ordering honest if that changes.
 */
final readonly class GedBootstrapProvider implements BootstrapProviderInterface
{
    public function __construct(
        private InlineUploadCategoryProvider $inlineUploadCategoryProvider,
    ) {}

    public function getPriority(): int
    {
        return 50;
    }

    public function bootstrap(): iterable
    {
        if ($this->inlineUploadCategoryProvider->find() instanceof DocumentCategoryInterface) {
            return;
        }

        $this->inlineUploadCategoryProvider->resolve();

        yield sprintf('catégorie %s', InlineUploadCategoryProvider::SLUG);
    }
}
