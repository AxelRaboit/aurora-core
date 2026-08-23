<?php

declare(strict_types=1);

namespace Aurora\Core\Twig;

use Aurora\Module\Dev\Prerequisite\DevPrerequisiteChecker;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Dev-environment helpers for Twig templates.
 *
 *   aurora_is_dev()       - true when kernel.environment === 'dev'.
 *                           Replaces the magic string `app.environment == 'dev'`
 *                           everywhere in templates.
 *
 *   aurora_dev_warnings() - list of {@see PrerequisiteWarning}
 *                           for missing dev prerequisites (PHP extensions,
 *                           Node.js…).
 */
final class DevPrerequisiteExtension extends AbstractExtension
{
    public function __construct(
        private readonly DevPrerequisiteChecker $checker,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $env,
    ) {}

    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'aurora_is_dev',
                fn (): bool => 'dev' === $this->env,
            ),
            new TwigFunction(
                'aurora_dev_warnings',
                fn (): array => $this->checker->getWarnings(),
            ),
        ];
    }
}
