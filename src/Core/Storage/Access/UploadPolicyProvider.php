<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Access;

use Aurora\Core\Locale\Service\LocaleContext;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;

use function max;

/**
 * Builds an {@see UploadPolicy} from what the administrator actually set.
 *
 * **Because the number in the panel was not the number that applied.**
 * `max_upload_size_mb` has been editable, stored and displayed since the
 * settings screen existed, and until 2026-09-16 no line of code read it: the
 * ceilings were written into `UploadPolicy` itself. An administrator lowering
 * it to 5 changed nothing, which is worse than having no setting, because a
 * panel that ignores what it is told teaches people not to trust the rest of
 * it.
 *
 * Reading a `Module` setting from `Core` follows what
 * {@see LocaleContext} and the frontend context
 * already do; settings are the one thing Core asks a module for.
 */
final readonly class UploadPolicyProvider
{
    private const int BYTES_PER_MB = 1024 * 1024;

    public function __construct(private SettingRepository $settings) {}

    public function forStaffDocuments(): UploadPolicy
    {
        return UploadPolicy::forStaffDocuments($this->ceilingBytes());
    }

    public function forSpaceGuests(): UploadPolicy
    {
        return UploadPolicy::forSpaceGuests($this->ceilingBytes());
    }

    /**
     * The administrator's ceiling in bytes, never zero.
     *
     * A blank or nonsensical value would otherwise refuse every upload ever
     * made, silently, for anybody who cleared the field to mean "no limit".
     * One megabyte is the floor: small enough that whoever typed it sees the
     * consequence immediately, rather than a site where filing has stopped
     * working with no message saying why.
     */
    private function ceilingBytes(): int
    {
        $configured = (int) $this->settings->getOrDefault(ApplicationParameterEnum::MaxUploadSizeMb);

        return max(1, $configured) * self::BYTES_PER_MB;
    }
}
