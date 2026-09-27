<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Setting;

use Aurora\Module\Configuration\Setting\Provider\ApplicationParameterProviderInterface;

/**
 * Declares {@see MarkdownNoteSettingEnum} to the deploy-time settings sync.
 *
 * `aurora:application-parameter` deletes every `core_settings` row no
 * provider vouches for. The markdown note settings implemented the settings interface and
 * were drawn by the settings screen, but nothing yielded them: whatever an
 * administrator saved was gone after the next release, and the page quietly
 * fell back to the defaults. Found on 27/09/2026 - no such row existed in
 * production.
 */
final readonly class MarkdownNoteApplicationParameterProvider implements ApplicationParameterProviderInterface
{
    public function getParameters(): iterable
    {
        yield from MarkdownNoteSettingEnum::cases();
    }
}
