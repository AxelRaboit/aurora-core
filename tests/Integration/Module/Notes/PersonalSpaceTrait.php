<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A note written by hand in a test has to live somewhere: in its folder when
 * it has one, otherwise in its author's personal space, like a note created
 * from the screen.
 */
trait PersonalSpaceTrait
{
    private function personalSpaceOf(CoreUserInterface $user): NoteSpaceInterface
    {
        $access = static::getContainer()->get(NoteSpaceAccess::class);
        self::assertInstanceOf(NoteSpaceAccess::class, $access);

        // The account held by the test may no longer be tracked: a request
        // resets the entity manager.
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $managed = $entityManager->getReference($user::class, $user->getId());
        self::assertInstanceOf(CoreUserInterface::class, $managed);

        return $access->personalSpace($managed);
    }
}
