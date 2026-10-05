<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Storage;

use Aurora\Core\Encryption\Service\EncryptionServiceInterface;
use Aurora\Core\Storage\Enum\StorageDeliveryModeEnum;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\R2\EnvR2ConfigurationProvider;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Configuration\Storage\Setting\StorageSettings;
use PHPUnit\Framework\TestCase;

/**
 * The rules that decide whether an installation is allowed to write elsewhere.
 *
 * Each of these was found by pointing the thing at a real bucket rather than
 * by reading the code, which is why they are pinned here: they are the kind of
 * rule that looks obviously right once written down and is silently absent
 * until something breaks.
 */
final class StorageSettingsTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $rows = [];

    public function testItStaysLocalUntilSomebodyChoosesOtherwise(): void
    {
        self::assertSame(StorageDiskEnum::Local, $this->settings()->activeDisk());
    }

    /**
     * The gate lives on the reading side on purpose. Guarding only the
     * controller that saves the tab would leave a fixture, a console command
     * or a client extension free to point the application at a bucket nothing
     * has ever reached.
     */
    public function testChoosingR2IsNotEnoughWithoutAVerification(): void
    {
        $settings = $this->settings(environment: $this->completeEnvironment());
        $settings->save(
            StorageDiskEnum::R2,
            StorageDeliveryModeEnum::Proxy,
            'https://account.r2.cloudflarestorage.com',
            'bucket',
            null,
            null,
            '',
        );

        self::assertSame(StorageDiskEnum::Local, $settings->activeDisk());
    }

    public function testItSwitchesOnceTheSuiteHasAnswered(): void
    {
        $settings = $this->settings(environment: $this->completeEnvironment());
        $settings->save(
            StorageDiskEnum::R2,
            StorageDeliveryModeEnum::Proxy,
            'https://account.r2.cloudflarestorage.com',
            'bucket',
            null,
            null,
            '',
        );
        $settings->markVerified();

        self::assertSame(StorageDiskEnum::R2, $settings->activeDisk());
    }

    public function testAnIncompleteConfigurationCannotBeActiveEvenOnceVerified(): void
    {
        $settings = $this->settings();
        $settings->save(StorageDiskEnum::R2, StorageDeliveryModeEnum::Proxy, '', '', '', '', '');
        $settings->markVerified();

        self::assertSame(StorageDiskEnum::Local, $settings->activeDisk());
    }

    /**
     * Credentials in the server's environment are a supported arrangement, and
     * an installation using it has nothing in this table. Judging completeness
     * on the stored configuration alone would make the switch unreachable for
     * exactly the operators most likely to want it.
     */
    public function testCredentialsFromTheEnvironmentCountAsConfiguration(): void
    {
        $settings = $this->settings(environment: $this->completeEnvironment());
        $settings->save(StorageDiskEnum::R2, StorageDeliveryModeEnum::Proxy, '', '', null, null, '');
        $settings->markVerified();

        self::assertTrue($settings->effectiveR2Configuration()->isComplete());
        self::assertSame(StorageDiskEnum::R2, $settings->activeDisk());
    }

    public function testTheEnvironmentWinsFieldByFieldRatherThanWholesale(): void
    {
        $settings = $this->settings(environment: new EnvR2ConfigurationProvider(
            'https://from-env.r2.cloudflarestorage.com',
            null,
            null,
            null,
            null,
        ));
        $settings->save(
            StorageDiskEnum::Local,
            StorageDeliveryModeEnum::Proxy,
            'https://from-settings.r2.cloudflarestorage.com',
            'bucket-from-settings',
            'stored-key',
            'stored-secret',
            '',
        );

        $configuration = $settings->effectiveR2Configuration();

        self::assertSame('https://from-env.r2.cloudflarestorage.com', $configuration->endpoint);
        self::assertSame('bucket-from-settings', $configuration->bucket, 'a bucket saved in the tab survives an endpoint set in the environment');
    }

    /**
     * A verification proves one bucket reachable with one pair of keys. Moving
     * any of them proves nothing about the new ones, and a tab showing a green
     * mark for a configuration nobody has tried is worse than one showing
     * none.
     */
    public function testChangingTheBucketRevokesTheVerification(): void
    {
        $settings = $this->settings(environment: $this->completeEnvironment());
        $settings->save(StorageDiskEnum::Local, StorageDeliveryModeEnum::Proxy, 'https://a.example', 'first', null, null, '');
        $settings->markVerified();
        self::assertNotNull($settings->verifiedAt());

        $settings->save(StorageDiskEnum::Local, StorageDeliveryModeEnum::Proxy, 'https://a.example', 'second', null, null, '');

        self::assertNull($settings->verifiedAt());
    }

    public function testSavingWithoutTouchingTheKeysKeepsThem(): void
    {
        $settings = $this->settings();
        $settings->save(StorageDiskEnum::Local, StorageDeliveryModeEnum::Proxy, 'https://a.example', 'bucket', 'the-key', 'the-secret', '');

        $settings->save(StorageDiskEnum::Local, StorageDeliveryModeEnum::Presigned, 'https://a.example', 'bucket', null, null, '');

        self::assertSame('the-key', $settings->r2Configuration()->accessKeyId);
        self::assertSame(StorageDeliveryModeEnum::Presigned, $settings->deliveryMode());
    }

    public function testAnEmptyStringForgetsAKey(): void
    {
        $settings = $this->settings();
        $settings->save(StorageDiskEnum::Local, StorageDeliveryModeEnum::Proxy, 'https://a.example', 'bucket', 'the-key', 'the-secret', '');

        $settings->save(StorageDiskEnum::Local, StorageDeliveryModeEnum::Proxy, 'https://a.example', 'bucket', '', null, '');

        self::assertSame('', $settings->r2Configuration()->accessKeyId);
    }

    /**
     * The browser is told a key exists, never what it is.
     */
    public function testTheStateNeverCarriesACredential(): void
    {
        $settings = $this->settings();
        $settings->save(StorageDiskEnum::Local, StorageDeliveryModeEnum::Proxy, 'https://a.example', 'bucket', 'the-key', 'the-secret', '');

        $state = $settings->state();

        self::assertTrue($state['hasAccessKeyId']);
        self::assertTrue($state['hasSecretAccessKey']);
        self::assertSame(
            [],
            array_intersect(['the-key', 'the-secret'], array_map(strval(...), array_filter($state, is_scalar(...)))),
            'no credential appears anywhere in what the tab receives',
        );
    }

    /**
     * Disconnecting is the one thing the save form cannot do: it only ever
     * sends a key somebody typed, so an empty field there means "keep what is
     * stored" and nothing ever means "forget it".
     */
    public function testDisconnectingForgetsEverything(): void
    {
        $settings = $this->settings();
        $settings->save(
            StorageDiskEnum::Local,
            StorageDeliveryModeEnum::Proxy,
            'https://account.r2.cloudflarestorage.com',
            'bucket',
            'key',
            'secret',
            '',
        );
        $settings->markVerified();

        $settings->disconnectR2();

        $state = $settings->state();
        self::assertSame('', $state['endpoint']);
        self::assertSame('', $state['bucket']);
        self::assertFalse($state['hasAccessKeyId']);
        self::assertFalse($state['hasSecretAccessKey']);
        self::assertFalse($state['isComplete']);
        self::assertSame(StorageDiskEnum::Local->value, $state['activeDisk']);
    }

    /**
     * The verification goes with the credentials. Keeping a green mark for
     * keys that no longer exist would offer a move to a backend nothing can
     * reach.
     */
    public function testDisconnectingCancelsTheVerification(): void
    {
        $settings = $this->settings();
        $settings->save(
            StorageDiskEnum::Local,
            StorageDeliveryModeEnum::Proxy,
            'https://account.r2.cloudflarestorage.com',
            'bucket',
            'key',
            'secret',
            '',
        );
        $settings->markVerified();
        self::assertNotNull($settings->verifiedAt());

        $settings->disconnectR2();

        self::assertNull($settings->verifiedAt());
        self::assertFalse($settings->isRelocationAvailable());
    }

    /**
     * A configuration held in the server's environment cannot be removed from
     * a screen, and the screen has to know that: clearing the rows underneath
     * would leave the form looking untouched, which reads as a button that
     * does nothing.
     */
    public function testAnEnvironmentConfigurationIsReportedAsSuch(): void
    {
        self::assertTrue($this->settings(environment: $this->completeEnvironment())->isConfiguredByEnvironment());
        self::assertFalse($this->settings()->isConfiguredByEnvironment());
    }

    public function testDisconnectingDoesNotSilenceTheEnvironment(): void
    {
        $settings = $this->settings(environment: $this->completeEnvironment());

        $settings->disconnectR2();

        // The rows are gone, the environment is not, and `state()` reports
        // what the application will actually use.
        self::assertTrue($settings->state()['isComplete']);
        self::assertTrue($settings->state()['fromEnvironment']);
    }

    private function settings(?EnvR2ConfigurationProvider $environment = null): StorageSettings
    {
        $this->rows = [];

        $repository = $this->createStub(SettingRepository::class);
        $repository->method('get')->willReturnCallback(
            fn (string $key, ?string $default = null): ?string => $this->rows[$key] ?? $default,
        );
        $repository->method('set')->willReturnCallback(
            function (string $key, ?string $value): void {
                $this->rows[$key] = $value;
            },
        );
        $repository->method('saveMany')->willReturnCallback(
            function (iterable $entries): void {
                foreach ($entries as [$key, $value]) {
                    $this->rows[$key] = $value;
                }
            },
        );

        // Reversible rather than real: this test is about the rules around the
        // credentials, not about the cipher, which has its own tests.
        $encryption = $this->createStub(EncryptionServiceInterface::class);
        $encryption->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'enc:'.$plain);
        $encryption->method('decrypt')->willReturnCallback(
            static fn (string $encoded): ?string => str_starts_with($encoded, 'enc:') ? mb_substr($encoded, 4) : null,
        );

        return new StorageSettings(
            $repository,
            $encryption,
            $environment ?? new EnvR2ConfigurationProvider(null, null, null, null, null),
        );
    }

    private function completeEnvironment(): EnvR2ConfigurationProvider
    {
        return new EnvR2ConfigurationProvider(
            'https://env.r2.cloudflarestorage.com',
            'env-bucket',
            'env-key',
            'env-secret',
            null,
        );
    }
}
