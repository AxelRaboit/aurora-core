<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Theme\Enum;

use Aurora\Module\Configuration\Theme\Enum\ThemeFontEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A family is declared in four places that must stay in agreement: the enum
 * case, its weights imported in `app.css`, its translated description, and
 * the default copied into the Vue form.
 *
 * None of these mismatches breaks anything loudly. A case without an import
 * serves a page set in the fallback stack, so in a font that is not the one
 * that was chosen; a missing description shows its raw key under the
 * selector. These are the failures nobody reports, hence these tests rather
 * than a comment.
 */
final class ThemeFontEnumTest extends TestCase
{
    private const string APP_CSS = __DIR__.'/../../../../../../src/Core/assets/css/app.css';

    private const string THEME_CSS = __DIR__.'/../../../../../../src/Core/assets/css/base/theme.css';

    private const string EDIT_JS = __DIR__.'/../../../../../../src/Module/Configuration/assets/suite/themes/composables/useThemesEdit.js';

    private const string TRANSLATIONS = __DIR__.'/../../../../../../src/Module/Configuration/Theme/translations/messages.%s.yaml';

    /** @return iterable<string, array{ThemeFontEnum}> */
    public static function fonts(): iterable
    {
        foreach (ThemeFontEnum::cases() as $font) {
            yield $font->value => [$font];
        }
    }

    /**
     * The six weights of the family are bundled.
     *
     * The name of the `@fontsource` package is the case's `value`, which is
     * not a coincidence to be preserved by chance: it is what makes this
     * check possible.
     */
    #[DataProvider('fonts')]
    public function testTheFamilyIsBundled(ThemeFontEnum $font): void
    {
        $css = (string) file_get_contents(self::APP_CSS);

        $weights = $font->hasItalic()
            ? ['400', '400-italic', '500', '500-italic', '600', '700']
            : ['400', '500', '600', '700'];

        foreach ($weights as $weight) {
            self::assertStringContainsString(
                sprintf('@import "@fontsource/%s/%s.css";', $font->value, $weight),
                $css,
                sprintf('%s est proposée dans le back-office mais sa graisse %s n\'est pas embarquée : la page sortirait dans la pile de secours.', $font->label(), $weight),
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        // The back office Spanish still falls back to French, so nothing to
        // check there until it has its `suite` section.
        yield 'fr' => ['fr'];
        yield 'en' => ['en'];
    }

    #[DataProvider('locales')]
    public function testEveryFamilyIsDescribedInBothLocales(string $locale): void
    {
        /** @var array<string, mixed> $catalogue */
        $catalogue = Yaml::parseFile(sprintf(self::TRANSLATIONS, $locale));
        $descriptions = $catalogue['suite']['themes']['fonts'] ?? null;

        self::assertIsArray($descriptions);

        foreach (ThemeFontEnum::cases() as $font) {
            self::assertArrayHasKey(
                $font->value,
                $descriptions,
                sprintf('%s n\'a pas de description en %s : le sélecteur afficherait sa clé.', $font->label(), $locale),
            );
        }

        self::assertArrayHasKey('font_family', $catalogue['suite']['themes']);
        self::assertArrayHasKey('font_preview', $catalogue['suite']['themes']);
    }

    /**
     * The default lives in PHP and the form keeps a copy of it, to know which
     * family to offer before having received the list.
     *
     * See `convention_mirrored_contract_php_js`: the copy is allowed, a test
     * holds it. It is the same story as ThemeDefaultColourMirrorTest, where the
     * default had slipped from indigo to green on one side only.
     */
    public function testTheJsDefaultMatchesTheEnum(): void
    {
        $js = (string) file_get_contents(self::EDIT_JS);

        self::assertSame(
            1,
            preg_match('/const DEFAULT_FONT_FAMILY = "([a-z-]+)";/', $js, $matches),
            'Le défaut a disparu de useThemesEdit.js : ce test doit le suivre plutôt que d\'être supprimé.',
        );

        self::assertSame(ThemeFontEnum::default()->value, $matches[1]);
    }

    /**
     * And the same default, a third time, in `theme.css`: it is what sets the
     * pages as long as no rule is applied at load time.
     */
    public function testTheCssDefaultMatchesTheEnum(): void
    {
        $css = (string) file_get_contents(self::THEME_CSS);

        self::assertStringContainsString(
            '--th-font-sans: '.ThemeFontEnum::default()->stack().';',
            $css,
            'Le défaut de theme.css ne correspond plus à ThemeFontEnum::default().',
        );
    }

    public function testAnUnknownValueFallsBackToTheDefault(): void
    {
        // The `config` column is free JSON: a key written by hand or
        // surviving the removal of a case is better set in Poppins than
        // turned into an error page.
        self::assertSame(ThemeFontEnum::default(), ThemeFontEnum::fromConfig('comic-sans'));
        self::assertSame(ThemeFontEnum::default(), ThemeFontEnum::fromConfig(null));
        self::assertSame(ThemeFontEnum::default(), ThemeFontEnum::fromConfig(42));
        self::assertSame(ThemeFontEnum::Lora, ThemeFontEnum::fromConfig('lora'));
    }

    #[DataProvider('fonts')]
    public function testTheStackNamesTheFamilyAndKeepsAFallback(ThemeFontEnum $font): void
    {
        $stack = $font->stack();

        self::assertStringStartsWith("'", $stack, 'Le nom de la famille est cité : « Work Sans » ne se résout pas sans guillemets.');
        self::assertStringContainsString(',', $stack, 'Une pile sans secours laisse la page en Times le temps du téléchargement.');
    }

    public function testChoicesDescribeEveryCaseForTheSelector(): void
    {
        $choices = ThemeFontEnum::choices();

        self::assertCount(count(ThemeFontEnum::cases()), $choices);

        foreach ($choices as $choice) {
            self::assertArrayHasKey('value', $choice);
            self::assertArrayHasKey('label', $choice);
            self::assertArrayHasKey('descriptionKey', $choice);
            self::assertArrayHasKey('stack', $choice);
        }
    }
}
