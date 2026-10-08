<?php

declare(strict_types=1);

namespace Aurora\Module\Notes;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

final readonly class NotesContext
{
    public function __construct(private ModuleAccessChecker $moduleAccessChecker) {}

    public function isSuiteEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::NotesSuite);
    }

    public function isMarkdownEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::NotesMarkdown);
    }

    /**
     * Whether a note can be handed to somebody else at all: named people, or
     * an address that writes.
     *
     * On by default, like every module parameter whose setting is unset: no
     * installation loses anything by this arriving.
     *
     * **What off means, precisely.** No new grant can be opened - the guest
     * list and the write switch are gone from the screen and their routes
     * answer 404 - and the **guest write route refuses**, because an
     * unauthenticated write left running by accident is the one thing nobody
     * wants. What off does *not* do is take a note back from somebody it was
     * already handed to: they keep reading it, and the person who shared it
     * takes it back by hand. Reading an existing read-only share link is
     * untouched too; it predates this switch and does not belong to it.
     */
    public function isCollaborationEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::NotesCollaboration);
    }
}
