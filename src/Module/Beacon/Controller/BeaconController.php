<?php

declare(strict_types=1);

namespace Aurora\Module\Beacon\Controller;

use Aurora\Module\Beacon\Entity\DeployedInstance;
use Aurora\Module\Beacon\Repository\DeployedInstanceRepository;
use Aurora\Module\Beacon\Service\KnownDomains;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function hash_equals;
use function hash_hmac;
use function is_array;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function preg_match;

/**
 * Receives the daily check-ins {@see BeaconSender} sends. Public by necessity:
 * a copy of Aurora must be able to report for the beacon to notice it, so this
 * endpoint cannot be authenticated. It is hardened against abuse instead -
 * per-IP rate limit, capped body, strict validation, bounded storage (one row
 * per instance, upserted), and NO outbound side effect (no email), so it can
 * never be turned into an amplifier. Every reported value is untrusted data.
 */
final readonly class BeaconController
{
    private const int MAX_BODY_BYTES = 2048;

    private const int MAX_PER_IP_PER_HOUR = 30;

    public function __construct(
        private DeployedInstanceRepository $instances,
        private KnownDomains $knownDomains,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        #[Autowire(param: 'app.beacon_secret')]
        private string $secret,
    ) {}

    #[Route('/_aurora/beacon', name: 'aurora_beacon_receive', methods: ['POST'])]
    public function receive(Request $request): Response
    {
        $ip = $request->getClientIp() ?? 'unknown';
        if ($this->rateLimited($ip)) {
            return new Response('', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $raw = $request->getContent();
        if ('' === $raw || mb_strlen($raw) > self::MAX_BODY_BYTES) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $instanceId = is_string($data['instance'] ?? null) ? $data['instance'] : '';
        if (1 !== preg_match('/^[a-zA-Z0-9-]{8,64}$/', $instanceId)) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $domain = $this->str($data['domain'] ?? null, 255);
        $hostname = $this->str($data['host'] ?? null, 255);
        $appVersion = $this->str($data['app'] ?? null, 40);
        $phpVersion = $this->str($data['php'] ?? null, 20);

        $signatureValid = '' !== $this->secret
            && is_string($sig = $request->headers->get('X-Aurora-Beacon-Signature'))
            && hash_equals(hash_hmac('sha256', $raw, $this->secret), $sig);

        $known = null !== $domain && $this->knownDomains->contains($domain);

        $instance = $this->instances->findOneByInstanceId($instanceId);
        if ($instance instanceof DeployedInstance) {
            $instance->touch($domain, $hostname, $appVersion, $phpVersion, $signatureValid, $known, $ip);
        } else {
            $instance = new DeployedInstance($instanceId)
                ->fill($domain, $hostname, $appVersion, $phpVersion, $signatureValid, $known, $ip);

            if (!$known) {
                $this->logger->warning('Beacon: new unknown instance seen.', [
                    'domain' => $domain,
                    'host' => $hostname,
                    'app' => $appVersion,
                    'signatureValid' => $signatureValid,
                ]);
            }
        }

        $this->instances->save($instance);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function rateLimited(string $ip): bool
    {
        $item = $this->cache->getItem('beacon.rl.'.date('YmdH').'.'.mb_substr($ip, 0, 64));
        $count = (int) ($item->get() ?? 0);
        if ($count >= self::MAX_PER_IP_PER_HOUR) {
            return true;
        }

        $item->set($count + 1)->expiresAfter(3600);
        $this->cache->save($item);

        return false;
    }

    private function str(mixed $value, int $max): ?string
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
