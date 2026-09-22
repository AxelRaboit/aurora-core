<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Folder\Dto;

use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function is_numeric;

#[AsAlias(NoteFolderInputFactoryInterface::class)]
class NoteFolderInputFactory implements NoteFolderInputFactoryInterface
{
    public function fromArray(array $data): NoteFolderInputInterface
    {
        return new NoteFolderInput(
            name: Str::trimOrNullFromArray($data, 'name'),
            parentId: $this->intOrNull($data, 'parentId'),
            position: $this->intOrNull($data, 'position'),
        );
    }

    /**
     * An absent key and an empty string both mean "no value".
     *
     * The browser sends `parentId: ""` for the root, and casting that to an
     * integer would file the folder under id 0, which exists nowhere.
     *
     * @param array<string, mixed> $data
     */
    private function intOrNull(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if (null === $value || '' === $value || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
