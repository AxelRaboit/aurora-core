<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Enum;

use Doctrine\ORM\QueryBuilder;

/**
 * Coarse-grained file type buckets, the same shape the Media module uses for
 * its list filters — exposed in Core so any list view that wants the same
 * "Images / Videos / PDF / Other" UX (GED Documents, future modules) can plug
 * the same enum into its repository + UI without duplicating the logic.
 *
 * Bind each case to a Doctrine `LIKE`/`=` clause via `applyTo($qb, $alias)`,
 * or ask the same question of a mime type already in hand via `matches()`.
 */
enum MimeGroupEnum: string
{
    case Image = 'image';
    case Video = 'video';
    case Pdf = 'pdf';
    case Other = 'other';

    /**
     * Append the matching condition to `$qb` against `<alias>.mimeType`.
     */
    public function applyTo(QueryBuilder $qb, string $alias): void
    {
        match ($this) {
            self::Image => $qb->andWhere(sprintf("%s.mimeType LIKE 'image/%%'", $alias)),
            self::Video => $qb->andWhere(sprintf("%s.mimeType LIKE 'video/%%'", $alias)),
            self::Pdf => $qb->andWhere(sprintf("%s.mimeType = 'application/pdf'", $alias)),
            self::Other => $qb->andWhere(sprintf(
                "%1\$s.mimeType NOT LIKE 'image/%%' AND %1\$s.mimeType NOT LIKE 'video/%%' AND %1\$s.mimeType <> 'application/pdf'",
                $alias,
            )),
        };
    }

    /**
     * The same buckets, decided against a mime type rather than in a query.
     *
     * Deliberately beside `applyTo` and not wherever it is first needed: these
     * two are one definition of what an image is, split across a WHERE clause
     * and a value. Let them drift and the library files a document under
     * Images that a renderer then refuses to draw — with nothing saying which
     * of the two is wrong. Anything holding a mime type asks here rather than
     * writing its own `str_starts_with`.
     *
     * A document with no mime type at all falls in no bucket, **not even
     * `Other`**. That is what the SQL above already answers: every one of
     * those comparisons against NULL is NULL, so the row matches no clause.
     */
    public function matches(?string $mimeType): bool
    {
        if (null === $mimeType) {
            return false;
        }

        return match ($this) {
            self::Image => str_starts_with($mimeType, 'image/'),
            self::Video => str_starts_with($mimeType, 'video/'),
            self::Pdf => 'application/pdf' === $mimeType,
            self::Other => !str_starts_with($mimeType, 'image/')
                && !str_starts_with($mimeType, 'video/')
                && 'application/pdf' !== $mimeType,
        };
    }
}
