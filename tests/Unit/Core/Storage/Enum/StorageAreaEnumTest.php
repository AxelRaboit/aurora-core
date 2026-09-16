<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Storage\Enum;

use Aurora\Core\Storage\Enum\StorageAreaEnum;
use PHPUnit\Framework\TestCase;

/**
 * These values are path prefixes, not labels.
 *
 * Every file Aurora has ever stored lives under one of them, so renaming a
 * case strands everything written before the rename, silently and with no
 * error anywhere. That is what this test is for: not to restate the enum, but
 * to make a rename fail here rather than in a support message six months
 * later.
 */
final class StorageAreaEnumTest extends TestCase
{
    public function testTheValuesAreTheStoredPathsAndMustNotChange(): void
    {
        self::assertSame('ged', StorageAreaEnum::Ged->value);
        self::assertSame('profile-photos', StorageAreaEnum::ProfilePhotos->value);
        self::assertSame('contracts', StorageAreaEnum::Contracts->value);
        self::assertSame('notes-markdown', StorageAreaEnum::NotesMarkdown->value);
    }

    public function testNoCaseWasAddedWithoutBeingConsidered(): void
    {
        self::assertCount(4, StorageAreaEnum::cases());
    }
}
