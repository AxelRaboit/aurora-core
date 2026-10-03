<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Setting;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Configuration\Setting\Service\EmailColors;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function preg_quote;

/**
 * Les couleurs des e-mails : au défaut un message sort comme avant, et ce qui
 * n'est pas un hexadécimal n'entre pas dans son HTML.
 */
final class EmailColorsTest extends TestCase
{
    public function testTheDefaultsAreTheColoursEmailCssHardCodes(): void
    {
        $css = (string) file_get_contents(__DIR__.'/../../../../../src/Core/assets/css/email.css');

        self::assertMatchesRegularExpression('/\.button-primary \{ background-color: '.preg_quote(ApplicationParameterEnum::EmailAccentColor->getDefaultValue(), '/').';/', $css);
        self::assertMatchesRegularExpression('/\.wrapper \{\s*background-color: '.preg_quote(ApplicationParameterEnum::EmailBackgroundColor->getDefaultValue(), '/').';/', $css);
        self::assertMatchesRegularExpression('/h1 \{\s*color: '.preg_quote(ApplicationParameterEnum::EmailHeadingColor->getDefaultValue(), '/').';/', $css);
        self::assertMatchesRegularExpression('/body \{[^}]*\bcolor: '.preg_quote(ApplicationParameterEnum::EmailTextColor->getDefaultValue(), '/').';/', $css);
    }

    public function testUntouchedSettingsAddNoRule(): void
    {
        $colors = $this->colorsWith([]);

        self::assertSame('', $colors->css());
        self::assertSame('#10b981', $colors->colors()['accentLight']);
    }

    public function testAChangedAccentRepaintsTheButtonAndTheLinks(): void
    {
        $colors = $this->colorsWith([ApplicationParameterEnum::EmailAccentColor->value => '#8B6CFF']);

        self::assertSame('a,.header a,.fallback a{color:#8b6cff}.button-primary{background-color:#8b6cff}.panel{border-left-color:#8b6cff}', $colors->css());
        self::assertSame('#a891ff', $colors->colors()['accentLight']);
    }

    public function testAValueThatIsNotHexFallsBackToTheDefault(): void
    {
        $colors = $this->colorsWith([ApplicationParameterEnum::EmailBackgroundColor->value => 'red}</style>']);

        self::assertSame('', $colors->css());
        self::assertSame('#f5f3ff', $colors->colors()['background']);
    }

    /** @param array<string, string> $stored */
    private function colorsWith(array $stored): EmailColors
    {
        $repository = $this->createStub(SettingRepository::class);
        $repository->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $stored[$key] ?? $default,
        );

        return new EmailColors($repository);
    }
}
