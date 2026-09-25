<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Theme\Enum;

use Aurora\Module\Configuration\Theme\Enum\ThemeFontEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Une famille se déclare à quatre endroits qui doivent rester d'accord : le cas
 * de l'enum, ses graisses importées dans `app.css`, sa description traduite, et
 * le défaut recopié dans le formulaire Vue.
 *
 * Aucun de ces désaccords ne casse quoi que ce soit bruyamment. Un cas sans
 * import sert une page composée dans la pile de secours, donc dans une police
 * qui n'est pas celle qu'on a choisie ; une description manquante affiche sa
 * clé en clair sous le sélecteur. Ce sont les pannes que personne ne signale,
 * d'où ces tests plutôt qu'un commentaire.
 */
final class ThemeFontEnumTest extends TestCase
{
    private const string APP_CSS = __DIR__.'/../../../../../../src/Core/assets/css/app.css';

    private const string THEME_CSS = __DIR__.'/../../../../../../src/Core/assets/css/base/theme.css';

    private const string EDIT_JS = __DIR__.'/../../../../../../src/Module/Configuration/assets/backend/themes/composables/useThemesEdit.js';

    private const string TRANSLATIONS = __DIR__.'/../../../../../../src/Module/Configuration/Theme/translations/messages.%s.yaml';

    /** @return iterable<string, array{ThemeFontEnum}> */
    public static function fonts(): iterable
    {
        foreach (ThemeFontEnum::cases() as $font) {
            yield $font->value => [$font];
        }
    }

    /**
     * Les six graisses de la famille sont embarquées.
     *
     * Le nom du paquet `@fontsource` est le `value` du cas, ce qui n'est pas
     * une coïncidence à préserver par hasard : c'est ce qui rend cette
     * vérification possible.
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
        // L'espagnol du back-office est encore en repli français, donc rien à
        // y vérifier tant qu'il n'a pas sa section `backend`.
        yield 'fr' => ['fr'];
        yield 'en' => ['en'];
    }

    #[DataProvider('locales')]
    public function testEveryFamilyIsDescribedInBothLocales(string $locale): void
    {
        /** @var array<string, mixed> $catalogue */
        $catalogue = Yaml::parseFile(sprintf(self::TRANSLATIONS, $locale));
        $descriptions = $catalogue['backend']['themes']['fonts'] ?? null;

        self::assertIsArray($descriptions);

        foreach (ThemeFontEnum::cases() as $font) {
            self::assertArrayHasKey(
                $font->value,
                $descriptions,
                sprintf('%s n\'a pas de description en %s : le sélecteur afficherait sa clé.', $font->label(), $locale),
            );
        }

        self::assertArrayHasKey('font_family', $catalogue['backend']['themes']);
        self::assertArrayHasKey('font_preview', $catalogue['backend']['themes']);
    }

    /**
     * Le défaut vit en PHP et le formulaire en garde une copie, pour savoir
     * quelle famille proposer avant d'avoir reçu la liste.
     *
     * Cf. `convention_mirrored_contract_php_js` : la copie est permise, un test
     * la tient. C'est la même histoire que ThemeDefaultColourMirrorTest, où le
     * défaut avait glissé de l'indigo au vert d'un seul côté.
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
     * Et le même défaut, une troisième fois, dans `theme.css` : c'est lui qui
     * compose les pages tant qu'aucune règle n'est posée au chargement.
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
        // La colonne `config` est un JSON libre : une clé écrite à la main ou
        // survivant à la suppression d'un cas vaut mieux composée en Poppins
        // qu'en page d'erreur.
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
