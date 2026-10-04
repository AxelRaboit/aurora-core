<?php

declare(strict_types=1);

namespace Aurora\Module\Beacon\Service;

use Aurora\Core\Enum\AppVersionEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function bin2hex;
use function file_exists;
use function file_get_contents;
use function gethostname;
use function hash_hmac;
use function mb_trim;
use function random_bytes;

/**
 * Sends this deployment's daily check-in to the beacon receiver.
 *
 * It reports what the deployment is, not who uses it: instance id, host and
 * version, never any visitor or content data. A dev checkout (no VERSION file,
 * so version 'dev') never pings, so only real deployments show up.
 *
 * The payload is signed with BEACON_SECRET when one is set. The secret lives
 * in the client's environment, not in this public repository, so a signed ping
 * marks one of your own instances; an unsigned one is a lead. It is never used
 * to decide whether to accept a ping - a copy must be able to report, that is
 * the whole point (see LICENSE).
 */
final readonly class BeaconSender
{
    private const int TIMEOUT_SECONDS = 4;

    private const string INSTANCE_ID_KEY = 'beacon.instance_id';

    public function __construct(
        private HttpClientInterface $httpClient,
        private SettingRepository $settings,
        private LoggerInterface $logger,
        #[Autowire(param: 'app.beacon_url')]
        private string $url,
        #[Autowire(param: 'app.beacon_secret')]
        private string $secret,
        #[Autowire(param: 'app.beacon_enabled')]
        private bool $enabled,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {}

    public function send(?string $domain): void
    {
        if (!$this->enabled || '' === $this->url) {
            return;
        }

        $version = $this->version();
        if (AppVersionEnum::Dev->value === $version) {
            return;
        }

        try {
            $body = json_encode([
                'instance' => $this->instanceId(),
                'domain' => $domain,
                'host' => gethostname() ?: null,
                'app' => $version,
                'php' => PHP_VERSION,
            ], JSON_THROW_ON_ERROR);

            $headers = ['Content-Type' => 'application/json'];
            if ('' !== $this->secret) {
                $headers['X-Aurora-Beacon-Signature'] = hash_hmac('sha256', $body, $this->secret);
            }

            // HttpClient is lazy: not reading the response keeps this
            // fire-and-forget, and max_duration caps the whole exchange.
            $this->httpClient->request('POST', $this->url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);
        } catch (Throwable $throwable) {
            $this->logger->warning('Beacon check-in failed.', ['error' => $throwable->getMessage()]);
        }
    }

    private function instanceId(): string
    {
        $id = $this->settings->get(self::INSTANCE_ID_KEY);
        if (null === $id || '' === $id) {
            $id = bin2hex(random_bytes(16));
            $this->settings->set(self::INSTANCE_ID_KEY, $id);
        }

        return $id;
    }

    private function version(): string
    {
        $file = $this->projectDir.'/VERSION';

        return file_exists($file) ? mb_trim((string) file_get_contents($file)) : AppVersionEnum::Dev->value;
    }
}
