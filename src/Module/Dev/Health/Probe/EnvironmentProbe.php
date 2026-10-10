<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\Health\Probe;

use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\StorageManager;
use Aurora\Core\Version\AppVersion;
use Aurora\Module\Dev\Health\Enum\HealthStatusEnum;
use Aurora\Module\Dev\Health\Report\HealthCheck;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * What the application runs on: its version, PHP, the disk, the mail and the
 * file storage.
 *
 * **The mail is named by its scheme, never its address.** A DSN carries the
 * password of the mail account; the block says « smtp », « resend+api » or
 * « null », and « null » is worth a warning: nothing leaves.
 */
final readonly class EnvironmentProbe
{
    /** Below this share of free disk, a warning; below half of it, an alert. */
    private const float DISK_WARNING_RATIO = 0.10;

    public function __construct(
        private AppVersion $appVersion,
        private StorageManager $storageManager,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDirectory,
        #[Autowire(env: 'default::MAILER_DSN')]
        private ?string $mailerDsn = null,
    ) {}

    /** @return list<HealthCheck> */
    public function checks(): array
    {
        return [
            $this->application(),
            $this->disk(),
            $this->mail(),
            $this->storage(),
        ];
    }

    private function application(): HealthCheck
    {
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $opcacheOn = is_array($opcache) && true === ($opcache['opcache_enabled'] ?? false);

        return new HealthCheck('application', HealthStatusEnum::Ok, 'suite.health.application.label', 'suite.health.application.running', [
            'version' => $this->appVersion->current(),
            'php' => PHP_VERSION,
        ], [
            'version' => $this->appVersion->current(),
            'php' => PHP_VERSION,
            'opcache' => $opcacheOn,
        ]);
    }

    private function disk(): HealthCheck
    {
        $directory = $this->projectDirectory.'/var';
        $free = @disk_free_space($directory);
        $total = @disk_total_space($directory);

        if (false === $free || false === $total || $total <= 0) {
            return new HealthCheck('disk', HealthStatusEnum::Unknown, 'suite.health.disk.label', 'suite.health.disk.unknown');
        }

        $ratio = $free / $total;
        $status = match (true) {
            $ratio < self::DISK_WARNING_RATIO / 2 => HealthStatusEnum::Danger,
            $ratio < self::DISK_WARNING_RATIO => HealthStatusEnum::Warning,
            default => HealthStatusEnum::Ok,
        };

        return new HealthCheck('disk', $status, 'suite.health.disk.label', 'suite.health.disk.free', [
            'percent' => (int) round($ratio * 100),
        ], [
            'freeBytes' => (int) $free,
            'totalBytes' => (int) $total,
        ]);
    }

    private function mail(): HealthCheck
    {
        $dsn = mb_trim((string) $this->mailerDsn);
        $scheme = '' === $dsn ? null : (parse_url($dsn, PHP_URL_SCHEME) ?: null);

        if (null === $scheme) {
            return new HealthCheck('mail', HealthStatusEnum::Danger, 'suite.health.mail.label', 'suite.health.mail.missing');
        }

        $silent = 'null' === $scheme;

        return new HealthCheck(
            'mail',
            $silent ? HealthStatusEnum::Warning : HealthStatusEnum::Ok,
            'suite.health.mail.label',
            $silent ? 'suite.health.mail.null' : 'suite.health.mail.configured',
            ['scheme' => $scheme],
            ['scheme' => $scheme],
        );
    }

    /**
     * The active file storage, and whether it answers. R2 is asked for a
     * key that does not exist: one HEAD request, billed as the cheapest kind.
     */
    private function storage(): HealthCheck
    {
        try {
            $disk = $this->storageManager->activeDisk();
            $adapter = $this->storageManager->active();
        } catch (Throwable) {
            return new HealthCheck('storage', HealthStatusEnum::Danger, 'suite.health.storage.label', 'suite.health.storage.unavailable');
        }

        if (!$adapter->isReady()) {
            return new HealthCheck('storage', HealthStatusEnum::Danger, 'suite.health.storage.label', 'suite.health.storage.not_ready', ['disk' => $disk->value], ['disk' => $disk->value]);
        }

        if (StorageDiskEnum::R2 === $disk) {
            $started = hrtime(true);

            try {
                $adapter->exists('.aurora-health-probe');
            } catch (Throwable) {
                return new HealthCheck('storage', HealthStatusEnum::Danger, 'suite.health.storage.label', 'suite.health.storage.unreachable', ['disk' => $disk->value], ['disk' => $disk->value]);
            }

            $milliseconds = (int) round((hrtime(true) - $started) / 1_000_000);

            return new HealthCheck('storage', HealthStatusEnum::Ok, 'suite.health.storage.label', 'suite.health.storage.remote_answers', [
                'disk' => $disk->value,
                'milliseconds' => $milliseconds,
            ], ['disk' => $disk->value, 'responseMs' => $milliseconds]);
        }

        return new HealthCheck('storage', HealthStatusEnum::Ok, 'suite.health.storage.label', 'suite.health.storage.local', ['disk' => $disk->value], ['disk' => $disk->value]);
    }
}
