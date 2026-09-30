<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space;

/**
 * Où vit une note ou un dossier : le carnet de quelqu'un, ou celui de
 * l'équipe.
 *
 * **Personnel** est l'espace par défaut, et le seul qui existait : ce qu'on y
 * range n'est qu'à soi, sauf à le partager en lecture. **Équipe** est un
 * carnet commun à tout le back-office : tous ceux qui ont le module le lisent,
 * ceux qui ont le droit `notes.team.edit` y écrivent. Une note vit toujours
 * dans l'espace de son dossier.
 *
 * Une colonne et pas une entité : il n'y a qu'un espace d'équipe par
 * installation, et un « espace » de plus serait une table dont chaque requête
 * du module devrait tenir compte sans qu'elle serve à personne.
 */
enum NoteSpaceEnum: string
{
    case Personal = 'personal';
    case Team = 'team';

    /** Le droit qui ouvre l'écriture dans l'espace Équipe. */
    public const string TEAM_EDIT = 'notes.team.edit';

    public static function fromInput(mixed $value): self
    {
        return self::tryFrom(is_string($value) ? $value : '') ?? self::Personal;
    }
}
