<?php

declare(strict_types=1);

namespace Aurora\Core\Content;

/**
 * Turns the address an author pasted into one that can be put in an iframe.
 *
 * A whitelist of three providers, and nothing else resolves. That is the whole
 * security model: an iframe runs whatever the host serves, so "any URL the
 * author typed" is not an option — the value would be an author-supplied frame
 * on the public page. An unknown host returns null and the zone renders as a
 * link instead of a player.
 *
 * The id is what is extracted, never the original string reassembled. A
 * `watch?v=ID&malicious=…` cannot smuggle anything through, because only the
 * id survives and it is matched against a strict pattern.
 */
final readonly class VideoEmbedResolver
{
    public const string YOUTUBE = 'youtube';

    public const string VIMEO = 'vimeo';

    public const string DAILYMOTION = 'dailymotion';

    /**
     * Patterns per provider, tried in order, each capturing the id.
     *
     * The hosts are anchored, `www.` optional, and the scheme required — a
     * bare `youtube.com/...` typed without one is not accepted rather than
     * being guessed at.
     *
     * @var array<string, list<string>>
     */
    private const array PATTERNS = [
        self::YOUTUBE => [
            '#^https?://(?:www\.)?youtube\.com/watch\?(?:.*&)?v=([A-Za-z0-9_-]{6,20})#i',
            '#^https?://(?:www\.)?youtube\.com/(?:embed|shorts|v)/([A-Za-z0-9_-]{6,20})#i',
            '#^https?://youtu\.be/([A-Za-z0-9_-]{6,20})#i',
        ],
        self::VIMEO => [
            '#^https?://(?:www\.)?vimeo\.com/(?:video/)?(\d{6,12})#i',
            '#^https?://player\.vimeo\.com/video/(\d{6,12})#i',
        ],
        self::DAILYMOTION => [
            '#^https?://(?:www\.)?dailymotion\.com/(?:embed/)?video/([A-Za-z0-9]{5,20})#i',
            '#^https?://dai\.ly/([A-Za-z0-9]{5,20})#i',
        ],
    ];

    /**
     * @return array{provider: string, id: string, embedUrl: string}|null null
     *                                                                    when the address belongs to no provider this knows
     */
    public function resolve(mixed $value): ?array
    {
        if (!is_string($value) || '' === mb_trim($value)) {
            return null;
        }

        $url = mb_trim($value);

        foreach (self::PATTERNS as $provider => $patterns) {
            foreach ($patterns as $pattern) {
                if (1 === preg_match($pattern, $url, $matches)) {
                    return [
                        'provider' => $provider,
                        'id' => $matches[1],
                        'embedUrl' => $this->embedUrl($provider, $matches[1]),
                    ];
                }
            }
        }

        return null;
    }

    private function embedUrl(string $provider, string $id): string
    {
        return match ($provider) {
            self::YOUTUBE => sprintf('https://www.youtube-nocookie.com/embed/%s', $id),
            self::VIMEO => sprintf('https://player.vimeo.com/video/%s', $id),
            self::DAILYMOTION => sprintf('https://www.dailymotion.com/embed/video/%s', $id),
            default => '',
        };
    }
}
