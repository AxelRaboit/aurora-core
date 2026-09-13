<?php

declare(strict_types=1);

namespace Aurora\Core\Trash;

use DateTimeImmutable;

/**
 * One trash: what is in it, and what can be done to it.
 *
 * The rows travel with the summary rather than behind a second request per
 * type. Five requests to draw one screen would be five chances for one type to
 * be missing without anybody noticing; here a source that fails takes the page
 * down with it, which is the honest outcome.
 *
 * The action routes are the module's own. The screen does not know how to
 * restore a document, and must not: it posts to the endpoint the module
 * already exposes, which is where the rules and the privileges live.
 */
final readonly class TrashSummary
{
    /**
     * @param string             $key              stable id for the tab, e.g. `ged_documents`
     * @param string             $labelKey         translation key naming what is in this trash
     * @param string             $icon             lucide icon name, as the side menu uses
     * @param int                $count            rows in the trash, whether or not they are all listed
     * @param list<TrashItem>    $items            the rows to show, newest deletion first
     * @param ?DateTimeImmutable $oldestDeletedAt  when the oldest of them was deleted, null when empty
     * @param ?string            $restoreRoute     route taking `{id}`, null when restoring is impossible
     * @param ?string            $forceDeleteRoute route taking `{id}`, null when destroying is impossible
     * @param ?string            $emptyTrashRoute  route emptying the whole trash, null when there is none
     * @param ?string            $actionPrivilege  privilege required to restore or destroy here
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $icon,
        public int $count,
        public array $items = [],
        public ?DateTimeImmutable $oldestDeletedAt = null,
        public ?string $restoreRoute = null,
        public ?string $forceDeleteRoute = null,
        public ?string $emptyTrashRoute = null,
        public ?string $actionPrivilege = null,
    ) {}
}
