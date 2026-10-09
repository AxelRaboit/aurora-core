<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Live\Service;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Studio\SpaceChat\Service\SpaceChatHub;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;
use Throwable;

use function sprintf;

/**
 * The live half of a note several people are on, and the one thing it must
 * never be.
 *
 * **It must never be the reason a save is lost.** A hub is a second process on
 * a second port; it gets restarted, it gets upgraded, and it is simply absent
 * on every installation that has not set one up. So nothing here is on the
 * path that stores a note: the row is committed first, this is called
 * afterwards, and every failure is swallowed into the log.
 *
 * **Absent is a supported state, not a broken one.** This is the same
 * arbitration {@see SpaceChatHub} made,
 * on purpose and to the letter - two answers to one question would be a reason
 * to distrust both. With `MERCURE_URL` unset, everything below is a no-op, the
 * page is handed no address to connect to, and it asks the server every
 * {@see NotePresence::BEAT_SECONDS} seconds instead. What you get without a hub
 * is a note that notices its neighbours within twenty seconds; what you get
 * with one is a note that notices them at once.
 *
 * **One topic per note, private, and the subscriber is let in by a cookie.**
 * A note is not public. Whoever the application has already decided may read
 * this note gets a short-lived JWT, scoped to that one note's topic, in a
 * cookie the browser only ever sends to the hub. Losing the right to read the
 * note stops the page being served, and the cookie expires without anything
 * needing to be revoked at the hub.
 */
