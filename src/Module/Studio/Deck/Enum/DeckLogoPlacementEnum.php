<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Enum;

/**
 * Where a deck's logo shows up, if it shows up at all.
 *
 * Three answers because those are the three that get asked for: none, the
 * cover alone, or the corner of every slide. A coordinate pair would be a
 * placement tool, which this module has already decided not to be.
 */
enum DeckLogoPlacementEnum: string
{
    case None = 'none';

    /** The opening slide only. What a deck sent to a client usually wants. */
    case Cover = 'cover';

    /** A corner of every slide, small. What a deck shown in a room wants. */
    case Every = 'every';

    public function labelKey(): string
    {
        return 'backend.studio.decks.logo_placements.'.$this->value;
    }
}
