<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Service;

use function preg_match;

/**
 * A Drive folder's identifier, given bare or inside its address.
 *
 * Shared by the two places that ask for a folder: a space's settings, for
 * the client's folder, and the Drive settings, for the agency's. Pasting the
 * whole address is what people do, and both accept it the same way.
 */
final class DriveFolderId
{
    /**
     * What Google accepts as an identifier, and what a folder's address
     * holds. Checked so a wrong paste gives a readable refusal rather than an
     * empty listing nobody can explain.
     */
    private const string PATTERN = '/^[A-Za-z0-9_-]{10,128}$/';

    public static function from(string $given): ?string
    {
        if (1 === preg_match('#/folders/([A-Za-z0-9_-]+)#', $given, $match)) {
            return $match[1];
        }

        return 1 === preg_match(self::PATTERN, $given) ? $given : null;
    }
}
