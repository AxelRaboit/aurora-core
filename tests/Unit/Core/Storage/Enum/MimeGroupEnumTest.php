<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Storage\Enum;

use Aurora\Core\Storage\Enum\MimeGroupEnum;
use PHPUnit\Framework\TestCase;

/**
 * `matches()` is the PHP half of what `applyTo()` says in SQL, and the two
 * have to answer the same question - a document the library files under
 * Images that a renderer then refuses to draw is a disagreement with nothing
 * to say which side is wrong.
 */
final class MimeGroupEnumTest extends TestCase
{
    public function testEachGroupClaimsItsOwnMimeTypes(): void
    {
        self::assertTrue(MimeGroupEnum::Image->matches('image/jpeg'));
        self::assertTrue(MimeGroupEnum::Video->matches('video/mp4'));
        self::assertTrue(MimeGroupEnum::Pdf->matches('application/pdf'));
        self::assertTrue(MimeGroupEnum::Other->matches('application/zip'));
    }

    public function testAGroupRefusesWhatBelongsToAnother(): void
    {
        self::assertFalse(MimeGroupEnum::Image->matches('video/mp4'));
        self::assertFalse(MimeGroupEnum::Image->matches('application/pdf'));
        self::assertFalse(MimeGroupEnum::Video->matches('image/jpeg'));
        self::assertFalse(MimeGroupEnum::Other->matches('image/webp'));
        self::assertFalse(MimeGroupEnum::Other->matches('application/pdf'));
    }

    /**
     * The prefix, not a whitelist: the query side is `LIKE 'image/%'`, so a
     * subtype nobody has enumerated is still an image. A closed list here
     * would refuse `image/avif` - which the library lists under Images and
     * every browser draws.
     */
    public function testAnUnenumeratedSubtypeStillBelongsToItsGroup(): void
    {
        self::assertTrue(MimeGroupEnum::Image->matches('image/avif'));
        self::assertTrue(MimeGroupEnum::Video->matches('video/webm'));
    }

    /**
     * A document with no mime type at all falls in no bucket, not even
     * `Other`. That is what the SQL already answers: each of its comparisons
     * against NULL is NULL, so the row matches no clause.
     */
    public function testAMissingMimeTypeBelongsToNoGroupAtAll(): void
    {
        foreach (MimeGroupEnum::cases() as $group) {
            self::assertFalse($group->matches(null), $group->value);
        }
    }
}
