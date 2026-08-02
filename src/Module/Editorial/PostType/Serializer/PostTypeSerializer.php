<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Serializer;

use Aurora\Module\Editorial\PostType\Entity\PostTypeFieldInterface;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PostTypeSerializerInterface::class)]
class PostTypeSerializer implements PostTypeSerializerInterface
{
    public function serialize(PostTypeInterface $postType): array
    {
        return [
            'id' => $postType->getId(),
            'label' => $postType->getLabel(),
            'slug' => $postType->getSlug(),
            'icon' => $postType->getIcon(),
            'hasArchive' => $postType->hasArchive(),
            'isBuiltIn' => $postType->isBuiltIn(),
            'supports' => $postType->getSupports(),
            'fields' => array_map(
                $this->serializeField(...),
                $postType->getFields()->toArray(),
            ),
        ];
    }

    /** @return array<string, mixed> */
    protected function serializeField(PostTypeFieldInterface $field): array
    {
        return [
            'id' => $field->getId(),
            'name' => $field->getName(),
            'label' => $field->getLabel(),
            'type' => $field->getType(),
            'required' => $field->isRequired(),
            'translatable' => $field->isTranslatable(),
            'options' => $field->getOptions(),
            'position' => $field->getPosition(),
        ];
    }
}
