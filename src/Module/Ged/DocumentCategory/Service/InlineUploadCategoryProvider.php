<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentCategory\Service;

use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;

/**
 * The category images uploaded from an editing form are filed under, created
 * on demand if it is not there.
 *
 * The naming lives here; the finding, creating and race recovery live in
 * {@see DocumentCategoryResolver}, which a second module now shares. The
 * bootstrap provider still seeds this category so it shows up in the list
 * before anyone uploads anything - the on-demand creation is what makes the
 * upload independent of that step, not a replacement for it.
 */
final readonly class InlineUploadCategoryProvider
{
    public const string SLUG = 'medias-editoriaux';

    public const string NAME_KEY = 'backend.ged.bootstrap.categories.inline_uploads';

    public const string DESCRIPTION_KEY = 'backend.ged.bootstrap.categories.inline_uploads_description';

    public function __construct(private DocumentCategoryResolver $documentCategoryResolver) {}

    public function resolve(): DocumentCategoryInterface
    {
        return $this->documentCategoryResolver->resolve(self::SLUG, self::NAME_KEY, self::DESCRIPTION_KEY);
    }

    public function find(): ?DocumentCategoryInterface
    {
        return $this->documentCategoryResolver->find(self::SLUG);
    }
}
