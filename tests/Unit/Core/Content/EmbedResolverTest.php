<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Content;

use Aurora\Core\Content\EmbedResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The whitelist is the security model, so the cases that matter are the ones
 * it has to refuse.
 *
 * An iframe runs whatever its host serves. A resolver that accepted an address
 * it did not recognise would put an author-supplied frame on the client's
 * public page, and no amount of care further down would take it back.
 */
final class EmbedResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addresses(): iterable
    {
        yield 'a spotify episode' => [
            'https://open.spotify.com/episode/4rOoJ6Egrf8K2IrywzwOMk',
            'https://open.spotify.com/embed/episode/4rOoJ6Egrf8K2IrywzwOMk',
        ];
        yield 'a codepen' => [
            'https://codepen.io/aurora/pen/abcdef',
            'https://codepen.io/aurora/embed/abcdef',
        ];
        yield 'a calendly event' => [
            'https://calendly.com/axel/premier-echange',
            'https://calendly.com/axel/premier-echange',
        ];
    }

    #[DataProvider('addresses')]
    public function testItResolvesAProviderItKnows(string $url, string $expected): void
    {
        self::assertSame($expected, new EmbedResolver()->resolve($url)['embedUrl'] ?? null);
    }

    /**
     * SoundCloud is the one whose player takes the address as a parameter, so
     * it is the one rebuilt rather than pointed at - from the two captured
     * parts and nothing else.
     */
    public function testSoundcloudIsRebuiltFromWhatMatchedAndNothingElse(): void
    {
        $resolved = new EmbedResolver()->resolve('https://soundcloud.com/aurora/episode-un?si=TRACKING');

        self::assertNotNull($resolved);
        self::assertStringNotContainsString('TRACKING', $resolved['embedUrl']);
        self::assertStringContainsString(
            rawurlencode('https://soundcloud.com/aurora/episode-un'),
            $resolved['embedUrl'],
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refusals(): iterable
    {
        yield 'a host nobody named' => ['https://evil.example.com/widget'];
        yield 'a lookalike host' => ['https://open.spotify.com.evil.example.com/track/abcdefghijklmnop'];
        yield 'a scheme that is not one' => ['javascript:alert(1)'];
        yield 'no scheme at all' => ['open.spotify.com/track/abcdefghijklmnop'];
        yield 'an empty string' => [''];
        yield 'not a string' => [42];
    }

    #[DataProvider('refusals')]
    public function testItRefusesAnythingElse(mixed $value): void
    {
        self::assertNull(new EmbedResolver()->resolve($value));
    }

    /** A booking form is a column of dates: a ratio would give it a scrollbar. */
    public function testEachProviderCarriesTheShapeItWasDesignedFor(): void
    {
        $resolver = new EmbedResolver();

        $calendly = $resolver->resolve('https://calendly.com/axel/premier-echange');
        self::assertNull($calendly['aspect']);
        self::assertSame(700, $calendly['height']);

        $codepen = $resolver->resolve('https://codepen.io/aurora/pen/abcdef');
        self::assertSame('16 / 9', $codepen['aspect']);
        self::assertNull($codepen['height']);
    }
}
