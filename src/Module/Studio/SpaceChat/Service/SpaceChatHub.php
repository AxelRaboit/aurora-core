<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Service;

use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;
use Throwable;

/**
 * The live half of a space's conversation, and the one thing it must never be.
 *
 * **It must never be the reason a message is lost.** A hub is a second process
 * on a second port; it gets restarted, it gets upgraded, it is simply absent on
 * every installation that has not set one up. So nothing here is on the path
 * that stores a message: the row is committed first, this is called afterwards,
 * and every failure is swallowed into the log. A studio whose hub is down sees
 * a conversation that needs refreshing, which is what every chat was before
 * 2010, rather than an error on a message that was in fact saved.
 *
 * **Absent is a supported state, not a broken one.** Aurora ships as a library,
 * and most projects consuming it will never run a hub. With `MERCURE_URL`
 * unset, everything below is a no-op, the pages are handed no address to
 * connect to, and the chat works the way the rest of the application does: you
 * see what was there when the page loaded. That is the reason the feature is
 * built on stored messages and a plain POST, with the hub layered on top, and
 * not on the hub carrying the messages.
 *
 * **The topic is private and the client is let in by a cookie.** A space's
 * conversation is not public, and the client reading it has no account - only a
 * secret address. So the subscription is authorised the same way the page is:
 * whoever proved they hold a usable link gets a short-lived JWT, scoped to that
 * one space's topic, in a cookie the browser only ever sends to the hub. A
 * revoked link stops resolving, the page stops being served, and the cookie
 * expires without anything needing to be revoked at the hub.
 */
