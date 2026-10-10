<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\Health\Probe;

use Aurora\Module\Dev\Health\Enum\HealthStatusEnum;
use Aurora\Module\Dev\Health\Report\HealthCheck;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * The Mercure hub, asked for real.
 *
 * « Configured » was all anybody could tell: the address in the environment,
 * nothing about whether a hub listened behind it. The probe calls it. The
 * hub answers a request without a topic with a 400, and any answer from it
 * means it is there; a refused connection or a timeout means the live chat
 * is down. Without an address, the hub is « off », which is a choice rather
 * than a fault.
 */
final readonly class MercureProbe
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'MERCURE_URL')]
        private string $hubUrl = '',
    ) {}

    public function check(): HealthCheck
    {
        if ('' === mb_trim($this->hubUrl)) {
            return new HealthCheck('mercure', HealthStatusEnum::Off, 'suite.health.mercure.label', 'suite.health.mercure.not_configured');
        }

        $started = hrtime(true);

        try {
            $status = $this->httpClient->request('GET', $this->hubUrl, ['timeout' => 3, 'max_duration' => 4])->getStatusCode();
        } catch (Throwable) {
            return new HealthCheck('mercure', HealthStatusEnum::Danger, 'suite.health.mercure.label', 'suite.health.mercure.unreachable');
        }

        $milliseconds = (int) round((hrtime(true) - $started) / 1_000_000);

        return new HealthCheck(
            'mercure',
            $status >= 500 ? HealthStatusEnum::Danger : HealthStatusEnum::Ok,
            'suite.health.mercure.label',
            $status >= 500 ? 'suite.health.mercure.error' : 'suite.health.mercure.answers',
            ['status' => $status, 'milliseconds' => $milliseconds],
            ['httpStatus' => $status, 'responseMs' => $milliseconds],
        );
    }
}
