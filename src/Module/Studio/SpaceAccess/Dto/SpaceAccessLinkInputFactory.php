<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Dto;

use Aurora\Core\Support\Str;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManager;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function is_numeric;

#[AsAlias(SpaceAccessLinkInputFactoryInterface::class)]
class SpaceAccessLinkInputFactory implements SpaceAccessLinkInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): SpaceAccessLinkInputInterface
    {
        $days = $data['validForDays'] ?? null;

        return new SpaceAccessLinkInput(
            recipientEmail: Str::emailFromArray($data, 'recipientEmail'),
            label: Str::trimOrNullFromArray($data, 'label'),
            validForDays: is_numeric($days) ? (int) $days : SpaceAccessLinkManager::DEFAULT_VALID_DAYS,
            // Absent means true: a payload that says nothing about the right
            // is a form that did not offer the choice, not a refusal.
            canApprove: (bool) ($data['canApprove'] ?? true),
            canComment: (bool) ($data['canComment'] ?? true),
            // False when the form says nothing, unlike the two above: a right
            // that writes bytes to our storage is not one a missing field
            // grants.
            canChat: (bool) ($data['canChat'] ?? true),
            canUpload: (bool) ($data['canUpload'] ?? false),
            // Absent vaut vrai : seul un faux explicite retire le dossier.
            canSeeDrive: false !== ($data['canSeeDrive'] ?? true),
        );
    }
}
