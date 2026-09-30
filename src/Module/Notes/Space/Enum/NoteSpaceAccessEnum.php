<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Enum;

/**
 * Qui a accès à un espace, dans le back-office.
 *
 * Indépendant de la publication sur le web : un espace qu'on est seul à écrire
 * peut être publié, un espace ouvert à tout le back-office peut ne pas l'être.
 */
enum NoteSpaceAccessEnum: string
{
    /** Le propriétaire seul. L'espace personnel l'est toujours. */
    case Private = 'private';

    /** Les personnes inscrites, chacune avec son rôle. */
    case Members = 'members';

    /**
     * Toute personne qui a le module, avec le rôle par défaut de l'espace ;
     * un membre inscrit garde le sien s'il est plus fort.
     */
    case Backoffice = 'backoffice';

    public static function fromInput(mixed $value): self
    {
        return self::tryFrom(is_string($value) ? $value : '') ?? self::Private;
    }
}
