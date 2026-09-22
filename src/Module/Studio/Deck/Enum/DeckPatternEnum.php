<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Enum;

/**
 * A texture on the deck's ground, drawn in its accent.
 *
 * **Three and a flat**, chosen because they are the three that survive being
 * projected: a motif finer than a couple of millimetres on a wall is a grey
 * haze, and one louder than this competes with the words. They sit at a low
 * mix of the accent for the same reason.
 *
 * **Under the picture, not over it.** The motif belongs to the ground, so a
 * slide whose ground is a photograph simply does not show it. A texture laid
 * over somebody's photograph is the kind of decision a module makes once and
 * a client asks to undo on every deck.
 *
 * Declared beside {@see DeckGradientEnum} rather than folded into it: a wash
 * and a texture are two answers to "the fond is bare", and a deck that wants
 * both should not have to pick.
 */
enum DeckPatternEnum: string
{
    /** Bare ground. What every deck looked like before. */
    case None = 'none';

    /** A field of dots. The quietest, and the only one that suits a cover. */
    case Dots = 'dots';

    /** A squared grid. Reads as paper, and suits the document register. */
    case Grid = 'grid';

    /** Diagonal hatching. The loudest of the three; one accent, angled. */
    case Diagonals = 'diagonals';

    public function labelKey(): string
    {
        return 'backend.studio.decks.patterns.'.$this->value;
    }
}
