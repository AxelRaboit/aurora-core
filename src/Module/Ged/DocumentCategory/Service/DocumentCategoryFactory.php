<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentCategory\Service;

use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the categories Aurora creates for itself.
 *
 * One place so the slug, the name and the description of the inline-upload
 * category are written once: the bootstrap provider seeds it at install and
 * the uploader creates it on demand, and two constructors drifting apart would
 * mean two categories that look the same and are not.
 */
final readonly class DocumentCategoryFactory
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {}

    public function createInlineUploadCategory(): DocumentCategoryInterface
    {
        return new DocumentCategory()
            ->setSlug(InlineUploadCategoryProvider::SLUG)
            ->setName($this->translator->trans(InlineUploadCategoryProvider::NAME_KEY))
            ->setDescription($this->translator->trans(InlineUploadCategoryProvider::DESCRIPTION_KEY));
    }
}
