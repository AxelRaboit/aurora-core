<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\Health\Probe;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Health\Enum\HealthStatusEnum;
use Aurora\Module\Dev\Health\Report\HealthCheck;
use DateTimeImmutable;
use Throwable;

/**
 * The site's TLS certificate, read by connecting to the site itself.
 *
 * Renewal is automatic until the day it is not, and then the site goes dark
 * for every visitor at once. The site's address comes from Settings >
 * Général: nothing to measure for an address in plain HTTP or on this
 * machine, which is « off ».
 */
final readonly class CertificateProbe
{
    private const int WARNING_DAYS = 21;

    private const int DANGER_DAYS = 7;

    public function __construct(
        private SettingRepository $settingRepository,
    ) {}

    public function check(): HealthCheck
    {
        $url = $this->settingRepository->getOrDefault(ApplicationParameterEnum::SiteUrl);
        $host = parse_url($url, PHP_URL_HOST);

        if ('https' !== parse_url($url, PHP_URL_SCHEME) || !is_string($host) || $this->isLocal($host)) {
            return new HealthCheck('certificate', HealthStatusEnum::Off, 'suite.health.certificate.label', 'suite.health.certificate.not_applicable');
        }

        $expiresAt = $this->expiryOf($host);
        if (!$expiresAt instanceof DateTimeImmutable) {
            return new HealthCheck('certificate', HealthStatusEnum::Warning, 'suite.health.certificate.label', 'suite.health.certificate.unreadable', ['host' => $host]);
        }

        $days = (int) floor(($expiresAt->getTimestamp() - time()) / 86400);
        $status = match (true) {
            $days < self::DANGER_DAYS => HealthStatusEnum::Danger,
            $days < self::WARNING_DAYS => HealthStatusEnum::Warning,
            default => HealthStatusEnum::Ok,
        };

        return new HealthCheck('certificate', $status, 'suite.health.certificate.label', 'suite.health.certificate.expires', [
            'host' => $host,
            'days' => $days,
        ], ['host' => $host, 'expiresAt' => $expiresAt->format(DATE_ATOM), 'days' => $days]);
    }

    private function expiryOf(string $host): ?DateTimeImmutable
    {
        try {
            $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
            $client = @stream_socket_client(sprintf('ssl://%s:443', $host), $errorCode, $errorMessage, 4, STREAM_CLIENT_CONNECT, $context);
            if (false === $client) {
                return null;
            }

            $certificate = stream_context_get_params($client)['options']['ssl']['peer_certificate'] ?? null;
            fclose($client);

            $parsed = null !== $certificate ? openssl_x509_parse($certificate) : false;
            $validTo = is_array($parsed) ? ($parsed['validTo_time_t'] ?? null) : null;

            return is_int($validTo) ? new DateTimeImmutable('@'.$validTo) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function isLocal(string $host): bool
    {
        return 'localhost' === $host
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || '::1' === $host
            || str_starts_with($host, '127.');
    }
}
