<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Enum;

/**
 * A wash of the deck's accent over its ground.
 *
 * **Four directions and a flat, which is a vocabulary rather than a gradient
 * editor.** A slide whose fond is a flat colour reads as a template; a slide
 * that carries a little of its own accent reads as chosen. What makes the
 * difference is that the same wash falls the same way on every slide of the
 * deck, so it is a property of the deck, beside its three colours, and never a
 * per-slide decision that would drift from one slide to the next.
 *
 * **No colours here.** Where the wash starts and how far it reaches is drawn
 * in the frame with `color-mix` over `--slide-accent`, exactly as the rules,
 * the cards and the placeholders already are: a hex value in this enum would
 * be a fourth colour to keep in agreement with a palette the deck can
 * override. The case names a direction, nothing more.
 *
 * `None` is the look every deck had before this enum existed, and it stays the
 * default, so no deck changes appearance the day the key is added.
 */
enum DeckGradientEnum: string
{
    /** Flat ground. What every deck looked like before. */
    case None = 'none';

    /** The accent falls from the top edge. Reads as light entering the frame. */
    case Top = 'top';

    /** The accent rises from the bottom edge, under the text. */
    case Bottom = 'bottom';

    /** A diagonal from the top left, the quietest of the four. */
    case Corner = 'corner';

    /** A halo behind the middle of the frame. For covers and section slides. */
    case Halo = 'halo';

    public function labelKey(): string
    {
        return 'backend.studio.decks.gradients.'.$this->value;
    }
}