final readonly class NoteLiveHub
{
    /**
     * A URL that can never resolve, and both halves of that are deliberate.
     *
     * **A URL**, because the protocol's matchers work on URLs: the publish
     * grant is a URL pattern with a wildcard where the note id goes, which is
     * the only way to hold a right over notes that do not exist yet. The
     * pattern itself is in config/services.yaml, and the two have to be read
     * together.
     *
     * **`.invalid`**, because it is reserved by RFC 2606 and no resolver will
     * ever answer for it. A topic is the name of a channel and there is
     * nothing at the other end of it; a name that looks fetchable invites
     * somebody to try. A constant host rather than the installation's own also
     * means the name survives the site being moved to another domain.
     */
    private const string TOPIC_TEMPLATE = 'https://aurora.invalid/notes/markdown/%d';

    /**
     * Where the **browsers** publish, and the server never does.
     *
     * **Two topics, split by who may write to them.** Showing somebody else's
     * cursor means a browser has to publish - a cursor is self-reported, there
     * is nothing a server could know about it. But a browser that could
     * publish on the topic above could also forge a `changed` event with a
     * bogus version, or a presence list naming people who never opened the
     * note. Neither destroys anything, and both are lies the page would
     * believe.
     *
     * So the publish grant handed to a browser names *this* topic and nothing
     * else. What a client can forge there is its own cursor, which is
     * self-reported by nature - the worst it can do is point at a place it is
     * not, in a note it already writes.
     */
    private const string AWARENESS_TOPIC_TEMPLATE = 'https://aurora.invalid/notes/markdown/%d/awareness';

    /**
     * How long a browser's right to publish its cursor lasts.
     *
     * Short on purpose, and shorter than the subscription: this token lives in
     * the page's JavaScript rather than in an http-only cookie, because a
     * publish is a `fetch` and not an `EventSource`. Fifteen minutes of the
     * right to say where one's own cursor is, on one note, is a small thing to
     * lose to a cross-site script - and the beat renews it long before it
     * runs out.
     */
    private const int PUBLISH_TOKEN_LIFETIME = 900;

    /**
     * How long a browser may keep listening before the page has to be
     * reopened. An hour, which is the component's own default.
     */
    private const int COOKIE_LIFETIME = 3600;

    public function __construct(
        private HubInterface $hub,
        // The same factory `aurora.mercure.publisher` signs with, so a
        // subscriber's token and a publisher's carry the same claims.
        #[Autowire(service: 'aurora.mercure.token_factory')]
        private TokenFactoryInterface $tokens,
        private LoggerInterface $logger,
        // The hub's own URL rather than a flag of its own: one thing to set,
        // and no way to end up with a hub configured and switched off.
        #[Autowire(env: 'MERCURE_URL')]
        private string $hubUrl,
    ) {}

    public function isEnabled(): bool
    {
        return '' !== mb_trim($this->hubUrl);
    }

    public function topicFor(MarkdownNoteInterface $note): string
    {
        return sprintf(self::TOPIC_TEMPLATE, (int) $note->getId());
    }

    public function awarenessTopicFor(MarkdownNoteInterface $note): string
    {
        return sprintf(self::AWARENESS_TOPIC_TEMPLATE, (int) $note->getId());
    }

    /**
     * What a browser needs to publish its own cursor, or null without a hub.
     *
     * The address to POST to, the topic to name, and a token that may publish
     * **on that topic only**. Handed to the page rather than set as a cookie,
     * because publishing is a `fetch` with an `Authorization` header and a
     * cookie would not reach it.
     *
     * @return array{publishUrl: string, topic: string, token: string}|null
     */
    public function awarenessGrant(MarkdownNoteInterface $note): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $topic = $this->awarenessTopicFor($note);

        try {
            $token = $this->tokens->create(
                [new Grant([Grant::ACTION_PUBLISH], [$topic])],
                ['exp' => new DateTimeImmutable('+'.self::PUBLISH_TOKEN_LIFETIME.' seconds')],
            );
        } catch (Throwable $throwable) {
            $this->logger->warning('Could not mint a Mercure publish token for a note.', [
                'note' => $note->getId(),
                'exception' => $throwable,
            ]);

            return null;
        }

        return [
            'publishUrl' => $this->hub->getPublicUrl(),
            'topic' => $topic,
            'token' => $token,
        ];
    }

    /**
     * Where a page connects to hear this note, or null when no hub is running.
     *
     * Null is what tells the front end not to open a connection at all,
     * rather than to open one and retry for ever against a port nobody is
     * listening on. It is also what makes it fall back to asking.
     */
    public function subscribeUrl(MarkdownNoteInterface $note): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        // The parameter is named by the protocol: `topic` before 1.0, `match`
        // from it. Read off the hub rather than pinned here, so a hub
        // configured for the older protocol is still handed an address it
        // understands.
        $parameter = ProtocolVersion::Legacy === $this->hub->getProtocolVersion() ? 'topic' : 'match';

        // Both topics on one connection: what the server pushes, and what the
        // other browsers publish. A browser holds six connections per host,
        // and opening a second one per note would spend them on a note.
        $query = implode('&', array_map(
            static fn (string $topic): string => $parameter.'='.rawurlencode($topic),
            [$this->topicFor($note), $this->awarenessTopicFor($note)],
        ));

        return $this->hub->getPublicUrl().'?'.$query;
    }

    /**
     * The cookie that lets one browser listen to one note.
     *
     * Minted from the factory rather than through `Authorization`, for the
     * reason spelled out in `SpaceChatHub`: the bundle wires either a token
     * provider or a token factory onto a hub, and the publish grant this
     * application needs can only be expressed by a provider.
     *
     * Returned rather than set on the request, so the caller attaches it to
     * the response it is already building. **Subscribe only**: a browser that
     * could publish could tell everybody else the note changed when it did
     * not, or put somebody in the room who never opened it.
     */
    public function subscriptionCookie(MarkdownNoteInterface $note): ?Cookie
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $expiresAt = new DateTimeImmutable('+'.self::COOKIE_LIFETIME.' seconds');

            $token = $this->tokens->create(
                [new Grant([Grant::ACTION_SUBSCRIBE], [$this->topicFor($note), $this->awarenessTopicFor($note)])],
                ['exp' => $expiresAt],
            );

            $parts = parse_url($this->hub->getPublicUrl());
            $parts = is_array($parts) ? $parts : [];

            return Cookie::create(
                $this->hub->getCookieName(),
                $token,
                $expiresAt,
                // The hub's own path, so this is the only address the browser
                // ever sends the token to.
                $parts['path'] ?? '/',
                // Host-only. The layout this supports is the hub proxied
                // under the application's own address.
                null,
                // **Always, and not derived from the scheme.** The protocol
                // names this cookie `__Secure-mercure_access_token` and a hub
                // reads no other name. Browsers treat localhost as a secure
                // origin, so a development machine still works.
                secure: true,
                httpOnly: true,
                raw: false,
                sameSite: Cookie::SAMESITE_STRICT,
            );
        } catch (Throwable $throwable) {
            $this->logger->warning('Could not mint a Mercure subscription cookie for a note.', [
                'note' => $note->getId(),
                'exception' => $throwable,
            ]);

            return null;
        }
    }

    /**
     * Tells whoever is listening that the note's text changed, and to what
     * version.
     *
     * **The version, never the text.** A subscriber already has the right to
     * read the note, so sending the body would not leak anything - but it
     * would make every save push the whole note to every open page, and it
     * would make this the thing that keeps them in sync. It is not: the page
     * asks for the note the ordinary way once it knows it is behind, which is
     * the same road a reload takes.
     */
    public function publishChanged(MarkdownNoteInterface $note, ?string $byName): void
    {
        $this->publish($note, [
            'kind' => 'changed',
            'version' => $note->getVersion(),
            'by' => $byName,
            'at' => new DateTimeImmutable()->format(DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Tells whoever is listening who is on the note.
     *
     * @param list<array{userId: int, name: ?string, editing: bool, guest: bool}> $people
     */
    public function publishPresence(MarkdownNoteInterface $note, array $people): void
    {
        $this->publish($note, ['kind' => 'presence', 'people' => $people]);
    }

    /** @param array<string, mixed> $message */
    private function publish(MarkdownNoteInterface $note, array $message): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $this->hub->publish(new Update(
                $this->topicFor($note),
                json_encode($message, JSON_THROW_ON_ERROR),
                private: true,
            ));
        } catch (Throwable $throwable) {
            // Swallowed on purpose: see the class docblock. The note is
            // already saved, and a hub that is down is a page that notices
            // twenty seconds later, not a write that failed.
            $this->logger->warning('Could not publish a note update to the hub.', [
                'note' => $note->getId(),
                'kind' => $message['kind'] ?? null,
                'exception' => $throwable,
            ]);
        }
    }
}
