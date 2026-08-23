<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\MountPoint\Service;

use Aurora\Module\Dev\MountPoint\Entity\MountPointInterface;
use Aurora\Module\Dev\MountPoint\Enum\MountPointTypeEnum;
use PDO;
use PDOException;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final readonly class MountPointTesterService
{
    public function __construct(
        private MountPointEncryptionService $encryptionService,
        private SshTunnelService $sshTunnelService,
        private HttpClientInterface $httpClient,
    ) {}

    public function test(MountPointInterface $mountPoint): MountPointTestResult
    {
        return match ($mountPoint->getType()) {
            MountPointTypeEnum::Database => $this->testDatabase($mountPoint),
            MountPointTypeEnum::Api => $this->testApi($mountPoint),
            MountPointTypeEnum::Sftp => $this->testSftp($mountPoint),
        };
    }

    private function testDatabase(MountPointInterface $mountPoint): MountPointTestResult
    {
        $config = $mountPoint->getConfig();
        $tunnel = null;

        try {
            $host = $mountPoint->getHost();
            $port = $mountPoint->getPort();

            if (!empty($config['sshTunnel'])) {
                $tunnel = $this->openSshTunnel($mountPoint, $config);
                $host = '127.0.0.1';
                $port = $tunnel->localPort;
            }

            return $this->connectDatabase($mountPoint, $host, $port ?? 5432);
        } catch (Throwable $throwable) {
            return MountPointTestResult::failure($throwable->getMessage());
        } finally {
            $tunnel?->close();
        }
    }

    private function openSshTunnel(MountPointInterface $mountPoint, array $config): SshTunnel
    {
        if (null === $mountPoint->getSshPrivateKey()) {
            throw new RuntimeException('SSH tunnel requires a private key - none is configured.');
        }

        $privateKey = $this->encryptionService->decrypt($mountPoint->getSshPrivateKey());

        if (null === $privateKey) {
            throw new RuntimeException('Failed to decrypt the SSH private key.');
        }

        return $this->sshTunnelService->open(
            sshHost: $config['sshHost'] ?? $mountPoint->getHost(),
            sshPort: (int) ($config['sshPort'] ?? 22),
            sshUser: $config['sshUser'] ?? ($mountPoint->getUsername() ?? ''),
            privateKeyContent: $privateKey,
            remoteHost: '127.0.0.1',
            remotePort: $mountPoint->getPort() ?? 5432,
        );
    }

    private function connectDatabase(MountPointInterface $mountPoint, string $host, int $port): MountPointTestResult
    {
        $password = null !== $mountPoint->getPassword()
            ? $this->encryptionService->decrypt($mountPoint->getPassword())
            : null;

        $driver = $mountPoint->getConfig()['driver'] ?? 'pgsql';

        $dsn = match ($driver) {
            'pgsql' => sprintf(
                'pgsql:host=%s;port=%d%s',
                $host,
                $port,
                null !== $mountPoint->getDatabase() ? ';dbname='.$mountPoint->getDatabase() : '',
            ),
            default => sprintf(
                '%s:host=%s;port=%d%s;charset=utf8mb4',
                $driver,
                $host,
                $port,
                null !== $mountPoint->getDatabase() ? ';dbname='.$mountPoint->getDatabase() : '',
            ),
        };

        try {
            new PDO($dsn, $mountPoint->getUsername(), $password, [
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            return MountPointTestResult::success();
        } catch (PDOException $pdoException) {
            return MountPointTestResult::failure($pdoException->getMessage());
        }
    }

    private function testApi(MountPointInterface $mountPoint): MountPointTestResult
    {
        $url = $mountPoint->getHost();

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'https://'.$url;
        }

        try {
            $response = $this->httpClient->request('HEAD', $url, ['timeout' => 5]);
            $response->getStatusCode();

            return MountPointTestResult::success();
        } catch (Throwable $throwable) {
            return MountPointTestResult::failure($throwable->getMessage());
        }
    }

    private function testSftp(MountPointInterface $mountPoint): MountPointTestResult
    {
        if ('' === mb_trim($mountPoint->getHost())) {
            return MountPointTestResult::failure('Host is required.');
        }

        return MountPointTestResult::success('Config looks valid - live SFTP test not implemented yet.');
    }
}
