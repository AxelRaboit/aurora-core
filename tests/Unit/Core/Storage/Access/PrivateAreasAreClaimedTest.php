<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Storage\Access;

use Aurora\Core\Storage\Access\UploadAccessDecider;
use Aurora\Core\Storage\Access\UploadAccessEnum;
use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Module\Notes\Markdown\Access\NotesImageUploadAccessGuard;
use Aurora\Module\Platform\User\Access\ProfilePhotoUploadAccessGuard;
use Aurora\Module\Studio\Contract\Access\ContractUploadAccessGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * Every storage area holding private files has a guard that says so.
 *
 * {@see UploadAccessDecider} leaves an unclaimed prefix anonymous, and that
 * default is deliberate - refusing what nobody claims would break a client
 * storing things under a prefix aurora-core has never heard of. The price is
 * that a private area nobody claims is silently public, which is exactly what
 * had happened to `profile-photos/` and `notes-markdown/`: measured on
 * 2026-09-16, a file under either answered 200 with no session at all.
 *
 * This is the test that would have caught it. It does not re-check what a
 * guard decides - each guard argues its own case in its docblock - it checks
 * that a decision is made at all.
 *
 * **The GED is deliberately not in the list.** It claims its area and answers
 * anonymous for published documents on purpose: a picture embedded in a public
 * page needs one stable cacheable address and no session.
 */
final class PrivateAreasAreClaimedTest extends TestCase
{
    /**
     * @return iterable<string, array{StorageAreaEnum}>
     */
    public static function privateAreas(): iterable
    {
        yield 'profile photos' => [StorageAreaEnum::ProfilePhotos];
        yield 'contracts' => [StorageAreaEnum::Contracts];
        yield 'notes images' => [StorageAreaEnum::NotesMarkdown];
    }

    #[DataProvider('privateAreas')]
    public function testAPrivateAreaIsNeverAnonymous(StorageAreaEnum $area): void
    {
        self::assertSame(
            UploadAccessEnum::Denied,
            $this->decider()->decide(sprintf('%s/whatever/file.jpg', $area->value)),
            sprintf(
                'The "%s" area holds private files. With no guard claiming it, '
                .'UploadAccessDecider leaves it anonymous and /uploads/ hands the file '
                .'to anybody who knows the address.',
                $area->value,
            ),
        );
    }

    /**
     * The default itself, asserted so that changing it stays a deliberate act.
     */
    public function testAnUnclaimedPrefixIsStillAnonymous(): void
    {
        self::assertSame(
            UploadAccessEnum::Anonymous,
            $this->decider()->decide('some-client-area/file.jpg'),
        );
    }

    public function testATraversalIsRefusedBeforeAnyGuardIsAsked(): void
    {
        $decider = $this->decider();

        self::assertSame(UploadAccessEnum::Denied, $decider->decide('ged/../contracts/secret.pdf'));
        self::assertSame(UploadAccessEnum::Denied, $decider->decide('/etc/passwd'));
    }

    private function decider(): UploadAccessDecider
    {
        // The GED's own guard needs a repository and is left out: every key
        // here belongs to an area it does not support, and a guard that does
        // not support a key is never asked to decide.
        return new UploadAccessDecider([
            new ProfilePhotoUploadAccessGuard(),
            new ContractUploadAccessGuard(),
            new NotesImageUploadAccessGuard(),
        ]);
    }
}
