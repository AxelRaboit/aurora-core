<?php

declare(strict_types=1);

namespace Aurora\Core\Scheduler\MessageHandler;

use Aurora\Core\Scheduler\Message\CleanTempFilesMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Cleans up orphaned temporary files left by Aurora processes.
 *
 * Covers:
 *   - /tmp/aurora_ssh_*  (SSH key files from MountPoint tunnels - crash orphans)
 */
#[AsMessageHandler]
final readonly class CleanTempFilesHandler
{
    /** Files older than this are considered orphans. */
    private const int TMP_MAX_AGE_MINUTES = 30;

    /** Prefixes of temporary files Aurora creates in sys_get_temp_dir(). */
    private const array TMP_PREFIXES = [
        'aurora_ssh_',
    ];

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function __invoke(CleanTempFilesMessage $message): void
    {
        $tmpCleaned = $this->cleanTmpFiles();

        if ($tmpCleaned > 0) {
            $this->logger->info('CleanTempFiles: removed {tmp} tmp file(s).', [
                'tmp' => $tmpCleaned,
            ]);
        }
    }

    private function cleanTmpFiles(): int
    {
        $cutoff = time() - (self::TMP_MAX_AGE_MINUTES * 60);
        $tmpDir = sys_get_temp_dir();
        $removed = 0;

        foreach (self::TMP_PREFIXES as $prefix) {
            $pattern = $tmpDir.DIRECTORY_SEPARATOR.$prefix.'*';
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && filemtime($file) < $cutoff) {
                    @unlink($file);
                    ++$removed;
                }
            }
        }

        return $removed;
    }
}
