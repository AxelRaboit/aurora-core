<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Core\Frontend;

use Aurora\Tests\Integration\IntegrationTestCase;
use Twig\Environment;

use function preg_match;

/**
 * The language switcher of the public header: a flag in the bar, the names in
 * the panel it opens.
 */
final class LocaleSwitcherTest extends IntegrationTestCase
{
    private const string TEMPLATE = 'Frontend/themes/default/partials/locale_switcher.html.twig';

    public function testTheBarShowsTheFlagAndKeepsTheNameForScreenReaders(): void
    {
        $summary = $this->summary('fr');

        self::assertStringContainsString('fi fi-', $summary);
        self::assertMatchesRegularExpression('/<span class="sr-only">\s*Français\s*<\/span>/u', $summary);
        self::assertStringContainsString('title="Français"', $summary, 'named on hover');
        self::assertStringNotContainsString('not-sr-only', $summary);
    }

    public function testThePanelNamesEveryLanguage(): void
    {
        $html = $this->render('fr');
        $panel = mb_substr($html, (int) mb_strpos($html, '</summary>'));

        self::assertStringContainsString('English', $panel);
        self::assertStringContainsString('Español', $panel);
        self::assertStringNotContainsString('sr-only', $panel);
    }

    private function summary(string $current): string
    {
        self::assertSame(1, preg_match('/<summary.*?<\/summary>/s', $this->render($current), $match));

        return $match[0];
    }

    private function render(string $current): string
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $locales = array_map(
            static fn (string $code): object => new class($code) {
                public function __construct(public string $code) {}
            },
            ['fr', 'en', 'es'],
        );

        return $twig->render(self::TEMPLATE, [
            'locales' => $locales,
            'current' => $current,
            'urls' => ['fr' => '/fr', 'en' => '/en', 'es' => '/es'],
        ]);
    }
}
