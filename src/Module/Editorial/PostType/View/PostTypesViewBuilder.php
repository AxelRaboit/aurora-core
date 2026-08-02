<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\View;

use Aurora\Module\Editorial\PostType\Entity\AbstractPostType;
use Aurora\Module\Editorial\PostType\Entity\AbstractPostTypeField;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\PostType\Serializer\PostTypeSerializerInterface;

/**
 * Builds the Twig payload consumed by the admin post-types screen.
 */
final readonly class PostTypesViewBuilder
{
    public function __construct(
        private PostTypeRepository $postTypeRepository,
        private PostTypeSerializerInterface $postTypeSerializer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function indexView(): array
    {
        return [
            'postTypes' => array_map(
                $this->postTypeSerializer->serialize(...),
                $this->postTypeRepository->findAllWithRelations(),
            ),
            // The vocabularies live in PHP; handing them over keeps the Vue
            // form from carrying a second copy that can drift.
            'supportOptions' => AbstractPostType::SUPPORTS,
            'fieldTypes' => AbstractPostTypeField::TYPES,
        ];
    }
}
