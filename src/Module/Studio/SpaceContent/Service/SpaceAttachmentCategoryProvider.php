<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Service;

use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentCategory\Service\DocumentCategoryResolver;

/**
 * The category files that arrive through a client space are filed under.
 *
 * Its own, and not the editorial one: a photo a client sent for their February
 * carousel is not an editorial media, and the whole value of filing it is being
 * able to answer "everything that came in through a space" in GED's own
 * screens later. A shared category would make that question unanswerable.
 *
 * One category and not one per space, because a space is already the index -
 * an attachment row says which item, and the item says which space. A category
 * per client would be a second filing to keep in step with the first, and GED's
 * categories are a flat dimension meant to describe kinds of document, not to
 * enumerate customers.
 */
final readonly class SpaceAttachmentCategoryProvider
{
    public const string SLUG = 'espaces-clients';

    public const string NAME_KEY = 'backend.studio.space_content.attachments.category';

    public const string DESCRIPTION_KEY = 'backend.studio.space_content.attachments.category_description';

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
