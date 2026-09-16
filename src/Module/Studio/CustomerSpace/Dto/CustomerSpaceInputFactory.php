<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Dto;

use Aurora\Core\Support\Str;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function is_array;
use function is_numeric;

#[AsAlias(CustomerSpaceInputFactoryInterface::class)]
class CustomerSpaceInputFactory implements CustomerSpaceInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): CustomerSpaceInputInterface
    {
        return new CustomerSpaceInput(
            name: Str::trimFromArray($data, 'name'),
            description: Str::trimOrNullFromArray($data, 'description'),
            customerId: $this->idOrNull($data, 'customerId'),
            // An unknown status reads as active rather than as an error: the
            // field is a select fed from this same enum, so the only way to
            // send something else is to have edited the payload, and refusing
            // to save a space over it would help nobody.
            status: CustomerSpaceStatusEnum::tryFrom(Str::trimFromArray($data, 'status')) ?? CustomerSpaceStatusEnum::Active,
            colourSlot: $this->idOrNull($data, 'colourSlot'),
            timezone: Str::trimFromArray($data, 'timezone', 'Europe/Paris'),
            members: $this->members($data),
        );
    }

    /**
     * The membership rows, cleaned of everything that cannot be one.
     *
     * A row with no resolvable account is dropped here rather than reported: the
     * list is built by a picker, so a missing id means a half-filled row the
     * person has not finished, and refusing the whole save over it would lose
     * the rest of the form.
     *
     * The same account twice collapses to its last role, because the form lets
     * a row be added before an earlier one is removed.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{userId: int, role: string}>
     */
    private function members(array $data): array
    {
        $raw = $data['members'] ?? [];

        if (!is_array($raw)) {
            return [];
        }

        $byUser = [];

        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }

            $userId = $this->idOrNull($row, 'userId');
            if (null === $userId) {
                continue;
            }

            $role = CustomerSpaceMemberRoleEnum::tryFrom(Str::trimFromArray($row, 'role'))
                ?? CustomerSpaceMemberRoleEnum::Member;

            $byUser[$userId] = ['userId' => $userId, 'role' => $role->value];
        }

        return array_values($byUser);
    }

    /** @param array<string, mixed> $data */
    private function idOrNull(array $data, string $key): ?int
    {
        $raw = $data[$key] ?? null;

        return is_numeric($raw) ? (int) $raw : null;
    }
}
