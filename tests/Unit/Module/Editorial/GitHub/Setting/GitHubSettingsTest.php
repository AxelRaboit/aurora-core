<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\GitHub\Setting;

use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Editorial\GitHub\Setting\GitHubSettingEnum;
use Aurora\Module\Editorial\GitHub\Setting\GitHubSettings;
use PHPUnit\Framework\TestCase;

/**
 * Which GitHub accounts a site shows, and when it shows none.
 *
 * The logins end up in an address, so what is worth testing is that nothing
 * but a well-formed username ever comes out of here - whatever was typed, and
 * whatever the row holds.
 */
final class GitHubSettingsTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $store = [];

    private function settings(): GitHubSettings
    {
        $repository = $this->createStub(SettingRepository::class);
        $repository->method('get')->willReturnCallback(
            fn (string $key, ?string $default = null): ?string => $this->store[$key] ?? $default,
        );
        $repository->method('getBoolean')->willReturnCallback(
            fn (string $key, bool $default = false): bool => '1' === ($this->store[$key] ?? ($default ? '1' : '0')),
        );
        $repository->method('saveMany')->willReturnCallback(function (iterable $entries): void {
            foreach ($entries as [$key, $value]) {
                $this->store[$key] = $value;
            }
        });

        return new GitHubSettings($repository);
    }

    public function testItIsOffUntilSomebodyTurnsItOn(): void
    {
        self::assertFalse($this->settings()->isEnabled());
    }

    public function testTheToggleAloneShowsNothing(): void
    {
        $this->settings()->save(enabled: true, logins: []);

        self::assertFalse($this->settings()->isEnabled());
    }

    public function testASavedListComesBackInItsOrder(): void
    {
        $this->settings()->save(enabled: true, logins: ['AxelRaboit', 'axelr7x']);

        self::assertTrue($this->settings()->isEnabled());
        self::assertSame(['AxelRaboit', 'axelr7x'], $this->settings()->logins());
    }

    public function testLinesCommasAndAtSignsAreAllAccepted(): void
    {
        [$valid, $invalid] = GitHubSettings::parse("@AxelRaboit\naxelr7x, octocat");

        self::assertSame(['AxelRaboit', 'axelr7x', 'octocat'], $valid);
        self::assertSame([], $invalid);
    }

    public function testADuplicateIsIgnoredWhateverItsCase(): void
    {
        [$valid] = GitHubSettings::parse("AxelRaboit\naxelraboit");

        self::assertSame(['AxelRaboit'], $valid);
    }

    /**
     * Refused rather than repaired: a slash or a query string in a username is
     * an attempt to reach another address, not a typo.
     */
    public function testWhatGitHubWouldRefuseIsReported(): void
    {
        [$valid, $invalid] = GitHubSettings::parse("good-name\n-edge\nbad--dash\nx/../y\nname?tab=1");

        self::assertSame(['good-name'], $valid);
        self::assertSame(['-edge', 'bad--dash', 'x/../y', 'name?tab=1'], $invalid);
    }

    /** A row written by hand never reaches an address it should not. */
    public function testAStoredRowIsFilteredOnTheWayOut(): void
    {
        $this->store[GitHubSettingEnum::Logins->value] = "AxelRaboit\n../admin\none\ntwo\nthree\nfour";

        self::assertSame(['AxelRaboit', 'one', 'two', 'three'], $this->settings()->logins());
    }
}
