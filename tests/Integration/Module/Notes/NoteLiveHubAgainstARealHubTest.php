<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Notes;

use Aurora\Module\Notes\Live\Service\NoteLiveHub;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function sprintf;

/**
 * The publish grant, against a hub that is actually running.
 *
 * **What this file exists to prove, and what nothing else can.** A browser
 * publishes its own cursor, with a token Aurora signs and scopes to one
 * topic. Everything about that is checkable without a network *except the one
 * thing that matters*: whether a real hub accepts the token, and whether the
 * scope it carries is actually enforced. A hand-written imitation of the token
 * proved nothing - it was refused, because Mercure 1.0 wants an RFC 9068
 * access token and the imitation's header said `JWT` where it has to say
 * `at+jwt`. Only the factory the application uses can answer.
 *
 * Skipped, loudly but harmlessly, on any machine without a hub - which
 * includes CI. Same trade as the R2 suite next door: the suite has to stay
 * green for somebody who has never started Docker, and the only way to learn
 * what a hub does is to ask one.
 *
 * Run it with `make test-hub`, which puts `MERCURE_*` in the environment -
 * Symfony ignores `.env.local` under `APP_ENV=test` on purpose, so running
 * phpunit directly skips these even on a machine that has a hub, which looks
 * like a broken suite and is not.
 */
#[Group('hub')]
final class NoteLiveHubAgainstARealHubTest extends IntegrationTestCase
{
    private NoteLiveHub $hub;

    private HttpClientInterface $httpClient;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['MERCURE_URL', 'MERCURE_JWT_SECRET'] as $variable) {
            if ('' === (string) ($_SERVER[$variable] ?? $_ENV[$variable] ?? '')) {
                self::markTestSkipped(sprintf('%s is not set: this suite needs a running hub. Run `make test-hub`.', $variable));
            }
        }

        self::bootKernel();

        $hub = static::getContainer()->get(NoteLiveHub::class);
        self::assertInstanceOf(NoteLiveHub::class, $hub);
        $this->hub = $hub;

        $httpClient = static::getContainer()->get(HttpClientInterface::class);
        self::assertInstanceOf(HttpClientInterface::class, $httpClient);
        $this->httpClient = $httpClient;
    }

    /** The grant a page is handed is a token the hub takes. */
    public function testTheHubAcceptsThePublishGrantAuroraMints(): void
    {
        $note = $this->note(42);
        $grant = $this->hub->awarenessGrant($note);

        self::assertIsArray($grant, 'No grant: the hub URL is set but the token could not be minted.');

        $status = $this->publish($grant, $grant['topic'], '{"kind":"cursor","from":3,"index":12}');

        self::assertSame(200, $status, 'The hub refused a token the application signed.');
    }

    /**
     * And the scope is not decoration.
     *
     * The whole reason there are two topics is that a browser must not be able
     * to forge what the *server* says - a `changed` event with a bogus
     * version, or a presence list naming people who never opened the note.
     * If the hub ignored the scope, that separation would be a comment rather
     * than a boundary.
     */
    public function testTheHubRefusesTheSameTokenOnTheServersTopic(): void
    {
        $note = $this->note(42);
        $grant = $this->hub->awarenessGrant($note);
        self::assertIsArray($grant);

        $status = $this->publish($grant, $this->hub->topicFor($note), '{"kind":"changed","version":999}');

        // 403 and not 401, and the difference is the whole point: the token is
        // valid - the test above publishes with it - and the hub refuses it
        // here because the topic is not in its grant. An unauthenticated 401
        // would have proved nothing about the scope.
        self::assertSame(403, $status, 'A browser could publish on the topic only the server may use.');
    }

    /** @param array{publishUrl: string, topic: string, token: string} $grant */
    private function publish(array $grant, string $topic, string $data): int
    {
        return $this->httpClient->request('POST', $grant['publishUrl'], [
            'auth_bearer' => $grant['token'],
            'body' => ['topic' => $topic, 'data' => $data, 'private' => 'on'],
        ])->getStatusCode();
    }

    /**
     * A note with an id and nothing else.
     *
     * Never persisted: the hub only ever sees the id, through the topic, and
     * a row in the database would be a fixture this file has no use for.
     */
    private function note(int $id): MarkdownNote
    {
        $note = new MarkdownNote();
        $identifier = new ReflectionProperty($note, 'id');
        $identifier->setValue($note, $id);

        return $note;
    }
}
