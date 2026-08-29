<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every path the client README hands a newcomer has to exist.
 *
 * This file is the first thing somebody reads on a client project, and its
 * links rot silently: two of them pointed at `getting_started/` for months
 * while the directory has always been `getting-started/`, and nothing said so
 * because a README is never executed.
 *
 * The two GitHub links are checked as repository paths rather than fetched.
 * They point at this repo on `develop`, so the file existing here is the same
 * question, and a test that reaches the network fails for reasons that have
 * nothing to do with the code.
 */
final class ClientReadmeLinksTest extends TestCase
{
    private const string TEMPLATE = __DIR__.'/../../.claude/client_template/README.md';

    private const string REPO_ROOT = __DIR__.'/../..';

    public function testEveryDocPathInTheTemplateExists(): void
    {
        $template = file_get_contents(self::TEMPLATE);
        self::assertIsString($template, 'The client README template could not be read.');

        $paths = array_merge(
            $this->backtickedPaths($template),
            $this->gitHubPaths($template),
        );

        self::assertNotSame([], $paths, 'No paths found - if the template moved, this test has to follow it.');

        $missing = array_values(array_filter(
            $paths,
            static fn (string $path): bool => !file_exists(self::REPO_ROOT.'/'.$path),
        ));

        self::assertSame([], $missing, sprintf(
            'The client README points at files that do not exist: %s.',
            implode(', ', $missing),
        ));
    }

    /**
     * Paths written in backticks, relative to the installed package.
     *
     * @return list<string>
     */
    private function backtickedPaths(string $template): array
    {
        preg_match_all('/`((?:docs|\.claude)\/[A-Za-z0-9_\/.-]+\.md)`/', $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Paths behind the GitHub links read before `vendor/` exists.
     *
     * @return list<string>
     */
    private function gitHubPaths(string $template): array
    {
        preg_match_all(
            '#https://github\.com/AxelRaboit/aurora-core/blob/develop/([A-Za-z0-9_/.-]+\.md)#',
            $template,
            $matches,
        );

        return array_values(array_unique($matches[1]));
    }
}
