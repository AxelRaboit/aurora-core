<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Controller\Backend;

use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Tests\Integration\Concern\CreatesTestUsers;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * A backend URL that matches no route: 404 when signed in, login page otherwise.
 *
 * The listener that draws this line asked the token storage whether anybody was
 * signed in, which cannot work for the case it exists to handle - an unmatched
 * path is rejected by `RouterListener` before the firewall runs, so the storage
 * is empty for everyone. Every mistyped backend URL bounced an administrator to
 * the login page.
 *
 * Nothing caught it: the redirect is a plausible enough response that it reads
 * as intended, and the listener's own name and docblock claimed the behaviour
 * it was failing to deliver.
 *
 * The path used here must not match any route. Routes with a `\d+` id are not
 * a substitute - those 404 from the argument resolver, after the firewall, and
 * they passed throughout.
 */
final class UnknownBackendPathTest extends IntegrationTestCase
{
    use CreatesTestUsers;

    private const UNKNOWN_PATH = '/backend/no-such-section/no-such-screen';

    public function testAnAdminGetsANotFound(): void
    {
        $client = $this->signedInClient();
        $client->request('GET', self::UNKNOWN_PATH);

        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
    }

    /** The backend does not tell a stranger which of its routes exist. */
    public function testAVisitorIsSentToTheLoginPage(): void
    {
        $client = static::createClient();
        $client->request('GET', self::UNKNOWN_PATH);

        self::assertResponseRedirects('/backend/platform/login');
    }

    /** The ordinary 404, raised after the firewall, is unchanged. */
    public function testAnAdminStillGetsANotFoundOnAMatchedRouteWithNoRow(): void
    {
        $client = $this->signedInClient();
        $client->request('GET', '/backend/editorial/posts/999999/edit');

        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
    }

    /**
     * Signed in through the login form, not through `loginUser()`.
     *
     * `loginUser()` puts the token straight into the token storage, so it is
     * already there when the exception is raised - which is the one thing that
     * never happens in production for an unmatched path, and it made this test
     * pass against the very defect it was written for. Posting the form leaves
     * the session cookie as the only carrier, and the kernel reboots between
     * requests, so the next request starts as the browser's does: empty
     * storage, `RouterListener` throwing before the firewall can fill it.
     */
    private function signedInClient(): KernelBrowser
    {
        $password = 'verysecure123';

        // The client first: creating the user boots the kernel, and a kernel
        // booted before createClient() is refused.
        $client = static::createClient();
        $user = $this->createTestUser('unknown-path-admin', role: UserRoleEnum::Admin);

        $client->request('GET', '/backend/platform/login');

        $client->request('POST', '/backend/platform/login', [
            'email' => $user->getEmail(),
            'password' => $password,
            '_csrf_token' => $this->csrfTokenFrom($client->getResponse()->getContent() ?: ''),
        ]);

        self::assertResponseRedirects();

        return $client;
    }

    /** The login screen is a Vue app; its token travels in the props blob. */
    private function csrfTokenFrom(string $html): string
    {
        self::assertSame(1, preg_match('/vue-props-value="([^"]*)"/', $html, $matches));

        /** @var array{csrfToken: string} $props */
        $props = json_decode(html_entity_decode($matches[1]), true, 512, JSON_THROW_ON_ERROR);

        return $props['csrfToken'];
    }
}
