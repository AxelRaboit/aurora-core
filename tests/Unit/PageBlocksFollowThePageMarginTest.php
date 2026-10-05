<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function count;
use function dirname;
use function file_get_contents;
use function implode;
use function is_string;
use function mb_strpos;
use function mb_substr;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function ucfirst;

/**
 * Between the blocks of a back-office screen, the page's own margin.
 *
 * The screens stacked their blocks each in its own way - `space-y-2
 * sm:space-y-4`, `space-y-3`, `space-y-4`, `space-y-5` - and none of these
 * followed `--aurora-page-margin`. On a phone that read as eight pixels under
 * the breadcrumb and twenty under the first block: a lone "Actions" button had
 * more air below than above. `aurora-stack` and `aurora-gap` (spacing.css) take
 * the margin at every width, and this test keeps a fixed step from coming back
 * at the root of a screen.
 *
 * Only the root and the screen's guide are checked: inside a card or a form,
 * the finer steps of the scale are the right ones.
 */
final class PageBlocksFollowThePageMarginTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /** A fixed vertical step: `space-y-4`, `sm:gap-5`, `gap-y-3`… */
    private const string FIXED_STEP = '/(?:^|\s)(?:[a-z]+:)*(?:space-y|gap|gap-y)-\d/';

    public function testEveryScreenRootFollowsThePageMargin(): void
    {
        $offenders = [];

        foreach ($this->screens() as $file) {
            $template = $this->template($file);
            if (1 === preg_match('/<([A-Za-z][\w-]*)\b[^>]*?\sclass="([^"]*)"/s', $this->firstTag($template), $match)
                && 1 === preg_match(self::FIXED_STEP, $match[2])
                // A screen whose root is itself a card: the step spaces the
                // inside of the card, not blocks of the page.
                && !str_contains($match[2], 'aurora-card')) {
                $offenders[] = $this->relative($file).' : '.$match[2];
            }

            preg_match_all('/<AppGuide\b[^>]*\sclass="([^"]*)"/s', $template, $guides);
            foreach ($guides[1] as $class) {
                if (1 === preg_match('/(?:^|\s)(?:[a-z]+:)*mb-\d/', $class)) {
                    $offenders[] = $this->relative($file).' (AppGuide) : '.$class;
                }
            }
        }

        self::assertSame([], $offenders, sprintf(
            "These screens space their blocks with a fixed step instead of the page margin:\n%s\n"
            .'Use `aurora-stack` (or `aurora-gap` on a grid or a flex row), and `mb-[var(--aurora-page-margin)]` on a guide outside a stack.',
            implode("\n", $offenders),
        ));
    }

    public function testTheScreensAreFound(): void
    {
        // A resolver that silently found nothing would make the test above pass.
        self::assertGreaterThan(30, count($this->screens()));
    }

    /**
     * Every component a back-office page mounts, plus the views a client
     * space and the settings swap in under their own root.
     *
     * @return list<string>
     */
    private function screens(): array
    {
        $screens = [];

        foreach ($this->files(self::ROOT.'/src', '.html.twig') as $twig) {
            $source = (string) file_get_contents($twig);
            if (1 !== preg_match("/extends '@(?:Core\\/suite|Studio\\/suite\\/space-content)\\/layout\\.html\\.twig'/", $source)) {
                continue;
            }

            preg_match_all("/vue_component\\('([^']+)'/", $source, $components);
            foreach ($components[1] as $component) {
                $file = $this->resolve($component);
                if (null !== $file) {
                    $screens[$file] = true;
                }
            }
        }

        foreach ($this->files(self::ROOT.'/src/Module/Configuration/assets/suite/settings/tabs', '.vue') as $tab) {
            $screens[$tab] = true;
        }

        // The dashboard's panels, one per module, stacked under its root.
        foreach ($this->files(self::ROOT.'/src', '.vue') as $panel) {
            if (str_contains(str_replace('\\', '/', $panel), '/assets/suite/dashboard/') && !str_contains($panel, '.test.')) {
                $screens[$panel] = true;
            }
        }

        $spaceApp = self::ROOT.'/src/Module/Studio/SpaceContent/assets/suite/content/SpaceContentApp.vue';
        preg_match_all('/from "([^"]+View\.vue)"/', (string) file_get_contents($spaceApp), $views);
        foreach ($views[1] as $view) {
            $screens[dirname($spaceApp).'/'.$view] = true;
        }

        return array_keys($screens);
    }

    /** `studio/suite/access/SpaceAccessApp` → the `.vue` file under the Studio module. */
    private function resolve(string $component): ?string
    {
        [$module, $rest] = explode('/', $component, 2);
        $base = 'core' === $module ? self::ROOT.'/src/Core' : self::ROOT.'/src/Module/'.ucfirst($module);
        $suffix = 'assets/'.$rest.'.vue';

        foreach ($this->files($base, '.vue') as $file) {
            if (str_ends_with(str_replace('\\', '/', $file), $suffix)) {
                return $file;
            }
        }

        return null;
    }

    private function template(string $file): string
    {
        $source = (string) file_get_contents($file);
        $start = mb_strpos($source, '<template>');

        return false === $start ? '' : mb_substr($source, $start + 10);
    }

    /** The first element of the template, comments skipped. */
    private function firstTag(string $template): string
    {
        $template = (string) preg_replace('/<!--.*?-->/s', '', $template);

        return 1 === preg_match('/<[A-Za-z][^>]*>/s', $template, $match) && is_string($match[0]) ? $match[0] : '';
    }

    /** @return list<string> */
    private function files(string $directory, string $extension): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $extension) && !str_contains($file->getPathname(), '/node_modules/')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function relative(string $file): string
    {
        return str_replace(self::ROOT.'/', '', $file);
    }
}
