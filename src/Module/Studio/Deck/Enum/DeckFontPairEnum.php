<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Enum;

/**
 * The two faces a deck is set in: one for the titles, one for the text.
 *
 * **Stacks rather than a font file per theme.** A deck is read in a browser,
 * printed from that browser and opened again from a share link, and a face
 * that only loads in the back office would leave the share link setting the
 * whole deck in Times. Poppins is Aurora's own face, already loaded on every
 * page by `app.css`; the serif and the monospace are generic families, which
 * every system resolves to something it actually has.
 *
 * Four pairs and no free choice, for the same reason the layouts are fixed:
 * the pairings that work are few, and a field where a reader types a font name
 * is a field that produces decks set in a face nobody has.
 */
enum DeckFontPairEnum: string
{
    /** Poppins throughout. The look of the back office itself. */
    case Sans = 'sans';

    /** Serif throughout. Reads as a document rather than as a screen. */
    case Serif = 'serif';

    /** Serif titles over sans text. The magazine arrangement. */
    case Editorial = 'editorial';

    /** Monospaced titles over sans text. For an audit or a technical review. */
    case Technical = 'technical';

    /** Sans titles over serif text. The report, and the inverse of Editorial. */
    case Report = 'report';

    /** Rounded throughout. Softer, for something addressed to the public. */
    case Soft = 'soft';

    /**
     * Named families first, and self-hosted ones at that.
     *
     * **A system stack was a deck that changed shape between machines.** Three
     * of these four pairs used to start at `ui-serif` or `ui-monospace`, which
     * resolve to a different face on macOS, on Windows and on Android: the
     * same deck broke its lines in different places for its author and for the
     * client opening the share link, and `useSlideFit` measured a different
     * factor on each. The families below are the ones the application already
     * carries in its own assets, so the frame draws the same everywhere,
     * including in the PDF and behind a public link.
     *
     * The fallbacks stay, for the seconds before a face has loaded and for the
     * reader who blocks web fonts.
     */
    private const string SANS = 'Poppins, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif';

    private const string SERIF = 'Lora, ui-serif, Georgia, "Times New Roman", serif';

    private const string MONO = '"JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';

    private const string ROUNDED = 'Nunito, ui-rounded, ui-sans-serif, system-ui, sans-serif';

    private const string GROTESQUE = '"Work Sans", ui-sans-serif, system-ui, sans-serif';

    public function heading(): string
    {
        return match ($this) {
            self::Sans => self::SANS,
            self::Serif, self::Editorial => self::SERIF,
            self::Technical => self::MONO,
            self::Report => self::GROTESQUE,
            self::Soft => self::ROUNDED,
        };
    }

    public function body(): string
    {
        return match ($this) {
            self::Sans, self::Editorial, self::Technical => self::SANS,
            self::Serif, self::Report => self::SERIF,
            self::Soft => self::ROUNDED,
        };
    }

    public function labelKey(): string
    {
        return 'backend.studio.decks.fonts.'.$this->value;
    }
}
