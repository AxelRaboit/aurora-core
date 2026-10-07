<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Controller;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;

/**
 * The only thing that separates the boards of two customers.
 *
 * Each screen of a space names the space in its address, then receives the
 * card, the note, the message or the file as its own entity by its id.
 * Nothing then stops a forged request from pointing at one customer's note
 * under another's space: this line is what stops it, and it used to live
 * copied in five controllers.
 *
 * **404 and not 403.** Telling the two apart would tell whoever holds an
 * address what they hold, which the public routes of this module already
 * refuse to say.
 */
trait SpaceOwnershipTrait
{
    /**
     * @param int|null $ownerId the space that what was received belongs to
     */
    protected function assertOwned(CustomerSpace $space, ?int $ownerId): void
    {
        if ($ownerId !== $space->getId()) {
            throw $this->createNotFoundException();
        }
    }
}
