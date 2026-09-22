<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Enum;

/**
 * The shapes a slide can take.
 *
 * **Fixed layouts rather than free placement**, and that is the central
 * decision of this module. A deck of audit findings or of strategy is text and
 * a few screenshots; free placement would mean building a design tool - drag,
 * resize, rotate, z-order, snapping - to serve a need six shapes already
 * cover. The day a deck genuinely needs a diagram nobody can express here,
 * that is the day to reconsider, with a real example in hand.
 *
 * Each layout names the slots its content JSON may carry. The whitelist lives
 * with the layout for the same reason `GridNormalizer` keeps its own: a slot
 * nobody declared must not survive a round trip through the form.
 */
enum SlideLayoutEnum: string
{
    /** A title, and optionally a line under it. Opens a deck. */
    case Title = 'title';

    /** A heading and a list. The workhorse of an audit deck. */
    case Bullets = 'bullets';

    /** One picture, edge to edge, with an optional caption. */
    case Image = 'image';

    /** Two columns of prose, for a before and an after. */
    case Split = 'split';

    /** A sentence someone said, and who said it. */
    case Quote = 'quote';

    /** A divider that announces what follows. Carries a title alone. */
    case Section = 'section';

    /** One number, as large as the frame allows, and what it counts. */
    case Stat = 'stat';

    /** A picture beside a paragraph, on whichever side suits it. */
    case ImageText = 'image_text';

    /** Two to four short blocks, side by side. Three chantiers, three offres. */
    case Cards = 'cards';

    /** Steps in order, on a line. A calendar, a method, a sequence. */
    case Timeline = 'timeline';

    /** A small table. Its first row is its header. */
    case Table = 'table';

    /** Figures, drawn. Bars, a line or a doughnut, in the deck's accent. */
    case Chart = 'chart';

    /** Two columns that answer each other. Before and after, us and them. */
    case Compare = 'compare';

    /** Three or four figures side by side. The results slide. */
    case Figures = 'figures';

    /** Thank you, and how to reach whoever presented. The last slide. */
    case End = 'end';

    /** The parts of the deck, numbered, with the one being opened lit. */
    case Agenda = 'agenda';

    /** What a client said, and their face. The testimonial. */
    case Portrait = 'portrait';

    /** The clients' marks, on a grid, brought to one optical height. */
    case Logos = 'logos';

    /** Two to eight photographs in a declared arrangement. */
    case Mosaic = 'mosaic';

    /**
     * The slots that hold a list of lines rather than one string.
     *
     * Declared once because three places have to agree about it: the manager,
     * which keeps only the strings; the editor, which shows a textarea and
     * splits on newlines; and the frame, which draws one row per line. A fourth
     * list slot added to a layout without being named here would be stored as
     * the raw string a textarea posts, and drawn as nothing.
     *
     * @return list<string>
     */
    public static function listSlots(): array
    {
        return ['bullets', 'items', 'steps', 'rows', 'series', 'figures', 'lines'];
    }

    /**
     * The separator inside one line of a list slot.
     *
     * A card is a heading and a sentence, a step is a name and a date, a table
     * row is its cells: all of them are several values on one line, and a
     * repeatable sub-form for each would be three more editors to build and
     * maintain. One character, typed, and the placeholder says so.
     *
     * The split is done at render, never at write: the line is what somebody
     * typed, and storing the pieces would mean a shape the editor then has to
     * put back together to let them edit it again.
     */
    public const string CELL_SEPARATOR = '|';

    /**
     * The slots every layout accepts, whatever its shape.
     *
     * **Separate from `slots()` because they answer a different question.** A
     * layout's own slots are what that shape is made of; these three are
     * settings that happen to live in the same JSON: a line above the title, a
     * picture behind everything, and how far that picture is dimmed. Putting
     * them in `slots()` would put them in the middle of the form, between a
     * quote and its attribution, where they read as part of the shape.
     *
     * The background is deliberately not a layout of its own. "A section
     * divider over a photograph" and "a stat over a photograph" would be two
     * more cases each, and the day a third shape wanted one it would be two
     * more again.
     *
     * @return list<string>
     */
    public static function commonSlots(): array
    {
        return ['kicker', 'bgMediaId', 'bgDim', 'bgTreatment', 'bgVeil', 'vignette', 'ground', 'anchor', 'align', 'measure', 'band', 'transition', 'drift', 'titleScale', 'reveal'];
    }

    /**
     * The slots this layout accepts, in the order the editor shows them.
     *
     * `mediaId` is an integer, everything else is a string. The manager reads
     * this list to drop whatever else arrived, so adding a slot here is the
     * only edit a new field needs.
     *
     * @return list<string>
     */
    public function slots(): array
    {
        return match ($this) {
            self::Title => ['title', 'subtitle'],
            self::Bullets => ['title', 'bullets'],
            self::Image => ['mediaId', 'mediaFit', 'mediaFocus', 'mediaShape', 'mediaFrame', 'caption', 'captionOver'],
            self::Split => ['title', 'left', 'right'],
            self::Quote => ['quote', 'attribution'],
            self::Section => ['title', 'ghost'],
            self::Stat => ['value', 'label'],
            self::ImageText => ['title', 'text', 'mediaId', 'mediaFit', 'mediaFocus', 'mediaShape', 'mediaFrame', 'mediaBleed', 'side'],
            self::Cards => ['title', 'items'],
            self::Timeline => ['title', 'steps'],
            self::Table => ['title', 'rows'],
            self::Chart => ['title', 'chartType', 'series'],
            self::Compare => ['title', 'leftTitle', 'left', 'rightTitle', 'right'],
            self::Figures => ['title', 'figures'],
            self::End => ['title', 'lines'],
            self::Agenda => ['title', 'steps', 'current'],
            self::Portrait => ['quote', 'attribution', 'role', 'mediaId', 'mediaFocus'],
            self::Logos => ['title', 'mediaIds'],
            self::Mosaic => ['title', 'mediaIds'],
        };
    }

    /**
     * Everything this layout's content may carry: its own slots and the common
     * ones. What the manager filters against.
     *
     * @return list<string>
     */
    public function allSlots(): array
    {
        return [...$this->slots(), ...self::commonSlots()];
    }

    public function labelKey(): string
    {
        return 'backend.studio.decks.layouts.'.$this->value;
    }
}
