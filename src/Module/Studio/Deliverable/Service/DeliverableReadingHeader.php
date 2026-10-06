<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Service;

use function is_array;
use function is_string;
use function mb_substr;
use function mb_trim;

/**
 * What a deliverable page's header says.
 *
 * The same trio as reading a publication through a link, so that the reading
 * template serves both: who the document was prepared for, its update date,
 * the studio's logo and name.
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
