<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Setting;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function dirname;
use function sprintf;

/**
 * The settings screen groups a tab's fields under titled sections, each with
 * a sentence saying what it covers (visual redesign of the suite, 10/10/2026).
 *
 * A section key with no title in one language prints the raw key on that
 * screen; a section holding fields of two tabs would draw the same heading on
 * both. Both are caught here rather than by a reader.
 */
final class SettingSectionsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        yield 'fr' => ['fr'];
        yield 'en' => ['en'];
        yield 'es' => ['es'];
    }

    #[DataProvider('locales')]
    public function testEverySectionHasATitleAndALead(string $locale): void
    {
        $catalogue = Yaml::parseFile(dirname(__DIR__, 5).sprintf('/src/Module/Configuration/Setting/translations/messages.%s.yaml', $locale));
        $sections = $catalogue['suite']['settings']['sections'] ?? [];

        foreach (ApplicationParameterEnum::cases() as $parameter) {
            $section = $parameter->getSection();
            if (null === $section) {
                continue;
            }

            self::assertNotEmpty($sections[$section]['title'] ?? null, sprintf('%s: section "%s" has no title.', $locale, $section));
            self::assertNotEmpty($sections[$section]['lead'] ?? null, sprintf('%s: section "%s" has no lead.', $locale, $section));
        }
    }

    public function testASectionBelongsToOneTab(): void
    {
        $tabBySection = [];
        foreach (ApplicationParameterEnum::cases() as $parameter) {
            $section = $parameter->getSection();
            if (null === $section) {
                continue;
            }

            $tab = $parameter->getGroup();
            $tabBySection[$section] ??= $tab;
            self::assertSame($tabBySection[$section], $tab, sprintf('Section "%s" spans two tabs.', $section));
        }

        self::assertNotEmpty($tabBySection);
    }
}
