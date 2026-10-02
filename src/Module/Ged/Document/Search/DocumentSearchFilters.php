<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Search;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\InputBag;

use function preg_match;

/**
 * The library's search beyond the title: which fields the box reads, and the
 * filters that go with it (added between two dates, shape, weight, documents
 * left without a category or a tag).
 *
 * One value rather than eight more arguments on the listing: the repository
 * already takes a dozen, and these travel together from the address to the
 * query. Every field defaults to "no constraint", so a listing built without
 * it is the listing it always was.
 */
final readonly class DocumentSearchFilters
{
    /** What the category and tag filters send for "none at all". */
    public const string NONE = 'none';

    public function __construct(
        public DocumentSearchFieldEnum $searchIn = DocumentSearchFieldEnum::All,
        public bool $uncategorized = false,
        public bool $untagged = false,
        public ?DateTimeImmutable $addedFrom = null,
        public ?DateTimeImmutable $addedTo = null,
        public ?DocumentOrientationEnum $orientation = null,
        public ?DocumentWeightEnum $weight = null,
    ) {}

    /**
     * Read from the listing's query string. Anything unreadable is dropped
     * rather than refused: a stale bookmark should still open the library.
     *
     * `addedTo` is the end of that day, so "until the 2nd" includes the 2nd.
     *
     * @param InputBag<string> $query
     */
    public static function fromQuery(InputBag $query): self
    {
        return new self(
            searchIn: DocumentSearchFieldEnum::tryFrom($query->getString('searchIn')) ?? DocumentSearchFieldEnum::All,
            uncategorized: self::NONE === $query->getString('categoryId'),
            untagged: self::NONE === $query->getString('tagId'),
            addedFrom: self::day($query->getString('addedFrom'))?->setTime(0, 0),
            addedTo: self::day($query->getString('addedTo'))?->setTime(23, 59, 59),
            orientation: DocumentOrientationEnum::tryFrom($query->getString('orientation')),
            weight: DocumentWeightEnum::tryFrom($query->getString('weight')),
        );
    }

    private static function day(string $value): ?DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $day || $day->format('Y-m-d') !== $value ? null : $day;
    }
}
