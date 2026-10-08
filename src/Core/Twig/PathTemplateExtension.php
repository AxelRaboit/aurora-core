<?php

declare(strict_types=1);

namespace Aurora\Core\Twig;

use Aurora\Core\Routing\PathTemplateGenerator;
use Twig\Attribute\AsTwigFunction;

/**
 * `path_template('route', {id: '__id__'})` from a template.
 *
 * The reasoning, and the incident that produced it, live on
 * {@see PathTemplateGenerator}. This is the Twig door onto it, kept because
 * most screens hand their paths to Vue from a template.
 */
final readonly class PathTemplateExtension
{
    public function __construct(private PathTemplateGenerator $pathTemplateGenerator) {}

    /** @param array<string, mixed> $parameters */
    #[AsTwigFunction(name: 'path_template')]
    public function pathTemplate(string $route, array $parameters = []): string
    {
        return $this->pathTemplateGenerator->generate($route, $parameters);
    }
}
