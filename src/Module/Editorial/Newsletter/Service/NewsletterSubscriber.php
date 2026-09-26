<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Newsletter\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function array_key_last;
use function explode;
use function mb_strtolower;
use function md5;
use function sprintf;
use function str_contains;

/**
 * Adding one visitor's address to the client's own mailing list.
 *
 * Two providers, one call each - both accept "the address is already there"
 * as success rather than an error, which is the only sane answer to someone
 * signing up twice.
 */
final readonly class NewsletterSubscriber
{
    private const int TIMEOUT_SECONDS = 6;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {}

    public function subscribe(string $provider, string $apiKey, string $listId, string $email): bool
    {
        try {
            return match ($provider) {
                'mailchimp' => $this->mailchimp($apiKey, $listId, $email),
                default => $this->brevo($apiKey, $listId, $email),
            };
        } catch (Throwable $throwable) {
            $this->logger->warning('Newsletter subscription could not be sent.', ['provider' => $provider, 'exception' => $throwable]);

            return false;
        }
    }

    private function brevo(string $apiKey, string $listId, string $email): bool
    {
        $response = $this->httpClient->request('POST', 'https://api.brevo.com/v3/contacts', [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => ['api-key' => $apiKey, 'content-type' => 'application/json', 'accept' => 'application/json'],
            'json' => ['email' => $email, 'listIds' => [(int) $listId], 'updateEnabled' => true],
        ]);

        return $response->getStatusCode() < 300;
    }

    /**
     * Mailchimp's data centre is the suffix of the key itself (`...-us21`),
     * so there is nothing else to ask for - and the member id is the email's
     * own MD5, which is how "add or update" becomes one request instead of a
     * search followed by a write.
     */
    private function mailchimp(string $apiKey, string $listId, string $email): bool
    {
        $parts = explode('-', $apiKey);
        $datacenter = $parts[array_key_last($parts)] ?? '';

        if ('' === $datacenter || !str_contains($apiKey, '-')) {
            return false;
        }

        $memberId = md5(mb_strtolower($email));

        $response = $this->httpClient->request('PUT', sprintf('https://%s.api.mailchimp.com/3.0/lists/%s/members/%s', $datacenter, $listId, $memberId), [
            'timeout' => self::TIMEOUT_SECONDS,
            'auth_basic' => ['anystring', $apiKey],
            'json' => ['email_address' => $email, 'status_if_new' => 'subscribed', 'status' => 'subscribed'],
        ]);

        return $response->getStatusCode() < 300;
    }
}
