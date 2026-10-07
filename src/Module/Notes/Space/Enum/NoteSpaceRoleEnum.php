<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Enum;

/**
 * What a person can do in a space.
 *
 * Three roles, and the owner above them - only the owner deletes or hands
 * over the space. Two roles were not enough: entrusting the members to
 * somebody meant giving them deletion too.
 */
enum NoteSpaceRoleEnum: string
{
    /** Lire, chercher, exporter. */
    case Reader = 'reader';

    /** Plus write, create, file, move to the trash. */
    case Editor = 'editor';

    /** Plus empty the trash, configure the space, manage members, publish. */
    case Manager = 'manager';

    public function canWrite(): bool
    {
        return self::Reader !== $this;
    }

    public function canManage(): bool
    {
        return self::Manager === $this;
    }

    /** The stronger of two roles: a member keeps their own if it exceeds everyone's. */
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
