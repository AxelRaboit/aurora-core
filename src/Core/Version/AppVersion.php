<?php

declare(strict_types=1);

namespace Aurora\Core\Version;

use Aurora\Core\Enum\AppVersionEnum;
use Symfony\Component\Filesystem\Path;

/**
 * The version this installation runs: the tag written to `VERSION` by the
 * deployment, `dev` where there is none.
 *
 * Read on every call rather than once per process: the file changes under a
 * running PHP-FPM at each deployment, and the whole point of asking is to see
 * that change.
 */
final readonly class AppVersion
{
    public function __construct(private string $projectDirectory) {}

    public function current(): string
    {
        $versionFile = Path::join($this->projectDirectory, 'VERSION');
        if (!is_file($versionFile)) {
            return AppVersionEnum::Dev->value;
        }

        $version = mb_trim((string) file_get_contents($versionFile));

        return '' !== $version ? $version : AppVersionEnum::Dev->value;
    }
}
