<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Access;

use Aurora\Core\Storage\Enum\StorageAreaEnum;

/**
 * Says who may read one key under `var/uploads`.
 *
 * `/uploads/{path}` is a catch-all by design (see CLAUDE.md §5bis): the
 * address an editor writes into the body of a publication must not move when
 * the file behind it does. The cost of a single address is that it cannot
 * carry an access rule of its own, so the rule is attached to the *area*
 * instead, and this is where an area states it.
 *
 * Implementations are registered with the `aurora.upload_access_guard` tag
 * and consulted in turn by {@see UploadAccessDecider}; the first one that
 * claims the key decides. An area nobody claims stays anonymous, which is
 * what the endpoint did before guards existed and what a client's own area
 * keeps doing until it declares otherwise.
 *
 * A guard answers about a **key**, not an entity: the endpoint receives a
 * path and nothing else, and a key can belong to a derived file (a variant, a
 * rendered thumbnail) whose owner has to be found before the question can be
 * answered at all.
 */
interface UploadAccessGuardInterface
{
    /** Whether this guard owns `$key` - typically a {@see StorageAreaEnum} prefix. */
    public function supports(string $key): bool;

    /**
     * Only called when {@see supports()} returned true. Free to consult the
     * current token: the decision is per-request, not per-file.
     */
    public function decide(string $key): UploadAccessEnum;
}
