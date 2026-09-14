<?php

declare(strict_types=1);

namespace Aurora\Core\Content;

/**
 * The same job {@see VideoEmbedResolver} does, for what is not a film.
 *
 * A separate class rather than more patterns in that one: a video zone asks
 * "can this be played" and answers in 16:9, while these answer in whatever
 * shape their provider was designed for. One class holding both would have a
 * caller asking a question it did not mean.
 *
 * **The whitelist is the whole security model**, exactly as it is next door. An
 * iframe runs whatever the host serves, so "any address the author typed" is
 * not an option - it would be an author-supplied frame on the client's public
 * page. An unknown host resolves to null and the zone offers a link instead.
 *
 * Only what the pattern captured is ever reassembled, never the string as it
 * arrived. A query string cannot smuggle anything through, because it does not
 * survive the match.
 *
 * **Adding a provider is not a code decision.** Each one here is a third party
 * that sees the client's visitors arrive, and that is the client's to accept,
 * not ours to add because somebody asked. Four is deliberate: the ones a
 * brochure site actually reaches for.
 */
final readonly class EmbedResolver
{
    public const string SPOTIFY = 'spotify';

    public const string SOUNDCLOUD = 'soundcloud';

    public const string CODEPEN = 'codepen';

    public const string CALENDLY = 'calendly';

    /**
     * Patterns per provider, each capturing what the embed address is built
     * from. Hosts anchored, scheme required - a bare `spotify.com/…` typed
     * without one is refused rather than guessed at.
     *
     * @var array<string, list<string>>
     */
    private const array PATTERNS = [
        self::SPOTIFY => [
            '#^https?://open\.spotify\.com/(track|album|playlist|episode|show)/([A-Za-z0-9]{16,32})#i',
        ],
        self::SOUNDCLOUD => [
            '#^https?://(?:www\.)?soundcloud\.com/([a-z0-9_-]{2,60})/([a-z0-9_-]{2,120})#i',
        ],
        self::CODEPEN => [
            '#^https?://(?:www\.)?codepen\.io/([A-Za-z0-9_-]{2,40})/(?:pen|embed|details|full)/([A-Za-z0-9]{4,20})#i',
        ],
        self::CALENDLY => [
            '#^https?://(?:www\.)?calendly\.com/([a-z0-9_-]{2,60})/([a-z0-9_-]{2,80})#i',
        ],
    ];

    /**
     * The shape each provider's frame wants.
     *
     * A ratio where the content has one, and a height where it does not: a
     * booking form is a column of dates whose height owes nothing to its
     * width, and forcing it into 16:9 would give a scrollbar inside a frame.
     *
     * @var array<string, array{aspect: string|null, height: int|null}>
     */
    private const array SHAPES = [
        self::SPOTIFY => ['aspect' => null, 'height' => 352],
        self::SOUNDCLOUD => ['aspect' => null, 'height' => 166],
        self::CODEPEN => ['aspect' => '16 / 9', 'height' => null],
        self::CALENDLY => ['aspect' => null, 'height' => 700],
    ];

    /**
     * @return array{provider: string, embedUrl: string, aspect: string|null, height: int|null}|null null
     *                                                                                               when the address belongs to no provider this knows
     */
    public function resolve(mixed $value): ?array
    {
        if (!is_string($value) || '' === mb_trim($value)) {
            return null;
        }

        $url = mb_trim($value);

        foreach (self::PATTERNS as $provider => $patterns) {
            foreach ($patterns as $pattern) {
                if (1 !== preg_match($pattern, $url, $matches)) {
                    continue;
                }

                return [
                    'provider' => $provider,
                    'embedUrl' => $this->embedUrl($provider, $matches[1], $matches[2]),
                    'aspect' => self::SHAPES[$provider]['aspect'],
                    'height' => self::SHAPES[$provider]['height'],
                ];
            }
        }

        return null;
    }

    private function embedUrl(string $provider, string $first, string $second): string
    {
        return match ($provider) {
            // `/embed/` is Spotify's own player, which is the only address
            // that renders in a frame.
            self::SPOTIFY => sprintf('https://open.spotify.com/embed/%s/%s', $first, $second),
            // SoundCloud's player takes the track address as a parameter, so
            // this is the one provider whose address is rebuilt rather than
            // pointed at. Rebuilt from the two captured parts and nothing
            // else, so the query string it arrived with does not travel.
            self::SOUNDCLOUD => sprintf(
                'https://w.soundcloud.com/player/?url=%s',
                rawurlencode(sprintf('https://soundcloud.com/%s/%s', $first, $second)),
            ),
            self::CODEPEN => sprintf('https://codepen.io/%s/embed/%s', $first, $second),
            self::CALENDLY => sprintf('https://calendly.com/%s/%s', $first, $second),
            default => '',
        };
    }
}
