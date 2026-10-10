<?php

declare(strict_types=1);

namespace Aurora\Core\Trash;

/**
 * A trash that can say how much it holds without building its summary.
 *
 * The side menu prints the total of every trash the reader can open beside
 * « Corbeille », on every page of the suite. Building each summary for that
 * - its first row, its oldest date - cost twenty queries a page; a source
 * that implements this answers in one.
 *
 * Optional, and a separate interface rather than a method added to
 * {@see TrashSourceInterface}: a client's own trash source keeps working
 * unchanged, and is counted through its summary instead. Whatever it does,
 * the figure must be the summary's `count` - the same scope, the same rows.
 */
interface CountableTrashSourceInterface extends TrashSourceInterface
{
    public function countTrashed(): int;
}