final readonly class SpaceChatHub
{
    /**
     * A URL that can never resolve, and both halves of that are deliberate.
     *
     * **A URL**, because the protocol's matchers work on URLs: the publish
     * grant is a URL pattern with a wildcard where the space id goes, which is
     * the only way to hold a right over spaces that do not exist yet. An opaque
     * identifier would have to be listed one space at a time. The pattern
     * itself is in config/services.yaml, and the two have to be read together.
     *
     * **`.invalid`**, because it is reserved by RFC 2606 and no resolver will
     * ever answer for it. A topic is the name of a channel and there is nothing
     * at the other end of it; a name that looks fetchable invites somebody to
     * try. A constant host rather than the installation's own also means the
     * name survives the site being moved to another domain, which would
     * otherwise orphan every subscription at once.
     */
    private const string TOPIC_TEMPLATE = 'https://aurora.invalid/studio/spaces/%d/chat/%d';

    /**
     * How long a browser may keep listening before the page has to be reopened.
     *
     * An hour, which is the component's own default for a session cookie. It is
     * also the only expiry a revoked access link has at the hub: the hub never
     * hears that a link was revoked, so what stops a revoked reader is the page
     * no longer being served plus this running out.
     */
    private const int COOKIE_LIFETIME = 3600;

    public function __construct(
        private HubInterface $hub,
        // The same factory `aurora.mercure.publisher` signs with, so a
        // subscriber's token and a publisher's carry the same claims.
        #[Autowire(service: 'aurora.mercure.token_factory')]
        private TokenFactoryInterface $tokens,
        private LoggerInterface $logger,
        // The hub's own URL rather than a flag of its own: one thing to set, and
        // no way to end up with a hub configured and switched off, or announced
        // and absent.
        #[Autowire(env: 'MERCURE_URL')]
        private string $hubUrl,
    ) {}

    public function isEnabled(): bool
    {
        return '' !== mb_trim($this->hubUrl);
    }

    public function topicFor(SpaceChatChannelInterface $channel): string
    {
        return sprintf(self::TOPIC_TEMPLATE, $channel->getSpace()->getId(), $channel->getId());
    }

    /**
     * Where a page connects to hear this space, or null when no hub is running.
     *
     * Null is what tells the front end not to open a connection at all, rather
     * than to open one and retry for ever against a port nobody is listening
     * on.
     */
    public function subscribeUrl(array $channels): ?string
    {
        if (!$this->isEnabled() || [] === $channels) {
            return null;
        }

        // The parameter is named by the protocol: `topic` before 1.0, `match`
        // from it. Read off the hub rather than pinned here, so a hub
        // configured for the older protocol is still handed an address it
        // understands.
        $parameter = ProtocolVersion::Legacy === $this->hub->getProtocolVersion() ? 'topic' : 'match';

        // One address listing every room this reader may hear, rather than one
        // connection per room: a browser holds six of those per host, and a
        // studio with seven rooms open would have stopped receiving the
        // seventh without any error to show for it.
        $query = implode('&', array_map(
            fn (SpaceChatChannelInterface $channel): string => $parameter.'='.rawurlencode($this->topicFor($channel)),
            $channels,
        ));

        return $this->hub->getPublicUrl().'?'.$query;
    }

    /**
     * The cookie that lets one browser listen to one space.
     *
     * **Minted here rather than through `Authorization`, and not by choice.**
     * The bundle wires either a token provider or a token factory onto a hub,
     * never both, and the publish grant this application needs - a URL pattern
     * covering spaces that do not exist yet - can only be expressed by a
     * provider. Taking that branch leaves the hub with no factory, and
     * `Authorization::createCookie()` is the one thing that requires one. So
     * the cookie is built from the same factory the provider signs with, which
     * is also what guarantees the two carry identical claims.
     *
     * Returned rather than set on the request, so the caller attaches it to the
     * response it is already building and nothing depends on a listener having
     * run. Subscribe only: a browser that could publish could put words in the
     * studio's mouth without any of them being stored.
     */
    public function subscriptionCookie(Request $request, array $channels): ?Cookie
    {
        if (!$this->isEnabled() || [] === $channels) {
            return null;
        }

        try {
            $expiresAt = new DateTimeImmutable('+'.self::COOKIE_LIFETIME.' seconds');

            // The rooms this reader may hear, named one by one. A pattern
            // covering the space would hand a client the internal rooms of
            // their own space, which is the one thing rooms were added to
            // prevent.
            $token = $this->tokens->create(
                [new Grant([Grant::ACTION_SUBSCRIBE], array_map($this->topicFor(...), $channels))],
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
                // Host-only. A hub on an unrelated host could not be handed a
                // cookie at all; the layout this supports is the hub proxied
                // under the application's own address.
                null,
                // **Always, and not derived from the scheme.** The protocol
                // names this cookie `__Secure-mercure_access_token` and a hub
                // reads no other name - measured against 1.0.0, where the same
                // token under a prefix-less name is a flat 401. That prefix is
                // a promise that the cookie only travels over TLS, so it is
                // kept. A development machine still works: browsers treat
                // localhost as a secure origin and accept the cookie over plain
                // HTTP there. Anywhere else, the hub needs HTTPS - which it
                // needs regardless.
                secure: true,
                httpOnly: true,
                raw: false,
                sameSite: Cookie::SAMESITE_STRICT,
            );
        } catch (Throwable $throwable) {
            $this->logger->warning('Could not mint a Mercure subscription cookie.', [
                'channels' => array_map(static fn (SpaceChatChannelInterface $channel): ?int => $channel->getId(), $channels),
                'exception' => $throwable,
            ]);

            return null;
        }
    }

    /**
     * Pushes one message to whoever is listening.
     *
     * Private, so the hub checks every subscriber's JWT against the topic
     * instead of handing a client's conversation to anybody who guesses the
     * channel.
     *
     * One event carries one message, in the shape the serializer writes, so
     * that a message arriving live and the same message after a refresh are one
     * object. A removal is the same shape carrying `deleted`, rather than an
     * event type of its own: the page is maintaining a list of messages, and
     * everything that happens to that list is a message changing.
     *
     * @param array<string, mixed> $message
     */
    public function publish(SpaceChatChannelInterface $channel, array $message): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $this->hub->publish(new Update(
                $this->topicFor($channel),
                json_encode($message, JSON_THROW_ON_ERROR),
                private: true,
            ));
        } catch (Throwable $throwable) {
            // Swallowed on purpose: see the class docblock. The message is
            // already stored, and a hub that is down is a page that needs
            // refreshing, not a write that failed.
            $this->logger->warning('Could not publish a space chat message to the hub.', [
                'channel' => $channel->getId(),
                'exception' => $throwable,
            ]);
        }
    }
}
