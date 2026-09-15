<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Dto;

use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function is_numeric;

#[AsAlias(SpaceContentItemInputFactoryInterface::class)]
class SpaceContentItemInputFactory implements SpaceContentItemInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): SpaceContentItemInputInterface
    {
        return new SpaceContentItemInput(
            title: Str::trimFromArray($data, 'title'),
            body: Str::trimOrNullFromArray($data, 'body'),
            columnId: $this->idOrNull($data, 'columnId'),
            scheduledAt: Str::trimOrNullFromArray($data, 'scheduledAt'),
        );
    }

    /** @param array<string, mixed> $data */
    private function idOrNull(array $data, string $key): ?int
    {
        $raw = $data[$key] ?? null;

        return is_numeric($raw) ? (int) $raw : null;
    }
}
