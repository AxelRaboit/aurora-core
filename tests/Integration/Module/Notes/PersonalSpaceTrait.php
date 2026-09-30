<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Une note écrite à la main dans un test doit vivre quelque part : dans son
 * dossier quand elle en a un, sinon dans l'espace personnel de son auteur,
 * comme une note créée par l'écran.
 */
trait PersonalSpaceTrait
{
    private function personalSpaceOf(CoreUserInterface $user): NoteSpaceInterface
    {
        $access = static::getContainer()->get(NoteSpaceAccess::class);
        self::assertInstanceOf(NoteSpaceAccess::class, $access);

        // Le compte tenu par le test peut ne plus être suivi : une requête
        // remet le gestionnaire d'entités à zéro.
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $managed = $entityManager->getReference($user::class, $user->getId());
        self::assertInstanceOf(CoreUserInterface::class, $managed);

        return $access->personalSpace($managed);
    }
}
