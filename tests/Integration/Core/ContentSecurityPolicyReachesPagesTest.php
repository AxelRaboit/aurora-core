<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Core;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The policy has to arrive on the pages, and stay off the files.
 *
 * A builder that produces a perfect header and a subscriber that never sets it
 * is the failure this catches: the unit test beside it proves the string is
 * right, and nothing else would notice that no browser ever received it.
 */
final class ContentSecurityPolicyReachesPagesTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
    }

    public function testAPublicPageCarriesThePolicy(): void
    {
        $this->client->request('GET', '/');

        $header = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');

        self::assertNotSame('', $header, 'A rendered page reached a browser with no policy on it.');
        self::assertStringContainsString("default-src 'self'", $header);
        self::assertStringNotContainsString("'unsafe-eval'", $header);
    }

    public function testABackOfficePageCarriesANonceBecauseItHasInlineScripts(): void
    {
        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->client->request('GET', '/backend');

        $header = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('nonce-', $header);

        // And the nonce in the header is the one the page used. A mismatch is
        // the subtlest way this breaks: the header looks right, the page looks
        // right, and nothing runs.
        preg_match("/'nonce-([a-f0-9]+)'/", $header, $matches);
        self::assertArrayHasKey(1, $matches);
        self::assertStringContainsString(sprintf('nonce="%s"', $matches[1]), $body);
    }

    /**
     * What is not a document carries no document policy.
     *
     * Putting it on a PDF, an image or a payload says something about bytes
     * that other headers already govern, and `frame-ancestors 'none'` on a
     * file response stops the document preview - which is an `<iframe>` - from
     * showing it.
     *
     * Asserted on a JSON answer rather than on a file because it exercises the
     * same branch and needs nothing on disk: a test that skips itself when the
     * fixture is missing is a test that asserts nothing on most runs.
     */
    public function testAResponseThatIsNotAPageCarriesNoPolicy(): void
    {
        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', ['name' => '']);

        $response = $this->client->getResponse();

        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        self::assertFalse($response->headers->has('Content-Security-Policy'));
    }
}
