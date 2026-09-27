<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Newsletter\Service;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function array_filter;
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

    /**
     * @param bool        $doubleOptIn     the address waits for the visitor to confirm it by email
     * @param int|null    $brevoTemplateId Brevo's confirmation template, required by Brevo for a double opt-in
     * @param string|null $redirectUrl     where Brevo sends the visitor after the confirmation click
     * @param string|null $visitorIp       kept by Mailchimp with the sign-up time, as proof of consent
     */
    public function subscribe(
        string $provider,
        #[SensitiveParameter]
        string $apiKey,
        string $listId,
        string $email,
        bool $doubleOptIn = false,
        ?int $brevoTemplateId = null,
        ?string $redirectUrl = null,
        ?string $visitorIp = null,
    ): bool {
        try {
            return match ($provider) {
                'mailchimp' => $this->mailchimp($apiKey, $listId, $email, $doubleOptIn, $visitorIp),
                default => $doubleOptIn
                    ? $this->brevoDoubleOptIn($apiKey, $listId, $email, $brevoTemplateId, $redirectUrl)
                    : $this->brevo($apiKey, $listId, $email),
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
     * Brevo's own confirmation flow: Brevo emails the visitor from the
     * client's template, and adds the address to the list only on the click.
     */
    private function brevoDoubleOptIn(string $apiKey, string $listId, string $email, ?int $templateId, ?string $redirectUrl): bool
    {
        if (null === $templateId || null === $redirectUrl) {
            return false;
        }

        $response = $this->httpClient->request('POST', 'https://api.brevo.com/v3/contacts/doubleOptinConfirmation', [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => ['api-key' => $apiKey, 'content-type' => 'application/json', 'accept' => 'application/json'],
            'json' => ['email' => $email, 'includeListIds' => [(int) $listId], 'templateId' => $templateId, 'redirectionUrl' => $redirectUrl],
        ]);

        return $response->getStatusCode() < 300;
    }

    /**
     * Mailchimp's data centre is the suffix of the key itself (`...-us21`),
     * so there is nothing else to ask for - and the member id is the email's
     * own MD5, which is how "add or update" becomes one request instead of a
     * search followed by a write.
     */
    private function mailchimp(string $apiKey, string $listId, string $email, bool $doubleOptIn, ?string $visitorIp): bool
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
            // `status_if_new` alone: a new address is added (pending when it
            // must be confirmed, and Mailchimp sends that email itself), and
            // a known one keeps its status. Sending `status` as well re-subscribed
            // anyone who had unsubscribed and typed their address again.
            // The sign-up's address and time travel with it: the proof of
            // consent the GDPR asks the list's owner to be able to show.
            'json' => array_filter([
                'email_address' => $email,
                'status_if_new' => $doubleOptIn ? 'pending' : 'subscribed',
                'ip_signup' => $visitorIp,
                'timestamp_signup' => new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ], static fn (?string $value): bool => null !== $value),
        ]);

        return $response->getStatusCode() < 300;
    }
}
