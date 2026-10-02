<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceDeliverable\Service;

use function is_array;
use function is_string;
use function mb_substr;
use function mb_trim;

/**
 * Ce que dit l'en-tête de la page d'un livrable.
 *
 * Le même trio que la lecture par lien d'une publication, pour que le gabarit
 * de lecture serve aux deux : pour qui le document a été préparé, sa date de
 * mise à jour, le logo et le nom du studio.
 */
final class DeliverableReadingHeader
{
    private const int PREPARED_FOR_MAX = 120;

    /** @return array{preparedFor: ?string, showDate: bool, showLogo: bool} */
    public static function normalize(mixed $raw): array
    {
        $data = is_array($raw) ? $raw : [];
        $preparedFor = is_string($data['preparedFor'] ?? null) ? mb_trim($data['preparedFor']) : '';

        return [
            'preparedFor' => '' === $preparedFor ? null : mb_substr($preparedFor, 0, self::PREPARED_FOR_MAX),
            'showDate' => false !== ($data['showDate'] ?? true),
            'showLogo' => false !== ($data['showLogo'] ?? true),
        ];
    }
}
