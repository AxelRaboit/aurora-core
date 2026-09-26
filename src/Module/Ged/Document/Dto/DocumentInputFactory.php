<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Dto;

use Aurora\Core\Support\Str;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(DocumentInputFactoryInterface::class)]
class DocumentInputFactory implements DocumentInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): DocumentInputInterface
    {
        $tagIds = array_map(intval(...), (array) ($data['tagIds'] ?? []));

        return new DocumentInput(
            title: Str::trimFromArray($data, 'title'),
            description: Str::trimOrNullFromArray($data, 'description'),
            status: DocumentStatusEnum::tryFrom($data['status'] ?? '') ?? DocumentStatusEnum::Draft,
            categoryId: isset($data['categoryId']) ? (int) $data['categoryId'] : null,
            filePath: Str::trimOrNullFromArray($data, 'filePath'),
            fileName: Str::trimOrNullFromArray($data, 'fileName'),
            originalName: Str::trimOrNullFromArray($data, 'originalName'),
            mimeType: Str::trimOrNullFromArray($data, 'mimeType'),
            size: isset($data['size']) ? (int) $data['size'] : null,
            width: isset($data['width']) ? (int) $data['width'] : null,
            height: isset($data['height']) ? (int) $data['height'] : null,
            thumbnailPath: Str::trimOrNullFromArray($data, 'thumbnailPath'),
            alt: Str::trimOrNullFromArray($data, 'alt'),
            caption: Str::trimOrNullFromArray($data, 'caption'),
            tagIds: $tagIds,
            folderId: isset($data['folderId']) ? (int) $data['folderId'] : null,
            focalX: isset($data['focalX']) ? (float) $data['focalX'] : null,
            focalY: isset($data['focalY']) ? (float) $data['focalY'] : null,
            sourceUrl: Str::trimOrNullFromArray($data, 'sourceUrl'),
            attributionName: Str::trimOrNullFromArray($data, 'attributionName'),
            attributionUrl: Str::trimOrNullFromArray($data, 'attributionUrl'),
            kept: filter_var($data['kept'] ?? false, FILTER_VALIDATE_BOOLEAN),
            originalId: isset($data['originalId']) && '' !== $data['originalId'] ? (int) $data['originalId'] : null,
            alternateLabel: Str::trimOrNullFromArray($data, 'alternateLabel'),
        );
    }
}
