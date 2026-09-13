<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Access;

use Aurora\Core\Storage\Access\UploadAccessEnum;
use Aurora\Core\Storage\Access\UploadAccessGuardInterface;
use Aurora\Core\Storage\Enum\StorageAreaEnum;

use function str_starts_with;

/**
 * Signed contracts are never served by the catch-all. Ever, to anybody.
 *
 * `ContractsController::storedPdf()` already explains why the module built
 * its own route under `/backend`: a signed contract is not the kind of file
 * `/uploads/{path}` should hand out. What that reasoning assumed, and what
 * was not true, is that the catch-all asked for a session at all. It did
 * not, and the path it answers on is guessable by construction -
 * `contracts/2026/CM-2026-0001.pdf`, a year and a sequential reference -
 * so the authorisation on the module's own route could be walked around by
 * anyone who had ever seen one contract number.
 *
 * Nothing builds such a URL: no generator in the codebase points at the
 * `contracts` area, and the two routes that serve a contract read its bytes
 * through `ContractPdfGenerator`. So this guard removes an address that was
 * only ever useful to somebody who should not have it.
 *
 * Denied rather than restricted, deliberately. Staff have a route that
 * streams the signed file with the right authorisation and the right
 * filename; a second way in that answers to a privilege this class would
 * have to guess is how the first one gets forgotten.
 */
final readonly class ContractUploadAccessGuard implements UploadAccessGuardInterface
{
    public function supports(string $key): bool
    {
        return str_starts_with($key, StorageAreaEnum::Contracts->value.'/');
    }

    public function decide(string $key): UploadAccessEnum
    {
        return UploadAccessEnum::Denied;
    }
}
