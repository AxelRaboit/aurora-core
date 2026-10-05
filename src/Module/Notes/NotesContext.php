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
}
