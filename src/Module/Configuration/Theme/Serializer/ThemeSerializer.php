<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Theme\Serializer;

use Aurora\Module\Configuration\Theme\Entity\ThemeInterface;
use Aurora\Module\Configuration\Theme\Manager\ThemeManagerInterface;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ThemeSerializerInterface::class)]
class ThemeSerializer implements ThemeSerializerInterface
{
    public function __construct(
        protected readonly ThemeManagerInterface $themeManager,
        // Optional so a serializer built by hand keeps working; the container
        // always provides it, and the themes screen needs it to show the
        // header logo the theme points at rather than a bare number.
        protected readonly ?ThemeContext $themeContext = null,
    ) {}

    /** @return array<string, mixed> */
    public function serialize(ThemeInterface $theme): array
    {
        return [
            'id' => $theme->getId(),
            'slug' => $theme->getSlug(),
            'name' => $theme->getName(),
            'description' => $theme->getDescription(),
            'active' => $theme->isActive(),
            'config' => $theme->getConfig(),
            'headerLogoUrl' => $this->themeContext?->headerLogoUrlFor($theme),
            'templateCount' => $this->themeManager->countTemplates($theme->getSlug()),
        ];
    }
}
