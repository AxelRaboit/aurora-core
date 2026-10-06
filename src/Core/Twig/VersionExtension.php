<?php

declare(strict_types=1);

namespace Aurora\Core\Twig;

use Aurora\Core\Enum\AppVersionEnum;
use Symfony\Component\Filesystem\Path;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

final class VersionExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private readonly string $projectDirectory) {}

    public function getGlobals(): array
    {
        $versionFile = Path::join($this->projectDirectory, 'VERSION');

        return [
            'appVersion' => file_exists($versionFile) ? mb_trim(file_get_contents($versionFile)) : AppVersionEnum::Dev->value,
        ];
    }
}
