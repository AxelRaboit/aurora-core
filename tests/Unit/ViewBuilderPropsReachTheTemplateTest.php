<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A key a view builder produces has to reach the template that mounts the app.
 *
 * The chain is three links long - builder, Twig, `defineProps` - and a break in
 * the middle is silent at every step. The share paths were generated, never
 * passed, and never declared; the front called `fetch(undefined)`, the browser
 * answered 404, and the screen said "an error occurred". Nothing in the build,
 * the linters or the test suite had an opinion.
 *
 * This checks the first link against the second: every `'somethingPath' =>`
 * a view builder emits must appear in a template that mounts a Vue component.
 * Vue's own `defineProps` warns loudly in dev for a missing required prop, so
 * the second link to the third is already covered at runtime.
 */
final class ViewBuilderPropsReachTheTemplateTest extends TestCase
{
    private const string SRC = __DIR__.'/../../src';

    public function testEveryPathAViewBuilderEmitsIsPassedByATemplate(): void
    {
        $templates = $this->templateSources();
        self::assertNotSame('', $templates, 'No templates were read - if the layout moved, this test has to follow it.');

        $missing = [];

        foreach ($this->files('php') as $file) {
            if (!str_contains($file, 'ViewBuilder')) {
                continue;
            }

            $source = file_get_contents($file);
            if (false === $source) {
                continue;
            }

            // `'somethingPath' => $this->urlGenerator->generate(...)`
            preg_match_all("/'([a-zA-Z]+Path)'\s*=>/", $source, $matches);

            foreach (array_unique($matches[1]) as $key) {
                if (!str_contains($templates, $key)) {
                    $missing[] = sprintf('%s::%s', basename($file, '.php'), $key);
                }
            }
        }

        self::assertSame([], $missing, sprintf(
            "These view-builder keys never reach a template, so the front receives undefined and its calls 404 in silence: %s.\nPass them in the `vue_component(...)` call and declare them in the component's props.",
            implode(', ', $missing),
        ));
    }

    private function templateSources(): string
    {
        $all = '';
        foreach ($this->files('twig') as $file) {
            $source = file_get_contents($file);
            if (false !== $source) {
                $all .= $source;
            }
        }

        return $all;
    }

    /** @return list<string> */
    private function files(string $extension): array
    {
        $found = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SRC));
        foreach ($files as $file) {
            if ($file->isFile() && $extension === $file->getExtension()) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }
}
