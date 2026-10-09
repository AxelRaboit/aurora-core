<?php

declare(strict_types=1);

namespace Aurora\Core\Twig;

use Aurora\Core\Version\AppVersion;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

final class VersionExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private readonly AppVersion $appVersion) {}

    public function getGlobals(): array
    {
        return [
            'appVersion' => $this->appVersion->current(),
        ];
    }
}
