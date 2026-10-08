<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Storage\Setting;

use Aurora\Core\Encryption\Service\EncryptionServiceInterface;
use Aurora\Core\Storage\Enum\StorageDeliveryModeEnum;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\R2\EnvR2ConfigurationProvider;
use Aurora\Core\Storage\R2\R2Configuration;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use DateTimeImmutable;
use SensitiveParameter;

/**
 * Where this installation writes its files, and with whose account.
 *
 * Local until somebody says otherwise. Aurora is delivered to clients and the
 * bucket behind the credentials is theirs: Cloudflare bills the account holder
 * and holds them responsible for what is stored, so the person who accepts
 * that has to be the person who owns the site.
 *
 * Both credentials are encrypted at rest with
 * {@see EncryptionServiceInterface}, the same treatment the Pexels key and
 * mount point passwords get. A secret access key grants write access to every
 * file the installation owns, which makes it a good deal more valuable than
 * most of what is in this table.
 *
 * The environment still wins when it is set. A server that would rather keep
 * its secrets out of its own database is taking a defensible position, and
 * that is also how the credentials were first wired before this screen
 * existed.
 */
final readonly class StorageSettings
{
    public function __construct(
        private SettingRepository $settingRepository,
        private EncryptionServiceInterface $encryption,
        private EnvR2ConfigurationProvider $envR2ConfigurationProvider,
    ) {}

    /**
     * The disk new files go to.
     *
     * Falls back to local whenever the answer would otherwise be a backend
     * that cannot work: an unknown value, a configuration missing a field, or
     * one nothing has ever successfully reached. Writing to a backend that
     * will reject every call is worse than not switching at all.
     *
     * The verification requirement lives here rather than in the controller
     * that saves the tab, so it holds for every caller: a fixture, a console
     * command, a client extension. A gate that only guards the HTTP door is
     * not a gate.
     */
    public function activeDisk(): StorageDiskEnum
    {
        $stored = StorageDiskEnum::tryFrom((string) $this->settingRepository->get(StorageSettingEnum::ActiveDisk->value, ''));

        if (StorageDiskEnum::R2 !== $stored) {
            return StorageDiskEnum::Local;
        }

        // Judged on the EFFECTIVE configuration, not the stored one. An
        // operator who keeps the credentials in the server's environment, which
        // is a supported and documented arrangement, has nothing in this table
        // and would otherwise never be allowed to switch.
        $usable = $this->effectiveR2Configuration()->isComplete() && null !== $this->verifiedAt();

        return $usable ? StorageDiskEnum::R2 : StorageDiskEnum::Local;
    }

    /**
     * Whether there is anywhere to move a document to.
     *
     * Not the same question as which disk is active. An administrator can
     * configure and verify a bucket while leaving new files on the server's
     * disk, and moving a document across is then perfectly sensible. What must
     * not be offered is a move to a backend nothing has ever reached, which is
     * an action that can only fail.
     */
    public function isRelocationAvailable(): bool
    {
        return $this->effectiveR2Configuration()->isComplete() && null !== $this->verifiedAt();
    }

    public function deliveryMode(): StorageDeliveryModeEnum
    {
        return StorageDeliveryModeEnum::tryFrom(
            (string) $this->settingRepository->get(StorageSettingEnum::DeliveryMode->value, ''),
        ) ?? StorageDeliveryModeEnum::Proxy;
    }

    /**
     * The configuration actually used, environment over settings, field by
     * field.
     *
     * Two sources rather than one because they answer different situations.
     * The settings table is how a client configures their own bucket without
     * touching a server. The environment is how an operator keeps secrets out
     * of a database they back up nightly.
     *
     * Field by field, not wholesale: an operator who sets only the endpoint
     * should not silently lose the bucket an administrator saved.
     *
     * Normalised on the way out, and here rather than at the two sources,
     * because this is the one place every reader goes through. An endpoint
     * with the bucket pasted on it is corrected whether it came from the
     * settings screen, from an environment variable, or from a database row
     * written before any of this existed.
     */
    public function effectiveR2Configuration(): R2Configuration
    {
        $stored = $this->r2Configuration();
        $environment = $this->envR2ConfigurationProvider->current();

        return new R2Configuration(
            endpoint: '' !== $environment->endpoint ? $environment->endpoint : $stored->endpoint,
            bucket: '' !== $environment->bucket ? $environment->bucket : $stored->bucket,
            accessKeyId: '' !== $environment->accessKeyId ? $environment->accessKeyId : $stored->accessKeyId,
            secretAccessKey: '' !== $environment->secretAccessKey ? $environment->secretAccessKey : $stored->secretAccessKey,
            publicBaseUrl: $environment->publicBaseUrl ?? $stored->publicBaseUrl,
        )->withNormalisedEndpoint();
    }

    /** What this installation saved, ignoring the environment. */
    public function r2Configuration(): R2Configuration
    {
        $publicBaseUrl = $this->read(StorageSettingEnum::R2PublicBaseUrl);

        return new R2Configuration(
            endpoint: mb_rtrim($this->read(StorageSettingEnum::R2Endpoint), '/'),
            bucket: $this->read(StorageSettingEnum::R2Bucket),
            accessKeyId: $this->readSecret(StorageSettingEnum::R2AccessKeyId),
            secretAccessKey: $this->readSecret(StorageSettingEnum::R2SecretAccessKey),
            publicBaseUrl: '' === $publicBaseUrl ? null : mb_rtrim($publicBaseUrl, '/'),
        );
    }

    public function verifiedAt(): ?string
    {
        $value = $this->read(StorageSettingEnum::R2VerifiedAt);

        return '' !== $value ? $value : null;
    }

    /**
     * What the settings screen is allowed to know.
     *
     * `hasAccessKeyId` and `hasSecretAccessKey` rather than the values: the
     * browser needs to draw "a key is saved" and nothing more. Sending them
     * back so a form could pre-fill would put them in the page source of every
     * admin who opens the tab.
     *
     * @return array{activeDisk: string, deliveryMode: string, endpoint: string, bucket: string, publicBaseUrl: string, hasAccessKeyId: bool, hasSecretAccessKey: bool, isComplete: bool, verifiedAt: string|null}
     */
    public function state(): array
    {
        // Reported from the effective configuration: the tab has to show what
        // the application will actually use, including credentials it cannot
        // see because they come from the environment.
        $configuration = $this->effectiveR2Configuration();

        return [
            'activeDisk' => $this->activeDisk()->value,
            'deliveryMode' => $this->deliveryMode()->value,
            'endpoint' => $configuration->endpoint,
            'bucket' => $configuration->bucket,
            'publicBaseUrl' => $configuration->publicBaseUrl ?? '',
            'hasAccessKeyId' => '' !== $configuration->accessKeyId,
            'hasSecretAccessKey' => '' !== $configuration->secretAccessKey,
            'isComplete' => $configuration->isComplete(),
            // Where the answer above comes from. Without it the screen cannot
            // explain why clearing a field changes nothing.
            'fromEnvironment' => $this->isConfiguredByEnvironment(),
            'verifiedAt' => $this->verifiedAt(),
        ];
    }

    /**
     * @param string|null $accessKeyId     null leaves the stored credential alone,
     *                                     which is how a form that never received
     *                                     it can save the rest of the tab. An empty
     *                                     string is an explicit "forget it".
     * @param string|null $secretAccessKey same contract
     */
    public function save(
        StorageDiskEnum $activeDisk,
        StorageDeliveryModeEnum $deliveryMode,
        string $endpoint,
        string $bucket,
        #[SensitiveParameter]
        ?string $accessKeyId,
        #[SensitiveParameter]
        ?string $secretAccessKey,
        string $publicBaseUrl,
    ): void {
        $entries = [
            [StorageSettingEnum::ActiveDisk->value, $activeDisk->value],
            [StorageSettingEnum::DeliveryMode->value, $deliveryMode->value],
            [StorageSettingEnum::R2Endpoint->value, '' === $endpoint ? null : mb_rtrim($endpoint, '/')],
            [StorageSettingEnum::R2Bucket->value, '' === $bucket ? null : $bucket],
            [StorageSettingEnum::R2PublicBaseUrl->value, '' === $publicBaseUrl ? null : mb_rtrim($publicBaseUrl, '/')],
        ];

        foreach ([
            [StorageSettingEnum::R2AccessKeyId, $accessKeyId],
            [StorageSettingEnum::R2SecretAccessKey, $secretAccessKey],
        ] as [$key, $value]) {
            if (null === $value) {
                continue;
            }

            $entries[] = [$key->value, '' === $value ? null : $this->encryption->encrypt($value)];
        }

        // Any change to where or with what invalidates the last verification:
        // a doctor run proved that bucket with those keys, and one of them just
        // moved. Cleared before the write so the tab cannot show a green mark
        // for a configuration nobody has tried.
        if ($this->credentialsChanged($endpoint, $bucket, $accessKeyId, $secretAccessKey)) {
            $entries[] = [StorageSettingEnum::R2VerifiedAt->value, null];
        }

        $this->settingRepository->saveMany($entries);
    }

    /**
     * Whether the server's environment supplies any part of the R2
     * configuration.
     *
     * It matters because the environment wins over the settings table, field
     * by field. An administrator who clears the screen while a deployment
     * exports `R2_ENDPOINT` would see the form fill itself back in and
     * conclude nothing happened - when in fact the settings really were
     * cleared and simply have no say. The screen has to name that, and
     * disconnecting has to refuse rather than pretend.
     */
    public function isConfiguredByEnvironment(): bool
    {
        $environment = $this->envR2ConfigurationProvider->current();

        return '' !== $environment->endpoint
            || '' !== $environment->bucket
            || '' !== $environment->accessKeyId
            || '' !== $environment->secretAccessKey;
    }

    /**
     * Forgets the remote backend entirely and brings new files back home.
     *
     * Every field, including the two credentials, which is the one thing the
     * save form cannot do: it only sends a key somebody typed, so an empty
     * field there means "keep what is stored" and nothing ever means "forget
     * it". Without this, a configuration entered once could be pointed
     * elsewhere but never removed.
     *
     * The verification goes with it. Keeping a green mark for credentials that
     * no longer exist would offer a move to a backend nothing can reach.
     */
    public function disconnectR2(): void
    {
        $this->settingRepository->saveMany([
            [StorageSettingEnum::ActiveDisk->value, StorageDiskEnum::Local->value],
            [StorageSettingEnum::R2Endpoint->value, null],
            [StorageSettingEnum::R2Bucket->value, null],
            [StorageSettingEnum::R2AccessKeyId->value, null],
            [StorageSettingEnum::R2SecretAccessKey->value, null],
            [StorageSettingEnum::R2PublicBaseUrl->value, null],
            [StorageSettingEnum::R2VerifiedAt->value, null],
        ]);
    }

    /** Records that the backend answered, so the toggle may be turned on. */
    public function markVerified(): void
    {
        $this->settingRepository->set(
            StorageSettingEnum::R2VerifiedAt->value,
            new DateTimeImmutable()->format(DateTimeImmutable::ATOM),
        );
    }

    private function credentialsChanged(
        string $endpoint,
        string $bucket,
        #[SensitiveParameter]
        ?string $accessKeyId,
        #[SensitiveParameter]
        ?string $secretAccessKey,
    ): bool {
        $current = $this->r2Configuration();

        return mb_rtrim($endpoint, '/') !== $current->endpoint
            || $bucket !== $current->bucket
            || (null !== $accessKeyId && $accessKeyId !== $current->accessKeyId)
            || (null !== $secretAccessKey && $secretAccessKey !== $current->secretAccessKey);
    }

    private function read(StorageSettingEnum $key): string
    {
        return mb_trim((string) $this->settingRepository->get($key->value, ''));
    }

    /**
     * A credential written before the encryption key was rotated decrypts to
     * null. Treated as absent rather than fatal: the backend goes quiet, the
     * settings tab asks for the key again, and that is the one thing that
     * fixes it.
     */
    private function readSecret(StorageSettingEnum $key): string
    {
        $stored = $this->read($key);

        return '' === $stored ? '' : ($this->encryption->decrypt($stored) ?? '');
    }
}
