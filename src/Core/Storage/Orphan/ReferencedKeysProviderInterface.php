<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Orphan;

use Aurora\Core\Storage\Enum\StorageAreaEnum;

/**
 * What a module still points at in one storage area.
 *
 * The sweep that removes unreferenced files knows how to list an area and
 * nothing about what a row is. Each module answers for its own: the GED names
 * a document's file, its still, its variants and every version's snapshot; the
 * platform names the photo on each account.
 *
 * **An area with no provider is never swept**, and that is the safe direction:
 * a sweep that could not name what a module references would delete the
 * module's files. An area gains a sweep by gaining a provider, never by
 * default.
 */
interface ReferencedKeysProviderInterface
{
    public function area(): StorageAreaEnum;

    /**
     * Storage keys, as they are stored: relative to the upload root, including
     * the area prefix.
     *
     * @return iterable<string>
     */
    public function referencedKeys(): iterable;
}
