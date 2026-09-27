<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Newsletter\Service;

use Aurora\Module\Editorial\Newsletter\Service\NewsletterSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Adding one visitor to the client's own list, on whichever of the two
 * providers they picked - both treat "already there" as success.
 */
final class NewsletterSubscriberTest extends TestCase
{
    public function testBrevoSubscribesToTheChosenList(): void
    {
        $request = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$request): MockResponse {
            $request = [$method, $url, $options];

            return new MockResponse('', ['http_code' => 201]);
        });

        $service = new NewsletterSubscriber($client, new NullLogger());

        self::assertTrue($service->subscribe('brevo', 'api-key', '3', 'axel@example.com'));
        self::assertSame('POST', $request[0]);
        self::assertSame('https://api.brevo.com/v3/contacts', $request[1]);
        self::assertContains('api-key: api-key', $request[2]['headers']);
        self::assertSame('{"email":"axel@example.com","listIds":[3],"updateEnabled":true}', $request[2]['body']);
    }

    public function testBrevoTreatsAnAlreadySubscribedAddressAsSuccess(): void
    {
        $service = new NewsletterSubscriber(new MockHttpClient(new MockResponse('', ['http_code' => 204])), new NullLogger());

        self::assertTrue($service->subscribe('brevo', 'api-key', '3', 'axel@example.com'));
    }

    public function testMailchimpReadsItsDataCentreFromTheKeySOwnSuffix(): void
    {
        $request = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$request): MockResponse {
            $request = [$method, $url, $options];

            return new MockResponse('', ['http_code' => 200]);
        });

        $service = new NewsletterSubscriber($client, new NullLogger());

        self::assertTrue($service->subscribe('mailchimp', 'abcd1234-us21', 'list-id', 'Axel@Example.com'));
        self::assertSame('PUT', $request[0]);
        self::assertSame(
            'https://us21.api.mailchimp.com/3.0/lists/list-id/members/'.md5('axel@example.com'),
            $request[1],
        );
        self::assertContains('Authorization: Basic '.base64_encode('anystring:abcd1234-us21'), $request[2]['headers']);
    }

    /**
     * A known address keeps its status: sending `status` as well used to
     * re-subscribe anyone who had unsubscribed and typed their address again.
     */
    public function testMailchimpNeverOverridesAKnownMembersStatus(): void
    {
        $body = $this->mailchimpBody(doubleOptIn: false);

        self::assertArrayNotHasKey('status', $body);
        self::assertSame('subscribed', $body['status_if_new']);
    }

    /** Confirmation on: the address waits, and Mailchimp sends the email. */
    public function testMailchimpWithConfirmationAddsTheAddressAsPending(): void
    {
        $body = $this->mailchimpBody(doubleOptIn: true, visitorIp: '203.0.113.7');

        self::assertSame('pending', $body['status_if_new']);
        self::assertSame('203.0.113.7', $body['ip_signup'], 'the sign-up address is kept as proof of consent');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $body['timestamp_signup']);
    }

    public function testBrevoWithConfirmationUsesItsDoubleOptInFlow(): void
    {
        $request = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$request): MockResponse {
            $request = [$method, $url, $options];

            return new MockResponse('', ['http_code' => 201]);
        });

        $service = new NewsletterSubscriber($client, new NullLogger());

        self::assertTrue($service->subscribe('brevo', 'api-key', '3', 'axel@example.com', doubleOptIn: true, brevoTemplateId: 12, redirectUrl: 'https://example.com/fr'));
        self::assertSame('https://api.brevo.com/v3/contacts/doubleOptinConfirmation', $request[1]);
        self::assertSame(
            ['email' => 'axel@example.com', 'includeListIds' => [3], 'templateId' => 12, 'redirectionUrl' => 'https://example.com/fr'],
            json_decode($request[2]['body'], true),
        );
    }

    /** Brevo refuses a confirmation without a template: nothing is sent. */
    public function testBrevoWithConfirmationButNoTemplateSendsNothing(): void
    {
        $service = new NewsletterSubscriber(new MockHttpClient(function (): MockResponse {
            self::fail('No request should leave without a template.');
        }), new NullLogger());

        self::assertFalse($service->subscribe('brevo', 'api-key', '3', 'axel@example.com', doubleOptIn: true, redirectUrl: 'https://example.com/fr'));
    }

    public function testMailchimpRefusesAKeyWithoutADataCentreSuffix(): void
    {
        $service = new NewsletterSubscriber(new MockHttpClient(function (): MockResponse {
            self::fail('No request should be sent without a data centre.');
        }), new NullLogger());

        self::assertFalse($service->subscribe('mailchimp', 'no-suffix-here', 'list-id', 'axel@example.com'));
    }

    public function testAFailedRequestIsReportedAsALogRatherThanThrown(): void
    {
        $service = new NewsletterSubscriber(new MockHttpClient(new MockResponse('', ['http_code' => 500])), new NullLogger());

        self::assertFalse($service->subscribe('brevo', 'api-key', '3', 'axel@example.com'));
    }

    public function testAnUnreachableProviderIsReportedAsALogRatherThanThrown(): void
    {
        $service = new NewsletterSubscriber(
            new MockHttpClient(new MockResponse('', ['error' => 'connection refused'])),
            new NullLogger(),
        );

        self::assertFalse($service->subscribe('brevo', 'api-key', '3', 'axel@example.com'));
    }

    /** @return array<string, mixed> */
    private function mailchimpBody(bool $doubleOptIn, ?string $visitorIp = null): array
    {
        $body = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = json_decode($options['body'], true);

            return new MockResponse('', ['http_code' => 200]);
        });

        new NewsletterSubscriber($client, new NullLogger())->subscribe('mailchimp', 'abcd1234-us21', 'list-id', 'axel@example.com', doubleOptIn: $doubleOptIn, visitorIp: $visitorIp);

        return $body;
    }
}
