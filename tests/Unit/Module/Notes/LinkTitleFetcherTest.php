<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Notes;

use Aurora\Module\Notes\Markdown\Service\LinkTitleFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** A pasted address becomes a titled link, and nothing else is fetched. */
final class LinkTitleFetcherTest extends TestCase
{
    public function testTheOpenGraphTitleComesBeforeThePageTitle(): void
    {
        $html = '<html><head><title>Accueil | Site</title><meta property="og:title" content="Le vrai titre &amp; plus"></head>';

        self::assertSame('Le vrai titre & plus', LinkTitleFetcher::extractTitle($html));
        self::assertSame('Accueil | Site', LinkTitleFetcher::extractTitle("<title>\n  Accueil | Site\n</title>"));
        self::assertNull(LinkTitleFetcher::extractTitle('<p>Pas de titre</p>'));
    }

    public function testOnlyWebAddressesAreFetchable(): void
    {
        self::assertTrue(LinkTitleFetcher::isFetchable('https://aurora.test/page'));
        self::assertFalse(LinkTitleFetcher::isFetchable('file:///etc/passwd'));
        self::assertFalse(LinkTitleFetcher::isFetchable('javascript:alert(1)'));
        self::assertFalse(LinkTitleFetcher::isFetchable('https://'));
    }

    public function testReadsTheTitleOfAnHtmlPage(): void
    {
        $client = new MockHttpClient(new MockResponse('<html><head><title>Une page</title></head><body>', ['response_headers' => ['content-type' => 'text/html; charset=utf-8']]));

        self::assertSame('Une page', new LinkTitleFetcher($client)->titleOf('https://example.com/page'));
    }

    public function testIgnoresWhatIsNotHtml(): void
    {
        $client = new MockHttpClient(new MockResponse('%PDF-1.7', ['response_headers' => ['content-type' => 'application/pdf']]));

        self::assertNull(new LinkTitleFetcher($client)->titleOf('https://example.com/file.pdf'));
    }

    /** The server's own network is never reached, whatever is pasted. */
    public function testRefusesThePrivateNetwork(): void
    {
        $client = new MockHttpClient(new MockResponse('<title>Interne</title>', ['response_headers' => ['content-type' => 'text/html']]));

        self::assertNull(new LinkTitleFetcher($client)->titleOf('http://127.0.0.1:8000/admin'));
        self::assertNull(new LinkTitleFetcher($client)->titleOf('http://10.0.0.5/'));
    }
}
