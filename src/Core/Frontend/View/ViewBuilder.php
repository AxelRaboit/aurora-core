<?php

declare(strict_types=1);

namespace Aurora\Core\Frontend\View;

use Aurora\Core\Frontend\Service\Context;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;

/**
 * Provides the base template variables required by the default front layout.
 * Inject this in any custom front controller or view builder.
 */
final readonly class ViewBuilder
{
    public function __construct(
        private Context $context,
        private ThemeContext $themeContext,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function baseView(string $locale, string $pageDescription = '', array $alternates = []): array
    {
        return [
            'locale' => $locale,
            'context' => $this->context,
            'themeContext' => $this->themeContext,
            'pageDescription' => $pageDescription ?: ($this->context->siteDescription() ?? ''),
            'alternates' => $alternates,
        ];
    }
}
