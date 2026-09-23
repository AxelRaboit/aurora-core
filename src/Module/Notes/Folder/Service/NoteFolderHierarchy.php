<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Folder\Service;

use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;

use function count;

/**
 * Stateless questions about the shape of the tree.
 *
 * Kept out of the Manager because these are read-only computations with no
 * persistence, and out of the Controller because they are invariants of the
 * data rather than of one request: any caller able to move a folder can
 * destroy the tree with it.
 */
final readonly class NoteFolderHierarchy
{
    /**
     * How deep a folder may sit, root included.
     *
     * Not a storage limit. A tree deeper than this stops being navigable in a
     * breadcrumb, and the depth is the only thing standing between a careless
     * import and a path no screen can display.
     */
    public const int MAX_DEPTH = 8;

    /**
     * Whether filing `$folder` under `$newParent` would make a cycle.
     *
     * Walks up from the candidate parent. Compares by object first and by id
     * second, because a folder that has never been flushed has no id yet and
     * two of them would otherwise both read as null and match.
     *
     * A cycle already sitting in the data is treated as unsafe: the walk
     * never reaches a root, so nothing about the move can be shown to be
     * sound.
     */
    public function wouldCreateCycle(NoteFolderInterface $folder, ?NoteFolderInterface $newParent): bool
    {
        $seen = [];

        for ($node = $newParent; $node instanceof NoteFolderInterface; $node = $node->getParent()) {
            if ($node === $folder) {
                return true;
            }

            $id = $node->getId();
            if (null !== $id && $id === $folder->getId()) {
                return true;
            }

            $key = spl_object_id($node);
            if (isset($seen[$key])) {
                return true;
            }

            $seen[$key] = true;
        }

        return false;
    }

    /**
     * The chain from the root down to this folder, the folder last.
     *
     * This is what a breadcrumb draws, and it is computed by walking the
     * parent links rather than by a recursive query: a chain is at most
     * {@see MAX_DEPTH} rows, all of them already in the identity map when the
     * tree was loaded.
     *
     * @return list<NoteFolderInterface>
     */
    public function pathTo(?NoteFolderInterface $folder): array
    {
        $chain = [];
        $seen = [];

        for ($node = $folder; $node instanceof NoteFolderInterface; $node = $node->getParent()) {
            $key = spl_object_id($node);
            if (isset($seen[$key])) {
                break;
            }

            $seen[$key] = true;
            $chain[] = $node;

            if (count($chain) > self::MAX_DEPTH) {
                break;
            }
        }

        return array_reverse($chain);
    }

    /** How many levels down this folder sits, a root being 1. */
    public function depthOf(?NoteFolderInterface $folder): int
    {
        return count($this->pathTo($folder));
    }

    /**
     * Whether a folder moved under this parent would sit too deep.
     *
     * Measures the branch being moved, not just the folder: moving a
     * three-level branch one step below the limit puts its leaves past it.
     */
    public function wouldExceedDepth(?NoteFolderInterface $newParent, int $branchHeight = 1): bool
    {
        return $this->depthOf($newParent) + $branchHeight > self::MAX_DEPTH;
    }
}
