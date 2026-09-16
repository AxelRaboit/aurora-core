<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Core\Storage;

use Aurora\Core\Storage\Access\UploadPolicy;
use Aurora\Core\Storage\Access\UploadPolicyProvider;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Tests\Integration\IntegrationTestCase;

/**
 * The number in the settings screen is the number that applies.
 *
 * It was not, and that is the whole reason this exists. `max_upload_size_mb`
 * was stored, displayed and editable while the ceilings lived as literals in
 * {@see UploadPolicy}; somebody lowering it to 5 to protect a small disk got a
 * screen that accepted the change and a server that went on taking 100MB
 * files. A control that ignores what it is told is worse than no control,
 * because the neighbouring `file_versions_limit` does work and the group looks
 * trustworthy.
 *
 * Through the container rather than by constructing the provider, because what
 * broke was never the arithmetic - it was that nothing connected the two ends.
 */
final class UploadCeilingFollowsTheSettingTest extends IntegrationTestCase
{
    private const int MB = 1024 * 1024;

    private ?string $original = null;

    protected function setUp(): void
    {
        parent::setUp();
        static::createClient();
        $this->original = $this->settings()->get(ApplicationParameterEnum::MaxUploadSizeMb->value);
    }

    protected function tearDown(): void
    {
        $this->settings()->set(ApplicationParameterEnum::MaxUploadSizeMb->value, $this->original);

        parent::tearDown();
    }

    public function testTheLibraryCeilingIsWhateverWasConfigured(): void
    {
        $this->given('7');

        self::assertSame(7 * self::MB, $this->policies()->forStaffDocuments()->maxBytes);
    }

    public function testLoweringItLowersTheGuestsToo(): void
    {
        $this->given('10');

        self::assertSame(10 * self::MB, $this->policies()->forSpaceGuests()->maxBytes);
    }

    /**
     * Raising it does not raise the guests, which is the asymmetry worth
     * testing: whoever holds a space link holds a secret address rather than
     * an account, and how much disk they may fill is not a preference.
     */
    public function testRaisingItDoesNotRaiseTheGuests(): void
    {
        $this->given('500');

        self::assertSame(500 * self::MB, $this->policies()->forStaffDocuments()->maxBytes);
        self::assertSame(UploadPolicy::GUEST_CAP_BYTES, $this->policies()->forSpaceGuests()->maxBytes);
    }

    /**
     * Somebody clearing the field means "no limit", and would otherwise get a
     * ceiling of zero: every upload refused, everywhere, with a message about
     * file size and nothing saying why it started.
     */
    public function testAnEmptiedFieldDoesNotStopFilingAltogether(): void
    {
        $this->given('0');

        self::assertGreaterThan(0, $this->policies()->forStaffDocuments()->maxBytes);
    }

    public function testTheTypeAllowListIsNotConfigurable(): void
    {
        $this->given('500');

        // `allowed_upload_extensions` was removed rather than wired: the guest
        // list is a security property, and an editable version of it would be
        // a way to put `image/svg+xml` back from a form.
        self::assertNotContains('image/svg+xml', (array) $this->policies()->forSpaceGuests()->allowedMimeTypes);
        self::assertNull($this->policies()->forStaffDocuments()->allowedMimeTypes);
    }

    private function given(string $megabytes): void
    {
        $this->settings()->set(ApplicationParameterEnum::MaxUploadSizeMb->value, $megabytes);
    }

    private function policies(): UploadPolicyProvider
    {
        return static::getContainer()->get(UploadPolicyProvider::class);
    }

    private function settings(): SettingRepository
    {
        return static::getContainer()->get(SettingRepository::class);
    }
}
