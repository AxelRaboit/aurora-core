<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Enum;

/**
 * Ce qu'une personne peut faire dans un espace.
 *
 * Trois rôles, et le propriétaire au-dessus - lui seul supprime ou cède
 * l'espace. Deux rôles ne suffisaient pas : confier les membres à quelqu'un
 * obligeait à lui donner aussi la suppression.
 */
enum NoteSpaceRoleEnum: string
{
    /** Lire, chercher, exporter. */
    case Reader = 'reader';

    /** Et écrire, créer, ranger, mettre à la corbeille. */
    case Editor = 'editor';

    /** Et vider la corbeille, régler l'espace, gérer les membres, publier. */
    case Manager = 'manager';

    public function canWrite(): bool
    {
        return self::Reader !== $this;
    }

    public function canManage(): bool
    {
        return self::Manager === $this;
    }

    /** Le plus fort de deux rôles : un membre inscrit garde le sien s'il dépasse celui de tous. */
    public function atLeast(self $other): self
    {
        return $this->rank() >= $other->rank() ? $this : $other;
    }

    public static function fromInput(mixed $value, self $default = self::Reader): self
    {
        return self::tryFrom(is_string($value) ? $value : '') ?? $default;
    }

    private function rank(): int
    {
        return match ($this) {
            self::Reader => 1,
            self::Editor => 2,
            self::Manager => 3,
        };
    }
}
