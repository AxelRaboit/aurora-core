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
}
