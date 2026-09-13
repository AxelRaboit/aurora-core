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

    private const string SANS = 'Poppins, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif';

    private const string SERIF = 'ui-serif, Georgia, "Times New Roman", serif';

    private const string MONO = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';

    public function heading(): string
    {
        return match ($this) {
            self::Sans => self::SANS,
            self::Serif, self::Editorial => self::SERIF,
            self::Technical => self::MONO,
        };
    }

    public function body(): string
    {
        return match ($this) {
            self::Sans, self::Editorial, self::Technical => self::SANS,
            self::Serif => self::SERIF,
        };
    }

    public function labelKey(): string
    {
        return 'backend.studio.decks.fonts.'.$this->value;
    }
}
