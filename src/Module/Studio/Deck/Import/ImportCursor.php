<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Import;

/**
 * Where the importer is in the document it is reading.
 *
 * A class rather than four local variables because the writing happens in six
 * methods and passing four of anything between them is how one of them stops
 * being updated. `filled` is the one that is easy to miss: it is what tells a
 * heading with nothing under it from a heading that opened a real slide, and
 * therefore whether to write a section divider when the next heading arrives.
 */
final class ImportCursor
{
    public string $title = '';

    /** @var list<string> */
    public array $paragraphs = [];

    /** Whether a slide has been written since the current heading was read. */
    public bool $filled = true;

    public int $count = 0;
}
