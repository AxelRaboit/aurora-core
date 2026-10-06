<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Theme\Enum;

use Aurora\Module\Studio\Deliverable\Slides\Enum\DeckFontPairEnum;

/**
 * The family in which a theme sets the whole application.
 *
 * **A closed list, not a free field.** Aurora's CSP sets
 * `font-src 'self' data:`: a font called by its name alone never arrives, it
 * is resolved by the visitor's system or not at all. The families offered
 * here are therefore the ones `app.css` bundles, one per `@fontsource`
 * package, and a field where a name is typed would produce sites set in a
 * font nobody has. It is the same reason as for {@see DeckFontPairEnum}, at
 * another scale.
 *
 * **Five, chosen to stand apart from each other.** A geometric, a neutral
 * interface one, a grotesque, a rounded one and a serif: the choice is only
 * worth something if switching from one to another shows.
 *
 * Adding a family means three steps that go together: the case here, its six
 * weights imported in `app.css`, and its package as a dependency.
 */
enum ThemeFontEnum: string
{
    /** Geometric. Aurora's historical font, and its default. */
    case Poppins = 'poppins';

    /** Neutral, drawn for screens. Goes unnoticed. */
    case Inter = 'inter';

    /** Grotesque, a little wider and warmer than Inter. */
    case WorkSans = 'work-sans';

    /** Rounded. The least institutional tone of the list. */
    case Nunito = 'nunito';

    /** Serif. Makes the page read as a text rather than a screen. */
    case Lora = 'lora';

    case PlayfairDisplay = 'playfair-display';

    case SpaceGrotesk = 'space-grotesk';

    /**
     * The fallback stacks serve twice: while the file arrives, and forever if
     * the request fails.
     */
    private const string SANS_FALLBACK = 'ui-sans-serif, system-ui, sans-serif';

    private const string SERIF_FALLBACK = 'ui-serif, Georgia, "Times New Roman", serif';

    /**
     * The family a theme configuration names, or the default.
     *
     * Tolerates anything `Theme::config` can hold: the column is free JSON, a
     * key may have been written there by hand or have survived the removal of
     * a case, and a screen set in Poppins is better than an error page.
     */
    public static function fromConfig(mixed $raw): self
    {
        return is_string($raw) ? (self::tryFrom($raw) ?? self::default()) : self::default();
    }

    public static function default(): self
    {
        return self::Poppins;
    }

    /**
     * The full CSS value, as it is set on `--th-font-sans`.
     *
     * The family name is the one declared by the `@font-face` rules of
     * `@fontsource`, quotes included: "Work Sans" does not resolve without
     * them.
     */
    public function stack(): string
    {
        return match ($this) {
            self::Poppins => "'Poppins', ".self::SANS_FALLBACK,
            self::Inter => "'Inter', ".self::SANS_FALLBACK,
            self::WorkSans => "'Work Sans', ".self::SANS_FALLBACK,
            self::Nunito => "'Nunito', ".self::SANS_FALLBACK,
            self::Lora => "'Lora', ".self::SERIF_FALLBACK,
            self::PlayfairDisplay => "'Playfair Display', ".self::SERIF_FALLBACK,
            self::SpaceGrotesk => "'Space Grotesk', ".self::SANS_FALLBACK,
        };
    }

    /**
     * The family name, which is a proper name: it is not translated and so
     * does not go through the catalogue.
     */
    public function label(): string
    {
        return match ($this) {
            self::Poppins => 'Poppins',
            self::Inter => 'Inter',
            self::WorkSans => 'Work Sans',
            self::Nunito => 'Nunito',
            self::Lora => 'Lora',
            self::PlayfairDisplay => 'Playfair Display',
            self::SpaceGrotesk => 'Space Grotesk',
        };
    }

    /** The line that says what the family looks like, which is translated. */
    /**
     * Space Grotesk is drawn without an italic: its package ships none, and
     * the browser slants the upright when a page asks for one.
     */
    public function hasItalic(): bool
    {
        return self::SpaceGrotesk !== $this;
    }

    public function descriptionKey(): string
    {
        return 'suite.themes.fonts.'.$this->value;
    }

    /**
     * What to fill the back-office selector with: the stored value, the
     * displayed name, the description key and the stack, so the preview is set
     * in the offered font without the JavaScript declaring the stacks again.
     *
     * @return list<array{value: string, label: string, descriptionKey: string, stack: string}>
     */
    public static function choices(): array
    {
        return array_map(
            static fn (self $font): array => [
                'value' => $font->value,
                'label' => $font->label(),
                'descriptionKey' => $font->descriptionKey(),
                'stack' => $font->stack(),
            ],
            self::cases(),
        );
    }
}
