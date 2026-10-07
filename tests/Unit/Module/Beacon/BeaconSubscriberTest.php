<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Beacon;

use Aurora\Module\Beacon\EventSubscriber\BeaconSubscriber;
use Aurora\Module\Beacon\Service\BeaconSender;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function json_decode;
use function sys_get_temp_dir;
use function uniqid;

/**
 * The domain a check-in announces.
 *
 * Production announced itself as 72.60.130.238 on 06/10 and 07/10/2026,
 * because a robot calling the server by its address was the first visitor of
 * the day. The configured address has to win over whoever knocked.
 */
final class BeaconSubscriberTest extends TestCase
{
    private string $projectDirectory;

    protected function setUp(): void
    {
        $this->projectDirectory = sys_get_temp_dir().'/beacon-'.uniqid();
        new Filesystem()->dumpFile($this->projectDirectory.'/VERSION', 'v9.9.9');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->projectDirectory);
    }

    public function testTheConfiguredAddressWinsOverTheRequestHost(): void
    {
        self::assertSame('app.axelraboit.fr', $this->announcedDomain('https://app.axelraboit.fr', 'http://72.60.130.238/'));
    }

    /**
     * A copy that never set DEFAULT_URI still tells where it runs.
     */
    #[DataProvider('uninformativeAddresses')]
    public function testAnUninformativeAddressFallsBackToTheRequestHost(string $defaultUri): void
    {
        self::assertSame('copie.example', $this->announcedDomain($defaultUri, 'https://copie.example/'));
    }

    /** @return iterable<string, array{string}> */
    public static function uninformativeAddresses(): iterable
    {
        yield 'the localhost default' => ['http://localhost'];
        yield 'a bare IPv4' => ['http://203.0.113.9'];
        yield 'a bare IPv6' => ['http://[2001:db8::1]'];
        yield 'nothing' => [''];
    }

    private function announcedDomain(string $defaultUri, string $requestUri): ?string
    {
        $sent = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = json_decode($options['body'], true);

            return new MockResponse('', ['http_code' => 204]);
        });

        $settings = $this->createStub(SettingRepository::class);
        $settings->method('get')->willReturn('0123456789abcdef');

        $sender = new BeaconSender($client, $settings, new NullLogger(), 'https://receiver.example/_aurora/beacon', '', true, $this->projectDirectory);
        $subscriber = new BeaconSubscriber($sender, new ArrayAdapter(), $defaultUri);

        $subscriber->onTerminate(new TerminateEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($requestUri),
            new Response(),
        ));

        self::assertCount(1, $sent);

        return $sent[0]['domain'];
    }
}
