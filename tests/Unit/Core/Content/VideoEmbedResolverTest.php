<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Content;

use Aurora\Core\Content\VideoEmbedResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The value this produces goes into an iframe's `src`, which runs whatever the
 * host serves. So the interesting half of these tests is what does *not*
 * resolve.
 */
final class VideoEmbedResolverTest extends TestCase
{
    private VideoEmbedResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new VideoEmbedResolver();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function addresses(): iterable
    {
        yield 'youtube watch' => [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'youtube',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        ];
        yield 'youtube watch with earlier params' => [
            'https://www.youtube.com/watch?list=PL123&v=dQw4w9WgXcQ',
            'youtube',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        ];
        yield 'youtube short link' => [
            'https://youtu.be/dQw4w9WgXcQ',
            'youtube',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        ];
        yield 'youtube shorts' => [
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'youtube',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        ];
        yield 'youtube already embedded' => [
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'youtube',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        ];
        yield 'vimeo' => [
            'https://vimeo.com/123456789',
            'vimeo',
            'https://player.vimeo.com/video/123456789',
        ];
        yield 'vimeo player' => [
            'https://player.vimeo.com/video/123456789',
            'vimeo',
            'https://player.vimeo.com/video/123456789',
        ];
        yield 'dailymotion' => [
            'https://www.dailymotion.com/video/x8abc12',
            'dailymotion',
            'https://www.dailymotion.com/embed/video/x8abc12',
        ];
        yield 'dailymotion short link' => [
            'https://dai.ly/x8abc12',
            'dailymotion',
            'https://www.dailymotion.com/embed/video/x8abc12',
        ];
    }

    #[DataProvider('addresses')]
    public function testAKnownAddressBecomesAnEmbed(string $url, string $provider, string $embed): void
    {
        $resolved = $this->resolver->resolve($url);

        self::assertNotNull($resolved, $url.' should resolve');
        self::assertSame($provider, $resolved['provider']);
        self::assertSame($embed, $resolved['embedUrl']);
    }

    /**
     * An iframe runs what its host serves, so "any address the author typed"
     * is not an option: it would be an author-supplied frame on a public page.
     *
     * @return iterable<string, array{string}>
     */
    public static function refusedAddresses(): iterable
    {
        yield 'a host nobody vetted' => ['https://evil.example/video/1'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'data' => ['data:text/html,<script>alert(1)</script>'];
        yield 'a lookalike host' => ['https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ'];
        yield 'a subdomain lookalike' => ['https://notyoutube.com/watch?v=dQw4w9WgXcQ'];
        yield 'no scheme' => ['youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'a youtube page that is not a video' => ['https://www.youtube.com/feed/subscriptions'];
        yield 'empty' => [''];
        yield 'blank' => ['   '];
    }

    #[DataProvider('refusedAddresses')]
    public function testAnythingElseDoesNotResolve(string $url): void
    {
        self::assertNull($this->resolver->resolve($url), $url.' must not reach an iframe');
    }

    public function testNonStringsDoNotResolve(): void
    {
        self::assertNull($this->resolver->resolve(null));
        self::assertNull($this->resolver->resolve(42));
        self::assertNull($this->resolver->resolve(['https://vimeo.com/123456789']));
    }

    /**
     * Only the id survives: the embed is built from it rather than from the
     * address, so nothing after it can be smuggled through.
     */
    public function testExtraParametersAreLeftBehind(): void
    {
        $resolved = $this->resolver->resolve(
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ&autoplay=1"><script>alert(1)</script>',
        );

        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $resolved['embedUrl']);
    }

    /** youtube-nocookie serves the same player without setting a tracking cookie. */
    public function testYoutubeUsesTheCookielessHost(): void
    {
        $resolved = $this->resolver->resolve('https://youtu.be/dQw4w9WgXcQ');

        self::assertStringContainsString('youtube-nocookie.com', $resolved['embedUrl']);
    }
}
