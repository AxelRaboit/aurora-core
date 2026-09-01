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
    /**
     * The post type a bare `/post-types` should send the reader to, or null
     * when there is nothing to send them to.
     *
     * Asked of the builder rather than of a repository injected into the
     * controller: the builder already owns the ordering the menu and the page
     * both show, and "first" has to mean the same thing in all three.
     */
    public function firstId(): ?int
    {
        $postTypes = $this->postTypeRepository->findAllWithRelations();

        return [] === $postTypes ? null : $postTypes[0]->getId();
    }

    /**
     * @param ?int $activeId the post type the address names, null when there
     *                       are none to name
     */
    public function indexView(?int $activeId = null): array
    {
        return [
            'activeId' => $activeId,
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
