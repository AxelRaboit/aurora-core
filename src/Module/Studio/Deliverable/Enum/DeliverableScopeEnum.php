<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Enum;

use function is_string;

/**
 * À qui appartient un livrable qui n'est rattaché à aucun espace client.
 *
 * Deux rayons, comme la demande les a dessinés : ce qu'on prépare pour soi, et
 * ce que l'équipe partage. Pas de membres ni de rôles : un livrable partagé se
 * lit et se modifie par quiconque a les droits du module, un livrable perso
 * par son auteur seul.
 *
 * Un livrable d'espace porte `Shared` sans s'en servir : c'est l'espace, et
 * l'appartenance à son équipe, qui décident de qui le voit.
 */
enum DeliverableScopeEnum: string
{
    case Personal = 'personal';
    case Shared = 'shared';

    /** Ce qui arrive d'un formulaire ; une valeur inconnue reste perso, le choix prudent. */
    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Personal) : self::Personal;
    }
}
