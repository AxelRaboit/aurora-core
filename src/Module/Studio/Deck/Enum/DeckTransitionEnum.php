<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Enum;

/**
 * What happens between two slides in the full screen.
 *
 * **Three, and none of them is a spin.** A transition exists to say "that was
 * one thought, here is the next", which a hundred and fifty milliseconds does
 * as well as anything; past that it becomes the thing the room is looking at.
 * A module that offered twenty would be a module whose decks are told apart by
 * their wipes.
 *
 * `Fade` is the default, and it is the one place in this module where a default
 * is not the previous behaviour spelled out. A transition is not a property of
 * the document - the print page and the share link are untouched by it - but of
 * the act of presenting, and no deck composed before today looks different on
 * paper or through its link because of this. Anybody who disagrees has `None`
 * one select away.
 */
enum DeckTransitionEnum: string
{
    /** Cut. What the player did before this enum existed. */
    case None = 'none';

    /** The slide dissolves into the next. The quiet default. */
    case Fade = 'fade';

    /** The slide steps aside, in the direction you are moving. */
    case Slide = 'slide';

    public function labelKey(): string
    {
        return 'backend.studio.decks.transitions.'.$this->value;
    }
}
